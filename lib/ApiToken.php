<?php
/**
 * API tokeny pro automatizaci (spouštění záloh mimo webové rozhraní).
 *
 * Model:
 *   - Token má tvar "pbkt_<id>_<secret>". `id` slouží jen k vyhledání
 *     záznamu, `secret` je vysoce entropické tajemství, ze kterého se nikdy
 *     neukládá nic čitelného – jen otisk (SHA-256) pro ověření.
 *   - Ke každému tokenu se při vytvoření zapečetí (AES-256-GCM) aktuální
 *     hlavní klíč (MK) klíčem odvozeným z `secret`. Díky tomu umí token bez
 *     hesla správce rozšifrovat heslo ZIP archivu i uložené přístupy k DB –
 *     ale jen ten, kdo zná přesně tento token, a jen v rozsahu, který mu
 *     dovoluje `scope`.
 *   - `scope` neomezuje přístup k MK (ten je technicky nutný i pro
 *     nešifrované zálohy souborů, pokud je zapnuté heslo archivu), ale
 *     omezuje, jaký režim zálohy (`files` / `db` / `both`) lze tokenem spustit.
 */

declare(strict_types=1);

// Přímé volání tohoto souboru nedává smysl – vstupním bodem je index.php.
if (!defined('PB_VERSION')) {
    http_response_code(404);
    exit;
}


final class ApiToken
{
    /** Po kolika neúspěšných pokusech začíná blokace. */
    private const MAX_ATTEMPTS = 5;

    /** Základní délka blokace v sekundách (dále se zdvojnásobuje). */
    private const LOCK_BASE = 60;

    /** @return array<string,array<string,mixed>> Všechny tokeny, klíč = id. */
    public static function all(): array
    {
        $data = Storage::readData('api_tokens') ?? [];
        return is_array($data) ? $data : [];
    }

    /**
     * Tokeny pro zobrazení ve správě – bez otisku a bez zapečetěného klíče.
     *
     * @return list<array<string,mixed>>
     */
    public static function publicList(): array
    {
        $out = [];
        foreach (self::all() as $id => $t) {
            $out[] = [
                'id' => $id,
                'name' => (string)($t['name'] ?? ''),
                'scope' => (string)($t['scope'] ?? 'files'),
                'ip_allow' => (array)($t['ip_allow'] ?? []),
                'created' => (int)($t['created'] ?? 0),
                'last_used' => isset($t['last_used']) ? (int)$t['last_used'] : null,
                'has_mk' => !empty($t['mk_sealed']),
            ];
        }
        usort($out, static fn($a, $b) => $b['created'] <=> $a['created']);
        return $out;
    }

    /**
     * Založí nový token. Vrací plaintext token (zobrazí se jen teď) a jeho id.
     *
     * @param list<string> $ipAllow
     * @return array{id:string,token:string}
     */
    public static function create(string $name, string $scope, array $ipAllow, ?string $mk): array
    {
        $id = bin2hex(random_bytes(12));
        $secret = self::b64url(random_bytes(24));

        $record = [
            'name' => self::sanitizeName($name),
            'scope' => self::sanitizeScope($scope),
            'ip_allow' => array_values($ipAllow),
            'hash' => hash('sha256', $secret),
            'created' => time(),
            'last_used' => null,
            'mk_sealed' => null,
        ];
        if ($mk !== null && Crypto::available()) {
            $record['mk_sealed'] = Crypto::seal($mk, self::sealKey($secret));
        }

        $all = self::all();
        $all[$id] = $record;
        Storage::writeData('api_tokens', $all);
        Storage::log('API TOKEN: vytvořen „' . $record['name'] . '“');

        return ['id' => $id, 'token' => 'pbkt_' . $id . '_' . $secret];
    }

    /**
     * Upraví metadata existujícího tokenu (název, oprávnění, IP omezení).
     * Samotné tajemství ani zapečetěný hlavní klíč se nemění – ten jde
     * zapečetit jen v okamžiku vzniku tokenu, kdy je tajemství ještě
     * v paměti v čitelné podobě.
     *
     * @param list<string> $ipAllow
     */
    public static function update(string $id, string $name, string $scope, array $ipAllow): bool
    {
        $all = self::all();
        if (!isset($all[$id])) {
            return false;
        }
        $all[$id]['name'] = self::sanitizeName($name);
        $all[$id]['scope'] = self::sanitizeScope($scope);
        $all[$id]['ip_allow'] = array_values($ipAllow);

        Storage::writeData('api_tokens', $all);
        Storage::log('API TOKEN: upraven „' . $all[$id]['name'] . '“');
        return true;
    }

    public static function revoke(string $id): bool
    {
        $all = self::all();
        if (!isset($all[$id])) {
            return false;
        }
        $name = (string)($all[$id]['name'] ?? $id);
        unset($all[$id]);
        Storage::writeData('api_tokens', $all);
        Storage::log('API TOKEN: zrušen „' . $name . '“');
        return true;
    }

    /**
     * Ověří token z hlavičky Authorization. Vrací chybu jako řetězec, nebo
     * pole s údaji o tokenu při úspěchu.
     *
     * @return array{id:string,scope:string,ip_allow:list<string>,mk:?string}|string
     */
    public static function authenticate(string $tokenString)
    {
        $wait = self::lockRemaining();
        if ($wait > 0) {
            return 'Příliš mnoho neplatných pokusů. Zkuste to znovu za ' . ceil($wait / 60) . ' min.';
        }

        // Malé zpoždění srovnává časy odpovědí (stejný princip jako u loginu).
        usleep(random_int(100000, 250000));

        if (preg_match('/^pbkt_([a-f0-9]{24})_([A-Za-z0-9_-]{20,64})$/', trim($tokenString), $m) !== 1) {
            self::registerFailure();
            return 'Neplatný token.';
        }
        [, $id, $secret] = $m;

        $all = self::all();
        if (!isset($all[$id]) || !is_array($all[$id])) {
            self::registerFailure();
            return 'Neplatný token.';
        }
        $record = $all[$id];

        if (!hash_equals((string)$record['hash'], hash('sha256', $secret))) {
            self::registerFailure();
            return 'Neplatný token.';
        }
        self::clearFailures();

        $mk = null;
        if (!empty($record['mk_sealed']) && Crypto::available()) {
            $mk = Crypto::open((string)$record['mk_sealed'], self::sealKey($secret));
        }

        $all[$id]['last_used'] = time();
        Storage::writeData('api_tokens', $all);

        return [
            'id' => $id,
            'scope' => (string)($record['scope'] ?? 'files'),
            'ip_allow' => (array)($record['ip_allow'] ?? []),
            'mk' => $mk,
        ];
    }

    /** Klíč pro zapečetění MK odvozený z tajemství tokenu (nikdy neopouští proces). */
    private static function sealKey(string $secret): string
    {
        return hash_hmac('sha256', $secret, 'pb-api-mk-v1', true);
    }

    private static function b64url(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }

    private static function sanitizeName(string $name): string
    {
        $name = trim(preg_replace('/[\x00-\x1F\x7F]/', '', $name) ?? '');
        if ($name === '') {
            $name = 'token';
        }
        return function_exists('mb_substr') ? mb_substr($name, 0, 60, 'UTF-8') : substr($name, 0, 60);
    }

    private static function sanitizeScope(string $scope): string
    {
        return $scope === 'files_db' ? 'files_db' : 'files';
    }

    // -------------------------------------------------------- omezení pokusů

    private static function lockRemaining(): int
    {
        $entry = self::throttleEntry();
        return max(0, (int)($entry['until'] ?? 0) - time());
    }

    /** @return array<string,mixed> */
    private static function throttleEntry(): array
    {
        $all = Storage::readData('api_throttle') ?? [];
        $key = self::throttleKey();
        return isset($all[$key]) && is_array($all[$key]) ? $all[$key] : ['fails' => 0, 'until' => 0];
    }

    private static function throttleKey(): string
    {
        return substr(hash('sha256', Security::clientIp()), 0, 32);
    }

    private static function registerFailure(): void
    {
        Storage::log('API: neplatný token');
        $all = Storage::readData('api_throttle') ?? [];
        $key = self::throttleKey();
        $entry = isset($all[$key]) && is_array($all[$key]) ? $all[$key] : ['fails' => 0, 'until' => 0];

        $entry['fails'] = (int)$entry['fails'] + 1;
        $entry['seen'] = time();
        if ($entry['fails'] >= self::MAX_ATTEMPTS) {
            $over = $entry['fails'] - self::MAX_ATTEMPTS;
            $entry['until'] = time() + min(3600, self::LOCK_BASE * (2 ** min($over, 6)));
        }
        $all[$key] = $entry;

        foreach ($all as $k => $v) {
            if (!is_array($v) || time() - (int)($v['seen'] ?? 0) > 86400) {
                unset($all[$k]);
            }
        }
        Storage::writeData('api_throttle', $all);
    }

    private static function clearFailures(): void
    {
        $all = Storage::readData('api_throttle') ?? [];
        unset($all[self::throttleKey()]);
        Storage::writeData('api_throttle', $all);
    }
}

<?php
/**
 * Bezpečnostní vrstva – hlavičky, session, přihlášení, CSRF, omezení pokusů,
 * volitelný IP whitelist a bezpečná práce s cestami.
 */

declare(strict_types=1);

// Přímé volání tohoto souboru nedává smysl – vstupním bodem je index.php.
if (!defined('PB_VERSION')) {
    http_response_code(404);
    exit;
}


final class Security
{
    /** Po kolika neúspěšných pokusech začíná blokace. */
    private const MAX_ATTEMPTS = 5;

    /** Základní délka blokace v sekundách (dále se zdvojnásobuje). */
    private const LOCK_BASE = 60;

    /** Nečinnost, po které session vyprší. */
    private const IDLE_TIMEOUT = 1800;

    /** Maximální celková délka přihlášení. */
    private const ABSOLUTE_TIMEOUT = 43200;

    /** @var string|null */
    private static $nonce = null;

    /**
     * Hlavní klíč dodaný mimo session (z API tokenu). Platí jen pro aktuální
     * požadavek, nikdy se nikam neukládá.
     *
     * @var string|null
     */
    private static $runtimeMasterKey = null;

    // ---------------------------------------------------------------- hlavičky

    /** Náhodný nonce pro CSP (inline styl a skript). */
    public static function nonce(): string
    {
        if (self::$nonce === null) {
            self::$nonce = base64_encode(random_bytes(16));
        }
        return self::$nonce;
    }

    public static function sendHeaders(): void
    {
        if (headers_sent()) {
            return;
        }
        $nonce = self::nonce();
        header("Content-Security-Policy: default-src 'none'; base-uri 'none'; form-action 'self'; "
            . "frame-ancestors 'none'; img-src 'self' data:; connect-src 'self'; "
            . "style-src 'nonce-$nonce'; script-src 'nonce-$nonce'");
        header('X-Content-Type-Options: nosniff');
        header('X-Frame-Options: DENY');
        header('Referrer-Policy: no-referrer');
        header('Permissions-Policy: geolocation=(), camera=(), microphone=(), interest-cohort=()');
        header('Cache-Control: no-store, no-cache, must-revalidate');
        header('Pragma: no-cache');
        header_remove('X-Powered-By');

        if (self::isHttps()) {
            header('Strict-Transport-Security: max-age=31536000');
        }
    }

    public static function isHttps(): bool
    {
        if (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off') {
            return true;
        }
        if ((int)($_SERVER['SERVER_PORT'] ?? 0) === 443) {
            return true;
        }
        // Hlavičkám od proxy věříme jen pokud to správce výslovně povolil.
        if (Storage::config('trust_proxy', false) === true) {
            $proto = strtolower((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? ''));
            return $proto === 'https';
        }
        return false;
    }

    /**
     * Vynutí HTTPS, pokud je to v konfiguraci zapnuté. Bez šifrovaného spojení
     * by po síti putovalo heslo i celý archiv.
     */
    public static function enforceHttps(): void
    {
        if (!Storage::config('require_https', false) || self::isHttps()) {
            return;
        }
        http_response_code(403);
        header('Content-Type: text/plain; charset=utf-8');
        exit("Přístup je povolen pouze přes HTTPS.\n"
            . "Pokud váš hosting HTTPS nenabízí, vypněte vynucení v souboru data/config.php (require_https).\n");
    }

    // --------------------------------------------------------------------- IP

    /**
     * IP adresa klienta. Hlavičky typu X-Forwarded-For jsou padělatelné, takže
     * je bereme v potaz jen při výslovně zapnutém `trust_proxy`.
     */
    public static function clientIp(): string
    {
        if (Storage::config('trust_proxy', false) === true) {
            $fwd = (string)($_SERVER['HTTP_X_FORWARDED_FOR'] ?? '');
            if ($fwd !== '') {
                $first = trim(explode(',', $fwd)[0]);
                if (filter_var($first, FILTER_VALIDATE_IP) !== false) {
                    return $first;
                }
            }
        }
        $ip = (string)($_SERVER['REMOTE_ADDR'] ?? '');
        return filter_var($ip, FILTER_VALIDATE_IP) !== false ? $ip : '0.0.0.0';
    }

    /** Volitelný whitelist adres. Prázdný seznam = bez omezení. */
    public static function enforceIpAllowList(): void
    {
        $allow = Storage::config('ip_allow', []);
        if (!is_array($allow) || $allow === []) {
            return;
        }
        $ip = self::clientIp();
        foreach ($allow as $rule) {
            if (self::ipMatches($ip, (string)$rule)) {
                return;
            }
        }
        Storage::log('ODMÍTNUTO: IP mimo whitelist');
        http_response_code(403);
        header('Content-Type: text/plain; charset=utf-8');
        exit("Přístup z této IP adresy není povolen.\n");
    }

    /** Porovná IP s pravidlem (přesná adresa nebo CIDR, IPv4 i IPv6). */
    public static function ipMatches(string $ip, string $rule): bool
    {
        $rule = trim($rule);
        if ($rule === '') {
            return false;
        }
        if (strpos($rule, '/') === false) {
            return hash_equals($rule, $ip);
        }
        [$subnet, $bits] = explode('/', $rule, 2);
        $bits = (int)$bits;
        $ipBin = @inet_pton($ip);
        $netBin = @inet_pton(trim($subnet));
        if ($ipBin === false || $netBin === false || strlen($ipBin) !== strlen($netBin)) {
            return false;
        }
        if ($bits < 0 || $bits > strlen($ipBin) * 8) {
            return false;
        }
        $bytes = intdiv($bits, 8);
        $restBits = $bits % 8;
        if ($bytes > 0 && !hash_equals(substr($netBin, 0, $bytes), substr($ipBin, 0, $bytes))) {
            return false;
        }
        if ($restBits === 0) {
            return true;
        }
        $mask = ~((1 << (8 - $restBits)) - 1) & 0xFF;
        return (ord($ipBin[$bytes]) & $mask) === (ord($netBin[$bytes]) & $mask);
    }

    // ---------------------------------------------------------------- session

    public static function startSession(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }

        // Session ukládáme do vlastního adresáře. Na sdíleném hostingu je
        // společné /tmp čitelné i pro ostatní zákazníky serveru.
        $dir = Storage::sessionDir();
        // Vlastní adresář použijeme jen tehdy, když session opravdu ukládá PHP
        // do souborů. Když má hosting nastavený jiný handler (redis, memcached,
        // vlastní), přepsání cesty by relace úplně rozbilo.
        $handler = strtolower(trim((string)@ini_get('session.save_handler')));
        if ($handler === 'files' && is_dir($dir) && is_writable($dir)) {
            @ini_set('session.save_path', $dir);
        }
        @ini_set('session.use_strict_mode', '1');
        @ini_set('session.use_only_cookies', '1');
        @ini_set('session.use_trans_sid', '0');
        @ini_set('session.cookie_httponly', '1');
        @ini_set('session.cookie_samesite', 'Strict');
        @ini_set('session.cookie_secure', self::isHttps() ? '1' : '0');
        @ini_set('session.gc_maxlifetime', (string)self::ABSOLUTE_TIMEOUT);
        @ini_set('session.sid_length', '48');
        @ini_set('session.sid_bits_per_character', '5');

        session_name('PBSESSID');
        session_set_cookie_params([
            'lifetime' => 0,
            'path' => self::basePath(),
            'httponly' => true,
            'samesite' => 'Strict',
            'secure' => self::isHttps(),
        ]);
        @session_start();

        self::expireStaleSession();
    }

    /**
     * Adresář, ve kterém nástroj běží (pro cookie path a odkazy).
     *
     * Záměrně z REQUEST_URI, ne ze SCRIPT_NAME: některé hostingy (např. Vedos)
     * mají ve SCRIPT_NAME interní cestu typu /domains/domena.tld/…, která se
     * neshoduje s veřejnou URL. Cookie s takovou cestou by prohlížeč nikdy
     * neposlal zpět a přihlášení by se rozpadlo při každém požadavku.
     */
    public static function basePath(): string
    {
        $path = (string)parse_url((string)($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);

        // Uřízne název skriptu; požadavek na adresář (končí lomítkem) se nechá.
        if ($path === '' || substr($path, -1) !== '/') {
            $path = str_replace('\\', '/', dirname($path));
        }
        $path = rtrim($path, '/') . '/';

        // Pojistka: do hlavičky Set-Cookie a Location patří jen neškodné znaky
        // a žádné odkazy na nadřazené adresáře.
        if (preg_match('#^(?:/[A-Za-z0-9._~%!$&\'()*+,;=:@-]*)+$#', $path) !== 1
            || strpos($path, '/../') !== false) {
            return '/';
        }
        return $path;
    }

    private static function expireStaleSession(): void
    {
        if (empty($_SESSION['auth'])) {
            return;
        }
        $now = time();
        $idle = $now - (int)($_SESSION['seen'] ?? 0);
        $age = $now - (int)($_SESSION['login_at'] ?? 0);
        $uaOk = hash_equals((string)($_SESSION['ua'] ?? ''), self::uaFingerprint());

        if ($idle > self::IDLE_TIMEOUT || $age > self::ABSOLUTE_TIMEOUT || !$uaOk) {
            self::logout();
            return;
        }
        $_SESSION['seen'] = $now;
    }

    private static function uaFingerprint(): string
    {
        return hash('sha256', (string)($_SERVER['HTTP_USER_AGENT'] ?? ''));
    }

    public static function isLoggedIn(): bool
    {
        return !empty($_SESSION['auth']);
    }

    /** Hlavní klíč přihlášeného uživatele (pro čtení šifrovaných tajemství). */
    public static function masterKey(): ?string
    {
        if (self::$runtimeMasterKey !== null) {
            return self::$runtimeMasterKey;
        }
        if (!self::isLoggedIn() || empty($_SESSION['mk'])) {
            return null;
        }
        $key = base64_decode((string)$_SESSION['mk'], true);
        return $key === false ? null : $key;
    }

    /**
     * Nastaví hlavní klíč pro API požadavky bez session (odemčený z API
     * tokenu). Platí jen po dobu tohoto požadavku – nikdy se neperzistuje.
     */
    public static function setRuntimeMasterKey(?string $mk): void
    {
        self::$runtimeMasterKey = $mk;
    }

    // ------------------------------------------------------------- přihlášení

    /**
     * Ověří heslo. Vrací null při úspěchu, jinak chybovou hlášku.
     */
    public static function login(string $password): ?string
    {
        $wait = self::lockRemaining();
        if ($wait > 0) {
            Storage::log('PŘIHLÁŠENÍ: blokováno (' . $wait . ' s)');
            return 'Příliš mnoho neúspěšných pokusů. Zkuste to znovu za ' . ceil($wait / 60) . ' min.';
        }

        // Malé zpoždění srovnává časy odpovědí a zdržuje automatizované útoky.
        usleep(random_int(150000, 350000));

        $hash = (string)Storage::config('pwd_hash', '');
        if ($hash === '' || !password_verify($password, $hash)) {
            self::registerFailure();
            Storage::log('PŘIHLÁŠENÍ: neúspěch');
            return 'Nesprávné heslo.';
        }

        $mk = Crypto::unwrapMasterKey(
            (string)Storage::config('mk_wrapped', ''),
            (string)Storage::config('mk_salt', ''),
            $password
        );

        self::clearFailures();
        session_regenerate_id(true);
        $_SESSION = [];
        $_SESSION['auth'] = true;
        $_SESSION['login_at'] = time();
        $_SESSION['seen'] = time();
        $_SESSION['ua'] = self::uaFingerprint();
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
        if ($mk !== null) {
            $_SESSION['mk'] = base64_encode($mk);
            Crypto::wipe($mk);
        }

        // Přehašování při změně výchozího algoritmu PHP.
        if (password_needs_rehash($hash, PASSWORD_DEFAULT)) {
            Storage::saveConfig(['pwd_hash' => password_hash($password, PASSWORD_DEFAULT)]);
        }

        Storage::log('PŘIHLÁŠENÍ: úspěch');
        return null;
    }

    public static function logout(): void
    {
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $p = session_get_cookie_params();
            setcookie(session_name(), '', [
                'expires' => time() - 42000,
                'path' => $p['path'],
                'httponly' => true,
                'samesite' => 'Strict',
                'secure' => self::isHttps(),
            ]);
        }
        @session_destroy();
    }

    // -------------------------------------------------------- omezení pokusů

    /** @return int Zbývající sekundy blokace (0 = neblokováno). */
    public static function lockRemaining(): int
    {
        $entry = self::throttleEntry();
        return max(0, (int)($entry['until'] ?? 0) - time());
    }

    /** @return array<string,mixed> */
    private static function throttleEntry(): array
    {
        $all = Storage::readData('throttle') ?? [];
        $key = self::throttleKey();
        return isset($all[$key]) && is_array($all[$key]) ? $all[$key] : ['fails' => 0, 'until' => 0];
    }

    private static function throttleKey(): string
    {
        return substr(hash('sha256', self::clientIp()), 0, 32);
    }

    private static function registerFailure(): void
    {
        $all = Storage::readData('throttle') ?? [];
        $key = self::throttleKey();
        $entry = isset($all[$key]) && is_array($all[$key]) ? $all[$key] : ['fails' => 0, 'until' => 0];

        $entry['fails'] = (int)$entry['fails'] + 1;
        $entry['seen'] = time();
        if ($entry['fails'] >= self::MAX_ATTEMPTS) {
            $over = $entry['fails'] - self::MAX_ATTEMPTS;
            $entry['until'] = time() + min(3600, self::LOCK_BASE * (2 ** min($over, 6)));
        }
        $all[$key] = $entry;

        // Úklid starých záznamů, aby soubor nerostl.
        foreach ($all as $k => $v) {
            if (!is_array($v) || time() - (int)($v['seen'] ?? 0) > 86400) {
                unset($all[$k]);
            }
        }
        Storage::writeData('throttle', $all);
    }

    private static function clearFailures(): void
    {
        $all = Storage::readData('throttle') ?? [];
        unset($all[self::throttleKey()]);
        Storage::writeData('throttle', $all);
    }

    // ------------------------------------------------------------------ CSRF

    public static function csrfToken(): string
    {
        if (empty($_SESSION['csrf'])) {
            $_SESSION['csrf'] = bin2hex(random_bytes(32));
        }
        return (string)$_SESSION['csrf'];
    }

    public static function csrfValid(): bool
    {
        $sent = (string)($_POST['_csrf'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
        $known = (string)($_SESSION['csrf'] ?? '');
        return $sent !== '' && $known !== '' && hash_equals($known, $sent);
    }

    /**
     * Serveru se nepodařilo udržet relaci – v session není ani token, který
     * jsme do ní při vykreslení formuláře uložili. Nejde tedy o vypršení
     * platnosti, ale o vadné ukládání relací na hostingu.
     */
    public static function sessionLost(): bool
    {
        return empty($_SESSION['csrf']);
    }

    /**
     * Proč nejspíš relace nefunguje – pro srozumitelnou hlášku uživateli.
     *
     * @return list<string>
     */
    public static function sessionDiagnosis(): array
    {
        $notes = [];
        if (session_status() !== PHP_SESSION_ACTIVE) {
            $notes[] = 'PHP na tomto hostingu nedokázalo relaci vůbec zahájit.';
        }
        $handler = strtolower(trim((string)@ini_get('session.save_handler')));
        if ($handler !== '' && $handler !== 'files') {
            $notes[] = 'Hosting ukládá relace přes „' . $handler . '“ – zkontrolujte, zda tato služba běží.';
        }
        if (!is_writable(PB_DATA)) {
            $notes[] = 'Do adresáře data/ nelze zapisovat (práva nebo vyčerpaná kvóta).';
        } else {
            $probe = PB_DATA . '/.write-test';
            if (@file_put_contents($probe, str_repeat('x', 1024)) !== 1024) {
                $notes[] = 'Zápis na disk selhal – nejspíš je vyčerpaná disková kvóta hostingu.';
            }
            @unlink($probe);
        }
        // Pozor: disk_free_space bývá na hostinzích v disable_functions a v PHP 8
        // pak volání skončí fatální chybou – proto vždy přes function_exists.
        if (function_exists('disk_free_space')) {
            $free = @disk_free_space(PB_DATA);
            if ($free !== false && $free < 5 * 1024 * 1024) {
                $notes[] = 'Na disku zbývá méně než 5 MB volného místa.';
            }
        }
        if (headers_sent($file, $line)) {
            $notes[] = 'Výstup začal dřív, než se stačila navázat relace ('
                . basename((string)$file) . ':' . (int)$line . ').';
        }
        if ($notes === []) {
            $notes[] = 'Relaci se nepodařilo načíst zpět. Bývá to plnou diskovou kvótou '
                . 'nebo tím, že hosting maže adresář s relacemi.';
        }
        return $notes;
    }

    /** Délka řetězce ve znacích – mbstring není na hostinzích samozřejmost. */
    public static function length(string $value): int
    {
        return function_exists('mb_strlen') ? mb_strlen($value, 'UTF-8') : strlen($value);
    }

    // ----------------------------------------------------------------- cesty

    /**
     * Ověří, že cesta leží uvnitř nadřazeného adresáře. Porovnává se až po
     * `realpath()` a s oddělovačem na konci – `/data/web` tak neprojde jako
     * rodič `/data/webhosting`.
     */
    public static function pathWithin(string $path, string $parent): bool
    {
        $realPath = realpath($path);
        $realParent = realpath($parent);
        if ($realPath === false || $realParent === false) {
            return false;
        }
        $realParent = rtrim($realParent, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
        return strncmp($realPath . DIRECTORY_SEPARATOR, $realParent, strlen($realParent)) === 0;
    }
}

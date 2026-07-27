<?php
/**
 * Úložiště – datový adresář, konfigurace, stavové soubory a log.
 *
 * Bezpečnostní princip: *žádný* soubor s citlivým obsahem nemá příponu, kterou
 * by webserver poslal ven jako prostý text. Vše je uloženo jako `.php` soubor,
 * který začíná ochrannou hlavičkou – při přímém požadavku z webu se tedy
 * vykoná (a vrátí 404), místo aby se vypsal obsah. To funguje i tam, kde
 * hosting ignoruje `.htaccess` (nginx).
 */

declare(strict_types=1);

// Přímé volání tohoto souboru nedává smysl – vstupním bodem je index.php.
if (!defined('PB_VERSION')) {
    http_response_code(404);
    exit;
}


final class Storage
{
    /** Ochranná hlavička na začátku každého datového souboru. */
    private const GUARD = "<?php http_response_code(404); exit; ?>\n";

    /** @var array<string,mixed>|null */
    private static $config = null;

    /**
     * Vytvoří datový adresář a ochranné soubory. Voláno při každém requestu –
     * je idempotentní a levné.
     */
    public static function init(): void
    {
        foreach ([PB_DATA, self::backupDir(), self::tmpDir(), self::sessionDir()] as $dir) {
            if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) {
                self::fail('Nelze vytvořit adresář: ' . basename($dir)
                    . '. Nastavte prosím zápisová práva pro adresář nástroje.');
            }
        }

        // Vrstva navíc pro Apache/IIS. Hlavní ochranou je ale .php guard výše
        // a nezhádatelné názvy záložních archivů.
        self::putIfMissing(PB_DATA . '/.htaccess', implode("\n", [
            '# Datový adresář PHP Backup Tool – přístup zvenčí je zakázán.',
            '<IfModule mod_authz_core.c>',
            '    Require all denied',
            '</IfModule>',
            '<IfModule !mod_authz_core.c>',
            '    Order deny,allow',
            '    Deny from all',
            '</IfModule>',
            '',
        ]));
        self::putIfMissing(PB_DATA . '/index.html', '');
        self::putIfMissing(PB_DATA . '/web.config',
            "<?xml version=\"1.0\"?>\n<configuration><system.webServer><security>"
            . "<requestFiltering><hiddenSegments><add segment=\".\" /></hiddenSegments>"
            . "</requestFiltering></security></system.webServer></configuration>\n");

        // Testovací soubor pro kontrolu, zda je datový adresář viditelný z webu
        // (kontrolu provádí prohlížeč – viz self-test na nástěnce).
        if (!is_file(PB_DATA . '/probe.txt')) {
            @file_put_contents(PB_DATA . '/probe.txt', bin2hex(random_bytes(8)));
        }
    }

    public static function backupDir(): string
    {
        $custom = self::config('backup_store');
        if (is_string($custom) && $custom !== '' && is_dir($custom)) {
            return rtrim($custom, '/\\');
        }
        return PB_DATA . '/backups';
    }

    public static function tmpDir(): string
    {
        return PB_DATA . '/tmp';
    }

    public static function sessionDir(): string
    {
        return PB_DATA . '/sessions';
    }

    public static function isInstalled(): bool
    {
        return is_file(PB_DATA . '/config.php');
    }

    /**
     * Vrátí položku konfigurace, nebo výchozí hodnotu.
     *
     * @return mixed
     */
    public static function config(string $key, $default = null)
    {
        if (self::$config === null) {
            self::$config = self::readData('config') ?: [];
        }
        return array_key_exists($key, self::$config) ? self::$config[$key] : $default;
    }

    /** @return array<string,mixed> */
    public static function configAll(): array
    {
        if (self::$config === null) {
            self::$config = self::readData('config') ?: [];
        }
        return self::$config;
    }

    /**
     * Uloží změny konfigurace (merge s existující).
     *
     * @param array<string,mixed> $changes
     */
    public static function saveConfig(array $changes): void
    {
        $config = array_merge(self::configAll(), $changes);
        self::writeData('config', $config);
        self::$config = $config;
    }

    /**
     * Vytvoří konfiguraci. Selže, pokud už existuje – tím je instalace
     * jednorázová a nelze ji zneužít k převzetí nástroje.
     *
     * @param array<string,mixed> $config
     */
    public static function createConfig(array $config): bool
    {
        $path = PB_DATA . '/config.php';
        $fh = @fopen($path, 'xb'); // 'x' = selže, pokud soubor existuje
        if ($fh === false) {
            return false;
        }
        $ok = fwrite($fh, self::GUARD . json_encode($config, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) !== false;
        fclose($fh);
        @chmod($path, 0600);
        self::$config = $ok ? $config : null;
        return $ok;
    }

    /**
     * Načte datový soubor (JSON za ochrannou hlavičkou).
     *
     * @return array<string,mixed>|null
     */
    public static function readData(string $name): ?array
    {
        $path = PB_DATA . '/' . self::safeName($name) . '.php';
        if (!is_file($path)) {
            return null;
        }
        $raw = @file_get_contents($path);
        if ($raw === false) {
            return null;
        }
        $nl = strpos($raw, "\n");
        if ($nl === false) {
            return null;
        }
        $data = json_decode(substr($raw, $nl + 1), true);
        return is_array($data) ? $data : null;
    }

    /**
     * Atomicky zapíše datový soubor.
     *
     * @param array<string,mixed> $data
     */
    public static function writeData(string $name, array $data): bool
    {
        $path = PB_DATA . '/' . self::safeName($name) . '.php';
        $tmp = $path . '.' . bin2hex(random_bytes(4)) . '.tmp';
        $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            return false;
        }
        if (@file_put_contents($tmp, self::GUARD . $json, LOCK_EX) === false) {
            return false;
        }
        @chmod($tmp, 0600);
        if (!@rename($tmp, $path)) {
            @unlink($tmp);
            return false;
        }
        return true;
    }

    public static function deleteData(string $name): void
    {
        @unlink(PB_DATA . '/' . self::safeName($name) . '.php');
    }

    /**
     * Zapíše řádek do auditního logu (přihlášení, zálohy, mazání, chyby).
     * Log se automaticky ořezává, aby nerostl bez omezení.
     */
    public static function log(string $message): void
    {
        $path = PB_DATA . '/audit.php';
        if (!is_dir(PB_DATA)) {
            return;
        }
        if (!is_file($path)) {
            @file_put_contents($path, self::GUARD);
            @chmod($path, 0600);
        } elseif (@filesize($path) > 512 * 1024) {
            $raw = (string)@file_get_contents($path);
            $lines = explode("\n", $raw);
            $lines = array_slice($lines, (int)(count($lines) / 2));
            @file_put_contents($path, self::GUARD . implode("\n", $lines));
        }

        $line = sprintf(
            "[%s] %s | %s | %s\n",
            date('Y-m-d H:i:s'),
            Security::clientIp(),
            str_replace(["\r", "\n"], ' ', $message),
            substr(str_replace(["\r", "\n"], ' ', (string)($_SERVER['HTTP_USER_AGENT'] ?? '-')), 0, 120)
        );
        @file_put_contents($path, $line, FILE_APPEND | LOCK_EX);
    }

    /** @return list<string> Posledních N řádků auditního logu, nejnovější první. */
    public static function readLog(int $limit = 40): array
    {
        $path = PB_DATA . '/audit.php';
        if (!is_file($path)) {
            return [];
        }
        $raw = (string)@file_get_contents($path);
        $nl = strpos($raw, "\n");
        $lines = array_filter(explode("\n", $nl === false ? '' : substr($raw, $nl + 1)), 'strlen');
        return array_slice(array_reverse(array_values($lines)), 0, $limit);
    }

    /** Smaže obsah dočasného adresáře (zbytky po přerušených úlohách). */
    public static function clearTmp(): void
    {
        foreach ((array)@glob(self::tmpDir() . '/*') as $file) {
            if (is_file($file)) {
                @unlink($file);
            }
        }
    }

    private static function putIfMissing(string $path, string $content): void
    {
        if (!is_file($path)) {
            @file_put_contents($path, $content);
        }
    }

    private static function safeName(string $name): string
    {
        if (preg_match('/^[a-z0-9_-]{1,40}$/', $name) !== 1) {
            self::fail('Neplatný název datového souboru.');
        }
        return $name;
    }

    /** Ukončí request s obecnou chybovou hláškou. */
    public static function fail(string $message): void
    {
        if (!headers_sent()) {
            header('Content-Type: text/plain; charset=utf-8');
            http_response_code(500);
        }
        exit($message . "\n");
    }
}

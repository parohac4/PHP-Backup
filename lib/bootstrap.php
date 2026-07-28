<?php
/**
 * PHP Backup Tool 4.0 – zaváděcí soubor.
 *
 * Načítá se jako první ze všech vstupních bodů. Nastaví bezpečné výchozí
 * hodnoty PHP, načte třídy a připraví datový adresář.
 */

declare(strict_types=1);

if (PHP_VERSION_ID < 70400) {
    header('Content-Type: text/plain; charset=utf-8');
    exit("PHP Backup Tool vyžaduje PHP 7.4 nebo novější (nalezeno " . PHP_VERSION . ").\n");
}

const PB_VERSION = '4.1';

// Kořen nástroje a datový adresář.
define('PB_ROOT', dirname(__DIR__));
define('PB_DATA', PB_ROOT . '/data');

// Chyby nikdy nevypisujeme do prohlížeče – mohly by prozradit cesty a konfiguraci.
ini_set('display_errors', '0');
ini_set('display_startup_errors', '0');
ini_set('log_errors', '1');
error_reporting(E_ALL);

// Práva pro nově vytvářené soubory: nic pro ostatní uživatele serveru.
umask(0077);

require_once __DIR__ . '/Storage.php';
require_once __DIR__ . '/Crypto.php';
require_once __DIR__ . '/Security.php';
require_once __DIR__ . '/ApiToken.php';
require_once __DIR__ . '/Job.php';
require_once __DIR__ . '/FilesBackup.php';
require_once __DIR__ . '/SqlDump.php';
require_once __DIR__ . '/View.php';

// Vlastní obsluha chyb – uživatel vidí obecnou hlášku, detail jde do logu.
set_error_handler(static function (int $no, string $str, string $file = '', int $line = 0): bool {
    if (!(error_reporting() & $no)) {
        return false; // potlačeno operátorem @
    }
    throw new ErrorException($str, 0, $no, $file, $line);
});

// Nezachycená výjimka nesmí uživateli vypsat cestu ani kus kódu.
set_exception_handler(static function (Throwable $e): void {
    Storage::log('VÝJIMKA: ' . get_class($e) . ': ' . $e->getMessage()
        . ' @ ' . basename($e->getFile()) . ':' . $e->getLine());
    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: text/plain; charset=utf-8');
    }
    echo "Nástroj narazil na neočekávanou chybu. Podrobnosti najdete v záznamu činnosti.\n";
});

register_shutdown_function(static function (): void {
    $e = error_get_last();
    if ($e === null || !in_array($e['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        return;
    }
    Storage::log('FATAL: ' . $e['message'] . ' @ ' . basename((string)$e['file']) . ':' . $e['line']);
});

Storage::init();

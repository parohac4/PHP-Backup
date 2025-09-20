<?php
declare(strict_types=1);

// --- načti config SPOLEHLIVĚ (vynutíme invalidaci OPcache) ---
$configPath = __DIR__ . '/config.php';
if (function_exists('opcache_invalidate')) { @opcache_invalidate($configPath, true); }
$cfg = require $configPath;

// helpery
function fail(int $code, string $msg): never {
    http_response_code($code);
    // ZAKÁZAT HTTP CACHE výstupu (CDN/proxy/prohlížeč)
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    header('Content-Type: text/plain; charset=UTF-8');
    echo $msg;
    exit;
}
function norm(string $p): string { return '/' . ltrim(str_replace('\\','/',$p), '/'); }
function ensureDir(string $d): void { if (!is_dir($d) && !@mkdir($d, 0775, true)) fail(500, "Cannot create dir: $d"); }
function allowedIp(array $allow): bool { if (!$allow) return true; $ip = $_SERVER['REMOTE_ADDR'] ?? ''; return in_array($ip, $allow, true); }

// --- základní kontroly / bezpečnost ---
if (!allowedIp($cfg['allow_ips'])) fail(403, 'Forbidden (IP)');
if (!empty($cfg['require_https']) && (($_SERVER['HTTPS'] ?? 'off') !== 'on')) fail(400, 'HTTPS required');

$token = $_POST['token'] ?? ($_GET['token'] ?? '');
if ($token === '' || empty($cfg['token']) || !hash_equals($cfg['token'], $token)) fail(401, 'Unauthorized');

// --- cesty ---
$rootConf = (string)$cfg['backup_root'];
$rootReal = realpath($rootConf) ?: '';
if ($rootReal === '' || !is_dir($rootReal)) {
    fail(500, "BACKUP_ROOT invalid:\n- configured: $rootConf\n- realpath(): FAILED");
}
$outDir = (string)$cfg['backup_dir']; ensureDir($outDir);

// --- rate-limit ---
$metaPath = $outDir . '/.last_run';
$now = time();
$last = is_file($metaPath) ? (int)@file_get_contents($metaPath) : 0;
$minGap = (int)($cfg['min_seconds_between_runs'] ?? 60);

$force = isset($_GET['force']) && $_GET['force'] === '1';

if (!$force && $minGap > 0 && $last && ($now - $last) < $minGap) {
    $wait = $minGap - ($now - $last);
    fail(429, "Too Many Requests – wait {$wait}s");
}

// --- zámek proti souběhu ---
$lockPath = $outDir . '/.backup.lock';
$lock = fopen($lockPath, 'c');
if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) fail(409, 'Another backup is running');

// --- ZIP ---
$zipName = 'site-' . date('Ymd-His') . '-' . bin2hex(random_bytes(4)) . '.zip';
$zipPath = $outDir . '/' . $zipName;

$zip = new ZipArchive();
if ($zip->open($zipPath, ZipArchive::CREATE) !== true) {
    flock($lock, LOCK_UN); fclose($lock);
    fail(500, 'Cannot open zip');
}

$ex = array_map(fn($x)=>rtrim(str_replace('\\','/',$x),'/').'/', (array)$cfg['exclude_paths']);
$outRel = ltrim(str_replace($rootReal, '', $outDir), DIRECTORY_SEPARATOR);
$maxItems = (int)($cfg['max_items'] ?? 0);

$added = 0;
$it = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($rootReal, FilesystemIterator::SKIP_DOTS),
    RecursiveIteratorIterator::SELF_FIRST
);
foreach ($it as $item) {
    $abs = $item->getPathname();
    if (is_link($abs)) continue;
    $rel = ltrim(str_replace($rootReal, '', $abs), DIRECTORY_SEPARATOR);
    $n   = norm($rel);

    $skip = false;
    foreach ($ex as $x) { if (str_contains($n.'/', $x)) { $skip = true; break; } }
    if (!$skip && $outRel !== '' && str_starts_with($n.'/', norm($outRel).'/')) $skip = true;
    if ($skip) continue;

    if ($item->isDir()) $zip->addEmptyDir($rel); else $zip->addFile($abs, $rel);
    if ($maxItems > 0 && ++$added >= $maxItems) break;
}

$zip->setArchiveComment('Created: ' . date('c'));
$zip->close();
@file_put_contents($metaPath, (string)$now);

// --- poslat ZIP (a opět NO-CACHE hlavičky) ---
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Content-Type: application/zip');
header('Content-Length: ' . (string)filesize($zipPath));
header('Content-Disposition: attachment; filename="' . basename($zipPath) . '"');

$fh = fopen($zipPath, 'rb');
if ($fh) { while (!feof($fh)) { echo fread($fh, 8192); @ob_flush(); flush(); } fclose($fh); }

@unlink($zipPath);
flock($lock, LOCK_UN);
fclose($lock);
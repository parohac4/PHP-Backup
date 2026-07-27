<?php
declare(strict_types=1);

/**
 * Jednoduché inkrementální zálohování přes jedinou URL.
 * Každé zavolání:
 *  - ověří token
 *  - provede jednu dávku přidání souborů do ZIPu
 *  - pokud není hotovo, vrátí 303 See Other na stejnou URL (curl -L pokračuje)
 *  - po dokončení vrátí 200 a pošle ZIP (a uklidí job)
 *
 * Použití (cURL):
 *   curl -L "https://domena.tld/public/backup.php?token=963748935cd0882aca85a026dcdf52699d497e293cb56091a2791eb344544f4f" -o backup-$(date +%F-%H%M).zip
 */

// ======= KONFIGURACE =======
$BACKUP_ROOT = '/data/web/virtuals/XXX/virtual/www/domains/parohac.eu/'; // např. '/data/web/virtuals/259668/virtual/www/domains/domena.tld/'
$DATA_DIR    = __DIR__ . '/data';        // musí být zapisovatelné
$TOKEN       = 'vas_bezpecny_token'; // změňte na vlastní bezpečný token
$BATCH       = 300;                      // kolik souborů zpracovat v jednom požadavku
$EXCLUDE     = [
  '/.git/', '/.svn/', '/.hg/', '/node_modules/', '/vendor/bin/',
  '/cache/', '/logs/', '/log/', '/tmp/', '/var/tmp/', '/var/cache/',
  '/.idea/', '/.vscode/', '/.DS_Store', '/Thumbs.db',
  '/php-zip/public/data/', // nearchivovat vlastní výstupy
];

// ======= POMOCNÉ FUNKCE =======
function fail(int $code, string $msg): never {
  http_response_code($code);
  header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
  header('Pragma: no-cache');
  header('Content-Type: text/plain; charset=UTF-8');
  echo $msg . "\n";
  exit;
}
function ensure_dir(string $d): void { if (!is_dir($d) && !@mkdir($d, 0775, true)) fail(500, "Cannot create dir: $d"); }
function norm(string $p): string { return '/' . ltrim(str_replace('\\','/',$p), '/'); }
function excluded(string $relNorm, array $ex): bool {
  foreach ($ex as $x) { $x = rtrim(str_replace('\\','/',$x),'/') . '/'; if (str_contains($relNorm.'/', $x)) return true; }
  return false;
}
function redirect_self(string $url): never {
  // krátký odpočinek je šetrný k FS a WAF
  header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
  header('Pragma: no-cache');
  header('Retry-After: 1');
  header('Location: ' . $url);
  http_response_code(303); // See Other
  echo "Working…\n";
  exit;
}

// ======= AUTORIZACE =======
$recv = $_GET['token'] ?? $_POST['token'] ?? '';
if (!is_string($recv) || $recv === '' || !hash_equals($TOKEN, $recv)) {
  fail(401, 'Unauthorized');
}

// ======= PŘÍPRAVA CEST =======
$root = realpath($BACKUP_ROOT) ?: '';
if ($root === '' || !is_dir($root)) fail(500, "Invalid BACKUP_ROOT");
ensure_dir($DATA_DIR);
$jobsRoot = $DATA_DIR . '/jobs';
ensure_dir($jobsRoot);

// „aktuální“ job pro daný token – klíč je hash tokenu (a cesty root)
$key = substr(hash('sha256', $TOKEN . '|' . $root), 0, 16);
$currentPtr = $jobsRoot . "/current-$key";

// ======= NAČTI / ZALOŽ JOB =======
$jobId = is_file($currentPtr) ? trim((string)@file_get_contents($currentPtr)) : '';
$jobDir = '';
$listFile = '';
$stateFile = '';
$zipPath = '';

if ($jobId !== '') {
  $jobDir = $jobsRoot . '/' . $jobId;
  $stateFile = $jobDir . '/state.json';
  $listFile  = $jobDir . '/files.txt';
  $zipPath   = $jobDir . '/backup-' . $jobId . '.zip';
  // pokud je job poškozený/není, vytvoř nový
  if (!is_dir($jobDir) || !is_file($stateFile) || !is_file($listFile)) $jobId = '';
}

if ($jobId === '') {
  // nová úloha
  $jobId = date('Ymd-His') . '-' . bin2hex(random_bytes(4));
  $jobDir = $jobsRoot . '/' . $jobId; ensure_dir($jobDir);
  $listFile  = $jobDir . '/files.txt';
  $stateFile = $jobDir . '/state.json';
  $zipPath   = $jobDir . '/backup-' . $jobId . '.zip';

  // vytvoř prázdný ZIP (a hned zavři)
  $zip = new ZipArchive();
  if ($zip->open($zipPath, ZipArchive::CREATE) !== true) fail(500, 'Cannot create zip');
  $zip->setArchiveComment('Created: ' . date('c'));
  $zip->close();

  // naskenuj soubory do listu (po souborech; prázdné složky neřešíme)
  $ex = $EXCLUDE;
  $outRel = ltrim(str_replace($root, '', $DATA_DIR), DIRECTORY_SEPARATOR);
  $outRelNorm = $outRel !== '' ? norm($outRel) : null;

  $fh = @fopen($listFile, 'wb');
  if (!$fh) fail(500, 'Cannot write file list');

  $total = 0;
  $it = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
    RecursiveIteratorIterator::SELF_FIRST
  );
  foreach ($it as $item) {
    $abs = $item->getPathname();
    if (is_link($abs) || $item->isDir()) continue;
    $rel = ltrim(str_replace($root, '', $abs), DIRECTORY_SEPARATOR);
    $n   = norm($rel);
    if ($outRelNorm && str_starts_with($n.'/', $outRelNorm.'/')) continue; // nikdy nearchivovat vlastní data/
    if (excluded($n, $ex)) continue;
    fwrite($fh, $rel . "\n");
    $total++;
  }
  fclose($fh);

  // uložit stav
  $state = ['id'=>$jobId,'pos'=>0,'total'=>$total,'done'=>false,'zip'=>$zipPath,'list'=>$listFile];
  file_put_contents($stateFile, json_encode($state, JSON_UNESCAPED_SLASHES));

  // nastavit current
  file_put_contents($currentPtr, $jobId);
}

// ======= LOCK PROTI SOUBĚHU =======
$lockFp = fopen($jobDir . '/.lock', 'c');
if (!$lockFp || !flock($lockFp, LOCK_EX | LOCK_NB)) {
  // někdo právě běží – přesměruj a zkus znovu
  redirect_self($_SERVER['REQUEST_URI']);
}

// ======= KROK: ZPRACUJ DÁVKU =======
$state = json_decode((string)@file_get_contents($stateFile), true);
if (!is_array($state)) { flock($lockFp, LOCK_UN); fclose($lockFp); fail(500, 'Bad state'); }

$pos = (int)($state['pos'] ?? 0);
$total = (int)($state['total'] ?? 0);
$done = !empty($state['done']);

if (!$done) {
  // otevřít ZIP (pokud zmizel/prázdný, CREATE)
  $zip = new ZipArchive();
  $flags = (is_file($zipPath) && filesize($zipPath) > 0) ? 0 : ZipArchive::CREATE;
  if ($zip->open($zipPath, $flags) !== true) { flock($lockFp, LOCK_UN); fclose($lockFp); fail(500, 'Cannot open zip'); }

  // otevřít list a přeskočit už hotové řádky
  $fh = @fopen($listFile, 'rb');
  if (!$fh) { $zip->close(); flock($lockFp, LOCK_UN); fclose($lockFp); fail(500, 'Cannot open list'); }
  for ($i=0; $i<$pos; $i++) { if (feof($fh)) break; fgets($fh); }

  // přidat až BATCH souborů
  $processed = 0;
  while ($processed < $BATCH && !feof($fh)) {
    $rel = fgets($fh);
    if ($rel === false) break;
    $rel = rtrim($rel, "\r\n");
    if ($rel === '') { $pos++; continue; }
    $abs = $root . DIRECTORY_SEPARATOR . $rel;
    if (is_file($abs)) {
      $zip->addFile($abs, $rel);
    }
    $processed++; $pos++;
  }
  fclose($fh);
  $zip->close();

  // uložit stav
  $state['pos'] = $pos;
  $state['done'] = ($pos >= $total);
  file_put_contents($stateFile, json_encode($state, JSON_UNESCAPED_SLASHES));
}

// ======= POKUD HOTOVO → POŠLI ZIP A UKLIĎ =======
$state = json_decode((string)@file_get_contents($stateFile), true) ?: ['done'=>false];
if (!empty($state['done'])) {
  // odeslat ZIP
  header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
  header('Pragma: no-cache');
  header('Content-Type: application/zip');
  header('Content-Length: ' . (string)filesize($zipPath));
  header('Content-Disposition: attachment; filename="backup-' . $jobId . '.zip"');

  $fh = fopen($zipPath, 'rb');
  if ($fh) { while (!feof($fh)) { echo fread($fh, 8192); @ob_flush(); flush(); } fclose($fh); }

  // úklid
  @unlink($zipPath);
  @unlink($state['list'] ?? '');
  @unlink($stateFile);
  @unlink($currentPtr);
  @rmdir($jobDir);

  flock($lockFp, LOCK_UN);
  fclose($lockFp);
  exit;
}

// ======= NENÍ HOTOVO → 303 NA STEJNOU URL =======
flock($lockFp, LOCK_UN);
fclose($lockFp);
$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? 'https' : 'http';
$host   = $_SERVER['HTTP_HOST'];
$uri    = $_SERVER['REQUEST_URI']; // už obsahuje token=...
redirect_self($uri);
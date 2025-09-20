<?php
declare(strict_types=1);
require __DIR__ . '/common.php';
no_cache(); header('Content-Type: application/json; charset=UTF-8');

$cfg = cfg(); require_auth($cfg);

$jobId = (string)($_POST['job_id'] ?? $_GET['job_id'] ?? '');
if ($jobId === '') fail(400, 'Missing job_id');
$batch = (int)($_POST['batch'] ?? $_GET['batch'] ?? ($cfg['batch_size'] ?? 400));
if ($batch < 50) $batch = 50;

[$root, $outDir] = root_paths($cfg);
$jobDir = $outDir . '/jobs/' . $jobId;
$stateFile = $jobDir . '/state.json';
$listFile  = $jobDir . '/files.txt';
$zipPath   = $jobDir . '/backup-' . $jobId . '.zip';

if (!is_file($stateFile)) fail(404, 'Job not found');

// per-job lock: brání paralelním STEPům
$lockFp = fopen($jobDir . '/.job.lock', 'c');
if (!$lockFp || !flock($lockFp, LOCK_EX | LOCK_NB)) {
  fail(409, 'Another step is running');
}

$state = json_decode((string)file_get_contents($stateFile), true);
if (!$state) { flock($lockFp, LOCK_UN); fclose($lockFp); fail(500, 'Bad state'); }

$pos = (int)($state['pos'] ?? 0);
$total = (int)($state['total'] ?? 0);
$done = !empty($state['done']);
if ($done) {
  flock($lockFp, LOCK_UN); fclose($lockFp);
  echo json_encode(['ok'=>true,'job'=>[
    'id'=>$jobId,'pos'=>$pos,'total'=>$total,'done'=>true,
    'download'=>"download-backup.php?job={$jobId}"
  ]]);
  exit;
}

// list existence
if (!is_file($listFile)) { flock($lockFp, LOCK_UN); fclose($lockFp); fail(500, 'List missing'); }

// ZIP existence / create-if-missing
$zipFlags = 0;
if (!is_file($zipPath) || (is_file($zipPath) && filesize($zipPath) === 0)) {
  // soubor neexistuje nebo je prázdný → vytvoříme
  $zipFlags = ZipArchive::CREATE;
}

// bezpečné otevření ZIPu s krátkým retry
$zip = new ZipArchive();
$opened = false;
for ($i=0; $i<5; $i++) {
  $res = $zip->open($zipPath, $zipFlags);
  if ($res === true) { $opened = true; break; }
  usleep(150 * 1000); // 150 ms mezi pokusy
}
// pokud selže i tak, vrať srozumitelnou chybu + info
if (!$opened) {
  $info = (is_file($zipPath) ? ('exists,size='.filesize($zipPath)) : 'missing');
  flock($lockFp, LOCK_UN); fclose($lockFp);
  fail(500, 'Cannot reopen zip ('.$info.')');
}

// otevři seznam a přeskoč hotové řádky
$fh = fopen($listFile, 'rb');
if (!$fh) { $zip->close(); flock($lockFp, LOCK_UN); fclose($lockFp); fail(500, 'Cannot open list'); }
for ($i=0; $i<$pos; $i++) { if (feof($fh)) break; fgets($fh); }

// dávkové přidávání souborů
$processed = 0;
while ($processed < $batch && !feof($fh)) {
  $rel = fgets($fh);
  if ($rel === false) break;
  $rel = rtrim($rel, "\r\n");
  if ($rel === '') { $pos++; continue; }
  $abs = $root . DIRECTORY_SEPARATOR . $rel;

  if (is_file($abs)) {
    // NENÍ nutné manuálně přidávat parent dirs – ZipArchive vytvoří cestu z "rel"
    $zip->addFile($abs, $rel);
  }
  $processed++; $pos++;
}
fclose($fh);

// zavři ZIP (důležité!)
$zip->close();

// ulož stav
$state['pos'] = $pos;
$state['done'] = ($pos >= $total);
file_put_contents($stateFile, json_encode($state, JSON_UNESCAPED_SLASHES));

// odemkni
flock($lockFp, LOCK_UN);
fclose($lockFp);

// odpověď
echo json_encode([
  'ok'=>true,
  'job'=>[
    'id'=>$jobId,
    'pos'=>$pos,
    'total'=>$total,
    'done'=>$state['done'],
    'download'=>$state['done'] ? "download-backup.php?job={$jobId}" : null
  ]
]);
<?php
declare(strict_types=1);
require __DIR__ . '/common.php';
no_cache(); header('Content-Type: application/json; charset=UTF-8');

$cfg = cfg(); require_auth($cfg);
[$root, $outDir] = root_paths($cfg);

// rate-limit
$metaPath = $outDir . '/.last_run';
$now = time(); $last = is_file($metaPath) ? (int)@file_get_contents($metaPath) : 0;
$minGap = (int)($cfg['min_seconds_between_runs'] ?? 60);
if ($minGap > 0 && $last && ($now - $last) < $minGap) {
  $wait = $minGap - ($now - $last);
  fail(429, "Too Many Requests – wait {$wait}s");
}

// job
$jobId = date('Ymd-His') . '-' . bin2hex(random_bytes(4));
$jobDir = $outDir . '/jobs/' . $jobId; ensure_dir($jobDir);
$listFile = $jobDir . '/files.txt';
$stateFile = $jobDir . '/state.json';
$zipPath = $jobDir . '/backup-' . $jobId . '.zip';

// vytvoř ZIP (prázdný)
$zip = new ZipArchive();
if ($zip->open($zipPath, ZipArchive::CREATE) !== true) fail(500, 'Cannot open zip');
$zip->setArchiveComment('Created: ' . date('c')); $zip->close();

// naskenuj strom → zapisuj do files.txt
$ex = (array)$cfg['exclude_paths'];
$outRel = ltrim(str_replace($root, '', $outDir), DIRECTORY_SEPARATOR);
$outRelNorm = $outRel !== '' ? norm($outRel) : null;

$scanLimit = (int)($cfg['scan_limit'] ?? 0);
$count = 0;
$fh = fopen($listFile, 'wb');
if (!$fh) fail(500, 'Cannot write file list');

$it = new RecursiveIteratorIterator(
  new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
  RecursiveIteratorIterator::SELF_FIRST
);
foreach ($it as $item) {
  $abs = $item->getPathname();
  if (is_link($abs)) continue;
  if ($item->isDir()) continue; // do ZIPu budeme přidávat jen soubory; prázdné adresáře se vytvoří automaticky

  $rel = ltrim(str_replace($root, '', $abs), DIRECTORY_SEPARATOR);
  $n   = norm($rel);

  if ($outRelNorm && str_starts_with($n.'/', $outRelNorm.'/')) continue; // nikdy nezahrnuj outDir
  if (excluded($n, $ex)) continue;

  fwrite($fh, $rel . "\n");
  $count++;
  if ($scanLimit > 0 && $count >= $scanLimit) break;
}
fclose($fh);

// uložit stav
$state = ['id'=>$jobId,'root'=>$root,'zip'=>$zipPath,'list'=>$listFile,'pos'=>0,'total'=>$count,'done'=>false];
file_put_contents($stateFile, json_encode($state, JSON_UNESCAPED_SLASHES));

// zapsat last_run
@file_put_contents($metaPath, (string)$now);

echo json_encode(['ok'=>true,'job'=>['id'=>$jobId,'total'=>$count]]);
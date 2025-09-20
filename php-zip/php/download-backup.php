<?php
declare(strict_types=1);
require __DIR__ . '/common.php';
no_cache();

$cfg = cfg(); require_auth($cfg);

$jobId = (string)($_GET['job'] ?? '');
if ($jobId === '') fail(400, 'Missing job');

[$root, $outDir] = root_paths($cfg);
$jobDir = $outDir . '/jobs/' . $jobId;
$stateFile = $jobDir . '/state.json';
if (!is_file($stateFile)) fail(404, 'Job not found');

$state = json_decode(file_get_contents($stateFile), true);
if (!$state || empty($state['done'])) fail(409, 'Not ready');

$zipPath = $state['zip'];
if (!is_file($zipPath)) fail(404, 'File missing');

header('Content-Type: application/zip');
header('Content-Length: ' . (string)filesize($zipPath));
header('Content-Disposition: attachment; filename="' . basename($zipPath) . '"');

$fh = fopen($zipPath, 'rb');
if ($fh) { while (!feof($fh)) { echo fread($fh, 8192); @ob_flush(); flush(); } fclose($fh); }

@unlink($zipPath);
// uklid složku jobu
@unlink($state['list']);
@unlink($stateFile);
@rmdir($jobDir);
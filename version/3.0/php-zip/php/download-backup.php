<?php
declare(strict_types=1);
require __DIR__ . '/common.php';
no_cache();

$cfg = cfg(); require_auth($cfg);

// read job from POST (no GET) and validate format
$jobId = (string)($_POST['job'] ?? '');
if ($jobId === '') fail(400, 'Missing job');
if (!preg_match('~^\d{8}-\d{6}-[0-9a-f]{8}$~', $jobId)) fail(400, 'Invalid job');

[$root, $outDir] = root_paths($cfg);
$jobsBase = realpath($outDir . '/jobs') ?: '';
if ($jobsBase === '' || !is_dir($jobsBase)) fail(500, 'Jobs dir missing');

$jobDir = $jobsBase . DIRECTORY_SEPARATOR . $jobId;
$jobDirReal = realpath($jobDir) ?: '';
if ($jobDirReal === '' || !str_starts_with($jobDirReal, $jobsBase . DIRECTORY_SEPARATOR)) fail(404, 'Job not found');

$stateFile = $jobDirReal . '/state.json';
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
@rmdir($jobDirReal);
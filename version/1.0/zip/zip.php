<?php
session_start();

$config = require __DIR__ . '/config.php';
require_once 'BackupEngine.php';
require_once 'DatabaseDumper.php';

$log = function ($msg) {
    file_put_contents(__DIR__ . '/debug.log', "[" . date('c') . "] $msg\n", FILE_APPEND);
};

$data = [];
if ($config['enableCSRF']) {
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(16));
        echo json_encode(['csrf_token' => $_SESSION['csrf_token']]);
        exit;
    } else {
        $data = json_decode(file_get_contents('php://input'), true);
        if (empty($data['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $data['csrf_token'])) {
            http_response_code(400);
            echo json_encode(['error' => 'Invalid CSRF token']);
            exit;
        }
    }
} else {
    $data = json_decode(file_get_contents('php://input'), true);
}

$mode = $data['mode'] ?? 'both';
$log("Požadovaný režim: $mode");

$files = [];

if ($mode === 'ftp' || $mode === 'both') {
    $engine = new BackupEngine($config, $config['backupRoot']);
    $log("Získané FTP soubory:\n" . print_r($ftpFiles, true));
    $ftpFiles = $engine->getAllFiles();
    $log("FTP souborů: " . count($ftpFiles));
    $files = array_merge($files, $ftpFiles);
}

if ($mode === 'dump' || $mode === 'both') {
    $dumpFiles = DatabaseDumper::dumpAll($config['databases']);
    $log("Dump souborů: " . count($dumpFiles));
    $files = array_merge($files, $dumpFiles);
}

if (empty($files)) {
    $log("Žádné soubory k zazipování.");
    echo json_encode(['error' => 'Nebyl nalezen žádný soubor k záloze.']);
    exit;
}

$zipName = 'backup_' . date('Ymd_His') . '.zip';
$zipFile = $config['backupDir'] . '/' . $zipName;
$ok = $engine->createZip($files, $zipFile);
$log("ZIP vytvořen: " . ($ok ? 'ANO' : 'NE') . " → $zipFile");

if (!$ok) {
    echo json_encode(['error' => 'Nepodařilo se vytvořit ZIP archiv.']);
    exit;
}

$response = ['complete' => true, 'zipUrl' => $zipName];

echo json_encode($response);

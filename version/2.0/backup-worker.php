<?php
/**
 * Worker script pro zpracování zálohy na pozadí
 * Spouští se přes shell exec z api.php nebo přímo z api.php jako fallback
 */

// Zajistit, že skript běží nezávisle na HTTP spojení
ignore_user_abort(true);
set_time_limit(0);

// Načíst konfiguraci
$configFile = __DIR__ . '/config.php';
if (!file_exists($configFile)) {
    error_log("Backup worker: Config file not found: $configFile");
    exit(1);
}

$config = require $configFile;

// Získat parametry z příkazové řádky (pokud se spouští přes exec)
// nebo z globálních proměnných (pokud se spouští přímo)
if (php_sapi_name() === 'cli') {
    // Spuštěno přes CLI
    $jobId = $argv[1] ?? null;
    $mode = $argv[2] ?? 'both';
} else {
    // Spuštěno přímo z api.php (fallback)
    // Parametry jsou předány přes globální proměnné nebo $_GET
    $jobId = $_GET['job_id'] ?? $GLOBALS['backup_job_id'] ?? null;
    $mode = $_GET['mode'] ?? $GLOBALS['backup_mode'] ?? 'both';
}

$statusFile = $config['backup_dir'] . '/.status_' . $jobId . '.json';

if (empty($jobId)) {
    error_log("Backup worker: Missing job_id");
    exit(1);
}

// Načíst databázové přístupy z JSON souboru (pokud existuje)
$databasesFile = $config['backup_dir'] . '/.databases_' . $jobId . '.json';
$databases = null;
if (file_exists($databasesFile)) {
    $databasesJson = file_get_contents($databasesFile);
    $databases = json_decode($databasesJson, true);
    // Smazat soubor s databázemi po načtení (bezpečnost)
    @unlink($databasesFile);
}

try {
    // Uložit status: processing
    $statusData = [
        'job_id' => $jobId,
        'status' => 'processing',
        'started_at' => date('Y-m-d H:i:s'),
        'mode' => $mode,
    ];
    file_put_contents($statusFile, json_encode($statusData, JSON_UNESCAPED_UNICODE));
    
    // Načíst BackupManager
    require_once __DIR__ . '/BackupManager.php';
    $backupManager = new BackupManager($config);
    
    // Vytvořit callback pro aktualizaci statusu
    $updateStatus = function($progress = null) use ($statusFile, $statusData) {
        $update = [
            'job_id' => $statusData['job_id'],
            'status' => 'processing',
            'started_at' => $statusData['started_at'],
            'mode' => $statusData['mode'],
            'updated_at' => date('Y-m-d H:i:s'),
        ];
        if ($progress !== null) {
            $update['progress'] = $progress;
        }
        @file_put_contents($statusFile, json_encode($update, JSON_UNESCAPED_UNICODE));
    };
    
    // Spustit zálohu
    error_log("Backup worker: Starting backup job $jobId");
    $result = $backupManager->createBackup($mode, $databases, $updateStatus);
    
    // Uložit status: completed
    $completedData = [
        'job_id' => $jobId,
        'status' => 'completed',
        'started_at' => $statusData['started_at'],
        'completed_at' => date('Y-m-d H:i:s'),
        'zip_file' => $result['zip_file'],
        'files_count' => $result['files_count'],
        'errors' => $result['errors'] ?? [],
    ];
    file_put_contents($statusFile, json_encode($completedData, JSON_UNESCAPED_UNICODE));
    
    error_log("Backup worker: Completed backup job $jobId: " . $result['zip_file']);
    exit(0);
    
} catch (Throwable $e) {
    // Uložit status: error
    $errorData = [
        'job_id' => $jobId,
        'status' => 'error',
        'started_at' => $statusData['started_at'] ?? date('Y-m-d H:i:s'),
        'error' => $e->getMessage(),
        'trace' => $e->getTraceAsString(),
    ];
    file_put_contents($statusFile, json_encode($errorData, JSON_UNESCAPED_UNICODE));
    
    error_log("Backup worker: Failed backup job $jobId: " . $e->getMessage());
    exit(1);
}


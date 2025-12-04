<?php
/**
 * API endpoint pro vytváření záloh
 * 
 * Bezpečnostní opatření:
 * - Token autentizace
 * - CSRF ochrana
 * - Rate limiting
 * - Validace vstupů
 */

// Nastavit JSON hlavičku ÚPLNĚ NA ZAČÁTKU, před jakýmkoli výstupem
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

// Nastavení error handlingu - všechny chyby jako JSON
error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');

// Zvýšit limity pro velké zálohy
@ini_set('memory_limit', '1024M'); // 1GB paměť
@ini_set('max_execution_time', '3600'); // 1 hodina
@set_time_limit(3600);

// Zapnout output buffering pro zachycení všech výstupů
// Vymazat všechny existující buffery
while (ob_get_level()) {
    ob_end_clean();
}
ob_start();

// Funkce pro odeslání JSON odpovědi (musí být definována brzy)
function jsonResponse(array $data, int $statusCode = 200): void {
    // Vymazat všechny výstupy
    while (ob_get_level()) {
        ob_end_clean();
    }
    
    // Zajistit JSON hlavičky
    header_remove();
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    http_response_code($statusCode);
    
    // Vypnout všechny error reporting výstupy
    $output = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    
    if ($output === false) {
        // Pokud JSON encoding selže, vrátit chybu
        header('Content-Type: application/json; charset=utf-8');
        http_response_code(500);
        echo json_encode(['error' => 'Chyba při kódování JSON odpovědi'], JSON_UNESCAPED_UNICODE);
    } else {
        echo $output;
    }
    
    // Ukončit skript
    if (function_exists('fastcgi_finish_request')) {
        fastcgi_finish_request();
    }
    exit;
}

// Funkce pro chybovou odpověď
function errorResponse(string $message, int $statusCode = 400): void {
    jsonResponse(['error' => $message], $statusCode);
}

// Zachytit všechny chyby a varování
set_error_handler(function($errno, $errstr, $errfile, $errline) {
    // Ignorovat varování, pokud není kritické
    if (!(error_reporting() & $errno)) {
        return false;
    }
    
    // Kritické chyby zachytit
    if (in_array($errno, [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR])) {
        ob_clean();
        header('Content-Type: application/json; charset=utf-8');
        http_response_code(500);
        echo json_encode([
            'error' => 'Fatální chyba: ' . $errstr,
            'file' => basename($errfile),
            'line' => $errline
        ], JSON_UNESCAPED_UNICODE);
        ob_end_flush();
        exit;
    }
    
    return false; // Nechat PHP zpracovat ostatní chyby
});

// Zachytit fatální chyby (nastavit až po definici funkcí)
register_shutdown_function(function() {
    $error = error_get_last();
    if ($error !== null && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
        // Zkontrolovat, zda už není odeslána JSON odpověď
        $headers = headers_list();
        $hasJsonHeader = false;
        foreach ($headers as $header) {
            if (stripos($header, 'Content-Type: application/json') !== false) {
                $hasJsonHeader = true;
                break;
            }
        }
        
        if (!$hasJsonHeader) {
            // Vymazat všechny buffery
            while (ob_get_level()) {
                ob_end_clean();
            }
            
            // Nastavit JSON hlavičky
            header_remove();
            header('Content-Type: application/json; charset=utf-8');
            http_response_code(500);
            
            $errorMsg = 'Fatální chyba: ' . $error['message'];
            // V produkci nezobrazovat detaily
            if (ini_get('display_errors')) {
                $errorMsg .= ' v ' . basename($error['file'] ?? 'unknown') . ':' . ($error['line'] ?? 0);
            }
            
            echo json_encode(['error' => $errorMsg], JSON_UNESCAPED_UNICODE);
            
            if (function_exists('fastcgi_finish_request')) {
                fastcgi_finish_request();
            }
        }
        exit;
    }
});

try {
    // Vymazat cache před načtením konfigurace, aby se vždy načetl aktuální token
    $configFile = __DIR__ . '/config.php';
    clearstatcache(true, $configFile);
    
    // Pokud je soubor právě upraven, počkat chvíli, aby se stihl uložit
    $fileTime = filemtime($configFile);
    $currentTime = time();
    if ($currentTime - $fileTime < 2) {
        // Soubor byl upraven v posledních 2 sekundách, počkat chvíli
        usleep(200000); // 0.2 sekundy
        clearstatcache(true, $configFile);
    }
    
    // Načtení konfigurace
    $config = require $configFile;
    require_once __DIR__ . '/BackupManager.php';
} catch (Exception $e) {
    errorResponse('Chyba při načítání konfigurace: ' . $e->getMessage(), 500);
} catch (Error $e) {
    errorResponse('Chyba při načítání konfigurace: ' . $e->getMessage(), 500);
}

// Funkce pro nastavení JSON hlaviček
function setJsonHeaders(): void {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
}

// ===== AUTENTIZACE =====
$providedToken = $_SERVER['HTTP_X_API_TOKEN'] ?? $_GET['token'] ?? null;
$expectedToken = $config['api_token'];

if (empty($providedToken) || !hash_equals($expectedToken, $providedToken)) {
    errorResponse('Neplatný nebo chybějící API token', 401);
}

// ===== CSRF OCHRANA =====
// Poznámka: GET požadavky pro download/list nepotřebují CSRF token
$method = $_SERVER['REQUEST_METHOD'];
$needsCsrf = false;

if ($config['enable_csrf'] ?? true) {
    session_start();
    
    // CSRF token je potřeba pouze pro POST a DELETE požadavky
    // GET požadavky s parametrem download nebo list jsou výjimka
    if ($method === 'POST' || $method === 'DELETE') {
        $needsCsrf = true;
    } elseif ($method === 'GET' && !isset($_GET['download']) && !isset($_GET['list'])) {
        // GET bez download/list - vrátit CSRF token
        if (!isset($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }
        jsonResponse(['csrf_token' => $_SESSION['csrf_token']]);
    }
    
    // Ověření CSRF tokenu pro POST a DELETE
    if ($needsCsrf) {
        $input = json_decode(file_get_contents('php://input'), true) ?? [];
        $providedCsrf = $input['csrf_token'] ?? $_POST['csrf_token'] ?? null;
        $expectedCsrf = $_SESSION['csrf_token'] ?? null;
        
        if (empty($providedCsrf) || empty($expectedCsrf) || !hash_equals($expectedCsrf, $providedCsrf)) {
            errorResponse('Neplatný CSRF token', 403);
        }
    }
} else {
    $input = json_decode(file_get_contents('php://input'), true) ?? [];
}

// ===== RATE LIMITING =====
// DOČASNĚ VYPNUTO - pro opětovné zapnutí nastavte rate_limit_seconds > 0 v config.php
$rateLimitSeconds = $config['rate_limit_seconds'] ?? 0;

if ($rateLimitSeconds > 0) {
    $rateLimitFile = __DIR__ . '/.rate_limit';
    
    if (file_exists($rateLimitFile)) {
        $lastRun = (int)file_get_contents($rateLimitFile);
        $timeSinceLastRun = time() - $lastRun;
        
        if ($timeSinceLastRun < $rateLimitSeconds) {
            $waitTime = $rateLimitSeconds - $timeSinceLastRun;
            errorResponse("Rate limit: Počkejte $waitTime sekund před dalším spuštěním zálohy", 429);
        }
    }
}

// ===== ZÁMĚK PROTI SOUBĚHU =====
$lockFile = __DIR__ . '/.backup.lock';
$lockHandle = @fopen($lockFile, 'c');

if (!$lockHandle || !flock($lockHandle, LOCK_EX | LOCK_NB)) {
    if ($lockHandle) {
        fclose($lockHandle);
    }
    errorResponse('Záloha již probíhá, zkuste to později', 409);
}

// Zaznamenat čas spuštění (pouze pokud je rate limiting zapnutý)
if ($rateLimitSeconds > 0) {
    file_put_contents($rateLimitFile, (string)time());
}

try {
    $backupManager = new BackupManager($config);
    
    // Zpracování požadavku
    if ($method === 'GET') {
        // Status zálohy
        if (isset($_GET['status'])) {
            $jobId = basename($_GET['status']);
            $statusFile = $config['backup_dir'] . '/.status_' . $jobId . '.json';
            
            if (file_exists($statusFile)) {
                $status = json_decode(file_get_contents($statusFile), true);
                if ($status) {
                    jsonResponse($status);
                } else {
                    errorResponse('Chyba při načítání statusu', 500);
                }
            } else {
                errorResponse('Status zálohy neexistuje', 404);
            }
        }
        
        // Seznam záloh
        if (isset($_GET['list'])) {
            $backups = $backupManager->listBackups();
            jsonResponse(['backups' => $backups]);
        }
        
        // Stáhnout zálohu
        if (isset($_GET['download'])) {
            $filename = basename($_GET['download']);
            $filepath = $config['backup_dir'] . '/' . $filename;
            
            if (!file_exists($filepath) || !preg_match('/^backup_\d{8}_\d{6}\.zip$/', $filename)) {
                setJsonHeaders();
                errorResponse('Záloha neexistuje', 404);
            }
            
            // Vymazat všechny předchozí hlavičky
            header_remove();
            
            // Nastavit hlavičky pro stažení ZIP
            header('Content-Type: application/zip');
            header('Content-Disposition: attachment; filename="' . $filename . '"');
            header('Content-Length: ' . filesize($filepath));
            header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
            header('Pragma: no-cache');
            
            // Vypnout výstupní buffering pro velké soubory
            if (ob_get_level()) {
                ob_end_clean();
            }
            
            readfile($filepath);
            exit;
        }
        
        // CSRF token (už zpracováno výše)
    }
    
    if ($method === 'POST') {
        // Vytvoření zálohy
        $mode = $input['mode'] ?? 'both';
        
        // Validace režimu
        if (!in_array($mode, ['both', 'files', 'database'])) {
            errorResponse('Neplatný režim zálohy', 400);
        }
        
        // Validace backup_root, pokud je potřeba zálohovat soubory
        if ($mode === 'files' || $mode === 'both') {
            $backupRoot = $config['backup_root'] ?? '';
            if (empty($backupRoot)) {
                errorResponse('Cesta pro zálohu není nastavena. Prosím, nastavte ji pomocí setup/index.php', 400);
            }
            if (!is_dir($backupRoot)) {
                errorResponse('Kořenový adresář pro zálohu neexistuje: ' . htmlspecialchars($backupRoot) . '. Zkontrolujte nastavení v setup/index.php', 400);
            }
        }
        
        // Získat databázové přístupy z POST (pokud jsou)
        $databases = $input['databases'] ?? null;
        
        // Pokud jsou databáze v požadavku, validovat je
        if ($databases !== null && is_array($databases)) {
            // Validace databázových přístupů
            foreach ($databases as $db) {
                if (empty($db['host']) || empty($db['user']) || empty($db['name'])) {
                    errorResponse('Neplatné přístupy do databáze: host, user a name jsou povinné', 400);
                }
            }
        }
        
        // Vytvořit zálohu s databázemi z POST nebo z config
        try {
            // Vytvořit job ID pro tracking
            $jobId = uniqid('backup_', true);
            $statusFile = $config['backup_dir'] . '/.status_' . $jobId . '.json';
            
            // Okamžitě vrátit JSON odpověď a nechat zálohu běžet na pozadí
            $response = [
                'success' => true,
                'message' => 'Záloha byla zahájena',
                'job_id' => $jobId,
                'status' => 'processing',
            ];
            
            // Vymazat všechny buffery
            while (ob_get_level()) {
                ob_end_clean();
            }
            
            // Zajistit, že JSON hlavička je nastavena
            header_remove();
            header('Content-Type: application/json; charset=utf-8');
            header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
            header('Pragma: no-cache');
            
            $json = json_encode($response, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
            if ($json === false) {
                throw new Exception('Chyba při kódování JSON odpovědi: ' . json_last_error_msg());
            }
            
            echo $json;
            
            // Ukončit HTTP odpověď a nechat zálohu běžet na pozadí
            if (function_exists('fastcgi_finish_request')) {
                fastcgi_finish_request();
            } else {
                // Fallback pro non-FPM prostředí
                if (ob_get_level()) {
                    ob_end_flush();
                }
                flush();
            }
            
            // Záloha běží na pozadí
            try {
                // Uložit status: processing
                file_put_contents($statusFile, json_encode([
                    'job_id' => $jobId,
                    'status' => 'processing',
                    'started_at' => date('Y-m-d H:i:s'),
                    'mode' => $mode,
                ]));
                
                $result = $backupManager->createBackup($mode, $databases);
                
                // Vymazat databázové přístupy z paměti (bezpečnost)
                if ($databases !== null) {
                    unset($input['databases']);
                    $databases = null;
                }
                
                // Uložit status: completed
                file_put_contents($statusFile, json_encode([
                    'job_id' => $jobId,
                    'status' => 'completed',
                    'started_at' => date('Y-m-d H:i:s'),
                    'completed_at' => date('Y-m-d H:i:s'),
                    'zip_file' => $result['zip_file'],
                    'files_count' => $result['files_count'],
                    'errors' => $result['errors'] ?? [],
                ]));
            } catch (Throwable $e) {
                // Uložit status: error
                file_put_contents($statusFile, json_encode([
                    'job_id' => $jobId,
                    'status' => 'error',
                    'started_at' => date('Y-m-d H:i:s'),
                    'error' => $e->getMessage(),
                ]));
            }
            
            exit;
        } catch (Throwable $e) {
            // Vymazat databázové přístupy z paměti před vrácením chyby
            if ($databases !== null) {
                unset($input['databases']);
                $databases = null;
            }
            
            // Zajistit, že odpověď je JSON
            while (ob_get_level()) {
                ob_end_clean();
            }
            header_remove();
            header('Content-Type: application/json; charset=utf-8');
            errorResponse('Chyba při vytváření zálohy: ' . $e->getMessage(), 500);
        }
    }
    
    if ($method === 'DELETE') {
        // Smazání zálohy (jednotlivé nebo hromadné)
        $input = json_decode(file_get_contents('php://input'), true) ?? [];
        
        // Podpora pro hromadné smazání
        $filenames = $input['filenames'] ?? [];
        if (empty($filenames) && isset($input['filename'])) {
            // Zpětná kompatibilita - jednotlivé smazání
            $filenames = [$input['filename']];
        }
        
        if (empty($filenames)) {
            errorResponse('Nebyly zadány žádné zálohy ke smazání', 400);
        }
        
        $deleted = [];
        $failed = [];
        
        foreach ($filenames as $filename) {
            // Validace názvu souboru
            if (empty($filename) || !preg_match('/^backup_\d{8}_\d{6}\.zip$/', $filename)) {
                $failed[] = $filename . ' (neplatný název)';
                continue;
            }
            
            $filepath = $config['backup_dir'] . '/' . $filename;
            
            if (!file_exists($filepath)) {
                $failed[] = $filename . ' (neexistuje)';
                continue;
            }
            
            if (@unlink($filepath)) {
                $deleted[] = $filename;
            } else {
                $failed[] = $filename . ' (chyba při mazání)';
            }
        }
        
        $message = '';
        if (count($deleted) > 0) {
            $message = 'Smazáno záloh: ' . count($deleted);
        }
        if (count($failed) > 0) {
            $message .= (count($deleted) > 0 ? '. ' : '') . 'Chyby: ' . implode(', ', $failed);
        }
        
        jsonResponse([
            'success' => count($failed) === 0,
            'message' => $message ?: 'Žádné zálohy ke smazání',
            'deleted' => $deleted,
            'failed' => $failed,
            'deleted_count' => count($deleted),
            'failed_count' => count($failed)
        ]);
    }
    
    errorResponse('Nepodporovaná metoda', 405);
    
} catch (Exception $e) {
    errorResponse('Chyba při vytváření zálohy: ' . $e->getMessage(), 500);
} finally {
    // Uvolnit zámek
    if ($lockHandle) {
        flock($lockHandle, LOCK_UN);
        fclose($lockHandle);
        @unlink($lockFile);
    }
}


<?php
/**
 * Setup rozhraní pro nastavení API tokenu
 * 
 * PO DOKONČENÍ NASTAVENÍ TENTO ADRESÁŘ SMAŽTE NEBO ZABLOKUJTE!
 */

// Načtení konfigurace
$configFile = dirname(__DIR__) . '/config.php';
$config = file_exists($configFile) ? require $configFile : [];

// Zpracování formuláře
$message = '';
$messageType = '';
$currentToken = $config['api_token'] ?? '';
$tokenGenerated = false;
$backupRootSet = false;
$currentBackupRoot = $config['backup_root'] ?? '';

// Inicializace session pro CSRF ochranu
session_start();
if (!isset($_SESSION['setup_csrf_token'])) {
    $_SESSION['setup_csrf_token'] = bin2hex(random_bytes(32));
}
$csrfToken = $_SESSION['setup_csrf_token'];

// Funkce pro kontrolu, zda je token skutečně nastavený (ne výchozí hodnota)
function isTokenValid($token) {
    // Kontrola, zda token není prázdný nebo null
    if (empty($token) || !is_string($token)) {
        return false;
    }
    
    // Výchozí hodnoty, které by neměly být považovány za validní tokeny
    $defaultTokens = [
        'ZMENTE_TENTO_TOKEN_NA_SILNY_NAHODNY_STRING',
        '478548f1d746fa63f627c01c83fcdb098c3646976d30fa07c41be3d0a1337e79' // Výchozí token z configu
    ];
    
    // Přesná kontrola výchozích tokenů
    if (in_array(trim($token), $defaultTokens, true)) {
        return false;
    }
    
    // Token by měl být hex string o délce 64 znaků (32 bytes = 64 hex znaků)
    $token = trim($token);
    if (strlen($token) !== 64) {
        return false;
    }
    
    // Kontrola, zda jsou všechny znaky hexadecimální
    if (!ctype_xdigit($token)) {
        return false;
    }
    
    return true;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['generate_token'])) {
    // Vygenerovat nový token
    $newToken = bin2hex(random_bytes(32));
    
    // Načíst obsah config.php
    $configContent = file_get_contents($configFile);
    
    if ($configContent === false) {
        $message = 'Chyba: Nelze načíst config.php';
        $messageType = 'error';
    } else {
        // Nahradit token v config.php
        $pattern = "/'api_token'\s*=>\s*[^,]+/";
        $replacement = "'api_token' => getenv('BACKUP_API_TOKEN') ?: '" . $newToken . "'";
        
        $newContent = preg_replace($pattern, $replacement, $configContent);
        
        if ($newContent === null) {
            $message = 'Chyba: Nepodařilo se upravit config.php';
            $messageType = 'error';
        } else {
            // Zapsat zpět do souboru
            if (file_put_contents($configFile, $newContent) !== false) {
                // Vymazat cache, aby se nový token načetl správně
                clearstatcache(true, $configFile);
                
                // Znovu načíst config, aby se ověřilo, že token je správně uložen
                // Vymazat cache před načtením
                clearstatcache(true, $configFile);
                
                // Načíst config znovu
                $config = require $configFile;
                $savedToken = $config['api_token'] ?? '';
                
                // Ověřit, zda se token správně uložil
                // Porovnat s novým tokenem (může být přepsán getenv, ale měl by být stejný nebo nový)
                if (isTokenValid($savedToken) && ($savedToken === $newToken || hash_equals($savedToken, $newToken))) {
                    $message = 'Token byl úspěšně vygenerován a uložen do config.php!';
                    $messageType = 'success';
                    $currentToken = $newToken;
                    $tokenGenerated = true;
                } elseif (isTokenValid($savedToken)) {
                    // Token je validní, ale není to nový token (možná getenv přepsal)
                    // To je v pořádku, pokud je token validní
                    $message = 'Token byl úspěšně vygenerován a uložen do config.php!';
                    $messageType = 'success';
                    $currentToken = $savedToken;
                    $tokenGenerated = true;
                } else {
                    $message = 'Varování: Token byl uložen, ale ověření selhalo. Zkuste obnovit stránku.';
                    $messageType = 'error';
                }
                
                // Počkat chvíli, aby se soubor stihl uložit na disk
                usleep(200000); // 0.2 sekundy
            } else {
                $message = 'Chyba: Nepodařilo se zapsat do config.php. Zkontrolujte oprávnění.';
                $messageType = 'error';
            }
        }
    }
}

// Zpracování nastavení cesty pro zálohu
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['set_backup_path'])) {
    $webhostNumber = trim($_POST['webhost_number'] ?? '');
    
    // Ověření CSRF tokenu
    $providedCsrf = $_POST['csrf_token'] ?? null;
    $expectedCsrf = $_SESSION['setup_csrf_token'] ?? null;
    
    if (empty($providedCsrf) || empty($expectedCsrf) || !hash_equals($expectedCsrf, $providedCsrf)) {
        $message = 'Chyba: Neplatný CSRF token. Zkuste to znovu.';
        $messageType = 'error';
    } elseif (empty($webhostNumber) || !preg_match('/^\d+$/', $webhostNumber)) {
        $message = 'Chyba: Zadejte platné číslo webhostingu (pouze číslice).';
        $messageType = 'error';
    } else {
        // Vytvořit cestu
        $backupPath = '/data/web/virtuals/' . $webhostNumber . '/virtual';
        
        // Načíst obsah config.php
        $configContent = file_get_contents($configFile);
        
        if ($configContent === false) {
            $message = 'Chyba: Nelze načíst config.php';
            $messageType = 'error';
        } else {
            // Nahradit backup_root v config.php
            $pattern = "/'backup_root'\s*=>\s*[^,]+/";
            $replacement = "'backup_root' => '" . addslashes($backupPath) . "'";
            
            $newContent = preg_replace($pattern, $replacement, $configContent);
            
            if ($newContent === null) {
                $message = 'Chyba: Nepodařilo se upravit config.php';
                $messageType = 'error';
            } else {
                // Zapsat zpět do souboru
                if (file_put_contents($configFile, $newContent) !== false) {
                    clearstatcache(true, $configFile);
                    $message = 'Cesta pro zálohu byla úspěšně nastavena na: ' . htmlspecialchars($backupPath);
                    $messageType = 'success';
                    $currentBackupRoot = $backupPath;
                    $backupRootSet = true;
                } else {
                    $message = 'Chyba: Nepodařilo se zapsat do config.php. Zkontrolujte oprávnění.';
                    $messageType = 'error';
                }
            }
        }
    }
}

// Zpracování smazání setup adresáře
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_setup'])) {
    // Ověření CSRF tokenu
    $providedCsrf = $_POST['csrf_token'] ?? null;
    $expectedCsrf = $_SESSION['setup_csrf_token'] ?? null;
    
    if (empty($providedCsrf) || empty($expectedCsrf) || !hash_equals($expectedCsrf, $providedCsrf)) {
        $message = 'Chyba: Neplatný CSRF token. Zkuste to znovu.';
        $messageType = 'error';
    } else {
        $setupDir = __DIR__;
    
    // Funkce pro rekurzivní smazání adresáře
    function deleteDirectory($dir) {
        if (!is_dir($dir)) {
            return false;
        }
        
        $files = array_diff(scandir($dir), array('.', '..'));
        
        foreach ($files as $file) {
            $path = $dir . DIRECTORY_SEPARATOR . $file;
            if (is_dir($path)) {
                deleteDirectory($path);
            } else {
                @unlink($path);
            }
        }
        
        return @rmdir($dir);
    }
    
        if (deleteDirectory($setupDir)) {
            // Pokud se podařilo smazat, přesměrujeme na hlavní stránku
            header('Location: ../index.php?setup_deleted=1');
            exit;
        } else {
            $message = 'Chyba: Nepodařilo se smazat setup adresář. Zkuste to ručně přes FTP.';
            $messageType = 'error';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="cs">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Nastavení API Tokenu - PHP Backup Tool</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }
        
        .container {
            background: white;
            border-radius: 16px;
            box-shadow: 0 20px 60px rgba(0,0,0,0.3);
            max-width: 600px;
            width: 100%;
            padding: 40px;
        }
        
        h1 {
            color: #333;
            margin-bottom: 10px;
            font-size: 1.8rem;
        }
        
        .subtitle {
            color: #666;
            margin-bottom: 30px;
            font-size: 0.95rem;
        }
        
        .message {
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 20px;
        }
        
        .message.success {
            background: #d4edda;
            border: 2px solid #28a745;
            color: #155724;
        }
        
        .message.error {
            background: #f8d7da;
            border: 2px solid #dc3545;
            color: #721c24;
        }
        
        .info-box {
            background: #f8f9fa;
            border-left: 4px solid #667eea;
            padding: 15px;
            margin-bottom: 20px;
            border-radius: 4px;
        }
        
        .info-box strong {
            color: #667eea;
        }
        
        button {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border: none;
            padding: 12px 24px;
            border-radius: 8px;
            font-size: 1rem;
            font-weight: 600;
            cursor: pointer;
            width: 100%;
            margin-top: 10px;
            transition: transform 0.2s;
        }
        
        button:hover {
            transform: translateY(-2px);
        }
        
        .warning {
            background: #fff3cd;
            border: 2px solid #ffc107;
            color: #856404;
            padding: 15px;
            border-radius: 8px;
            margin-top: 20px;
            font-size: 0.9rem;
        }
        
        .warning strong {
            color: #856404;
        }
        
        .delete-btn {
            background: #dc3545;
            margin-top: 15px;
        }
        
        .delete-btn:hover {
            background: #c82333;
        }
        
        .success-actions {
            margin-top: 20px;
            padding-top: 20px;
            border-top: 2px solid #e0e0e0;
        }
        
        input[type="text"], input[type="number"] {
            width: 100%;
            padding: 10px;
            border: 2px solid #ddd;
            border-radius: 8px;
            font-size: 1rem;
            margin-top: 5px;
            box-sizing: border-box;
        }
        
        input[type="text"]:focus, input[type="number"]:focus {
            outline: none;
            border-color: #667eea;
        }
        
        label {
            display: block;
            margin-top: 15px;
            margin-bottom: 5px;
            font-weight: 600;
            color: #333;
        }
        
        .path-info {
            background: #e7f3ff;
            border-left: 4px solid #2196F3;
            padding: 12px;
            margin-top: 10px;
            border-radius: 4px;
            font-size: 0.9rem;
            color: #1976D2;
        }
        
        .path-info a {
            color: #1976D2;
            text-decoration: underline;
        }
        
        .path-display {
            background: #f0f0f0;
            padding: 10px;
            border-radius: 4px;
            font-family: monospace;
            margin-top: 10px;
            color: #333;
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>🔐 Nastavení API Tokenu</h1>
        <p class="subtitle">PHP Backup Tool v2.0</p>
        
        <?php if ($message): ?>
            <div class="message <?php echo $messageType; ?>">
                <?php echo htmlspecialchars($message); ?>
            </div>
        <?php endif; ?>
        
        <div class="info-box">
            <strong>Co je API token?</strong><br>
            API token je bezpečnostní klíč, který chrání váš backup nástroj před neoprávněným přístupem.
            Měl by být silný a jedinečný.
        </div>
        
        <?php if (isTokenValid($currentToken)): ?>
            <div class="info-box" style="background: #d1ecf1; border-left-color: #0c5460;">
                <strong>✅ Token je nastaven</strong><br>
                Aktuální token je uložen v config.php a je připraven k použití.
            </div>
        <?php else: ?>
            <div class="info-box" style="background: #fff3cd; border-left-color: #ffc107;">
                <strong>⚠️ Token není nastaven</strong><br>
                Klikněte na tlačítko níže pro vygenerování a automatické uložení tokenu.
            </div>
        <?php endif; ?>
        
        <form method="POST">
            <button type="submit" name="generate_token">
                🔑 Vygenerovat nový token
            </button>
        </form>
        
        <?php if ($tokenGenerated || isTokenValid($currentToken)): ?>
            <div class="success-actions">
                <div class="info-box" style="background: #d1ecf1; border-left-color: #0c5460;">
                    <strong>✅ Token je nastaven!</strong><br>
                    Nyní nastavte cestu pro zálohu a pak můžete přejít na hlavní stránku zálohování.
                </div>
                
                <?php 
                // Zkontrolovat, zda je cesta nastavena správně (musí obsahovat /data/web/virtuals/ a číslo)
                $isPathValid = !empty($currentBackupRoot) && 
                               preg_match('#^/data/web/virtuals/\d+/virtual$#', $currentBackupRoot);
                
                // Zobrazit formulář, pokud cesta není nastavena nebo není ve správném formátu
                $showPathForm = !$backupRootSet && !$isPathValid;
                ?>
                
                <?php if ($showPathForm): ?>
                    <form method="POST" style="margin-top: 20px;">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>">
                        
                        <label for="webhost_number">
                            📁 Identifikátor podle interní URL:
                        </label>
                        <?php 
                        // Pokud je cesta ve správném formátu, extrahovat číslo
                        $currentNumber = '';
                        if (preg_match('#/data/web/virtuals/(\d+)/virtual#', $currentBackupRoot, $matches)) {
                            $currentNumber = $matches[1];
                        }
                        ?>
                        <input type="text" 
                               id="webhost_number" 
                               name="webhost_number" 
                               pattern="[0-9]+" 
                               placeholder="např. 259668" 
                               value="<?php echo htmlspecialchars($currentNumber); ?>"
                               required
                               style="text-align: center; font-size: 1.1rem; font-weight: 600;">
                        
                        <div class="path-info">
                            <strong>ℹ️ Jak zjistit číslo webhostingu?</strong><br>
                            Číslo webhostingu je identifikátor podle interní adresy vašeho webu.<br><br>
                            <strong>Příklad:</strong> Pokud je interní adresa vašeho webu <code>http://259668.w68.wedos.ws/</code>,<br>
                            pak číslo webhostingu je <strong>259668</strong> (číslo na začátku adresy).<br><br>
                            Interní adresu najdete v administraci Wedos v detailu vašeho webhostingu:<br>
                            <a href="https://client.wedos.com/webhosting/webhost-list.html" target="_blank">https://client.wedos.com/webhosting/webhost-list.html</a>
                        </div>
                        
                        <div class="path-display" id="path-preview" style="display: none;">
                            Cesta bude nastavena na:<br>
                            <strong id="path-value"></strong>
                        </div>
                        
                        <button type="submit" name="set_backup_path" style="margin-top: 15px;">
                            💾 Nastavit cestu pro zálohu
                        </button>
                    </form>
                    
                    <script>
                        const input = document.getElementById('webhost_number');
                        const preview = document.getElementById('path-preview');
                        const pathValue = document.getElementById('path-value');
                        
                        // Zobrazit náhled, pokud je už nějaká hodnota
                        if (input.value) {
                            pathValue.textContent = '/data/web/virtuals/' + input.value + '/virtual';
                            preview.style.display = 'block';
                        }
                        
                        input.addEventListener('input', function() {
                            const number = this.value.trim();
                            
                            if (number && /^\d+$/.test(number)) {
                                pathValue.textContent = '/data/web/virtuals/' + number + '/virtual';
                                preview.style.display = 'block';
                            } else {
                                preview.style.display = 'none';
                            }
                        });
                    </script>
                <?php elseif ($backupRootSet || $isPathValid): ?>
                    <div class="info-box" style="background: #d4edda; border-left-color: #28a745; margin-top: 15px;">
                        <strong>✅ Cesta pro zálohu je nastavena!</strong><br>
                        Aktuální cesta: <code style="background: #f0f0f0; padding: 2px 6px; border-radius: 3px;"><?php echo htmlspecialchars($currentBackupRoot); ?></code>
                    </div>
                <?php endif; ?>
                
                <?php if ($backupRootSet || $isPathValid): ?>
                    <a href="../index.php?token_set=1&_=<?php echo time(); ?>" style="text-decoration: none;" onclick="window.location.href = '../index.php?token_set=1&_=' + Date.now(); return false;">
                        <button type="button" style="background: linear-gradient(135deg, #28a745 0%, #20c997 100%); margin-top: 15px;">
                            🚀 Přejít na hlavní stránku zálohování
                        </button>
                    </a>
                <?php endif; ?>
                
                <form method="POST" onsubmit="return confirm('Opravdu chcete smazat celý setup adresář? Tato akce je nevratná!');">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>">
                    <button type="submit" name="delete_setup" class="delete-btn">
                        🗑️ Smazat setup adresář (doporučeno)
                    </button>
                </form>
                
                <p style="margin-top: 10px; font-size: 0.85rem; color: #666; text-align: center;">
                    Po smazání setup adresáře se přesměrujete na hlavní stránku zálohování.
                </p>
            </div>
        <?php else: ?>
            <div class="warning">
                <strong>⚠️ Bezpečnostní upozornění:</strong><br>
                Po dokončení nastavení tokenu <strong>zablokujte nebo smažte tento adresář</strong> z bezpečnostních důvodů!
                <br><br>
                Tento adresář je chráněn .htaccess, ale pro maximální bezpečnost ho po použití odstraňte.
            </div>
        <?php endif; ?>
    </div>
    
</body>
</html>


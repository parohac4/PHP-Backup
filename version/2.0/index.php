<?php
// Vymazat cache před načtením konfigurace, aby se vždy načetl aktuální token
$configFile = __DIR__ . "/config.php";
clearstatcache(true, $configFile);

// Pokud je v URL parametr token_set, vynutit načtení nového tokenu
if (isset($_GET['token_set'])) {
    // Vymazat cache ještě jednou
    clearstatcache(true, $configFile);
    // Malé zpoždění, aby se soubor stihl uložit
    usleep(100000); // 0.1 sekundy
}

// Kontrola, zda je token nastaven
$config = require $configFile;
$apiToken = $config['api_token'] ?? '';

// Funkce pro kontrolu, zda je token skutečně nastavený (ne výchozí hodnota)
function isTokenValid($token) {
    if (empty($token) || !is_string($token)) {
        return false;
    }
    $defaultTokens = [
        'ZMENTE_TENTO_TOKEN_NA_SILNY_NAHODNY_STRING',
        '478548f1d746fa63f627c01c83fcdb098c3646976d30fa07c41be3d0a1337e79'
    ];
    if (in_array(trim($token), $defaultTokens, true)) {
        return false;
    }
    $token = trim($token);
    return strlen($token) === 64 && ctype_xdigit($token);
}

// Pokud token není nastaven, přesměrovat na setup
if (!isTokenValid($apiToken)) {
    $setupPath = __DIR__ . '/setup/index.php';
    if (file_exists($setupPath)) {
        header('Location: setup/index.php');
        exit;
    } else {
        die('❌ Chyba: API token není nastaven. Prosím, vygenerujte token pomocí setup/index.php');
    }
}
?>
<!DOCTYPE html>
<html lang="cs">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>PHP Backup Tool v2.0</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen, Ubuntu, Cantarell, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            padding: 20px;
            color: #333;
        }
        
        .container {
            max-width: 900px;
            margin: 0 auto;
            background: white;
            border-radius: 16px;
            box-shadow: 0 20px 60px rgba(0,0,0,0.3);
            overflow: hidden;
        }
        
        .header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 30px;
            text-align: center;
        }
        
        .header h1 {
            font-size: 2rem;
            margin-bottom: 10px;
        }
        
        .header p {
            opacity: 0.9;
            font-size: 0.95rem;
        }
        
        .content {
            padding: 30px;
        }
        
        .form-group {
            margin-bottom: 20px;
        }
        
        label {
            display: block;
            margin-bottom: 8px;
            font-weight: 600;
            color: #555;
        }
        
        select, button {
            width: 100%;
            padding: 12px 16px;
            border: 2px solid #e0e0e0;
            border-radius: 8px;
            font-size: 1rem;
            transition: all 0.3s;
        }
        
        select:focus {
            outline: none;
            border-color: #667eea;
        }
        
        button {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border: none;
            font-weight: 600;
            cursor: pointer;
            margin-top: 10px;
        }
        
        button:hover:not(:disabled) {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(102, 126, 234, 0.4);
        }
        
        button:disabled {
            opacity: 0.6;
            cursor: not-allowed;
        }
        
        .progress-container {
            display: none;
            margin: 20px 0;
        }
        
        .progress-bar {
            display: none; /* Skrýt progress bar, zobrazujeme jen text */
            width: 100%;
            height: 30px;
            background: #f0f0f0;
            border-radius: 15px;
            overflow: hidden;
            margin-bottom: 10px;
        }
        
        .progress-fill {
            height: 100%;
            background: linear-gradient(90deg, #667eea 0%, #764ba2 100%);
            width: 0%;
            transition: width 0.3s;
            display: none; /* Skrýt progress bar, zobrazujeme jen text */
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: 600;
            font-size: 0.9rem;
        }
        
        .progress-text {
            text-align: center;
            font-weight: 600;
            color: #667eea;
            padding: 10px;
        }
        
        .result {
            margin-top: 20px;
            padding: 20px;
            border-radius: 8px;
            display: none;
        }
        
        .result.success {
            background: #d4edda;
            border: 2px solid #28a745;
            color: #155724;
        }
        
        .result.error {
            background: #f8d7da;
            border: 2px solid #dc3545;
            color: #721c24;
        }
        
        .result h3 {
            margin-bottom: 10px;
        }
        
        .download-link {
            display: inline-block;
            margin-top: 15px;
            padding: 12px 24px;
            background: #28a745;
            color: white;
            text-decoration: none;
            border-radius: 8px;
            font-weight: 600;
            transition: all 0.3s;
        }
        
        .download-link:hover {
            background: #218838;
            transform: translateY(-2px);
        }
        
        .backups-list {
            margin-top: 30px;
            padding-top: 30px;
            border-top: 2px solid #e0e0e0;
        }
        
        .backups-list h2 {
            margin-bottom: 15px;
            color: #555;
        }
        
        .backup-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 15px;
            background: #f8f9fa;
            border-radius: 8px;
            margin-bottom: 10px;
        }
        
        .backup-info {
            flex: 1;
        }
        
        .backup-name {
            font-weight: 600;
            color: #333;
        }
        
        .backup-meta {
            font-size: 0.85rem;
            color: #666;
            margin-top: 5px;
        }
        
        .backup-actions {
            display: flex;
            gap: 10px;
        }
        
        .btn-small {
            padding: 8px 16px;
            font-size: 0.9rem;
            width: auto;
            margin: 0;
        }
        
        .btn-danger {
            background: #dc3545;
        }
        
        .btn-danger:hover:not(:disabled) {
            background: #c82333;
        }
        
        .loading {
            text-align: center;
            padding: 20px;
            color: #666;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>🔒 PHP Backup Tool v2.0</h1>
            <p>Bezpečná záloha souborů a databází</p>
        </div>
        
        <div class="content">
            <?php if (isset($_GET['setup_deleted']) && $_GET['setup_deleted'] == '1'): ?>
                <div class="result success" style="display: block; margin-bottom: 20px;">
                    <h3>✅ Setup adresář byl úspěšně smazán!</h3>
                    <p>Nyní můžete bezpečně používat nástroj pro zálohování.</p>
                </div>
            <?php endif; ?>
            
            <div class="form-group">
                <label for="mode">Typ zálohy:</label>
                <select id="mode" onchange="toggleDatabaseForm()">
                    <option value="both">Soubory + Databáze (kompletní)</option>
                    <option value="files">Pouze soubory</option>
                    <option value="database">Pouze databáze</option>
                </select>
            </div>
            
            <!-- Formulář pro přístupy do databáze -->
            <div id="database-form" class="form-group" style="display: none;">
                <h3 style="margin-bottom: 15px; color: #555;">📊 Přístupy do databáze</h3>
                <div id="database-entries">
                    <div class="database-entry" style="margin-bottom: 15px; padding: 15px; background: #f8f9fa; border-radius: 8px; border: 2px solid #e0e0e0; position: relative;">
                        <button type="button" onclick="removeDatabaseEntry(this)" style="position: absolute; top: 10px; right: 10px; background: #dc3545; color: white; border: none; padding: 5px 10px; border-radius: 4px; cursor: pointer; font-size: 0.8rem; display: none;">✕ Odstranit</button>
                        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 10px; margin-bottom: 10px;">
                            <div>
                                <label style="display: block; margin-bottom: 5px; font-weight: 600; color: #555;">Host:</label>
                                <input type="text" class="db-host" placeholder="localhost" value="localhost" style="width: 100%; padding: 8px; border: 1px solid #ddd; border-radius: 4px;">
                            </div>
                            <div>
                                <label style="display: block; margin-bottom: 5px; font-weight: 600; color: #555;">Port:</label>
                                <input type="number" class="db-port" placeholder="3306" value="3306" style="width: 100%; padding: 8px; border: 1px solid #ddd; border-radius: 4px;">
                            </div>
                        </div>
                        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 10px; margin-bottom: 10px;">
                            <div>
                                <label style="display: block; margin-bottom: 5px; font-weight: 600; color: #555;">Uživatel:</label>
                                <input type="text" class="db-user" placeholder="root" required style="width: 100%; padding: 8px; border: 1px solid #ddd; border-radius: 4px;">
                            </div>
                            <div>
                                <label style="display: block; margin-bottom: 5px; font-weight: 600; color: #555;">Heslo:</label>
                                <input type="password" class="db-pass" placeholder="heslo" style="width: 100%; padding: 8px; border: 1px solid #ddd; border-radius: 4px;">
                            </div>
                        </div>
                        <div>
                            <label style="display: block; margin-bottom: 5px; font-weight: 600; color: #555;">Název databáze:</label>
                            <input type="text" class="db-name" placeholder="nazev_databaze" required style="width: 100%; padding: 8px; border: 1px solid #ddd; border-radius: 4px;">
                        </div>
                    </div>
                </div>
                <button type="button" onclick="addDatabaseEntry()" style="background: #6c757d; color: white; border: none; padding: 8px 16px; border-radius: 6px; cursor: pointer; font-size: 0.9rem; margin-top: 10px;">
                    + Přidat další databázi
                </button>
                <p style="margin-top: 10px; font-size: 0.85rem; color: #666;">
                    ⚠️ Přístupy se neukládají a po dokončení dumpu se smažou z paměti.
                </p>
            </div>
            
            <button id="start-btn" onclick="startBackup()">🚀 Spustit zálohu</button>
            
            <div class="progress-container" id="progress-container">
                <div class="progress-bar">
                    <div class="progress-fill" id="progress-fill">0%</div>
                </div>
                <div class="loading" id="progress-text">Příprava zálohy...</div>
            </div>
            
            <div class="result" id="result"></div>
            
            <div class="backups-list">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 15px;">
                    <h2 style="margin: 0;">📦 Dostupné zálohy</h2>
                    <button id="delete-selected-btn" onclick="deleteSelectedBackups()" 
                            class="btn-small btn-danger" 
                            style="display: none;">
                        🗑️ Smazat vybrané
                    </button>
                </div>
                <div style="margin-bottom: 10px;">
                    <label style="display: flex; align-items: center; gap: 8px; cursor: pointer;">
                        <input type="checkbox" id="select-all" onchange="toggleSelectAll()" style="width: auto; cursor: pointer;">
                        <span style="font-size: 0.9rem; color: #666;">Vybrat vše</span>
                    </label>
                </div>
                <div id="backups-list">
                    <div class="loading">Načítání...</div>
                </div>
            </div>
        </div>
    </div>
    
    <script>
        const API_TOKEN = '<?php echo htmlspecialchars($apiToken, ENT_QUOTES, "UTF-8"); ?>';
        let csrfToken = null;
        
        // Funkce pro stažení zálohy
        function downloadBackup(filename) {
            const url = `api.php?token=${encodeURIComponent(API_TOKEN)}&download=${encodeURIComponent(filename)}`;
            // Přesměrovat na URL pro stažení (prohlížeč automaticky stáhne soubor)
            window.location.href = url;
        }
        
        // Zobrazit/skrýt formulář pro databázi
        function toggleDatabaseForm() {
            const mode = document.getElementById('mode').value;
            const dbForm = document.getElementById('database-form');
            
            if (mode === 'both' || mode === 'database') {
                dbForm.style.display = 'block';
                // Aktualizovat tlačítka pro odstranění
                setTimeout(updateRemoveButtons, 100);
            } else {
                dbForm.style.display = 'none';
            }
        }
        
        // Při načtení stránky aktualizovat tlačítka a načíst CSRF token
        document.addEventListener('DOMContentLoaded', function() {
            updateRemoveButtons();
            loadCsrfToken();
        });
        
        // Odstranit záznam databáze
        function removeDatabaseEntry(button) {
            const entries = document.querySelectorAll('.database-entry');
            if (entries.length > 1) {
                button.closest('.database-entry').remove();
                updateRemoveButtons();
            } else {
                alert('Musíte mít alespoň jeden záznam databáze!');
            }
        }
        
        // Aktualizovat viditelnost tlačítek pro odstranění
        function updateRemoveButtons() {
            const entries = document.querySelectorAll('.database-entry');
            entries.forEach(entry => {
                const removeBtn = entry.querySelector('button[onclick*="removeDatabaseEntry"]');
                if (removeBtn) {
                    removeBtn.style.display = entries.length > 1 ? 'block' : 'none';
                }
            });
        }
        
        // Přidat další databázi
        function addDatabaseEntry() {
            const container = document.getElementById('database-entries');
            const newEntry = document.createElement('div');
            newEntry.className = 'database-entry';
            newEntry.style.cssText = 'margin-bottom: 15px; padding: 15px; background: #f8f9fa; border-radius: 8px; border: 2px solid #e0e0e0; position: relative;';
            
            newEntry.innerHTML = `
                <button type="button" onclick="removeDatabaseEntry(this)" style="position: absolute; top: 10px; right: 10px; background: #dc3545; color: white; border: none; padding: 5px 10px; border-radius: 4px; cursor: pointer; font-size: 0.8rem;">✕ Odstranit</button>
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 10px; margin-bottom: 10px;">
                    <div>
                        <label style="display: block; margin-bottom: 5px; font-weight: 600; color: #555;">Host:</label>
                        <input type="text" class="db-host" placeholder="localhost" value="localhost" style="width: 100%; padding: 8px; border: 1px solid #ddd; border-radius: 4px;">
                    </div>
                    <div>
                        <label style="display: block; margin-bottom: 5px; font-weight: 600; color: #555;">Port:</label>
                        <input type="number" class="db-port" placeholder="3306" value="3306" style="width: 100%; padding: 8px; border: 1px solid #ddd; border-radius: 4px;">
                    </div>
                </div>
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 10px; margin-bottom: 10px;">
                    <div>
                        <label style="display: block; margin-bottom: 5px; font-weight: 600; color: #555;">Uživatel:</label>
                        <input type="text" class="db-user" placeholder="root" required style="width: 100%; padding: 8px; border: 1px solid #ddd; border-radius: 4px;">
                    </div>
                    <div>
                        <label style="display: block; margin-bottom: 5px; font-weight: 600; color: #555;">Heslo:</label>
                        <input type="password" class="db-pass" placeholder="heslo" style="width: 100%; padding: 8px; border: 1px solid #ddd; border-radius: 4px;">
                    </div>
                </div>
                <div>
                    <label style="display: block; margin-bottom: 5px; font-weight: 600; color: #555;">Název databáze:</label>
                    <input type="text" class="db-name" placeholder="nazev_databaze" required style="width: 100%; padding: 8px; border: 1px solid #ddd; border-radius: 4px;">
                </div>
            `;
            
            container.appendChild(newEntry);
            updateRemoveButtons();
        }
        
        // Získat data z formuláře databází
        function getDatabaseEntries() {
            const entries = document.querySelectorAll('.database-entry');
            const databases = [];
            
            entries.forEach(entry => {
                const host = entry.querySelector('.db-host').value.trim();
                const port = parseInt(entry.querySelector('.db-port').value) || 3306;
                const user = entry.querySelector('.db-user').value.trim();
                const pass = entry.querySelector('.db-pass').value;
                const name = entry.querySelector('.db-name').value.trim();
                
                if (host && user && name) {
                    databases.push({
                        host: host,
                        port: port,
                        user: user,
                        pass: pass,
                        name: name
                    });
                }
            });
            
            return databases;
        }
        
        // Načtení CSRF tokenu
        async function loadCsrfToken() {
            try {
                const response = await fetch('api.php?token=' + encodeURIComponent(API_TOKEN) + '&_=' + Date.now(), {
                    method: 'GET',
                    cache: 'no-cache'
                });
                if (!response.ok) {
                    console.error('Chyba při načítání CSRF tokenu: HTTP ' + response.status);
                    return;
                }
                const data = await response.json();
                if (data.csrf_token) {
                    csrfToken = data.csrf_token;
                } else {
                    console.error('Chyba: CSRF token nebyl v odpovědi');
                }
            } catch (error) {
                console.error('Chyba při načítání CSRF tokenu:', error);
            }
        }
        
        // Spuštění zálohy
        async function startBackup() {
            const mode = document.getElementById('mode').value;
            const startBtn = document.getElementById('start-btn');
            const progressContainer = document.getElementById('progress-container');
            const progressFill = document.getElementById('progress-fill');
            const progressText = document.getElementById('progress-text');
            const resultDiv = document.getElementById('result');
            
            // Validace databázových přístupů, pokud je potřeba
            if (mode === 'both' || mode === 'database') {
                const databases = getDatabaseEntries();
                if (databases.length === 0) {
                    alert('Chyba: Musíte zadat alespoň jednu databázi!');
                    return;
                }
            }
            
            // Zkontrolovat, zda je CSRF token načtený
            if (!csrfToken) {
                progressText.textContent = 'Načítám CSRF token...';
                await loadCsrfToken();
                if (!csrfToken) {
                    alert('Chyba: Nepodařilo se načíst CSRF token. Zkuste obnovit stránku.');
                    return;
                }
            }
            
            startBtn.disabled = true;
            progressContainer.style.display = 'block';
            resultDiv.style.display = 'none';
            progressFill.style.width = '0%'; // Skrýt progress bar
            progressFill.style.display = 'none'; // Skrýt progress bar
            progressText.textContent = 'Zahajuji zálohu...';
            
            try {
                progressText.textContent = 'Zahajuji zálohu...';
                
                // Sestavit data pro odeslání
                const requestData = {
                    csrf_token: csrfToken,
                    mode: mode
                };
                
                // Přidat databázové přístupy, pokud je potřeba
                if (mode === 'both' || mode === 'database') {
                    requestData.databases = getDatabaseEntries();
                }
                
                const response = await fetch('api.php?token=' + encodeURIComponent(API_TOKEN), {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-API-Token': API_TOKEN
                    },
                    body: JSON.stringify(requestData)
                });
                
                const data = await response.json();
                
                if (!response.ok) {
                    throw new Error(data.error || 'Chyba při vytváření zálohy');
                }
                
                // Záloha byla dokončena synchronně
                progressText.textContent = 'Hotovo! Záloha byla úspěšně vytvořena.';
                
                // Zobrazit výsledek
                resultDiv.className = 'result success';
                resultDiv.style.display = 'block';
                resultDiv.innerHTML = `
                    <h3>✅ Záloha byla úspěšně vytvořena!</h3>
                    <p>Počet souborů: ${data.files_count || 0}</p>
                    ${data.errors && data.errors.length > 0 ? 
                        '<p style="color: #856404;">Varování: ' + data.errors.join(', ') + '</p>' : ''}
                    <button class="download-link" onclick="downloadBackup('${data.zip_file}')">
                        📥 Stáhnout zálohu: ${data.zip_file}
                    </button>
                `;
                
                // Aktualizovat seznam záloh
                loadBackups();
                
                startBtn.disabled = false;
                setTimeout(() => {
                    progressContainer.style.display = 'none';
                }, 2000);
                
            } catch (error) {
                progressText.textContent = 'Chyba!';
                resultDiv.className = 'result error';
                resultDiv.style.display = 'block';
                resultDiv.innerHTML = `
                    <h3>❌ Chyba</h3>
                    <p>${error.message}</p>
                `;
                startBtn.disabled = false;
                setTimeout(() => {
                    progressContainer.style.display = 'none';
                }, 2000);
            }
        }
        
        // Načtení seznamu záloh
        async function loadBackups() {
            const backupsList = document.getElementById('backups-list');
            
            try {
                const response = await fetch('api.php?token=' + encodeURIComponent(API_TOKEN) + '&list=1');
                const data = await response.json();
                
                if (data.backups && data.backups.length > 0) {
                    backupsList.innerHTML = data.backups.map(backup => `
                        <div class="backup-item">
                            <div style="display: flex; align-items: center; gap: 10px;">
                                <input type="checkbox" class="backup-checkbox" value="${backup.filename}" onchange="updateDeleteButton()" style="width: auto; cursor: pointer;">
                                <div class="backup-info" style="flex: 1;">
                                    <div class="backup-name">${backup.filename}</div>
                                    <div class="backup-meta">
                                        ${backup.size_human} • 
                                        ${new Date(backup.created * 1000).toLocaleString('cs-CZ')}
                                    </div>
                                </div>
                            </div>
                            <div class="backup-actions">
                                <button class="download-link btn-small" onclick="downloadBackup('${backup.filename}')">
                                    Stáhnout
                                </button>
                                <button class="btn-small btn-danger" onclick="deleteBackup('${backup.filename}')">
                                    Smazat
                                </button>
                            </div>
                        </div>
                    `).join('');
                    updateDeleteButton();
                } else {
                    backupsList.innerHTML = '<div class="loading">Žádné zálohy</div>';
                    document.getElementById('delete-selected-btn').style.display = 'none';
                }
            } catch (error) {
                backupsList.innerHTML = '<div class="loading" style="color: #dc3545;">Chyba při načítání záloh</div>';
            }
        }
        
        // Smazání zálohy
        async function deleteBackup(filename) {
            if (!confirm('Opravdu chcete smazat tuto zálohu?')) {
                return;
            }
            
            await deleteBackups([filename]);
        }
        
        // Hromadné smazání záloh
        async function deleteSelectedBackups() {
            const checkboxes = document.querySelectorAll('.backup-checkbox:checked');
            const filenames = Array.from(checkboxes).map(cb => cb.value);
            
            if (filenames.length === 0) {
                alert('Vyberte alespoň jednu zálohu ke smazání');
                return;
            }
            
            if (!confirm(`Opravdu chcete smazat ${filenames.length} záloh?`)) {
                return;
            }
            
            await deleteBackups(filenames);
        }
        
        // Funkce pro smazání více záloh
        async function deleteBackups(filenames) {
            try {
                const response = await fetch('api.php?token=' + encodeURIComponent(API_TOKEN), {
                    method: 'DELETE',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-API-Token': API_TOKEN
                    },
                    body: JSON.stringify({
                        csrf_token: csrfToken,
                        filenames: filenames
                    })
                });
                
                const data = await response.json();
                
                if (response.ok) {
                    // Zobrazit výsledek
                    const resultDiv = document.getElementById('result');
                    if (data.deleted_count > 0) {
                        resultDiv.className = 'result success';
                        resultDiv.style.display = 'block';
                        let message = `✅ Úspěšně smazáno ${data.deleted_count} záloh`;
                        if (data.failed_count > 0) {
                            message += `<br>⚠️ ${data.failed_count} záloh se nepodařilo smazat`;
                        }
                        resultDiv.innerHTML = `<h3>${message}</h3>`;
                    } else {
                        resultDiv.className = 'result error';
                        resultDiv.style.display = 'block';
                        resultDiv.innerHTML = `<h3>❌ Chyba</h3><p>${data.message || 'Nepodařilo se smazat zálohy'}</p>`;
                    }
                    
                    // Skrýt po 3 sekundách
                    setTimeout(() => {
                        resultDiv.style.display = 'none';
                    }, 3000);
                    
                    loadBackups();
                } else {
                    alert('Chyba: ' + (data.error || 'Nepodařilo se smazat zálohy'));
                }
            } catch (error) {
                alert('Chyba: ' + error.message);
            }
        }
        
        // Aktualizovat viditelnost tlačítka pro hromadné smazání
        function updateDeleteButton() {
            const checkboxes = document.querySelectorAll('.backup-checkbox:checked');
            const deleteBtn = document.getElementById('delete-selected-btn');
            
            if (checkboxes.length > 0) {
                deleteBtn.style.display = 'block';
                deleteBtn.textContent = `🗑️ Smazat vybrané (${checkboxes.length})`;
            } else {
                deleteBtn.style.display = 'none';
            }
        }
        
        // Vybrat/odznačit vše
        function toggleSelectAll() {
            const selectAll = document.getElementById('select-all');
            const checkboxes = document.querySelectorAll('.backup-checkbox');
            
            checkboxes.forEach(cb => {
                cb.checked = selectAll.checked;
            });
            
            updateDeleteButton();
        }
        
        // Inicializace
        loadCsrfToken();
        loadBackups();
        
        // Automatické obnovení seznamu každých 30 sekund
        setInterval(loadBackups, 30000);
    </script>
</body>
</html>


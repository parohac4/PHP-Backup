<?php
/**
 * Unified Backup Manager - kombinuje zálohu souborů a databází
 */

class BackupManager {
    private $config;
    private $logFile;
    
    public function __construct(array $config) {
        $this->config = $config;
        $this->logFile = __DIR__ . '/backup.log';
        
        // Zajistit existenci adresáře pro zálohy
        $backupDir = $config['backup_dir'];
        if (!is_dir($backupDir)) {
            @mkdir($backupDir, 0750, true);
        }
        
        // Zajistit, že adresář je zapisovatelný
        if (!is_writable($backupDir)) {
            throw new Exception("Adresář pro zálohy není zapisovatelný: $backupDir");
        }
    }
    
    /**
     * Vytvoří kompletní zálohu (soubory + databáze)
     * @param string $mode Režim zálohy: 'both', 'files', 'database'
     * @param array|null $databases Databázové přístupy (volitelné, pokud null použije z config)
     * @param callable|null $statusCallback Callback pro aktualizaci statusu (volitelné)
     */
    public function createBackup(string $mode = 'both', ?array $databases = null, ?callable $statusCallback = null): array {
        $this->log("=== Začátek zálohy (režim: $mode) ===");
        
        $errors = [];
        $filesCount = 0;
        
        // Vytvořit prázdný ZIP archiv hned na začátku (jako v verzi 1)
        $timestamp = date('Ymd_His');
        $zipName = "backup_{$timestamp}.zip";
        $zipPath = $this->config['backup_dir'] . '/' . $zipName;
        
        $zip = new ZipArchive();
        $result = $zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        
        if ($result !== true) {
            throw new Exception("Nelze vytvořit ZIP archiv: $result");
        }
        
        $zip->setArchiveComment("Backup vytvořen: " . date('Y-m-d H:i:s'));
        $zip->close();
        
        // Nastavit heslo, pokud je konfigurováno
        $password = $this->config['zip_password'] ?? '';
        
        // Záloha souborů - přidávat přímo do ZIPu po dávkách
        if ($mode === 'files' || $mode === 'both') {
            try {
                if ($statusCallback) {
                    $statusCallback('Skenování souborů...');
                }
                $filesCount += $this->addFilesToZip($zipPath, $password, $statusCallback);
                $this->log("Přidáno $filesCount souborů do zálohy");
                if ($statusCallback) {
                    $statusCallback("Zpracováno $filesCount souborů");
                }
            } catch (Exception $e) {
                $errors[] = "Chyba při skenování souborů: " . $e->getMessage();
                $this->log("CHYBA: " . $e->getMessage());
            }
        }
        
        // Záloha databází
        if ($mode === 'database' || $mode === 'both') {
            try {
                if ($statusCallback) {
                    $statusCallback('Zálohování databází...');
                }
                // Použít databáze z parametru nebo z config
                $dbList = $databases ?? $this->config['databases'] ?? [];
                if (empty($dbList)) {
                    throw new Exception("Nebyly zadány žádné databázové přístupy");
                }
                $dbFilesCount = $this->addDatabasesToZip($zipPath, $dbList, $password);
                $this->log("Přidáno $dbFilesCount databázových dumpů");
                $filesCount += $dbFilesCount;
                if ($statusCallback) {
                    $statusCallback("Zpracováno $dbFilesCount databází");
                }
            } catch (Exception $e) {
                $errors[] = "Chyba při dumpu databází: " . $e->getMessage();
                $this->log("CHYBA: " . $e->getMessage());
            }
        }
        
        if ($filesCount === 0) {
            @unlink($zipPath);
            throw new Exception("Žádné soubory k zálohování");
        }
        
        // Automatické mazání starých záloh
        $this->cleanupOldBackups();
        
        $this->log("=== Záloha dokončena: " . basename($zipPath) . " ===");
        
        return [
            'success' => true,
            'zip_file' => basename($zipPath),
            'zip_path' => $zipPath,
            'files_count' => $filesCount,
            'errors' => $errors,
        ];
    }
    
    /**
     * Přidá soubory do ZIP archivu postupně pomocí iteratoru po dávkách
     * ZIP se otevírá a zavírá pro každou dávku, aby se uvolnila paměť
     */
    private function addFilesToZip(string $zipPath, string $password = '', ?callable $statusCallback = null): int {
        $root = $this->config['backup_root'];
        
        if (empty($root)) {
            throw new Exception("Cesta backup_root není nastavena");
        }
        
        if (!is_dir($root)) {
            throw new Exception("Kořenový adresář neexistuje: $root");
        }
        
        $root = realpath($root);
        if ($root === false) {
            throw new Exception("Nelze získat realpath pro: " . $this->config['backup_root']);
        }
        
        $backupDir = realpath($this->config['backup_dir']);
        $excludePatterns = $this->config['exclude_patterns'] ?? [];
        
        $this->log("Skenování adresáře: $root");
        
        // Zkontrolovat, jaké adresáře existují v root
        $dirs = @scandir($root);
        if ($dirs !== false) {
            $dirs = array_filter($dirs, function($d) use ($root) {
                return $d !== '.' && $d !== '..' && is_dir($root . '/' . $d);
            });
            $this->log("Nalezené adresáře v root: " . implode(', ', $dirs));
        }
        
        // Velikost dávky (podobně jako v verzi 1)
        $batchSize = 400;
        $added = 0;
        $processed = 0;
        $excludedCount = 0;
        $batch = [];
        
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );
        
        foreach ($iterator as $file) {
            // Logovat všechny adresáře, které iterator prochází (pro debug)
            if ($file->isDir() && $processed < 10) {
                $dirPath = $file->getPathname();
                if (strpos($dirPath, $root) === 0) {
                    $relativeDir = substr($dirPath, strlen($root));
                    $relativeDir = ltrim($relativeDir, DIRECTORY_SEPARATOR . '/');
                    $this->log("DEBUG: Procházím adresář: $relativeDir");
                }
            }
            
            if (!$file->isFile()) {
                continue;
            }
            
            $path = $file->getPathname();
            
            // Vyloučit adresář se zálohami
            if ($backupDir) {
                $backupDirReal = realpath($backupDir);
                $pathReal = realpath($path);
                if ($backupDirReal && $pathReal && strpos($pathReal, $backupDirReal) === 0) {
                    $excludedCount++;
                    continue;
                }
            }
            
            // Vytvořit relativní cestu od backup_root
            // Použít realpath pro normalizaci obou cest
            $pathReal = realpath($path);
            if ($pathReal === false) {
                continue; // Přeskočit soubory, které nelze přečíst
            }
            
            // Zkontrolovat, zda je soubor pod root
            if (strpos($pathReal, $root) === 0) {
                $relativePath = substr($pathReal, strlen($root));
                $relativePath = ltrim($relativePath, DIRECTORY_SEPARATOR . '/');
                // Normalizovat na forward slashes
                $relativePath = str_replace('\\', '/', $relativePath);
                
                // Debug: logovat první pár souborů pro kontrolu
                if ($processed < 5) {
                    $this->log("DEBUG: Přidávám soubor - path: $pathReal, relative: $relativePath, root: $root");
                }
            } else {
                // Pokud cesta není pod root, přeskočit
                $this->log("VAROVÁNÍ: Soubor není pod root - path: $pathReal, root: $root");
                continue;
            }
            
            // Kontrola exclude patterns
            $excluded = false;
            foreach ($excludePatterns as $pattern) {
                // Kontrola názvu souboru (wildcards)
                if (fnmatch($pattern, basename($path))) {
                    $excluded = true;
                    break;
                }
                
                // Kontrola celé relativní cesty (wildcards)
                if (fnmatch($pattern, $relativePath)) {
                    $excluded = true;
                    break;
                }
                
                // Kontrola, zda je pattern na začátku cesty (celý adresář)
                $patternWithSlash = $pattern . '/';
                if (strpos($relativePath, $patternWithSlash) === 0 || 
                    $relativePath === $pattern) {
                    $excluded = true;
                    break;
                }
            }
            
            if (!$excluded) {
                $batch[] = ['path' => $pathReal, 'relative' => $relativePath];
                $processed++;
                
                // Debug: logovat první soubor z každého adresáře
                $dirName = dirname($relativePath);
                if ($processed <= 20 || ($processed % 1000 === 0)) {
                    $this->log("Přidávám soubor #$processed: $relativePath (adresář: $dirName)");
                }
                
                // Zpracovat dávku, když dosáhne velikosti
                if (count($batch) >= $batchSize) {
                    // Otevřít ZIP pro přidávání souborů
                    $zip = new ZipArchive();
                    $retries = 0;
                    $opened = false;
                    while ($retries < 5) {
                        // ZipArchive::CREATE otevře existující ZIP pro zápis nebo vytvoří nový
                        if ($zip->open($zipPath, ZipArchive::CREATE) === true) {
                            $opened = true;
                            break;
                        }
                        usleep(150000); // 150ms mezi pokusy
                        $retries++;
                    }
                    
                    if (!$opened) {
                        throw new Exception("Nelze otevřít ZIP archiv pro dávku (pokusy: $retries)");
                    }
                    
                    if (!empty($password)) {
                        $zip->setPassword($password);
                    }
                    
                    // Přidat soubory z dávky
                    foreach ($batch as $fileInfo) {
                        // Použít realpath pro spolehlivost
                        $filePath = realpath($fileInfo['path']);
                        if ($filePath === false || !file_exists($filePath)) {
                            $this->log("VAROVÁNÍ: Soubor neexistuje: " . $fileInfo['path']);
                            continue;
                        }
                        if ($zip->addFile($filePath, $fileInfo['relative'])) {
                            $added++;
                            if (!empty($password)) {
                                $zip->setEncryptionName($fileInfo['relative'], ZipArchive::EM_AES_256);
                            }
                        } else {
                            $this->log("VAROVÁNÍ: Nepodařilo se přidat soubor do ZIP: " . $fileInfo['relative']);
                        }
                    }
                    
                    $zip->close();
                    $batch = [];
                    
                    // Uvolnit paměť
                    if (function_exists('gc_collect_cycles')) {
                        gc_collect_cycles();
                    }
                    // Prodloužit časový limit
                    if (function_exists('set_time_limit')) {
                        @set_time_limit(3600);
                    }
                    $this->log("Zpracováno $processed souborů, přidáno $added do ZIPu (vyloučeno: $excludedCount)");
                    
                    // Aktualizovat status každých 1000 souborů
                    if ($statusCallback && $processed % 1000 === 0) {
                        $statusCallback("Zpracováno $processed souborů...");
                    }
                }
            } else {
                $excludedCount++;
            }
        }
        
        $this->log("Celkem zpracováno: $processed souborů, vyloučeno: $excludedCount");
        
        if ($statusCallback) {
            $statusCallback("Hotovo: $added souborů přidáno do zálohy");
        }
        
        // Zpracovat zbývající soubory
        if (!empty($batch)) {
            // Otevřít ZIP pro přidávání souborů
            $zip = new ZipArchive();
            $retries = 0;
            $opened = false;
            while ($retries < 5) {
                // ZipArchive::CREATE otevře existující ZIP pro zápis nebo vytvoří nový
                if ($zip->open($zipPath, ZipArchive::CREATE) === true) {
                    $opened = true;
                    break;
                }
                usleep(150000);
                $retries++;
            }
            
            if (!$opened) {
                throw new Exception("Nelze otevřít ZIP archiv pro zbývající soubory (pokusy: $retries)");
            }
            
            if (!empty($password)) {
                $zip->setPassword($password);
            }
            
            foreach ($batch as $fileInfo) {
                // Použít realpath pro spolehlivost
                $filePath = realpath($fileInfo['path']);
                if ($filePath === false || !file_exists($filePath)) {
                    $this->log("VAROVÁNÍ: Soubor neexistuje: " . $fileInfo['path']);
                    continue;
                }
                if ($zip->addFile($filePath, $fileInfo['relative'])) {
                    $added++;
                    if (!empty($password)) {
                        $zip->setEncryptionName($fileInfo['relative'], ZipArchive::EM_AES_256);
                    }
                } else {
                    $this->log("VAROVÁNÍ: Nepodařilo se přidat soubor do ZIP: " . $fileInfo['relative']);
                }
            }
            
            $zip->close();
        }
        
        return $added;
    }
    
    /**
     * Přidá databázové dumpy do ZIP archivu
     * @param string $zipPath Cesta k ZIP archivu
     * @param array $databases Seznam databází pro dump
     * @param string $password Heslo pro šifrování
     * @return int Počet přidaných souborů
     */
    private function addDatabasesToZip(string $zipPath, array $databases, string $password = ''): int {
        $compress = $this->config['compress_dumps'] ?? true;
        $tempDir = sys_get_temp_dir();
        $added = 0;
        
        foreach ($databases as $db) {
            if (empty($db['name']) || empty($db['host'])) {
                continue;
            }
            
            $host = $db['host'];
            $port = $db['port'] ?? 3306;
            $user = $db['user'] ?? 'root';
            $pass = $db['pass'] ?? '';
            $name = $db['name'];
            
            $timestamp = date('Ymd_His');
            $filename = "dump_{$name}_{$timestamp}.sql";
            if ($compress) {
                $filename .= '.gz';
            }
            
            $tempFile = $tempDir . '/' . $filename;
            $zipPathInArchive = 'databases/' . $filename;
            
            // Zkusit nejdříve mysqldump, pokud je dostupný a funkce proc_open nebo exec
            $useMysqldump = false;
            
            // Zkontrolovat dostupnost funkcí a mysqldump
            if (function_exists('proc_open') || function_exists('exec')) {
                // Zkontrolovat, zda je mysqldump dostupný
                $mysqldumpCheck = @exec('which mysqldump 2>&1', $output, $return);
                if ($return === 0 || !empty($mysqldumpCheck)) {
                    $useMysqldump = true;
                }
            }
            
            $dumpCreated = false;
            
            if ($useMysqldump && function_exists('proc_open')) {
                // Použít proc_open (pokud je dostupná)
                $env = ['MYSQL_PWD' => $pass];
                $cmd = sprintf(
                    'mysqldump -h%s -P%d -u%s %s 2>&1',
                    escapeshellarg($host),
                    (int)$port,
                    escapeshellarg($user),
                    escapeshellarg($name)
                );
                
                if ($compress) {
                    $cmd .= ' | gzip';
                }
                $cmd .= ' > ' . escapeshellarg($tempFile);
                
                $descriptorspec = [
                    0 => ['pipe', 'r'],
                    1 => ['pipe', 'w'],
                    2 => ['pipe', 'w']
                ];
                
                $process = @proc_open($cmd, $descriptorspec, $pipes, null, $env);
                
                if (is_resource($process)) {
                    fclose($pipes[0]);
                    $stdout = stream_get_contents($pipes[1]);
                    $stderr = stream_get_contents($pipes[2]);
                    fclose($pipes[1]);
                    fclose($pipes[2]);
                    $returnCode = proc_close($process);
                    
                    if ($returnCode === 0 && file_exists($tempFile) && filesize($tempFile) > 0) {
                        $dumpCreated = true;
                    }
                }
            } elseif ($useMysqldump && function_exists('exec')) {
                // Použít exec (pokud je dostupná)
                $env = 'MYSQL_PWD=' . escapeshellarg($pass);
                $cmd = sprintf(
                    'mysqldump -h%s -P%d -u%s %s',
                    escapeshellarg($host),
                    (int)$port,
                    escapeshellarg($user),
                    escapeshellarg($name)
                );
                
                if ($compress) {
                    $cmd .= ' | gzip > ' . escapeshellarg($tempFile);
                } else {
                    $cmd .= ' > ' . escapeshellarg($tempFile);
                }
                
                putenv($env);
                exec($cmd . ' 2>&1', $output, $returnCode);
                putenv('MYSQL_PWD'); // Vymazat z prostředí
                
                if ($returnCode === 0 && file_exists($tempFile) && filesize($tempFile) > 0) {
                    $dumpCreated = true;
                }
            }
            
            // Pokud mysqldump nefunguje nebo není dostupný, použít mysqli metodu
            if (!$dumpCreated) {
                $this->log("Použití mysqli metody pro dump databáze $name (mysqldump není dostupný nebo selhal)");
                $tempFile = $this->dumpDatabaseViaMysqli($db, $tempFile, $compress);
                if ($tempFile && file_exists($tempFile) && filesize($tempFile) > 0) {
                    $dumpCreated = true;
                } else {
                    $this->log("CHYBA: Nepodařilo se vytvořit dump databáze $name");
                    continue;
                }
            }
            
            // Přidat dump do ZIPu - otevřít ZIP, přidat soubor, zavřít ZIP
            if ($dumpCreated && file_exists($tempFile)) {
                $zip = new ZipArchive();
                $retries = 0;
                while ($retries < 5) {
                    if ($zip->open($zipPath, 0) === true) {
                        break;
                    }
                    usleep(150000); // 150ms mezi pokusy
                    $retries++;
                }
                
                if ($zip->open($zipPath, 0) === true) {
                    if (!empty($password)) {
                        $zip->setPassword($password);
                    }
                    
                    if ($zip->addFile($tempFile, $zipPathInArchive)) {
                        $added++;
                        if (!empty($password)) {
                            $zip->setEncryptionName($zipPathInArchive, ZipArchive::EM_AES_256);
                        }
                    }
                    $zip->close();
                }
                
                // Smazat dočasný soubor po přidání do ZIPu
                @unlink($tempFile);
                
                // Uvolnit paměť
                if (function_exists('gc_collect_cycles')) {
                    gc_collect_cycles();
                }
            }
        }
        
        return $added;
    }
    
    /**
     * Alternativní metoda pro vytvoření dumpu přes mysqli (pokud mysqldump není dostupný)
     */
    private function dumpDatabaseViaMysqli(array $db, string $outputFile, bool $compress): ?string {
        $host = $db['host'];
        $port = $db['port'] ?? 3306;
        $user = $db['user'] ?? 'root';
        $pass = $db['pass'] ?? '';
        $name = $db['name'];
        
        mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
        
        try {
            $mysqli = new mysqli($host, $user, $pass, $name, $port);
            $mysqli->set_charset('utf8mb4');
            
            // Otevřít výstupní soubor
            if ($compress) {
                if (!function_exists('gzopen')) {
                    $this->log("CHYBA: zlib není k dispozici pro kompresi");
                    return null;
                }
                $fh = gzopen($outputFile, 'wb9');
                $write = function($s) use ($fh) { return gzwrite($fh, $s) !== false; };
                $close = function() use ($fh) { gzclose($fh); };
            } else {
                $fh = fopen($outputFile, 'wb');
                $write = function($s) use ($fh) { return fwrite($fh, $s) !== false; };
                $close = function() use ($fh) { fclose($fh); };
            }
            
            if (!$fh) {
                $this->log("CHYBA: Nelze otevřít výstupní soubor: $outputFile");
                return null;
            }
            
            // Hlavička
            $write("-- SQL dump generated by BackupManager\n");
            $write("-- Database: $name\n");
            $write("-- Generated: " . date('Y-m-d H:i:s') . "\n\n");
            $write("SET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS=0;\n\n");
            
            // Získat seznam tabulek
            $tables = [];
            $result = $mysqli->query("SHOW TABLES");
            while ($row = $result->fetch_array(MYSQLI_NUM)) {
                $tables[] = $row[0];
            }
            $result->free();
            
            // Export každé tabulky
            foreach ($tables as $table) {
                // Escapování názvu tabulky pro bezpečnost
                $tableEscaped = '`' . str_replace('`', '``', $table) . '`';
                
                $write("-- Table: $tableEscaped\n");
                $write("DROP TABLE IF EXISTS $tableEscaped;\n");
                
                // CREATE TABLE
                $createResult = $mysqli->query("SHOW CREATE TABLE $tableEscaped");
                if (!$createResult) {
                    $this->log("CHYBA: Nelze získat CREATE TABLE pro $table: " . $mysqli->error);
                    continue;
                }
                $createRow = $createResult->fetch_assoc();
                $write($createRow['Create Table'] . ";\n\n");
                $createResult->free();
                
                // Data
                $dataResult = $mysqli->query("SELECT * FROM $tableEscaped");
                if ($dataResult->num_rows > 0) {
                    $columns = [];
                    $fields = $dataResult->fetch_fields();
                    foreach ($fields as $field) {
                        // Escapování názvu sloupce pro bezpečnost
                        $columnEscaped = '`' . str_replace('`', '``', $field->name) . '`';
                        $columns[] = $columnEscaped;
                    }
                    $colList = implode(', ', $columns);
                    
                    $write("INSERT INTO $tableEscaped ($colList) VALUES\n");
                    $values = [];
                    while ($row = $dataResult->fetch_assoc()) {
                        $vals = [];
                        foreach ($fields as $field) {
                            $val = $row[$field->name];
                            if ($val === null) {
                                $vals[] = 'NULL';
                            } else {
                                $vals[] = "'" . $mysqli->real_escape_string($val) . "'";
                            }
                        }
                        $values[] = '(' . implode(', ', $vals) . ')';
                    }
                    $write(implode(",\n", $values) . ";\n\n");
                    $dataResult->free();
                }
            }
            
            $write("SET FOREIGN_KEY_CHECKS=1;\n");
            $close();
            $mysqli->close();
            
            return $outputFile;
            
        } catch (Exception $e) {
            $this->log("CHYBA mysqli dump: " . $e->getMessage());
            return null;
        }
    }
    
    /**
     * Vytvoří ZIP archiv ze seznamu souborů
     * Zpracovává soubory po částech přímo do finálního ZIPu, aby se vyhnulo problémům s pamětí
     */
    private function createZip(array $files): string {
        $timestamp = date('Ymd_His');
        $zipName = "backup_{$timestamp}.zip";
        $zipPath = $this->config['backup_dir'] . '/' . $zipName;
        
        // Počet souborů zpracovaných najednou (chunk size)
        $chunkSize = 1000;
        $totalFiles = count($files);
        $chunks = array_chunk($files, $chunkSize);
        
        $this->log("Zpracování $totalFiles souborů v " . count($chunks) . " částech");
        
        // Vytvořit finální ZIP archiv
        $zip = new ZipArchive();
        $result = $zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        
        if ($result !== true) {
            throw new Exception("Nelze vytvořit ZIP archiv: $result");
        }
        
        // Nastavit heslo, pokud je konfigurováno
        $password = $this->config['zip_password'] ?? '';
        if (!empty($password)) {
            $zip->setPassword($password);
        }
        
        $totalAdded = 0;
        
        // Zpracovat soubory po částech přímo do finálního ZIPu
        foreach ($chunks as $chunkIndex => $chunk) {
            $added = 0;
            
            foreach ($chunk as $file) {
                $sourcePath = $file['path'];
                $zipPathInArchive = $file['relative'];
                
                if (!file_exists($sourcePath)) {
                    $this->log("VAROVÁNÍ: Soubor neexistuje: $sourcePath");
                    continue;
                }
                
                // Přidat soubor přímo do finálního ZIPu
                if ($zip->addFile($sourcePath, $zipPathInArchive)) {
                    $added++;
                    $totalAdded++;
                    
                    // Pokud je nastaveno heslo, musíme ho aplikovat na každý soubor
                    if (!empty($password)) {
                        $zip->setEncryptionName($zipPathInArchive, ZipArchive::EM_AES_256);
                    }
                } else {
                    $this->log("VAROVÁNÍ: Nepodařilo se přidat soubor: $sourcePath");
                }
            }
            
            $this->log("Část " . ($chunkIndex + 1) . "/" . count($chunks) . ": přidáno $added souborů (celkem $totalAdded)");
            
            // Uvolnit paměť po každé části
            if (function_exists('gc_collect_cycles')) {
                gc_collect_cycles();
            }
            
            // Prodloužit časový limit, pokud je to možné
            if (function_exists('set_time_limit')) {
                @set_time_limit(3600);
            }
        }
        
        $zip->setArchiveComment("Backup vytvořen: " . date('Y-m-d H:i:s'));
        $zip->close();
        
        $this->log("Do ZIP archivu přidáno celkem $totalAdded souborů");
        
        // Kontrola velikosti
        $maxSize = ($this->config['max_zip_size_mb'] ?? 0) * 1024 * 1024;
        if ($maxSize > 0 && filesize($zipPath) > $maxSize) {
            @unlink($zipPath);
            throw new Exception("ZIP archiv překračuje maximální velikost");
        }
        
        return $zipPath;
    }
    
    /**
     * Vyčistí dočasné soubory (dumpy databází)
     */
    private function cleanupTempFiles(array $files): void {
        foreach ($files as $file) {
            $path = $file['path'];
            // Smazat pouze dočasné soubory (dumpy)
            if (strpos($path, sys_get_temp_dir()) === 0 && 
                (strpos($path, 'dump_') !== false || strpos($path, '.sql') !== false)) {
                @unlink($path);
            }
        }
    }
    
    /**
     * Smaže staré zálohy podle retain_days
     */
    private function cleanupOldBackups(): void {
        $retainDays = $this->config['retain_days'] ?? 7;
        $backupDir = $this->config['backup_dir'];
        $cutoffTime = time() - ($retainDays * 86400);
        
        $files = glob($backupDir . '/backup_*.zip');
        $deleted = 0;
        
        foreach ($files as $file) {
            if (filemtime($file) < $cutoffTime) {
                @unlink($file);
                $deleted++;
                $this->log("Smazána stará záloha: " . basename($file));
            }
        }
        
        if ($deleted > 0) {
            $this->log("Smazáno $deleted starých záloh");
        }
    }
    
    /**
     * Zaloguje zprávu
     */
    private function log(string $message): void {
        $timestamp = date('Y-m-d H:i:s');
        $logEntry = "[$timestamp] $message\n";
        @file_put_contents($this->logFile, $logEntry, FILE_APPEND);
    }
    
    /**
     * Získá seznam dostupných záloh
     */
    public function listBackups(): array {
        $backupDir = $this->config['backup_dir'];
        $files = glob($backupDir . '/backup_*.zip');
        
        $backups = [];
        foreach ($files as $file) {
            $backups[] = [
                'filename' => basename($file),
                'size' => filesize($file),
                'created' => filemtime($file),
                'size_human' => $this->formatBytes(filesize($file)),
            ];
        }
        
        // Seřadit podle data (nejnovější první)
        usort($backups, function($a, $b) {
            return $b['created'] - $a['created'];
        });
        
        return $backups;
    }
    
    /**
     * Formátuje velikost souboru
     */
    private function formatBytes(int $bytes): string {
        $units = ['B', 'KB', 'MB', 'GB'];
        $bytes = max($bytes, 0);
        $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
        $pow = min($pow, count($units) - 1);
        $bytes /= pow(1024, $pow);
        return round($bytes, 2) . ' ' . $units[$pow];
    }
}


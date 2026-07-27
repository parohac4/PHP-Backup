<?php
/**
 * Záloha souborů – procházení adresáře a balení do ZIPu, obojí po krocích.
 *
 * Bezpečnostní zásady:
 *  - vše se odehrává výhradně uvnitř zvoleného kořene (kontrola přes realpath),
 *  - symbolické odkazy se přeskakují (nemohou vyvést mimo kořen ani zacyklit),
 *  - vlastní datový adresář nástroje se do zálohy nikdy nepřidává.
 */

declare(strict_types=1);

// Přímé volání tohoto souboru nedává smysl – vstupním bodem je index.php.
if (!defined('PB_VERSION')) {
    http_response_code(404);
    exit;
}


final class FilesBackup
{
    /** Výchozí vzory, které nemá smysl zálohovat. */
    public const DEFAULT_EXCLUDES = [
        '.git',
        '.svn',
        'node_modules',
        'vendor/bin',
        '*.log',
        '*.tmp',
        '.DS_Store',
        'Thumbs.db',
    ];

    private static function manifestPath(): string
    {
        return PB_DATA . '/manifest.php';
    }

    /**
     * Založí seznam souborů k zabalení a vrátí, kde v souboru začínají data.
     *
     * Soubor má stejnou ochrannou hlavičku jako ostatní data nástroje. Balení
     * musí začít čtením až za ní – jinak by první „cestou“ v seznamu byla
     * samotná hlavička.
     */
    public static function createManifest(): int
    {
        $guard = "<?php http_response_code(404); exit; ?>\n";
        if (@file_put_contents(self::manifestPath(), $guard) === false) {
            throw new RuntimeException('Nelze vytvořit seznam souborů v datovém adresáři.');
        }
        @chmod(self::manifestPath(), 0600);
        return strlen($guard);
    }

    /**
     * Jeden krok procházení adresářů. Stav je zásobník ještě nenavštívených
     * adresářů, takže se dá kdykoli přerušit a pokračovat dalším requestem.
     *
     * @param array<string,mixed> $job
     */
    public static function scanStep(array &$job, float $deadline): void
    {
        $root = self::root();
        $excludes = self::excludePatterns();
        $fh = @fopen(self::manifestPath(), 'ab');
        if ($fh === false) {
            throw new RuntimeException('Nelze zapisovat do datového adresáře.');
        }

        $milestone = (int)($job['scan_milestone'] ?? 0);

        try {
            while ($job['stack'] !== [] && microtime(true) < $deadline) {
                // Položka zásobníku je dvojice [adresář, pořadí položky].
                // Díky offsetu umíme navázat i uprostřed adresáře – jinak by
                // jediná složka s desítkami tisíc souborů zablokovala celý
                // požadavek a hosting by ho ukončil (504).
                $item = array_pop($job['stack']);
                $relDir = is_array($item) ? (string)$item[0] : (string)$item;
                $offset = is_array($item) ? (int)($item[1] ?? 0) : 0;
                $absDir = $relDir === '' ? $root : $root . '/' . $relDir;

                $entries = @scandir($absDir);
                if ($entries === false) {
                    $job['skipped']++;
                    Job::message($job, 'Přeskočeno (nelze číst): ' . ($relDir === '' ? '/' : $relDir));
                    continue;
                }

                $count = count($entries);
                for ($i = $offset; $i < $count; $i++) {
                    // Čas kontrolujeme po dávkách položek – volání hodin u
                    // každého souboru by samo o sobě stálo víc než práce.
                    if ($i > $offset && ($i - $offset) % 250 === 0 && microtime(true) >= $deadline) {
                        $job['stack'][] = [$relDir, $i];
                        break 2;
                    }

                    $entry = (string)$entries[$i];
                    if ($entry === '.' || $entry === '..') {
                        continue;
                    }
                    $abs = $absDir . '/' . $entry;
                    $rel = $relDir === '' ? $entry : $relDir . '/' . $entry;

                    // Symlinky ignorujeme – jinak by šlo zálohou "vytáhnout"
                    // soubory mimo zvolený kořen.
                    if (is_link($abs)) {
                        $job['skipped']++;
                        continue;
                    }
                    if (self::isExcluded($rel, $excludes) || self::isOwnData($abs)) {
                        $job['skipped']++;
                        continue;
                    }

                    if (is_dir($abs)) {
                        $job['stack'][] = [$rel, 0];
                    } elseif (is_file($abs) && is_readable($abs)) {
                        fwrite($fh, rawurlencode($rel) . "\n");
                        $job['files_total']++;

                        // Ať je v okně vidět, že se něco děje.
                        if ($job['files_total'] - $milestone >= 10000) {
                            $milestone = (int)$job['files_total'];
                            Job::message($job, 'Nalezeno ' . $milestone . ' souborů…');
                        }
                    } else {
                        $job['skipped']++;
                    }
                }
            }
        } finally {
            $job['scan_milestone'] = $milestone;
            fclose($fh);
        }
    }

    /**
     * Jeden krok balení. Archiv se otevře jednou za request a zavře až na
     * konci – zavření je u velkých archivů drahá operace.
     *
     * @param array<string,mixed> $job
     */
    public static function zipStep(array &$job, float $deadline, string $password): void
    {
        if ($job['files_done'] >= $job['files_total']) {
            return;
        }

        $root = self::root();
        $zipPath = Storage::backupDir() . '/' . $job['zip'];

        $manifest = @fopen(self::manifestPath(), 'rb');
        if ($manifest === false) {
            throw new RuntimeException('Seznam souborů se nepodařilo otevřít.');
        }
        if (fseek($manifest, (int)$job['manifest_pos']) !== 0) {
            fclose($manifest);
            throw new RuntimeException('Poškozený seznam souborů.');
        }

        // Pozor: prázdný archiv na disku neexistuje – knihovna zip ho při
        // zavření smaže. Proto vždy otevíráme s příznakem CREATE.
        $zip = new ZipArchive();
        $opened = $zip->open($zipPath, ZipArchive::CREATE);
        if ($opened !== true) {
            fclose($manifest);
            throw new RuntimeException('Nelze otevřít archiv pro zápis (kód ' . (int)$opened . ').');
        }
        // Je-li heslo zadané, šifrování musí opravdu proběhnout – jinak úlohu
        // raději ukončíme, než abychom vyrobili nechráněný archiv.
        $encrypt = $password !== '';
        if ($encrypt) {
            if (!Job::zipEncryptionAvailable() || !$zip->setPassword($password)) {
                $zip->close();
                fclose($manifest);
                throw new RuntimeException('Archiv se nepodařilo zašifrovat, záloha byla zastavena.');
            }
        }

        // Meze jedné dávky. Zavření archivu je ta drahá část (komprimace
        // novinek + přepis dosavadního obsahu), a právě ono musí skončit dřív,
        // než požadavek ukončí hosting. Proto se hlídá objem *veškerých*
        // přidaných dat, ne jen těch držených v paměti.
        $maxWork = max(1024 * 1024, (int)($job['batch_bytes'] ?? 8 * 1024 * 1024));

        // Dávka nesmí být tak velká, aby jí část zálohy přeskočila svůj limit –
        // jinak by nastavená velikost části neplatila.
        $partLimit = Job::partLimitBytes();
        if ($partLimit > 0) {
            $maxWork = min($maxWork, max(1024 * 1024, intdiv($partLimit, 4)));
        }
        $maxMemory = self::batchByteLimit();
        $maxFiles = 5000;
        $maxHandles = 200;   // soubory přidané odkazem drží otevřený deskriptor
        $inlineLimit = 2 * 1024 * 1024;

        $added = 0;
        $workBytes = 0;    // vše, co v této dávce půjde komprimovat
        $memoryBytes = 0;  // jen to, co držíme v paměti
        $handles = 0;
        $eof = false;
        try {
            while (microtime(true) < $deadline) {
                $line = fgets($manifest);
                if ($line === false) {
                    $eof = true;
                    break;
                }
                $job['manifest_pos'] = ftell($manifest);
                $rel = rawurldecode(rtrim($line, "\r\n"));
                if ($rel === '') {
                    continue;
                }
                $job['files_done']++;

                $abs = $root . '/' . $rel;
                // Poslední kontrola těsně před přidáním: soubor musí stále
                // ležet uvnitř kořene a nesmí to být symlink.
                if (is_link($abs) || !is_file($abs) || !Security::pathWithin($abs, $root)) {
                    $job['skipped']++;
                    continue;
                }

                // Malé soubory přidáváme rovnou z paměti – nedrží deskriptor,
                // takže se do jedné dávky jich vejde mnohem víc.
                $size = (int)@filesize($abs);
                $ok = false;
                if ($size <= $inlineLimit) {
                    $content = @file_get_contents($abs);
                    if ($content !== false) {
                        $ok = $zip->addFromString($rel, $content);
                        $memoryBytes += $size;
                        unset($content);
                    }
                } else {
                    $ok = $zip->addFile($abs, $rel);
                    $handles++;
                }

                if ($ok) {
                    $added++;
                    $workBytes += $size;
                    if ($encrypt && !$zip->setEncryptionName($rel, ZipArchive::EM_AES_256)) {
                        throw new RuntimeException('Soubor ' . $rel . ' se nepodařilo zašifrovat, '
                            . 'záloha byla zastavena.');
                    }
                } else {
                    $job['skipped']++;
                }

                if ($workBytes >= $maxWork || $memoryBytes >= $maxMemory
                    || $handles >= $maxHandles || $added >= $maxFiles) {
                    break;
                }
            }
        } finally {
            $ok = $zip->close();
            fclose($manifest);
            // Konec seznamu = hotovo, i kdyby počty kvůli přeskočeným
            // souborům přesně nesouhlasily (jinak by úloha nikdy neskončila).
            if ($eof) {
                $job['files_done'] = $job['files_total'];
            }
            if ($ok === false) {
                throw new RuntimeException('Zápis do archivu selhal (místo na disku?).');
            }
        }
    }

    /**
     * Kolik dat smíme v jedné dávce držet v paměti – odvozeno od skutečného
     * limitu PHP, ať to nespadne na hostingu se 128 MB.
     */
    private static function batchByteLimit(): int
    {
        $raw = trim((string)@ini_get('memory_limit'));
        $limit = (int)$raw;
        $unit = strtoupper(substr($raw, -1));
        if ($unit === 'G') {
            $limit *= 1024 * 1024 * 1024;
        } elseif ($unit === 'M') {
            $limit *= 1024 * 1024;
        } elseif ($unit === 'K') {
            $limit *= 1024;
        }
        if ($limit <= 0) {
            $limit = 128 * 1024 * 1024; // neomezeno nebo neznámo – buďme opatrní
        }
        return (int)max(4 * 1024 * 1024, min(48 * 1024 * 1024, $limit * 0.15));
    }

    /** Kořenový adresář zálohy – ověřený a normalizovaný. */
    public static function root(): string
    {
        $root = (string)Storage::config('backup_root', '');
        if ($root === '') {
            throw new RuntimeException('Není nastaven adresář k zálohování.');
        }
        $real = realpath($root);
        if ($real === false || !is_dir($real)) {
            throw new RuntimeException('Adresář k zálohování neexistuje: ' . $root);
        }
        return rtrim(str_replace('\\', '/', $real), '/');
    }

    /** @return list<string> */
    public static function excludePatterns(): array
    {
        $patterns = Storage::config('excludes', self::DEFAULT_EXCLUDES);
        if (!is_array($patterns)) {
            return self::DEFAULT_EXCLUDES;
        }
        $out = [];
        foreach ($patterns as $p) {
            $p = trim(str_replace('\\', '/', (string)$p), " \t/");
            if ($p !== '') {
                $out[] = $p;
            }
        }
        return $out;
    }

    /**
     * Porovná relativní cestu se vzory. Vzor odpovídá, pokud sedí na
     * název položky, na celou relativní cestu, nebo je to adresář na jejím
     * začátku (`cache` vyloučí i `cache/x/y.txt`).
     *
     * @param list<string> $patterns
     */
    public static function isExcluded(string $rel, array $patterns): bool
    {
        $base = basename($rel);
        foreach ($patterns as $pattern) {
            if (self::matches($pattern, $base)
                || self::matches($pattern, $rel)
                || self::matches($pattern . '/*', $rel)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Porovnání se vzorem se zástupnými znaky. `fnmatch()` není na všech
     * hostinzích k dispozici, proto náhrada přes regulární výraz.
     */
    private static function matches(string $pattern, string $subject): bool
    {
        if (function_exists('fnmatch')) {
            return fnmatch($pattern, $subject);
        }
        $regex = '#^' . str_replace(['\*', '\?'], ['[^/]*', '[^/]'], preg_quote($pattern, '#')) . '$#';
        return preg_match($regex, $subject) === 1;
    }

    /** Nikdy nezálohujeme vlastní datový adresář (obsahuje zálohy a session). */
    private static function isOwnData(string $abs): bool
    {
        if (!is_dir($abs)) {
            return false;
        }
        $real = realpath($abs);
        if ($real === false) {
            return false;
        }
        $data = realpath(PB_DATA);
        $store = realpath(Storage::backupDir());
        return ($data !== false && $real === $data) || ($store !== false && $real === $store);
    }

    /**
     * Nabídne pravděpodobné kořeny zálohy – ať uživatel nemusí zjišťovat
     * absolutní cestu na hostingu.
     *
     * @return list<string>
     */
    public static function suggestRoots(): array
    {
        $candidates = [
            dirname(PB_ROOT),
            PB_ROOT,
            (string)($_SERVER['DOCUMENT_ROOT'] ?? ''),
            dirname((string)($_SERVER['DOCUMENT_ROOT'] ?? '.')),
            dirname(PB_ROOT, 2),
        ];

        $out = [];
        foreach ($candidates as $path) {
            if ($path === '' || $path === '.' || $path === '/') {
                continue;
            }
            $real = realpath($path);
            if ($real !== false && is_dir($real) && is_readable($real) && !in_array($real, $out, true)) {
                $out[] = $real;
            }
        }
        return $out;
    }
}

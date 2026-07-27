<?php
/**
 * Úloha zálohování – běží po krocích.
 *
 * Na sdíleném hostingu je `max_execution_time` typicky 30 s, což na zazipování
 * celého webu nestačí. Úloha si proto drží stav v souboru a prohlížeč ji volá
 * opakovaně; každý krok pracuje jen po dobu daného časového rozpočtu.
 */

declare(strict_types=1);

// Přímé volání tohoto souboru nedává smysl – vstupním bodem je index.php.
if (!defined('PB_VERSION')) {
    http_response_code(404);
    exit;
}


final class Job
{
    public const PHASE_SCAN = 'scan';
    public const PHASE_FILES = 'files';
    public const PHASE_DB = 'db';
    public const PHASE_FINALIZE = 'finalize';
    public const PHASE_DONE = 'done';
    public const PHASE_ERROR = 'error';

    /**
     * Od jaké velikosti zálohy doporučovat FTP místo prohlížeče.
     *
     * Stahování přes web je jeden dlouhý požadavek a hosting ho po nějaké době
     * ukončí. U takhle velkých záloh se to obvykle nestihne ani po částech.
     */
    public const FTP_HINT_BYTES = 2147483648; // 2 GB

    /** @return array<string,mixed>|null */
    public static function current(): ?array
    {
        return Storage::readData('job');
    }

    /** @param array<string,mixed> $job */
    public static function save(array $job): void
    {
        $job['updated'] = time();
        Storage::writeData('job', $job);
    }

    /**
     * Založí novou úlohu.
     *
     * @param 'files'|'db'|'both' $mode
     * @param list<string>        $databases
     * @return array<string,mixed>
     */
    public static function create(string $mode, array $databases = []): array
    {
        if (!class_exists('ZipArchive')) {
            throw new RuntimeException('Na serveru chybí rozšíření zip, archiv nelze vytvořit.');
        }
        Storage::clearTmp();

        $job = [
            'id' => bin2hex(random_bytes(8)),
            'mode' => $mode,
            'phase' => $mode === 'db' ? self::PHASE_DB : self::PHASE_SCAN,
            'started' => time(),
            'updated' => time(),
            'zip_base' => sprintf('backup_%s_%s', date('Ymd_His'), bin2hex(random_bytes(6))),
            'part' => 1,
            'parts' => [],
            'stack' => [['', 0]],
            'files_total' => 0,
            'files_done' => 0,
            'skipped' => 0,
            'manifest_pos' => 0,
            'scan_milestone' => 0,
            'batch_bytes' => 8 * 1024 * 1024,
            'db' => [
                'names' => array_values($databases),
                'index' => 0,
                'stage' => 'init',
                'tables' => [],
                'table_index' => 0,
                'row_offset' => 0,
                'file' => '',
                'rows' => 0,
            ],
            'messages' => [],
            'error' => '',
        ];

        $job['zip'] = self::partName((string)$job['zip_base'], 1);

        // Archiv se nezakládá dopředu: prázdný ZIP se na disk nezapisuje
        // (knihovna ho při zavření smaže). Ověříme jen, že tam umíme psát.
        $probe = Storage::backupDir() . '/.write-test';
        if (@file_put_contents($probe, 'x') === false) {
            throw new RuntimeException('Do adresáře pro zálohy nelze zapisovat. Zkontrolujte práva k zápisu.');
        }
        @unlink($probe);

        // Seznam souborů k zabalení. Čtení musí začít za ochrannou hlavičkou.
        $job['manifest_pos'] = FilesBackup::createManifest();

        self::message($job, 'Záloha zahájena (' . self::modeLabel($mode) . ').');
        self::save($job);
        Storage::log('ZÁLOHA: start, režim ' . $mode . ', archiv ' . $job['zip']);

        return $job;
    }

    /**
     * Provede jeden krok úlohy.
     *
     * @param array<string,string>|null $dbCredentials Přístupy k DB (jen v paměti)
     * @return array<string,mixed> Stav úlohy po kroku
     */
    public static function step(?array $dbCredentials): array
    {
        $lock = @fopen(PB_DATA . '/job.lock', 'cb');
        if ($lock === false) {
            throw new RuntimeException('Nelze získat zámek úlohy.');
        }
        if (!flock($lock, LOCK_EX | LOCK_NB)) {
            fclose($lock);
            throw new RuntimeException('Úloha už právě běží v jiném okně. Počkejte na dokončení.');
        }

        try {
            $job = self::current();
            if ($job === null) {
                throw new RuntimeException('Žádná probíhající úloha.');
            }
            if (in_array($job['phase'], [self::PHASE_DONE, self::PHASE_ERROR], true)) {
                return $job;
            }

            $budget = max(5, min(300, (int)Storage::config('time_budget', 15)));
            // set_time_limit bývá na hostinzích zakázaná – v PHP 8 by volání
            // neexistující funkce shodilo celý požadavek.
            if (function_exists('set_time_limit')) {
                @set_time_limit($budget + 60);
            }
            @ini_set('memory_limit', '512M');
            $deadline = microtime(true) + $budget;

            $startedAt = microtime(true);
            $phaseBefore = (string)$job['phase'];

            try {
                self::runPhases($job, $dbCredentials, $deadline);
            } catch (Throwable $e) {
                $job['phase'] = self::PHASE_ERROR;
                $job['error'] = $e->getMessage();
                self::message($job, 'CHYBA: ' . $e->getMessage());
                Storage::log('ZÁLOHA: chyba – ' . $e->getMessage());
            }

            $elapsed = microtime(true) - $startedAt;
            self::tuneBatchSize($job, $elapsed, $budget);

            // Do protokolu i tehdy, když se odpověď k prohlížeči nedostane –
            // po výpadku je pak vidět, kde se to zaseklo.
            Storage::log(sprintf(
                'ZÁLOHA: krok %s→%s, %.1f s, soubory %d/%d',
                $phaseBefore,
                (string)$job['phase'],
                $elapsed,
                (int)$job['files_done'],
                (int)$job['files_total']
            ));

            self::save($job);
            return $job;
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /**
     * Přizpůsobí velikost dávky skutečnému výkonu serveru.
     *
     * Komprimace probíhá až při zavření archivu, takže délku kroku nejde
     * odhadnout dopředu – měříme ji tedy zpětně. Když krok výrazně přetáhne,
     * dávku zmenšíme; když je naopak rychlý, opatrně ji zvětšíme. Cílem je
     * skončit dřív, než požadavek ukončí hosting (obvykle 60 s).
     *
     * @param array<string,mixed> $job
     */
    private static function tuneBatchSize(array &$job, float $elapsed, int $budget): void
    {
        $current = max(1024 * 1024, (int)($job['batch_bytes'] ?? 8 * 1024 * 1024));
        $min = 1024 * 1024;
        $max = 48 * 1024 * 1024;

        if ($elapsed > $budget * 2.5) {
            $current = (int)max($min, $current / 2);
        } elseif ($elapsed < $budget * 0.9) {
            $current = (int)min($max, $current * 1.5);
        }
        $job['batch_bytes'] = $current;
    }

    /**
     * @param array<string,mixed>       $job
     * @param array<string,string>|null $db
     */
    private static function runPhases(array &$job, ?array $db, float $deadline): void
    {
        while (microtime(true) < $deadline) {
            switch ($job['phase']) {
                case self::PHASE_SCAN:
                    FilesBackup::scanStep($job, $deadline);
                    if ($job['stack'] === []) {
                        self::message($job, 'Nalezeno ' . $job['files_total'] . ' souborů k zálohování.');
                        $job['phase'] = self::PHASE_FILES;
                    }
                    break;

                case self::PHASE_FILES:
                    self::rotatePartIfNeeded($job);
                    FilesBackup::zipStep($job, $deadline, self::zipPassword());
                    if ($job['files_done'] >= $job['files_total']) {
                        self::message($job, 'Soubory přidány do archivu (' . $job['files_done'] . ').');
                        $job['phase'] = $job['mode'] === 'both' ? self::PHASE_DB : self::PHASE_FINALIZE;
                    }
                    break;

                case self::PHASE_DB:
                    if ($job['mode'] === 'files') {
                        $job['phase'] = self::PHASE_FINALIZE;
                        break;
                    }
                    SqlDump::step($job, $db, $deadline);
                    if ($job['db']['stage'] === 'done') {
                        $job['phase'] = self::PHASE_FINALIZE;
                    }
                    break;

                case self::PHASE_FINALIZE:
                    self::finalize($job);
                    return;

                default:
                    return;
            }
        }
    }

    /** @param array<string,mixed> $job */
    private static function finalize(array &$job): void
    {
        $zipPath = Storage::backupDir() . '/' . $job['zip'];

        // Dumpy databází se přidávají až nakonec – jsou malé a je jistota,
        // že byly kompletně zapsané. Zároveň archivu nastavíme popisek.
        $dumps = (array)@glob(Storage::tmpDir() . '/*.sql*');
        $zip = new ZipArchive();
        $opened = $zip->open($zipPath, ZipArchive::CREATE);
        if ($opened !== true) {
            throw new RuntimeException('Nelze otevřít archiv pro dokončení (kód ' . (int)$opened . ').');
        }
        $password = self::zipPassword();
        if ($password !== '' && !$zip->setPassword($password)) {
            $zip->close();
            throw new RuntimeException('Archiv se nepodařilo zašifrovat, záloha byla zastavena.');
        }
        foreach ($dumps as $dump) {
            $entry = 'databaze/' . basename($dump);
            if ($zip->addFile($dump, $entry)) {
                // Dump databáze je to nejcitlivější v celé záloze – když se
                // nezašifruje, nesmíme to přejít mlčením.
                if ($password !== '' && !$zip->setEncryptionName($entry, ZipArchive::EM_AES_256)) {
                    $zip->close();
                    throw new RuntimeException('Export databáze se nepodařilo zašifrovat, '
                        . 'záloha byla zastavena.');
                }
            }
        }
        $zip->setArchiveComment('PHP Backup Tool ' . PB_VERSION . ' – ' . date('Y-m-d H:i:s'));
        $zip->close();

        Storage::clearTmp();
        Storage::deleteData('manifest');

        clearstatcache(true, $zipPath);

        // Poslední část do seznamu – pokud vůbec vznikla. Prázdný archiv
        // knihovna zip na disk nezapíše.
        if (is_file($zipPath) && (int)filesize($zipPath) > 0) {
            @chmod($zipPath, 0600);
            $job['parts'][] = $job['zip'];
        }
        if ($job['parts'] === []) {
            throw new RuntimeException('Záloha neobsahuje žádné soubory ani data, archiv se nevytvořil.');
        }

        // Každá část musí být čitelný archiv.
        $size = 0;
        $entries = 0;
        foreach ($job['parts'] as $part) {
            $path = Storage::backupDir() . '/' . $part;
            $check = new ZipArchive();
            if ($check->open($path, ZipArchive::CHECKCONS) !== true && $check->open($path) !== true) {
                throw new RuntimeException('Část zálohy ' . $part . ' je poškozená.');
            }
            $entries += $check->numFiles;
            $check->close();
            $size += (int)@filesize($path);
        }

        $job['phase'] = self::PHASE_DONE;
        $job['bytes'] = $size;
        $job['entries'] = $entries;
        $count = count($job['parts']);
        self::message($job, $count === 1
            ? 'Hotovo: ' . $job['parts'][0] . ' (' . View::bytes($size) . ', ' . $entries . ' položek).'
            : 'Hotovo: ' . $count . ' částí, celkem ' . View::bytes($size) . ', ' . $entries . ' položek.');
        if ($size >= self::FTP_HINT_BYTES) {
            self::message($job, 'Záloha má ' . View::bytes($size) . ' – stáhněte ji raději přes FTP '
                . 'z adresáře data/backups/. Přes prohlížeč se takhle velké soubory často nedotáhnou.');
        }
        Storage::log('ZÁLOHA: dokončena ' . $job['zip_base'] . ' – ' . $count . ' část(í), '
            . $size . ' B, ' . $entries . ' položek');

        self::applyRetention((string)$job['zip_base']);
    }

    /**
     * Smaže staré zálohy podle nastavení (dny + maximální počet).
     * Pracuje po celých zálohách, ne po jednotlivých částech.
     */
    public static function applyRetention(string $keepBase = ''): void
    {
        $days = (int)Storage::config('retain_days', 14);
        $max = (int)Storage::config('retain_max', 10);

        $deleted = 0;
        foreach (self::listBackups() as $i => $backup) {
            $tooOld = $days > 0 && $backup['created'] < time() - $days * 86400;
            $tooMany = $max > 0 && $i >= $max;
            if ((!$tooOld && !$tooMany) || $backup['base'] === $keepBase || $backup['incomplete']) {
                continue;
            }
            foreach ($backup['parts'] as $part) {
                if (@unlink(Storage::backupDir() . '/' . $part['name'])) {
                    $deleted++;
                }
            }
        }
        if ($deleted > 0) {
            Storage::log('ÚKLID: smazáno ' . $deleted . ' souborů starých záloh');
        }
    }

    /**
     * Seznam hotových záloh, nejnovější první. Části jedné zálohy jsou
     * seskupené pod jednou položkou.
     *
     * @return list<array{base:string,size:int,created:int,incomplete:bool,parts:list<array{name:string,size:int}>}>
     */
    public static function listBackups(): array
    {
        // Archiv rozpracované (nebo spadlé) úlohy leží na disku stejně jako
        // hotový. Bez rozlišení by se v seznamu tvářil jako plnohodnotná
        // záloha, což je horší než ho neukázat vůbec.
        $running = self::current();
        $unfinished = ($running !== null && (string)$running['phase'] !== self::PHASE_DONE)
            ? (string)($running['zip_base'] ?? '')
            : '';

        $groups = [];
        foreach ((array)@glob(Storage::backupDir() . '/backup_*.zip') as $path) {
            if (!is_file($path) || !self::validBackupName(basename($path))) {
                continue;
            }
            $name = basename($path);
            // Z "backup_20260727_101500_abc123abc123_p02.zip" udělá základ
            // "backup_20260727_101500_abc123abc123".
            $base = preg_replace('/(?:_p\d{2,3})?\.zip$/', '', $name);
            $size = (int)@filesize($path);
            $created = (int)@filemtime($path);

            if (!isset($groups[$base])) {
                $groups[$base] = [
                    'base' => (string)$base,
                    'size' => 0,
                    'created' => $created,
                    'parts' => [],
                    'incomplete' => ($unfinished !== '' && $base === $unfinished),
                ];
            }
            $groups[$base]['size'] += $size;
            // Datum zálohy = vznik její první části.
            $groups[$base]['created'] = min($groups[$base]['created'], $created);
            $groups[$base]['parts'][] = ['name' => $name, 'size' => $size];
        }

        foreach ($groups as &$group) {
            usort($group['parts'], static fn(array $a, array $b): int => strcmp($a['name'], $b['name']));
        }
        unset($group);

        $out = array_values($groups);
        usort($out, static fn(array $a, array $b): int => $b['created'] <=> $a['created']);
        return $out;
    }

    /** Ověří název archivu proti přesnému vzoru (ochrana proti průchodu cestou). */
    public static function validBackupName(string $name): bool
    {
        // Přípona _pNN je číslo části; starší zálohy ji nemají.
        return preg_match('/^backup_\d{8}_\d{6}_[a-f0-9]{12}(?:_p\d{2,3})?\.zip$/', $name) === 1;
    }

    /** Název souboru pro danou část zálohy. */
    public static function partName(string $base, int $part): string
    {
        return $base . '_p' . str_pad((string)$part, 2, '0', STR_PAD_LEFT) . '.zip';
    }

    /**
     * Velikost, po jejímž překročení se začne nová část (0 = nedělit).
     *
     * Dělení řeší dvě věci naráz: zavření archivu ho přepisuje celý, takže
     * u velkých záloh každý krok trvá déle a déle – a hlavně se pak jeden
     * obří soubor nedá stáhnout přes web, protože hosting požadavek ukončí.
     * Každá část je samostatný platný ZIP; při obnově se rozbalí všechny
     * do stejného adresáře.
     */
    public static function partLimitBytes(): int
    {
        $mb = (int)Storage::config('part_size_mb', 150);
        if ($mb <= 0) {
            return 0;
        }
        return max(10, min(4000, $mb)) * 1024 * 1024;
    }

    /**
     * Uzavře stávající část a přejde na další, pokud už je dost velká.
     *
     * @param array<string,mixed> $job
     */
    public static function rotatePartIfNeeded(array &$job): void
    {
        $limit = self::partLimitBytes();
        if ($limit <= 0) {
            return;
        }
        // Přepínáme s rezervou na jednu dávku, aby část limit nepřekročila.
        $reserve = max(1024 * 1024, intdiv($limit, 4));
        $threshold = max(1024 * 1024, $limit - $reserve);

        $path = Storage::backupDir() . '/' . $job['zip'];
        clearstatcache(true, $path);
        if (!is_file($path) || (int)filesize($path) < $threshold) {
            return;
        }

        $job['parts'][] = $job['zip'];
        $job['part'] = (int)$job['part'] + 1;
        $job['zip'] = self::partName((string)$job['zip_base'], (int)$job['part']);
        self::message($job, 'Část ' . ((int)$job['part'] - 1) . ' hotová ('
            . View::bytes((int)filesize($path)) . '), pokračuji do části ' . $job['part'] . '.');
    }

    public static function cancel(): void
    {
        $job = self::current();
        if ($job !== null) {
            // Smazat všechny rozpracované části, ne jen tu poslední.
            $names = array_merge((array)($job['parts'] ?? []), [(string)($job['zip'] ?? '')]);
            foreach ($names as $name) {
                $name = (string)$name;
                $path = Storage::backupDir() . '/' . $name;
                if ($name !== '' && self::validBackupName($name) && is_file($path)) {
                    @unlink($path);
                }
            }
        }
        Storage::clearTmp();
        Storage::deleteData('manifest');
        Storage::deleteData('job');
        Storage::log('ZÁLOHA: zrušena uživatelem');
    }

    /**
     * Heslo pro šifrování archivu (uložené zašifrovaně, dostupné po přihlášení).
     *
     * Když je šifrování nastavené, ale heslo nejde získat, úloha skončí chybou.
     * Tiché pokračování bez šifrování je horší než neúspěch: správce by dostal
     * čitelný archiv a domníval se, že je chráněný.
     */
    private static function zipPassword(): string
    {
        $sealed = (string)Storage::config('zip_password', '');
        if ($sealed === '') {
            return '';   // šifrování není zapnuté
        }

        $mk = Security::masterKey();
        if ($mk === null) {
            throw new RuntimeException('Archiv se má šifrovat, ale šifrovací klíč není k dispozici. '
                . 'Odhlaste se a přihlaste znovu; pokud potíž trvá, zrušte heslo archivu v nastavení.');
        }
        $plain = Crypto::open($sealed, $mk);
        if ($plain === null || $plain === '') {
            throw new RuntimeException('Heslo pro šifrování archivu se nepodařilo odemknout. '
                . 'Nastavte ho v nastavení znovu.');
        }
        if (!self::zipEncryptionAvailable()) {
            throw new RuntimeException('Tento server neumí šifrovat ZIP archivy (chybí podpora AES). '
                . 'Zrušte heslo archivu v nastavení, jinak zálohu nelze vytvořit.');
        }
        return $plain;
    }

    /** Umí knihovna ZIP na tomto serveru šifrovat? */
    public static function zipEncryptionAvailable(): bool
    {
        return class_exists('ZipArchive') && method_exists('ZipArchive', 'setEncryptionName');
    }

    /** @param array<string,mixed> $job */
    public static function message(array &$job, string $text): void
    {
        $job['messages'][] = date('H:i:s') . '  ' . $text;
        if (count($job['messages']) > 60) {
            $job['messages'] = array_slice($job['messages'], -60);
        }
    }

    /**
     * Data pro průběžné zobrazení v prohlížeči.
     *
     * @param array<string,mixed> $job
     * @return array<string,mixed>
     */
    public static function progress(array $job): array
    {
        $percent = 0;
        if ($job['phase'] === self::PHASE_SCAN) {
            $percent = 3;
        } elseif ($job['phase'] === self::PHASE_FILES) {
            $total = max(1, (int)$job['files_total']);
            $percent = 5 + (int)round(80 * min(1, (int)$job['files_done'] / $total));
        } elseif ($job['phase'] === self::PHASE_DB) {
            $percent = 88;
        } elseif ($job['phase'] === self::PHASE_FINALIZE) {
            $percent = 96;
        } elseif ($job['phase'] === self::PHASE_DONE) {
            $percent = 100;
        }

        return [
            'phase' => $job['phase'],
            'phase_label' => self::phaseLabel($job),
            'percent' => $percent,
            'files_total' => (int)$job['files_total'],
            'files_done' => (int)$job['files_done'],
            'messages' => array_values((array)$job['messages']),
            'error' => (string)$job['error'],
            'zip' => (string)$job['zip'],
            'parts' => array_values((array)($job['parts'] ?? [])),
            'size' => isset($job['bytes']) ? View::bytes((int)$job['bytes']) : '',
            'done' => $job['phase'] === self::PHASE_DONE,
        ];
    }

    /** @param array<string,mixed> $job */
    private static function phaseLabel(array $job): string
    {
        switch ($job['phase']) {
            case self::PHASE_SCAN:
                // U velkých webů trvá procházení i minuty – ať je z popisku
                // poznat, že se pořád něco děje.
                return 'Procházím adresáře… nalezeno ' . $job['files_total'] . ' souborů, '
                    . 'zbývá projít ' . count((array)$job['stack']) . ' složek ('
                    . (time() - (int)$job['started']) . ' s)';
            case self::PHASE_FILES:
                return 'Balím soubory… ' . $job['files_done'] . ' / ' . $job['files_total'];
            case self::PHASE_DB:
                $db = (string)($job['db']['names'][$job['db']['index']] ?? '');
                return 'Exportuji databázi ' . $db . '…';
            case self::PHASE_FINALIZE:
                return 'Dokončuji archiv…';
            case self::PHASE_DONE:
                return 'Hotovo';
            case self::PHASE_ERROR:
                return 'Chyba';
            default:
                return (string)$job['phase'];
        }
    }

    private static function modeLabel(string $mode): string
    {
        if ($mode === 'files') {
            return 'soubory';
        }
        if ($mode === 'db') {
            return 'databáze';
        }
        return 'soubory + databáze';
    }
}

<?php
/**
 * Export databáze do SQL – čistě přes mysqli, bez volání shellu.
 *
 * Proč bez `exec`/`mysqldump`:
 *  - na sdíleném hostingu bývá spouštění procesů zakázané,
 *  - a hlavně tím úplně odpadá riziko injektáže do příkazové řádky.
 *
 * Export běží po krocích (tabulka + posun v řádcích), takže zvládne i velké
 * databáze při krátkém `max_execution_time`.
 */

declare(strict_types=1);

// Přímé volání tohoto souboru nedává smysl – vstupním bodem je index.php.
if (!defined('PB_VERSION')) {
    http_response_code(404);
    exit;
}


final class SqlDump
{
    /** Systémové databáze, které se nezálohují. */
    private const SYSTEM_DATABASES = ['information_schema', 'performance_schema', 'mysql', 'sys'];

    /**
     * Připojení k serveru. Heslo se nikdy nikam nezapisuje ani neloguje.
     *
     * @param array<string,string> $creds
     */
    public static function connect(array $creds, ?string $database = null): mysqli
    {
        $host = trim((string)($creds['host'] ?? 'localhost'));
        $user = (string)($creds['user'] ?? '');
        $pass = (string)($creds['pass'] ?? '');
        $port = (int)($creds['port'] ?? 3306);

        if ($host === '' || $user === '') {
            throw new RuntimeException('Vyplňte prosím server a uživatele databáze.');
        }
        if ($port < 1 || $port > 65535) {
            throw new RuntimeException('Neplatný port databáze.');
        }
        if (!class_exists('mysqli')) {
            throw new RuntimeException('Na serveru chybí rozšíření mysqli, export databáze není možný.');
        }

        // Chyby si kontrolujeme sami (návratovými hodnotami) – výjimky mysqli
        // by mohly do hlášky propašovat část přihlašovacích údajů.
        mysqli_report(MYSQLI_REPORT_OFF);

        // Připojujeme se dvoufázově (init + real_connect), protože jinak nejde
        // nastavit timeout. Bez něj čeká mysqli na neodpovídající server až
        // 60 s, hosting mezitím ukončí celý požadavek a uživatel se dozví jen
        // „502“ – přesně to nechceme.
        $link = @mysqli_init();
        if ($link === false || $link === null) {
            throw new RuntimeException('Nepodařilo se inicializovat připojení k databázi.');
        }
        @$link->options(MYSQLI_OPT_CONNECT_TIMEOUT, 8);
        if (defined('MYSQLI_OPT_READ_TIMEOUT')) {
            @$link->options(MYSQLI_OPT_READ_TIMEOUT, 60);
        }

        $started = microtime(true);
        $ok = @$link->real_connect($host, $user, $pass, $database ?? '', $port);
        $took = round(microtime(true) - $started, 1);

        if (!$ok || $link->connect_errno) {
            $errno = (int)$link->connect_errno;
            // Detail (bez hesla) jen do chráněného protokolu.
            Storage::log('DB: připojení selhalo, chyba ' . $errno . ' po ' . $took . ' s – '
                . substr((string)$link->connect_error, 0, 200));

            // Rozlišíme „server neodpovídá“ od „špatné heslo“ – u prvního bývá
            // na vině hostname, protože databáze zpravidla neběží na localhost.
            $unreachable = in_array($errno, [2002, 2003, 2005, 2006], true);
            throw new RuntimeException($unreachable
                ? 'Databázový server „' . $host . '“ neodpovídá (' . $took . ' s). '
                  . 'Na sdíleném hostingu databáze obvykle neběží na localhost – '
                  . 'správný název serveru najdete v administraci hostingu u přístupů k databázi.'
                : 'Databáze odmítla přihlášení (chyba ' . $errno . '). '
                  . 'Zkontrolujte jméno uživatele a heslo.');
        }

        $link->set_charset('utf8mb4');
        return $link;
    }

    /**
     * Seznam databází dostupných danému uživateli.
     *
     * @param array<string,string> $creds
     * @return list<string>
     */
    public static function listDatabases(array $creds): array
    {
        $link = self::connect($creds);
        try {
            $res = $link->query('SHOW DATABASES');
            if ($res === false) {
                // Někteří hostingové oprávnění k výpisu databází neudělují.
                // Není to chyba – uživatel název zadá ručně.
                Storage::log('DB: SHOW DATABASES zamítnuto – ' . substr((string)$link->error, 0, 120));
                return [];
            }
            $out = [];
            while ($row = $res->fetch_row()) {
                $name = (string)$row[0];
                if (!in_array(strtolower($name), self::SYSTEM_DATABASES, true)) {
                    $out[] = $name;
                }
            }
            $res->free();
            sort($out, SORT_NATURAL | SORT_FLAG_CASE);
            return $out;
        } finally {
            $link->close();
        }
    }

    /**
     * Ověří, že databáze existuje a máme k ní přístup. Slouží k potvrzení
     * názvu zadaného ručně (když hosting nepovolí výpis databází).
     *
     * @param array<string,string> $creds
     */
    public static function databaseExists(array $creds, string $name): bool
    {
        // Přísná kontrola názvu ještě před dotazem na server.
        if (preg_match('/^[A-Za-z0-9_$\x{0080}-\x{FFFF}-]{1,64}$/u', $name) !== 1) {
            return false;
        }
        try {
            $link = self::connect($creds, $name);
        } catch (Throwable $e) {
            return false;
        }
        $link->close();
        return true;
    }

    /**
     * Jeden krok exportu.
     *
     * @param array<string,mixed>       $job
     * @param array<string,string>|null $creds
     */
    public static function step(array &$job, ?array $creds, float $deadline): void
    {
        $state = &$job['db'];

        if ($state['names'] === []) {
            $state['stage'] = 'done';
            return;
        }
        if ($creds === null) {
            throw new RuntimeException('Chybí přístupové údaje k databázi. Spusťte zálohu znovu.');
        }
        if ($state['index'] >= count($state['names'])) {
            $state['stage'] = 'done';
            return;
        }

        $database = (string)$state['names'][$state['index']];
        $link = self::connect($creds, $database);

        try {
            if ($state['stage'] === 'init') {
                self::beginDatabase($job, $link, $database);
            }
            while (microtime(true) < $deadline && $state['stage'] !== 'next' && $state['stage'] !== 'done') {
                self::work($job, $link, $database);
            }
        } finally {
            $link->close();
        }

        // Přechod na další databázi.
        if ($state['stage'] === 'next') {
            $state['index']++;
            $state['stage'] = $state['index'] >= count($state['names']) ? 'done' : 'init';
        }
    }

    /** @param array<string,mixed> $job */
    private static function beginDatabase(array &$job, mysqli $link, string $database): void
    {
        $state = &$job['db'];

        $tables = [];
        $views = [];
        $res = $link->query('SHOW FULL TABLES');
        if ($res === false) {
            throw new RuntimeException('Nepodařilo se načíst seznam tabulek databáze ' . $database . '.');
        }
        while ($row = $res->fetch_row()) {
            if (strtoupper((string)$row[1]) === 'VIEW') {
                $views[] = (string)$row[0];
            } else {
                $tables[] = (string)$row[0];
            }
        }
        $res->free();

        $state['tables'] = $tables;
        $state['views'] = $views;
        $state['table_index'] = 0;
        $state['row_offset'] = 0;
        $state['rows'] = 0;
        $state['stage'] = 'tables';
        $state['file'] = Storage::tmpDir() . '/' . self::safeFileName($database) . '.sql';

        $header = "-- PHP Backup Tool " . PB_VERSION . "\n"
            . "-- Databáze: " . $database . "\n"
            . "-- Vytvořeno: " . date('Y-m-d H:i:s') . "\n\n"
            . "SET NAMES utf8mb4;\n"
            . "SET SQL_MODE='NO_AUTO_VALUE_ON_ZERO';\n"
            . "SET FOREIGN_KEY_CHECKS=0;\n"
            . "SET UNIQUE_CHECKS=0;\n\n";
        self::write($state['file'], $header, true);

        Job::message($job, 'Databáze ' . $database . ': ' . count($tables) . ' tabulek, ' . count($views) . ' pohledů.');
    }

    /** @param array<string,mixed> $job */
    private static function work(array &$job, mysqli $link, string $database): void
    {
        $state = &$job['db'];

        switch ($state['stage']) {
            case 'tables':
                if ($state['table_index'] >= count($state['tables'])) {
                    $state['stage'] = 'views';
                    return;
                }
                self::dumpTableChunk($job, $link);
                return;

            case 'views':
                self::dumpViews($job, $link);
                $state['stage'] = 'triggers';
                return;

            case 'triggers':
                self::dumpTriggers($job, $link);
                $state['stage'] = 'finish';
                return;

            case 'finish':
                self::write($state['file'], "\nSET FOREIGN_KEY_CHECKS=1;\nSET UNIQUE_CHECKS=1;\n");
                Job::message($job, 'Databáze ' . $database . ' hotová (' . $state['rows'] . ' řádků).');
                $state['stage'] = 'next';
                return;

            default:
                $state['stage'] = 'next';
        }
    }

    /** @param array<string,mixed> $job */
    private static function dumpTableChunk(array &$job, mysqli $link): void
    {
        $state = &$job['db'];
        $table = (string)$state['tables'][$state['table_index']];
        $quoted = self::quoteIdent($table);
        $batch = max(50, min(5000, (int)Storage::config('batch_rows', 500)));

        // Struktura tabulky – jen jednou, před prvním blokem dat.
        if ((int)$state['row_offset'] === 0) {
            $res = $link->query('SHOW CREATE TABLE ' . $quoted);
            if ($res === false) {
                Job::message($job, 'Přeskočena tabulka ' . $table . ' (nelze přečíst strukturu).');
                $state['table_index']++;
                return;
            }
            $row = $res->fetch_assoc();
            $res->free();
            $create = (string)($row['Create Table'] ?? '');
            self::write(
                $state['file'],
                "\n-- ---------- Tabulka " . $table . " ----------\n"
                . 'DROP TABLE IF EXISTS ' . $quoted . ";\n" . $create . ";\n\n"
            );
        }

        // Data po blocích. Řadíme podle primárního klíče, aby stránkování
        // dávalo stabilní výsledek i mezi jednotlivými requesty.
        $order = self::orderClause($link, $table);
        $sql = 'SELECT * FROM ' . $quoted . $order . ' LIMIT ' . $batch . ' OFFSET ' . (int)$state['row_offset'];
        $res = $link->query($sql);
        if ($res === false) {
            Job::message($job, 'Přeskočena data tabulky ' . $table . '.');
            $state['table_index']++;
            $state['row_offset'] = 0;
            return;
        }

        $fields = $res->fetch_fields();
        $columns = [];
        foreach ($fields as $field) {
            $columns[] = self::quoteIdent($field->name);
        }

        $count = 0;
        $buffer = '';
        $values = [];
        while ($row = $res->fetch_row()) {
            $cells = [];
            foreach ($row as $i => $value) {
                $cells[] = self::formatValue($link, $value, $fields[$i]);
            }
            $values[] = '(' . implode(',', $cells) . ')';
            $count++;

            // Průběžný zápis, ať se do paměti nevejde celá tabulka.
            if (count($values) >= 200) {
                $buffer .= 'INSERT INTO ' . $quoted . ' (' . implode(',', $columns) . ") VALUES\n"
                    . implode(",\n", $values) . ";\n";
                $values = [];
                self::write($state['file'], $buffer);
                $buffer = '';
            }
        }
        if ($values !== []) {
            $buffer .= 'INSERT INTO ' . $quoted . ' (' . implode(',', $columns) . ") VALUES\n"
                . implode(",\n", $values) . ";\n";
        }
        if ($buffer !== '') {
            self::write($state['file'], $buffer);
        }
        $res->free();

        $state['rows'] = (int)$state['rows'] + $count;
        if ($count < $batch) {
            $state['table_index']++;
            $state['row_offset'] = 0;
        } else {
            $state['row_offset'] = (int)$state['row_offset'] + $count;
        }
    }

    /**
     * ORDER BY podle primárního klíče (pokud existuje) – bez něj MySQL
     * nezaručuje stejné pořadí u opakovaných dotazů s LIMIT/OFFSET.
     */
    private static function orderClause(mysqli $link, string $table): string
    {
        $res = @$link->query('SHOW KEYS FROM ' . self::quoteIdent($table) . " WHERE Key_name = 'PRIMARY'");
        if ($res === false) {
            return '';
        }
        $parts = [];
        while ($row = $res->fetch_assoc()) {
            $parts[] = self::quoteIdent((string)$row['Column_name']);
        }
        $res->free();
        return $parts === [] ? '' : ' ORDER BY ' . implode(',', $parts);
    }

    /** @param array<string,mixed> $job */
    private static function dumpViews(array &$job, mysqli $link): void
    {
        $state = &$job['db'];
        foreach ((array)($state['views'] ?? []) as $view) {
            $res = @$link->query('SHOW CREATE VIEW ' . self::quoteIdent((string)$view));
            if ($res === false) {
                continue;
            }
            $row = $res->fetch_assoc();
            $res->free();
            $create = (string)($row['Create View'] ?? '');
            if ($create === '') {
                continue;
            }
            self::write(
                $state['file'],
                "\n-- ---------- Pohled " . $view . " ----------\n"
                . 'DROP VIEW IF EXISTS ' . self::quoteIdent((string)$view) . ";\n" . $create . ";\n"
            );
        }
    }

    /** @param array<string,mixed> $job */
    private static function dumpTriggers(array &$job, mysqli $link): void
    {
        $state = &$job['db'];
        $res = @$link->query('SHOW TRIGGERS');
        if ($res === false) {
            return;
        }
        $triggers = [];
        while ($row = $res->fetch_assoc()) {
            $triggers[] = $row;
        }
        $res->free();
        if ($triggers === []) {
            return;
        }

        $sql = "\n-- ---------- Triggery ----------\nDELIMITER ;;\n";
        foreach ($triggers as $t) {
            $sql .= 'DROP TRIGGER IF EXISTS ' . self::quoteIdent((string)$t['Trigger']) . ";;\n"
                . 'CREATE TRIGGER ' . self::quoteIdent((string)$t['Trigger']) . ' '
                . (string)$t['Timing'] . ' ' . (string)$t['Event']
                . ' ON ' . self::quoteIdent((string)$t['Table'])
                . ' FOR EACH ROW ' . (string)$t['Statement'] . ";;\n";
        }
        $sql .= "DELIMITER ;\n";
        self::write($state['file'], $sql);
    }

    /**
     * Převede hodnotu na literál pro SQL. Binární data jdou hexadecimálně,
     * čísla bez uvozovek, ostatní přes `real_escape_string`.
     *
     * @param mixed  $value
     * @param object $field Metadata sloupce z mysqli
     */
    private static function formatValue(mysqli $link, $value, object $field): string
    {
        if ($value === null) {
            return 'NULL';
        }
        $value = (string)$value;

        $binaryTypes = [MYSQLI_TYPE_BLOB, MYSQLI_TYPE_TINY_BLOB, MYSQLI_TYPE_MEDIUM_BLOB, MYSQLI_TYPE_LONG_BLOB];
        $isBinary = (((int)$field->flags & MYSQLI_BINARY_FLAG) !== 0)
            && in_array((int)$field->type, $binaryTypes, true);
        if ($isBinary) {
            return $value === '' ? "''" : '0x' . bin2hex($value);
        }

        $numericTypes = [
            MYSQLI_TYPE_TINY, MYSQLI_TYPE_SHORT, MYSQLI_TYPE_LONG, MYSQLI_TYPE_LONGLONG,
            MYSQLI_TYPE_INT24, MYSQLI_TYPE_FLOAT, MYSQLI_TYPE_DOUBLE,
            MYSQLI_TYPE_DECIMAL, MYSQLI_TYPE_NEWDECIMAL,
        ];
        if (in_array((int)$field->type, $numericTypes, true) && is_numeric($value)) {
            return $value;
        }

        return "'" . $link->real_escape_string($value) . "'";
    }

    /** Bezpečné uvozování identifikátorů (tabulky, sloupce). */
    private static function quoteIdent(string $name): string
    {
        return '`' . str_replace('`', '``', $name) . '`';
    }

    /** Název souboru dumpu – z názvu databáze jen bezpečné znaky. */
    private static function safeFileName(string $database): string
    {
        $safe = preg_replace('/[^A-Za-z0-9_-]/', '_', $database);
        return 'dump_' . substr((string)$safe, 0, 60) . '_' . date('Ymd_His');
    }

    private static function write(string $file, string $content, bool $truncate = false): void
    {
        if (!Security::pathWithin(dirname($file), Storage::tmpDir()) && dirname($file) !== Storage::tmpDir()) {
            throw new RuntimeException('Neplatná cesta k dočasnému souboru.');
        }
        $flags = $truncate ? LOCK_EX : (FILE_APPEND | LOCK_EX);
        if (@file_put_contents($file, $content, $flags) === false) {
            throw new RuntimeException('Nelze zapsat dočasný soubor exportu databáze.');
        }
        @chmod($file, 0600);
    }
}

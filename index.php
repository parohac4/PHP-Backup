<?php
/**
 * PHP Backup Tool 4.0 – jediný vstupní bod nástroje.
 *
 * Nastavení, přihlášení, spuštění zálohy i stahování archivů běží přes tento
 * soubor. Ostatní kód leží v adresáři lib/ a nedá se z webu spustit přímo.
 */

declare(strict_types=1);

require __DIR__ . '/lib/bootstrap.php';

Security::sendHeaders();

$installed = Storage::isInstalled();
if ($installed) {
    Security::enforceIpAllowList();
    Security::enforceHttps();
}
Security::startSession();

$method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
$action = (string)($_POST['action'] ?? $_GET['action'] ?? '');
$isPost = $method === 'POST';

/**
 * Odpověď ve formátu JSON (pro volání z prohlížeče na pozadí).
 *
 * @param array<string,mixed> $data
 */
function jsonOut(array $data, int $code = 200): void
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

/** Přesměrování na sebe sama (Post/Redirect/Get – zabrání znovuodeslání formuláře). */
function redirectSelf(string $flashType = '', string $flashText = ''): void
{
    if ($flashText !== '') {
        $_SESSION['flash'] = ['type' => $flashType, 'text' => $flashText];
    }
    header('Location: ' . Security::basePath() . basename((string)($_SERVER['SCRIPT_NAME'] ?? 'index.php')), true, 303);
    exit;
}

/** Ověří CSRF token u každé měnící operace. */
function requireCsrf(bool $json = false): void
{
    if (Security::csrfValid()) {
        return;
    }

    // Rozlišujeme dvě věci, které vypadají stejně: skutečně prošlý formulář
    // (v session token je, ale jiný) a hosting, který relace neudrží vůbec.
    if (Security::sessionLost()) {
        // Prázdná session znamená, že uživatel není a nemůže být přihlášený.
        // Podrobnosti o prostředí serveru proto ven neposíláme – šly by
        // komukoli, kdo pošle POST bez cookie. Míří jen do chráněného
        // protokolu, ke kterému se správce dostane přes FTP.
        $notes = Security::sessionDiagnosis();
        Storage::log('CHYBA: relace se neudržela (' . (string)($_POST['action'] ?? '?') . ') – '
            . implode(' | ', $notes));

        $message = 'Server nedokázal uchovat relaci (session) mezi dvěma požadavky. '
            . 'Není to chyba vašeho prohlížeče – mazání cookies proto nepomůže.';
        if ($json) {
            jsonOut(['error' => $message], 403);
        }
        View::header('Problém s relací');
        echo '<div class="card"><h2>Server neudržel relaci</h2>';
        View::flash('err', $message);
        echo '<h3>Co s tím</h3><ol>'
            . '<li>Zkontrolujte volné místo a diskovou kvótu hostingu.</li>'
            . '<li>Smažte přes FTP obsah adresáře <code>data/sessions/</code>.</li>'
            . '<li>Přesnou příčinu najdete v souboru <code>data/audit.php</code>'
            . ' – otevřete ho přes FTP, poslední řádky ji popisují.</li>'
            . '</ol><p><a href="' . View::self() . '">Zkusit znovu</a></p></div>';
        View::footer();
        exit;
    }

    Storage::log('ODMÍTNUTO: neplatný CSRF token (' . (string)($_POST['action'] ?? '?') . ')');
    if ($json) {
        jsonOut(['error' => 'Platnost formuláře vypršela. Načtěte stránku znovu.'], 403);
    }
    http_response_code(403);
    exit("Platnost formuláře vypršela. Načtěte stránku znovu.\n");
}

/**
 * Přístupové údaje k databázi z formuláře, případně z uloženého (šifrovaného)
 * nastavení. Heslo se nikdy nevrací zpět do stránky.
 *
 * @return array<string,string>
 */
function dbCredentialsFromRequest(): array
{
    $saved = savedDbCredentials();
    $creds = [
        'host' => trim((string)($_POST['db_host'] ?? ($saved['host'] ?? 'localhost'))),
        'user' => trim((string)($_POST['db_user'] ?? ($saved['user'] ?? ''))),
        'pass' => (string)($_POST['db_pass'] ?? ''),
        'port' => (string)(int)($_POST['db_port'] ?? ($saved['port'] ?? 3306)),
    ];
    // Prázdné heslo + uložené přístupy = použij uložené heslo.
    if ($creds['pass'] === '' && isset($saved['pass'])) {
        $creds['pass'] = (string)$saved['pass'];
    }
    return $creds;
}

/** @return array<string,string>|null */
function savedDbCredentials(): ?array
{
    $sealed = (string)Storage::config('db_saved', '');
    $mk = Security::masterKey();
    if ($sealed === '' || $mk === null) {
        return null;
    }
    $plain = Crypto::open($sealed, $mk);
    if ($plain === null) {
        return null;
    }
    $data = json_decode($plain, true);
    return is_array($data) ? $data : null;
}

// =========================================================================
// 1) Instalace – proběhne jen jednou, dokud neexistuje konfigurace
// =========================================================================
if (!$installed) {
    $error = '';

    if ($isPost && $action === 'install') {
        $password = (string)($_POST['password'] ?? '');
        $password2 = (string)($_POST['password2'] ?? '');
        $root = trim((string)($_POST['backup_root'] ?? ''));
        if ($root === '__custom__') {
            $root = trim((string)($_POST['backup_root_custom'] ?? ''));
        }

        if (Security::length($password) < 10) {
            $error = 'Heslo musí mít alespoň 10 znaků.';
        } elseif ($password !== $password2) {
            $error = 'Hesla se neshodují.';
        } elseif ($root === '' || !is_dir($root) || !is_readable($root)) {
            $error = 'Zvolený adresář k zálohování neexistuje nebo není čitelný.';
        } else {
            $masterKey = Crypto::newKey();
            $wrap = Crypto::wrapMasterKey($masterKey, $password);

            $config = [
                'version' => PB_VERSION,
                'created' => time(),
                'pwd_hash' => password_hash($password, PASSWORD_DEFAULT),
                'mk_salt' => $wrap['salt'],
                'mk_wrapped' => $wrap['wrapped'],
                'backup_root' => rtrim(str_replace('\\', '/', (string)realpath($root)), '/'),
                'excludes' => FilesBackup::DEFAULT_EXCLUDES,
                'retain_days' => 14,
                'retain_max' => 10,
                'time_budget' => 15,
                'part_size_mb' => 150,
                'batch_rows' => 500,
                'require_https' => Security::isHttps(),
                'trust_proxy' => false,
                'ip_allow' => [],
                'zip_password' => '',
                'db_saved' => '',
            ];

            if (!Storage::createConfig($config)) {
                $error = 'Nástroj už je nainstalovaný, nebo se konfiguraci nepodařilo zapsat.';
            } else {
                Crypto::wipe($masterKey);
                Storage::log('INSTALACE: dokončena');
                $loginError = Security::login($password);
                redirectSelf($loginError === null ? 'ok' : 'warn',
                    $loginError === null
                        ? 'Nastaveno. Nástroj je připraven k použití.'
                        : 'Nastaveno. Přihlaste se prosím.');
            }
        }
    }

    View::header('Nastavení');
    require __DIR__ . '/views/install.php';
    View::footer();
    exit;
}

// =========================================================================
// 2) Přihlášení
// =========================================================================
if (!Security::isLoggedIn()) {
    $error = '';

    if ($isPost && $action === 'login') {
        // CSRF token má i přihlašovací formulář (ochrana proti vnucenému
        // přihlášení cizími údaji).
        requireCsrf();
        $error = (string)Security::login((string)($_POST['password'] ?? ''));
        if ($error === '') {
            redirectSelf();
        }
    }

    View::header('Přihlášení');
    require __DIR__ . '/views/login.php';
    View::footer();
    exit;
}

// =========================================================================
// 3) Přihlášený uživatel
// =========================================================================

// --- Stažení archivu -----------------------------------------------------
if ($action === 'download') {
    $name = (string)($_GET['file'] ?? '');
    $path = Storage::backupDir() . '/' . $name;

    if (!Job::validBackupName($name) || !is_file($path) || !Security::pathWithin($path, Storage::backupDir())) {
        Storage::log('ODMÍTNUTO: pokus o stažení "' . substr($name, 0, 80) . '"');
        http_response_code(404);
        exit("Záloha nenalezena.\n");
    }

    // Session dál nepotřebujeme. Kdybychom ji drželi, její zámek by po celou
    // dobu stahování blokoval každý další požadavek nástroje.
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }

    clearstatcache(true, $path);
    $size = (int)filesize($path);
    $start = 0;
    $end = $size - 1;
    $partial = false;

    // Podpora hlavičky Range: umožní prohlížeči přerušené stahování navázat,
    // místo aby u velkého archivu začínal vždy od začátku.
    $range = trim((string)($_SERVER['HTTP_RANGE'] ?? ''));
    if ($range !== '' && preg_match('/^bytes=(\d*)-(\d*)$/', $range, $m) === 1) {
        if ($m[1] === '' && $m[2] !== '') {
            $start = max(0, $size - (int)$m[2]);   // posledních N bajtů
            $partial = true;
        } elseif ($m[1] !== '') {
            $start = (int)$m[1];
            if ($m[2] !== '') {
                $end = min((int)$m[2], $size - 1);
            }
            $partial = true;
        }
        if ($start > $end || $start >= $size) {
            http_response_code(416);
            header('Content-Range: bytes */' . $size);
            exit;
        }
    }

    // Žádné vyrovnávací paměti ani komprese: u binárního souboru by komprese
    // rozbila slíbenou délku a prohlížeč by přenos ohlásil jako chybu sítě.
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    @ini_set('zlib.output_compression', 'Off');
    @ini_set('output_buffering', '0');
    if (function_exists('set_time_limit')) {
        @set_time_limit(0);
    }

    $length = $end - $start + 1;
    http_response_code($partial ? 206 : 200);
    header('Content-Type: application/zip');
    header('Content-Disposition: attachment; filename="' . $name . '"');
    header('Content-Length: ' . (string)$length);
    header('Accept-Ranges: bytes');
    if ($partial) {
        header('Content-Range: bytes ' . $start . '-' . $end . '/' . $size);
    }
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: no-store');

    Storage::log('STAŽENÍ: ' . $name . ' (' . View::bytes($length) . ($partial ? ', navázání' : '') . ')');

    // U požadavku HEAD stačí hlavičky.
    if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) === 'HEAD') {
        exit;
    }

    $fh = @fopen($path, 'rb');
    if ($fh === false) {
        Storage::log('STAŽENÍ: soubor nelze otevřít – ' . $name);
        exit;
    }
    if ($start > 0) {
        fseek($fh, $start);
    }

    // Posíláme po blocích a průběžně odesíláme – tím se drží nízká spotřeba
    // paměti bez ohledu na velikost archivu.
    $remaining = $length;
    while ($remaining > 0 && !feof($fh) && !connection_aborted()) {
        $data = fread($fh, (int)min(262144, $remaining));
        if ($data === false || $data === '') {
            break;
        }
        echo $data;
        $remaining -= strlen($data);
        flush();
    }
    fclose($fh);

    if ($remaining > 0) {
        // Do protokolu, ať je poznat, že přenos nedoběhl (a kolik zbývalo).
        Storage::log('STAŽENÍ: přerušeno, nedoručeno ' . View::bytes((int)$remaining));
    }
    exit;
}

// --- Operace na pozadí (JSON) -------------------------------------------
if ($isPost && in_array($action, ['db-list', 'job-start', 'job-step', 'job-cancel'], true)) {
    requireCsrf(true);

    try {
        switch ($action) {
            case 'db-list':
                $creds = dbCredentialsFromRequest();
                jsonOut(['databases' => SqlDump::listDatabases($creds)]);
                // no break – jsonOut ukončuje skript

            case 'job-start':
                if (Job::current() !== null && !in_array((string)Job::current()['phase'], [Job::PHASE_DONE, Job::PHASE_ERROR], true)) {
                    jsonOut(['error' => 'Jiná záloha ještě běží.'], 409);
                }
                $mode = (string)($_POST['mode'] ?? 'files');
                if (!in_array($mode, ['files', 'db', 'both'], true)) {
                    jsonOut(['error' => 'Neplatný režim zálohy.'], 400);
                }

                $databases = [];
                if ($mode !== 'files') {
                    $creds = dbCredentialsFromRequest();
                    $available = SqlDump::listDatabases($creds);
                    $requested = (array)($_POST['databases'] ?? []);
                    foreach ($requested as $name) {
                        if (in_array((string)$name, $available, true)) {
                            $databases[] = (string)$name;
                        }
                    }

                    // Ručně zadaný název – pro hostingy, kde výpis databází
                    // není povolen. Ověříme ho skutečným připojením.
                    $manual = trim((string)($_POST['db_manual'] ?? ''));
                    if ($manual !== '' && !in_array($manual, $databases, true)) {
                        if (!SqlDump::databaseExists($creds, $manual)) {
                            jsonOut(['error' => 'K databázi „' . $manual . '“ se nepodařilo připojit. '
                                . 'Zkontrolujte název.'], 400);
                        }
                        $databases[] = $manual;
                    }

                    if ($databases === []) {
                        jsonOut(['error' => 'Vyberte alespoň jednu databázi, nebo zadejte její název ručně.'], 400);
                    }
                    // Přístupy drží jen serverová session, na disk v otevřené
                    // podobě nejdou a po dokončení úlohy se smažou.
                    $_SESSION['dbcreds'] = $creds;

                    if (!empty($_POST['db_remember']) && Crypto::available()) {
                        $mk = Security::masterKey();
                        if ($mk !== null) {
                            Storage::saveConfig([
                                'db_saved' => Crypto::seal((string)json_encode($creds), $mk),
                            ]);
                        }
                    }
                }

                $job = Job::create($mode, $databases);
                jsonOut(['progress' => Job::progress($job)]);

            case 'job-step':
                $job = Job::step(isset($_SESSION['dbcreds']) ? (array)$_SESSION['dbcreds'] : null);
                if (in_array((string)$job['phase'], [Job::PHASE_DONE, Job::PHASE_ERROR], true)) {
                    unset($_SESSION['dbcreds']);
                }
                jsonOut(['progress' => Job::progress($job)]);

            case 'job-cancel':
                Job::cancel();
                unset($_SESSION['dbcreds']);
                jsonOut(['cancelled' => true]);
        }
    } catch (Throwable $e) {
        Storage::log('CHYBA (' . $action . '): ' . $e->getMessage());
        jsonOut(['error' => $e->getMessage()], 400);
    }
}

// --- Formulářové akce ----------------------------------------------------
if ($isPost) {
    requireCsrf();

    if ($action === 'logout') {
        Storage::log('ODHLÁŠENÍ');
        Security::logout();
        redirectSelf();
    }

    if ($action === 'delete') {
        // Maže se celá záloha včetně všech svých částí.
        $base = (string)($_POST['base'] ?? '');
        $deleted = 0;
        if (preg_match('/^backup_\d{8}_\d{6}_[a-f0-9]{12}$/', $base) === 1) {
            foreach (Job::listBackups() as $backup) {
                if ($backup['base'] !== $base) {
                    continue;
                }
                foreach ($backup['parts'] as $part) {
                    $path = Storage::backupDir() . '/' . $part['name'];
                    if (Job::validBackupName($part['name']) && is_file($path)
                        && Security::pathWithin($path, Storage::backupDir())) {
                        if (@unlink($path)) {
                            $deleted++;
                        }
                    }
                }
            }
        }
        if ($deleted > 0) {
            Storage::log('SMAZÁNÍ: ' . $base . ' (' . $deleted . ' souborů)');
            redirectSelf('ok', 'Záloha byla smazána.');
        }
        redirectSelf('err', 'Zálohu se nepodařilo smazat.');
    }

    if ($action === 'settings') {
        $root = trim((string)($_POST['backup_root'] ?? ''));
        if ($root === '' || !is_dir($root) || !is_readable($root)) {
            redirectSelf('err', 'Adresář k zálohování neexistuje nebo není čitelný.');
        }

        $excludes = [];
        foreach (preg_split('/\R/', (string)($_POST['excludes'] ?? '')) ?: [] as $line) {
            $line = trim($line);
            if ($line !== '') {
                $excludes[] = $line;
            }
        }

        $ipAllow = [];
        foreach (preg_split('/\R/', (string)($_POST['ip_allow'] ?? '')) ?: [] as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            if (strpos($line, '/') !== false) {
                [$subnet, $bits] = explode('/', $line, 2);
                $binary = @inet_pton(trim($subnet));
                $valid = $binary !== false
                    && ctype_digit($bits)
                    && (int)$bits >= 0
                    && (int)$bits <= strlen($binary) * 8;
            } else {
                $valid = filter_var($line, FILTER_VALIDATE_IP) !== false;
            }
            if (!$valid) {
                redirectSelf('err', 'Neplatný záznam v seznamu IP adres: ' . $line);
            }
            $ipAllow[] = $line;
        }
        // Pojistka proti zamknutí sebe sama.
        if ($ipAllow !== []) {
            $self = Security::clientIp();
            $covered = false;
            foreach ($ipAllow as $rule) {
                if (Security::ipMatches($self, $rule)) {
                    $covered = true;
                    break;
                }
            }
            if (!$covered) {
                $ipAllow[] = $self;
            }
        }

        $changes = [
            'backup_root' => rtrim(str_replace('\\', '/', (string)realpath($root)), '/'),
            'excludes' => $excludes,
            'ip_allow' => $ipAllow,
            'retain_days' => max(0, min(3650, (int)($_POST['retain_days'] ?? 14))),
            'retain_max' => max(0, min(999, (int)($_POST['retain_max'] ?? 10))),
            'time_budget' => max(5, min(300, (int)($_POST['time_budget'] ?? 15))),
            'part_size_mb' => max(0, min(4000, (int)($_POST['part_size_mb'] ?? 150))),
            'batch_rows' => max(50, min(5000, (int)($_POST['batch_rows'] ?? 500))),
            'require_https' => !empty($_POST['require_https']),
            'trust_proxy' => !empty($_POST['trust_proxy']),
        ];

        // Heslo pro šifrování archivu – uloží se zašifrovaně hlavním klíčem.
        $zipPassword = (string)($_POST['zip_password'] ?? '');
        if ($zipPassword === '') {
            if (!empty($_POST['zip_password_clear'])) {
                $changes['zip_password'] = '';
            }
        } else {
            $mk = Security::masterKey();
            if ($mk === null || !Crypto::available()) {
                redirectSelf('err', 'Heslo archivu nelze uložit (chybí rozšíření openssl).');
            }
            // Raději odmítnout hned, než vyrábět archivy, které se tváří
            // zašifrovaně a nejsou.
            if (!Job::zipEncryptionAvailable()) {
                redirectSelf('err', 'Tento server neumí šifrovat ZIP archivy (chybí podpora AES), '
                    . 'heslo archivu proto nelze nastavit.');
            }
            $sealed = Crypto::seal($zipPassword, $mk);
            if ($sealed === '') {
                redirectSelf('err', 'Heslo archivu se nepodařilo zašifrovat, nebylo uloženo.');
            }
            $changes['zip_password'] = $sealed;
        }

        if (!empty($_POST['db_forget'])) {
            $changes['db_saved'] = '';
        }

        Storage::saveConfig($changes);
        Storage::log('NASTAVENÍ: změněno');
        redirectSelf('ok', 'Nastavení bylo uloženo.');
    }

    if ($action === 'password') {
        $current = (string)($_POST['current_password'] ?? '');
        $new = (string)($_POST['new_password'] ?? '');
        $new2 = (string)($_POST['new_password2'] ?? '');

        if (!password_verify($current, (string)Storage::config('pwd_hash', ''))) {
            Storage::log('ZMĚNA HESLA: neúspěch (špatné stávající heslo)');
            redirectSelf('err', 'Stávající heslo není správné.');
        }
        if (Security::length($new) < 10) {
            redirectSelf('err', 'Nové heslo musí mít alespoň 10 znaků.');
        }
        if ($new !== $new2) {
            redirectSelf('err', 'Nová hesla se neshodují.');
        }

        // Hlavní klíč zůstává stejný, jen se přebalí novým heslem – uložená
        // tajemství tak zůstanou čitelná.
        $mk = Security::masterKey();
        if ($mk === null) {
            $mk = Crypto::unwrapMasterKey(
                (string)Storage::config('mk_wrapped', ''),
                (string)Storage::config('mk_salt', ''),
                $current
            );
        }
        if ($mk === null) {
            $mk = Crypto::newKey();
        }
        $wrap = Crypto::wrapMasterKey($mk, $new);

        Storage::saveConfig([
            'pwd_hash' => password_hash($new, PASSWORD_DEFAULT),
            'mk_salt' => $wrap['salt'],
            'mk_wrapped' => $wrap['wrapped'],
        ]);
        $_SESSION['mk'] = base64_encode($mk);
        Crypto::wipe($mk);
        session_regenerate_id(true);
        Storage::log('ZMĚNA HESLA: úspěch');
        redirectSelf('ok', 'Heslo bylo změněno.');
    }

    redirectSelf('err', 'Neznámá akce.');
}

// --- Hlavní stránka ------------------------------------------------------
$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

View::header('Zálohy');
require __DIR__ . '/views/app.php';
View::footer();

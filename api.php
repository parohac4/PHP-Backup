<?php
/**
 * PHP Backup Tool 4.0 – API pro automatizaci (cron, monitoring, vlastní skripty).
 *
 * Samostatný vstupní bod – žádná session, žádné cookies, žádný CSRF. Ověření
 * probíhá výhradně hlavičkou `Authorization: Bearer <token>`. Token se
 * spravuje ve webovém rozhraní (Nastavení → API tokeny) a nese vlastní
 * oprávnění (jen soubory / soubory i databáze) a volitelné omezení na IP.
 *
 * Použití (stejný princip jako webové rozhraní – záloha běží po krocích, aby
 * nenarazila na časový limit hostingu):
 *   1. POST ?action=start&mode=files   -> {"progress": {...}}
 *   2. GET  ?action=status             -> opakovaně, dokud "done" není true
 *   3. GET  ?action=download&file=...  -> stažení hotového archivu
 */

declare(strict_types=1);

require __DIR__ . '/lib/bootstrap.php';

Security::sendHeaders();
header('Content-Type: application/json; charset=utf-8');

/** @param array<string,mixed> $data */
function apiOut(array $data, int $code = 200): void
{
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

if (!Storage::isInstalled()) {
    apiOut(['error' => 'Nástroj ještě není nainstalován.'], 503);
}

// Bearer token je citlivé tajemství bez jakékoli další ochrany (žádná cookie,
// žádný CSRF) – proto je HTTPS vynucené vždy, bez ohledu na nastavení
// require_https, které platí jen pro webové rozhraní.
if (!Security::isHttps()) {
    apiOut(['error' => 'API je dostupné jen přes HTTPS.'], 403);
}

Security::enforceIpAllowList();

$method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
$action = (string)($_GET['action'] ?? '');

function apiBearerToken(): string
{
    $header = (string)($_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
    if ($header === '' && function_exists('getallheaders')) {
        foreach ((array)getallheaders() as $name => $value) {
            if (strcasecmp($name, 'Authorization') === 0) {
                $header = (string)$value;
                break;
            }
        }
    }
    if (preg_match('/^Bearer\s+(\S+)$/i', trim($header), $m) === 1) {
        return $m[1];
    }
    return '';
}

$bearer = apiBearerToken();
if ($bearer === '') {
    apiOut(['error' => 'Chybí hlavička Authorization: Bearer <token>.'], 401);
}

$auth = ApiToken::authenticate($bearer);
if (is_string($auth)) {
    Storage::log('API: odmítnuto – ' . $auth);
    apiOut(['error' => $auth], 401);
}

// Volitelné omezení konkrétního tokenu na vybrané IP adresy (navíc k
// obecnému ip_allow z nastavení nástroje).
if ($auth['ip_allow'] !== []) {
    $ip = Security::clientIp();
    $ok = false;
    foreach ($auth['ip_allow'] as $rule) {
        if (Security::ipMatches($ip, (string)$rule)) {
            $ok = true;
            break;
        }
    }
    if (!$ok) {
        Storage::log('API: odmítnuto – token mimo povolenou IP');
        apiOut(['error' => 'Přístup z této IP adresy není pro tento token povolen.'], 403);
    }
}

Security::setRuntimeMasterKey($auth['mk']);

/** @return array<string,string>|null */
function apiSavedDbCredentials(?string $mk): ?array
{
    $sealed = (string)Storage::config('db_saved', '');
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

try {
    switch ($action) {
        case 'start':
            if ($method !== 'POST') {
                apiOut(['error' => 'Použijte POST.'], 405);
            }
            $current = Job::current();
            if ($current !== null && !in_array((string)$current['phase'], [Job::PHASE_DONE, Job::PHASE_ERROR], true)) {
                apiOut(['error' => 'Jiná záloha ještě běží.'], 409);
            }

            $mode = (string)($_POST['mode'] ?? $_GET['mode'] ?? 'files');
            if (!in_array($mode, ['files', 'db', 'both'], true)) {
                apiOut(['error' => 'Neplatný režim zálohy.'], 400);
            }
            if ($mode !== 'files' && (string)$auth['scope'] !== 'files_db') {
                Storage::log('API: odmítnuto – token nemá oprávnění k záloze databáze');
                apiOut(['error' => 'Tento token nemá oprávnění spouštět zálohu databáze.'], 403);
            }

            $databases = [];
            $job = null;
            if ($mode !== 'files') {
                $creds = apiSavedDbCredentials($auth['mk']);
                if ($creds === null) {
                    apiOut(['error' => 'Nejsou uloženy žádné přístupy k databázi. Přihlaste se do '
                        . 'webového rozhraní, spusťte jednou zálohu databáze s volbou „zapamatovat '
                        . 'přístupy“ a pak už půjde spouštět i přes API.'], 400);
                }
                $available = SqlDump::listDatabases($creds);
                $requested = trim((string)($_POST['databases'] ?? $_GET['databases'] ?? ''));
                if ($requested !== '') {
                    foreach (explode(',', $requested) as $name) {
                        $name = trim($name);
                        if ($name !== '' && in_array($name, $available, true)) {
                            $databases[] = $name;
                        }
                    }
                } else {
                    $databases = $available;
                }
                if ($databases === []) {
                    apiOut(['error' => 'Nenalezena žádná databáze k zálohování.'], 400);
                }

                $job = Job::create($mode, $databases);
                if ($auth['mk'] !== null) {
                    $job['api_db_sealed'] = Crypto::seal((string)json_encode($creds), $auth['mk']);
                    Job::save($job);
                }
            } else {
                $job = Job::create($mode);
            }

            Storage::log('API: záloha spuštěna (token ' . $auth['id'] . ', režim ' . $mode . ')');
            apiOut(['progress' => Job::progress($job)]);
            // no break – apiOut ukončuje skript

        case 'status':
        case 'step':
            $job = Job::current();
            if ($job === null) {
                apiOut(['error' => 'Žádná probíhající úloha.'], 404);
            }
            // Token bez oprávnění k databázi nesmí sledovat ani popohánět
            // probíhající zálohu databáze – jinak by touhle cestou dostal
            // stav (a nakonec i sám export) dat, ke kterým nemá mít přístup.
            if ((string)$job['mode'] !== 'files' && (string)$auth['scope'] !== 'files_db') {
                Storage::log('API: odmítnuto – token nemá oprávnění sledovat zálohu databáze');
                apiOut(['error' => 'Tento token nemá oprávnění k záloze databáze.'], 403);
            }
            if (!in_array((string)$job['phase'], [Job::PHASE_DONE, Job::PHASE_ERROR], true)) {
                $dbCreds = null;
                if (!empty($job['api_db_sealed']) && $auth['mk'] !== null) {
                    $plain = Crypto::open((string)$job['api_db_sealed'], $auth['mk']);
                    $decoded = $plain !== null ? json_decode($plain, true) : null;
                    $dbCreds = is_array($decoded) ? $decoded : null;
                }
                $job = Job::step($dbCreds);
            }
            apiOut(['progress' => Job::progress($job)]);
            // no break – apiOut ukončuje skript

        case 'cancel':
            if ($method !== 'POST') {
                apiOut(['error' => 'Použijte POST.'], 405);
            }
            Job::cancel();
            Storage::log('API: záloha zrušena (token ' . $auth['id'] . ')');
            apiOut(['cancelled' => true]);
            // no break – apiOut ukončuje skript

        case 'download':
            $name = (string)($_GET['file'] ?? '');
            $path = Storage::backupDir() . '/' . $name;
            if (!Job::validBackupName($name) || !is_file($path) || !Security::pathWithin($path, Storage::backupDir())) {
                Storage::log('API: odmítnuto stažení „' . substr($name, 0, 80) . '“');
                apiOut(['error' => 'Záloha nenalezena.'], 404);
            }
            // Neznámý (starší) режим bereme jako potenciálně citlivý – token
            // bez oprávnění k databázi tak nesmí stáhnout ani archiv, u kterého
            // nevíme jistě, že obsahuje jen soubory.
            $backupMode = Job::backupModeFor($name);
            if (($backupMode === null || $backupMode !== 'files') && (string)$auth['scope'] !== 'files_db') {
                Storage::log('API: odmítnuto stažení „' . $name . '“ – token nemá oprávnění k databázi');
                apiOut(['error' => 'Tento token nemá oprávnění stáhnout tuto zálohu (obsahuje, '
                    . 'nebo může obsahovat, export databáze).'], 403);
            }

            clearstatcache(true, $path);
            $size = (int)filesize($path);
            while (ob_get_level() > 0) {
                ob_end_clean();
            }
            @ini_set('zlib.output_compression', 'Off');
            @ini_set('output_buffering', '0');
            if (function_exists('set_time_limit')) {
                @set_time_limit(0);
            }

            http_response_code(200);
            header('Content-Type: application/zip');
            header('Content-Disposition: attachment; filename="' . $name . '"');
            header('Content-Length: ' . (string)$size);
            header('X-Content-Type-Options: nosniff');
            header('Cache-Control: no-store');

            Storage::log('API: stažení ' . $name . ' (token ' . $auth['id'] . ')');

            $fh = @fopen($path, 'rb');
            if ($fh === false) {
                exit;
            }
            while (!feof($fh) && !connection_aborted()) {
                $data = fread($fh, 262144);
                if ($data === false || $data === '') {
                    break;
                }
                echo $data;
                flush();
            }
            fclose($fh);
            exit;

        default:
            apiOut(['error' => 'Neznámá akce. Použijte start, status, cancel nebo download.'], 400);
    }
} catch (Throwable $e) {
    Storage::log('API CHYBA (' . $action . '): ' . $e->getMessage());
    apiOut(['error' => $e->getMessage()], 400);
}

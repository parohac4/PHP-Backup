<?php
/**
 * Hlavní obrazovka – spuštění zálohy, seznam archivů a nastavení.
 *
 * @var array{type:string,text:string}|null $flash
 */
declare(strict_types=1);

// Přímé volání tohoto souboru nedává smysl – vstupním bodem je index.php.
if (!defined('PB_VERSION')) {
    http_response_code(404);
    exit;
}


$csrf = Security::csrfToken();
$nonce = Security::nonce();
$config = Storage::configAll();
$backups = Job::listBackups();
$job = Job::current();
$running = $job !== null && !in_array((string)$job['phase'], [Job::PHASE_DONE, Job::PHASE_ERROR], true);
$savedDb = savedDbCredentials();
$hasDb = class_exists('mysqli');
$totalSize = array_sum(array_column($backups, 'size'));
$apiTokens = ApiToken::publicList();
$newApiToken = $_SESSION['flash_token'] ?? null;
unset($_SESSION['flash_token']);
$apiUrl = preg_replace('/index\.php$/', 'api.php', View::self());
?>

<?php if (is_array($flash)): ?>
    <?php View::flash((string)$flash['type'], (string)$flash['text']); ?>
<?php endif; ?>

<div class="msg err hidden" id="expose-warning">
    Pozor: adresář <code>data/</code> je přístupný z internetu. Zálohy i konfigurace by mohl
    stáhnout kdokoli. Přesuňte nástroj na hosting s podporou <code>.htaccess</code>,
    nebo adresář zablokujte v nastavení serveru.
</div>

<!-- ------------------------------------------------------------ záloha -->
<div class="card">
    <h2>Nová záloha</h2>

    <div id="setup-panel" <?= $running ? 'class="hidden"' : '' ?>>
        <p class="hint">Zálohuje se adresář
            <code><?= View::e((string)($config['backup_root'] ?? '')) ?></code>.
            <?php $castMb = (int)($config['part_size_mb'] ?? 150); ?>
            <?php if ($castMb > 0): ?>
                Archiv se dělí na části po <?= View::e((string)$castMb) ?> MB.
            <?php else: ?>
                <strong>Dělení na části je vypnuté</strong> – vznikne jeden soubor,
                který se u většího webu nemusí dát stáhnout přes prohlížeč.
            <?php endif; ?></p>

        <div class="mode">
            <label><input type="radio" name="mode" value="files" checked> Jen soubory</label>
            <label><input type="radio" name="mode" value="db" <?= $hasDb ? '' : 'disabled' ?>> Jen databáze</label>
            <label><input type="radio" name="mode" value="both" <?= $hasDb ? '' : 'disabled' ?>> Soubory + databáze</label>
        </div>
        <?php if (!$hasDb): ?>
            <p class="hint">Na tomto serveru chybí rozšíření <code>mysqli</code>, zálohovat lze jen soubory.</p>
        <?php endif; ?>

        <div id="db-panel" class="hidden">
            <h3>Připojení k databázi</h3>
            <p class="hint">Údaje najdete v administraci hostingu u přístupů k databázi.
                Pozor na <strong>název serveru</strong> – na sdíleném hostingu databáze
                zpravidla neběží na <code>localhost</code>, ale na zvláštním stroji.
                Heslo se nikam neukládá, pokud si to sami nevyžádáte.</p>
            <div class="grid">
                <div>
                    <label for="db_host">Server</label>
                    <input type="text" id="db_host" name="db_host" value="<?= View::e((string)($savedDb['host'] ?? 'localhost')) ?>">
                </div>
                <div>
                    <label for="db_user">Uživatel</label>
                    <input type="text" id="db_user" name="db_user" value="<?= View::e((string)($savedDb['user'] ?? '')) ?>" autocomplete="off">
                </div>
                <div>
                    <label for="db_pass">Heslo<?= $savedDb ? ' (uložené – nechte prázdné)' : '' ?></label>
                    <input type="password" id="db_pass" name="db_pass" autocomplete="new-password">
                </div>
                <div>
                    <label for="db_port">Port</label>
                    <input type="number" id="db_port" name="db_port" value="<?= View::e((string)($savedDb['port'] ?? 3306)) ?>" min="1" max="65535">
                </div>
            </div>

            <div class="row">
                <button type="button" class="ghost" id="btn-dblist">Načíst databáze</button>
                <span class="hint" id="db-status"></span>
            </div>

            <div id="db-select-wrap" class="hidden">
                <label for="databases">Které databáze zálohovat</label>
                <select id="databases" name="databases" multiple size="6"></select>
                <label for="db_manual">Nebo název databáze ručně
                    (když hosting výpis nepovolí)</label>
                <input type="text" id="db_manual" name="db_manual" autocomplete="off"
                    placeholder="např. opslavia_web">
                <?php if (Crypto::available()): ?>
                    <label class="inline"><input type="checkbox" id="db_remember"> Zapamatovat přístupy
                        (uloží se zašifrované vaším heslem)</label>
                <?php endif; ?>
            </div>
        </div>

        <div class="row">
            <button type="button" id="btn-start">Spustit zálohu</button>
        </div>
    </div>

    <div id="progress-panel" <?= $running ? '' : 'class="hidden"' ?>>
        <p><strong id="phase-label"><?= View::e($running ? 'Přerušená záloha – lze pokračovat' : '') ?></strong></p>
        <div class="bar"><i id="bar-fill"></i></div>
        <div class="logbox" id="joblog"><?= View::e(implode("\n", (array)($job['messages'] ?? []))) ?></div>
        <div class="row">
            <button type="button" id="btn-resume" <?= $running ? '' : 'class="hidden"' ?>>Pokračovat</button>
            <button type="button" class="ghost" id="btn-cancel" <?= $running ? '' : 'class="hidden"' ?>>Zrušit zálohu</button>
            <a id="link-download" class="hidden" href="#">Stáhnout archiv</a>
        </div>
    </div>
</div>

<!-- ------------------------------------------------------------ archivy -->
<div class="card">
    <h2>Hotové zálohy</h2>
    <?php if ($backups === []): ?>
        <p class="hint">Zatím tu nic není.</p>
    <?php else: ?>
        <div class="tablewrap">
        <table>
            <tr>
                <th>Archiv</th>
                <th>Vytvořeno</th>
                <th class="num">Velikost</th>
                <th></th>
            </tr>
            <?php foreach ($backups as $backup): ?>
                <?php $velka = $backup['size'] >= Job::FTP_HINT_BYTES; ?>
                <tr<?= $velka || $backup['incomplete'] ? ' class="hasnote"' : '' ?>>
                    <td>
                        <?php if (count($backup['parts']) === 1): ?>
                            <a href="?action=download&amp;file=<?= View::e(rawurlencode($backup['parts'][0]['name'])) ?>">
                                <?= View::e($backup['parts'][0]['name']) ?></a>
                        <?php else: ?>
                            <?= View::e($backup['base']) ?><br>
                            <span class="hint">Stáhněte všechny části a rozbalte je do stejného adresáře:</span><br>
                            <button type="button" class="dl-all"
                                data-bytes="<?= View::e((string)$backup['size']) ?>"
                                data-parts="<?= View::e(implode('|', array_column($backup['parts'], 'name'))) ?>">
                                Stáhnout vše (<?= View::e((string)count($backup['parts'])) ?> částí)
                            </button>
                            <span class="hint dl-status"></span>
                            <details>
                                <summary>Jednotlivé části</summary>
                                <?php foreach ($backup['parts'] as $i => $part): ?>
                                    <a href="?action=download&amp;file=<?= View::e(rawurlencode($part['name'])) ?>"
                                       >část <?= View::e((string)($i + 1)) ?></a>
                                    <span class="hint">(<?= View::e(View::bytes($part['size'])) ?>)</span><?= $i < count($backup['parts']) - 1 ? ' · ' : '' ?>
                                <?php endforeach; ?>
                            </details>
                        <?php endif; ?>
                    </td>
                    <td class="date"><?= View::e(View::dateTime($backup['created'])) ?></td>
                    <td class="num"><?= View::e(View::bytes($backup['size'])) ?>
                        <?php if (count($backup['parts']) > 1): ?>
                            <br><span class="hint"><?= View::e((string)count($backup['parts'])) ?> částí</span>
                        <?php endif; ?>
                    </td>
                    <td class="num">
                        <form method="post">
                            <input type="hidden" name="action" value="delete">
                            <input type="hidden" name="_csrf" value="<?= View::e($csrf) ?>">
                            <input type="hidden" name="base" value="<?= View::e($backup['base']) ?>">
                            <button type="submit" class="danger">Smazat</button>
                        </form>
                    </td>
                </tr>
                <?php if ($backup['incomplete'] || $velka): ?>
                    <tr class="noterow">
                        <td colspan="4">
                            <?php if ($backup['incomplete']): ?>
                                <p class="msg err">Tahle záloha <strong>není dokončená</strong> –
                                    úloha ještě běží, nebo skončila chybou. Archiv je neúplný,
                                    nespoléhejte na něj.</p>
                            <?php endif; ?>
                            <?php if ($velka): ?>
                                <p class="msg warn">Záloha má
                                    <strong><?= View::e(View::bytes($backup['size'])) ?></strong>.
                                    Stáhněte ji <strong>přes FTP</strong>, ne přes prohlížeč – u takového
                                    objemu webserver přenos zpravidla ukončí dřív, než doběhne.
                                    Najdete ji v adresáři nástroje v <code>data/backups/</code>:
                                    <code class="path"><?= View::e(Storage::backupDir()) ?></code></p>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endif; ?>
            <?php endforeach; ?>
        </table>
        </div>
        <p class="hint">Celkem <?= View::e((string)count($backups)) ?> záloh,
            <?= View::e(View::bytes((int)$totalSize)) ?>.
            Starší se mažou automaticky podle nastavení.</p>
    <?php endif; ?>
</div>

<!-- ---------------------------------------------------------- nastavení -->
<div class="card">
    <details>
        <summary>Nastavení</summary>
        <form method="post">
            <input type="hidden" name="action" value="settings">
            <input type="hidden" name="_csrf" value="<?= View::e($csrf) ?>">

            <label for="backup_root">Adresář k zálohování</label>
            <input type="text" id="backup_root" name="backup_root" value="<?= View::e((string)($config['backup_root'] ?? '')) ?>">

            <label for="excludes">Co vynechat (jeden vzor na řádek)</label>
            <textarea id="excludes" name="excludes"><?= View::e(implode("\n", (array)($config['excludes'] ?? []))) ?></textarea>
            <p class="hint">Např. <code>cache</code>, <code>*.log</code>, <code>wp-content/uploads</code>.</p>

            <div class="grid">
                <div>
                    <label for="retain_days">Uchovat zálohy (dní, 0 = bez omezení)</label>
                    <input type="number" id="retain_days" name="retain_days" min="0" max="3650"
                        value="<?= View::e((string)($config['retain_days'] ?? 14)) ?>">
                </div>
                <div>
                    <label for="retain_max">Maximální počet záloh (0 = bez omezení)</label>
                    <input type="number" id="retain_max" name="retain_max" min="0" max="999"
                        value="<?= View::e((string)($config['retain_max'] ?? 10)) ?>">
                </div>
                <div>
                    <label for="part_size_mb">Velikost jedné části (MB, 0 = nedělit)</label>
                    <input type="number" id="part_size_mb" name="part_size_mb" min="0" max="4000"
                        value="<?= View::e((string)($config['part_size_mb'] ?? 150)) ?>">
                </div>
                <div>
                    <label for="time_budget">Délka jednoho kroku (s)</label>
                    <input type="number" id="time_budget" name="time_budget" min="5" max="300"
                        value="<?= View::e((string)($config['time_budget'] ?? 15)) ?>">
                </div>
                <div>
                    <label for="batch_rows">Řádků databáze na krok</label>
                    <input type="number" id="batch_rows" name="batch_rows" min="50" max="5000"
                        value="<?= View::e((string)($config['batch_rows'] ?? 500)) ?>">
                </div>
            </div>

            <h3>Bezpečnost</h3>
            <label class="inline">
                <input type="checkbox" name="require_https" <?= !empty($config['require_https']) ? 'checked' : '' ?>>
                Povolit přístup jen přes HTTPS
            </label>
            <label class="inline">
                <input type="checkbox" name="trust_proxy" <?= !empty($config['trust_proxy']) ? 'checked' : '' ?>>
                Věřit hlavičkám proxy (X-Forwarded-For) – zapněte jen za vlastní proxy/CDN
            </label>

            <label for="ip_allow">Povolené IP adresy (jedna na řádek, prázdné = bez omezení)</label>
            <textarea id="ip_allow" name="ip_allow"><?= View::e(implode("\n", (array)($config['ip_allow'] ?? []))) ?></textarea>
            <p class="hint">Vaše současná adresa je <code><?= View::e(Security::clientIp()) ?></code>
                a bude do seznamu doplněna automaticky, abyste se nezamkli.</p>

            <?php if (Crypto::available() && Job::zipEncryptionAvailable()): ?>
                <label for="zip_password">Heslo pro šifrování archivu (AES‑256)</label>
                <input type="password" id="zip_password" name="zip_password" autocomplete="new-password"
                    placeholder="<?= !empty($config['zip_password']) ? 'nastaveno – ponechte prázdné beze změny' : 'bez šifrování' ?>">
                <?php if (!empty($config['zip_password'])): ?>
                    <label class="inline"><input type="checkbox" name="zip_password_clear"> Zrušit šifrování archivů</label>
                <?php endif; ?>
                <p class="hint">Zašifrovaný archiv otevřete jen s tímto heslem – bez něj jsou data
                    nečitelná i pro toho, kdo se dostane k souborům na serveru.</p>
            <?php endif; ?>

            <?php if ($savedDb !== null): ?>
                <label class="inline"><input type="checkbox" name="db_forget"> Zapomenout uložené přístupy k databázi</label>
            <?php endif; ?>

            <div class="row">
                <button type="submit">Uložit nastavení</button>
            </div>
        </form>
    </details>

    <details>
        <summary>Změna hesla</summary>
        <form method="post" autocomplete="off">
            <input type="hidden" name="action" value="password">
            <input type="hidden" name="_csrf" value="<?= View::e($csrf) ?>">
            <label for="current_password">Stávající heslo</label>
            <input type="password" id="current_password" name="current_password" required autocomplete="current-password">
            <label for="new_password">Nové heslo (alespoň 10 znaků)</label>
            <input type="password" id="new_password" name="new_password" required minlength="10" autocomplete="new-password">
            <label for="new_password2">Nové heslo znovu</label>
            <input type="password" id="new_password2" name="new_password2" required minlength="10" autocomplete="new-password">
            <div class="row">
                <button type="submit">Změnit heslo</button>
            </div>
        </form>
    </details>

    <details>
        <summary>API tokeny pro automatizaci</summary>

        <?php if (is_string($newApiToken) && $newApiToken !== ''): ?>
            <p class="msg warn">Nový token – zkopírujte si ho, další zobrazení už nebude možné:<br>
                <code class="path"><?= View::e($newApiToken) ?></code></p>
            <p class="hint">Použití: <code>curl -H "Authorization: Bearer &lt;token&gt;" -X POST
                "https://váš-web/<?= View::e($apiUrl) ?>?action=start&amp;mode=files"</code>,
                dál opakovaně <code>?action=status</code>, dokud odpověď neobsahuje <code>"done": true</code>.</p>
        <?php endif; ?>

        <p class="hint">Token umí jen spustit/sledovat/zrušit zálohu a stáhnout hotový archiv přes
            samostatný soubor <code><?= View::e($apiUrl) ?></code> – nikdy nezmění nastavení, nesmaže
            zálohu ani nepřidá další token. Volá se hlavičkou <code>Authorization: Bearer …</code>,
            vyžaduje HTTPS bez ohledu na nastavení výše.</p>

        <?php if ($apiTokens === []): ?>
            <p class="hint">Zatím žádné tokeny.</p>
        <?php else: ?>
        <div class="tablewrap">
        <table>
            <tr>
                <th>Název</th><th>Vytvořen</th><th>Použito naposled</th>
                <th>Oprávnění</th><th>IP omezení</th><th></th>
            </tr>
            <?php foreach ($apiTokens as $t): ?>
            <tr>
                <td><?= View::e($t['name']) ?></td>
                <td class="date"><?= View::e(View::dateTime($t['created'])) ?></td>
                <td class="date"><?= $t['last_used'] !== null ? View::e(View::dateTime($t['last_used'])) : '—' ?></td>
                <td><?= $t['scope'] === 'files_db' ? 'Soubory i databáze' : 'Jen soubory' ?></td>
                <td><?= $t['ip_allow'] !== [] ? View::e(implode(', ', $t['ip_allow'])) : '—' ?></td>
                <td class="num">
                    <details>
                        <summary>Upravit</summary>
                        <form method="post">
                            <input type="hidden" name="action" value="api-token-update">
                            <input type="hidden" name="_csrf" value="<?= View::e($csrf) ?>">
                            <input type="hidden" name="id" value="<?= View::e($t['id']) ?>">
                            <label>Název
                                <input type="text" name="name" maxlength="60" required
                                    value="<?= View::e($t['name']) ?>">
                            </label>
                            <label class="inline">
                                <input type="radio" name="scope" value="files"
                                    <?= $t['scope'] !== 'files_db' ? 'checked' : '' ?>> Jen soubory
                            </label>
                            <label class="inline">
                                <input type="radio" name="scope" value="files_db"
                                    <?= (!$hasDb) ? 'disabled' : '' ?>
                                    <?= $t['scope'] === 'files_db' ? 'checked' : '' ?>> Soubory i databáze
                            </label>
                            <label>Omezit na IP adresy (nepovinné, jedna na řádek)
                                <textarea name="ip_allow"><?= View::e(implode("\n", $t['ip_allow'])) ?></textarea>
                            </label>
                            <div class="row"><button type="submit">Uložit změny</button></div>
                        </form>
                    </details>
                    <form method="post">
                        <input type="hidden" name="action" value="api-token-revoke">
                        <input type="hidden" name="_csrf" value="<?= View::e($csrf) ?>">
                        <input type="hidden" name="id" value="<?= View::e($t['id']) ?>">
                        <button type="submit" class="danger">Zrušit</button>
                    </form>
                </td>
            </tr>
            <?php endforeach; ?>
        </table>
        </div>
        <?php endif; ?>

        <h3>Vytvořit nový token</h3>
        <form method="post">
            <input type="hidden" name="action" value="api-token-create">
            <input type="hidden" name="_csrf" value="<?= View::e($csrf) ?>">
            <label for="api_name">Název</label>
            <input type="text" id="api_name" name="name" maxlength="60" required placeholder="např. cron-noční">
            <label class="inline"><input type="radio" name="scope" value="files" checked> Jen soubory</label>
            <label class="inline">
                <input type="radio" name="scope" value="files_db" <?= (!$hasDb) ? 'disabled' : '' ?>> Soubory i databáze
            </label>
            <label for="api_ip_allow">Omezit na IP adresy (nepovinné, jedna na řádek)</label>
            <textarea id="api_ip_allow" name="ip_allow"></textarea>
            <div class="row"><button type="submit">Vytvořit token</button></div>
        </form>
    </details>

    <details>
        <summary>Záznam činnosti</summary>
        <div class="logbox"><?= View::e(implode("\n", Storage::readLog(40))) ?></div>
    </details>
</div>

<form method="post" class="row">
    <input type="hidden" name="action" value="logout">
    <input type="hidden" name="_csrf" value="<?= View::e($csrf) ?>">
    <button type="submit" class="ghost">Odhlásit se</button>
</form>

<footer class="foot">PHP Backup Tool <?= View::e(PB_VERSION) ?></footer>

<script nonce="<?= View::e($nonce) ?>">
(function () {
    'use strict';
    var CSRF = <?= json_encode($csrf) ?>;
    var FTP_HINT_BYTES = <?= json_encode(Job::FTP_HINT_BYTES) ?>;
    var URL_SELF = <?= json_encode(basename((string)($_SERVER['SCRIPT_NAME'] ?? 'index.php'))) ?>;
    var $ = function (id) { return document.getElementById(id); };

    function post(action, extra) {
        var body = new URLSearchParams();
        body.set('action', action);
        (extra || []).forEach(function (pair) { body.append(pair[0], pair[1]); });
        return fetch(URL_SELF, {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'X-CSRF-Token': CSRF,
                'X-Requested-With': 'fetch',
                'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'
            },
            body: body.toString()
        }).then(function (r) {
            return r.json().catch(function () {
                throw new Error('Server vrátil neočekávanou odpověď (' + r.status + ').');
            }).then(function (data) {
                if (!r.ok) { throw new Error(data.error || ('Chyba ' + r.status)); }
                return data;
            });
        });
    }

    /* --- kontrola, zda datový adresář neuniká ven -------------------- */
    fetch('data/probe.txt', { credentials: 'same-origin' }).then(function (r) {
        if (r.ok) { return r.text(); }
        return '';
    }).then(function (text) {
        if (/^[0-9a-f]{16}$/.test(text.trim())) { $('expose-warning').classList.remove('hidden'); }
    }).catch(function () { /* zablokováno = v pořádku */ });

    /* --- přepínání režimu -------------------------------------------- */
    var dbPanel = $('db-panel');
    function currentMode() {
        var checked = document.querySelector('input[name="mode"]:checked');
        return checked ? checked.value : 'files';
    }
    Array.prototype.forEach.call(document.querySelectorAll('input[name="mode"]'), function (radio) {
        radio.addEventListener('change', function () {
            dbPanel.classList.toggle('hidden', currentMode() === 'files');
        });
    });

    function dbFields() {
        return [
            ['db_host', $('db_host') ? $('db_host').value : ''],
            ['db_user', $('db_user') ? $('db_user').value : ''],
            ['db_pass', $('db_pass') ? $('db_pass').value : ''],
            ['db_port', $('db_port') ? $('db_port').value : '3306']
        ];
    }

    /* --- načtení seznamu databází ------------------------------------ */
    var btnDbList = $('btn-dblist');
    if (btnDbList) {
        btnDbList.addEventListener('click', function () {
            var status = $('db-status');
            btnDbList.disabled = true;
            status.textContent = 'Připojuji…';
            post('db-list', dbFields()).then(function (data) {
                var select = $('databases');
                select.innerHTML = '';
                data.databases.forEach(function (name) {
                    var option = document.createElement('option');
                    option.value = name;
                    option.textContent = name;
                    option.selected = true;
                    select.appendChild(option);
                });
                $('db-select-wrap').classList.remove('hidden');
                status.textContent = data.databases.length > 0
                    ? 'Nalezeno ' + data.databases.length + ' databází.'
                    : 'Připojeno, ale hosting nepovolil výpis databází – zadejte název ručně.';
            }).catch(function (err) {
                status.textContent = err.message;
            }).finally(function () {
                btnDbList.disabled = false;
            });
        });
    }

    /* --- průběh zálohy ------------------------------------------------ */
    var stopped = false;

    function render(progress) {
        $('phase-label').textContent = progress.phase_label;
        $('bar-fill').style.width = progress.percent + '%';
        $('joblog').textContent = progress.messages.join('\n');
        $('joblog').scrollTop = $('joblog').scrollHeight;

        if (progress.done) {
            var link = $('link-download');
            link.href = '?action=download&file=' + encodeURIComponent(progress.zip);
            link.textContent = 'Stáhnout ' + progress.zip + ' (' + progress.size + ')';
            link.classList.remove('hidden');
            $('btn-cancel').classList.add('hidden');
            $('btn-resume').classList.add('hidden');
        }
    }

    /*
     * Krok zálohy může u velkých webů skončit výpadkem na straně hostingu
     * (504) nebo souběhem. Stav úlohy je uložený na serveru, takže se dá
     * bez rizika zopakovat – zkoušíme to několikrát s rostoucí pauzou,
     * teprve pak to vzdáme.
     */
    var failures = 0;
    var MAX_FAILURES = 8;

    function loop() {
        if (stopped) { return; }
        post('job-step', []).then(function (data) {
            failures = 0;
            render(data.progress);
            if (data.progress.done) {
                window.setTimeout(function () { window.location.reload(); }, 2500);
                return;
            }
            if (data.progress.phase === 'error') {
                $('btn-resume').classList.remove('hidden');
                return;
            }
            loop();
        }).catch(function (err) {
            if (stopped) { return; }
            failures++;
            if (failures > MAX_FAILURES) {
                $('phase-label').textContent = 'Přerušeno: ' + err.message
                    + ' – zkuste pokračovat ručně.';
                $('btn-resume').classList.remove('hidden');
                return;
            }
            var wait = Math.min(30, 3 * failures);
            $('phase-label').textContent = 'Server neodpověděl (' + err.message
                + ') – zkouším znovu za ' + wait + ' s… (' + failures + '/' + MAX_FAILURES + ')';
            window.setTimeout(loop, wait * 1000);
        });
    }

    function showProgress() {
        $('setup-panel').classList.add('hidden');
        $('progress-panel').classList.remove('hidden');
        $('btn-cancel').classList.remove('hidden');
        $('btn-resume').classList.add('hidden');
    }

    var btnStart = $('btn-start');
    if (btnStart) {
        btnStart.addEventListener('click', function () {
            var mode = currentMode();
            var fields = [['mode', mode]];
            if (mode !== 'files') {
                fields = fields.concat(dbFields());
                var select = $('databases');
                var chosen = select ? Array.prototype.filter.call(select.options, function (o) { return o.selected; }) : [];
                var manual = $('db_manual') ? $('db_manual').value.trim() : '';
                if (chosen.length === 0 && manual === '') {
                    $('db-status').textContent = 'Načtěte a vyberte databáze, nebo zadejte název ručně.';
                    return;
                }
                chosen.forEach(function (o) { fields.push(['databases[]', o.value]); });
                if ($('db_manual') && $('db_manual').value.trim() !== '') {
                    fields.push(['db_manual', $('db_manual').value.trim()]);
                }
                if ($('db_remember') && $('db_remember').checked) { fields.push(['db_remember', '1']); }
            }
            btnStart.disabled = true;
            stopped = false;
            post('job-start', fields).then(function (data) {
                showProgress();
                render(data.progress);
                loop();
            }).catch(function (err) {
                btnStart.disabled = false;
                $('db-status').textContent = err.message;
                window.alert(err.message);
            });
        });
    }

    var btnResume = $('btn-resume');
    if (btnResume) {
        btnResume.addEventListener('click', function () {
            btnResume.classList.add('hidden');
            $('btn-cancel').classList.remove('hidden');
            stopped = false;
            loop();
        });
    }

    /* --- postupné stažení všech částí zálohy ----------------------------
     * Části musí jít po sobě, ne najednou: paralelní přenosy si dělí linku,
     * každý pak trvá násobně déle a hosting je ukončí. Proto se na dokončení
     * každé části čeká a průběh se ukazuje uživateli.
     */
    function saveBlob(blob, filename) {
        var url = window.URL.createObjectURL(blob);
        var a = document.createElement('a');
        a.href = url;
        a.download = filename;
        document.body.appendChild(a);
        a.click();
        document.body.removeChild(a);
        window.setTimeout(function () { window.URL.revokeObjectURL(url); }, 60000);
    }

    function pause(ms) {
        return new Promise(function (resolve) { window.setTimeout(resolve, ms); });
    }

    function fetchPart(name, onProgress) {
        return fetch('?action=download&file=' + encodeURIComponent(name), {
            credentials: 'same-origin'
        }).then(function (resp) {
            if (!resp.ok) { throw new Error('HTTP ' + resp.status); }
            var total = parseInt(resp.headers.get('Content-Length') || '0', 10);

            // Když prohlížeč umí čtení po částech, hlásíme i procenta.
            if (!resp.body || !resp.body.getReader) { return resp.blob(); }
            var reader = resp.body.getReader();
            var chunks = [];
            var received = 0;
            return (function pump() {
                return reader.read().then(function (res) {
                    if (res.done) { return new Blob(chunks); }
                    chunks.push(res.value);
                    received += res.value.length;
                    if (total > 0) { onProgress(Math.round(received * 100 / total)); }
                    return pump();
                });
            }());
        });
    }

    function downloadAll(names, btn, status) {
        btn.disabled = true;
        var index = 0;

        function next() {
            if (index >= names.length) {
                status.textContent = 'Hotovo – stáhnuto ' + names.length + ' částí.';
                btn.disabled = false;
                return;
            }
            var label = 'část ' + (index + 1) + ' z ' + names.length;
            var attempt = 0;

            function tryPart() {
                attempt++;
                status.textContent = 'Stahuji ' + label + '…';
                fetchPart(names[index], function (percent) {
                    status.textContent = 'Stahuji ' + label + ' – ' + percent + ' %';
                }).then(function (blob) {
                    saveBlob(blob, names[index]);
                    index++;
                    // Malá pauza, ať prohlížeč stihne soubor uložit.
                    return pause(800).then(next);
                }).catch(function (err) {
                    if (attempt >= 3) {
                        status.textContent = label.charAt(0).toUpperCase() + label.slice(1)
                            + ' se nepodařilo stáhnout (' + err.message
                            + '). Zkuste ji zvlášť, nebo archiv vezměte z FTP.';
                        btn.disabled = false;
                        return;
                    }
                    status.textContent = label + ' selhala (' + err.message
                        + '), pokus ' + (attempt + 1) + ' z 3…';
                    pause(2500).then(tryPart);
                });
            }
            tryPart();
        }
        next();
    }

    Array.prototype.forEach.call(document.querySelectorAll('.dl-all'), function (btn) {
        btn.addEventListener('click', function () {
            var names = (btn.getAttribute('data-parts') || '').split('|').filter(function (n) { return n !== ''; });
            var status = btn.parentNode.querySelector('.dl-status');
            if (names.length === 0) { return; }

            // U hodně velké zálohy raději nejdřív připomeneme FTP.
            var bytes = parseInt(btn.getAttribute('data-bytes') || '0', 10);
            if (bytes >= FTP_HINT_BYTES && !window.confirm(
                    'Záloha má ' + Math.round(bytes / 1073741824 * 10) / 10 + ' GB.\n\n'
                    + 'Přes prohlížeč se takový objem často nedotáhne – spolehlivější je vzít\n'
                    + 'soubory z adresáře data/backups/ přes FTP.\n\n'
                    + 'Chcete přesto stahovat přes prohlížeč?')) {
                return;
            }
            downloadAll(names, btn, status);
        });
    });

    var btnCancel = $('btn-cancel');
    if (btnCancel) {
        btnCancel.addEventListener('click', function () {
            if (!window.confirm('Opravdu zrušit probíhající zálohu? Rozpracovaný archiv se smaže.')) { return; }
            stopped = true;
            post('job-cancel', []).then(function () { window.location.reload(); });
        });
    }
}());
</script>

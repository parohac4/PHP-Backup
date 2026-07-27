<?php
/**
 * Instalace – jediné nastavení, kterým uživatel musí projít.
 *
 * @var string $error
 */
declare(strict_types=1);

// Přímé volání tohoto souboru nedává smysl – vstupním bodem je index.php.
if (!defined('PB_VERSION')) {
    http_response_code(404);
    exit;
}


$suggested = FilesBackup::suggestRoots();
$checks = [
    ['ZIP archivy (rozšíření zip)', class_exists('ZipArchive'), true],
    ['Databáze (rozšíření mysqli)', class_exists('mysqli'), false],
    ['Šifrování uložených hesel (openssl)', Crypto::available(), false],
    ['Zápis do adresáře nástroje', is_writable(PB_DATA), true],
];
?>
<div class="card">
    <h2>Vítejte</h2>
    <p>Nástroj zabalí obsah vámi zvoleného adresáře do ZIP archivu a umí k němu přidat
        i export databáze. Stačí zvolit heslo a adresář – nic dalšího nastavovat nemusíte.</p>

    <?php if ($error !== ''): ?>
        <?php View::flash('err', $error); ?>
    <?php endif; ?>

    <?php View::flash('warn', 'Dokud nenastavíte heslo, může to za vás udělat kdokoli, kdo tuto adresu zná. Dokončete nastavení hned teď, po nahrání na FTP.'); ?>

    <?php if (!Security::isHttps()): ?>
        <?php View::flash('warn', 'Stránka neběží přes HTTPS. Heslo i zálohy by putovaly po síti nešifrovaně – pokud to hosting umožňuje, otevřete tuto adresu přes https://'); ?>
    <?php endif; ?>

    <h3>Kontrola serveru</h3>
    <table>
        <?php foreach ($checks as [$label, $ok, $required]): ?>
            <tr>
                <td><?= View::e($label) ?></td>
                <td class="num">
                    <?php if ($ok): ?>
                        <span class="msg ok">k dispozici</span>
                    <?php elseif ($required): ?>
                        <span class="msg err">chybí</span>
                    <?php else: ?>
                        <span class="msg warn">není k dispozici</span>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
    </table>

    <form method="post" autocomplete="off">
        <input type="hidden" name="action" value="install">

        <h3>1. Heslo do nástroje</h3>
        <p class="hint">Tímto heslem se budete přihlašovat. Chrání i uložené přístupy k databázi –
            proto ho nelze obnovit, pouze změnit po přihlášení.</p>
        <label for="password">Heslo (alespoň 10 znaků)</label>
        <input type="password" id="password" name="password" required minlength="10" autocomplete="new-password">
        <label for="password2">Heslo znovu</label>
        <input type="password" id="password2" name="password2" required minlength="10" autocomplete="new-password">

        <h3>2. Co zálohovat</h3>
        <p class="hint">Vyberte adresář, jehož obsah se má balit. Obvykle je to kořen webu.</p>
        <label for="backup_root">Adresář k zálohování</label>
        <select id="backup_root" name="backup_root">
            <?php foreach ($suggested as $i => $path): ?>
                <option value="<?= View::e($path) ?>"<?= $i === 0 ? ' selected' : '' ?>><?= View::e($path) ?></option>
            <?php endforeach; ?>
            <option value="__custom__">Jiná cesta (zadám ručně)…</option>
        </select>
        <label for="backup_root_custom">Vlastní absolutní cesta (vyplňte jen při volbě „Jiná cesta“)</label>
        <input type="text" id="backup_root_custom" name="backup_root_custom" placeholder="/data/web/virtuals/12345/virtual/www">

        <div class="row">
            <button type="submit">Dokončit nastavení</button>
        </div>
    </form>
</div>

<footer class="foot">PHP Backup Tool <?= View::e(PB_VERSION) ?></footer>

<?php
/**
 * Přihlašovací formulář.
 *
 * @var string $error
 */
declare(strict_types=1);

// Přímé volání tohoto souboru nedává smysl – vstupním bodem je index.php.
if (!defined('PB_VERSION')) {
    http_response_code(404);
    exit;
}


$lock = Security::lockRemaining();
$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);
?>
<div class="card">
    <h2>Přihlášení</h2>

    <?php if (is_array($flash)): ?>
        <?php View::flash((string)$flash['type'], (string)$flash['text']); ?>
    <?php endif; ?>

    <?php if ($error !== ''): ?>
        <?php View::flash('err', $error); ?>
    <?php endif; ?>

    <?php if (!Security::isHttps()): ?>
        <?php View::flash('warn', 'Spojení není šifrované (HTTPS). Heslo by šlo po síti čitelně.'); ?>
    <?php endif; ?>

    <form method="post" autocomplete="off">
        <input type="hidden" name="action" value="login">
        <input type="hidden" name="_csrf" value="<?= View::e(Security::csrfToken()) ?>">
        <label for="password">Heslo</label>
        <input type="password" id="password" name="password" required autocomplete="current-password" autofocus
            <?= $lock > 0 ? 'disabled' : '' ?>>
        <div class="row">
            <button type="submit" <?= $lock > 0 ? 'disabled' : '' ?>>Přihlásit se</button>
        </div>
    </form>
</div>

<footer class="foot">PHP Backup Tool <?= View::e(PB_VERSION) ?></footer>

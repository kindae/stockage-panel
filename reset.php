<?php
require __DIR__ . '/inc/bootstrap.php';
$token = (string)($_GET['token'] ?? $_POST['token'] ?? '');
$st = db()->prepare('SELECT r.*, u.username, u.display_name FROM password_resets r JOIN users u ON u.id = r.user_id
                     WHERE r.token_hash = ? AND r.used_at IS NULL AND r.expires_at > ? AND u.active = 1');
$st->execute([hash('sha256', $token), db_time()]);
$r = $token !== '' ? $st->fetch() : null;

$err = '';
if ($r && $_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $pw = (string)($_POST['password'] ?? '');
    if (strlen($pw) < 8) $err = 'Le mot de passe doit contenir au moins 8 caractères.';
    elseif ($pw !== ($_POST['password2'] ?? '')) $err = 'Les mots de passe ne correspondent pas.';
    else {
        db()->prepare('UPDATE users SET password_hash = ? WHERE id = ?')->execute([password_hash($pw, PASSWORD_DEFAULT), $r['user_id']]);
        db()->prepare('UPDATE password_resets SET used_at = ? WHERE user_id = ? AND used_at IS NULL')->execute([db_time(), $r['user_id']]);
        log_action('password_reset_email', $r['username'], (int)$r['user_id']);
        flash('Mot de passe modifié. Vous pouvez vous connecter.');
        redirect('login.php');
    }
}
page_header('Nouveau mot de passe');
?>
<div class="card narrow">
  <h1>Nouveau mot de passe</h1>
  <?php if (!$r): ?>
    <div class="alert error">Ce lien est invalide ou a expiré.</div>
    <p class="center"><a href="<?= e(url('forgot.php')) ?>">Faire une nouvelle demande</a></p>
  <?php else: ?>
    <p class="muted">Compte : <strong>@<?= e($r['username']) ?></strong></p>
    <?php if ($err): ?><div class="alert error"><?= e($err) ?></div><?php endif; ?>
    <form method="post" class="form">
      <?= csrf_field() ?><input type="hidden" name="token" value="<?= e($token) ?>">
      <label>Nouveau mot de passe<input type="password" name="password" required minlength="8" autofocus autocomplete="new-password"></label>
      <label>Confirmer<input type="password" name="password2" required minlength="8" autocomplete="new-password"></label>
      <button class="btn primary">Enregistrer</button>
    </form>
  <?php endif; ?>
</div>
<?php page_footer();

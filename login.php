<?php
require __DIR__ . '/inc/bootstrap.php';
if (needs_setup()) redirect('setup.php');
if (current_user()) redirect('files.php');

$err = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    if (login_throttled()) {
        $err = 'Trop de tentatives. Réessayez dans 15 minutes.';
    } else {
        $login = trim($_POST['username'] ?? '');
        $st = db()->prepare("SELECT * FROM users WHERE username = ? OR (email <> '' AND email = ?)");
        $st->execute([$login, $login]);
        $u = $st->fetch();
        if ($u && password_verify((string)($_POST['password'] ?? ''), $u['password_hash'])) {
            if (!$u['active']) {
                $err = 'Votre compte est suspendu. Contactez un administrateur.';
            } else {
                if (password_needs_rehash($u['password_hash'], PASSWORD_DEFAULT)) {
                    db()->prepare('UPDATE users SET password_hash = ? WHERE id = ?')->execute([password_hash($_POST['password'], PASSWORD_DEFAULT), $u['id']]);
                }
                session_regenerate_id(true);
                $_SESSION['uid'] = (int)$u['id'];
                db()->prepare('UPDATE users SET last_login = CURRENT_TIMESTAMP WHERE id = ?')->execute([$u['id']]);
                log_action('login', '', (int)$u['id']);
                redirect('files.php');
            }
        } else {
            log_action('login_fail', $login, null);
            $err = 'Identifiant ou mot de passe incorrect.';
        }
    }
}
page_header('Connexion');
?>
<div class="card narrow">
  <h1>Connexion</h1>
  <p class="muted">Accédez à votre espace de stockage.</p>
  <?php if ($err): ?><div class="alert error"><?= e($err) ?></div><?php endif; ?>
  <form method="post" class="form">
    <?= csrf_field() ?>
    <label>Identifiant ou e-mail<input name="username" required autofocus value="<?= e($_POST['username'] ?? '') ?>" autocomplete="username"></label>
    <label>Mot de passe<input type="password" name="password" required autocomplete="current-password"></label>
    <button class="btn primary">Se connecter</button>
  </form>
  <p class="center">Pas encore de compte ? <a href="<?= e(url('register.php')) ?>">S’inscrire avec un code</a></p>
</div>
<?php page_footer();

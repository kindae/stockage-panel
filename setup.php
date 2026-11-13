<?php
require __DIR__ . '/inc/bootstrap.php';
if (!needs_setup()) redirect('login.php');

$err = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $username = trim($_POST['username'] ?? '');
    $display  = trim($_POST['display_name'] ?? '') ?: $username;
    $email    = trim($_POST['email'] ?? '');
    $pass     = (string)($_POST['password'] ?? '');
    if (!valid_username($username)) $err = 'Identifiant : 3 à 32 caractères (lettres, chiffres, . _ -).';
    elseif (strlen($pass) < 8) $err = 'Le mot de passe doit contenir au moins 8 caractères.';
    elseif ($pass !== ($_POST['password2'] ?? '')) $err = 'Les mots de passe ne correspondent pas.';
    else {
        $id = create_user($username, $display, $email, $pass, 'admin');
        registration_code();
        if (!setting('default_quota')) set_setting('default_quota', (string)(200 * 1024 * 1024));
        session_regenerate_id(true);
        $_SESSION['uid'] = $id;
        log_action('setup', 'Création du compte administrateur', $id);
        flash('Installation terminée. Bienvenue dans l’administration !');
        redirect('admin.php');
    }
}
page_header('Installation');
?>
<div class="card narrow">
  <h1>Installation</h1>
  <p class="muted">Créez le compte <strong>administrateur principal</strong>. Cette page n’apparaît qu’une seule fois.</p>
  <?php if ($err): ?><div class="alert error"><?= e($err) ?></div><?php endif; ?>
  <form method="post" class="form">
    <?= csrf_field() ?>
    <label>Identifiant<input name="username" required value="<?= e($_POST['username'] ?? '') ?>" autocomplete="username"></label>
    <label>Nom affiché<input name="display_name" value="<?= e($_POST['display_name'] ?? '') ?>"></label>
    <label>E-mail<input type="email" name="email" value="<?= e($_POST['email'] ?? '') ?>"></label>
    <label>Mot de passe<input type="password" name="password" required minlength="8" autocomplete="new-password"></label>
    <label>Confirmer le mot de passe<input type="password" name="password2" required minlength="8" autocomplete="new-password"></label>
    <button class="btn primary">Créer l’administrateur</button>
  </form>
</div>
<?php page_footer();

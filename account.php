<?php
require __DIR__ . '/inc/bootstrap.php';
$me = require_login();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    if (($_POST['do'] ?? '') === 'profile') {
        $display = trim($_POST['display_name'] ?? '');
        $email = trim($_POST['email'] ?? '');
        if ($display === '') flash('Le nom affiché est obligatoire.', 'error');
        elseif ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) flash('E-mail invalide.', 'error');
        else {
            db()->prepare('UPDATE users SET display_name = ?, email = ? WHERE id = ?')->execute([mb_substr($display, 0, 60), $email, $me['id']]);
            flash('Profil mis à jour.');
        }
    } else {
        $pw = (string)($_POST['password'] ?? '');
        if (!password_verify((string)($_POST['current'] ?? ''), $me['password_hash'])) flash('Mot de passe actuel incorrect.', 'error');
        elseif (strlen($pw) < 8) flash('Le nouveau mot de passe doit contenir au moins 8 caractères.', 'error');
        elseif ($pw !== ($_POST['password2'] ?? '')) flash('Les mots de passe ne correspondent pas.', 'error');
        else {
            db()->prepare('UPDATE users SET password_hash = ? WHERE id = ?')->execute([password_hash($pw, PASSWORD_DEFAULT), $me['id']]);
            session_regenerate_id(true);
            log_action('password_change');
            flash('Mot de passe modifié.');
        }
    }
    redirect('account.php');
}
$used = user_usage($me);
page_header('Mon compte', 'account');
?>
<h1>Mon compte</h1>
<div class="grid2">
  <section class="card">
    <h2>Profil</h2>
    <p class="muted">Identifiant : <strong class="mono">@<?= e($me['username']) ?></strong> · Dossier : <strong class="mono">/<?= e($me['folder']) ?></strong></p>
    <?= quota_bar($used, user_quota($me)) ?>
    <form method="post" class="form">
      <?= csrf_field() ?><input type="hidden" name="do" value="profile">
      <label>Nom affiché<input name="display_name" required value="<?= e($me['display_name']) ?>"></label>
      <label>E-mail<input type="email" name="email" value="<?= e($me['email']) ?>"></label>
      <button class="btn primary">Enregistrer</button>
    </form>
  </section>
  <section class="card">
    <h2>Mot de passe</h2>
    <form method="post" class="form">
      <?= csrf_field() ?><input type="hidden" name="do" value="password">
      <label>Mot de passe actuel<input type="password" name="current" required autocomplete="current-password"></label>
      <label>Nouveau mot de passe<input type="password" name="password" required minlength="8" autocomplete="new-password"></label>
      <label>Confirmer<input type="password" name="password2" required minlength="8" autocomplete="new-password"></label>
      <button class="btn primary">Changer le mot de passe</button>
    </form>
  </section>
</div>
<?php page_footer();

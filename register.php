<?php
require __DIR__ . '/inc/bootstrap.php';
if (needs_setup()) redirect('setup.php');
if (current_user()) redirect('files.php');

$err = '';
$open = setting('registration_open', '1') === '1';
if ($open && $_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $code     = strtoupper(trim($_POST['code'] ?? ''));
    $username = trim($_POST['username'] ?? '');
    $display  = trim($_POST['display_name'] ?? '') ?: $username;
    $email    = trim($_POST['email'] ?? '');
    $pass     = (string)($_POST['password'] ?? '');
    $exists = db()->prepare('SELECT COUNT(*) FROM users WHERE username = ? OR folder = ?');
    $exists->execute([$username, strtolower($username)]);

    $emailUsed = false;
    if ($email !== '') {
        $eu = db()->prepare('SELECT COUNT(*) FROM users WHERE LOWER(email) = LOWER(?)');
        $eu->execute([$email]);
        $emailUsed = $eu->fetchColumn() > 0;
    }
    // Code principal (affiché dans l'administration) ou code d'invitation
    $isMain = normalize_code($code) !== '' && hash_equals(normalize_code(registration_code()), normalize_code($code));
    $invite = $isMain ? null : find_invite($code);

    if (login_throttled()) $err = 'Trop de tentatives. Réessayez dans 15 minutes.';
    elseif (!$isMain && !$invite) {
        log_action('login_fail', 'code inscription invalide', null);
        $err = 'Code d’inscription invalide. Demandez un code valide à l’administrateur.';
    }
    elseif ($invite && ($p = invite_problem($invite))) $err = $p;
    elseif (!valid_username($username)) $err = 'Identifiant : 3 à 32 caractères (lettres, chiffres, . _ -), il servira de nom à votre dossier.';
    elseif ($exists->fetchColumn() > 0) $err = 'Cet identifiant est déjà utilisé.';
    elseif ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) $err = 'Adresse e-mail invalide.';
    elseif ($emailUsed) $err = 'Cette adresse e-mail est déjà utilisée par un autre compte.';
    elseif (strlen($pass) < 8) $err = 'Le mot de passe doit contenir au moins 8 caractères.';
    elseif ($pass !== ($_POST['password2'] ?? '')) $err = 'Les mots de passe ne correspondent pas.';
    elseif ($invite && !invite_consume((int)$invite['id'])) $err = 'Ce code d’invitation vient d’être épuisé.';
    else {
        $role = $invite && in_array($invite['role'], ['user', 'moderator'], true) ? $invite['role'] : 'user';
        $quota = $invite && $invite['quota_bytes'] !== null ? (int)$invite['quota_bytes'] : null;
        $id = create_user($username, mb_substr($display, 0, 60), $email, $pass, $role, $quota, $invite ? (int)$invite['id'] : null);
        // Code principal à usage unique : un nouveau code est généré automatiquement après chaque inscription.
        if ($isMain && setting('rotate_code', '1') === '1') set_setting('registration_code', random_code());
        log_action('register', $username . ($invite ? ' (code ' . $invite['code'] . ')' : ''), $id);
        session_regenerate_id(true);
        $_SESSION['uid'] = $id;
        flash('Bienvenue ' . $display . ' ! Votre dossier personnel est prêt.');
        redirect('files.php');
    }
}
page_header('Inscription');
?>
<div class="card narrow">
  <h1>Inscription</h1>
  <?php if (!$open): ?>
    <div class="alert warn">Les inscriptions sont actuellement fermées.</div>
  <?php else: ?>
  <p class="muted">Un <strong>code d’inscription</strong> est nécessaire. Il vous est remis par l’administration.</p>
  <?php if ($err): ?><div class="alert error"><?= e($err) ?></div><?php endif; ?>
  <form method="post" class="form">
    <?= csrf_field() ?>
    <label>Code d’inscription<input name="code" required placeholder="XXXX-XXXX" value="<?= e($_POST['code'] ?? ($_GET['code'] ?? '')) ?>" class="mono" autocomplete="off"></label>
    <label>Identifiant (nom de votre dossier)<input name="username" required pattern="[a-zA-Z0-9][a-zA-Z0-9_.\-]{2,31}" value="<?= e($_POST['username'] ?? '') ?>" autocomplete="username"></label>
    <label>Nom complet ou nom du studio<input name="display_name" value="<?= e($_POST['display_name'] ?? '') ?>"></label>
    <label>E-mail<input type="email" name="email" value="<?= e($_POST['email'] ?? '') ?>"></label>
    <label>Mot de passe<input type="password" name="password" required minlength="8" autocomplete="new-password"></label>
    <label>Confirmer le mot de passe<input type="password" name="password2" required minlength="8" autocomplete="new-password"></label>
    <button class="btn primary">Créer mon compte</button>
  </form>
  <?php endif; ?>
  <p class="center">Déjà inscrit ? <a href="<?= e(url('login.php')) ?>">Se connecter</a></p>
</div>
<?php page_footer();

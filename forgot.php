<?php
require __DIR__ . '/inc/bootstrap.php';
if (needs_setup()) redirect('setup.php');
if (current_user()) redirect('files.php');

$sent = false; $err = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $login = trim((string)($_POST['login'] ?? ''));
    if (recent_actions('reset_request', 3600) >= 5) {
        $err = 'Trop de demandes. Réessayez dans une heure.';
    } elseif ($login === '') {
        $err = 'Indiquez votre identifiant ou votre e-mail.';
    } else {
        log_action('reset_request', $login, null);
        $st = db()->prepare('SELECT * FROM users WHERE (username = ? OR LOWER(email) = LOWER(?)) AND active = 1');
        $st->execute([$login, $login]);
        $u = $st->fetch();
        if ($u && !empty($u['email'])) {
            $token = random_token(32);
            db()->prepare('UPDATE password_resets SET used_at = ? WHERE user_id = ? AND used_at IS NULL')->execute([db_time(), $u['id']]);
            db()->prepare('INSERT INTO password_resets(user_id, token_hash, expires_at) VALUES(?,?,?)')
                ->execute([$u['id'], hash('sha256', $token), db_time(3600)]);
            $link = url('reset.php?token=' . $token);
            send_mail($u['email'], 'Réinitialisation de votre mot de passe',
                "Bonjour " . $u['display_name'] . ",\n\n"
                . "Une demande de réinitialisation du mot de passe a été faite pour votre compte « " . $u['username'] . " » sur " . cfg('app_name') . ".\n\n"
                . "Pour choisir un nouveau mot de passe, ouvrez ce lien (valable 1 heure) :\n" . $link . "\n\n"
                . "Si vous n’êtes pas à l’origine de cette demande, ignorez simplement cet e-mail : votre mot de passe reste inchangé.\n");
        }
        // Même réponse dans tous les cas (on ne révèle pas si le compte existe).
        $sent = true;
    }
}
page_header('Mot de passe oublié');
?>
<div class="card narrow">
  <h1>Mot de passe oublié</h1>
  <?php if ($sent): ?>
    <div class="alert ok">Si un compte avec une adresse e-mail correspond, un lien de réinitialisation vient d’être envoyé. Pensez à vérifier les courriers indésirables.</div>
    <p class="muted small">Pas d’e-mail enregistré sur votre compte ? Demandez à un administrateur de réinitialiser votre mot de passe.</p>
  <?php else: ?>
    <p class="muted">Indiquez votre identifiant ou votre e-mail : vous recevrez un lien pour choisir un nouveau mot de passe.</p>
    <?php if ($err): ?><div class="alert error"><?= e($err) ?></div><?php endif; ?>
    <form method="post" class="form">
      <?= csrf_field() ?>
      <label>Identifiant ou e-mail<input name="login" required autofocus autocomplete="username"></label>
      <button class="btn primary">Envoyer le lien</button>
    </form>
  <?php endif; ?>
  <p class="center"><a href="<?= e(url('login.php')) ?>">← Retour à la connexion</a></p>
</div>
<?php page_footer();

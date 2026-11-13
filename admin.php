<?php
require __DIR__ . '/inc/bootstrap.php';
$me = require_staff();

/** Le modérateur ne gère que les membres simples ; l'admin gère tout le monde sauf lui-même pour rôle/suppression. */
function can_manage(array $me, array $target): bool {
    if ($me['role'] === 'admin') return true;
    return $target['role'] === 'user';
}
function find_user(int $id): ?array {
    $st = db()->prepare('SELECT * FROM users WHERE id = ?'); $st->execute([$id]); return $st->fetch() ?: null;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $do = $_POST['do'] ?? '';
    try {
        switch ($do) {
        case 'new_code':
            $c = trim($_POST['custom_code'] ?? '');
            if ($c !== '') {
                if (!preg_match('/^[A-Za-z0-9-]{4,32}$/', $c)) throw new RuntimeException('Code personnalisé : 4 à 32 caractères (lettres, chiffres, tiret).');
                set_setting('registration_code', strtoupper($c));
            } else {
                set_setting('registration_code', random_code());
            }
            log_action('code', 'Nouveau code d’inscription');
            flash('Nouveau code d’inscription généré.');
            break;

        case 'reg_options':
            set_setting('registration_open', !empty($_POST['registration_open']) ? '1' : '0');
            set_setting('rotate_code', !empty($_POST['rotate_code']) ? '1' : '0');
            flash('Options d’inscription enregistrées.');
            break;

        case 'settings':
            if (!is_admin($me)) throw new RuntimeException('Réservé à l’administrateur.');
            $q = parse_size_mb((string)($_POST['default_quota'] ?? ''));
            if ($q === null || $q < 1024 * 1024) throw new RuntimeException('Quota par défaut invalide (ex. 200, 500M, 1G).');
            set_setting('default_quota', (string)$q);
            set_setting('pretty_urls', !empty($_POST['pretty_urls']) ? '1' : '0');
            log_action('settings', 'Quota par défaut : ' . human_size($q));
            flash('Paramètres enregistrés. Quota par défaut : ' . human_size($q) . '.');
            break;

        case 'quota': {
            $u = find_user((int)$_POST['id']);
            if (!$u || !can_manage($me, $u)) throw new RuntimeException('Action non autorisée.');
            $raw = trim((string)($_POST['quota'] ?? ''));
            $q = $raw === '' ? null : parse_size_mb($raw);
            if ($raw !== '' && $q === null) throw new RuntimeException('Quota invalide (ex. 200, 500M, 2G ou vide = défaut).');
            db()->prepare('UPDATE users SET quota_bytes = ? WHERE id = ?')->execute([$q, $u['id']]);
            log_action('quota', $u['username'] . ' : ' . ($q === null ? 'défaut' : human_size($q)));
            flash('Quota de ' . $u['username'] . ' : ' . ($q === null ? 'par défaut (' . human_size(default_quota()) . ')' : human_size($q)) . '.');
            break;
        }

        case 'toggle': {
            $u = find_user((int)$_POST['id']);
            if (!$u || !can_manage($me, $u) || $u['id'] == $me['id']) throw new RuntimeException('Action non autorisée.');
            db()->prepare('UPDATE users SET active = 1 - active WHERE id = ?')->execute([$u['id']]);
            log_action('toggle', $u['username']);
            flash($u['active'] ? 'Compte ' . $u['username'] . ' suspendu.' : 'Compte ' . $u['username'] . ' réactivé.');
            break;
        }

        case 'role': {
            if (!is_admin($me)) throw new RuntimeException('Réservé à l’administrateur.');
            $u = find_user((int)$_POST['id']);
            $role = $_POST['role'] ?? '';
            if (!$u || $u['id'] == $me['id'] || !in_array($role, ['user', 'moderator', 'admin'], true)) throw new RuntimeException('Action non autorisée.');
            db()->prepare('UPDATE users SET role = ? WHERE id = ?')->execute([$role, $u['id']]);
            log_action('role', $u['username'] . ' → ' . $role);
            flash('Rôle de ' . $u['username'] . ' : ' . ['user' => 'membre', 'moderator' => 'modérateur', 'admin' => 'administrateur'][$role] . '.');
            break;
        }

        case 'password': {
            $u = find_user((int)$_POST['id']);
            if (!$u || !can_manage($me, $u)) throw new RuntimeException('Action non autorisée.');
            $pw = (string)($_POST['password'] ?? '');
            if (strlen($pw) < 8) throw new RuntimeException('Mot de passe : 8 caractères minimum.');
            db()->prepare('UPDATE users SET password_hash = ? WHERE id = ?')->execute([password_hash($pw, PASSWORD_DEFAULT), $u['id']]);
            log_action('password_reset', $u['username']);
            flash('Mot de passe de ' . $u['username'] . ' réinitialisé.');
            break;
        }

        case 'delete': {
            $u = find_user((int)$_POST['id']);
            if (!$u || !can_manage($me, $u) || $u['id'] == $me['id']) throw new RuntimeException('Action non autorisée.');
            if (($_POST['confirm'] ?? '') !== $u['username']) throw new RuntimeException('Confirmation incorrecte : tapez exactement l’identifiant.');
            rm_rf(cfg('storage_dir') . '/' . $u['folder']);
            db()->prepare('DELETE FROM users WHERE id = ?')->execute([$u['id']]);
            log_action('delete_user', $u['username']);
            flash('Membre ' . $u['username'] . ' et ses fichiers supprimés.');
            break;
        }

        case 'create': {
            $username = trim($_POST['username'] ?? '');
            $role = is_admin($me) ? ($_POST['role'] ?? 'user') : 'user';
            if (!in_array($role, ['user', 'moderator', 'admin'], true)) $role = 'user';
            $pw = (string)($_POST['password'] ?? '');
            if (!valid_username($username)) throw new RuntimeException('Identifiant invalide (3 à 32 caractères : lettres, chiffres, . _ -).');
            $ex = db()->prepare('SELECT COUNT(*) FROM users WHERE username = ? OR folder = ?'); $ex->execute([$username, strtolower($username)]);
            if ($ex->fetchColumn()) throw new RuntimeException('Identifiant déjà utilisé.');
            if (strlen($pw) < 8) throw new RuntimeException('Mot de passe : 8 caractères minimum.');
            create_user($username, trim($_POST['display_name'] ?? '') ?: $username, trim($_POST['email'] ?? ''), $pw, $role);
            log_action('create_user', $username . ' (' . $role . ')');
            flash('Compte ' . $username . ' créé.');
            break;
        }
        }
    } catch (RuntimeException $ex) {
        flash($ex->getMessage(), 'error');
    }
    redirect('admin.php' . (isset($_POST['tab']) ? '#' . preg_replace('/[^a-z]/', '', $_POST['tab']) : ''));
}

$users = db()->query("SELECT * FROM users ORDER BY role = 'admin' DESC, role = 'moderator' DESC, created_at DESC")->fetchAll();
$totalUsed = 0;
foreach ($users as &$u) { $u['used'] = dir_size(cfg('storage_dir') . '/' . $u['folder']); $u['quota'] = user_quota($u); $totalUsed += $u['used']; }
unset($u);
$shareCount = (int)db()->query('SELECT COUNT(*) FROM shares')->fetchColumn();
$logs = db()->query('SELECT l.*, u.username FROM logs l LEFT JOIN users u ON u.id = l.user_id ORDER BY l.id DESC LIMIT 60')->fetchAll();
$roles = ['admin' => 'Administrateur', 'moderator' => 'Modérateur', 'user' => 'Membre'];
$code = registration_code();
$regUrl = url('register.php');
$disk = @disk_free_space(cfg('storage_dir'));

page_header('Administration', 'admin');
?>
<h1>Administration</h1>

<div class="stats">
  <div class="stat"><b><?= count($users) ?></b><span>comptes</span></div>
  <div class="stat"><b><?= e(human_size($totalUsed)) ?></b><span>stockage utilisé</span></div>
  <div class="stat"><b><?= $shareCount ?></b><span>liens partagés</span></div>
  <div class="stat"><b><?= e(human_size(default_quota())) ?></b><span>quota par défaut</span></div>
  <?php if ($disk): ?><div class="stat"><b><?= e(human_size($disk)) ?></b><span>libre sur le serveur</span></div><?php endif; ?>
</div>

<div class="grid2">
  <section class="card" id="code">
    <h2>🔑 Code d’inscription</h2>
    <p class="muted">Visible uniquement par l’administration. Donnez-le à la personne qui doit s’inscrire.</p>
    <div class="codebox">
      <span class="mono big"><?= e($code) ?></span>
      <button class="btn" data-copy="<?= e($code) ?>">Copier le code</button>
    </div>
    <div class="copyrow"><input readonly value="<?= e($regUrl) ?>"><button class="btn sm" data-copy="<?= e($regUrl) ?>">Copier le lien d’inscription</button></div>
    <form method="post" class="row-form">
      <?= csrf_field() ?><input type="hidden" name="do" value="new_code"><input type="hidden" name="tab" value="code">
      <input name="custom_code" placeholder="Code personnalisé (facultatif)" class="mono">
      <button class="btn primary">Changer le code</button>
    </form>
    <form method="post" class="checks">
      <?= csrf_field() ?><input type="hidden" name="do" value="reg_options"><input type="hidden" name="tab" value="code">
      <label><input type="checkbox" name="rotate_code" value="1" <?= setting('rotate_code', '1') === '1' ? 'checked' : '' ?>> Changer automatiquement le code après chaque inscription (code à usage unique)</label>
      <label><input type="checkbox" name="registration_open" value="1" <?= setting('registration_open', '1') === '1' ? 'checked' : '' ?>> Inscriptions ouvertes</label>
      <button class="btn sm">Enregistrer</button>
    </form>
  </section>

  <section class="card" id="settings">
    <h2>⚙️ Paramètres</h2>
    <?php if (is_admin($me)): ?>
    <form method="post" class="form">
      <?= csrf_field() ?><input type="hidden" name="do" value="settings"><input type="hidden" name="tab" value="settings">
      <label>Quota par défaut de chaque membre
        <input name="default_quota" value="<?= e(round(default_quota() / 1048576)) ?>" placeholder="200">
        <small class="muted">En Mo (ex. 200), ou avec unité : 500M, 1G, 2.5G. Un quota personnalisé par membre est possible ci-dessous.</small>
      </label>
      <label class="check"><input type="checkbox" name="pretty_urls" value="1" <?= setting('pretty_urls', '1') === '1' ? 'checked' : '' ?>> Liens courts <code><?= e(url('s/…')) ?></code> (nécessite mod_rewrite / .htaccess)</label>
      <button class="btn primary">Enregistrer</button>
    </form>
    <p class="muted small">Limite d’envoi PHP actuelle : <strong><?= e(human_size(max_upload_bytes())) ?></strong> par fichier (modifiable dans <code>.user.ini</code> ou cPanel › Sélecteur PHP).</p>
    <?php else: ?>
      <p class="muted">Seul l’administrateur peut modifier les paramètres globaux. Quota par défaut : <strong><?= e(human_size(default_quota())) ?></strong>.</p>
    <?php endif; ?>
  </section>
</div>

<section class="card" id="members">
  <h2>👥 Membres</h2>
  <div class="table-wrap">
  <table class="users">
    <thead><tr><th>Membre</th><th>Rôle</th><th>Stockage</th><th>Quota</th><th>Actions</th></tr></thead>
    <tbody>
    <?php foreach ($users as $u): $manage = can_manage($me, $u); $self = $u['id'] == $me['id']; ?>
      <tr class="<?= $u['active'] ? '' : 'inactive' ?>">
        <td>
          <strong><?= e($u['display_name']) ?></strong> <?= $self ? '<span class="tag">vous</span>' : '' ?><?= $u['active'] ? '' : '<span class="tag danger">suspendu</span>' ?><br>
          <small class="muted mono">@<?= e($u['username']) ?> · <?= e($u['email'] ?: 'sans e-mail') ?></small><br>
          <small class="muted">Inscrit le <?= e(fmt_dt($u['created_at'], 'd/m/Y')) ?><?= $u['last_login'] ? ' · vu le ' . e(fmt_dt($u['last_login'])) : '' ?></small>
        </td>
        <td>
          <?php if (is_admin($me) && !$self): ?>
            <form method="post" class="inline"><?= csrf_field() ?><input type="hidden" name="do" value="role"><input type="hidden" name="id" value="<?= (int)$u['id'] ?>"><input type="hidden" name="tab" value="members">
              <select name="role" onchange="this.form.submit()">
                <?php foreach ($roles as $k => $v): ?><option value="<?= $k ?>" <?= $u['role'] === $k ? 'selected' : '' ?>><?= $v ?></option><?php endforeach; ?>
              </select></form>
          <?php else: ?><span class="role <?= e($u['role']) ?>"><?= e($roles[$u['role']]) ?></span><?php endif; ?>
        </td>
        <td style="min-width:170px"><?= quota_bar($u['used'], $u['quota']) ?></td>
        <td>
          <?php if ($manage): ?>
          <form method="post" class="inline quota-form"><?= csrf_field() ?><input type="hidden" name="do" value="quota"><input type="hidden" name="id" value="<?= (int)$u['id'] ?>"><input type="hidden" name="tab" value="members">
            <input name="quota" size="6" value="<?= $u['quota_bytes'] !== null ? e(round($u['quota_bytes'] / 1048576)) : '' ?>" placeholder="défaut" title="Mo, ou 500M / 2G. Vide = quota par défaut">
            <button class="btn sm">OK</button>
          </form>
          <small class="muted"><?= $u['quota_bytes'] === null ? 'par défaut' : 'personnalisé' ?></small>
          <?php else: ?><?= e(human_size($u['quota'])) ?><?php endif; ?>
        </td>
        <td class="acts">
          <?php if ($manage || $self): ?>
            <a class="btn sm" href="<?= e(url('files.php' . ($self ? '' : '?as=' . (int)$u['id']))) ?>">📁 Fichiers</a>
          <?php endif; ?>
          <?php if ($manage && !$self): ?>
            <form method="post" class="inline"><?= csrf_field() ?><input type="hidden" name="do" value="toggle"><input type="hidden" name="id" value="<?= (int)$u['id'] ?>"><input type="hidden" name="tab" value="members">
              <button class="btn sm"><?= $u['active'] ? '⏸ Suspendre' : '▶ Réactiver' ?></button></form>
            <details class="menu"><summary class="btn sm">⋯</summary><div class="menu-forms">
              <form method="post"><?= csrf_field() ?><input type="hidden" name="do" value="password"><input type="hidden" name="id" value="<?= (int)$u['id'] ?>"><input type="hidden" name="tab" value="members">
                <label>Nouveau mot de passe<input name="password" type="text" minlength="8" required></label><button class="btn sm">Réinitialiser</button></form>
              <form method="post"><?= csrf_field() ?><input type="hidden" name="do" value="delete"><input type="hidden" name="id" value="<?= (int)$u['id'] ?>"><input type="hidden" name="tab" value="members">
                <label>Tapez « <?= e($u['username']) ?> » pour supprimer le compte et tous ses fichiers<input name="confirm" required></label><button class="btn sm danger">Supprimer</button></form>
            </div></details>
          <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>
</section>

<div class="grid2">
  <section class="card" id="create">
    <h2>➕ Créer un compte manuellement</h2>
    <form method="post" class="form">
      <?= csrf_field() ?><input type="hidden" name="do" value="create"><input type="hidden" name="tab" value="create">
      <label>Identifiant (nom du dossier)<input name="username" required></label>
      <label>Nom affiché<input name="display_name"></label>
      <label>E-mail<input type="email" name="email"></label>
      <label>Mot de passe<input name="password" type="text" required minlength="8"></label>
      <?php if (is_admin($me)): ?>
      <label>Rôle<select name="role"><?php foreach ($roles as $k => $v): ?><option value="<?= $k ?>" <?= $k === 'user' ? 'selected' : '' ?>><?= $v ?></option><?php endforeach; ?></select></label>
      <?php endif; ?>
      <button class="btn primary">Créer</button>
    </form>
  </section>

  <section class="card" id="logs">
    <h2>📜 Activité récente</h2>
    <div class="logs">
    <?php foreach ($logs as $l): ?>
      <div><small class="muted"><?= e(fmt_dt($l['created_at'], 'd/m H:i')) ?></small>
        <strong><?= e($l['username'] ?? '—') ?></strong> <span class="tag"><?= e($l['action']) ?></span> <?= e($l['detail']) ?></div>
    <?php endforeach; ?>
    <?php if (!$logs): ?><p class="muted">Aucune activité.</p><?php endif; ?>
    </div>
  </section>
</div>
<?php page_footer();

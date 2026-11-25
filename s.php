<?php
require __DIR__ . '/inc/bootstrap.php';

$token = (string)($_GET['t'] ?? '');
$st = db()->prepare('SELECT s.*, u.folder, u.display_name, u.active FROM shares s JOIN users u ON u.id = s.user_id WHERE s.token = ?');
$st->execute([$token]);
$share = $st->fetch();

$dl  = !empty($_GET['dl']);
$raw = !empty($_GET['raw']);
$zip = !empty($_GET['zip']);

function share_404(): void {
    http_response_code(404);
    if (!empty($_GET['raw']) || !empty($_GET['dl']) || on_content_host()) {
        header('Content-Type: text/plain; charset=utf-8');
        header('Access-Control-Allow-Origin: *');
        exit('404 - Fichier introuvable ou lien désactivé.');
    }
    page_header('Lien introuvable');
    echo '<div class="card narrow center"><h1>Lien introuvable</h1><p class="muted">Ce lien n’existe pas ou a été désactivé par son propriétaire.</p></div>';
    page_footer();
    exit;
}
if (!$share || !$share['active']) share_404();

function share_error(int $code, string $title, string $msg): void {
    http_response_code($code);
    if (!empty($_GET['raw']) || !empty($_GET['dl']) || !empty($_GET['zip'])) {
        header('Content-Type: text/plain; charset=utf-8');
        header('Access-Control-Allow-Origin: *');
        exit($code . ' - ' . $msg);
    }
    page_header($title);
    echo '<div class="card narrow center"><h1>' . e($title) . '</h1><p class="muted">' . e($msg) . '</p></div>';
    page_footer();
    exit;
}

// Lien désactivé, expiré ou épuisé
if ($reason = share_block_reason($share)) share_error(410, 'Lien indisponible', $reason);

// Lien protégé par mot de passe
if (!share_unlocked($share)) {
    $pwErr = '';
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (recent_actions('share_pw_fail') >= 10) {
            $pwErr = 'Trop de tentatives. Réessayez dans 15 minutes.';
        } elseif (password_verify((string)($_POST['share_password'] ?? ''), $share['password_hash'])) {
            share_set_unlock_cookie($share);
            header('Location: ' . ($_SERVER['REQUEST_URI'] ?? share_url($token)), true, 303);
            exit;
        } else {
            log_action('share_pw_fail', $token, null);
            $pwErr = 'Mot de passe incorrect.';
        }
    }
    $dest = $_SERVER['HTTP_SEC_FETCH_DEST'] ?? '';
    $wantsPage = $dest === 'document' || ($dest === '' && strpos($_SERVER['HTTP_ACCEPT'] ?? '', 'text/html') !== false);
    if (($dl || $raw || $zip) && !$wantsPage) share_error(401, 'Lien protégé', 'Ce lien est protégé par un mot de passe (ajoutez la clé ?k=… fournie par le propriétaire).');
    http_response_code(401);
    page_header('Lien protégé');
    ?>
    <div class="card narrow center">
      <h1>🔒 Lien protégé</h1>
      <p class="muted"><?= e($share['display_name']) ?> a protégé ce contenu par un mot de passe.</p>
      <?php if ($pwErr): ?><div class="alert error"><?= e($pwErr) ?></div><?php endif; ?>
      <form method="post" class="form">
        <label>Mot de passe<input type="password" name="share_password" required autofocus autocomplete="off"></label>
        <button class="btn primary">Accéder</button>
      </form>
    </div>
    <?php
    page_footer();
    exit;
}

try {
    $root = user_root($share);
    $base = abs_path($root, $share['path']);
    if (!file_exists($base)) share_404();
    $sub = clean_rel($_GET['p'] ?? '');
    if ($sub !== '' && !$share['is_dir']) share_404();
    $abs = $sub === '' ? $base : abs_path($root, ltrim($share['path'] . '/' . $sub, '/'));
    if (!file_exists($abs)) share_404();
} catch (RuntimeException $e) { share_404(); }

$name = $share['path'] === '' ? $share['folder'] : basename($share['path']);

// Lecture directe d'un dossier : sert index.html s'il existe (site web / WebView).
if ($raw && is_dir($abs)) {
    $uri = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH);
    if (setting('pretty_urls', '1') === '1' && substr($uri, -1) !== '/') {
        // Ajoute le "/" final pour que les chemins relatifs (css/js/images) fonctionnent.
        $qs = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_QUERY);
        header('Location: ' . $uri . '/' . ($qs ? '?' . $qs : ''), true, 301);
        exit;
    }
    foreach (['index.html', 'index.htm'] as $idx) {
        if (is_file($abs . '/' . $idx)) { $abs .= '/' . $idx; break; }
    }
    if (is_dir($abs)) share_404();
}

if (is_file($abs) && ($dl || $raw)) {
    db()->prepare('UPDATE shares SET ' . ($dl ? 'downloads = downloads + 1' : 'views = views + 1') . ', last_access = ? WHERE id = ?')
        ->execute([db_time(), $share['id']]);
    send_file($abs, $dl ? 'download' : 'raw');
}
if (is_dir($abs) && $zip) {
    db()->prepare('UPDATE shares SET downloads = downloads + 1, last_access = ? WHERE id = ?')->execute([db_time(), $share['id']]);
    send_zip($abs, $sub === '' ? $name : basename($sub));
}
if ($dl || $raw) share_404();

function sub_link(string $token, string $sub, string $mode = ''): string {
    global $share;
    if ($mode === 'raw') return share_with_key($share, share_url($token, 'view', $sub, basename($sub)));
    if ($mode === 'dl' && $sub === '') return share_with_key($share, share_url($token, 'dl'));
    $q = 's.php?t=' . rawurlencode($token) . ($sub !== '' ? '&p=' . rawurlencode($sub) : '');
    if ($sub === '' && $mode === '') return share_url($token);
    if ($mode !== '') $q .= '&' . $mode . '=1';
    return $mode !== '' ? share_with_key($share, url($q)) : url($q);
}

page_header($name);
?>
<div class="card">
<?php if (is_file($abs)):
    $size = filesize($abs);
    $mime = mime_of($abs);
    $fname = basename($abs);
    $direct = sub_link($token, $sub, 'dl');
    $view = $sub === '' ? share_with_key($share, share_url($token, 'view', '', $fname)) : sub_link($token, $sub, 'raw');
?>
  <div class="share-file">
    <div class="file-icon big" data-ext="<?= e(strtolower(pathinfo($fname, PATHINFO_EXTENSION))) ?>"></div>
    <h1 class="break"><?= e($fname) ?></h1>
    <p class="muted"><?= e(human_size($size)) ?> · partagé par <strong><?= e($share['display_name']) ?></strong> · modifié le <?= date('d/m/Y H:i', filemtime($abs)) ?></p>
    <?php if (preg_match('#^image/(png|jpe?g|gif|webp|avif)#', $mime)): ?>
      <img class="preview" src="<?= e($view) ?>" alt="">
    <?php elseif (strpos($mime, 'video/') === 0): ?>
      <video class="preview" src="<?= e($view) ?>" controls></video>
    <?php endif; ?>
    <div class="actions center">
      <a class="btn primary big" href="<?= e($direct) ?>">⬇ Télécharger</a>
      <?php if (raw_viewable($mime)): ?><a class="btn big" href="<?= e($view) ?>" target="_blank" rel="noopener">👁 Afficher</a><?php endif; ?>
    </div>
    <div class="linkbox">
      <label>Lien de téléchargement direct</label>
      <div class="copyrow"><input readonly value="<?= e($direct) ?>"><button class="btn" data-copy="<?= e($direct) ?>">Copier</button></div>
    </div>
    <?php if (raw_viewable($mime)): ?>
    <div class="linkbox">
      <label>Lien de lecture directe (navigateur, WebView, API)</label>
      <div class="copyrow"><input readonly value="<?= e($view) ?>"><button class="btn" data-copy="<?= e($view) ?>">Copier</button></div>
    </div>
    <?php endif; ?>
  </div>
<?php else:
    $items = list_dir($abs);
    $crumbs = $sub === '' ? [] : explode('/', $sub);
    $hasIndex = is_file($abs . '/index.html') || is_file($abs . '/index.htm');
?>
  <div class="share-head">
    <div>
      <h1 class="break">📁 <?= e($name) ?></h1>
      <p class="muted">Dossier partagé par <strong><?= e($share['display_name']) ?></strong></p>
    </div>
    <div class="toolbar">
      <?php if ($hasIndex): $site = share_with_key($share, share_url($token, 'site', $sub)); ?>
        <a class="btn" href="<?= e($site) ?>" target="_blank" rel="noopener">🌐 Ouvrir le site</a>
      <?php endif; ?>
      <?php if (class_exists('ZipArchive')): ?>
      <a class="btn primary" href="<?= e(sub_link($token, $sub, 'zip')) ?>">⬇ Tout télécharger (ZIP)</a>
      <?php endif; ?>
    </div>
  </div>
  <nav class="crumbs">
    <a href="<?= e(sub_link($token, '')) ?>"><?= e($name) ?></a>
    <?php $acc = ''; foreach ($crumbs as $c): $acc = ltrim($acc . '/' . $c, '/'); ?>
      <span>/</span><a href="<?= e(sub_link($token, $acc)) ?>"><?= e($c) ?></a>
    <?php endforeach; ?>
  </nav>
  <table class="files">
    <thead><tr><th>Nom</th><th class="hide-sm">Taille</th><th class="hide-sm">Modifié</th><th></th></tr></thead>
    <tbody>
    <?php if (!$items): ?><tr><td colspan="4" class="muted center">Dossier vide</td></tr><?php endif; ?>
    <?php foreach ($items as $it): $p = ltrim($sub . '/' . $it['name'], '/'); ?>
      <tr>
        <td class="name"><span class="file-icon" data-ext="<?= $it['dir'] ? 'dir' : e($it['ext']) ?>"></span>
          <a href="<?= e(sub_link($token, $p)) ?>"><?= e($it['name']) ?></a></td>
        <td class="hide-sm"><?= $it['dir'] ? '—' : e(human_size($it['size'])) ?></td>
        <td class="hide-sm"><?= date('d/m/Y H:i', $it['mtime']) ?></td>
        <td class="right acts">
          <?php if (!$it['dir']): $v = sub_link($token, $p, 'raw'); ?>
            <?php if (raw_viewable(mime_of($abs . '/' . $it['name']))): ?><a class="btn sm" href="<?= e($v) ?>" target="_blank" rel="noopener" title="Afficher">👁</a><?php endif; ?>
            <button class="btn sm" data-copy="<?= e($v) ?>" title="Copier le lien de lecture directe">🔗</button>
            <a class="btn sm" href="<?= e(sub_link($token, $p, 'dl')) ?>" title="Télécharger">⬇</a>
          <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
<?php endif; ?>
</div>
<?php page_footer();

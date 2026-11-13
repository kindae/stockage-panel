<?php
require __DIR__ . '/inc/bootstrap.php';

$token = (string)($_GET['t'] ?? '');
$st = db()->prepare('SELECT s.*, u.folder, u.display_name, u.active FROM shares s JOIN users u ON u.id = s.user_id WHERE s.token = ?');
$st->execute([$token]);
$share = $st->fetch();

function share_404(): void {
    http_response_code(404);
    page_header('Lien introuvable');
    echo '<div class="card narrow center"><h1>Lien introuvable</h1><p class="muted">Ce lien n’existe pas ou a été désactivé par son propriétaire.</p></div>';
    page_footer();
    exit;
}
if (!$share || !$share['active']) share_404();

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
$dl = !empty($_GET['dl']);
$raw = !empty($_GET['raw']);

if (is_file($abs) && ($dl || $raw)) {
    db()->prepare('UPDATE shares SET downloads = downloads + 1 WHERE id = ?')->execute([$share['id']]);
    send_file($abs, $dl);
}
if (is_dir($abs) && !empty($_GET['zip'])) {
    db()->prepare('UPDATE shares SET downloads = downloads + 1 WHERE id = ?')->execute([$share['id']]);
    send_zip($abs, $sub === '' ? $name : basename($sub));
}

$pretty = setting('pretty_urls', '1') === '1';
function sub_link(string $token, string $sub, string $mode = ''): string {
    global $pretty;
    if ($pretty && $mode === 'raw' && $sub !== '') {
        return url('s/' . $token . '/f/' . implode('/', array_map('rawurlencode', explode('/', $sub))));
    }
    $q = 's.php?t=' . rawurlencode($token) . ($sub !== '' ? '&p=' . rawurlencode($sub) : '');
    if ($mode !== '') $q .= '&' . $mode . '=1';
    return url($q);
}

page_header($name);
?>
<div class="card">
<?php if (is_file($abs)):
    $size = filesize($abs);
    $mime = mime_of($abs);
    $fname = basename($abs);
    $direct = $sub === '' ? share_url($token, true) : sub_link($token, $sub, 'dl');
?>
  <div class="share-file">
    <div class="file-icon big" data-ext="<?= e(strtolower(pathinfo($fname, PATHINFO_EXTENSION))) ?>"></div>
    <h1 class="break"><?= e($fname) ?></h1>
    <p class="muted"><?= e(human_size($size)) ?> · partagé par <strong><?= e($share['display_name']) ?></strong> · modifié le <?= date('d/m/Y H:i', filemtime($abs)) ?></p>
    <?php if (preg_match('#^image/(png|jpe?g|gif|webp)#', $mime)): ?>
      <img class="preview" src="<?= e(sub_link($token, $sub, 'raw')) ?>" alt="">
    <?php elseif (strpos($mime, 'video/') === 0): ?>
      <video class="preview" src="<?= e(sub_link($token, $sub, 'raw')) ?>" controls></video>
    <?php endif; ?>
    <div class="actions center">
      <a class="btn primary big" href="<?= e($direct) ?>">⬇ Télécharger</a>
    </div>
    <div class="linkbox">
      <label>Lien de téléchargement direct</label>
      <div class="copyrow"><input readonly value="<?= e($direct) ?>"><button class="btn" data-copy="<?= e($direct) ?>">Copier</button></div>
    </div>
  </div>
<?php else:
    $items = list_dir($abs);
    $crumbs = $sub === '' ? [] : explode('/', $sub);
?>
  <div class="share-head">
    <div>
      <h1 class="break">📁 <?= e($name) ?></h1>
      <p class="muted">Dossier partagé par <strong><?= e($share['display_name']) ?></strong></p>
    </div>
    <?php if (class_exists('ZipArchive')): ?>
    <a class="btn primary" href="<?= e(sub_link($token, $sub, 'zip')) ?>">⬇ Tout télécharger (ZIP)</a>
    <?php endif; ?>
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
          <a href="<?= e($it['dir'] ? sub_link($token, $p) : sub_link($token, $p)) ?>"><?= e($it['name']) ?></a></td>
        <td class="hide-sm"><?= $it['dir'] ? '—' : e(human_size($it['size'])) ?></td>
        <td class="hide-sm"><?= date('d/m/Y H:i', $it['mtime']) ?></td>
        <td class="right">
          <?php if (!$it['dir']): ?>
            <button class="btn sm" data-copy="<?= e(sub_link($token, $p, 'raw')) ?>" title="Copier le lien direct">🔗</button>
            <a class="btn sm" href="<?= e(sub_link($token, $p, 'dl')) ?>">⬇</a>
          <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
<?php endif; ?>
</div>
<?php page_footer();

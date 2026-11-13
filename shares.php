<?php
require __DIR__ . '/inc/bootstrap.php';
$me = require_login();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    db()->prepare('DELETE FROM shares WHERE id = ? AND user_id = ?')->execute([(int)($_POST['id'] ?? 0), $me['id']]);
    flash('Lien désactivé.');
    redirect('shares.php');
}
$st = db()->prepare('SELECT * FROM shares WHERE user_id = ? ORDER BY created_at DESC');
$st->execute([$me['id']]);
$shares = $st->fetchAll();
page_header('Mes liens', 'shares');
?>
<h1>Mes liens partagés</h1>
<p class="muted">Tous les liens publics que vous avez créés. Désactivez un lien pour qu’il ne fonctionne plus.</p>
<div class="card">
<?php if (!$shares): ?>
  <p class="muted center">Aucun lien pour l’instant. Dans « Mes fichiers », cliquez sur <strong>🔗 Lien</strong> à côté d’un fichier ou d’un dossier.</p>
<?php else: ?>
  <div class="table-wrap"><table class="files">
    <thead><tr><th>Élément</th><th>Lien</th><th class="hide-sm">Téléchargements</th><th class="hide-sm">Créé le</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($shares as $s):
        $link = share_url($s['token']); $direct = share_url($s['token'], true);
        $exists = file_exists(user_root($me) . '/' . $s['path']); ?>
      <tr>
        <td class="name"><span class="file-icon" data-ext="<?= $s['is_dir'] ? 'dir' : e(strtolower(pathinfo($s['path'], PATHINFO_EXTENSION))) ?>"></span>
          <span class="break">/<?= e($s['path']) ?></span><?= $exists ? '' : ' <span class="tag danger">introuvable</span>' ?></td>
        <td class="acts">
          <button class="btn sm" data-copy="<?= e($link) ?>">Copier le lien</button>
          <?php if (!$s['is_dir']): ?><button class="btn sm" data-copy="<?= e($direct) ?>">Lien direct</button><?php endif; ?>
          <a class="btn sm" href="<?= e($link) ?>" target="_blank" rel="noopener">Ouvrir</a>
        </td>
        <td class="hide-sm"><?= (int)$s['downloads'] ?></td>
        <td class="hide-sm"><?= e(fmt_dt($s['created_at'])) ?></td>
        <td class="right"><form method="post" class="inline"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$s['id'] ?>">
          <button class="btn sm danger" data-confirm="Désactiver ce lien ?">Désactiver</button></form></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
<?php endif; ?>
</div>
<?php page_footer();

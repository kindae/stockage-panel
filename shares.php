<?php
require __DIR__ . '/inc/bootstrap.php';
$me = require_login();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $id = (int)($_POST['id'] ?? 0);
    if (($_POST['do'] ?? '') === 'toggle') {
        db()->prepare('UPDATE shares SET disabled = 1 - disabled WHERE id = ? AND user_id = ?')->execute([$id, $me['id']]);
        flash('État du lien modifié.');
    } else {
        db()->prepare('DELETE FROM shares WHERE id = ? AND user_id = ?')->execute([$id, $me['id']]);
        flash('Lien supprimé.');
    }
    redirect('shares.php');
}
$st = db()->prepare('SELECT * FROM shares WHERE user_id = ? ORDER BY created_at DESC');
$st->execute([$me['id']]);
$shares = $st->fetchAll();
page_header('Mes liens', 'shares');
?>
<h1>Mes liens partagés</h1>
<p class="muted">Tous les liens publics que vous avez créés. Pour régler l’expiration, le mot de passe ou la limite de téléchargements, ouvrez <strong>🔗 Lien</strong> depuis « Mes fichiers ».</p>
<div class="card">
<?php if (!$shares): ?>
  <p class="muted center">Aucun lien pour l’instant. Dans « Mes fichiers », cliquez sur <strong>🔗 Lien</strong> à côté d’un fichier ou d’un dossier.</p>
<?php else: ?>
  <div class="table-wrap"><table class="files">
    <thead><tr><th>Élément</th><th>Liens</th><th class="hide-sm">Activité</th><th class="hide-sm">Créé le</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($shares as $s):
        $abs = user_root($me) . '/' . $s['path'];
        $exists = file_exists($abs);
        $link = share_url($s['token']);
        $direct = share_with_key($s, share_url($s['token'], 'dl'));
        $view = share_with_key($s, $s['is_dir'] ? share_url($s['token'], 'site') : share_url($s['token'], 'view', '', basename($s['path']))); ?>
      <tr class="<?= $s['disabled'] ? 'inactive' : '' ?>">
        <td class="name"><span class="file-icon" data-ext="<?= $s['is_dir'] ? 'dir' : e(strtolower(pathinfo($s['path'], PATHINFO_EXTENSION))) ?>"></span>
          <span class="break">/<?= e($s['path']) ?></span><?= $exists ? '' : ' <span class="tag danger">introuvable</span>' ?>
          <?php if ($s['note']): ?><br><small class="muted"><?= e($s['note']) ?></small><?php endif; ?>
          <div class="status-line"><?php foreach (share_status_badges($s) as [$c, $l]): ?><span class="tag <?= e($c) ?>"><?= e($l) ?></span><?php endforeach; ?></div>
        </td>
        <td class="acts">
          <button class="btn sm" data-copy="<?= e($link) ?>">Copier le lien</button>
          <?php if (!$s['is_dir']): ?><button class="btn sm" data-copy="<?= e($direct) ?>">Lien direct</button><?php endif; ?>
          <button class="btn sm" data-copy="<?= e($view) ?>" title="<?= $s['is_dir'] ? 'Adresse du site / de base : ajoutez le chemin du fichier à la fin' : 'Affichage dans le navigateur / WebView' ?>"><?= $s['is_dir'] ? 'Lien web' : 'Lien lecture' ?></button>
          <a class="btn sm" href="<?= e($link) ?>" target="_blank" rel="noopener">Ouvrir</a>
        </td>
        <td class="hide-sm"><?= (int)$s['downloads'] ?> ⬇ · <?= (int)$s['views'] ?> 👁<?= $s['last_access'] ? '<br><small class="muted">dernier accès ' . e(fmt_dt($s['last_access'])) . '</small>' : '' ?></td>
        <td class="hide-sm"><?= e(fmt_dt($s['created_at'])) ?></td>
        <td class="right"><form method="post" class="inline"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$s['id'] ?>">
          <button class="btn sm" name="do" value="toggle"><?= $s['disabled'] ? '▶ Réactiver' : '⏸ Désactiver' ?></button>
          <button class="btn sm danger" name="do" value="delete" data-confirm="Supprimer définitivement ce lien ?">🗑</button></form></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
<?php endif; ?>
</div>
<?php page_footer();

<?php
require __DIR__ . '/inc/bootstrap.php';
$me = require_login();
$owner = $me;
if (!empty($_GET['as']) && (int)$_GET['as'] !== (int)$me['id']) {
    if (!is_staff($me)) redirect('trash.php');
    $st = db()->prepare('SELECT * FROM users WHERE id = ?');
    $st->execute([(int)$_GET['as']]);
    $owner = $st->fetch();
    if (!$owner || ($owner['role'] === 'admin' && !is_admin($me))) redirect('admin.php');
}
$asOther = $owner['id'] !== $me['id'];
$self = 'trash.php' . ($asOther ? '?as=' . (int)$owner['id'] : '');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $ids = array_map('intval', (array)($_POST['ids'] ?? []));
    $do = $_POST['do'] ?? '';
    if (!empty($_POST['restore_one'])) { $do = 'restore'; $ids = [(int)$_POST['restore_one']]; }
    try {
        if (in_array($do, ['restore', 'purge'], true) && !$ids) throw new RuntimeException('Sélectionnez au moins un élément.');
        switch ($do) {
        case 'restore':
            $done = [];
            foreach ($ids as $id) $done[] = trash_restore($owner, $id);
            foreach ($done as $p) log_action('restore', $owner['folder'] . '/' . $p);
            flash(count($done) . ' élément(s) restauré(s)' . (count($done) === 1 ? ' : /' . $done[0] : '') . '.');
            break;
        case 'purge':
            $n = 0;
            foreach ($ids as $id) if ($it = trash_item($owner, $id)) { trash_purge_item($owner, $it); $n++; }
            log_action('purge', $owner['folder'] . ' : ' . $n . ' élément(s)');
            flash($n . ' élément(s) supprimé(s) définitivement.');
            break;
        case 'empty':
            $st = db()->prepare('SELECT * FROM trash WHERE user_id = ?');
            $st->execute([$owner['id']]);
            $n = 0;
            foreach ($st->fetchAll() as $it) { trash_purge_item($owner, $it); $n++; }
            log_action('empty_trash', $owner['folder'] . ' : ' . $n . ' élément(s)');
            flash('Corbeille vidée (' . $n . ' élément(s)).');
            break;
        }
    } catch (RuntimeException $e) { flash($e->getMessage(), 'error'); }
    redirect($self);
}

$st = db()->prepare('SELECT t.*, u.username AS deleted_by_name FROM trash t LEFT JOIN users u ON u.id = t.deleted_by WHERE t.user_id = ? ORDER BY t.deleted_at DESC');
$st->execute([$owner['id']]);
$items = $st->fetchAll();
$total = array_sum(array_column($items, 'size'));
$days = max(1, (int)setting('trash_days', '30'));

page_header('Corbeille', $asOther ? 'admin' : 'files');
?>
<?php if ($asOther): ?>
  <div class="alert warn">Mode modération : corbeille de <strong><?= e($owner['display_name']) ?></strong>. <a href="<?= e(url('files.php?as=' . (int)$owner['id'])) ?>">Retour à ses fichiers</a></div>
<?php endif; ?>
<div class="fm-head">
  <div>
    <h1>🗑 Corbeille</h1>
    <p class="muted"><?= count($items) ?> élément(s) · <?= e(human_size($total)) ?><?= setting('trash_counts_quota', '1') === '1' ? ' (compté dans le quota)' : '' ?>.
      Les éléments sont supprimés définitivement après <strong><?= $days ?> jours</strong>.</p>
  </div>
  <div class="toolbar">
    <a class="btn" href="<?= e(url('files.php' . ($asOther ? '?as=' . (int)$owner['id'] : ''))) ?>">← Retour aux fichiers</a>
    <?php if ($items): ?>
    <form method="post" class="inline"><?= csrf_field() ?><input type="hidden" name="do" value="empty">
      <button class="btn danger" data-confirm="Vider la corbeille ? Les <?= count($items) ?> élément(s) seront supprimés définitivement.">Vider la corbeille</button></form>
    <?php endif; ?>
  </div>
</div>
<?php if (!trash_enabled()): ?><div class="alert warn">La corbeille est désactivée par l’administration : les suppressions sont définitives.</div><?php endif; ?>

<form method="post" class="card" id="trashForm">
  <?= csrf_field() ?>
  <?php if (!$items): ?>
    <p class="muted center empty">La corbeille est vide.</p>
  <?php else: ?>
  <div class="selbar">
    <span>Sélection :</span>
    <button class="btn sm" name="do" value="restore">↩ Restaurer</button>
    <button class="btn sm danger" name="do" value="purge" data-confirm="Supprimer définitivement la sélection ?">Supprimer définitivement</button>
  </div>
  <div class="table-wrap"><table class="files">
    <thead><tr><th class="chk"><input type="checkbox" onclick="document.querySelectorAll('#trashForm [name=\'ids[]\']').forEach(c => c.checked = this.checked)" aria-label="Tout sélectionner"></th>
      <th>Nom</th><th class="hide-sm">Emplacement d’origine</th><th class="hide-sm">Taille</th><th>Supprimé le</th><th class="hide-sm">Expire</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($items as $it):
        $left = (int)ceil(($days * 86400 - (time() - strtotime($it['deleted_at'] . ' UTC'))) / 86400); ?>
      <tr>
        <td class="chk"><input type="checkbox" name="ids[]" value="<?= (int)$it['id'] ?>"></td>
        <td class="name"><span class="file-icon" data-ext="<?= $it['is_dir'] ? 'dir' : e(strtolower(pathinfo($it['name'], PATHINFO_EXTENSION))) ?>"></span><?= e($it['name']) ?></td>
        <td class="hide-sm muted break">/<?= e(dirname($it['original_path']) === '.' ? '' : dirname($it['original_path'])) ?></td>
        <td class="hide-sm"><?= e(human_size($it['size'])) ?></td>
        <td><?= e(fmt_dt($it['deleted_at'])) ?><?= $it['deleted_by_name'] && $it['deleted_by'] != $owner['id'] ? '<br><small class="muted">par ' . e($it['deleted_by_name']) . '</small>' : '' ?></td>
        <td class="hide-sm"><?= max(0, $left) ?> j</td>
        <td class="right"><button class="btn sm" name="restore_one" value="<?= (int)$it['id'] ?>" title="Restaurer">↩ Restaurer</button></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
  <?php endif; ?>
</form>
<?php page_footer();

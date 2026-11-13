<?php
require __DIR__ . '/inc/bootstrap.php';
$me = require_login();
$owner = $me;
if (!empty($_GET['as']) && (int)$_GET['as'] !== (int)$me['id']) {
    if (!is_staff($me)) redirect('files.php');
    $st = db()->prepare('SELECT * FROM users WHERE id = ?');
    $st->execute([(int)$_GET['as']]);
    $owner = $st->fetch();
    if (!$owner || ($owner['role'] === 'admin' && !is_admin($me))) redirect('admin.php');
}
$asOther = $owner['id'] !== $me['id'];
page_header('Mes fichiers', $asOther ? 'admin' : 'files');
?>
<div id="fm" data-as="<?= $asOther ? (int)$owner['id'] : '' ?>" data-root="<?= e($owner['folder']) ?>"
     data-api="<?= e(url('api.php')) ?>" data-dl="<?= e(url('download.php')) ?>">
  <?php if ($asOther): ?>
    <div class="alert warn">Mode modération : vous consultez l’espace de <strong><?= e($owner['display_name']) ?></strong> (<?= e($owner['username']) ?>). <a href="<?= e(url('admin.php')) ?>">Retour à l’administration</a></div>
  <?php endif; ?>
  <div class="fm-head">
    <div>
      <h1>Dossier de <?= e($owner['display_name']) ?></h1>
      <div id="quota"></div>
    </div>
    <div class="toolbar">
      <button class="btn" data-act="mkdir">＋ Dossier</button>
      <button class="btn" data-act="newfile">＋ Fichier texte</button>
      <label class="btn">⬆ Fichiers<input type="file" id="upFiles" multiple hidden></label>
      <label class="btn">⬆ Dossier<input type="file" id="upDir" webkitdirectory directory multiple hidden></label>
      <button class="btn" data-act="sharecur" title="Obtenir le lien du dossier courant">🔗 Lien du dossier</button>
    </div>
  </div>

  <nav class="crumbs" id="crumbs"></nav>

  <div class="selbar" id="selbar" hidden>
    <span id="selcount"></span>
    <button class="btn sm" data-act="movesel">Déplacer</button>
    <button class="btn sm danger" data-act="delsel">Supprimer</button>
    <button class="btn sm" data-act="clearsel">Annuler</button>
  </div>

  <div class="dropzone" id="dropzone">
    <table class="files">
      <thead><tr><th class="chk"><input type="checkbox" id="chkall" aria-label="Tout sélectionner"></th><th>Nom</th><th class="hide-sm">Taille</th><th class="hide-sm">Modifié</th><th class="right">Actions</th></tr></thead>
      <tbody id="list"><tr><td colspan="5" class="center muted">Chargement…</td></tr></tbody>
    </table>
    <div class="drophint">Déposez vos fichiers ou dossiers ici</div>
  </div>
  <div id="uploads" class="uploads" hidden></div>
</div>

<dialog id="dlg" class="dlg"><form method="dialog" id="dlgForm"><h2 id="dlgTitle"></h2><div id="dlgBody"></div>
  <div class="dlg-actions" id="dlgActions"></div></form></dialog>

<dialog id="editor" class="dlg wide"><form method="dialog" id="edForm">
  <h2 id="edTitle" class="break"></h2>
  <textarea id="edText" spellcheck="false"></textarea>
  <div class="dlg-actions"><button value="cancel" class="btn">Fermer</button><button id="edSave" value="save" class="btn primary">Enregistrer</button></div>
</form></dialog>
<?php page_footer();

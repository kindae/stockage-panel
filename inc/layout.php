<?php
function page_header(string $title, string $active = ''): void {
    $u = current_user();
    $app = cfg('app_name');
    ?><!doctype html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<meta name="csrf" content="<?= e(csrf_token()) ?>">
<title><?= e($title) ?> · <?= e($app) ?></title>
<link rel="icon" href="<?= e(url('assets/logo.svg')) ?>" type="image/svg+xml">
<link rel="stylesheet" href="<?= e(url('assets/style.css')) ?>?v=1">
</head>
<body>
<header class="topbar">
  <a class="brand" href="<?= e(url($u ? 'files.php' : 'login.php')) ?>">
    <img src="<?= e(url('assets/logo.svg')) ?>" alt="" width="28" height="28">
    <span><?= e($app) ?></span>
  </a>
  <?php if ($u): ?>
  <nav class="nav">
    <a href="<?= e(url('files.php')) ?>" class="<?= $active === 'files' ? 'on' : '' ?>">Mes fichiers</a>
    <a href="<?= e(url('shares.php')) ?>" class="<?= $active === 'shares' ? 'on' : '' ?>">Mes liens</a>
    <?php if (is_staff($u)): ?>
    <a href="<?= e(url('admin.php')) ?>" class="<?= $active === 'admin' ? 'on' : '' ?>">Administration</a>
    <?php endif; ?>
    <a href="<?= e(url('account.php')) ?>" class="<?= $active === 'account' ? 'on' : '' ?>" title="Mon compte"><?= e($u['display_name']) ?></a>
    <form method="post" action="<?= e(url('logout.php')) ?>" class="inline"><?= csrf_field() ?><button class="linklike">Déconnexion</button></form>
  </nav>
  <?php endif; ?>
</header>
<main class="container">
<?php foreach (flash() as [$type, $msg]): ?>
  <div class="alert <?= e($type) ?>"><?= e($msg) ?></div>
<?php endforeach;
}

function page_footer(): void {
    ?></main>
<footer class="foot"><?= e(cfg('app_name')) ?> · espace de stockage pour créateurs d’applications</footer>
<div id="toast" class="toast" hidden></div>
<script src="<?= e(url('assets/app.js')) ?>?v=1"></script>
</body>
</html><?php
}

function quota_bar(int $used, int $quota): string {
    $pct = $quota > 0 ? min(100, round($used / $quota * 100, 1)) : 100;
    $cls = $pct >= 90 ? 'danger' : ($pct >= 70 ? 'warn' : '');
    return '<div class="quota"><div class="bar ' . $cls . '"><span style="width:' . $pct . '%"></span></div>'
        . '<small>' . e(human_size($used)) . ' utilisés sur ' . e(human_size($quota)) . ' (' . $pct . ' %)</small></div>';
}

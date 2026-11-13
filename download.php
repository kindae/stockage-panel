<?php
require __DIR__ . '/inc/bootstrap.php';
$me = require_login();
$owner = $me;
if (!empty($_GET['as']) && (int)$_GET['as'] !== (int)$me['id']) {
    if (!is_staff($me)) { http_response_code(403); exit('Accès refusé.'); }
    $st = db()->prepare('SELECT * FROM users WHERE id = ?');
    $st->execute([(int)$_GET['as']]);
    $owner = $st->fetch();
    if (!$owner || ($owner['role'] === 'admin' && !is_admin($me))) { http_response_code(403); exit('Accès refusé.'); }
}
try {
    $root = user_root($owner);
    $rel = clean_rel($_GET['path'] ?? '');
    $abs = abs_path($root, $rel);
} catch (RuntimeException $e) { http_response_code(400); exit($e->getMessage()); }

if (is_dir($abs)) send_zip($abs, $rel === '' ? $owner['folder'] : basename($rel));
send_file($abs, !empty($_GET['dl']));

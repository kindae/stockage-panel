<?php
declare(strict_types=1);

define('APP_ROOT', dirname(__DIR__));

$defaults = [
    'app_name'      => 'Yabiso Cloud Stockage',
    'base_url'      => '',
    'data_dir'      => APP_ROOT . '/data',
    'storage_dir'   => APP_ROOT . '/storage',
    'max_edit_size' => 2 * 1024 * 1024,
    'timezone'      => 'Africa/Kinshasa',
];
$custom = is_file(APP_ROOT . '/config.php') ? (require APP_ROOT . '/config.php') : [];
$GLOBALS['CFG'] = array_merge($defaults, is_array($custom) ? $custom : []);

function cfg(string $k) { return $GLOBALS['CFG'][$k] ?? null; }
@date_default_timezone_set((string)cfg('timezone'));

foreach (['data_dir', 'storage_dir'] as $d) {
    $p = cfg($d);
    if (!is_dir($p)) { @mkdir($p, 0750, true); }
    if (!is_file($p . '/.htaccess')) {
        @file_put_contents($p . '/.htaccess', "<IfModule mod_authz_core.c>\n  Require all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\n  Order allow,deny\n  Deny from all\n</IfModule>\n");
    }
    if (!is_file($p . '/index.html')) { @file_put_contents($p . '/index.html', ''); }
}

ini_set('session.cookie_httponly', '1');
ini_set('session.use_strict_mode', '1');
if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') { ini_set('session.cookie_secure', '1'); }
session_name('YABISOSESS');
session_set_cookie_params(['samesite' => 'Lax', 'path' => '/']);
session_start();

require __DIR__ . '/db.php';
require __DIR__ . '/helpers.php';
require __DIR__ . '/auth.php';
require __DIR__ . '/fs.php';
require __DIR__ . '/layout.php';

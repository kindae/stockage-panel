<?php
/* Règles d'accès aux liens de partage : désactivation, expiration, limite, mot de passe. */

/** Raison du blocage d'un lien, ou null s'il est utilisable. */
function share_block_reason(array $s): ?string {
    if (!empty($s['disabled'])) return 'Ce lien a été désactivé par son propriétaire.';
    if (!empty($s['expires_at']) && is_past($s['expires_at'])) return 'Ce lien a expiré.';
    if ($s['max_downloads'] !== null && $s['max_downloads'] !== '' && (int)$s['downloads'] >= (int)$s['max_downloads']) {
        return 'Ce lien a atteint son nombre maximum de téléchargements.';
    }
    return null;
}

/** Clé d'accès d'un lien protégé (change si le mot de passe change). */
function share_key(array $s): string {
    return substr(hash_hmac('sha256', $s['token'] . '|' . $s['password_hash'], app_secret()), 0, 24);
}

function share_unlocked(array $s): bool {
    if (empty($s['password_hash'])) return true;
    $k = share_key($s);
    if (isset($_GET['k']) && is_string($_GET['k']) && hash_equals($k, $_GET['k'])) return true;
    $c = $_COOKIE['ysk_' . $s['token']] ?? '';
    return is_string($c) && hash_equals($k, $c);
}

function share_set_unlock_cookie(array $s): void {
    setcookie('ysk_' . $s['token'], share_key($s), [
        'expires' => time() + 86400 * 7, 'path' => '/', 'httponly' => true, 'samesite' => 'Lax',
        'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
    ]);
}

/** Ajoute la clé d'accès à une URL si le lien est protégé. */
function share_with_key(array $s, string $url): string {
    if (empty($s['password_hash'])) return $url;
    return $url . (strpos($url, '?') === false ? '?' : '&') . 'k=' . share_key($s);
}

/** Description courte de l'état d'un lien (pour les listes). */
function share_status_badges(array $s): array {
    $b = [];
    if (!empty($s['disabled'])) $b[] = ['danger', 'désactivé'];
    if (!empty($s['expires_at'])) $b[] = is_past($s['expires_at']) ? ['danger', 'expiré'] : ['', 'expire le ' . fmt_dt($s['expires_at'])];
    if (!empty($s['password_hash'])) $b[] = ['', '🔒 mot de passe'];
    if ($s['max_downloads'] !== null && $s['max_downloads'] !== '') {
        $b[] = [(int)$s['downloads'] >= (int)$s['max_downloads'] ? 'danger' : '', (int)$s['downloads'] . '/' . (int)$s['max_downloads'] . ' téléchargements'];
    }
    return $b;
}

/** Données d'un lien renvoyées au gestionnaire de fichiers. */
function share_payload(array $owner, array $s, string $abs): array {
    $isDir = is_dir($abs);
    $name = $s['path'] === '' ? $owner['folder'] : basename($s['path']);
    $hasIndex = $isDir && (is_file($abs . '/index.html') || is_file($abs . '/index.htm'));
    return [
        'ok' => true, 'token' => $s['token'], 'is_dir' => $isDir, 'name' => $name,
        'url' => share_url($s['token']),
        'direct' => $isDir ? null : share_with_key($s, share_url($s['token'], 'dl')),
        'view' => $isDir ? null : (raw_viewable(mime_of($abs)) ? share_with_key($s, share_url($s['token'], 'view', '', $name)) : null),
        'site' => $hasIndex ? share_with_key($s, share_url($s['token'], 'site')) : null,
        'files_base' => $isDir ? share_url($s['token'], 'site') : null,
        'key' => empty($s['password_hash']) ? null : share_key($s),
        'sandboxed' => content_host() === '',
        'options' => [
            'note' => (string)$s['note'],
            'expires_at' => $s['expires_at'] ? date('Y-m-d\TH:i', strtotime($s['expires_at'] . ' UTC')) : '',
            'has_password' => !empty($s['password_hash']),
            'max_downloads' => $s['max_downloads'] === null ? '' : (int)$s['max_downloads'],
            'downloads' => (int)$s['downloads'],
            'views' => (int)$s['views'],
            'disabled' => (bool)$s['disabled'],
        ],
        'blocked' => share_block_reason($s),
    ];
}

function share_find(array $owner, string $rel): ?array {
    $st = db()->prepare('SELECT * FROM shares WHERE user_id = ? AND path = ? ORDER BY id LIMIT 1');
    $st->execute([$owner['id'], $rel]);
    return $st->fetch() ?: null;
}

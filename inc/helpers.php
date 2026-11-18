<?php
function e($s): string { return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }

function base_url(): string {
    $b = rtrim((string)cfg('base_url'), '/');
    if ($b !== '') return $b;
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $dir = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/');
    return ($https ? 'https' : 'http') . '://' . $host . $dir;
}

function url(string $path = ''): string { return base_url() . '/' . ltrim($path, '/'); }

function redirect(string $path): void { header('Location: ' . url($path)); exit; }

function csrf_token(): string {
    if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(32));
    return $_SESSION['csrf'];
}
function csrf_field(): string { return '<input type="hidden" name="csrf" value="' . e(csrf_token()) . '">'; }
function check_csrf(): void {
    $t = $_POST['csrf'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
    if (!is_string($t) || !hash_equals(csrf_token(), $t)) {
        http_response_code(400);
        if (is_api()) json_out(['ok' => false, 'error' => 'Jeton de sécurité invalide. Rechargez la page.'], 400);
        exit('Jeton de sécurité invalide. Rechargez la page.');
    }
}
function is_api(): bool { return basename($_SERVER['SCRIPT_NAME'] ?? '') === 'api.php'; }

function json_out(array $data, int $code = 200): void {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function flash(?string $msg = null, string $type = 'ok') {
    if ($msg !== null) { $_SESSION['flash'][] = [$type, $msg]; return null; }
    $f = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);
    return $f;
}

function human_size($bytes): string {
    $bytes = (float)$bytes;
    $u = ['o', 'Ko', 'Mo', 'Go', 'To'];
    $i = 0;
    while ($bytes >= 1024 && $i < count($u) - 1) { $bytes /= 1024; $i++; }
    return ($i === 0 ? (string)(int)$bytes : number_format($bytes, $bytes < 10 ? 2 : 1, ',', ' ')) . ' ' . $u[$i];
}

function random_code(int $len = 8): string {
    $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    $s = '';
    for ($i = 0; $i < $len; $i++) $s .= $alphabet[random_int(0, strlen($alphabet) - 1)];
    return substr($s, 0, 4) . '-' . substr($s, 4);
}

function random_token(int $bytes = 12): string {
    return rtrim(strtr(base64_encode(random_bytes($bytes)), '+/', '-_'), '=');
}

/** Lit une taille "200", "200M", "1.5G" -> octets (MB par défaut). */
function parse_size_mb(string $v): ?int {
    $v = trim(str_replace(',', '.', $v));
    if ($v === '') return null;
    if (!preg_match('/^(\d+(?:\.\d+)?)\s*([kmgt]?)[ob]?$/i', $v, $m)) return null;
    $n = (float)$m[1];
    $mult = ['' => 1024 ** 2, 'k' => 1024, 'm' => 1024 ** 2, 'g' => 1024 ** 3, 't' => 1024 ** 4][strtolower($m[2])];
    return (int)round($n * $mult);
}

/**
 * Lien de partage.
 * $mode : ''     = page de téléchargement
 *         'dl'   = téléchargement direct
 *         'view' = lecture directe (JSON, HTML, images… pour navigateur / WebView)
 *         'site' = dossier partagé ouvert comme site web (index.html)
 * $sub  : chemin d'un fichier dans un dossier partagé (mode 'view' / 'dl').
 */
function share_url(string $token, string $mode = '', string $sub = '', string $name = ''): string {
    $pretty = setting('pretty_urls', '1') === '1';
    $enc = fn($p) => implode('/', array_map('rawurlencode', explode('/', $p)));
    if ($mode === 'site') {
        if ($pretty) return content_url('s/' . $token . '/f/' . ($sub !== '' ? $enc($sub) . '/' : ''));
        return content_url('s.php?t=' . rawurlencode($token) . ($sub !== '' ? '&p=' . rawurlencode($sub) : '') . '&raw=1');
    }
    if ($mode === 'view') {
        if ($pretty) return content_url('s/' . $token . ($sub !== '' ? '/f/' . $enc($sub) : '/v/' . ($name !== '' ? rawurlencode($name) : '')));
        return content_url('s.php?t=' . rawurlencode($token) . ($sub !== '' ? '&p=' . rawurlencode($sub) : '') . '&raw=1');
    }
    if ($pretty && $sub === '') return url('s/' . $token . ($mode === 'dl' ? '/dl' : ''));
    return url('s.php?t=' . rawurlencode($token) . ($sub !== '' ? '&p=' . rawurlencode($sub) : '') . ($mode === 'dl' ? '&dl=1' : ''));
}

/** Domaine séparé (facultatif) pour servir les contenus en lecture directe, ex. files.yabiso.cloud */
function content_host(): string {
    $h = strtolower(trim((string)setting('content_host', '')));
    return preg_replace('#^https?://|/.*$#', '', $h);
}
function on_content_host(): bool {
    $h = content_host();
    return $h !== '' && strtolower(preg_replace('/:\d+$/', '', $_SERVER['HTTP_HOST'] ?? '')) === preg_replace('/:\d+$/', '', $h);
}
function content_url(string $path): string {
    $h = content_host();
    if ($h === '') return url($path);
    $dir = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/');
    return 'https://' . $h . $dir . '/' . ltrim($path, '/');
}

function ini_bytes(string $v): int {
    $v = trim($v); if ($v === '') return 0;
    $n = (int)$v; $u = strtolower(substr($v, -1));
    return $n * (['g' => 1024 ** 3, 'm' => 1024 ** 2, 'k' => 1024][$u] ?? 1);
}
function max_upload_bytes(): int {
    $a = ini_bytes((string)ini_get('upload_max_filesize'));
    $b = ini_bytes((string)ini_get('post_max_size'));
    return $b > 0 ? min($a, $b) : $a;
}

/** Les dates SQLite sont en UTC : conversion vers le fuseau configuré. */
function fmt_dt(?string $utc, string $fmt = 'd/m/Y H:i'): string {
    if (!$utc) return '';
    $t = strtotime($utc . ' UTC');
    return $t ? date($fmt, $t) : '';
}

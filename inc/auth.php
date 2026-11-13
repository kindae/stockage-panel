<?php
function current_user(): ?array {
    static $cache = false;
    if ($cache !== false) return $cache;
    $cache = null;
    if (!empty($_SESSION['uid'])) {
        $st = db()->prepare('SELECT * FROM users WHERE id = ? AND active = 1');
        $st->execute([$_SESSION['uid']]);
        $cache = $st->fetch() ?: null;
        if (!$cache) unset($_SESSION['uid']);
    }
    return $cache;
}

function require_login(): array {
    $u = current_user();
    if (!$u) {
        if (is_api()) json_out(['ok' => false, 'error' => 'Session expirée. Reconnectez-vous.'], 401);
        redirect('login.php');
    }
    return $u;
}

function is_staff(?array $u = null): bool { $u = $u ?? current_user(); return $u && in_array($u['role'], ['admin', 'moderator'], true); }
function is_admin(?array $u = null): bool { $u = $u ?? current_user(); return $u && $u['role'] === 'admin'; }

function require_staff(): array {
    $u = require_login();
    if (!is_staff($u)) { http_response_code(403); exit('Accès réservé à l’administration.'); }
    return $u;
}

function default_quota(): int { return (int)setting('default_quota', (string)(200 * 1024 * 1024)); }
function user_quota(array $u): int { return $u['quota_bytes'] !== null ? (int)$u['quota_bytes'] : default_quota(); }

function registration_code(): string {
    $c = setting('registration_code');
    if (!$c) { $c = random_code(); set_setting('registration_code', $c); }
    return $c;
}

function needs_setup(): bool {
    return (int)db()->query("SELECT COUNT(*) FROM users WHERE role = 'admin'")->fetchColumn() === 0;
}

function valid_username(string $u): bool { return (bool)preg_match('/^[a-zA-Z0-9][a-zA-Z0-9_.-]{2,31}$/', $u); }

function create_user(string $username, string $display, string $email, string $password, string $role = 'user'): int {
    $folder = strtolower($username);
    $st = db()->prepare('INSERT INTO users(username, display_name, email, password_hash, role, folder) VALUES(?,?,?,?,?,?)');
    $st->execute([$username, $display, $email, password_hash($password, PASSWORD_DEFAULT), $role, $folder]);
    $id = (int)db()->lastInsertId();
    $dir = cfg('storage_dir') . '/' . $folder;
    if (!is_dir($dir)) mkdir($dir, 0750, true);
    return $id;
}

function login_throttled(): bool {
    $ip = $_SERVER['REMOTE_ADDR'] ?? '';
    $st = db()->prepare("SELECT COUNT(*) FROM logs WHERE action = 'login_fail' AND ip = ? AND created_at > datetime('now', '-15 minutes')");
    $st->execute([$ip]);
    return (int)$st->fetchColumn() >= 10;
}

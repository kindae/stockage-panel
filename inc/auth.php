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

function create_user(string $username, string $display, string $email, string $password, string $role = 'user', ?int $quota = null, ?int $inviteId = null): int {
    $folder = strtolower($username);
    $st = db()->prepare('INSERT INTO users(username, display_name, email, password_hash, role, folder, quota_bytes, invite_code_id) VALUES(?,?,?,?,?,?,?,?)');
    $st->execute([$username, $display, $email !== '' ? $email : null, password_hash($password, PASSWORD_DEFAULT), $role, $folder, $quota, $inviteId]);
    $id = (int)db()->lastInsertId();
    $dir = cfg('storage_dir') . '/' . $folder;
    if (!is_dir($dir)) mkdir($dir, 0750, true);
    return $id;
}

function login_throttled(): bool { return recent_actions('login_fail') >= 10; }

/* ===================== Codes d'invitation ===================== */

function normalize_code(string $c): string { return strtoupper(preg_replace('/[\s-]+/', '', trim($c))); }

/** Code d'invitation correspondant (actif ou non), ou null. */
function find_invite(string $code): ?array {
    $n = normalize_code($code);
    if ($n === '') return null;
    foreach (db()->query('SELECT * FROM invite_codes')->fetchAll() as $row) {
        if (hash_equals(normalize_code($row['code']), $n)) return $row;
    }
    return null;
}

/** Raison pour laquelle un code ne peut pas être utilisé, ou null. */
function invite_problem(array $c): ?string {
    if (!$c['active']) return 'Ce code d’invitation a été désactivé.';
    if (!empty($c['expires_at']) && is_past($c['expires_at'])) return 'Ce code d’invitation a expiré.';
    if ($c['max_uses'] !== null && (int)$c['uses'] >= (int)$c['max_uses']) return 'Ce code d’invitation a déjà été utilisé le nombre de fois prévu.';
    return null;
}

/** Réserve une utilisation du code (atomique). */
function invite_consume(int $id): bool {
    $st = db()->prepare('UPDATE invite_codes SET uses = uses + 1 WHERE id = ? AND active = 1 AND (max_uses IS NULL OR uses < max_uses)');
    $st->execute([$id]);
    return $st->rowCount() === 1;
}

function invite_status(array $c): array {
    $p = invite_problem($c);
    if ($p === null) return ['ok', 'actif'];
    if (!$c['active']) return ['', 'désactivé'];
    if (!empty($c['expires_at']) && is_past($c['expires_at'])) return ['danger', 'expiré'];
    return ['danger', 'épuisé'];
}

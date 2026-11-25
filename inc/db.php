<?php
require_once __DIR__ . '/schema.php';

/** Moteur utilisé : 'sqlite' (par défaut) ou 'mysql' (MySQL / MariaDB). */
function db_driver(): string {
    $c = cfg('db');
    return (is_array($c) && ($c['driver'] ?? '') === 'mysql') ? 'mysql' : 'sqlite';
}

function db(): PDO {
    static $pdo = null;
    if ($pdo) return $pdo;
    $c = (array)cfg('db');
    $opts = [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC];
    try {
        if (db_driver() === 'mysql') {
            $dsn = 'mysql:host=' . ($c['host'] ?? 'localhost') . ';port=' . (int)($c['port'] ?? 3306)
                . ';dbname=' . ($c['name'] ?? '') . ';charset=utf8mb4';
            $pdo = new PDO($dsn, (string)($c['user'] ?? ''), (string)($c['pass'] ?? ''), $opts);
            // Toutes les dates sont stockées en UTC.
            $pdo->exec("SET time_zone = '+00:00'");
            $pdo->exec("SET SESSION sql_mode = 'STRICT_TRANS_TABLES,NO_ENGINE_SUBSTITUTION'");
        } else {
            $pdo = new PDO('sqlite:' . cfg('data_dir') . '/yabiso.sqlite', null, null, $opts);
            $pdo->exec('PRAGMA foreign_keys = ON');
            $pdo->exec('PRAGMA journal_mode = WAL');
            $pdo->exec('PRAGMA busy_timeout = 5000');
        }
    } catch (PDOException $e) {
        http_response_code(500);
        error_log('Yabiso DB: ' . $e->getMessage());
        exit('Connexion à la base de données impossible. Vérifiez les paramètres "db" dans config.php.');
    }
    db_migrate($pdo);
    return $pdo;
}

/** Met la base à jour (tables / colonnes manquantes) quand la version du schéma change. */
function db_migrate(PDO $pdo): void {
    $driver = db_driver();
    $q = schema_q($driver, 'key');
    $current = null;
    try {
        $st = $pdo->prepare("SELECT value FROM settings WHERE $q = 'schema_version'");
        $st->execute();
        $current = $st->fetchColumn();
    } catch (PDOException $e) { /* base vide */ }
    if ((int)$current === SCHEMA_VERSION) return;

    schema_sync($pdo, $driver);
    $pdo->prepare(db_upsert_sql())->execute(['schema_version', (string)SCHEMA_VERSION]);
}

function db_upsert_sql(): string {
    return db_driver() === 'mysql'
        ? 'INSERT INTO settings(`key`, value) VALUES(?, ?) ON DUPLICATE KEY UPDATE value = VALUES(value)'
        : 'INSERT INTO settings("key", value) VALUES(?, ?) ON CONFLICT("key") DO UPDATE SET value = excluded.value';
}

/** Date/heure UTC au format de la base, décalée de $seconds. */
function db_time(int $seconds = 0): string { return gmdate('Y-m-d H:i:s', time() + $seconds); }

function setting(string $key, $default = null) {
    static $cache = null;
    if ($cache === null || isset($GLOBALS['__settings_dirty'])) {
        unset($GLOBALS['__settings_dirty']);
        $cache = [];
        foreach (db()->query('SELECT ' . schema_q(db_driver(), 'key') . ' AS k, value FROM settings')->fetchAll() as $r) {
            $cache[$r['k']] = $r['value'];
        }
    }
    return array_key_exists($key, $cache) ? $cache[$key] : $default;
}

function set_setting(string $key, $value): void {
    db()->prepare(db_upsert_sql())->execute([$key, (string)$value]);
    $GLOBALS['__settings_dirty'] = true;
}

function log_action(string $action, string $detail = '', ?int $uid = null): void {
    $uid = $uid ?? (current_user()['id'] ?? null);
    $st = db()->prepare('INSERT INTO logs(user_id, action, detail, ip) VALUES(?, ?, ?, ?)');
    $st->execute([$uid, $action, mb_substr($detail, 0, 500), $_SERVER['REMOTE_ADDR'] ?? '']);
}

/** Nombre d'actions récentes (anti-abus) pour l'IP courante. */
function recent_actions(string $action, int $seconds = 900): int {
    $st = db()->prepare('SELECT COUNT(*) FROM logs WHERE action = ? AND ip = ? AND created_at > ?');
    $st->execute([$action, $_SERVER['REMOTE_ADDR'] ?? '', db_time(-$seconds)]);
    return (int)$st->fetchColumn();
}

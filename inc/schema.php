<?php
/**
 * Schéma de la base de données (source unique pour SQLite et MySQL/MariaDB).
 *
 * Jetons remplacés selon le moteur :
 *   {ID}   clé primaire auto-incrémentée     {REF}  clé étrangère vers un {ID}
 *   {STR}  texte court (255)                 {PATH} chemin de fichier (700)
 *   {TOK}  jeton / identifiant sensible à la casse
 *   {TXT}  texte long                        {INT}  entier
 *   {BIG}  grand entier (octets)             {DT}   date/heure UTC "YYYY-MM-DD HH:MM:SS"
 *   {NOW}  valeur par défaut = maintenant
 *
 * Toute colonne ajoutée ici est créée automatiquement sur une base existante.
 */
const SCHEMA_VERSION = 2;

function schema_tables(): array {
    return [
        'users' => [
            'cols' => [
                'id'             => '{ID}',
                'username'       => '{STR} NOT NULL',
                'display_name'   => '{STR} NOT NULL',
                'email'          => '{STR} NULL',
                'password_hash'  => '{STR} NOT NULL',
                'role'           => "{STR} NOT NULL DEFAULT 'user'",   // admin | moderator | user
                'quota_bytes'    => '{BIG} NULL',                       // NULL = quota par défaut
                'folder'         => '{STR} NOT NULL',
                'active'         => '{INT} NOT NULL DEFAULT 1',
                'invite_code_id' => '{REF} NULL',
                'created_at'     => '{DT} NOT NULL DEFAULT {NOW}',
                'last_login'     => '{DT} NULL',
            ],
            'unique'  => ['uq_users_username' => ['username'], 'uq_users_folder' => ['folder']],
            'index'   => ['idx_users_email' => ['email']],
        ],
        'settings' => [
            'cols' => [
                'key'   => '{STR} NOT NULL PRIMARY KEY',
                'value' => '{TXT} NULL',
            ],
        ],
        'shares' => [
            'cols' => [
                'id'            => '{ID}',
                'token'         => '{TOK} NOT NULL',
                'user_id'       => '{REF} NOT NULL',
                'path'          => '{PATH} NOT NULL',
                'is_dir'        => '{INT} NOT NULL DEFAULT 0',
                'downloads'     => '{INT} NOT NULL DEFAULT 0',
                'views'         => '{INT} NOT NULL DEFAULT 0',
                'note'          => '{STR} NULL',
                'expires_at'    => '{DT} NULL',
                'password_hash' => '{STR} NULL',
                'max_downloads' => '{INT} NULL',
                'disabled'      => '{INT} NOT NULL DEFAULT 0',
                'last_access'   => '{DT} NULL',
                'created_at'    => '{DT} NOT NULL DEFAULT {NOW}',
            ],
            'unique'  => ['uq_shares_token' => ['token']],
            'index'   => ['idx_shares_user_path' => ['user_id', 'path']],
            'fk'      => ['user_id' => 'users(id) ON DELETE CASCADE'],
        ],
        'invite_codes' => [
            'cols' => [
                'id'          => '{ID}',
                'code'        => '{STR} NOT NULL',
                'note'        => '{STR} NULL',
                'max_uses'    => '{INT} NULL',                 // NULL = illimité
                'uses'        => '{INT} NOT NULL DEFAULT 0',
                'expires_at'  => '{DT} NULL',
                'quota_bytes' => '{BIG} NULL',                 // quota attribué aux inscrits
                'role'        => "{STR} NOT NULL DEFAULT 'user'",
                'active'      => '{INT} NOT NULL DEFAULT 1',
                'created_by'  => '{REF} NULL',
                'created_at'  => '{DT} NOT NULL DEFAULT {NOW}',
            ],
            'unique' => ['uq_invite_codes_code' => ['code']],
            'fk'     => ['created_by' => 'users(id) ON DELETE SET NULL'],
        ],
        'trash' => [
            'cols' => [
                'id'            => '{ID}',
                'user_id'       => '{REF} NOT NULL',
                'original_path' => '{PATH} NOT NULL',
                'name'          => '{STR} NOT NULL',
                'is_dir'        => '{INT} NOT NULL DEFAULT 0',
                'size'          => '{BIG} NOT NULL DEFAULT 0',
                'stored_name'   => '{TOK} NOT NULL',
                'deleted_by'    => '{REF} NULL',
                'deleted_at'    => '{DT} NOT NULL DEFAULT {NOW}',
            ],
            'index' => ['idx_trash_user' => ['user_id'], 'idx_trash_deleted' => ['deleted_at']],
            'fk'    => ['user_id' => 'users(id) ON DELETE CASCADE', 'deleted_by' => 'users(id) ON DELETE SET NULL'],
        ],
        'password_resets' => [
            'cols' => [
                'id'         => '{ID}',
                'user_id'    => '{REF} NOT NULL',
                'token_hash' => '{TOK} NOT NULL',
                'expires_at' => '{DT} NOT NULL',
                'used_at'    => '{DT} NULL',
                'created_at' => '{DT} NOT NULL DEFAULT {NOW}',
            ],
            'unique' => ['uq_password_resets_token' => ['token_hash']],
            'fk'     => ['user_id' => 'users(id) ON DELETE CASCADE'],
        ],
        'logs' => [
            'cols' => [
                'id'         => '{ID}',
                'user_id'    => '{REF} NULL',
                'action'     => '{STR} NOT NULL',
                'detail'     => '{TXT} NULL',
                'ip'         => '{STR} NULL',
                'created_at' => '{DT} NOT NULL DEFAULT {NOW}',
            ],
            'index' => ['idx_logs_action_ip' => ['action', 'ip', 'created_at'], 'idx_logs_user' => ['user_id']],
        ],
    ];
}

function schema_types(string $driver): array {
    if ($driver === 'mysql') {
        return [
            '{ID}' => 'INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY', '{REF}' => 'INT UNSIGNED',
            '{STR}' => 'VARCHAR(255)', '{TOK}' => 'VARCHAR(191) CHARACTER SET ascii COLLATE ascii_bin',
            '{PATH}' => 'VARCHAR(700) COLLATE utf8mb4_bin', '{TXT}' => 'TEXT',
            '{INT}' => 'INT', '{BIG}' => 'BIGINT', '{DT}' => 'DATETIME', '{NOW}' => 'CURRENT_TIMESTAMP',
        ];
    }
    return [
        '{ID}' => 'INTEGER PRIMARY KEY AUTOINCREMENT', '{REF}' => 'INTEGER',
        '{STR}' => 'TEXT', '{TOK}' => 'TEXT', '{PATH}' => 'TEXT', '{TXT}' => 'TEXT',
        '{INT}' => 'INTEGER', '{BIG}' => 'INTEGER', '{DT}' => 'TEXT', '{NOW}' => 'CURRENT_TIMESTAMP',
    ];
}

function schema_q(string $driver, string $name): string { return $driver === 'mysql' ? "`$name`" : "\"$name\""; }

function schema_col(string $driver, string $table, string $col, string $def): string {
    $sql = schema_q($driver, $col) . ' ' . strtr($def, schema_types($driver));
    if ($driver === 'sqlite' && in_array("$table.$col", ['users.username', 'users.email', 'invite_codes.code'], true)) {
        $sql .= ' COLLATE NOCASE';
    }
    return $sql;
}

/** Instructions CREATE TABLE / CREATE INDEX pour un moteur. */
function schema_create_sql(string $driver, bool $ifNotExists = true): array {
    $out = [];
    $ine = $ifNotExists ? 'IF NOT EXISTS ' : '';
    foreach (schema_tables() as $table => $t) {
        $lines = [];
        foreach ($t['cols'] as $c => $def) $lines[] = '  ' . schema_col($driver, $table, $c, $def);
        foreach ($t['unique'] ?? [] as $n => $cols) {
            $lines[] = '  CONSTRAINT ' . schema_q($driver, $n) . ' UNIQUE (' . implode(', ', array_map(fn($c) => schema_q($driver, $c), $cols)) . ')';
        }
        if ($driver === 'mysql') {
            foreach ($t['index'] ?? [] as $n => $cols) {
                $lines[] = '  KEY ' . schema_q($driver, $n) . ' (' . implode(', ', array_map(fn($c) => schema_q($driver, $c), $cols)) . ')';
            }
        }
        foreach ($t['fk'] ?? [] as $c => $ref) {
            preg_match('/^(\w+)\((\w+)\)(.*)$/', $ref, $m);
            $lines[] = '  ' . ($driver === 'mysql' ? 'CONSTRAINT ' . schema_q($driver, "fk_{$table}_{$c}") . ' ' : '')
                . 'FOREIGN KEY (' . schema_q($driver, $c) . ') REFERENCES ' . schema_q($driver, $m[1]) . ' (' . schema_q($driver, $m[2]) . ')' . $m[3];
        }
        $sql = 'CREATE TABLE ' . $ine . schema_q($driver, $table) . " (\n" . implode(",\n", $lines) . "\n)";
        if ($driver === 'mysql') $sql .= ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';
        $out[] = $sql;
        if ($driver === 'sqlite') {
            foreach ($t['index'] ?? [] as $n => $cols) {
                $out[] = 'CREATE INDEX ' . $ine . schema_q($driver, $n) . ' ON ' . schema_q($driver, $table)
                    . ' (' . implode(', ', array_map(fn($c) => schema_q($driver, $c), $cols)) . ')';
            }
        }
    }
    return $out;
}

function schema_existing_columns(PDO $pdo, string $driver, string $table): ?array {
    try {
        if ($driver === 'mysql') {
            $rows = $pdo->query('SHOW COLUMNS FROM `' . $table . '`')->fetchAll(PDO::FETCH_ASSOC);
            return array_column($rows, 'Field');
        }
        $rows = $pdo->query('PRAGMA table_info("' . $table . '")')->fetchAll(PDO::FETCH_ASSOC);
        return $rows ? array_column($rows, 'name') : null;
    } catch (PDOException $e) {
        return null;
    }
}

/** Crée les tables manquantes et ajoute les colonnes manquantes (mise à jour sans perte). */
function schema_sync(PDO $pdo, string $driver): void {
    foreach (schema_create_sql($driver, true) as $sql) $pdo->exec($sql);
    foreach (schema_tables() as $table => $t) {
        $have = schema_existing_columns($pdo, $driver, $table) ?? [];
        foreach ($t['cols'] as $c => $def) {
            if (in_array($c, $have, true)) continue;
            // Les colonnes ajoutées après coup sont toujours facultatives (NULL / valeur par défaut).
            $def = str_replace(['{ID}'], ['{INT}'], $def);
            $pdo->exec('ALTER TABLE ' . schema_q($driver, $table) . ' ADD COLUMN ' . schema_col($driver, $table, $c, $def));
        }
    }
}

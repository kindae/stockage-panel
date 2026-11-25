-- ============================================================
-- Yabiso Cloud Stockage — schéma de la base de données (SQLite)
-- Version du schéma : 2
-- Fichier généré par bin/export-sql.php à partir de inc/schema.php
-- ============================================================
-- L'application crée et met à jour ce schéma automatiquement : ce fichier est fourni pour référence.

PRAGMA foreign_keys = ON;

CREATE TABLE IF NOT EXISTS "users" (
  "id" INTEGER PRIMARY KEY AUTOINCREMENT,
  "username" TEXT NOT NULL COLLATE NOCASE,
  "display_name" TEXT NOT NULL,
  "email" TEXT NULL COLLATE NOCASE,
  "password_hash" TEXT NOT NULL,
  "role" TEXT NOT NULL DEFAULT 'user',
  "quota_bytes" INTEGER NULL,
  "folder" TEXT NOT NULL,
  "active" INTEGER NOT NULL DEFAULT 1,
  "invite_code_id" INTEGER NULL,
  "created_at" TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
  "last_login" TEXT NULL,
  CONSTRAINT "uq_users_username" UNIQUE ("username"),
  CONSTRAINT "uq_users_folder" UNIQUE ("folder")
);

CREATE INDEX IF NOT EXISTS "idx_users_email" ON "users" ("email");

CREATE TABLE IF NOT EXISTS "settings" (
  "key" TEXT NOT NULL PRIMARY KEY,
  "value" TEXT NULL
);

CREATE TABLE IF NOT EXISTS "shares" (
  "id" INTEGER PRIMARY KEY AUTOINCREMENT,
  "token" TEXT NOT NULL,
  "user_id" INTEGER NOT NULL,
  "path" TEXT NOT NULL,
  "is_dir" INTEGER NOT NULL DEFAULT 0,
  "downloads" INTEGER NOT NULL DEFAULT 0,
  "views" INTEGER NOT NULL DEFAULT 0,
  "note" TEXT NULL,
  "expires_at" TEXT NULL,
  "password_hash" TEXT NULL,
  "max_downloads" INTEGER NULL,
  "disabled" INTEGER NOT NULL DEFAULT 0,
  "last_access" TEXT NULL,
  "created_at" TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT "uq_shares_token" UNIQUE ("token"),
  FOREIGN KEY ("user_id") REFERENCES "users" ("id") ON DELETE CASCADE
);

CREATE INDEX IF NOT EXISTS "idx_shares_user_path" ON "shares" ("user_id", "path");

CREATE TABLE IF NOT EXISTS "invite_codes" (
  "id" INTEGER PRIMARY KEY AUTOINCREMENT,
  "code" TEXT NOT NULL COLLATE NOCASE,
  "note" TEXT NULL,
  "max_uses" INTEGER NULL,
  "uses" INTEGER NOT NULL DEFAULT 0,
  "expires_at" TEXT NULL,
  "quota_bytes" INTEGER NULL,
  "role" TEXT NOT NULL DEFAULT 'user',
  "active" INTEGER NOT NULL DEFAULT 1,
  "created_by" INTEGER NULL,
  "created_at" TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT "uq_invite_codes_code" UNIQUE ("code"),
  FOREIGN KEY ("created_by") REFERENCES "users" ("id") ON DELETE SET NULL
);

CREATE TABLE IF NOT EXISTS "trash" (
  "id" INTEGER PRIMARY KEY AUTOINCREMENT,
  "user_id" INTEGER NOT NULL,
  "original_path" TEXT NOT NULL,
  "name" TEXT NOT NULL,
  "is_dir" INTEGER NOT NULL DEFAULT 0,
  "size" INTEGER NOT NULL DEFAULT 0,
  "stored_name" TEXT NOT NULL,
  "deleted_by" INTEGER NULL,
  "deleted_at" TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY ("user_id") REFERENCES "users" ("id") ON DELETE CASCADE,
  FOREIGN KEY ("deleted_by") REFERENCES "users" ("id") ON DELETE SET NULL
);

CREATE INDEX IF NOT EXISTS "idx_trash_user" ON "trash" ("user_id");

CREATE INDEX IF NOT EXISTS "idx_trash_deleted" ON "trash" ("deleted_at");

CREATE TABLE IF NOT EXISTS "password_resets" (
  "id" INTEGER PRIMARY KEY AUTOINCREMENT,
  "user_id" INTEGER NOT NULL,
  "token_hash" TEXT NOT NULL,
  "expires_at" TEXT NOT NULL,
  "used_at" TEXT NULL,
  "created_at" TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT "uq_password_resets_token" UNIQUE ("token_hash"),
  FOREIGN KEY ("user_id") REFERENCES "users" ("id") ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS "logs" (
  "id" INTEGER PRIMARY KEY AUTOINCREMENT,
  "user_id" INTEGER NULL,
  "action" TEXT NOT NULL,
  "detail" TEXT NULL,
  "ip" TEXT NULL,
  "created_at" TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX IF NOT EXISTS "idx_logs_action_ip" ON "logs" ("action", "ip", "created_at");

CREATE INDEX IF NOT EXISTS "idx_logs_user" ON "logs" ("user_id");

INSERT OR IGNORE INTO "settings" ("key", value) VALUES ('schema_version', '2');

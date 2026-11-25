-- ============================================================
-- Yabiso Cloud Stockage — schéma de la base de données (MySQL / MariaDB)
-- Version du schéma : 2
-- Fichier généré par bin/export-sql.php à partir de inc/schema.php
-- ============================================================
--
-- Installation :
--   1. Hostinger › Bases de données › MySQL : créez une base et un utilisateur.
--   2. phpMyAdmin › sélectionnez la base › Importer › choisissez ce fichier.
--   3. Dans config.php, renseignez 'db' => ['driver' => 'mysql', ...].
--   4. Ouvrez le site : la page Installation crée le compte administrateur.
--
-- Toutes les dates sont stockées en UTC. Ce fichier ne contient AUCUNE donnée personnelle.

SET NAMES utf8mb4;
SET time_zone = '+00:00';
SET FOREIGN_KEY_CHECKS = 0;

DROP TABLE IF EXISTS `logs`;
DROP TABLE IF EXISTS `password_resets`;
DROP TABLE IF EXISTS `trash`;
DROP TABLE IF EXISTS `invite_codes`;
DROP TABLE IF EXISTS `shares`;
DROP TABLE IF EXISTS `settings`;
DROP TABLE IF EXISTS `users`;

CREATE TABLE `users` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `username` VARCHAR(255) NOT NULL,
  `display_name` VARCHAR(255) NOT NULL,
  `email` VARCHAR(255) NULL,
  `password_hash` VARCHAR(255) NOT NULL,
  `role` VARCHAR(255) NOT NULL DEFAULT 'user',
  `quota_bytes` BIGINT NULL,
  `folder` VARCHAR(255) NOT NULL,
  `active` INT NOT NULL DEFAULT 1,
  `invite_code_id` INT UNSIGNED NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `last_login` DATETIME NULL,
  CONSTRAINT `uq_users_username` UNIQUE (`username`),
  CONSTRAINT `uq_users_folder` UNIQUE (`folder`),
  KEY `idx_users_email` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `settings` (
  `key` VARCHAR(255) NOT NULL PRIMARY KEY,
  `value` TEXT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `shares` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `token` VARCHAR(191) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `user_id` INT UNSIGNED NOT NULL,
  `path` VARCHAR(700) COLLATE utf8mb4_bin NOT NULL,
  `is_dir` INT NOT NULL DEFAULT 0,
  `downloads` INT NOT NULL DEFAULT 0,
  `views` INT NOT NULL DEFAULT 0,
  `note` VARCHAR(255) NULL,
  `expires_at` DATETIME NULL,
  `password_hash` VARCHAR(255) NULL,
  `max_downloads` INT NULL,
  `disabled` INT NOT NULL DEFAULT 0,
  `last_access` DATETIME NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT `uq_shares_token` UNIQUE (`token`),
  KEY `idx_shares_user_path` (`user_id`, `path`),
  CONSTRAINT `fk_shares_user_id` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `invite_codes` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `code` VARCHAR(255) NOT NULL,
  `note` VARCHAR(255) NULL,
  `max_uses` INT NULL,
  `uses` INT NOT NULL DEFAULT 0,
  `expires_at` DATETIME NULL,
  `quota_bytes` BIGINT NULL,
  `role` VARCHAR(255) NOT NULL DEFAULT 'user',
  `active` INT NOT NULL DEFAULT 1,
  `created_by` INT UNSIGNED NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT `uq_invite_codes_code` UNIQUE (`code`),
  CONSTRAINT `fk_invite_codes_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `trash` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `user_id` INT UNSIGNED NOT NULL,
  `original_path` VARCHAR(700) COLLATE utf8mb4_bin NOT NULL,
  `name` VARCHAR(255) NOT NULL,
  `is_dir` INT NOT NULL DEFAULT 0,
  `size` BIGINT NOT NULL DEFAULT 0,
  `stored_name` VARCHAR(191) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `deleted_by` INT UNSIGNED NULL,
  `deleted_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY `idx_trash_user` (`user_id`),
  KEY `idx_trash_deleted` (`deleted_at`),
  CONSTRAINT `fk_trash_user_id` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_trash_deleted_by` FOREIGN KEY (`deleted_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `password_resets` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `user_id` INT UNSIGNED NOT NULL,
  `token_hash` VARCHAR(191) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `expires_at` DATETIME NOT NULL,
  `used_at` DATETIME NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT `uq_password_resets_token` UNIQUE (`token_hash`),
  CONSTRAINT `fk_password_resets_user_id` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `logs` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `user_id` INT UNSIGNED NULL,
  `action` VARCHAR(255) NOT NULL,
  `detail` TEXT NULL,
  `ip` VARCHAR(255) NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY `idx_logs_action_ip` (`action`, `ip`, `created_at`),
  KEY `idx_logs_user` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `settings` (`key`, `value`) VALUES
  ('schema_version', '2'),
  ('default_quota', '209715200'),
  ('registration_open', '1'),
  ('rotate_code', '1'),
  ('pretty_urls', '1'),
  ('trash_enabled', '1'),
  ('trash_days', '30'),
  ('trash_counts_quota', '1');

SET FOREIGN_KEY_CHECKS = 1;

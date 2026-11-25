<?php
/**
 * Génère database/yabiso.sql (MySQL/MariaDB) et database/yabiso-sqlite.sql à partir de inc/schema.php.
 * Usage : php bin/export-sql.php
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require dirname(__DIR__) . '/inc/schema.php';

$head = function (string $engine): string {
    return "-- ============================================================\n"
        . "-- Yabiso Cloud Stockage — schéma de la base de données ($engine)\n"
        . "-- Version du schéma : " . SCHEMA_VERSION . "\n"
        . "-- Fichier généré par bin/export-sql.php à partir de inc/schema.php\n"
        . "-- ============================================================\n";
};

// ---------- MySQL / MariaDB ----------
$my = $head('MySQL / MariaDB');
$my .= "--\n-- Installation :\n"
    . "--   1. Hostinger › Bases de données › MySQL : créez une base et un utilisateur.\n"
    . "--   2. phpMyAdmin › sélectionnez la base › Importer › choisissez ce fichier.\n"
    . "--   3. Dans config.php, renseignez 'db' => ['driver' => 'mysql', ...].\n"
    . "--   4. Ouvrez le site : la page Installation crée le compte administrateur.\n"
    . "--\n-- Toutes les dates sont stockées en UTC. Ce fichier ne contient AUCUNE donnée personnelle.\n\n"
    . "SET NAMES utf8mb4;\nSET time_zone = '+00:00';\nSET FOREIGN_KEY_CHECKS = 0;\n\n";
foreach (array_reverse(array_keys(schema_tables())) as $t) $my .= "DROP TABLE IF EXISTS `$t`;\n";
$my .= "\n";
foreach (schema_create_sql('mysql', false) as $sql) $my .= $sql . ";\n\n";
$my .= "INSERT INTO `settings` (`key`, `value`) VALUES\n"
    . "  ('schema_version', '" . SCHEMA_VERSION . "'),\n"
    . "  ('default_quota', '209715200'),\n"
    . "  ('registration_open', '1'),\n"
    . "  ('rotate_code', '1'),\n"
    . "  ('pretty_urls', '1'),\n"
    . "  ('trash_enabled', '1'),\n"
    . "  ('trash_days', '30'),\n"
    . "  ('trash_counts_quota', '1');\n\n"
    . "SET FOREIGN_KEY_CHECKS = 1;\n";
file_put_contents(dirname(__DIR__) . '/database/yabiso.sql', $my);

// ---------- SQLite ----------
$lite = $head('SQLite') . "-- L'application crée et met à jour ce schéma automatiquement : ce fichier est fourni pour référence.\n\n"
    . "PRAGMA foreign_keys = ON;\n\n";
foreach (schema_create_sql('sqlite', true) as $sql) $lite .= $sql . ";\n\n";
$lite .= "INSERT OR IGNORE INTO \"settings\" (\"key\", value) VALUES ('schema_version', '" . SCHEMA_VERSION . "');\n";
file_put_contents(dirname(__DIR__) . '/database/yabiso-sqlite.sql', $lite);

echo "OK : database/yabiso.sql et database/yabiso-sqlite.sql générés.\n";

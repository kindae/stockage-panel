<?php
// Copiez ce fichier en config.php pour personnaliser (facultatif).
return [
    // Nom affiché
    'app_name'   => 'Yabiso Cloud Stockage',
    // URL publique de base (sans / final). Laisser vide = détection automatique.
    'base_url'   => '', // ex: 'https://bd.yabiso.cloud'
    // Dossiers de données (idéalement HORS du dossier public si votre hébergeur le permet)
    'data_dir'    => __DIR__ . '/data',
    'storage_dir' => __DIR__ . '/storage',
    // Fuseau horaire d'affichage
    'timezone' => 'Africa/Kinshasa',
    // Taille max d'un fichier texte éditable en ligne (octets)
    'max_edit_size' => 2 * 1024 * 1024,
];

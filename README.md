# Yabiso Cloud Stockage

Espace de stockage privé pour créateurs d’applications mobiles, à installer sur
**bd.yabiso.cloud**. PHP 8 avec **SQLite** (aucune configuration) ou **MySQL / MariaDB**
(fichier `database/yabiso.sql` à importer dans phpMyAdmin).

## Fonctionnalités

**Membres**
- Inscription uniquement avec un **code d’inscription** fourni par l’administration.
- Chaque membre a son **propre dossier** (au nom de son identifiant) et ne voit que lui.
- Envoi de fichiers et de **dossiers entiers** (boutons ou glisser-déposer), barre de progression.
- Créer des dossiers, **renommer**, **déplacer**, **supprimer** (un ou plusieurs éléments).
- **Modifier en ligne** les fichiers texte (json, xml, yaml, txt, md, dart, kt, js…) — Ctrl+S pour enregistrer.
- **🔗 Lien** sur chaque fichier/dossier :
  - page de téléchargement : `https://bd.yabiso.cloud/s/JETON`
  - téléchargement direct : `https://bd.yabiso.cloud/s/JETON/dl`
  - **lecture directe** (affichage dans le navigateur, une WebView, `fetch()`…) :
    `https://bd.yabiso.cloud/s/JETON/v/config.json` — JSON, HTML, CSS, JS, images, SVG, PDF,
    vidéo, audio, polices… avec le bon `Content-Type`, CORS ouvert (`Access-Control-Allow-Origin: *`),
    cache court + ETag.
  - **site d’un dossier** (WebView) : `https://bd.yabiso.cloud/s/JETON/f/` ouvre `index.html`,
    et les chemins relatifs (`css/app.css`, `img/logo.png`) fonctionnent.
  - fichier d’un dossier partagé : `https://bd.yabiso.cloud/s/JETON/f/chemin/fichier.json`
  - dossier partagé téléchargeable en ZIP.
- Les liens suivent les fichiers renommés/déplacés ; page « Mes liens » pour les désactiver.
- **Options de chaque lien** : date d’expiration, mot de passe, nombre maximum de
  téléchargements, note privée, désactivation temporaire, compteurs (téléchargements / lectures).
  Un lien protégé donne aussi une **clé** (`?k=…`) pour l’utiliser dans une application sans
  saisir le mot de passe.
- **Corbeille** : les suppressions sont restaurables pendant 30 jours (réglable), puis
  effacées automatiquement.
- **Copier / dupliquer** fichiers et dossiers, **recherche** dans tout son espace,
  **tri** par nom, taille ou date.
- **Mot de passe oublié** : lien de réinitialisation envoyé par e-mail (valable 1 heure).
- Quota (200 Mo par défaut) avec barre d’utilisation.

**Administration** (`/admin.php`) — administrateur et modérateurs
- Code d’inscription principal visible seulement ici ; **changé automatiquement après chaque
  inscription** (option), régénérable ou personnalisable ; inscriptions ouvrables/fermables.
- **Codes d’invitation supplémentaires** : nombre d’inscriptions limité, date d’expiration,
  quota et rôle attribués automatiquement, lien d’inscription prérempli et message
  d’invitation prêt à copier (WhatsApp, e-mail).
- Recherche et filtre des membres ; corbeille de chaque membre accessible à l’administration.
- Quota par défaut modifiable (admin) + **quota personnalisé par membre** (vide = défaut).
- Suspendre/réactiver, réinitialiser le mot de passe, supprimer un membre, parcourir ses fichiers.
- Création manuelle de comptes, journal d’activité.
- Rôles : **Administrateur** (tout), **Modérateur** (gère uniquement les membres simples,
  voit le code, ne change pas les paramètres globaux ni les rôles).

## Installation (cPanel / hébergement mutualisé)

1. Dans cPanel › **Sous-domaines**, créez `bd.yabiso.cloud` (racine, ex. `public_html/bd`).
2. Envoyez tout le contenu de ce dépôt dans ce dossier (Gestionnaire de fichiers ou Git).
3. Activez le **SSL** (cPanel › SSL/TLS Status › AutoSSL) pour le sous-domaine.
4. Vérifiez PHP ≥ 8.0 avec les extensions `pdo_sqlite`, `fileinfo`, `zip` (Sélecteur PHP).
5. Base de données : par défaut **SQLite**, rien à faire (le fichier est créé dans `data/`).
   Pour **MySQL / MariaDB**, voir la section ci-dessous.
6. Ouvrez `https://bd.yabiso.cloud` : la page **Installation** crée le compte administrateur.
7. Dans Administration, copiez le **code d’inscription** et envoyez-le avec le lien
   `https://bd.yabiso.cloud/register.php` à la personne à inscrire.

### Utiliser MySQL / MariaDB (Hostinger)
1. hPanel › **Bases de données › Gestion** : créez une base, un utilisateur et un mot de passe.
2. **phpMyAdmin** › sélectionnez la base › **Importer** › choisissez `database/yabiso.sql`.
3. Copiez `config.sample.php` en `config.php` et remplissez :
   ```php
   'db' => ['driver' => 'mysql', 'host' => 'localhost', 'port' => 3306,
            'name' => 'u123456789_yabiso', 'user' => 'u123456789_yabiso', 'pass' => '…'],
   ```
4. Ouvrez le site : la page **Installation** crée le compte administrateur.

Les mises à jour du schéma sont **automatiques** (SQLite comme MySQL) : les tables et colonnes
manquantes sont ajoutées sans perte de données. Le schéma est défini dans `inc/schema.php` ;
après une modification, régénérez les fichiers SQL avec `php bin/export-sql.php`.

> Passer d’une installation SQLite existante à MySQL ne transfère pas automatiquement les
> comptes : faites-le sur une installation neuve, ou demandez un script de migration.

### E-mails
« Mot de passe oublié » utilise la fonction `mail()` de l’hébergeur. Créez une adresse
(ex. `no-reply@yabiso.cloud`) dans hPanel › E-mails et indiquez-la dans Administration ›
Paramètres pour une meilleure délivrabilité.

### Taille maximale des fichiers
La limite par fichier dépend de PHP (`upload_max_filesize` / `post_max_size`). Le fichier
`.user.ini` la fixe à 256 Mo ; si l’hébergeur l’ignore, modifiez-la dans cPanel ›
**Sélecteur PHP › Options** (ou MultiPHP INI Editor). La limite actuelle est affichée dans
l’administration.

### Sécurité
- `data/` (base SQLite) et `storage/` (fichiers) sont bloqués par `.htaccess` ; les fichiers
  ne sont servis que via PHP, après contrôle d’accès. Pour plus de sûreté, placez-les hors de
  la racine web : copiez `config.sample.php` en `config.php` et changez `data_dir` / `storage_dir`.
- Sur **Nginx** (pas de `.htaccess`), ajoutez :
  ```nginx
  location ~ ^/(data|storage|inc|database|bin|docs)/ { deny all; }
  location ~ ^/s/([A-Za-z0-9_-]+)/dl/?$ { rewrite ^/s/([^/]+)/dl/?$ /s.php?t=$1&dl=1 last; }
  location ~ ^/s/([A-Za-z0-9_-]+)/v(/.*)?$ { rewrite ^/s/([^/]+)/v(/.*)?$ /s.php?t=$1&raw=1 last; }
  location ~ ^/s/([A-Za-z0-9_-]+)/f/?$ { rewrite ^/s/([^/]+)/f/?$ /s.php?t=$1&raw=1 last; }
  location ~ ^/s/([A-Za-z0-9_-]+)/f/(.+)$ { rewrite ^/s/([^/]+)/f/(.+)$ /s.php?t=$1&p=$2&raw=1 last; }
  location ~ ^/s/([A-Za-z0-9_-]+)/?$ { rewrite ^/s/([^/]+)/?$ /s.php?t=$1 last; }
  ```
  Sans réécriture d’URL, décochez « Liens courts » dans l’administration.
- Mots de passe hachés (bcrypt), protection CSRF, limitation des tentatives de connexion,
  protection contre la traversée de chemins.

### Pages HTML partagées et WebView
Un fichier HTML d’un membre affiché sur `bd.yabiso.cloud` pourrait, s’il était malveillant,
voler la session d’un administrateur. Par défaut, le HTML/SVG est donc servi en **mode isolé**
(`CSP sandbox`) : les scripts, formulaires et CDN fonctionnent, mais pas les cookies ni le
`localStorage`.

Pour des sites/WebView sans restriction, créez un **2ᵉ sous-domaine** (ex. `files.yabiso.cloud`)
dont la racine est **le même dossier** que `bd.yabiso.cloud`, activez le SSL, puis indiquez-le
dans Administration › Paramètres › « Domaine des contenus ». Les liens de lecture utiliseront ce
domaine (sans session, sans accès au panneau) et le HTML y sera servi normalement.

### Sauvegarde
Sauvegardez les dossiers `data/` et `storage/` (la corbeille est dans `storage/.trash/`),
ainsi que la base MySQL si vous l’utilisez (phpMyAdmin › Exporter).

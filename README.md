# Yabiso Cloud Stockage

Espace de stockage privé pour créateurs d’applications mobiles, à installer sur
**bd.yabiso.cloud**. PHP 8 + SQLite : aucune base MySQL à créer.

## Fonctionnalités

**Membres**
- Inscription uniquement avec un **code d’inscription** fourni par l’administration.
- Chaque membre a son **propre dossier** (au nom de son identifiant) et ne voit que lui.
- Envoi de fichiers et de **dossiers entiers** (boutons ou glisser-déposer), barre de progression.
- Créer des dossiers, **renommer**, **déplacer**, **supprimer** (un ou plusieurs éléments).
- **Modifier en ligne** les fichiers texte (json, xml, yaml, txt, md, dart, kt, js…) — Ctrl+S pour enregistrer.
- **🔗 Lien** sur chaque fichier/dossier :
  - page de téléchargement : `https://bd.yabiso.cloud/s/JETON`
  - lien direct (fichier) : `https://bd.yabiso.cloud/s/JETON/dl`
  - fichier dans un dossier partagé : `https://bd.yabiso.cloud/s/JETON/f/chemin/fichier.json`
  - dossier partagé téléchargeable en ZIP.
- Les liens suivent les fichiers renommés/déplacés ; page « Mes liens » pour les désactiver.
- Quota (200 Mo par défaut) avec barre d’utilisation.

**Administration** (`/admin.php`) — administrateur et modérateurs
- Code d’inscription visible seulement ici ; **changé automatiquement après chaque inscription**
  (option), régénérable ou personnalisable ; inscriptions ouvrables/fermables.
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
5. Ouvrez `https://bd.yabiso.cloud` : la page **Installation** crée le compte administrateur.
6. Dans Administration, copiez le **code d’inscription** et envoyez-le avec le lien
   `https://bd.yabiso.cloud/register.php` à la personne à inscrire.

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
  location ~ ^/(data|storage|inc)/ { deny all; }
  location ~ ^/s/([A-Za-z0-9_-]+)/dl/?$ { rewrite ^/s/([^/]+)/dl/?$ /s.php?t=$1&dl=1 last; }
  location ~ ^/s/([A-Za-z0-9_-]+)/f/(.+)$ { rewrite ^/s/([^/]+)/f/(.+)$ /s.php?t=$1&p=$2&raw=1 last; }
  location ~ ^/s/([A-Za-z0-9_-]+)/?$ { rewrite ^/s/([^/]+)/?$ /s.php?t=$1 last; }
  ```
  Sans réécriture d’URL, décochez « Liens courts » dans l’administration.
- Mots de passe hachés (bcrypt), protection CSRF, limitation des tentatives de connexion,
  protection contre la traversée de chemins.

### Sauvegarde
Sauvegardez les dossiers `data/` et `storage/`.

<?php
require __DIR__ . '/inc/bootstrap.php';
$me = require_login();

/**
 * Le personnel (admin/modérateur) peut agir sur l'espace d'un autre membre via ?as=ID
 * (lecture, liens, suppression – pour la modération).
 */
$owner = $me;
if (!empty($_REQUEST['as']) && (int)$_REQUEST['as'] !== (int)$me['id']) {
    if (!is_staff($me)) json_out(['ok' => false, 'error' => 'Accès refusé.'], 403);
    $st = db()->prepare('SELECT * FROM users WHERE id = ?');
    $st->execute([(int)$_REQUEST['as']]);
    $owner = $st->fetch();
    if (!$owner) json_out(['ok' => false, 'error' => 'Membre introuvable.'], 404);
    if ($owner['role'] === 'admin' && !is_admin($me)) json_out(['ok' => false, 'error' => 'Accès refusé.'], 403);
}
$root = user_root($owner);
$action = $_REQUEST['action'] ?? '';

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') check_csrf();

    switch ($action) {
    case 'list': {
        $rel = clean_rel($_GET['path'] ?? '');
        $abs = abs_path($root, $rel);
        if (!is_dir($abs)) json_out(['ok' => false, 'error' => 'Dossier introuvable.'], 404);
        $items = list_dir($abs);
        $st = db()->prepare('SELECT path, token FROM shares WHERE user_id = ?');
        $st->execute([$owner['id']]);
        $shared = array_column($st->fetchAll(), 'token', 'path');
        foreach ($items as &$it) {
            $p = ($rel === '' ? '' : $rel . '/') . $it['name'];
            $it['shared'] = isset($shared[$p]);
            $it['editable'] = !$it['dir'] && is_text_ext($it['ext']) && $it['size'] <= cfg('max_edit_size');
        }
        if (random_int(1, 40) === 1) trash_autopurge();
        $used = user_usage($owner);
        $tc = db()->prepare('SELECT COUNT(*) FROM trash WHERE user_id = ?'); $tc->execute([$owner['id']]);
        json_out(['ok' => true, 'path' => $rel, 'items' => $items, 'used' => $used, 'quota' => user_quota($owner),
            'trash_count' => (int)$tc->fetchColumn(), 'trash_enabled' => trash_enabled(),
            'max_upload' => max_upload_bytes()]);
    }

    case 'mkdir': {
        $rel = clean_rel($_POST['path'] ?? '');
        $name = trim($_POST['name'] ?? '');
        if (!valid_name($name)) throw new RuntimeException('Nom de dossier invalide.');
        $abs = abs_path($root, $rel . '/' . $name);
        if (file_exists($abs)) throw new RuntimeException('Un élément porte déjà ce nom.');
        mkdir($abs, 0750, true);
        log_action('mkdir', $owner['folder'] . '/' . ltrim($rel . '/' . $name, '/'));
        json_out(['ok' => true]);
    }

    case 'upload': {
        $rel = clean_rel($_POST['path'] ?? '');
        // "relpath" : chemin relatif quand on envoie un dossier entier (webkitdirectory)
        $sub = clean_rel($_POST['relpath'] ?? '');
        if (empty($_FILES['file'])) throw new RuntimeException('Aucun fichier reçu (taille maximale du serveur dépassée ?).');
        $f = $_FILES['file'];
        if ($f['error'] !== UPLOAD_ERR_OK) throw new RuntimeException(upload_error($f['error']));
        $name = $sub !== '' ? basename($sub) : $f['name'];
        $name = trim(str_replace(['/', '\\'], '_', $name));
        if (!valid_name($name)) throw new RuntimeException('Nom de fichier invalide : ' . $name);
        $dirRel = trim($rel . '/' . ($sub !== '' ? (dirname($sub) === '.' ? '' : dirname($sub)) : ''), '/');
        $dirAbs = abs_path($root, $dirRel);
        $quota = user_quota($owner);
        $used = user_usage($owner);
        $target = $dirAbs . '/' . $name;
        $replace = ($_POST['mode'] ?? '') === 'replace' && is_file($target);
        $delta = $f['size'] - ($replace ? filesize($target) : 0);
        if ($used + $delta > $quota) {
            throw new RuntimeException('Espace insuffisant : ' . human_size($quota - $used) . ' disponibles, le fichier fait ' . human_size($f['size']) . '.');
        }
        if (!is_dir($dirAbs)) mkdir($dirAbs, 0750, true);
        if (!$replace) $name = unique_name($dirAbs, $name);
        if (!move_uploaded_file($f['tmp_name'], $dirAbs . '/' . $name)) throw new RuntimeException('Échec de l’enregistrement du fichier.');
        @chmod($dirAbs . '/' . $name, 0640);
        log_action('upload', $owner['folder'] . '/' . ltrim($dirRel . '/' . $name, '/'));
        json_out(['ok' => true, 'name' => $name]);
    }

    case 'rename': {
        $rel = clean_rel($_POST['path'] ?? '');
        $new = trim($_POST['name'] ?? '');
        if ($rel === '') throw new RuntimeException('Impossible de renommer votre dossier racine.');
        if (!valid_name($new)) throw new RuntimeException('Nouveau nom invalide.');
        $abs = abs_path($root, $rel);
        if (!file_exists($abs)) throw new RuntimeException('Élément introuvable.');
        $parent = dirname($rel) === '.' ? '' : dirname($rel);
        $newRel = ltrim($parent . '/' . $new, '/');
        $newAbs = abs_path($root, $newRel);
        if ($newAbs === $abs) json_out(['ok' => true]);
        if (file_exists($newAbs) && strtolower($newAbs) !== strtolower($abs)) throw new RuntimeException('Un élément porte déjà ce nom.');
        rename($abs, $newAbs);
        shares_moved((int)$owner['id'], $rel, $newRel);
        log_action('rename', $rel . ' → ' . $newRel);
        json_out(['ok' => true]);
    }

    case 'move': {
        $paths = (array)($_POST['paths'] ?? []);
        $dest = clean_rel($_POST['dest'] ?? '');
        $destAbs = abs_path($root, $dest);
        if (!is_dir($destAbs)) throw new RuntimeException('Dossier de destination introuvable.');
        foreach ($paths as $p) {
            $rel = clean_rel($p);
            if ($rel === '') continue;
            $abs = abs_path($root, $rel);
            if ($dest === $rel || strpos($dest . '/', $rel . '/') === 0) throw new RuntimeException('Impossible de déplacer un dossier dans lui-même.');
            $name = basename($rel);
            if (file_exists($destAbs . '/' . $name)) throw new RuntimeException('« ' . $name . ' » existe déjà dans la destination.');
            rename($abs, $destAbs . '/' . $name);
            shares_moved((int)$owner['id'], $rel, ltrim($dest . '/' . $name, '/'));
            log_action('move', $rel . ' → ' . $dest);
        }
        json_out(['ok' => true]);
    }

    case 'delete': {
        $paths = (array)($_POST['paths'] ?? []);
        foreach ($paths as $p) {
            $rel = clean_rel($p);
            if ($rel === '') throw new RuntimeException('Impossible de supprimer votre dossier racine.');
            $abs = abs_path($root, $rel);
            if (!file_exists($abs)) continue;
            trash_put($owner, $rel, (int)$me['id']);
            shares_deleted((int)$owner['id'], $rel);
            log_action(trash_enabled() ? 'trash' : 'delete', $owner['folder'] . '/' . $rel);
        }
        json_out(['ok' => true, 'trash' => trash_enabled()]);
    }

    case 'read': {
        $rel = clean_rel($_GET['path'] ?? '');
        $abs = abs_path($root, $rel);
        if (!is_file($abs)) throw new RuntimeException('Fichier introuvable.');
        if (filesize($abs) > cfg('max_edit_size')) throw new RuntimeException('Fichier trop volumineux pour l’éditeur.');
        $c = file_get_contents($abs);
        if (!mb_check_encoding($c, 'UTF-8')) throw new RuntimeException('Ce fichier n’est pas un texte UTF-8 modifiable.');
        json_out(['ok' => true, 'content' => $c]);
    }

    case 'save': {
        $rel = clean_rel($_POST['path'] ?? '');
        $content = (string)($_POST['content'] ?? '');
        if ($rel === '') throw new RuntimeException('Chemin invalide.');
        $abs = abs_path($root, $rel);
        if (is_dir($abs)) throw new RuntimeException('C’est un dossier.');
        if (strlen($content) > cfg('max_edit_size')) throw new RuntimeException('Contenu trop volumineux.');
        $old = is_file($abs) ? filesize($abs) : 0;
        if (user_usage($owner) - $old + strlen($content) > user_quota($owner)) throw new RuntimeException('Espace insuffisant.');
        if (!is_file($abs) && ($_POST['create'] ?? '') !== '1') throw new RuntimeException('Fichier introuvable.');
        if (!is_file($abs) && file_exists($abs)) throw new RuntimeException('Un élément porte déjà ce nom.');
        file_put_contents($abs, $content, LOCK_EX);
        log_action('edit', $owner['folder'] . '/' . $rel);
        json_out(['ok' => true]);
    }

    case 'share': {
        $rel = clean_rel($_POST['path'] ?? '');
        $abs = abs_path($root, $rel);
        if (!file_exists($abs)) throw new RuntimeException('Élément introuvable.');
        $share = share_find($owner, $rel);
        if (!$share) {
            db()->prepare('INSERT INTO shares(token, user_id, path, is_dir) VALUES(?,?,?,?)')
                ->execute([random_token(9), $owner['id'], $rel, is_dir($abs) ? 1 : 0]);
            log_action('share', $owner['folder'] . '/' . $rel);
            $share = share_find($owner, $rel);
        }
        json_out(share_payload($owner, $share, $abs));
    }

    case 'share_options': {
        $rel = clean_rel($_POST['path'] ?? '');
        $abs = abs_path($root, $rel);
        $share = share_find($owner, $rel);
        if (!$share || !file_exists($abs)) throw new RuntimeException('Lien introuvable.');
        $exp = local_to_utc((string)($_POST['expires_at'] ?? ''));
        if (($_POST['expires_at'] ?? '') !== '' && $exp === null) throw new RuntimeException('Date d’expiration invalide.');
        $max = trim((string)($_POST['max_downloads'] ?? ''));
        if ($max !== '' && (!ctype_digit($max) || (int)$max < 1)) throw new RuntimeException('Limite de téléchargements invalide.');
        $pwHash = $share['password_hash'];
        if (!empty($_POST['remove_password'])) $pwHash = null;
        $pw = (string)($_POST['password'] ?? '');
        if ($pw !== '') {
            if (strlen($pw) < 4) throw new RuntimeException('Mot de passe du lien : 4 caractères minimum.');
            $pwHash = password_hash($pw, PASSWORD_DEFAULT);
        }
        $downloads = !empty($_POST['reset_counter']) ? 0 : (int)$share['downloads'];
        db()->prepare('UPDATE shares SET note = ?, expires_at = ?, max_downloads = ?, password_hash = ?, disabled = ?, downloads = ? WHERE id = ?')
            ->execute([mb_substr(trim((string)($_POST['note'] ?? '')), 0, 200) ?: null, $exp, $max === '' ? null : (int)$max,
                       $pwHash, !empty($_POST['disabled']) ? 1 : 0, $downloads, $share['id']]);
        log_action('share_options', $owner['folder'] . '/' . $rel);
        json_out(share_payload($owner, share_find($owner, $rel), $abs));
    }

    case 'unshare': {
        $rel = clean_rel($_POST['path'] ?? '');
        $st = db()->prepare('DELETE FROM shares WHERE user_id = ? AND (path = ? OR token = ?)');
        $st->execute([$owner['id'], $rel, (string)($_POST['token'] ?? '')]);
        log_action('unshare', $owner['folder'] . '/' . $rel);
        json_out(['ok' => true]);
    }

    case 'copy': {
        $paths = (array)($_POST['paths'] ?? []);
        $dest = clean_rel($_POST['dest'] ?? '');
        $destAbs = abs_path($root, $dest);
        if (!is_dir($destAbs)) throw new RuntimeException('Dossier de destination introuvable.');
        $need = 0; $srcs = [];
        foreach ($paths as $p) {
            $rel = clean_rel($p);
            if ($rel === '') throw new RuntimeException('Impossible de copier le dossier racine.');
            $abs = abs_path($root, $rel);
            if (!file_exists($abs)) throw new RuntimeException('Élément introuvable : ' . $rel);
            if (is_dir($abs) && ($dest === $rel || strpos($dest . '/', $rel . '/') === 0)) throw new RuntimeException('Impossible de copier un dossier dans lui-même.');
            $need += dir_size($abs); $srcs[] = [$rel, $abs];
        }
        if (user_usage($owner) + $need > user_quota($owner)) {
            throw new RuntimeException('Espace insuffisant : la copie nécessite ' . human_size($need) . '.');
        }
        $names = [];
        foreach ($srcs as [$rel, $abs]) {
            $name = copy_name($destAbs, basename($rel));
            copy_rec($abs, $destAbs . '/' . $name);
            $names[] = $name;
            log_action('copy', $rel . ' → ' . ltrim($dest . '/' . $name, '/'));
        }
        json_out(['ok' => true, 'names' => $names]);
    }

    case 'search': {
        $q = trim((string)($_GET['q'] ?? ''));
        if (mb_strlen($q) < 2) throw new RuntimeException('Tapez au moins 2 caractères.');
        json_out(['ok' => true, 'results' => search_files($root, $q)]);
    }

    case 'tree': {
        // Liste de tous les dossiers (pour "Déplacer vers…")
        $out = [''];
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST);
        foreach ($it as $f) if ($f->isDir()) $out[] = str_replace('\\', '/', substr($f->getPathname(), strlen($root) + 1));
        sort($out, SORT_NATURAL | SORT_FLAG_CASE);
        json_out(['ok' => true, 'dirs' => array_slice($out, 0, 2000)]);
    }

    default:
        json_out(['ok' => false, 'error' => 'Action inconnue.'], 400);
    }
} catch (RuntimeException $ex) {
    json_out(['ok' => false, 'error' => $ex->getMessage()], 400);
}

function upload_error(int $code): string {
    return [
        UPLOAD_ERR_INI_SIZE => 'Fichier trop volumineux pour le serveur (limite : ' . human_size(max_upload_bytes()) . ').',
        UPLOAD_ERR_FORM_SIZE => 'Fichier trop volumineux.',
        UPLOAD_ERR_PARTIAL => 'Envoi interrompu, réessayez.',
        UPLOAD_ERR_NO_FILE => 'Aucun fichier envoyé.',
        UPLOAD_ERR_NO_TMP_DIR => 'Dossier temporaire manquant sur le serveur.',
        UPLOAD_ERR_CANT_WRITE => 'Écriture impossible sur le disque.',
    ][$code] ?? 'Erreur d’envoi (' . $code . ').';
}

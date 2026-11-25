<?php
/** Racine de stockage d'un utilisateur. */
function user_root(array $u): string {
    $dir = cfg('storage_dir') . '/' . $u['folder'];
    if (!is_dir($dir)) mkdir($dir, 0750, true);
    return $dir;
}

function valid_name(string $name): bool {
    if ($name === '' || $name === '.' || $name === '..') return false;
    if (mb_strlen($name) > 180) return false;
    if (preg_match('#[/\\\\\x00-\x1F<>:"|?*]#u', $name)) return false;
    if ($name[0] === '.' && preg_match('/^\.ht/i', $name)) return false;
    if (rtrim($name, ' .') !== $name) return false;
    return true;
}

/** Normalise un chemin relatif ("a/b/c"), lance une exception si invalide. */
function clean_rel(?string $rel): string {
    $rel = str_replace('\\', '/', (string)$rel);
    $parts = [];
    foreach (explode('/', $rel) as $seg) {
        if ($seg === '' || $seg === '.') continue;
        if (!valid_name($seg)) throw new RuntimeException('Chemin invalide.');
        $parts[] = $seg;
    }
    return implode('/', $parts);
}

/** Chemin absolu sécurisé à l'intérieur de la racine. */
function abs_path(string $root, string $rel): string {
    $rel = clean_rel($rel);
    $path = $root . ($rel === '' ? '' : '/' . $rel);
    // Vérifie qu'aucun lien symbolique ne sort de la racine
    $realRoot = realpath($root);
    $check = $path;
    while (!file_exists($check) && $check !== $root) $check = dirname($check);
    $real = realpath($check);
    if ($real === false || ($real !== $realRoot && strpos($real, $realRoot . DIRECTORY_SEPARATOR) !== 0)) {
        throw new RuntimeException('Accès refusé.');
    }
    return $path;
}

function dir_size(string $dir): int {
    if (!is_dir($dir)) return is_file($dir) ? (int)filesize($dir) : 0;
    $size = 0;
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $f) if ($f->isFile()) $size += $f->getSize();
    return $size;
}

function dir_count(string $dir): array {
    $files = 0; $dirs = 0;
    if (!is_dir($dir)) return [0, 0];
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST);
    foreach ($it as $f) { $f->isDir() ? $dirs++ : $files++; }
    return [$files, $dirs];
}

function user_usage(array $u): int {
    $used = dir_size(user_root($u));
    if (setting('trash_counts_quota', '1') === '1') $used += trash_size($u);
    return $used;
}

function rm_rf(string $path): void {
    if (is_link($path) || is_file($path)) { unlink($path); return; }
    if (!is_dir($path)) return;
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($it as $f) { $f->isDir() && !$f->isLink() ? rmdir($f->getPathname()) : unlink($f->getPathname()); }
    rmdir($path);
}

function list_dir(string $abs): array {
    $items = [];
    foreach (new DirectoryIterator($abs) as $f) {
        if ($f->isDot()) continue;
        $name = $f->getFilename();
        if (preg_match('/^\.ht/i', $name)) continue;
        $isDir = $f->isDir();
        $items[] = [
            'name'  => $name,
            'dir'   => $isDir,
            'size'  => $isDir ? null : $f->getSize(),
            'mtime' => $f->getMTime(),
            'ext'   => $isDir ? '' : strtolower($f->getExtension()),
        ];
    }
    usort($items, fn($a, $b) => [$b['dir'], mb_strtolower($a['name'])] <=> [$a['dir'], mb_strtolower($b['name'])]);
    return $items;
}

function unique_name(string $dir, string $name): string {
    if (!file_exists($dir . '/' . $name)) return $name;
    $ext = pathinfo($name, PATHINFO_EXTENSION);
    $base = $ext !== '' ? substr($name, 0, -strlen($ext) - 1) : $name;
    for ($i = 1; ; $i++) {
        $cand = $base . ' (' . $i . ')' . ($ext !== '' ? '.' . $ext : '');
        if (!file_exists($dir . '/' . $cand)) return $cand;
    }
}

function is_text_ext(string $ext): bool {
    return in_array($ext, ['txt','md','json','xml','yml','yaml','csv','html','htm','css','js','ts','jsx','tsx','php','py','java','kt','kts','swift','dart','gradle','properties','ini','env','sh','bat','sql','log','plist','conf','cfg','toml','vue','c','h','cpp','m','rb','go','rs','gitignore','txt'], true);
}

function mime_of(string $path): string {
    $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
    $map = [
        'apk' => 'application/vnd.android.package-archive', 'aab' => 'application/octet-stream', 'ipa' => 'application/octet-stream',
        'html' => 'text/html', 'htm' => 'text/html', 'xhtml' => 'application/xhtml+xml',
        'css' => 'text/css', 'js' => 'text/javascript', 'mjs' => 'text/javascript', 'map' => 'application/json',
        'json' => 'application/json', 'webmanifest' => 'application/manifest+json', 'xml' => 'application/xml',
        'txt' => 'text/plain', 'md' => 'text/plain', 'csv' => 'text/csv', 'yml' => 'text/plain', 'yaml' => 'text/plain',
        'svg' => 'image/svg+xml', 'png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'gif' => 'image/gif',
        'webp' => 'image/webp', 'avif' => 'image/avif', 'ico' => 'image/x-icon', 'bmp' => 'image/bmp',
        'mp4' => 'video/mp4', 'webm' => 'video/webm', 'mp3' => 'audio/mpeg', 'ogg' => 'audio/ogg', 'wav' => 'audio/wav', 'm4a' => 'audio/mp4',
        'pdf' => 'application/pdf', 'wasm' => 'application/wasm',
        'woff' => 'font/woff', 'woff2' => 'font/woff2', 'ttf' => 'font/ttf', 'otf' => 'font/otf',
    ];
    if (isset($map[$ext])) return $map[$ext];
    if (function_exists('mime_content_type')) { $m = @mime_content_type($path); if ($m) return $m; }
    return 'application/octet-stream';
}

/** Types sûrs à afficher dans le navigateur (le reste est forcé en téléchargement). */
function inline_safe(string $mime): bool {
    return (bool)preg_match('#^(image/(png|jpe?g|gif|webp|avif|bmp|x-icon)|video/|audio/|application/pdf|text/plain)#', $mime);
}

/** Types affichables en "lecture directe" (le reste est téléchargé). */
function raw_viewable(string $mime): bool {
    return (bool)preg_match('#^(text/|image/|video/|audio/|font/|application/(json|manifest\+json|xml|xhtml\+xml|pdf|wasm))#', $mime);
}

/** Contenu pouvant exécuter du script s'il est ouvert comme page (HTML, SVG, XML). */
function is_active_content(string $mime): bool {
    return (bool)preg_match('#^(text/html|image/svg\+xml|application/(xhtml\+xml|xml))#', $mime);
}

/**
 * Envoie un fichier avec support des requêtes partielles (Range).
 * $mode : true|'download' = téléchargement forcé ; false|'inline' = affichage des types sûrs ;
 *         'raw' = lecture directe (JSON, HTML, images…) pour navigateur / WebView / application.
 */
function send_file(string $path, $mode, ?string $name = null): void {
    if ($mode === true) $mode = 'download';
    if ($mode === false) $mode = 'inline';
    if (!is_file($path)) { http_response_code(404); exit('Fichier introuvable.'); }
    $name = $name ?? basename($path);
    $size = filesize($path);
    $mime = mime_of($path);
    $mtime = filemtime($path);
    while (ob_get_level()) ob_end_clean();
    header('X-Content-Type-Options: nosniff');

    if ($mode === 'raw') {
        $inline = raw_viewable($mime);
        $ctype = $mime . (preg_match('#^text/|json|xml|javascript#', $mime) ? '; charset=utf-8' : '');
        header('Access-Control-Allow-Origin: *');
        header('Access-Control-Expose-Headers: Content-Length, Content-Range, ETag');
        header('Cache-Control: public, max-age=60');
        // HTML/SVG sur le domaine du panneau : isolé (sandbox) pour protéger les sessions.
        if (is_active_content($mime) && !on_content_host()) {
            header('Content-Security-Policy: sandbox allow-scripts allow-forms allow-popups allow-popups-to-escape-sandbox allow-modals allow-downloads allow-presentation');
        }
        $etag = '"' . dechex($mtime) . '-' . dechex($size) . '"';
        header('ETag: ' . $etag);
        header('Last-Modified: ' . gmdate('D, d M Y H:i:s', $mtime) . ' GMT');
        if (trim($_SERVER['HTTP_IF_NONE_MATCH'] ?? '') === $etag) { http_response_code(304); exit; }
    } else {
        $inline = $mode === 'inline' && inline_safe($mime);
        header("Content-Security-Policy: default-src 'none'; img-src 'self'; media-src 'self'; style-src 'unsafe-inline'; sandbox");
        $risky = (bool)preg_match('#^(text/|image/svg|application/(xhtml|javascript|xml))#', $mime);
        $ctype = $inline ? $mime : ($risky ? 'application/octet-stream' : $mime);
        header('Cache-Control: private, max-age=0');
    }
    header('Content-Type: ' . $ctype);
    $disp = $inline ? 'inline' : 'attachment';
    $ascii = preg_replace('/[^\x20-\x7E]|["\\\\]/', '_', $name);
    header("Content-Disposition: $disp; filename=\"$ascii\"; filename*=UTF-8''" . rawurlencode($name));
    header('Accept-Ranges: bytes');
    $start = 0; $end = $size - 1;
    if ($size > 0 && isset($_SERVER['HTTP_RANGE']) && preg_match('/bytes=(\d*)-(\d*)/', $_SERVER['HTTP_RANGE'], $m)) {
        if ($m[1] === '' && $m[2] !== '') { $start = max(0, $size - (int)$m[2]); }
        else { $start = (int)$m[1]; if ($m[2] !== '') $end = min((int)$m[2], $size - 1); }
        if ($start > $end || $start >= $size) { http_response_code(416); header("Content-Range: bytes */$size"); exit; }
        http_response_code(206);
        header("Content-Range: bytes $start-$end/$size");
    }
    header('Content-Length: ' . max(0, $end - $start + 1));
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'HEAD' || $size === 0) exit;
    $fp = fopen($path, 'rb');
    fseek($fp, $start);
    $left = $end - $start + 1;
    @set_time_limit(0);
    while ($left > 0 && !feof($fp)) {
        $chunk = fread($fp, (int)min(1024 * 256, $left));
        echo $chunk; flush();
        $left -= strlen($chunk);
    }
    fclose($fp);
    exit;
}

/** Envoie un dossier compressé en ZIP. */
function send_zip(string $dir, string $name): void {
    if (!class_exists('ZipArchive')) { http_response_code(500); exit('ZIP non disponible sur ce serveur.'); }
    @set_time_limit(0);
    $tmp = tempnam(sys_get_temp_dir(), 'yzip');
    $zip = new ZipArchive();
    $zip->open($tmp, ZipArchive::OVERWRITE);
    $base = strlen(rtrim($dir, '/')) + 1;
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST);
    $zip->addEmptyDir($name);
    foreach ($it as $f) {
        $local = $name . '/' . substr($f->getPathname(), $base);
        $f->isDir() ? $zip->addEmptyDir($local) : $zip->addFile($f->getPathname(), $local);
    }
    $zip->close();
    register_shutdown_function(fn() => @unlink($tmp));
    send_file($tmp, true, $name . '.zip');
}

/** Met à jour les liens partagés après renommage / déplacement. */
function shares_moved(int $uid, string $old, string $new): void {
    $st = db()->prepare('SELECT id, path FROM shares WHERE user_id = ?');
    $st->execute([$uid]);
    $up = db()->prepare('UPDATE shares SET path = ? WHERE id = ?');
    foreach ($st->fetchAll() as $r) {
        if ($r['path'] !== $old && strpos($r['path'], $old . '/') !== 0) continue;
        $up->execute([$new . substr($r['path'], strlen($old)), $r['id']]);
    }
}

function shares_deleted(int $uid, string $rel): void {
    $st = db()->prepare('SELECT id, path FROM shares WHERE user_id = ?');
    $st->execute([$uid]);
    $del = db()->prepare('DELETE FROM shares WHERE id = ?');
    foreach ($st->fetchAll() as $r) {
        if ($r['path'] === $rel || strpos($r['path'], $rel . '/') === 0) $del->execute([$r['id']]);
    }
}

/* ===================== Corbeille ===================== */

function trash_enabled(): bool { return setting('trash_enabled', '1') === '1'; }

function trash_dir(array $u): string {
    $d = cfg('storage_dir') . '/.trash/' . $u['folder'];
    if (!is_dir($d)) mkdir($d, 0750, true);
    return $d;
}

function trash_size(array $u): int {
    $st = db()->prepare('SELECT COALESCE(SUM(size), 0) FROM trash WHERE user_id = ?');
    $st->execute([$u['id']]);
    return (int)$st->fetchColumn();
}

/** Déplace un élément vers la corbeille (ou le supprime si la corbeille est désactivée). */
function trash_put(array $owner, string $rel, ?int $by = null): void {
    $abs = abs_path(user_root($owner), $rel);
    if (!file_exists($abs)) return;
    if (!trash_enabled()) { rm_rf($abs); return; }
    $stored = bin2hex(random_bytes(12));
    $size = dir_size($abs);
    $isDir = is_dir($abs) ? 1 : 0;
    if (!@rename($abs, trash_dir($owner) . '/' . $stored)) throw new RuntimeException('Impossible de déplacer vers la corbeille.');
    db()->prepare('INSERT INTO trash(user_id, original_path, name, is_dir, size, stored_name, deleted_by) VALUES(?,?,?,?,?,?,?)')
        ->execute([$owner['id'], $rel, basename($rel), $isDir, $size, $stored, $by]);
}

function trash_item(array $owner, int $id): ?array {
    $st = db()->prepare('SELECT * FROM trash WHERE id = ? AND user_id = ?');
    $st->execute([$id, $owner['id']]);
    return $st->fetch() ?: null;
}

/** Restaure un élément à son emplacement d'origine (renomme en cas de conflit). Retourne le chemin final. */
function trash_restore(array $owner, int $id): string {
    $it = trash_item($owner, $id);
    if (!$it) throw new RuntimeException('Élément introuvable dans la corbeille.');
    $src = trash_dir($owner) . '/' . $it['stored_name'];
    if (!file_exists($src)) {
        db()->prepare('DELETE FROM trash WHERE id = ?')->execute([$id]);
        throw new RuntimeException('Le contenu de cet élément a disparu du disque.');
    }
    if (setting('trash_counts_quota', '1') !== '1' && user_usage($owner) + (int)$it['size'] > user_quota($owner)) {
        throw new RuntimeException('Espace insuffisant pour restaurer « ' . $it['name'] . ' ».');
    }
    $root = user_root($owner);
    $parent = dirname($it['original_path']) === '.' ? '' : dirname($it['original_path']);
    $parentAbs = abs_path($root, $parent);
    if (!is_dir($parentAbs)) mkdir($parentAbs, 0750, true);
    $name = unique_name($parentAbs, $it['name']);
    rename($src, $parentAbs . '/' . $name);
    db()->prepare('DELETE FROM trash WHERE id = ?')->execute([$id]);
    return ltrim($parent . '/' . $name, '/');
}

function trash_purge_item(array $owner, array $it): void {
    rm_rf(trash_dir($owner) . '/' . $it['stored_name']);
    db()->prepare('DELETE FROM trash WHERE id = ?')->execute([$it['id']]);
}

/** Supprime définitivement les éléments plus anciens que le délai réglé (tous les membres). */
function trash_autopurge(): int {
    $days = max(1, (int)setting('trash_days', '30'));
    $st = db()->prepare('SELECT t.*, u.folder FROM trash t JOIN users u ON u.id = t.user_id WHERE t.deleted_at < ?');
    $st->execute([db_time(-$days * 86400)]);
    $n = 0;
    foreach ($st->fetchAll() as $it) { trash_purge_item(['folder' => $it['folder'], 'id' => $it['user_id']], $it); $n++; }
    return $n;
}

/* ===================== Copie ===================== */

function copy_rec(string $src, string $dst): void {
    if (is_dir($src)) {
        mkdir($dst, 0750, true);
        foreach (new FilesystemIterator($src) as $f) copy_rec($f->getPathname(), $dst . '/' . $f->getFilename());
    } else {
        if (!copy($src, $dst)) throw new RuntimeException('Copie impossible : ' . basename($src));
    }
}

/** Nom de copie : "photo (copie).png", "photo (copie 2).png"… */
function copy_name(string $dir, string $name): string {
    if (!file_exists($dir . '/' . $name)) return $name;
    $ext = pathinfo($name, PATHINFO_EXTENSION);
    $isFile = is_file($dir . '/' . $name) && $ext !== '';
    $base = $isFile ? substr($name, 0, -strlen($ext) - 1) : $name;
    for ($i = 1; ; $i++) {
        $cand = $base . ' (copie' . ($i > 1 ? ' ' . $i : '') . ')' . ($isFile ? '.' . $ext : '');
        if (!file_exists($dir . '/' . $cand)) return $cand;
    }
}

/* ===================== Recherche ===================== */

function search_files(string $root, string $q, int $limit = 200): array {
    $q = mb_strtolower(trim($q));
    $out = [];
    if ($q === '') return $out;
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST);
    foreach ($it as $f) {
        $name = $f->getFilename();
        if (preg_match('/^\.ht/i', $name)) continue;
        if (mb_strpos(mb_strtolower($name), $q) === false) continue;
        $rel = str_replace('\\', '/', substr($f->getPathname(), strlen($root) + 1));
        $isDir = $f->isDir();
        $out[] = ['name' => $name, 'path' => $rel, 'dir' => $isDir, 'size' => $isDir ? null : $f->getSize(),
                  'mtime' => $f->getMTime(), 'ext' => $isDir ? '' : strtolower($f->getExtension())];
        if (count($out) >= $limit) break;
    }
    return $out;
}

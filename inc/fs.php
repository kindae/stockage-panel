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

function user_usage(array $u): int { return dir_size(user_root($u)); }

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
        'apk' => 'application/vnd.android.package-archive',
        'aab' => 'application/octet-stream',
        'ipa' => 'application/octet-stream',
        'json' => 'application/json', 'svg' => 'image/svg+xml', 'css' => 'text/css',
        'js' => 'text/javascript', 'mp4' => 'video/mp4', 'webp' => 'image/webp',
    ];
    if (isset($map[$ext])) return $map[$ext];
    if (function_exists('mime_content_type')) { $m = @mime_content_type($path); if ($m) return $m; }
    return 'application/octet-stream';
}

/** Types sûrs à afficher dans le navigateur (le reste est forcé en téléchargement). */
function inline_safe(string $mime): bool {
    return (bool)preg_match('#^(image/(png|jpe?g|gif|webp|bmp|x-icon)|video/|audio/|application/pdf|text/plain)#', $mime);
}

/** Envoie un fichier avec support des requêtes partielles (Range). */
function send_file(string $path, bool $download, ?string $name = null): void {
    if (!is_file($path)) { http_response_code(404); exit('Fichier introuvable.'); }
    $name = $name ?? basename($path);
    $size = filesize($path);
    $mime = mime_of($path);
    $inline = !$download && inline_safe($mime);
    while (ob_get_level()) ob_end_clean();
    header('X-Content-Type-Options: nosniff');
    header("Content-Security-Policy: default-src 'none'; img-src 'self'; media-src 'self'; style-src 'unsafe-inline'; sandbox");
    $risky = (bool)preg_match('#^(text/|image/svg|application/(xhtml|javascript|xml))#', $mime);
    header('Content-Type: ' . ($inline ? $mime : ($risky ? 'application/octet-stream' : $mime)));
    $disp = $inline ? 'inline' : 'attachment';
    $ascii = preg_replace('/[^\x20-\x7E]|["\\\\]/', '_', $name);
    header("Content-Disposition: $disp; filename=\"$ascii\"; filename*=UTF-8''" . rawurlencode($name));
    header('Accept-Ranges: bytes');
    header('Cache-Control: private, max-age=0');
    $start = 0; $end = $size - 1;
    if (isset($_SERVER['HTTP_RANGE']) && preg_match('/bytes=(\d*)-(\d*)/', $_SERVER['HTTP_RANGE'], $m)) {
        if ($m[1] === '' && $m[2] !== '') { $start = max(0, $size - (int)$m[2]); }
        else { $start = (int)$m[1]; if ($m[2] !== '') $end = min((int)$m[2], $size - 1); }
        if ($start > $end || $start >= $size) { http_response_code(416); header("Content-Range: bytes */$size"); exit; }
        http_response_code(206);
        header("Content-Range: bytes $start-$end/$size");
    }
    header('Content-Length: ' . ($end - $start + 1));
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'HEAD') exit;
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
    $st = db()->prepare('SELECT id, path FROM shares WHERE user_id = ? AND (path = ? OR path LIKE ?)');
    $st->execute([$uid, $old, str_replace(['%', '_'], ['\%', '\_'], $old) . '/%']);
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

<?php
/* ── Auth guard ─────────────────────────────────────────────────────── */
if (session_status() === PHP_SESSION_NONE) session_start();

if (!file_exists(__DIR__ . '/dash_config.php')) {
    header('Content-Type: application/json'); http_response_code(503);
    echo json_encode(['error' => 'Dashboard not configured.']); exit;
}

if (empty($_SESSION['logged_in'])) {
    if (isset($_COOKIE['dash_auth'])) require_once 'auth.php';
    if (empty($_SESSION['logged_in'])) {
        header('Content-Type: application/json'); http_response_code(401);
        echo json_encode(['error' => 'Session expired — please reload the page and log in again.']); exit;
    }
}

require_once __DIR__ . '/db.php';

/* ── Per-user setup ──────────────────────────────────────────────────── */
$_dashUser = $_SESSION['sub_user'] ?? $_SESSION['dash_user'] ?? $_SESSION['username'] ?? 'admin';
$_dashUser = preg_replace('/[^a-zA-Z0-9_-]/', '', strtolower($_dashUser)) ?: 'admin';

$_db     = getDashDb();
$baseDir = __DIR__ . '/uploads/docs/' . $_dashUser;
if (!is_dir($baseDir)) mkdir($baseDir, 0755, true);

/* ── Helpers ──────────────────────────────────────────────────────────── */

function newFolderDirKey(): string {
    return 'fd_' . time() . '_' . substr(bin2hex(random_bytes(3)), 0, 6);
}

function validDirKey(string $dk): bool {
    return $dk !== '' && preg_match('/^[a-zA-Z0-9_-]+$/', $dk) === 1;
}

function removeDir(string $dir): bool {
    if (!is_dir($dir)) return true;
    foreach (scandir($dir) as $f) {
        if ($f === '.' || $f === '..') continue;
        $path = $dir . '/' . $f;
        if (is_dir($path)) removeDir($path);
        else unlink($path);
    }
    return rmdir($dir);
}

function formatSize(int $bytes): string {
    if ($bytes < 1024)       return $bytes . ' B';
    if ($bytes < 1048576)    return round($bytes / 1024, 1) . ' KB';
    if ($bytes < 1073741824) return round($bytes / 1048576, 1) . ' MB';
    return round($bytes / 1073741824, 1) . ' GB';
}

function fileIcon(string $ext): string {
    $map = [
        'pdf'=>'📄','doc'=>'📝','docx'=>'📝','xls'=>'📊','xlsx'=>'📊',
        'ppt'=>'📋','pptx'=>'📋','txt'=>'📃','md'=>'📃','csv'=>'📊','rtf'=>'📝','odt'=>'📝',
        'zip'=>'🗜','tar'=>'🗜','gz'=>'🗜','rar'=>'🗜','7z'=>'🗜','bz2'=>'🗜',
        'jpg'=>'🖼','jpeg'=>'🖼','png'=>'🖼','gif'=>'🖼','webp'=>'🖼','svg'=>'🖼','bmp'=>'🖼',
        'tiff'=>'🖼','tif'=>'🖼','heic'=>'🖼','heif'=>'🖼',
        'mp4'=>'🎬','mov'=>'🎬','avi'=>'🎬','mkv'=>'🎬','wmv'=>'🎬','flv'=>'🎬',
        'webm'=>'🎬','mpeg'=>'🎬','mpg'=>'🎬','m4v'=>'🎬',
        'mp3'=>'🎵','wav'=>'🎵','flac'=>'🎵','ogg'=>'🎵','aac'=>'🎵','m4a'=>'🎵','wma'=>'🎵','opus'=>'🎵',
        'json'=>'🔧','xml'=>'🔧','yaml'=>'🔧','yml'=>'🔧',
        'sh'=>'⚙️','bash'=>'⚙️','conf'=>'⚙️','cfg'=>'⚙️',
        'iso'=>'💿','dmg'=>'💿','exe'=>'💿','msi'=>'💿','deb'=>'💿','rpm'=>'💿',
        'html'=>'🌐','htm'=>'🌐','css'=>'🎨','js'=>'📜',
        'psd'=>'🎨','ai'=>'🎨','indd'=>'🎨','sketch'=>'🎨','fig'=>'🎨',
    ];
    return $map[$ext] ?? '📎';
}

function fileTypeGroup(string $ext): string {
    static $map = null;
    if (!$map) $map = [
        'jpg'=>'1','jpeg'=>'1','png'=>'1','gif'=>'1','webp'=>'1','svg'=>'1','bmp'=>'1','tiff'=>'1','tif'=>'1','heic'=>'1','heif'=>'1','psd'=>'1','ai'=>'1','sketch'=>'1','fig'=>'1',
        'mp4'=>'2','mov'=>'2','avi'=>'2','mkv'=>'2','wmv'=>'2','flv'=>'2','webm'=>'2','mpeg'=>'2','mpg'=>'2','m4v'=>'2',
        'mp3'=>'3','wav'=>'3','flac'=>'3','ogg'=>'3','aac'=>'3','m4a'=>'3','wma'=>'3','opus'=>'3',
        'pdf'=>'4','doc'=>'4','docx'=>'4','rtf'=>'4','odt'=>'4','txt'=>'4','md'=>'4','csv'=>'4','xls'=>'4','xlsx'=>'4','ppt'=>'4','pptx'=>'4','html'=>'4','htm'=>'4',
        'zip'=>'5','tar'=>'5','gz'=>'5','rar'=>'5','7z'=>'5','bz2'=>'5',
        'json'=>'6','xml'=>'6','yaml'=>'6','yml'=>'6','sh'=>'6','bash'=>'6','conf'=>'6','cfg'=>'6','css'=>'6','js'=>'6',
        'iso'=>'7','dmg'=>'7','exe'=>'7','msi'=>'7','deb'=>'7','rpm'=>'7',
    ];
    return $map[$ext] ?? '8';
}

/**
 * Scan a folder directory on disk and return file rows.
 * This is the ONLY source of truth for what files live in a folder.
 * MySQL stores metadata (label, icon, pin) — the filesystem stores files.
 * Two different dir_keys → two different physical directories → guaranteed isolation.
 */
function fsFilesForFolder(string $dirPath, string $dirKey): array {
    if (!is_dir($dirPath)) return [];
    $out = [];
    foreach (glob($dirPath . '/*') ?: [] as $fp) {
        if (!is_file($fp)) continue;
        $name = basename($fp);
        if ($name === '_meta.json') continue;
        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        $out[] = [
            'filename' => $name,
            'size'     => (int)filesize($fp),
            'ext'      => $ext,
            'mtime'    => (int)filemtime($fp),
            'dir_key'  => $dirKey,
        ];
    }
    return $out;
}

/** Build the file-list response array from a filesystem row. */
function buildFileEntry(array $row, string $dirKey): array {
    $name = $row['filename'] ?? $row['name'] ?? '';
    $ext  = $row['ext']  ?? strtolower(pathinfo($name, PATHINFO_EXTENSION));
    $sz   = (int)($row['size']  ?? 0);
    $mt   = (int)($row['mtime'] ?? 0);
    return [
        'name'       => $name,
        'size'       => $sz,
        'size_h'     => formatSize($sz),
        'ext'        => $ext,
        'icon'       => fileIcon($ext),
        'type_group' => fileTypeGroup($ext),
        'mtime'      => $mt,
        'folder'     => $dirKey,
        'url'        => 'download.php?action=get&folder=' . urlencode($dirKey) . '&file=' . urlencode($name),
    ];
}

/* ── Router ──────────────────────────────────────────────────────────── */
header('Content-Type: application/json');
$action = $_GET['action'] ?? $_POST['action'] ?? 'list';

// ── set_folder_pin ─────────────────────────────────────────────────────────
if ($action === 'set_folder_pin') {
    $dk      = preg_replace('/[^a-zA-Z0-9_-]/', '', $_POST['folder'] ?? '');
    $type    = preg_replace('/[^a-z]/', '', $_POST['pinned_type'] ?? 'all');
    $allowed = ['all','image','video','audio','doc','archive','other'];
    if (!$dk)                       { echo json_encode(['ok'=>false,'error'=>'No folder']); exit; }
    if (!in_array($type, $allowed)) $type = 'all';
    dashDocSetPin($_db, $_dashUser, $dk, $type);
    echo json_encode(['ok'=>true,'pinned_type'=>$type]);
    exit;
}

// ── files (single folder) ──────────────────────────────────────────────────
if ($action === 'files') {
    $dk = preg_replace('/[^a-zA-Z0-9_-]/', '', $_GET['folder'] ?? '');
    if (!$dk) { echo json_encode(['ok'=>false,'error'=>'No folder']); exit; }

    // Metadata from MySQL (label/icon/pin) — files always read from disk
    $folder   = dashDocFolder($_db, $_dashUser, $dk);
    $dirPath  = $baseDir . '/' . $dk;
    $rawFiles = fsFilesForFolder($dirPath, $dk);   // ← filesystem, not MySQL

    $files = [];
    foreach ($rawFiles as $row) $files[] = buildFileEntry($row, $dk);
    usort($files, fn($a,$b)=>strcmp($a['type_group'],$b['type_group'])?:strcasecmp($a['name'],$b['name']));

    echo json_encode([
        'ok'          => true,
        'dir_key'     => $dk,
        'label'       => $folder['label']    ?? $dk,
        'icon'        => $folder['icon']     ?? '📁',
        'pinned_type' => $folder['pin_type'] ?? 'all',
        'files'       => $files,
    ]);
    exit;
}

// ── list ───────────────────────────────────────────────────────────────────
if ($action === 'list') {
    $folders = dashDocFolders($_db, $_dashUser);
    $result  = [];
    foreach ($folders as $f) {
        $dk      = $f['dir_key'];
        $dirPath = $baseDir . '/' . $dk;
        $rawFiles = fsFilesForFolder($dirPath, $dk);   // ← filesystem, not MySQL
        $files = [];
        foreach ($rawFiles as $row) $files[] = buildFileEntry($row, $dk);
        usort($files, fn($a,$b)=>strcmp($a['type_group'],$b['type_group'])?:strcasecmp($a['name'],$b['name']));
        $result[] = [
            'id'          => $dk,
            'dir_key'     => $dk,
            'label'       => $f['label'],
            'icon'        => $f['icon']     ?? '📁',
            'pinned_type' => $f['pin_type'] ?? 'all',
            'path'        => $dk,
            'files'       => $files,
        ];
    }
    echo json_encode(['ok' => true, 'folders' => $result]);
    exit;
}

// ── add_folder ─────────────────────────────────────────────────────────────
if ($action === 'add_folder') {
    header('Content-Type: application/json');
    try {
        $label = htmlspecialchars(trim($_POST['label'] ?? ''), ENT_QUOTES);
        $icon  = trim($_POST['icon'] ?? '📁');
        if (!$label) { echo json_encode(['ok'=>false,'error'=>'Label required']); exit; }

        // Ensure base directory exists and is writable
        if (!is_dir($baseDir)) {
            if (!mkdir($baseDir, 0755, true)) {
                echo json_encode(['ok'=>false,'error'=>'Cannot create uploads directory: '.$baseDir.' — check PHP write permissions']); exit;
            }
        }
        if (!is_writable($baseDir)) {
            echo json_encode(['ok'=>false,'error'=>'Directory not writable: '.$baseDir]); exit;
        }

        $existing = dashDocFolders($_db, $_dashUser);
        $order    = count($existing);
        $dk       = newFolderDirKey();
        $dirPath  = $baseDir . '/' . $dk;

        if (!mkdir($dirPath, 0755, true)) {
            echo json_encode(['ok'=>false,'error'=>'mkdir failed for: '.$dirPath]); exit;
        }

        // Always write _meta.json so filesystem-only mode works
        $metaOk = file_put_contents($dirPath . '/_meta.json', json_encode(
            ['label'=>$label,'icon'=>$icon,'order'=>$order], JSON_PRETTY_PRINT
        ));
        if ($metaOk === false) {
            echo json_encode(['ok'=>false,'error'=>'Could not write _meta.json in '.$dirPath]); exit;
        }

        dashDocCreateFolder($_db, $_dashUser, $dk, $label, $icon, $order);

        echo json_encode(['ok' => true, 'dir' => $dk, 'path' => $dk]);
    } catch (Throwable $ex) {
        echo json_encode(['ok'=>false,'error'=>$ex->getMessage()]);
    }
    exit;
}

// ── rename_folder ──────────────────────────────────────────────────────────
if ($action === 'rename_folder') {
    $dk    = preg_replace('/[^a-zA-Z0-9_-]/', '', $_POST['folder'] ?? '');
    $label = htmlspecialchars(trim($_POST['label'] ?? ''), ENT_QUOTES);
    if (!$dk || !$label) { echo json_encode(['ok'=>false,'error'=>'Missing params']); exit; }
    dashDocRenameFolder($_db, $_dashUser, $dk, $label);
    // Keep _meta.json in sync
    $mf = $baseDir . '/' . $dk . '/_meta.json';
    if (file_exists($mf)) {
        $m = json_decode(@file_get_contents($mf) ?: '{}', true) ?: [];
        $m['label'] = $label;
        file_put_contents($mf, json_encode($m, JSON_PRETTY_PRINT));
    }
    echo json_encode(['ok' => true]);
    exit;
}

// ── clear_folder (delete all files, keep folder) ───────────────────────────
if ($action === 'clear_folder') {
    $dk = preg_replace('/[^a-zA-Z0-9_-]/', '', $_POST['folder'] ?? '');
    if (!$dk || !validDirKey($dk)) { echo json_encode(['ok'=>false,'error'=>'Invalid folder']); exit; }
    // Verify this folder belongs to this user
    $folder = dashDocFolder($_db, $_dashUser, $dk);
    if (!$folder) { echo json_encode(['ok'=>false,'error'=>'Folder not found']); exit; }
    $dirPath = $baseDir . '/' . $dk;
    $deleted = 0;
    if (is_dir($dirPath)) {
        foreach (glob($dirPath . '/*') ?: [] as $fp) {
            $name = basename($fp);
            if ($name === '_meta.json' || !is_file($fp)) continue;
            if (@unlink($fp)) {
                dashDocDeleteFile($_db, $_dashUser, $dk, $name);
                $deleted++;
            }
        }
    }
    echo json_encode(['ok' => true, 'deleted' => $deleted]);
    exit;
}

// ── delete_folder ──────────────────────────────────────────────────────────
if ($action === 'delete_folder') {
    $dk = preg_replace('/[^a-zA-Z0-9_-]/', '', $_POST['folder'] ?? $_POST['id'] ?? '');
    if (!$dk || !validDirKey($dk)) { echo json_encode(['error' => 'Invalid folder']); exit; }

    $dirPath = $baseDir . '/' . $dk;
    // Ownership is proven by the DB row (username + dir_key) — no sentinel file needed.
    // Always remove the physical directory so no ghost files remain on disk.
    if (is_dir($dirPath)) {
        removeDir($dirPath);
    }
    dashDocDeleteFolder($_db, $_dashUser, $dk);
    echo json_encode(['ok' => true]);
    exit;
}

// ── upload ─────────────────────────────────────────────────────────────────
if ($action === 'upload') {
    $dk = preg_replace('/[^a-zA-Z0-9_-]/', '', $_POST['folder'] ?? '');
    if (!$dk || !validDirKey($dk)) { echo json_encode(['ok'=>false,'errors'=>['Invalid folder']]); exit; }

    $dirPath = $baseDir . '/' . $dk;
    if (!is_dir($dirPath)) mkdir($dirPath, 0755, true);
    if (!file_exists($dirPath . '/_meta.json')) {
        file_put_contents($dirPath . '/_meta.json', json_encode(['label'=>$dk,'icon'=>'📁','order'=>0]));
        dashDocCreateFolder($_db, $_dashUser, $dk, $dk, '📁', 0);
    }

    $uploaded = 0; $errors = [];
    if (!empty($_FILES['files']['name'][0])) {
        foreach ($_FILES['files']['tmp_name'] as $i => $tmp) {
            $name = basename($_FILES['files']['name'][$i]);
            $name = preg_replace('/[^a-zA-Z0-9._\- ]/', '_', $name);
            $ext  = strtolower(pathinfo($name, PATHINFO_EXTENSION));
            if (in_array($ext, ['php','phtml','php3','php5','phar','shtml'])) {
                $errors[] = "$name: PHP files not allowed"; continue;
            }
            $dest = $dirPath . '/' . $name;
            if (move_uploaded_file($tmp, $dest)) {
                $sz = filesize($dest);
                $mt = filemtime($dest);
                dashDocUpsertFile($_db, $_dashUser, $dk, $name, $sz, $ext, $mt);
                $uploaded++;
            } else {
                $errors[] = "$name: upload failed";
            }
        }
    }
    echo json_encode(['ok' => true, 'uploaded' => $uploaded, 'errors' => $errors]);
    exit;
}

// ── delete (single file) ───────────────────────────────────────────────────
if ($action === 'delete') {
    $dk   = preg_replace('/[^a-zA-Z0-9_-]/', '', $_GET['folder'] ?? '');
    $file = basename($_GET['file'] ?? '');
    if (!$dk || !$file)         { echo json_encode(['error' => 'Invalid params']); exit; }
    if ($file === '_meta.json') { echo json_encode(['error' => 'Protected file']); exit; }
    $fp = $baseDir . '/' . $dk . '/' . $file;
    if (file_exists($fp) && is_file($fp)) {
        unlink($fp);
        dashDocDeleteFile($_db, $_dashUser, $dk, $file);
        echo json_encode(['ok' => true]);
    } else {
        dashDocDeleteFile($_db, $_dashUser, $dk, $file); // clean up orphaned DB row
        echo json_encode(['ok' => true, 'note' => 'file not found on disk']);
    }
    exit;
}

// ── get (download / serve) ─────────────────────────────────────────────────
if ($action === 'get') {
    $dk   = preg_replace('/[^a-zA-Z0-9_-]/', '', $_GET['folder'] ?? '');
    $file = basename($_GET['file'] ?? '');
    if (!$dk || !$file || $file === '_meta.json') {
        header('HTTP/1.1 404 Not Found'); header('Content-Type: application/json');
        echo json_encode(['error' => 'Not found']); exit;
    }
    $fp = $baseDir . '/' . $dk . '/' . $file;
    if (!file_exists($fp) || !is_file($fp)) {
        header('HTTP/1.1 404 Not Found'); header('Content-Type: application/json');
        echo json_encode(['error' => 'File not found']); exit;
    }
    header('Content-Description: File Transfer');
    header('Content-Type: application/octet-stream');
    header('Content-Disposition: attachment; filename="' . addslashes($file) . '"');
    header('Content-Length: ' . filesize($fp));
    header('Cache-Control: no-cache');
    readfile($fp);
    exit;
}

// ── wipe_doc_folders (admin — clean slate) ─────────────────────────────────
if ($action === 'wipe_doc_folders') {
    // Delete all filesystem directories for this user
    if (is_dir($baseDir)) {
        foreach (scandir($baseDir) as $entry) {
            if ($entry[0] === '.') continue;
            $dp = $baseDir . '/' . $entry;
            if (!is_dir($dp)) continue;
            // Only remove managed folders (have _meta.json) or empty dirs
            $mf = $dp . '/_meta.json';
            if (file_exists($mf) || count(glob($dp . '/*')) === 0) {
                removeDir($dp);
            }
        }
    }
    // Wipe MySQL records for this user
    if ($_db) {
        $_db->prepare('DELETE FROM dash_doc_files   WHERE username=?')->execute([$_dashUser]);
        $_db->prepare('DELETE FROM dash_doc_folders WHERE username=?')->execute([$_dashUser]);
        // Also remove migration_done flag so a fresh import can be triggered manually
    }
    echo json_encode(['ok' => true, 'msg' => 'All doc folders and files wiped for user: ' . $_dashUser]);
    exit;
}

// ── debug ──────────────────────────────────────────────────────────────────
if ($action === 'debug') {
    $folders = dashDocFolders($_db, $_dashUser);
    $out = [];
    foreach ($folders as $f) {
        $dk      = $f['dir_key'];
        $dirPath = $baseDir . '/' . $dk;
        $files   = fsFilesForFolder($dirPath, $dk);
        $out[] = [
            'dir_key'   => $dk,
            'label'     => $f['label'],
            'dir_exists'=> is_dir($dirPath),
            'files'     => array_column($files, 'filename'),
        ];
    }
    echo json_encode([
        'ok'      => true,
        'backend' => $_db ? 'mysql' : 'filesystem',
        'user'    => $_dashUser,
        'base'    => $baseDir,
        'folders' => $out,
    ], JSON_PRETTY_PRINT);
    exit;
}

echo json_encode(['error' => 'Unknown action']);

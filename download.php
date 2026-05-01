<?php
/* ── Auth guard: always returns JSON, never redirects to HTML pages ─────
   This prevents the "Unexpected token '<'" error when fetch() follows a
   Location redirect and gets login.php/setup.php HTML instead of JSON. */
if (session_status() === PHP_SESSION_NONE) session_start();

// If setup hasn't run yet, say so in JSON
if (!file_exists(__DIR__ . '/dash_config.php')) {
    header('Content-Type: application/json'); http_response_code(503);
    echo json_encode(['error' => 'Dashboard not configured.']); exit;
}

// Check session; also accept the remember-me cookie by loading auth helpers
// without triggering auth.php's own redirect-on-failure logic.
if (empty($_SESSION['logged_in'])) {
    if (isset($_COOKIE['dash_auth'])) {
        require_once 'auth.php';
    }
    if (empty($_SESSION['logged_in'])) {
        header('Content-Type: application/json'); http_response_code(401);
        echo json_encode(['error' => 'Session expired — please reload the page and log in again.']); exit;
    }
}

/* ── Document download / upload / delete handler ─────────────────────
   Per-user isolation: each user's files live under uploads/docs/USERNAME/
   Each folder inside that user dir is guaranteed to have a unique directory
   name (ensured at add_folder time) so two folders can never share files.
─────────────────────────────────────────────────────────────────────── */

$_dashUser = $_SESSION['sub_user'] ?? $_SESSION['dash_user'] ?? $_SESSION['username'] ?? 'admin';
$_dashUser = preg_replace('/[^a-zA-Z0-9_-]/', '', strtolower($_dashUser)) ?: 'admin';
$_isAdmin  = !empty($_SESSION['sub_role']) && $_SESSION['sub_role'] === 'admin'
             || empty($_SESSION['sub_user']) && !empty($_SESSION['logged_in']);

// Per-user document store — each user sees only their own folders and files.
$baseDir    = __DIR__ . '/uploads/docs/' . $_dashUser;
$configFile = __DIR__ . '/dash_docfolders_' . $_dashUser . '.json';

if (!is_dir($baseDir)) mkdir($baseDir, 0755, true);

// Load/save folder config — also auto-deduplicates paths to fix any existing collision
function loadFolders() {
    global $configFile;
    $d = @json_decode(@file_get_contents($configFile) ?: '[]', true) ?: [];
    if (empty($d)) {
        $d = [['id'=>'docs','label'=>'Documents','path'=>'docs','icon'=>'📄']];
        saveFolders($d);
        return $d;
    }
    // Deduplicate: if two folders share the same path, rename the second
    $seen = []; $changed = false;
    foreach ($d as &$f) {
        if (empty($f['path'])) {
            // empty path → derive from id or timestamp
            $f['path'] = preg_replace('/[^a-z0-9_-]/', '', strtolower($f['id'] ?? '')) ?: 'folder-'.time();
            $changed = true;
        }
        $base = $f['path']; $n = 2;
        while (in_array($f['path'], $seen)) {
            $f['path'] = $base . '-' . $n++;
            $changed = true;
        }
        $seen[] = $f['path'];
    }
    unset($f);
    if ($changed) saveFolders($d);
    return $d;
}
function saveFolders($data) {
    global $configFile;
    file_put_contents($configFile, json_encode($data, JSON_PRETTY_PRINT));
}

function safePath($base, $rel) {
    $rel  = ltrim(preg_replace('/[\/\\\\]+/', '/', $rel), '/');
    if (strpos($rel, '..') !== false || strpos($rel, '/') !== false) return false;
    return $base . '/' . $rel;
}

function folderPath($base, $folder) {
    $folder = preg_replace('/[^a-zA-Z0-9_-]/', '', $folder);
    if (!$folder) return false; // never allow empty folder name
    $path = $base . '/' . $folder;
    if (!is_dir($path)) mkdir($path, 0755, true);
    return $path;
}

header('Content-Type: application/json');
$action = $_GET['action'] ?? 'list';

if ($action === 'list') {
    $folders = loadFolders();
    $result  = [];
    foreach ($folders as $f) {
        // Use ID-based dir if available, otherwise fall back to path (backward compat)
        $dirKey = isset($f['dir']) ? preg_replace('/[^a-zA-Z0-9_-]/', '', $f['dir']) : $f['path'];
        $dir = folderPath($baseDir, $dirKey);
        if ($dir === false) continue;
        $files = [];
        foreach (glob($dir . '/*') ?: [] as $fp) {
            if (is_file($fp)) {
                $name = basename($fp);
                $ext  = strtolower(pathinfo($name, PATHINFO_EXTENSION));
                $files[] = [
                    'name'       => $name,
                    'size'       => filesize($fp),
                    'size_h'     => formatSize(filesize($fp)),
                    'ext'        => $ext,
                    'icon'       => fileIcon($ext),
                    'type_group' => fileTypeGroup($ext),
                    'mtime'      => filemtime($fp),
                    'folder'     => $dirKey,
                    'url'        => 'download.php?action=get&folder='.urlencode($dirKey).'&file='.urlencode($name),
                ];
            }
        }
        usort($files, function($a, $b) {
            $cmp = strcmp($a['type_group'], $b['type_group']);
            return $cmp !== 0 ? $cmp : strcasecmp($a['name'], $b['name']);
        });
        $result[] = [
            'id'      => $f['id'],
            'label'   => $f['label'],
            'path'    => $f['path'],
            'dir_key' => $dirKey,
            'icon'    => $f['icon'],
            'files'   => $files,
        ];
    }
    echo json_encode(['ok'=>true,'folders'=>$result]);
    exit;
}

if ($action === 'add_folder') {
    $label = htmlspecialchars(trim($_POST['label'] ?? ''), ENT_QUOTES);
    $icon  = trim($_POST['icon'] ?? '📁');
    if (!$label) { echo json_encode(['error'=>'Label required']); exit; }

    // Build a filesystem-safe display path from the label (used for readability)
    $pathKey = preg_replace('/[^a-z0-9_-]/', '', strtolower($label));
    if (!$pathKey) $pathKey = 'folder';

    $folders = loadFolders();
    $existingPaths = array_column($folders, 'path');
    $baseKey = $pathKey; $n = 2;
    while (in_array($pathKey, $existingPaths)) {
        $pathKey = $baseKey . '-' . $n++;
    }

    // dir = unique ID-based directory name — guarantees no two folders ever share a directory
    $folderId = 'f-' . time() . '-' . substr(md5($pathKey . mt_rand()), 0, 6);
    $dirKey   = 'fd' . substr(str_replace('-', '', $folderId), 1, 16);
    $folders[] = ['id' => $folderId, 'label' => $label, 'path' => $pathKey, 'dir' => $dirKey, 'icon' => $icon];
    saveFolders($folders);
    $dir = folderPath($baseDir, $dirKey);
    echo json_encode(['ok' => true, 'path' => $pathKey, 'dir' => $dirKey]);
    exit;
}

if ($action === 'delete_folder') {
    $fid = preg_replace('/[^a-zA-Z0-9_-]/', '', $_POST['id'] ?? '');
    $folders = loadFolders();
    $folders = array_values(array_filter($folders, fn($x) => $x['id'] !== $fid));
    saveFolders($folders);
    echo json_encode(['ok' => true]);
    exit;
}

if ($action === 'upload') {
    $folder = preg_replace('/[^a-zA-Z0-9_-]/', '', $_POST['folder'] ?? 'docs');
    if (!$folder) { echo json_encode(['ok'=>false,'errors'=>['Invalid folder']]); exit; }
    $dir = folderPath($baseDir, $folder);
    if (!$dir) { echo json_encode(['ok'=>false,'errors'=>['Invalid folder path']]); exit; }
    $uploaded = 0; $errors = [];
    if (!empty($_FILES['files']['name'][0])) {
        foreach ($_FILES['files']['tmp_name'] as $i => $tmp) {
            $name    = basename($_FILES['files']['name'][$i]);
            $name    = preg_replace('/[^a-zA-Z0-9._\- ]/', '_', $name);
            $destPath = $dir . '/' . $name;
            $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
            if (in_array($ext, ['php','phtml','php3','php5','phar','shtml'])) {
                $errors[] = "$name: PHP files not allowed";
                continue;
            }
            if (move_uploaded_file($tmp, $destPath)) $uploaded++;
            else $errors[] = "$name: upload failed";
        }
    }
    echo json_encode(['ok'=>true,'uploaded'=>$uploaded,'errors'=>$errors]);
    exit;
}

if ($action === 'delete') {
    $folder = preg_replace('/[^a-zA-Z0-9_-]/', '', $_GET['folder'] ?? 'docs');
    $file   = basename($_GET['file'] ?? '');
    $dir    = folderPath($baseDir, $folder);
    if (!$dir) { echo json_encode(['error'=>'Invalid folder']); exit; }
    $fp = $dir . '/' . $file;
    if (file_exists($fp) && is_file($fp)) { unlink($fp); echo json_encode(['ok'=>true]); }
    else echo json_encode(['error'=>'Not found']);
    exit;
}

if ($action === 'get') {
    $folder = preg_replace('/[^a-zA-Z0-9_-]/', '', $_GET['folder'] ?? 'docs');
    $file   = basename($_GET['file'] ?? '');
    $dir    = folderPath($baseDir, $folder);
    if (!$dir) { header('HTTP/1.1 404 Not Found'); header('Content-Type: application/json'); echo json_encode(['error'=>'Invalid folder']); exit; }
    $fp = $dir . '/' . $file;
    if (!$file || !file_exists($fp) || !is_file($fp)) {
        header('HTTP/1.1 404 Not Found');
        header('Content-Type: application/json');
        echo json_encode(['error'=>'File not found']); exit;
    }
    header('Content-Description: File Transfer');
    header('Content-Type: application/octet-stream');
    header('Content-Disposition: attachment; filename="'.addslashes($file).'"');
    header('Content-Length: '.filesize($fp));
    header('Cache-Control: no-cache');
    readfile($fp);
    exit;
}

echo json_encode(['error'=>'Unknown action']);

/* ── Helpers ───────────────────────────────────────────────────────── */
function formatSize($bytes) {
    if ($bytes < 1024) return $bytes.' B';
    if ($bytes < 1048576) return round($bytes/1024,1).' KB';
    if ($bytes < 1073741824) return round($bytes/1048576,1).' MB';
    return round($bytes/1073741824,1).' GB';
}
function fileIcon($ext) {
    $map = [
        'pdf'=>'📄','doc'=>'📝','docx'=>'📝','xls'=>'📊','xlsx'=>'📊',
        'ppt'=>'📋','pptx'=>'📋','txt'=>'📃','md'=>'📃','csv'=>'📊','rtf'=>'📝','odt'=>'📝',
        'zip'=>'🗜','tar'=>'🗜','gz'=>'🗜','rar'=>'🗜','7z'=>'🗜','bz2'=>'🗜',
        'jpg'=>'🖼','jpeg'=>'🖼','png'=>'🖼','gif'=>'🖼','webp'=>'🖼','svg'=>'🖼','bmp'=>'🖼','tiff'=>'🖼','tif'=>'🖼','heic'=>'🖼','heif'=>'🖼',
        'mp4'=>'🎬','mov'=>'🎬','avi'=>'🎬','mkv'=>'🎬','wmv'=>'🎬','flv'=>'🎬','webm'=>'🎬','mpeg'=>'🎬','mpg'=>'🎬','m4v'=>'🎬',
        'mp3'=>'🎵','wav'=>'🎵','flac'=>'🎵','ogg'=>'🎵','aac'=>'🎵','m4a'=>'🎵','wma'=>'🎵','opus'=>'🎵',
        'json'=>'🔧','xml'=>'🔧','yaml'=>'🔧','yml'=>'🔧',
        'sh'=>'⚙️','bash'=>'⚙️','conf'=>'⚙️','cfg'=>'⚙️',
        'iso'=>'💿','dmg'=>'💿','exe'=>'💿','msi'=>'💿','deb'=>'💿','rpm'=>'💿',
        'html'=>'🌐','htm'=>'🌐','css'=>'🎨','js'=>'📜',
        'psd'=>'🎨','ai'=>'🎨','indd'=>'🎨','sketch'=>'🎨','fig'=>'🎨',
    ];
    return $map[$ext] ?? '📎';
}

function fileTypeGroup($ext) {
    $images  = ['jpg','jpeg','png','gif','webp','svg','bmp','tiff','tif','heic','heif','psd','ai','sketch','fig'];
    $videos  = ['mp4','mov','avi','mkv','wmv','flv','webm','mpeg','mpg','m4v'];
    $audio   = ['mp3','wav','flac','ogg','aac','m4a','wma','opus'];
    $docs    = ['pdf','doc','docx','rtf','odt','txt','md','csv','xls','xlsx','ppt','pptx','html','htm'];
    $archives= ['zip','tar','gz','rar','7z','bz2'];
    $code    = ['json','xml','yaml','yml','sh','bash','conf','cfg','css','js','html','htm'];
    $installs= ['iso','dmg','exe','msi','deb','rpm'];
    if (in_array($ext,$images))   return '1_images';
    if (in_array($ext,$videos))   return '2_videos';
    if (in_array($ext,$audio))    return '3_audio';
    if (in_array($ext,$docs))     return '4_documents';
    if (in_array($ext,$archives)) return '5_archives';
    if (in_array($ext,$code))     return '6_code';
    if (in_array($ext,$installs)) return '7_installers';
    return '8_other';
}

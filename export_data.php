<?php
// export_data.php — generates a full user-data backup ZIP (WordPress-style).
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/db.php';
if (!isLoggedIn()) { http_response_code(403); exit; }

if (!class_exists('ZipArchive')) {
    header('Content-Type: application/json');
    echo json_encode(['ok'=>false,'error'=>'ZipArchive PHP extension not available on this server.']);
    exit;
}

$db   = getDashDb();
$user = getCurrentUsername();

// Collect all user data
$export = [
    'manifest' => [
        'version'     => '1.5',
        'export_date' => date('Y-m-d H:i:s'),
        'username'    => $user,
        'generator'   => 'Server Homepage Dashboard v1.5',
    ],
    'settings'     => dashGetSettings($db, $user),
    'links'        => dashGetLinks($db, $user),
    'profiles'     => dashGetProfiles($db, $user),
    'page_folders' => dashGetPageFolders($db, $user),
    'custom_bgs'   => dashGetCustomBgs($db, $user),
    'widgets' => [
        'html'      => dashGetWidgets($db, $user, 'html'),
        'rss'       => dashGetWidgets($db, $user, 'rss'),
        'camera'    => dashGetWidgets($db, $user, 'camera'),
        'calendar'  => dashGetWidgets($db, $user, 'calendar'),
        'countdown' => dashGetWidgets($db, $user, 'countdown'),
    ],
];

// Also export doc folder metadata
if ($db) {
    try {
        $s = $db->prepare('SELECT * FROM dash_doc_folders WHERE username=? ORDER BY sort_order');
        $s->execute([$user]);
        $export['doc_folders'] = $s->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) { $export['doc_folders'] = []; }
}

$tmpZip = tempnam(sys_get_temp_dir(), 'dash_export_') . '.zip';
$zip = new ZipArchive();
if ($zip->open($tmpZip, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
    header('Content-Type: application/json');
    echo json_encode(['ok'=>false,'error'=>'Could not create ZIP archive.']);
    exit;
}

// Data files
$zip->addFromString('manifest.json',      json_encode($export['manifest'],     JSON_PRETTY_PRINT));
$zip->addFromString('data/settings.json', json_encode($export['settings'],     JSON_PRETTY_PRINT));
$zip->addFromString('data/links.json',    json_encode($export['links'],        JSON_PRETTY_PRINT));
$zip->addFromString('data/profiles.json', json_encode($export['profiles'],     JSON_PRETTY_PRINT));
$zip->addFromString('data/page_folders.json', json_encode($export['page_folders'], JSON_PRETTY_PRINT));
$zip->addFromString('data/custom_bgs.json',   json_encode($export['custom_bgs'],   JSON_PRETTY_PRINT));
$zip->addFromString('data/widgets.json',  json_encode($export['widgets'],      JSON_PRETTY_PRINT));
$zip->addFromString('data/doc_folders.json',  json_encode($export['doc_folders'] ?? [], JSON_PRETTY_PRINT));

// Uploads directory (wallpapers, logos, doc files)
$uploadsBase = __DIR__ . '/uploads';
if (is_dir($uploadsBase)) {
    $iter = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($uploadsBase, RecursiveDirectoryIterator::SKIP_DOTS)
    );
    foreach ($iter as $file) {
        if (!$file->isFile()) continue;
        $rel = str_replace('\\', '/', substr($file->getPathname(), strlen(__DIR__) + 1));
        $zip->addFile($file->getPathname(), $rel);
    }
}

$zip->close();

$filename = 'dash_backup_' . preg_replace('/[^a-z0-9_]/', '_', strtolower($user)) . '_' . date('Y-m-d') . '.zip';
header('Content-Type: application/zip');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Content-Length: ' . filesize($tmpZip));
header('Cache-Control: no-store');
readfile($tmpZip);
@unlink($tmpZip);
exit;

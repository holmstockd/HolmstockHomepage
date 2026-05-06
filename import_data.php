<?php
// import_data.php — restores a full user-data backup ZIP created by export_data.php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/db.php';
header('Content-Type: application/json');
if (!isLoggedIn()) { http_response_code(403); echo '{"ok":false}'; exit; }
if (!class_exists('ZipArchive')) {
    echo json_encode(['ok'=>false,'error'=>'ZipArchive PHP extension not available.']); exit;
}
if (empty($_FILES['import_zip']['tmp_name']) || !is_uploaded_file($_FILES['import_zip']['tmp_name'])) {
    echo json_encode(['ok'=>false,'error'=>'No file uploaded.']); exit;
}

$db   = getDashDb();
$user = getCurrentUsername();
$zip  = new ZipArchive();
if ($zip->open($_FILES['import_zip']['tmp_name']) !== true) {
    echo json_encode(['ok'=>false,'error'=>'Could not open ZIP.']); exit;
}

// Read manifest
$manifestJson = $zip->getFromName('manifest.json');
if (!$manifestJson) { $zip->close(); echo json_encode(['ok'=>false,'error'=>'Not a valid dashboard backup (manifest.json missing).']); exit; }
$manifest = json_decode($manifestJson, true);

// Restore settings
$settingsJson = $zip->getFromName('data/settings.json');
if ($settingsJson) { $s = json_decode($settingsJson, true); if (is_array($s)) dashSetSettings($db, $user, $s); }

// Restore links
$linksJson = $zip->getFromName('data/links.json');
if ($linksJson) { $l = json_decode($linksJson, true); if (is_array($l)) dashSetLinks($db, $user, $l); }

// Restore profiles
$profilesJson = $zip->getFromName('data/profiles.json');
if ($profilesJson) {
    $profiles = json_decode($profilesJson, true);
    if (is_array($profiles) && $db) {
        foreach ($profiles as $p) {
            if (empty($p['profile_name'])) continue;
            $db->prepare('INSERT INTO dash_profiles (username,profile_name,theme,wallpaper_variant,size,stat_pos_json,saved)
                          VALUES (?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE
                          theme=VALUES(theme),wallpaper_variant=VALUES(wallpaper_variant),
                          size=VALUES(size),stat_pos_json=VALUES(stat_pos_json),saved=VALUES(saved)')
               ->execute([$user,$p['profile_name'],$p['theme']??'',$p['wallpaper_variant']??'',$p['size']??100,$p['stat_pos_json']??'{}',$p['saved']??'']);
        }
    }
}

// Restore page folders
$pfJson = $zip->getFromName('data/page_folders.json');
if ($pfJson) { $pf = json_decode($pfJson, true); if (is_array($pf)) dashSetPageFolders($db, $user, $pf); }

// Restore custom bgs
$bgJson = $zip->getFromName('data/custom_bgs.json');
if ($bgJson) { $bg = json_decode($bgJson, true); if (is_array($bg)) dashSetCustomBgs($db, $user, $bg); }

// Restore widgets
$wJson = $zip->getFromName('data/widgets.json');
if ($wJson) {
    $w = json_decode($wJson, true);
    if (is_array($w)) {
        foreach (['html','rss','camera','calendar','countdown'] as $t) {
            if (!empty($w[$t]) && is_array($w[$t])) dashSetWidgets($db, $user, $t, $w[$t]);
        }
    }
}

// Restore uploads (extract to uploads/)
for ($i = 0; $i < $zip->numFiles; $i++) {
    $name = $zip->getNameIndex($i);
    if (!str_starts_with($name, 'uploads/')) continue;
    $rel  = substr($name, 8); // strip 'uploads/'
    if (!$rel || str_ends_with($rel, '/')) continue;
    // Security: no path traversal
    $dest = realpath(__DIR__ . '/uploads') . '/' . ltrim($rel, '/');
    if (!str_starts_with($dest, realpath(__DIR__ . '/uploads'))) continue;
    @mkdir(dirname($dest), 0755, true);
    $data = $zip->getFromIndex($i);
    if ($data !== false) file_put_contents($dest, $data);
}

$zip->close();

echo json_encode([
    'ok'      => true,
    'message' => 'Backup restored successfully from ' . ($manifest['export_date'] ?? 'unknown date') . '. Reload the dashboard.',
    'source_version' => $manifest['version'] ?? '?',
    'source_user'    => $manifest['username'] ?? '?',
]);

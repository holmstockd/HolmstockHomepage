<?php
// check_update.php — fetches version.json from the configured update URL and returns status.
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/db.php';
header('Content-Type: application/json');
if (!isLoggedIn()) { http_response_code(403); echo '{"ok":false}'; exit; }

define('DASH_CURRENT_VERSION', '1.5');

$db  = getDashDb();
$user = getCurrentUsername();

$updateUrl = trim(dashGetSetting($db, $user, 'update_url', ''));
if (!$updateUrl) {
    // No default update server. Set your own in Settings -> Update if you
    // host a version.json somewhere; otherwise update checks stay disabled.
    echo json_encode(['ok'=>false,'error'=>'No update URL configured. Set one in Settings \u2192 Update.']);
    exit;
}
// Normalise: must end with version.json
if (!str_ends_with(strtolower(parse_url($updateUrl, PHP_URL_PATH) ?? ''), 'version.json')) {
    $updateUrl = rtrim($updateUrl, '/') . '/version.json';
}
if (!filter_var($updateUrl, FILTER_VALIDATE_URL)) {
    echo json_encode(['ok'=>false,'error'=>'Invalid update URL. Check Settings → Update.']);
    exit;
}

try {
    $ctx = stream_context_create(['http' => [
        'timeout'       => 8,
        'method'        => 'GET',
        'header'        => "User-Agent: DashHomepage/" . DASH_CURRENT_VERSION . "\r\n",
        'ignore_errors' => true,
    ]]);
    $raw = @file_get_contents($updateUrl, false, $ctx);
    if ($raw === false || $raw === '') {
        echo json_encode(['ok'=>false,'error'=>'Could not reach update server. Check your update URL.']);
        exit;
    }
    $data = json_decode($raw, true);
    if (!$data || empty($data['version'])) {
        echo json_encode(['ok'=>false,'error'=>'Received invalid version.json from update server.']);
        exit;
    }
    $remote  = trim($data['version']);
    $current = DASH_CURRENT_VERSION;
    $newer   = version_compare($remote, $current, '>');
    echo json_encode([
        'ok'        => true,
        'current'   => $current,
        'remote'    => $remote,
        'newer'     => $newer,
        'changelog' => $data['changelog']  ?? '',
        'zip_url'   => $data['zip_url']    ?? '',
        'sha256'    => $data['sha256']     ?? '',
        'zip_size'  => $data['zip_size']   ?? '',
        'released'  => $data['released']   ?? '',
    ]);
} catch (Throwable $e) {
    echo json_encode(['ok'=>false,'error'=>$e->getMessage()]);
}

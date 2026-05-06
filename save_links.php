<?php
require_once 'auth.php';
require_once 'db.php';
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo '{"ok":false}'; exit; }

$action = $_POST['action'] ?? 'save_links';
$db     = getDashDb();
$user   = getCurrentUsername();

// Read-only users cannot save links or page folders
if (getCurrentRole() === 'readonly' && in_array($action, ['save_links','save_page_folders'])) {
    http_response_code(403); echo '{"ok":false,"error":"Read-only user"}'; exit;
}

// ── Save page folder positions ──────────────────────────────────────────────
if ($action === 'save_page_folders') {
    $raw     = $_POST['folders_json'] ?? '[]';
    $folders = json_decode($raw, true);
    if (!is_array($folders)) { echo '{"ok":false}'; exit; }
    $out = [];
    foreach ($folders as $f) {
        $out[] = [
            'id'      => preg_replace('/[^a-z0-9_-]/', '', $f['id'] ?? ('pf-'.time())),
            'label'   => htmlspecialchars(mb_substr(trim($f['label'] ?? 'Folder'), 0, 60), ENT_QUOTES),
            'dir_key' => preg_replace('/[^a-zA-Z0-9_-]/', '', $f['dir_key'] ?? ''),
            'pos_x'   => max(0, (int)($f['pos_x'] ?? 0)),
            'pos_y'   => max(0, (int)($f['pos_y'] ?? 0)),
        ];
    }
    dashSetPageFolders($db, $user, $out);
    echo json_encode(['ok' => true]);
    exit;
}

// ── Save links ───────────────────────────────────────────────────────────────
if (!isset($_POST['links_json'])) { http_response_code(400); echo '{"ok":false}'; exit; }

$links = json_decode($_POST['links_json'], true);
if (!is_array($links)) { http_response_code(400); echo '{"ok":false,"error":"invalid json"}'; exit; }


$out = [];
foreach ($links as $sec) {
    $cards = [];
    foreach ($sec['cards'] ?? [] as $c) {
        $url = filter_var(trim($c['url'] ?? ''), FILTER_SANITIZE_URL);
        if (!$url) continue;
        $card = [
            'icon'  => mb_substr(trim($c['icon']  ?? '🔗'), 0, 8),
            'label' => mb_substr(trim($c['label'] ?? ''), 0, 80),
            'url'   => $url,
        ];
        if (!empty($c['icon_img']) && str_starts_with($c['icon_img'], 'uploads/icons/')) {
            $card['icon_img'] = preg_replace('/[^a-z0-9_\-\.\/]/', '', $c['icon_img']);
        }
        $cards[] = $card;
    }
    $allowed_views = ['list','grid','folder'];
    $w = (int)($sec['width'] ?? 0);
    $out[] = [
        'id'        => preg_replace('/[^a-z0-9_-]/', '', $sec['id'] ?? ('sec-'.time())),
        'title'     => mb_substr(trim($sec['title'] ?? ''), 0, 60),
        'icon'      => mb_substr(trim($sec['icon']  ?? '🔗'), 0, 8),
        'pos_x'     => max(0, (int)($sec['pos_x'] ?? 0)),
        'pos_y'     => max(0, (int)($sec['pos_y'] ?? 0)),
        'width'     => ($w >= 160 && $w <= 600) ? $w : 0,
        'locked'    => !empty($sec['locked']),
        'collapsed' => !empty($sec['collapsed']),
        'view'      => in_array($sec['view'] ?? 'list', $allowed_views) ? $sec['view'] : 'list',
        'cards'     => $cards,
    ];
}

dashSetLinks($db, $user, $out);

// Write a timestamped backup so the user can recover if links are ever lost.
// This is completely separate from the live data — only written on successful save.
$backupData = ['saved_at' => date('Y-m-d H:i:s'), 'count' => count($out), 'links' => $out];
if ($db) {
    dashSetSetting($db, $user, 'dash_links_backup', json_encode($backupData, JSON_UNESCAPED_UNICODE));
} else {
    @file_put_contents(__DIR__ . '/dash_links_backup.json',
        json_encode($backupData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX);
}

echo json_encode(['ok' => true, 'count' => count($out)]);

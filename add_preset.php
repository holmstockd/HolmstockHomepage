<?php
/**
 * add_preset.php — Append a preset column to the current user's dashboard.
 *
 * Accessible to ALL authenticated non-readonly users (not admin-only).
 * Used by the 📦 Presets button modal in index.php so sub-users can add
 * starter columns at any time, not just during the first-run wizard.
 *
 * POST params:
 *   preset_cat  — key from dashGetPresets() e.g. 'AI', 'Shopping', 'Gaming'
 *
 * Returns JSON: {ok:true, title:string, count:int}  on success
 *               {ok:false, error:string}             on failure
 */
require_once 'auth.php';
require_once 'db.php';
require_once 'presets.php';

header('Content-Type: application/json');

// Must be logged in
if (!isLoggedIn()) {
    http_response_code(401);
    echo '{"ok":false,"error":"Not logged in"}';
    exit;
}

// Readonly users cannot add columns
if (getCurrentRole() === 'readonly') {
    http_response_code(403);
    echo '{"ok":false,"error":"Read-only account — ask an admin to add columns for you"}';
    exit;
}

// POST only
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo '{"ok":false,"error":"POST required"}';
    exit;
}

$cat     = trim($_POST['preset_cat'] ?? '');
$presets = dashGetPresets();

if (!isset($presets[$cat])) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Unknown category: ' . $cat]);
    exit;
}

$db   = getDashDb();
$user = getCurrentUsername();
$info = $presets[$cat];

// Load current links for this specific user
$links = dashGetLinks($db, $user);

// De-dupe column title — never silently overwrite existing columns
$existingTitles = array_map(fn($s) => $s['title'] ?? '', $links);
$title = $cat;
if (in_array($title, $existingTitles, true)) {
    $n = 2;
    while (in_array($title . ' (' . $n . ')', $existingTitles, true)) {
        $n++;
    }
    $title = $title . ' (' . $n . ')';
}

// Build the card list from the preset definition
$cards = [];
foreach ($info['items'] as $it) {
    $url = filter_var(trim($it['url'] ?? ''), FILTER_SANITIZE_URL);
    if (!$url) continue;
    $cards[] = [
        'icon'  => mb_substr(trim($it['icon']  ?? '🔗'), 0, 8),
        'label' => mb_substr(trim($it['label'] ?? ''), 0, 80),
        'url'   => $url,
    ];
}

$links[] = [
    'id'    => 'sec-preset-' . time() . '-' . mt_rand(100, 999),
    'title' => $title,
    'icon'  => $info['icon'] ?? '📁',
    'cards' => $cards,
];

dashSetLinks($db, $user, $links);

echo json_encode([
    'ok'    => true,
    'title' => $title,
    'count' => count($cards),
]);

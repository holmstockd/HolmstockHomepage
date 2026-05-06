<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/db.php';
header('Content-Type: application/json');
if (!isLoggedIn()) { http_response_code(403); echo '{"ok":false}'; exit; }

$body = file_get_contents('php://input');
$data = json_decode($body, true);
if (!is_array($data)) { http_response_code(400); echo '{"ok":false}'; exit; }

$db   = getDashDb();
$user = getCurrentUsername();

foreach ($data as $k => $v) {
    $k = preg_replace('/[^a-z0-9_\-]/', '', $k);
    if (!$k) continue;
    dashSetSetting($db, $user, $k, $v === null ? null : (string)$v);
}

// ── Machine profile recall: update the device's last-used state ──────────
// Works in BOTH MySQL mode AND flat-file mode so each device stores its own
// theme/size/variant. Without this, loading a profile on PC overwrites the
// global hp-theme that Mac reads on reload — breaking per-device isolation.
$uuid = preg_replace('/[^0-9a-f\-]/', '', $_COOKIE['dash_machine_uuid'] ?? '');
if ($uuid && strlen($uuid) === 36) {
    $machineUpdate = [];
    if (isset($data['hp-theme']))   $machineUpdate['last_theme']   = (string)$data['hp-theme'];
    if (isset($data['hp-size']))    $machineUpdate['last_size']    = (int)$data['hp-size'];
    if (isset($data['hp-variant'])) $machineUpdate['last_variant'] = (string)$data['hp-variant'];
    // Variant can also arrive as "variant-<theme>" key
    foreach ($data as $k => $v) {
        if (strpos($k, 'variant-') === 0 && !isset($machineUpdate['last_variant']))
            $machineUpdate['last_variant'] = (string)$v;
    }
    if ($machineUpdate) {
        if ($db) {
            dashSaveMachine($db, $user, $uuid, $machineUpdate);
        } else {
            // Flat-file mode: write per-device state (never shared across devices)
            $mFile = __DIR__ . '/dash_machine_state_' . $uuid . '.json';
            $mData = json_decode(@file_get_contents($mFile) ?: '{}', true) ?: [];
            $mData = array_merge($mData, $machineUpdate);
            file_put_contents($mFile, json_encode($mData, JSON_PRETTY_PRINT), LOCK_EX);
        }
    }
}

echo '{"ok":true}';

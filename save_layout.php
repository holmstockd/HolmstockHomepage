<?php
require_once 'auth.php';
require_once 'db.php';
header('Content-Type: application/json');

$json = null;
$ct   = $_SERVER['CONTENT_TYPE'] ?? '';
if (strpos($ct, 'application/json') !== false) {
    $raw  = file_get_contents('php://input');
    $json = json_decode($raw, true);
}

$action = $json['action'] ?? $_POST['action'] ?? $_GET['action'] ?? '';
$db     = getDashDb();
$user   = getCurrentUsername();

// Current device UUID (used for per-device stat_pos in flat-file mode)
$_sl_uuid = preg_replace('/[^0-9a-f\-]/', '', $_COOKIE['dash_machine_uuid'] ?? '');
$_sl_mfile = (strlen($_sl_uuid) === 36)
    ? __DIR__ . '/dash_machine_state_' . $_sl_uuid . '.json'
    : null;

// Helper: read per-device machine state (flat-file mode only)
function _sl_mdata(): array {
    global $_sl_mfile;
    if (!$_sl_mfile) return [];
    return json_decode(@file_get_contents($_sl_mfile) ?: '{}', true) ?: [];
}

// Helper: write a field into the per-device machine state file
function _sl_mwrite(array $fields): void {
    global $_sl_mfile;
    if (!$_sl_mfile) return;
    $d = json_decode(@file_get_contents($_sl_mfile) ?: '{}', true) ?: [];
    $d = array_merge($d, $fields);
    file_put_contents($_sl_mfile, json_encode($d, JSON_PRETTY_PRINT), LOCK_EX);
}

// Read current stat positions for this user (prefer per-device in flat-file mode)
function _currentStatPos(string $user, ?PDO $db): string {
    if ($db) {
        $raw = dashGetSetting($db, $user, 'stat_pos_json', '{}');
        return $raw ?: '{}';
    }
    // Flat-file: prefer device-specific file so saving a profile captures THIS
    // device's positions, not another device's that loaded a profile more recently.
    $mData = _sl_mdata();
    if (!empty($mData['stat_pos_json'])) return $mData['stat_pos_json'];
    return @file_get_contents(__DIR__ . '/dash_stat_pos.json') ?: '{}';
}

switch ($action) {

    // ── LIST ─────────────────────────────────────────────────────────────────
    case 'list':
        $profiles = dashGetProfiles($db, $user);
        $out = [];
        foreach ($profiles as $p) {
            $out[] = [
                'name'              => $p['profile_name'],
                'saved'             => $p['saved'] ?? '',
                'theme'             => $p['theme'] ?? '',
                'wallpaper_variant' => $p['wallpaper_variant'] ?? '',
                'size'              => (int)($p['size'] ?? 100),
            ];
        }
        echo json_encode(['ok' => true, 'layouts' => $out, 'backend' => $db ? 'mysql' : 'json']);
        break;

    // ── SAVE ─────────────────────────────────────────────────────────────────
    case 'save':
        $name    = trim($json['name'] ?? $_POST['name'] ?? '');
        $theme   = trim($json['theme'] ?? $_POST['theme'] ?? '');
        $variant = trim($json['wallpaper_variant'] ?? $_POST['wallpaper_variant'] ?? '');
        $size    = max(60, min(200, (int)($json['size'] ?? $_POST['size'] ?? 100)));
        $statPos = isset($json['stat_pos_json']) && is_string($json['stat_pos_json'])
                   ? $json['stat_pos_json'] : _currentStatPos($user, $db);
        if (!$name) { echo json_encode(['ok'=>false,'error'=>'Missing profile name']); exit; }
        dashSaveProfile($db, $user, $name, $theme, $variant, $size, $statPos);
        echo json_encode(['ok' => true, 'backend' => $db ? 'mysql' : 'json']);
        break;

    // ── PATCH (silent auto-save from theme/drag changes) ────────────────────
    case 'patch':
        $name = trim($json['name'] ?? '');
        if (!$name) { echo json_encode(['ok'=>false,'error'=>'Missing name']); exit; }
        $fields = [];
        if (isset($json['theme']))             $fields['theme']             = trim($json['theme']);
        if (isset($json['wallpaper_variant'])) $fields['wallpaper_variant'] = trim($json['wallpaper_variant']);
        if (isset($json['size']))              $fields['size']              = max(60,min(200,(int)$json['size']));
        if (isset($json['stat_pos_json']))     $fields['stat_pos_json']     = $json['stat_pos_json'];
        if (!$fields) { echo json_encode(['ok'=>true]); exit; }
        dashPatchProfile($db, $user, $name, $fields);
        // In flat-file mode, also mirror stat_pos to this device's machine file
        // so that PHP reads the correct positions for THIS device on next reload.
        if (!$db && isset($fields['stat_pos_json'])) {
            _sl_mwrite(['stat_pos_json' => $fields['stat_pos_json']]);
        }
        echo json_encode(['ok'=>true]);
        break;

    // ── LOAD ─────────────────────────────────────────────────────────────────
    case 'load':
        $name = trim($json['name'] ?? $_POST['name'] ?? '');
        $p    = dashGetProfile($db, $user, $name);
        if (!$p) { echo json_encode(['ok'=>false,'error'=>'Profile not found']); exit; }
        $statPos    = $p['stat_pos_json'] ?? '{}';
        $statPosArr = json_decode($statPos, true);
        // Restore stat positions — device-specific in flat-file mode so that
        // loading a profile on PC does NOT overwrite Mac's widget positions.
        if ($db) {
            if (is_array($statPosArr) && !empty($statPosArr))
                dashSetSetting($db, $user, 'stat_pos_json', $statPos);
        } else {
            // Flat-file: write ONLY to this device's machine state file.
            // Global dash_stat_pos.json is intentionally NOT touched here.
            if (is_array($statPosArr) && !empty($statPosArr)) {
                _sl_mwrite(['stat_pos_json' => $statPos]);
            }
        }
        echo json_encode([
            'ok'               => true,
            'theme'            => $p['theme'] ?? '',
            'wallpaper_variant'=> $p['wallpaper_variant'] ?? '',
            'size'             => (int)($p['size'] ?? 100),
            'backend'          => $db ? 'mysql' : 'json',
        ]);
        break;

    // ── DELETE ────────────────────────────────────────────────────────────────
    case 'delete':
        $name = trim($json['name'] ?? $_POST['name'] ?? '');
        if (!$name) { echo json_encode(['ok'=>false,'error'=>'Missing profile name']); exit; }
        dashDeleteProfile($db, $user, $name);
        echo json_encode(['ok' => true]);
        break;

    default:
        echo json_encode(['ok' => false, 'error' => 'Unknown action: ' . $action]);
}

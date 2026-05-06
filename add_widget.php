<?php
/**
 * add_widget.php — lightweight widget-creation endpoint for any logged-in,
 * non-readonly user (used by the sub-user first-run wizard and options.php).
 */
require_once 'auth.php';
require_once 'db.php';

header('Content-Type: application/json');

if (!isLoggedIn() || getCurrentRole() === 'readonly') {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Not authorized']);
    exit;
}

$db     = getDashDb();
$user   = getCurrentUsername();
$type   = trim($_POST['widget_type'] ?? '');
$params = json_decode($_POST['params'] ?? '{}', true) ?: [];
$ts     = time();
$rand   = rand(100, 9999);

switch ($type) {
    case 'rss':
        $url = trim($params['url'] ?? '');
        if (!$url) { echo json_encode(['ok'=>false,'error'=>'URL required']); exit; }
        $list   = dashGetWidgets($db, $user, 'rss');
        $list[] = [
            'id'   => 'rw-'.$ts.'-'.$rand,
            'name' => $params['name'] ?? 'RSS Feed',
            'url'  => $url,
            'x' => 20, 'y' => 60, 'w' => 320, 'h' => 220,
        ];
        dashSetWidgets($db, $user, 'rss', $list);
        break;

    case 'calendar':
        $ids = trim($params['ids'] ?? '');
        if (!$ids) { echo json_encode(['ok'=>false,'error'=>'Calendar IDs required']); exit; }
        $list   = dashGetWidgets($db, $user, 'calendar');
        $list[] = [
            'id'           => 'cw-'.$ts.'-'.$rand,
            'name'         => $params['name'] ?? 'Calendar',
            'calendar_ids' => $ids,
            'x' => 20, 'y' => 290, 'w' => 380, 'h' => 300,
        ];
        dashSetWidgets($db, $user, 'calendar', $list);
        break;

    case 'weather_city':
        $city   = trim($params['city'] ?? '');
        $list   = dashGetWidgets($db, $user, 'weather_city');
        $list[] = [
            'id'   => 'wcw-'.$ts.'-'.$rand,
            'city' => $city,
            'x' => 400, 'y' => 60, 'w' => 280, 'h' => 160,
        ];
        dashSetWidgets($db, $user, 'weather_city', $list);
        break;

    case 'sticky':
        $notes   = json_decode(dashGetSetting($db, $user, 'sticky_notes', '[]') ?: '[]', true) ?: [];
        $notes[] = [
            'id'      => 'sn-'.$ts.'-'.$rand,
            'content' => $params['content'] ?? '',
            'x' => 720, 'y' => 60, 'w' => 240, 'h' => 180,
            'color'   => '#fef3c7',
        ];
        dashSetSetting($db, $user, 'sticky_notes', json_encode($notes));
        break;

    default:
        echo json_encode(['ok' => false, 'error' => 'Unknown widget type: ' . htmlspecialchars($type)]);
        exit;
}

echo json_encode(['ok' => true]);

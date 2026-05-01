<?php
require_once __DIR__ . '/auth.php';
header('Content-Type: application/json');
if (!isLoggedIn()) { http_response_code(403); echo '{"ok":false}'; exit; }

$body = file_get_contents('php://input');
$pos  = json_decode($body, true);
if (!is_array($pos)) { http_response_code(400); echo '{"ok":false}'; exit; }

$clean = [];
foreach ($pos as $k => $v) {
    $k = preg_replace('/[^a-z0-9_-]/', '', $k);
    if (!$k) continue;
    $entry = ['x' => (int)($v['x']??0), 'y' => (int)($v['y']??0)];
    if (isset($v['w']) && (int)$v['w'] > 0) $entry['w'] = (int)$v['w'];
    if (isset($v['h']) && (int)$v['h'] > 0) $entry['h'] = (int)$v['h'];
    $clean[$k] = $entry;
}

file_put_contents(__DIR__ . '/dash_stat_pos.json', json_encode($clean));
echo '{"ok":true}';

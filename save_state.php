<?php
require_once __DIR__ . '/auth.php';
header('Content-Type: application/json');
if (!isLoggedIn()) { http_response_code(403); echo '{"ok":false}'; exit; }

$body = file_get_contents('php://input');
$data = json_decode($body, true);
if (!is_array($data)) { http_response_code(400); echo '{"ok":false}'; exit; }

$file = __DIR__ . '/dash_state.json';
$state = json_decode(@file_get_contents($file) ?: '{}', true) ?: [];

foreach ($data as $k => $v) {
    $k = preg_replace('/[^a-z0-9_\-]/', '', $k);
    if (!$k) continue;
    if ($v === null) {
        unset($state[$k]);
    } else {
        $state[$k] = $v;
    }
}

file_put_contents($file, json_encode($state));
echo '{"ok":true}';

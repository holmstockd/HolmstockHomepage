<?php
/**
 * camera_proxy.php — Trigger recording on a Scrypted camera via its REST API.
 * Called by the dashboard Camera widget "Record" button.
 *
 * POST body (JSON):
 *   { "action": "start_record"|"stop_record",
 *     "camera_id": "<scrypted_device_id>",
 *     "scrypted_url": "http://192.168.1.x:10443",
 *     "api_token": "<scrypted_api_token>",
 *     "save_path": "/mnt/recordings" }
 *
 * Returns: { "ok": true|false, "message": "..." }
 */
require_once __DIR__ . '/auth.php';
header('Content-Type: application/json');
if (!isLoggedIn()) { http_response_code(403); echo json_encode(['ok'=>false,'message'=>'Not authenticated']); exit; }

$raw  = file_get_contents('php://input');
$body = json_decode($raw, true) ?: [];

$action     = $body['action']      ?? '';
$cameraId   = $body['camera_id']   ?? '';
$scryptedUrl= rtrim($body['scrypted_url'] ?? '', '/');
$apiToken   = $body['api_token']   ?? '';
$savePath   = $body['save_path']   ?? '';

if (!$action || !$cameraId || !$scryptedUrl) {
    echo json_encode(['ok'=>false,'message'=>'Missing required fields (action, camera_id, scrypted_url)']); exit;
}

// Scrypted REST API: POST /endpoint/@scrypted/core/public/device/<id>/startRecording
$endpoint = $scryptedUrl . '/endpoint/@scrypted/core/public/device/' . urlencode($cameraId);
if ($action === 'start_record') {
    $apiAction = 'startRecording';
} elseif ($action === 'stop_record') {
    $apiAction = 'stopRecording';
} else {
    echo json_encode(['ok'=>false,'message'=>'Unknown action: '.$action]); exit;
}

$ctx = stream_context_create([
    'http' => [
        'method'  => 'POST',
        'timeout' => 10,
        'header'  => "Authorization: Bearer $apiToken\r\nContent-Type: application/json\r\n",
        'content' => json_encode(['savePath' => $savePath ?: null]),
        'ignore_errors' => true,
    ],
    'ssl' => [
        'verify_peer'      => false,
        'verify_peer_name' => false,
    ],
]);

$resp = @file_get_contents($endpoint . '/' . $apiAction, false, $ctx);
if ($resp === false) {
    echo json_encode(['ok'=>false,'message'=>'Could not reach Scrypted at '.$scryptedUrl]); exit;
}

$data = json_decode($resp, true);
echo json_encode([
    'ok'      => true,
    'message' => $action === 'start_record' ? 'Recording started' : 'Recording stopped',
    'data'    => $data,
]);

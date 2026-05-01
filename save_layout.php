<?php
require_once 'auth.php';
require_once 'db.php';
header('Content-Type: application/json');

// ── Input parsing: JSON body (primary) or FormData (legacy) ──────────────────
$json = null;
$ct   = $_SERVER['CONTENT_TYPE'] ?? '';
if (strpos($ct, 'application/json') !== false) {
    $raw  = file_get_contents('php://input');
    $json = json_decode($raw, true);
}

$action = $json['action'] ?? $_POST['action'] ?? $_GET['action'] ?? '';

// ── Storage backend: SQLite when available, JSON file as fallback ─────────────
$db       = getDashDb();
$jsonFile = __DIR__ . '/dash_layouts.json';

function _layoutsFromJson(string $file): array {
    return json_decode(@file_get_contents($file) ?: '{}', true) ?: [];
}
function _layoutsToJson(string $file, array $data): void {
    file_put_contents($file, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
}

switch ($action) {

    // ── LIST ─────────────────────────────────────────────────────────────────
    case 'list':
        $out = [];
        if ($db) {
            $res = $db->query('SELECT name, saved, theme, wallpaper_variant FROM layouts ORDER BY rowid');
            while ($row = $res->fetchArray(SQLITE3_ASSOC)) {
                $out[] = [
                    'name'             => $row['name'],
                    'saved'            => $row['saved'],
                    'theme'            => $row['theme'],
                    'wallpaper_variant'=> $row['wallpaper_variant'],
                ];
            }
        } else {
            foreach (_layoutsFromJson($jsonFile) as $name => $data) {
                $out[] = [
                    'name'             => $name,
                    'saved'            => $data['saved'] ?? '',
                    'theme'            => $data['theme'] ?? '',
                    'wallpaper_variant'=> $data['wallpaper_variant'] ?? '',
                ];
            }
        }
        echo json_encode(['ok' => true, 'layouts' => $out, 'backend' => $db ? 'sqlite' : 'json']);
        break;

    // ── SAVE ─────────────────────────────────────────────────────────────────
    case 'save':
        $name    = trim($json['name'] ?? $_POST['name'] ?? '');
        $theme   = trim($json['theme'] ?? $_POST['theme'] ?? '');
        $variant = trim($json['wallpaper_variant'] ?? $_POST['wallpaper_variant'] ?? '');

        $data = $json['links'] ?? null;
        if ($data === null) {
            $raw2 = $_POST['links_json'] ?? '';
            if ($raw2) $data = json_decode($raw2, true);
        }
        if (!$name) {
            echo json_encode(['ok' => false, 'error' => 'Missing profile name']); exit;
        }
        if (!is_array($data)) {
            echo json_encode(['ok' => false, 'error' => 'Invalid links data: ' . json_last_error_msg()]); exit;
        }
        $linksJson = json_encode($data, JSON_UNESCAPED_UNICODE);
        $saved     = date('Y-m-d H:i');
        if ($db) {
            $stmt = $db->prepare(
                'INSERT OR REPLACE INTO layouts (name, saved, links_json, theme, wallpaper_variant)
                 VALUES (:n, :s, :l, :t, :v)'
            );
            $stmt->bindValue(':n', $name,      SQLITE3_TEXT);
            $stmt->bindValue(':s', $saved,     SQLITE3_TEXT);
            $stmt->bindValue(':l', $linksJson, SQLITE3_TEXT);
            $stmt->bindValue(':t', $theme,     SQLITE3_TEXT);
            $stmt->bindValue(':v', $variant,   SQLITE3_TEXT);
            $stmt->execute();
        } else {
            $layouts = _layoutsFromJson($jsonFile);
            $layouts[$name] = [
                'saved'            => $saved,
                'theme'            => $theme,
                'wallpaper_variant'=> $variant,
                'links'            => $data,
            ];
            _layoutsToJson($jsonFile, $layouts);
        }
        echo json_encode(['ok' => true, 'backend' => $db ? 'sqlite' : 'json']);
        break;

    // ── LOAD ─────────────────────────────────────────────────────────────────
    case 'load':
        $name  = trim($json['name'] ?? $_POST['name'] ?? '');
        $links = null; $theme = ''; $variant = '';
        if ($db) {
            $stmt = $db->prepare(
                'SELECT links_json, theme, wallpaper_variant FROM layouts WHERE name = :n COLLATE NOCASE'
            );
            $stmt->bindValue(':n', $name, SQLITE3_TEXT);
            $row = $stmt->execute()->fetchArray(SQLITE3_ASSOC);
            if ($row) {
                $links   = json_decode($row['links_json'], true);
                $theme   = $row['theme']            ?? '';
                $variant = $row['wallpaper_variant'] ?? '';
            }
        } else {
            $layouts = _layoutsFromJson($jsonFile);
            if (isset($layouts[$name])) {
                $links   = $layouts[$name]['links']             ?? null;
                $theme   = $layouts[$name]['theme']             ?? '';
                $variant = $layouts[$name]['wallpaper_variant'] ?? '';
            }
        }
        if (!is_array($links)) {
            echo json_encode(['ok' => false, 'error' => 'Profile not found']); exit;
        }
        // Write link layout so page reload picks it up
        file_put_contents(__DIR__ . '/dash_links.json',
            json_encode($links, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        echo json_encode([
            'ok'               => true,
            'theme'            => $theme,
            'wallpaper_variant'=> $variant,
            'backend'          => $db ? 'sqlite' : 'json',
        ]);
        break;

    // ── DELETE ────────────────────────────────────────────────────────────────
    case 'delete':
        $name = trim($json['name'] ?? $_POST['name'] ?? '');
        if (!$name) {
            echo json_encode(['ok' => false, 'error' => 'Missing profile name']); exit;
        }
        if ($db) {
            $stmt = $db->prepare('DELETE FROM layouts WHERE name = :n COLLATE NOCASE');
            $stmt->bindValue(':n', $name, SQLITE3_TEXT);
            $stmt->execute();
        } else {
            $layouts = _layoutsFromJson($jsonFile);
            unset($layouts[$name]);
            _layoutsToJson($jsonFile, $layouts);
        }
        echo json_encode(['ok' => true]);
        break;

    default:
        echo json_encode(['ok' => false, 'error' => 'Unknown action: ' . $action]);
}

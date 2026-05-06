<?php
/**
 * rss_proxy.php — fetch & parse an RSS/Atom feed on behalf of the dashboard
 * Usage: rss_proxy.php?url=<encoded_feed_url>&max=<int>
 *
 * Returns JSON: { "title":"Feed Title", "items":[{"title":"…","link":"…","date":"…","desc":"…"}, …] }
 * On error:     { "error": "message" }
 */

// ── Auth guard ─────────────────────────────────────────────────────────────
if (session_status() === PHP_SESSION_NONE) session_start();
if (!file_exists(__DIR__ . '/dash_config.php')) {
    http_response_code(503); echo '{"error":"Not configured"}'; exit;
}
if (empty($_SESSION['logged_in'])) {
    if (isset($_COOKIE['dash_auth'])) @include_once __DIR__ . '/auth.php';
    if (empty($_SESSION['logged_in'])) {
        http_response_code(401); echo '{"error":"Not authenticated"}'; exit;
    }
}

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$url = trim($_GET['url'] ?? '');
$max = max(3, min(30, intval($_GET['max'] ?? 10)));

if (!$url || !filter_var($url, FILTER_VALIDATE_URL)) {
    echo json_encode(['error'=>'Missing or invalid URL']);
    exit;
}

// Fetch with a reasonable timeout
$ctx = stream_context_create(['http'=>[
    'timeout'          => 8,
    'follow_location'  => 1,
    'max_redirects'    => 3,
    'header'           => "User-Agent: Mozilla/5.0 (compatible; DashRSSReader/1.0)\r\n",
    'ignore_errors'    => true,
]]);

$raw = @file_get_contents($url, false, $ctx);
if ($raw === false || strlen($raw) < 30) {
    echo json_encode(['error'=>'Could not fetch feed — check the URL and try again.']);
    exit;
}

// Suppress XML warnings
libxml_use_internal_errors(true);
$xml = simplexml_load_string($raw, 'SimpleXMLElement',
       LIBXML_NOCDATA | LIBXML_NOERROR | LIBXML_NOWARNING);
libxml_clear_errors();

if (!$xml) {
    echo json_encode(['error'=>'The URL did not return a valid RSS/Atom feed.']);
    exit;
}

$items = [];
$feedTitle = '';

// Detect RSS 2.0 vs Atom
if (isset($xml->channel)) {
    // RSS 2.0
    $feedTitle = (string)$xml->channel->title;
    $entries = $xml->channel->item;
    foreach ($entries as $item) {
        if (count($items) >= $max) break;
        $link = (string)($item->link ?: ($item->guid ?? ''));
        $desc = (string)($item->description ?? '');
        // Strip HTML tags from description excerpt
        $desc = trim(html_entity_decode(strip_tags($desc)));
        $desc = mb_strimwidth($desc, 0, 160, '…');
        $items[] = [
            'title' => htmlspecialchars(trim((string)$item->title), ENT_QUOTES),
            'link'  => htmlspecialchars(trim($link), ENT_QUOTES),
            'date'  => htmlspecialchars(trim((string)($item->pubDate ?? '')), ENT_QUOTES),
            'desc'  => htmlspecialchars($desc, ENT_QUOTES),
        ];
    }
} elseif (isset($xml->entry)) {
    // Atom
    $feedTitle = (string)($xml->title ?? '');
    foreach ($xml->entry as $entry) {
        if (count($items) >= $max) break;
        $link = '';
        foreach ($entry->link as $l) {
            $rel = (string)($l['rel'] ?? 'alternate');
            if ($rel === 'alternate' || $rel === '') { $link = (string)($l['href'] ?? ''); break; }
        }
        $desc = (string)($entry->summary ?? $entry->content ?? '');
        $desc = trim(html_entity_decode(strip_tags($desc)));
        $desc = mb_strimwidth($desc, 0, 160, '…');
        $items[] = [
            'title' => htmlspecialchars(trim((string)$entry->title), ENT_QUOTES),
            'link'  => htmlspecialchars(trim($link), ENT_QUOTES),
            'date'  => htmlspecialchars(trim((string)($entry->updated ?? $entry->published ?? '')), ENT_QUOTES),
            'desc'  => htmlspecialchars($desc, ENT_QUOTES),
        ];
    }
} else {
    echo json_encode(['error'=>'Unrecognised feed format (expected RSS or Atom).']);
    exit;
}

echo json_encode(['title'=>htmlspecialchars($feedTitle, ENT_QUOTES), 'items'=>$items]);

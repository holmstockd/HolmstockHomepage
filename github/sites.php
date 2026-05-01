<?php
header('Access-Control-Allow-Origin: *');
header('Content-Type: application/json');
header('Cache-Control: no-cache');

$sites = [];

// ─── Apache vhosts ──────────────────────────────────────────────────────────
function parseApache() {
    $sites = [];
    $dirs = ['/etc/apache2/sites-enabled', '/etc/httpd/conf.d', '/usr/local/etc/apache24/Includes'];
    foreach ($dirs as $dir) {
        if (!is_dir($dir)) continue;
        foreach (glob($dir . '/*.conf') as $file) {
            $content = @file_get_contents($file);
            if (!$content) continue;
            // Extract ServerName / ServerAlias lines
            preg_match_all('/ServerName\s+(.+)/i', $content, $sn);
            preg_match_all('/ServerAlias\s+(.+)/i', $content, $sa);
            preg_match_all('/DocumentRoot\s+(.+)/i', $content, $dr);
            // Extract port
            preg_match('/VirtualHost[^:]*:(\d+)/i', $content, $port_m);
            $port = isset($port_m[1]) ? (int)$port_m[1] : 80;
            $proto = $port === 443 ? 'https' : 'http';

            foreach ($sn[1] as $i => $name) {
                $name = trim($name);
                if (!$name || $name === '_default_') continue;
                $docroot = isset($dr[1][$i]) ? trim($dr[1][$i]) : '';
                $sites[] = [
                    'name'    => $name,
                    'url'     => $proto . '://' . $name . ($port != 80 && $port != 443 ? ":$port" : ''),
                    'docroot' => $docroot,
                    'server'  => 'Apache',
                    'port'    => $port,
                    'file'    => basename($file),
                ];
            }
        }
    }
    return $sites;
}

// ─── Nginx vhosts ────────────────────────────────────────────────────────────
function parseNginx() {
    $sites = [];
    $dirs  = ['/etc/nginx/sites-enabled', '/etc/nginx/conf.d', '/usr/local/etc/nginx/servers'];
    foreach ($dirs as $dir) {
        if (!is_dir($dir)) continue;
        foreach (glob($dir . '/*.conf') as $file) {
            $content = @file_get_contents($file);
            if (!$content) continue;
            // Find server blocks
            preg_match_all('/server\s*\{([^{}]+(?:\{[^{}]*\}[^{}]*)*)\}/s', $content, $blocks);
            foreach ($blocks[1] as $block) {
                preg_match_all('/server_name\s+([^;]+);/i', $block, $sn);
                preg_match('/listen\s+(\d+)/i', $block, $lm);
                preg_match('/root\s+([^;]+);/i', $block, $rm);
                $port  = isset($lm[1]) ? (int)$lm[1] : 80;
                $proto = ($port === 443 || strpos($block,'ssl')!==false) ? 'https' : 'http';
                $docroot = isset($rm[1]) ? trim($rm[1]) : '';
                foreach ($sn[1] as $nameStr) {
                    $names = preg_split('/\s+/', trim($nameStr));
                    foreach ($names as $name) {
                        $name = trim($name);
                        if (!$name || $name === '_' || $name === 'localhost') continue;
                        $sites[] = [
                            'name'    => $name,
                            'url'     => $proto . '://' . $name . ($port != 80 && $port != 443 ? ":$port" : ''),
                            'docroot' => $docroot,
                            'server'  => 'Nginx',
                            'port'    => $port,
                            'file'    => basename($file),
                        ];
                    }
                }
            }
        }
    }
    return $sites;
}

$detected = array_merge(parseApache(), parseNginx());

// Remove duplicates by URL
$seen = [];
foreach ($detected as $s) {
    if (!isset($seen[$s['url']])) {
        $seen[$s['url']] = $s;
    }
}
$sites = array_values($seen);

echo json_encode(['sites' => $sites, 'count' => count($sites)]);

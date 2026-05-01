<?php
header('Access-Control-Allow-Origin: *');
header('Content-Type: application/json');
header('Cache-Control: no-cache');

function getCpuPercent() {
    if (!is_readable('/proc/stat')) return null;
    $s1 = file_get_contents('/proc/stat');
    preg_match('/^cpu\s+(\d+)\s+(\d+)\s+(\d+)\s+(\d+)/m', $s1, $m1);
    usleep(200000);
    $s2 = file_get_contents('/proc/stat');
    preg_match('/^cpu\s+(\d+)\s+(\d+)\s+(\d+)\s+(\d+)/m', $s2, $m2);
    if (empty($m1) || empty($m2)) return null;
    $idle1  = $m1[4]; $total1 = $m1[1]+$m1[2]+$m1[3]+$m1[4];
    $idle2  = $m2[4]; $total2 = $m2[1]+$m2[2]+$m2[3]+$m2[4];
    $totalDiff = $total2 - $total1;
    $idleDiff  = $idle2  - $idle1;
    if ($totalDiff == 0) return 0;
    return round(100 * (1 - $idleDiff / $totalDiff), 1);
}

function getRam() {
    $mem = @shell_exec('free -m 2>/dev/null');
    if (!$mem) {
        // fallback /proc/meminfo
        $info = @file_get_contents('/proc/meminfo');
        if (!$info) return ['used'=>null,'total'=>null];
        preg_match('/MemTotal:\s+(\d+)/i', $info, $mt);
        preg_match('/MemAvailable:\s+(\d+)/i', $info, $ma);
        $total = isset($mt[1]) ? round($mt[1]/1048576, 1) : null;
        $avail = isset($ma[1]) ? round($ma[1]/1048576, 1) : null;
        $used  = ($total && $avail) ? round($total - $avail, 1) : null;
        return ['used'=>$used,'total'=>$total];
    }
    $lines = explode("\n", trim($mem));
    $parts = preg_split('/\s+/', $lines[1] ?? '');
    $ram_used  = isset($parts[2]) ? $parts[2] : 0;
    $ram_total = isset($parts[1]) ? $parts[1] : 0;
    return ['used'=>round($ram_used/1024,1),'total'=>round($ram_total/1024,1)];
}

function driveInfo($path) {
    if (!is_dir($path)) return null;
    $free  = @disk_free_space($path);
    $total = @disk_total_space($path);
    if ($free === false || $total === false) return null;
    $used_pct = $total > 0 ? round(100 * (1 - $free/$total)) : 0;
    if ($total >= 1099511627776)
        return ['free'=>round($free/1099511627776,2),'total'=>round($total/1099511627776,2),'used_pct'=>$used_pct,'unit'=>'TB'];
    return ['free'=>round($free/1073741824,1),'total'=>round($total/1073741824,1),'used_pct'=>$used_pct,'unit'=>'GB'];
}

// Load configured drives
$drives_cfg = [];
$drives_file = __DIR__ . '/dash_drives.json';
if (file_exists($drives_file)) {
    $drives_cfg = json_decode(file_get_contents($drives_file), true) ?: [];
}

$ram = getRam();
$out = [
    'cpu'       => getCpuPercent(),
    'ram_used'  => $ram['used'],
    'ram_total' => $ram['total'],
    'drives'    => [],
];

foreach ($drives_cfg as $d) {
    $key  = preg_replace('/[^a-z0-9_]/', '', $d['key'] ?? '');
    $path = $d['path'] ?? '';
    if ($key && $path) {
        $info = driveInfo($path);
        $out['drives'][$key] = $info;
        // legacy compat
        $out[$key] = $info;
    }
}

echo json_encode($out);

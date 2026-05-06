<?php
require_once 'auth.php';
header('Content-Type: application/json');

// ── Validate a single path (called from options.php manual path input) ────────
$action = $_GET['action'] ?? '';
if ($action === 'validate') {
    $path = trim($_GET['path'] ?? '');
    if (!$path) { echo json_encode(['ok'=>false,'error'=>'No path provided']); exit; }
    if (!is_dir($path)) { echo json_encode(['ok'=>false,'error'=>'Path not found or not a directory on this server: '.$path]); exit; }
    $free = @disk_free_space($path);
    if ($free === false) { echo json_encode(['ok'=>false,'error'=>'Cannot read disk stats for: '.$path]); exit; }
    $total  = @disk_total_space($path) ?: 0;
    $pct    = ($total > 0) ? round(100*(1-$free/$total)) : 0;
    $key    = preg_replace('/_{2,}/','_',preg_replace('/[^a-z0-9]/','_',strtolower(basename($path) ?: 'drive')));
    echo json_encode(['ok'=>true,'drive'=>[
        'label'    => basename($path) ?: $path,
        'path'     => $path,
        'key'      => $key,
        'used_pct' => $pct,
        'free_gb'  => round($free/1073741824,1),
        'total_gb' => round($total/1073741824,1),
    ]]);
    exit;
}

$os     = PHP_OS_FAMILY; // 'Windows', 'Linux', 'Darwin', 'BSD'
$drives = [];

function bytesToGb($b) {
    if ($b === false || $b <= 0) return 0;
    return round($b / 1073741824, 1);
}

function testDrive(string $path, string $label, string $key): ?array {
    if (!is_dir($path)) return null;
    $free = @disk_free_space($path);
    if ($free === false) return null;
    $total = @disk_total_space($path);
    if ($total === false || $total <= 0) $total = 0;
    $pct = ($total > 0) ? round(100 * (1 - $free / $total)) : 0;
    return [
        'label'    => $label,
        'path'     => $path,
        'key'      => preg_replace('/_{2,}/', '_', preg_replace('/[^a-z0-9]/', '_', strtolower($key))),
        'used_pct' => $pct,
        'free_gb'  => bytesToGb($free),
        'total_gb' => bytesToGb($total),
    ];
}

// ── Deduplicate by (free_gb, total_gb) so same physical volume isn't listed twice
function dedupeBySpace(array $drives): array {
    $seen = [];
    $out  = [];
    foreach ($drives as $d) {
        $sig = $d['free_gb'].':'.$d['total_gb'];
        if (!isset($seen[$sig])) { $seen[$sig]=true; $out[]=$d; }
    }
    return $out;
}

if ($os === 'Darwin') {
    if ($d = testDrive('/', 'Macintosh HD', 'root')) $drives[] = $d;
    if (is_dir('/Volumes')) {
        foreach (array_diff(@scandir('/Volumes') ?: [], ['.','..']) as $entry) {
            $path = '/Volumes/'.$entry;
            if ($d = testDrive($path, $entry, $entry)) $drives[] = $d;
        }
    }

} elseif ($os === 'Windows') {
    foreach (range('C','Z') as $letter) {   // skip A/B (floppies)
        $path = $letter.':\\';
        if ($d = testDrive($path, 'Drive '.$letter.':', strtolower($letter).'_drive')) $drives[] = $d;
    }

} else {
    // Linux / BSD / other
    if ($d = testDrive('/', 'Root (/)', 'root')) $drives[] = $d;

    // /mnt/*
    if (is_dir('/mnt')) {
        foreach (array_diff(@scandir('/mnt') ?: [], ['.','..']) as $entry) {
            if ($d = testDrive('/mnt/'.$entry, 'Mount: '.$entry, $entry)) $drives[] = $d;
        }
    }

    // /media/USER/*
    if (is_dir('/media')) {
        foreach (array_diff(@scandir('/media') ?: [], ['.','..']) as $user) {
            $sub = '/media/'.$user;
            if (!is_dir($sub)) continue;
            foreach (array_diff(@scandir($sub) ?: [], ['.','..']) as $entry) {
                if ($d = testDrive($sub.'/'.$entry, 'Media: '.$entry, $entry)) $drives[] = $d;
            }
            if ($d = testDrive($sub, 'Media: '.$user, $user)) $drives[] = $d;
        }
    }

    // /home as separate partition (only add if different volume from /)
    if ($d = testDrive('/home', 'Home (/home)', 'home')) {
        $rootTotal = @disk_total_space('/') ?: 0;
        if ($rootTotal && abs($d['total_gb'] * 1073741824 - $rootTotal) > 1073741824) {
            $drives[] = $d;
        }
    }

    // /data, /srv, /storage, /backup — common NAS/server paths
    foreach (['/data'=>'Data', '/srv'=>'Server (/srv)', '/storage'=>'Storage', '/backup'=>'Backup'] as $p => $lbl) {
        if ($d = testDrive($p, $lbl, basename($p))) $drives[] = $d;
    }
}

$drives = dedupeBySpace($drives);
echo json_encode(['ok' => true, 'os' => $os, 'drives' => $drives]);

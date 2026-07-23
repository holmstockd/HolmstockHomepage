<?php
// apply_update.php — accepts a ZIP (upload or URL), verifies SHA-256, extracts and applies it.
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/db.php';
header('Content-Type: application/json');
if (!isLoggedIn() || !isAdmin()) {
    http_response_code(403);
    echo '{"ok":false,"error":"Admin access required."}';
    exit;
}
if (!class_exists('ZipArchive')) {
    echo json_encode(['ok'=>false,'error'=>'ZipArchive PHP extension is not installed on this server. Ask your host to enable php-zip.']);
    exit;
}

$mode        = $_POST['mode']    ?? 'upload';  // 'upload' | 'url'
$expectedSha = strtolower(trim($_POST['sha256'] ?? ''));
$tmpZip      = '';
$tmpDir      = '';

function _rmdir(string $dir): void {
    if (!is_dir($dir)) return;
    foreach (new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    ) as $item) {
        $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
    }
    @rmdir($dir);
}

try {
    // ── 1. Get the ZIP ────────────────────────────────────────────────────
    if ($mode === 'url') {
        $zipUrl = trim($_POST['zip_url'] ?? '');
        if (!filter_var($zipUrl, FILTER_VALIDATE_URL)) throw new Exception('Invalid ZIP URL.');
        $tmpZip = tempnam(sys_get_temp_dir(), 'dash_upd_') . '.zip';
        $ctx = stream_context_create(['http'=>[
            'timeout' => 90, 'method' => 'GET',
            'header'  => "User-Agent: DashHomepage/1.5\r\n",
        ]]);
        $bytes = @file_get_contents($zipUrl, false, $ctx);
        if ($bytes === false) throw new Exception('Failed to download update ZIP from URL.');
        file_put_contents($tmpZip, $bytes);
    } else {
        if (empty($_FILES['update_zip']['tmp_name']) || !is_uploaded_file($_FILES['update_zip']['tmp_name'])) {
            throw new Exception('No file uploaded or upload error.');
        }
        $tmpZip = $_FILES['update_zip']['tmp_name'];
    }

    // ── 2. SHA-256 verification ───────────────────────────────────────────
    if ($expectedSha) {
        $actual = strtolower(hash_file('sha256', $tmpZip));
        if ($actual !== $expectedSha) {
            throw new Exception("SHA-256 mismatch — update rejected for security.\nExpected: $expectedSha\nGot:      $actual");
        }
    }

    // ── 3. Open + validate ZIP ────────────────────────────────────────────
    $zip = new ZipArchive();
    $res = $zip->open($tmpZip);
    if ($res !== true) throw new Exception("Could not open ZIP (ZipArchive error $res).");

    // Must contain index.php somewhere
    $hasIndex = ($zip->locateName('index.php') !== false ||
                 $zip->locateName('php-dashboard/index.php') !== false);
    if (!$hasIndex) {
        $zip->close();
        throw new Exception('ZIP does not appear to be a valid dashboard package (index.php not found).');
    }

    // Read new version from README.md
    $newVersion = '?';
    foreach (['README.md', 'php-dashboard/README.md'] as $rn) {
        $idx = $zip->locateName($rn);
        if ($idx !== false) {
            $readme = $zip->getFromIndex($idx);
            if (preg_match('/v(\d+\.\d+(?:\.\d+)?)/', $readme, $m)) $newVersion = $m[1];
            break;
        }
    }

    // ── 4. Extract to temp dir ────────────────────────────────────────────
    $tmpDir = sys_get_temp_dir() . '/dash_upd_ext_' . time();
    @mkdir($tmpDir, 0755, true);
    $zip->extractTo($tmpDir);
    $zip->close();

    // Find source root (may be nested in php-dashboard/ subdirectory)
    $srcRoot = $tmpDir;
    if (!file_exists($srcRoot . '/index.php')) {
        foreach (['php-dashboard', 'dash', 'dashboard'] as $sub) {
            if (file_exists("$srcRoot/$sub/index.php")) { $srcRoot = "$srcRoot/$sub"; break; }
        }
        if (!file_exists($srcRoot . '/index.php')) {
            foreach (glob($tmpDir . '/*/index.php') ?: [] as $f) {
                $srcRoot = dirname($f); break;
            }
        }
    }

    // ── 5. Copy files (skip protected) ────────────────────────────────────
    $protectedFiles = [
        'dash_config.php','dash_secret.php','dash_users.json','dash_links.json','dash_settings.json',
        'dash_monitor.json','dash_drives.json','dash_state.json','dash_profiles.json',
        'dash_custom_bgs.json',
        // v1.3+ user-data files — never overwrite on upgrade
        'dash_custom_bg.json','dash_custom_theme.json','dash_docfolders.json',
        'dash_hidden_themes.json','dash_page_folders.json','dash_videos.json',
        'dash_stat_pos.json','dash_hidden_cols.json','dash_hidden_stats.json',
        'dash_widgets.json',
    ];
    $protectedDirs = ['uploads', 'zips'];
    $dashRoot = __DIR__;
    $copied = 0; $skipped = 0;

    $iter = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($srcRoot, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );
    foreach ($iter as $item) {
        $rel  = str_replace('\\', '/', substr($item->getPathname(), strlen($srcRoot) + 1));
        $top  = explode('/', $rel)[0];
        // Per-device and per-user state files are named with a suffix, so match by prefix.
        $isPerDevice = (strpos($rel, 'dash_machine_state_') === 0)
                    || (strpos($rel, 'dash_device_profiles_') === 0)
                    || (strpos($rel, 'dash_links_') === 0)
                    || (strpos($rel, 'dash_docfolders_') === 0);
        if (in_array($rel, $protectedFiles) || in_array($top, $protectedDirs) || $isPerDevice) { $skipped++; continue; }
        $dest = $dashRoot . '/' . $rel;
        if ($item->isDir()) { @mkdir($dest, 0755, true); }
        else { if (@copy($item->getPathname(), $dest)) $copied++; else $skipped++; }
    }

    // ── 6. Run SQL migrations ─────────────────────────────────────────────
    $migrated = [];
    $migrDir  = $srcRoot . '/migrations';
    if (is_dir($migrDir)) {
        $db = getDashDb();
        if ($db) {
            $sqls = glob($migrDir . '/*.sql') ?: [];
            sort($sqls);
            foreach ($sqls as $sqlFile) {
                $sql = @file_get_contents($sqlFile);
                if (!$sql) continue;
                try {
                    $db->exec($sql);
                    $migrated[] = basename($sqlFile) . ' ✓';
                } catch (Throwable $e) {
                    $migrated[] = basename($sqlFile) . ' ⚠ ' . $e->getMessage();
                }
            }
        }
    }

    _rmdir($tmpDir);
    if ($mode === 'url' && $tmpZip) @unlink($tmpZip);

    echo json_encode([
        'ok'         => true,
        'version'    => $newVersion,
        'copied'     => $copied,
        'skipped'    => $skipped,
        'migrations' => $migrated,
        'message'    => "Update to v$newVersion applied — $copied files updated. Reload the dashboard.",
    ]);

} catch (Throwable $e) {
    if ($tmpDir) _rmdir($tmpDir);
    if ($mode === 'url' && $tmpZip && $tmpZip !== ($_FILES['update_zip']['tmp_name'] ?? '')) @unlink($tmpZip);
    echo json_encode(['ok'=>false,'error'=>$e->getMessage()]);
}

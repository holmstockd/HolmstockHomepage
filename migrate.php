<?php
/**
 * migrate.php — Migrate existing JSON/filesystem data to MySQL
 *
 * Can be run:
 *   - Automatically on first login after MySQL is configured (via auth.php)
 *   - Manually by visiting /migrate.php (admin only) for a progress report
 */

require_once __DIR__ . '/db.php';

/**
 * Run a silent migration for one user.
 * Returns array of what was migrated.
 */
function dashRunMigration(PDO $db, string $username): array {
    $done = [];

    // Mark done immediately to prevent re-entry (even if migration fails mid-way)
    $db->prepare('INSERT IGNORE INTO dash_settings (username, setting_key, setting_val)
                  VALUES (?,?,?)')->execute([$username, 'migration_done', date('Y-m-d H:i:s')]);

    // Sub-users MUST NOT inherit admin JSON data.  They start with a clean slate;
    // sharing is opt-in only.  Only the primary admin account gets JSON migration.
    $isAdmin = defined('DASH_USERNAME') && $username === DASH_USERNAME;
    if (!$isAdmin) {
        return $done;
    }

    // ── 1. dash_state.json → dash_settings ───────────────────────────────────
    $stateFile = __DIR__ . '/dash_state.json';
    if (file_exists($stateFile)) {
        $state = json_decode(@file_get_contents($stateFile) ?: '{}', true) ?: [];
        foreach ($state as $k => $v) {
            $k = preg_replace('/[^a-z0-9_\-]/', '', $k);
            if (!$k) continue;
            $db->prepare('INSERT IGNORE INTO dash_settings (username,setting_key,setting_val)
                          VALUES (?,?,?)')->execute([$username, $k, (string)$v]);
        }
        $done[] = 'settings from dash_state.json';
    }

    // ── 2. dash_stat_pos.json → dash_settings (key=stat_pos_json) ────────────
    $statFile = __DIR__ . '/dash_stat_pos.json';
    if (file_exists($statFile)) {
        $raw = @file_get_contents($statFile) ?: '{}';
        $db->prepare('INSERT IGNORE INTO dash_settings (username,setting_key,setting_val)
                      VALUES (?,?,?)')->execute([$username, 'stat_pos_json', $raw]);
        $done[] = 'stat positions from dash_stat_pos.json';
    }

    // ── 3. dash_links.json → dash_links ──────────────────────────────────────
    $linksFile = __DIR__ . '/dash_links.json';
    if (file_exists($linksFile)) {
        $s = $db->prepare('SELECT id FROM dash_links WHERE username=?');
        $s->execute([$username]);
        if (!$s->fetch()) {
            $raw = @file_get_contents($linksFile) ?: '[]';
            $db->prepare('INSERT IGNORE INTO dash_links (username,data) VALUES (?,?)')
               ->execute([$username, $raw]);
            $done[] = 'links from dash_links.json';
        }
    }

    // ── 4. dash_page_folders.json → dash_page_folders ────────────────────────
    $pfFile = __DIR__ . '/dash_page_folders.json';
    if (file_exists($pfFile)) {
        $s = $db->prepare('SELECT id FROM dash_page_folders WHERE username=?');
        $s->execute([$username]);
        if (!$s->fetch()) {
            $raw = @file_get_contents($pfFile) ?: '[]';
            $db->prepare('INSERT IGNORE INTO dash_page_folders (username,data) VALUES (?,?)')
               ->execute([$username, $raw]);
            $done[] = 'page folders from dash_page_folders.json';
        }
    }

    // ── 5. Widgets (html / rss / camera / calendar) ───────────────────────────
    foreach (['html','rss','camera','calendar'] as $type) {
        $wFile = __DIR__ . "/dash_{$type}_widgets.json";
        if (file_exists($wFile)) {
            $s = $db->prepare('SELECT id FROM dash_widgets WHERE username=? AND widget_type=?');
            $s->execute([$username, $type]);
            if (!$s->fetch()) {
                $raw = @file_get_contents($wFile) ?: '[]';
                $db->prepare('INSERT IGNORE INTO dash_widgets (username,widget_type,data) VALUES (?,?,?)')
                   ->execute([$username, $type, $raw]);
                $done[] = "$type widgets from dash_{$type}_widgets.json";
            }
        }
    }

    // ── 6. dash_custom_bg.json → dash_custom_bgs ────────────────────────────
    $bgFile = __DIR__ . '/dash_custom_bg.json';
    if (file_exists($bgFile)) {
        $s = $db->prepare('SELECT id FROM dash_custom_bgs WHERE username=?');
        $s->execute([$username]);
        if (!$s->fetch()) {
            $raw = @file_get_contents($bgFile) ?: '{}';
            $db->prepare('INSERT IGNORE INTO dash_custom_bgs (username,data) VALUES (?,?)')
               ->execute([$username, $raw]);
            $done[] = 'custom backgrounds from dash_custom_bg.json';
        }
    }

    // ── 7. dash_custom_theme.json → dash_settings ────────────────────────────
    $ctFile = __DIR__ . '/dash_custom_theme.json';
    if (file_exists($ctFile)) {
        $raw = @file_get_contents($ctFile) ?: '{}';
        $db->prepare('INSERT IGNORE INTO dash_settings (username,setting_key,setting_val)
                      VALUES (?,?,?)')->execute([$username, 'custom_theme', $raw]);
        $done[] = 'custom theme from dash_custom_theme.json';
    }

    // ── 8. dash_hidden_themes.json → dash_settings ───────────────────────────
    $htFile = __DIR__ . '/dash_hidden_themes.json';
    if (file_exists($htFile)) {
        $raw = @file_get_contents($htFile) ?: '[]';
        $db->prepare('INSERT IGNORE INTO dash_settings (username,setting_key,setting_val)
                      VALUES (?,?,?)')->execute([$username, 'hidden_themes', $raw]);
        $done[] = 'hidden themes from dash_hidden_themes.json';
    }

    // ── 9. dash_drives.json → dash_settings ──────────────────────────────────
    $drFile = __DIR__ . '/dash_drives.json';
    if (file_exists($drFile)) {
        $raw = @file_get_contents($drFile) ?: '[]';
        $db->prepare('INSERT IGNORE INTO dash_settings (username,setting_key,setting_val)
                      VALUES (?,?,?)')->execute([$username, 'drives', $raw]);
        $done[] = 'drives from dash_drives.json';
    }

    // ── 10. dash_monitor.json → dash_settings ─────────────────────────────────
    $monFile = __DIR__ . '/dash_monitor.json';
    if (file_exists($monFile)) {
        $raw = @file_get_contents($monFile) ?: '{}';
        $db->prepare('INSERT IGNORE INTO dash_settings (username,setting_key,setting_val)
                      VALUES (?,?,?)')->execute([$username, 'monitor', $raw]);
        $done[] = 'monitor settings from dash_monitor.json';
    }

    // ── 11. Layout profiles: dash_layouts.json → dash_profiles ───────────────
    $lFile = __DIR__ . '/dash_layouts.json';
    if (file_exists($lFile)) {
        $layouts = json_decode(@file_get_contents($lFile) ?: '{}', true) ?: [];
        foreach ($layouts as $name => $d) {
            $db->prepare('INSERT IGNORE INTO dash_profiles
                            (username,profile_name,theme,wallpaper_variant,size,stat_pos_json,saved)
                          VALUES (?,?,?,?,?,?,?)')
               ->execute([$username, $name, $d['theme']??'', $d['wallpaper_variant']??'',
                          (int)($d['size']??100), $d['stat_pos_json']??'{}', $d['saved']??'']);
        }
        if ($layouts) $done[] = count($layouts) . ' layout profile(s) from dash_layouts.json';
    }

    // ── 12. Doc folders + files from filesystem ───────────────────────────────
    $base = __DIR__ . '/uploads/docs/' . $username;
    if (is_dir($base)) {
        $pins = json_decode(@file_get_contents($base.'/folder_pins.json') ?: '{}', true) ?: [];
        $order = 0;
        foreach (scandir($base) as $dirKey) {
            if ($dirKey[0] === '.') continue;
            $dp = $base . '/' . $dirKey;
            if (!is_dir($dp)) continue;
            $mf = $dp . '/_meta.json';
            if (!file_exists($mf)) continue;
            $m = json_decode(@file_get_contents($mf) ?: '{}', true) ?: [];
            $label   = $m['label'] ?? $dirKey;
            $icon    = $m['icon']  ?? '📁';
            $pinType = $pins[$dirKey] ?? 'all';
            $db->prepare('INSERT IGNORE INTO dash_doc_folders
                            (username,dir_key,label,icon,sort_order,pin_type)
                          VALUES (?,?,?,?,?,?)
                          ON DUPLICATE KEY UPDATE label=VALUES(label)')
               ->execute([$username, $dirKey, $label, $icon, $order++, $pinType]);
            // Migrate files in this folder
            foreach (glob($dp . '/*') ?: [] as $fp) {
                if (!is_file($fp)) continue;
                $fname = basename($fp);
                if ($fname === '_meta.json') continue;
                $ext   = strtolower(pathinfo($fname, PATHINFO_EXTENSION));
                $db->prepare('INSERT IGNORE INTO dash_doc_files
                                (username,dir_key,filename,size,ext,mtime)
                              VALUES (?,?,?,?,?,?)')
                   ->execute([$username, $dirKey, $fname, filesize($fp), $ext, filemtime($fp)]);
            }
        }
        $done[] = 'doc folders and files from filesystem';
    }

    // ── 13. dash_users.json → dash_users ─────────────────────────────────────
    $usersFile = __DIR__ . '/dash_users.json';
    if (file_exists($usersFile)) {
        $users = json_decode(@file_get_contents($usersFile) ?: '[]', true) ?: [];
        foreach ($users as $u) {
            if (empty($u['username']) || empty($u['password_hash'])) continue;
            $db->prepare('INSERT IGNORE INTO dash_users (username,password_hash,role) VALUES (?,?,?)')
               ->execute([$u['username'], $u['password_hash'], $u['role']??'user']);
        }
        if ($users) $done[] = count($users) . ' sub-user(s) from dash_users.json';
    }

    return $done;
}

// ─── Manual page (admin only) ─────────────────────────────────────────────────
// Only show the HTML interface when visited directly, not when included
if (basename(__FILE__) === basename($_SERVER['SCRIPT_FILENAME'] ?? '')) {
    require_once __DIR__ . '/auth.php';
    if (!isAdmin()) { header('Location: index.php'); exit; }

    $db  = getDashDb();
    $msg = '';

    if (!$db) {
        $msg = '<div class="msg err">MySQL is not configured. Add DASH_DB_TYPE, DASH_DB_HOST, DASH_DB_NAME, DASH_DB_USER, DASH_DB_PASS to dash_config.php first, then re-run this page.</div>';
    } elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['act'] ?? '') === 'run') {
        $targetUser = trim($_POST['target_user'] ?? getCurrentUsername());
        if (!$targetUser) $targetUser = getCurrentUsername();
        // Reset migration flag so it re-runs
        $db->prepare('DELETE FROM dash_settings WHERE username=? AND setting_key=\'migration_done\'')
           ->execute([$targetUser]);
        $done = dashRunMigration($db, $targetUser);
        $msg  = '<div class="msg ok">✅ Migration complete for <strong>'.htmlspecialchars($targetUser).'</strong>.<br>'
              . (empty($done) ? 'Nothing new to migrate (data was already in MySQL).'
                              : 'Migrated: ' . implode(', ', $done) . '.')
              . '</div>';
    }

    $cfg = getDashConfig();
    $allUsers = [['username' => $cfg['username']]];
    foreach (dashGetUsers($db) as $u) $allUsers[] = $u;
    $allUsers = array_unique(array_column($allUsers, 'username'));
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>MySQL Migration — Dashboard</title>
<link rel="icon" type="image/svg+xml" href="favicon.svg">
<style>
*{box-sizing:border-box;margin:0;padding:0}
body{font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',sans-serif;background:#0a0a1a;color:#e0e6ff;min-height:100vh;padding:28px 20px}
h1{font-size:22px;margin-bottom:6px}
.subtitle{font-size:13px;opacity:.5;margin-bottom:28px}
.card{background:#111827;border:1px solid rgba(255,255,255,.1);border-radius:12px;padding:20px;margin-bottom:18px}
.card h2{font-size:15px;font-weight:700;margin-bottom:12px}
.msg{padding:12px 16px;border-radius:8px;margin-bottom:18px;font-size:13px;line-height:1.5}
.msg.ok{background:rgba(74,222,128,.15);border:1px solid rgba(74,222,128,.3);color:#4ade80}
.msg.err{background:rgba(248,113,113,.15);border:1px solid rgba(248,113,113,.3);color:#f87171}
label{font-size:13px;display:block;margin-bottom:6px}
select,input{padding:8px 12px;border-radius:8px;border:1px solid rgba(255,255,255,.15);background:#1e293b;color:#e0e6ff;font-size:13px;margin-bottom:10px}
button{padding:9px 20px;border-radius:8px;border:none;cursor:pointer;font-size:13px;font-weight:600;background:#4a9eff;color:#fff}
a{color:#4a9eff}
code{background:rgba(255,255,255,.08);padding:1px 5px;border-radius:4px;font-size:12px}
p{font-size:13px;opacity:.8;line-height:1.6;margin-bottom:10px}
</style>
</head>
<body>
<h1>🗄 MySQL Migration Tool</h1>
<p class="subtitle">Import your existing JSON / filesystem data into MySQL for a user.</p>
<?= $msg ?>
<div class="card">
<h2>📋 What this does</h2>
<p>For the selected user, this reads all existing JSON data files and filesystem doc folders, then inserts them into MySQL <strong>without overwriting existing MySQL records</strong>. Safe to run multiple times. After migration, all new saves go to MySQL automatically.</p>
<p>Data sources migrated: <code>dash_state.json</code>, <code>dash_links.json</code>, <code>dash_page_folders.json</code>, <code>dash_*_widgets.json</code>, <code>dash_custom_bg.json</code>, <code>dash_custom_theme.json</code>, <code>dash_hidden_themes.json</code>, <code>dash_drives.json</code>, <code>dash_monitor.json</code>, <code>dash_layouts.json</code>, <code>uploads/docs/</code>, <code>dash_users.json</code>.</p>
</div>
<div class="card">
<h2>▶ Run Migration</h2>
<form method="POST">
<input type="hidden" name="act" value="run">
<label>Migrate data for user:</label>
<select name="target_user">
<?php foreach ($allUsers as $u): ?>
<option value="<?= htmlspecialchars($u) ?>"><?= htmlspecialchars($u) ?></option>
<?php endforeach; ?>
</select>
<br>
<button type="submit">Run Migration Now</button>
</form>
</div>
<div style="margin-top:12px;font-size:12px;opacity:.4">
<a href="options.php">← Options</a> &nbsp; <a href="index.php">Dashboard →</a>
</div>
</body>
</html>
<?php
}

<?php
// Show ALL errors so you can see exactly what went wrong
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

if (session_status() === PHP_SESSION_NONE) session_start();

// ── Simple auth ────────────────────────────────────────────────────────────
$PASS = 'recover2024'; // emergency password (change if you want)
$ok   = !empty($_SESSION['logged_in']) || !empty($_SESSION['recover_ok']);
if (!$ok && !empty($_POST['p']) && $_POST['p'] === $PASS) {
    $_SESSION['recover_ok'] = true;
    $ok = true;
}

if (!$ok) { showLogin(); exit; }

// ── Includes ───────────────────────────────────────────────────────────────
require_once __DIR__ . '/db.php';

$db   = getDashDb();
$user = '';
foreach (['sub_user','dash_user','username'] as $k) {
    if (!empty($_SESSION[$k])) { $user = $_SESSION[$k]; break; }
}
$user = preg_replace('/[^a-zA-Z0-9_-]/', '', strtolower($user));
if ($user === '') $user = 'admin';

// ── Handle POST actions ────────────────────────────────────────────────────
$notice = '';
$action = $_POST['action'] ?? '';

if ($action === 'restore_backup') {
    if ($db) {
        $raw = dashGetSetting($db, $user, 'dash_links_backup', '');
    } else {
        $raw = @file_get_contents(__DIR__ . '/dash_links_backup.json') ?: '';
    }
    $bk = $raw ? json_decode($raw, true) : null;
    if (is_array($bk) && !empty($bk['links'])) {
        dashSetLinks($db, $user, $bk['links']);
        $cnt = count($bk['links']);
        $at  = isset($bk['saved_at']) ? $bk['saved_at'] : '?';
        $notice = "ok:Restored $cnt column(s) from auto-backup (saved $at). Reload dashboard.";
    } else {
        $notice = 'err:Auto-backup is empty or missing.';
    }
}

if ($action === 'restore_json') {
    $jl = _dashJsonRead(__DIR__ . '/dash_links.json', array());
    if (!empty($jl)) {
        dashSetLinks($db, $user, $jl);
        $cnt = count($jl);
        $notice = "ok:Restored $cnt column(s) from dash_links.json. Reload dashboard.";
    } else {
        $notice = 'err:dash_links.json is empty or not found.';
    }
}

if ($action === 'restore_paste') {
    $raw   = trim(isset($_POST['json']) ? $_POST['json'] : '');
    $links = $raw ? json_decode($raw, true) : null;
    if (is_array($links) && !empty($links)) {
        dashSetLinks($db, $user, $links);
        $cnt = count($links);
        $notice = "ok:Restored $cnt column(s) from pasted JSON. Reload dashboard.";
    } else {
        $notice = 'err:Invalid or empty JSON. Make sure it is an array [...].';
    }
}

if ($action === 'restore_bgs') {
    $bgs = _dashJsonRead(__DIR__ . '/dash_custom_bg.json', array());
    if (!empty($bgs)) {
        dashSetCustomBgs($db, $user, $bgs);
        $cnt = count($bgs);
        $notice = "ok:Restored $cnt custom background(s) from JSON. Reload dashboard.";
    } else {
        $notice = 'err:dash_custom_bg.json is empty or not found.';
    }
}

// ── Gather data ────────────────────────────────────────────────────────────

// Current MySQL links
$curLinks = array();
if ($db) {
    $st = $db->prepare('SELECT data FROM dash_links WHERE username=?');
    $st->execute(array($user));
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if ($row) {
        $decoded = json_decode($row['data'], true);
        if (is_array($decoded)) $curLinks = $decoded;
    }
}

// Auto-backup
$bkRaw = '';
if ($db) {
    $bkRaw = dashGetSetting($db, $user, 'dash_links_backup', '');
} else {
    $bkRaw = @file_get_contents(__DIR__ . '/dash_links_backup.json') ?: '';
}
$bkData  = $bkRaw ? json_decode($bkRaw, true) : null;
$bkLinks = (is_array($bkData) && isset($bkData['links']) && is_array($bkData['links'])) ? $bkData['links'] : array();
$bkAt    = (is_array($bkData) && isset($bkData['saved_at'])) ? $bkData['saved_at'] : 'unknown';

// JSON file links
$jsonLinks = _dashJsonRead(__DIR__ . '/dash_links.json', array());
if (!is_array($jsonLinks)) $jsonLinks = array();

// Custom backgrounds
$curBgs  = $db ? dashGetCustomBgs($db, $user) : array();
$jsonBgs = _dashJsonRead(__DIR__ . '/dash_custom_bg.json', array());
if (!is_array($jsonBgs)) $jsonBgs = array();

// All settings keys in MySQL
$allKeys = array();
if ($db) {
    $st2 = $db->prepare("SELECT setting_key, LENGTH(setting_val) as sz, updated_at FROM dash_settings WHERE username=? ORDER BY updated_at DESC");
    $st2->execute(array($user));
    $allKeys = $st2->fetchAll(PDO::FETCH_ASSOC);
    if (!is_array($allKeys)) $allKeys = array();
}

// Upload files
$uploadDir   = __DIR__ . '/uploads/custom_bg/' . $user;
$uploadFiles = array();
if (is_dir($uploadDir)) {
    $gf = glob($uploadDir . '/*');
    if ($gf) foreach ($gf as $f) { if (is_file($f)) $uploadFiles[] = basename($f); }
}

// Helper
function secSummary($links) {
    $cards = 0;
    $names = array();
    foreach ($links as $s) {
        $c = isset($s['cards']) && is_array($s['cards']) ? count($s['cards']) : 0;
        $cards += $c;
        $title = isset($s['title']) ? $s['title'] : (isset($s['id']) ? $s['id'] : '?');
        $icon  = isset($s['icon'])  ? $s['icon']  : '';
        $names[] = $icon . ' ' . $title;
    }
    $n = count($links);
    $preview = implode(' · ', array_slice($names, 0, 5));
    if (count($names) > 5) $preview .= '…';
    return "$n column(s), $cards card(s): " . htmlspecialchars($preview);
}

// Split notice
$nType = ''; $nText = '';
if ($notice) {
    $p = strpos($notice, ':');
    $nType = substr($notice, 0, $p);
    $nText = substr($notice, $p + 1);
}

showPage($user, $db, $curLinks, $bkLinks, $bkAt, $jsonLinks, $curBgs, $jsonBgs, $uploadFiles, $allKeys, $nType, $nText);
exit;

// ── View functions ─────────────────────────────────────────────────────────
function showLogin() {
?><!DOCTYPE html><html><head><meta charset="utf-8"><title>Recovery</title>
<style>body{background:#07090f;color:#94a3b8;display:flex;align-items:center;justify-content:center;min-height:100vh;font-family:sans-serif;}
.b{background:#0f1623;border:1px solid #334155;border-radius:12px;padding:36px;max-width:360px;width:90%;text-align:center;}
h2{color:#f8fafc;margin:0 0 10px;}p{font-size:13px;margin:0 0 18px;}
input{width:100%;padding:9px 12px;background:#1e2a3a;border:1px solid #334155;border-radius:7px;color:#e2e8f0;font-size:14px;box-sizing:border-box;margin:8px 0;}
button{width:100%;padding:10px;background:#3b82f6;color:#fff;border:none;border-radius:7px;font-size:14px;cursor:pointer;}
.hint{font-size:11px;opacity:.4;margin-top:12px;}
</style></head><body>
<div class="b"><h2>🔧 Dashboard Recovery</h2>
<p>Log in to the dashboard first, or enter the emergency password:</p>
<form method="POST"><input type="password" name="p" placeholder="Password (default: recover2024)" autofocus>
<button type="submit">Unlock</button></form>
<div class="hint">Edit recover.php line 8 to change the password.</div>
</div></body></html><?php
}

function showPage($user, $db, $curLinks, $bkLinks, $bkAt, $jsonLinks, $curBgs, $jsonBgs, $uploadFiles, $allKeys, $nType, $nText) {
$backend = $db ? 'MySQL' : 'JSON files';
$curN  = count($curLinks);
$bkN   = count($bkLinks);
$jsonN = count($jsonLinks);
?><!DOCTYPE html><html><head><meta charset="utf-8"><title>Dashboard Recovery</title>
<style>
*{box-sizing:border-box;margin:0;padding:0;}
body{font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;background:#07090f;color:#94a3b8;padding:24px 14px;}
.w{max-width:820px;margin:0 auto;}
h1{font-size:20px;color:#f1f5f9;margin-bottom:4px;}
.meta{font-size:12px;color:#64748b;margin-bottom:20px;}
.meta a{color:#4a9eff;text-decoration:none;}
h2{font-size:14px;color:#cbd5e1;margin:22px 0 9px;border-bottom:1px solid #1e293b;padding-bottom:5px;}
.card{background:#0f1623;border:1px solid #1e2d42;border-radius:10px;padding:16px 18px;margin-bottom:12px;}
.card.g{border-color:rgba(34,197,94,.35);}
.card.r{border-color:rgba(239,68,68,.35);}
.card.y{border-color:rgba(234,179,8,.3);}
.label{font-size:14px;font-weight:600;color:#e2e8f0;}
.badge{display:inline-block;font-size:11px;font-weight:700;padding:2px 8px;border-radius:10px;margin-left:8px;}
.bg{background:rgba(34,197,94,.2);color:#4ade80;}
.br{background:rgba(239,68,68,.2);color:#f87171;}
.by{background:rgba(234,179,8,.2);color:#facc15;}
.sum{font-size:12px;color:#94a3b8;margin-top:5px;}
.sum.dim{color:#64748b;}
details summary{font-size:12px;color:#475569;cursor:pointer;margin-top:8px;user-select:none;}
details summary:hover{color:#94a3b8;}
.code{background:#020712;border:1px solid #1e293b;border-radius:7px;padding:10px 12px;font-family:monospace;font-size:12px;color:#7dd3fc;overflow-x:auto;white-space:pre-wrap;word-break:break-all;margin-top:8px;max-height:300px;overflow-y:auto;}
form{margin-top:10px;}
button.restore{padding:8px 16px;background:#3b82f6;color:#fff;border:none;border-radius:7px;font-size:13px;font-weight:600;cursor:pointer;}
button.restore:hover{background:#2563eb;}
button.jp{background:#7c3aed;}button.jp:hover{background:#6d28d9;}
button.bg-btn{background:#0891b2;}button.bg-btn:hover{background:#0e7490;}
textarea{width:100%;background:#07090f;border:1px solid #334155;border-radius:7px;color:#94a3b8;font-family:monospace;font-size:12px;padding:9px;resize:vertical;margin-top:8px;}
.notice{padding:12px 16px;border-radius:8px;font-size:13px;font-weight:500;margin-bottom:18px;}
.notice.ok{background:rgba(34,197,94,.12);border:1px solid rgba(34,197,94,.3);color:#4ade80;}
.notice.err{background:rgba(239,68,68,.12);border:1px solid rgba(239,68,68,.3);color:#f87171;}
.sqlbox{background:#020712;border:1px solid #1e2d42;border-radius:7px;padding:10px;font-family:monospace;font-size:11px;color:#7dd3fc;margin-top:6px;white-space:pre-wrap;word-break:break-all;}
.warn{background:rgba(234,179,8,.08);border:1px solid rgba(234,179,8,.3);border-radius:8px;padding:10px 14px;font-size:12px;color:#fde68a;margin-top:22px;}
</style></head><body>
<div class="w">
  <h1>🔧 Dashboard Recovery</h1>
  <div class="meta">User: <strong style="color:#e2e8f0;"><?= htmlspecialchars($user) ?></strong>
    &nbsp;·&nbsp; Backend: <strong style="color:#e2e8f0;"><?= htmlspecialchars($backend) ?></strong>
    &nbsp;·&nbsp; <a href="index.php">← Dashboard</a>
    &nbsp;·&nbsp; <a href="options.php">Options</a>
  </div>

<?php if ($nText): ?>
  <div class="notice <?= htmlspecialchars($nType) ?>"><?= htmlspecialchars($nText) ?></div>
<?php endif; ?>

  <!-- ── Current live data ── -->
  <h2>📊 Live Data — what the dashboard reads now</h2>
  <div class="card <?= $curN > 0 ? 'g' : 'r' ?>">
    <span class="label">MySQL dash_links</span>
    <span class="badge <?= $curN > 0 ? 'bg' : 'br' ?>"><?= $curN > 0 ? $curN . ' cols' : 'EMPTY' ?></span>
    <?php if ($curN > 0): ?>
    <div class="sum"><?= secSummary($curLinks) ?></div>
    <details><summary>View raw JSON…</summary>
    <div class="code"><?= htmlspecialchars(json_encode($curLinks, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) ?></div>
    </details>
    <?php else: ?>
    <div class="sum dim">No row found in dash_links for "<?= htmlspecialchars($user) ?>".</div>
    <?php endif; ?>
  </div>

  <!-- ── Auto-backup ── -->
  <h2>💾 Auto-backup (written by save_links.php on every save)</h2>
  <div class="card <?= $bkN > 0 ? 'g' : ($bkRaw ? 'y' : 'r') ?>">
    <span class="label">Backup in dash_settings</span>
    <span class="badge <?= $bkN > 0 ? 'bg' : ($bkRaw ? 'by' : 'br') ?>">
      <?= $bkN > 0 ? $bkN . ' cols · ' . htmlspecialchars($bkAt) : ($bkRaw ? 'Found but 0 links' : 'NOT FOUND') ?>
    </span>
    <?php if ($bkN > 0): ?>
    <div class="sum"><?= secSummary($bkLinks) ?></div>
    <form method="POST">
      <input type="hidden" name="action" value="restore_backup">
      <button class="restore" type="submit"
        onclick="return confirm('Restore <?= $bkN ?> column(s) from auto-backup?\nThis overwrites current live data.')">
        ♻ Restore from auto-backup
      </button>
    </form>
    <details><summary>View raw JSON…</summary>
    <div class="code"><?= htmlspecialchars(json_encode($bkLinks, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) ?></div>
    </details>
    <?php elseif ($bkRaw): ?>
    <div class="sum dim">Backup record exists but links array is empty.<br>
    Raw: <?= htmlspecialchars(substr($bkRaw, 0, 300)) ?></div>
    <?php else: ?>
    <div class="sum dim">No auto-backup found. It is created every time you save links from the dashboard.</div>
    <?php endif; ?>
  </div>

  <!-- ── JSON file ── -->
  <h2>📄 dash_links.json (JSON-mode fallback file)</h2>
  <div class="card <?= $jsonN > 0 ? 'g' : 'r' ?>">
    <span class="label">dash_links.json</span>
    <span class="badge <?= $jsonN > 0 ? 'bg' : 'br' ?>"><?= $jsonN > 0 ? $jsonN . ' cols' : (file_exists(__DIR__.'/dash_links.json') ? 'Empty' : 'NOT FOUND') ?></span>
    <?php if ($jsonN > 0): ?>
    <div class="sum"><?= secSummary($jsonLinks) ?></div>
    <form method="POST">
      <input type="hidden" name="action" value="restore_json">
      <button class="restore jp" type="submit"
        onclick="return confirm('Copy dash_links.json → MySQL and make it live?\nThis overwrites current live data.')">
        ♻ Restore from dash_links.json
      </button>
    </form>
    <details><summary>View raw JSON…</summary>
    <div class="code"><?= htmlspecialchars(json_encode($jsonLinks, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) ?></div>
    </details>
    <?php else: ?>
    <div class="sum dim">File <?= file_exists(__DIR__.'/dash_links.json') ? 'exists but is empty' : 'does not exist on server' ?>.</div>
    <?php endif; ?>
  </div>

  <!-- ── Paste JSON ── -->
  <h2>📋 Paste JSON (from browser or export)</h2>
  <div class="card">
    <div class="sum">Copy from browser console (open dashboard tab, press F12, paste):</div>
    <div class="code">var u=window.HP_USER||'admin';
var b=localStorage.getItem('hp-links-backup-'+u);
if(b){console.log(JSON.stringify(JSON.parse(b).d,null,2));}
else{console.log(JSON.stringify(window.WIN9X_LINKS||[],null,2));}</div>
    <form method="POST">
      <input type="hidden" name="action" value="restore_paste">
      <textarea name="json" rows="7" placeholder="Paste the JSON array here — starts with [ ..."></textarea>
      <button class="restore" type="submit" style="margin-top:8px;"
        onclick="return confirm('Restore from pasted JSON?\nThis overwrites current live data.')">
        ♻ Restore from Pasted JSON
      </button>
    </form>
  </div>

  <!-- ── Custom backgrounds ── -->
  <h2>🖼 Custom Backgrounds</h2>
  <div class="card">
    <span class="label">MySQL dash_custom_bgs</span>
    <span class="badge <?= count($curBgs) > 0 ? 'bg' : 'br' ?>"><?= count($curBgs) ?> entry/entries</span>
    <?php if (!empty($curBgs)): ?>
    <div class="sum"><?php foreach ($curBgs as $b) { $n=isset($b['name'])?htmlspecialchars($b['name']):'?'; echo "$n &nbsp;"; } ?></div>
    <?php endif; ?>
    <?php if (!empty($jsonBgs)): ?>
    <div class="sum" style="margin-top:10px;">dash_custom_bg.json has <?= count($jsonBgs) ?> entry/entries</div>
    <form method="POST">
      <input type="hidden" name="action" value="restore_bgs">
      <button class="restore bg-btn" type="submit"
        onclick="return confirm('Copy dash_custom_bg.json → MySQL?')">
        ♻ Restore custom backgrounds from JSON
      </button>
    </form>
    <?php endif; ?>
    <div class="sum dim" style="margin-top:10px;">Uploaded image files in
      <code>uploads/custom_bg/<?= htmlspecialchars($user) ?>/</code>:
      <?= !empty($uploadFiles) ? htmlspecialchars(implode(', ', array_slice($uploadFiles, 0, 8))) : 'none found' ?>
    </div>
  </div>

  <!-- ── MySQL settings keys ── -->
<?php if (!empty($allKeys)): ?>
  <h2>🗄 All MySQL settings for "<?= htmlspecialchars($user) ?>"</h2>
  <div class="card">
  <table style="width:100%;border-collapse:collapse;font-size:12px;">
  <tr><th style="text-align:left;color:#64748b;padding:3px 0;">Key</th><th style="color:#64748b;padding:3px 8px;">Bytes</th><th style="color:#64748b;">Updated</th></tr>
  <?php foreach ($allKeys as $k): ?>
  <tr style="border-top:1px solid #1e293b;">
    <td style="padding:4px 0;color:#94a3b8;"><?= htmlspecialchars($k['setting_key']) ?></td>
    <td style="text-align:right;padding:4px 8px;color:#64748b;"><?= (int)$k['sz'] ?></td>
    <td style="color:#64748b;padding:4px 0;"><?= htmlspecialchars($k['updated_at'] ?? '') ?></td>
  </tr>
  <?php endforeach; ?>
  </table>
  </div>
<?php endif; ?>

  <!-- ── SQL commands ── -->
  <h2>🛠 Run these in phpMyAdmin / MySQL CLI</h2>
  <div class="card">
  <p style="font-size:12px;color:#94a3b8;margin-bottom:8px;">Check dash_links:</p>
  <div class="sqlbox">SELECT username, LENGTH(data) as bytes, updated_at FROM dash_links WHERE username='<?= htmlspecialchars($user) ?>';</div>
  <p style="font-size:12px;color:#94a3b8;margin:10px 0 8px;">Check backup:</p>
  <div class="sqlbox">SELECT setting_key, LENGTH(setting_val) as bytes, updated_at FROM dash_settings WHERE username='<?= htmlspecialchars($user) ?>' AND setting_key='dash_links_backup';</div>
  <p style="font-size:12px;color:#94a3b8;margin:10px 0 8px;">Extract backup links only:</p>
  <div class="sqlbox">SELECT JSON_UNQUOTE(JSON_EXTRACT(setting_val,'$.links')) FROM dash_settings WHERE username='<?= htmlspecialchars($user) ?>' AND setting_key='dash_links_backup';</div>
  </div>

  <div class="warn">⚠️ <strong>Delete recover.php from your server</strong> once you are done. It exposes your data to anyone who knows the password.</div>
</div>
</body></html><?php
}

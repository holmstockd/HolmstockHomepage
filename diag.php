<?php
/**
 * diag.php — Dashboard Diagnostics
 * Shows version, PHP info, MySQL state, and folder isolation status.
 * Also provides the "Wipe all doc folders" clean-slate action.
 */
define('DASH_VERSION', '1.4.3');

if (session_status() === PHP_SESSION_NONE) session_start();

// Auth guard — require login
if (!file_exists(__DIR__ . '/dash_config.php')) {
    die('<h2 style="font-family:monospace;color:red;">dash_config.php not found — run setup.php first.</h2>');
}
if (empty($_SESSION['logged_in'])) {
    if (isset($_COOKIE['dash_auth'])) @include_once __DIR__ . '/auth.php';
    if (empty($_SESSION['logged_in'])) {
        header('Location: login.php'); exit;
    }
}

require_once __DIR__ . '/db.php';

$_dashUser = $_SESSION['sub_user'] ?? $_SESSION['dash_user'] ?? $_SESSION['username'] ?? 'admin';
$_dashUser = preg_replace('/[^a-zA-Z0-9_-]/', '', strtolower($_dashUser)) ?: 'admin';
$_db       = getDashDb();
$baseDir   = __DIR__ . '/uploads/docs/' . $_dashUser;

// ── Handle POST actions ───────────────────────────────────────────────────
$msg = ''; $msgType = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $act = $_POST['act'] ?? '';

    if ($act === 'wipe_folders') {
        $wiped = 0;
        if (is_dir($baseDir)) {
            foreach (scandir($baseDir) as $e) {
                if ($e[0] === '.') continue;
                $dp = $baseDir . '/' . $e;
                if (!is_dir($dp)) continue;
                // Recursively remove
                $it = new RecursiveIteratorIterator(
                    new RecursiveDirectoryIterator($dp, RecursiveDirectoryIterator::SKIP_DOTS),
                    RecursiveIteratorIterator::CHILD_FIRST
                );
                foreach ($it as $item) { $item->isDir() ? rmdir($item) : unlink($item); }
                rmdir($dp);
                $wiped++;
            }
        }
        if ($_db) {
            $_db->prepare('DELETE FROM dash_doc_files   WHERE username=?')->execute([$_dashUser]);
            $_db->prepare('DELETE FROM dash_doc_folders WHERE username=?')->execute([$_dashUser]);
        }
        $msg = "Wiped $wiped folder(s) from disk and cleared MySQL records for user: $_dashUser. All folder widgets on the dashboard are now empty — open them to create fresh folders.";
        $msgType = 'ok';
    }

    if ($act === 'wipe_page_folders') {
        if ($_db) {
            $_db->prepare('DELETE FROM dash_page_folders WHERE username=?')->execute([$_dashUser]);
        }
        @file_put_contents(__DIR__ . '/dash_page_folders.json', '[]');
        $msg = "Wiped all page folder widgets for user: $_dashUser. Reload the dashboard — the 📁 icons will be gone. Use '📁 + Folder' to create fresh ones.";
        $msgType = 'ok';
    }
}

// ── Gather diagnostic data ────────────────────────────────────────────────

// MySQL connection test
$mysqlOk  = false;
$mysqlErr = '';
$mysqlVer = '';
if ($_db) {
    try {
        $mysqlVer = $_db->query('SELECT VERSION()')->fetchColumn();
        $mysqlOk  = true;
    } catch (Throwable $e) {
        $mysqlErr = $e->getMessage();
    }
}

// Doc folders: MySQL list
$dbFolders = [];
if ($_db && $mysqlOk) {
    $s = $_db->prepare('SELECT dir_key, label FROM dash_doc_folders WHERE username=? ORDER BY sort_order, label');
    $s->execute([$_dashUser]);
    foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $r) $dbFolders[$r['dir_key']] = $r['label'];
}

// Doc folders: filesystem list
$fsFolders = [];
if (is_dir($baseDir)) {
    foreach (scandir($baseDir) as $e) {
        if ($e[0] === '.') continue;
        $dp = $baseDir . '/' . $e;
        if (!is_dir($dp)) continue;
        $mf = $dp . '/_meta.json';
        $meta  = file_exists($mf) ? (json_decode(@file_get_contents($mf), true) ?: []) : [];
        $files = array_filter(glob($dp . '/*') ?: [], fn($f) => is_file($f) && basename($f) !== '_meta.json');
        $fsFolders[$e] = ['label' => $meta['label'] ?? $e, 'files' => count($files), 'has_meta' => file_exists($mf)];
    }
}

// Page folder widgets
$pageFolders = [];
if ($_db) {
    $s = $_db->prepare('SELECT data FROM dash_page_folders WHERE username=?');
    $s->execute([$_dashUser]);
    $row = $s->fetch(PDO::FETCH_ASSOC);
    if ($row) $pageFolders = json_decode($row['data'], true) ?: [];
} else {
    $pageFolders = _dashJsonRead(__DIR__ . '/dash_page_folders.json', []);
}

// MySQL file counts per folder
$dbFileCounts = [];
if ($_db && $mysqlOk) {
    $s = $_db->prepare('SELECT dir_key, COUNT(*) as cnt FROM dash_doc_files WHERE username=? GROUP BY dir_key');
    $s->execute([$_dashUser]);
    foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $r) $dbFileCounts[$r['dir_key']] = (int)$r['cnt'];
}

// All unique dir_keys across all sources
$allKeys = array_unique(array_merge(
    array_keys($dbFolders),
    array_keys($fsFolders),
    array_column($pageFolders, 'dir_key')
));
sort($allKeys);
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Dashboard Diagnostics v<?= DASH_VERSION ?></title>
<link rel="icon" type="image/svg+xml" href="favicon.svg">
<style>
*{box-sizing:border-box;margin:0;padding:0}
body{font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',monospace;background:#0a0a1a;color:#e0e6ff;min-height:100vh;padding:24px 18px;font-size:13px;}
h1{font-size:20px;margin-bottom:4px;font-family:sans-serif;}
.sub{font-size:12px;opacity:.45;margin-bottom:20px;}
.card{background:#111827;border:1px solid rgba(255,255,255,.1);border-radius:10px;padding:16px;margin-bottom:14px;}
.card h2{font-size:13px;font-weight:700;margin-bottom:10px;text-transform:uppercase;letter-spacing:.05em;opacity:.7;}
.row{display:flex;justify-content:space-between;align-items:center;padding:5px 0;border-bottom:1px solid rgba(255,255,255,.05);}
.row:last-child{border-bottom:none;}
.label{opacity:.6;}
.val{font-weight:600;}
.ok{color:#4ade80;} .warn{color:#facc15;} .err{color:#f87171;}
table{width:100%;border-collapse:collapse;font-size:12px;}
th,td{padding:6px 8px;text-align:left;border-bottom:1px solid rgba(255,255,255,.05);}
th{font-size:10px;text-transform:uppercase;letter-spacing:.05em;opacity:.4;font-weight:600;}
td code{background:rgba(255,255,255,.08);padding:1px 5px;border-radius:3px;font-size:11px;}
.badge{display:inline-block;font-size:10px;padding:1px 6px;border-radius:3px;font-weight:700;}
.badge-ok{background:rgba(74,222,128,.2);color:#4ade80;border:1px solid rgba(74,222,128,.3);}
.badge-warn{background:rgba(250,204,21,.15);color:#facc15;border:1px solid rgba(250,204,21,.3);}
.badge-err{background:rgba(248,113,113,.15);color:#f87171;border:1px solid rgba(248,113,113,.3);}
.badge-none{background:rgba(255,255,255,.08);color:rgba(255,255,255,.4);border:1px solid rgba(255,255,255,.1);}
.msg{padding:10px 14px;border-radius:8px;margin-bottom:14px;font-size:13px;line-height:1.5;}
.msg.ok{background:rgba(74,222,128,.12);border:1px solid rgba(74,222,128,.3);color:#4ade80;}
.msg.err{background:rgba(248,113,113,.12);border:1px solid rgba(248,113,113,.3);color:#f87171;}
button,a.btn{display:inline-block;padding:8px 16px;border-radius:7px;border:none;cursor:pointer;font-size:12px;font-weight:700;text-decoration:none;}
.btn-danger{background:rgba(200,50,50,.3);color:#ff9999;border:1px solid rgba(200,50,50,.4);}
.btn-warn{background:rgba(250,150,0,.2);color:#facc15;border:1px solid rgba(250,150,0,.3);}
.btn-primary{background:#4a9eff;color:#fff;}
.btn-back{background:rgba(255,255,255,.1);color:#e0e6ff;border:1px solid rgba(255,255,255,.2);}
.actions{display:flex;gap:10px;flex-wrap:wrap;margin-top:10px;}
.muted{opacity:.4;}
</style>
</head>
<body>

<h1>🔬 Dashboard Diagnostics</h1>
<p class="sub">Version <strong style="color:#4ade80;"><?= DASH_VERSION ?></strong> &nbsp;·&nbsp; User: <strong><?= htmlspecialchars($_dashUser) ?></strong> &nbsp;·&nbsp; PHP <?= PHP_VERSION ?> &nbsp;·&nbsp; <?= date('Y-m-d H:i:s') ?></p>

<?php if ($msg): ?>
<div class="msg <?= $msgType ?>"><?= htmlspecialchars($msg) ?></div>
<?php endif; ?>

<!-- ─────────── SESSION / AUTH STATE (v1.4.1) ───────────
     Use this if a sub-user reports "I see admin's dashboard".
     Open diag.php while logged in as the affected user. The values below
     come straight from PHP_SESSION and the auth.php helpers, so whatever
     they show here IS what index.php sees on the same request. -->
<?php
@include_once __DIR__ . '/auth.php';
$_diag_role  = function_exists('getCurrentRole')     ? getCurrentRole()     : '?';
$_diag_uname = function_exists('getCurrentUsername') ? getCurrentUsername() : '?';
$_diag_admin = function_exists('isAdmin')            ? isAdmin()            : null;
?>
<div class="card" style="border-color:rgba(250,204,21,.4);">
  <h2 style="color:#facc15;">🔐 Session / Auth (debug)</h2>
  <div class="row"><span class="label">getCurrentUsername()</span><span class="val ok"><?= htmlspecialchars((string)$_diag_uname) ?></span></div>
  <div class="row"><span class="label">getCurrentRole()</span><span class="val <?= $_diag_role==='admin'?'warn':'ok' ?>"><?= htmlspecialchars((string)$_diag_role) ?></span></div>
  <div class="row"><span class="label">isAdmin()</span><span class="val <?= $_diag_admin?'warn':'ok' ?>"><?= $_diag_admin ? 'TRUE — sees admin UI' : 'false — sees own data' ?></span></div>
  <div class="row"><span class="label">$_SESSION['logged_in']</span><span class="val"><?= !empty($_SESSION['logged_in']) ? 'true' : '<span class="err">missing</span>' ?></span></div>
  <div class="row"><span class="label">$_SESSION['sub_user']</span><span class="val"><?= isset($_SESSION['sub_user']) ? htmlspecialchars((string)$_SESSION['sub_user']) : '<span class="muted">— (admin session)</span>' ?></span></div>
  <div class="row"><span class="label">$_SESSION['sub_role']</span><span class="val"><?= isset($_SESSION['sub_role']) ? htmlspecialchars((string)$_SESSION['sub_role']) : '<span class="muted">—</span>' ?></span></div>
  <div class="row"><span class="label">PHP session ID</span><span class="val" style="font-size:11px;font-family:monospace;"><?= htmlspecialchars(session_id()) ?></span></div>
  <div class="row"><span class="label">Session save path</span><span class="val" style="font-size:11px;font-family:monospace;"><?= htmlspecialchars(session_save_path() ?: ini_get('session.save_path') ?: '(default)') ?></span></div>
  <div class="row"><span class="label">Session cookie params</span><span class="val" style="font-size:11px;font-family:monospace;"><?php $cp=session_get_cookie_params(); echo htmlspecialchars('path='.$cp['path'].' secure='.($cp['secure']?'1':'0').' httponly='.($cp['httponly']?'1':'0').' samesite='.($cp['samesite']??'?')); ?></span></div>
  <div class="row"><span class="label">Cookies sent by browser</span><span class="val" style="font-size:11px;font-family:monospace;word-break:break-all;text-align:right;max-width:60%;"><?= htmlspecialchars(implode(', ', array_keys($_COOKIE ?: []))) ?></span></div>
  <p style="font-size:11px;opacity:.6;margin-top:10px;line-height:1.5;">
    <strong>If isAdmin()=TRUE here while logged in as a sub-user</strong> → session is broken (sub_user not stored or got cleared between login.php and this page). Check session save path, file permissions, and whether your host allows session cookies on the same path. <br>
    <strong>If isAdmin()=false here but you still see admin's dashboard</strong> → it's a browser/proxy cache issue. Hard-refresh (Ctrl+Shift+R) — v1.4.1 added no-cache headers to index.php to prevent this.
  </p>
</div>

<!-- System info -->
<div class="card">
  <h2>⚙️ System</h2>
  <div class="row"><span class="label">Dashboard version</span><span class="val ok"><?= DASH_VERSION ?></span></div>
  <div class="row"><span class="label">PHP version</span><span class="val"><?= PHP_VERSION ?></span></div>
  <div class="row"><span class="label">Server software</span><span class="val"><?= htmlspecialchars($_SERVER['SERVER_SOFTWARE'] ?? 'unknown') ?></span></div>
  <div class="row"><span class="label">Files writable?</span>
    <span class="val <?= is_writable(__DIR__) ? 'ok' : 'err' ?>"><?= is_writable(__DIR__) ? '✓ Yes' : '✗ No — uploads/saves will fail' ?></span>
  </div>
  <div class="row"><span class="label">uploads/ writable?</span>
    <span class="val <?= is_writable(__DIR__.'/uploads') || !is_dir(__DIR__.'/uploads') ? 'ok' : 'err' ?>">
      <?= is_dir(__DIR__.'/uploads') ? (is_writable(__DIR__.'/uploads') ? '✓ Yes' : '✗ No') : '— not created yet (will be auto-created on first upload)' ?>
    </span>
  </div>
</div>

<!-- MySQL -->
<div class="card">
  <h2>🗄 MySQL Connection</h2>
  <?php if ($mysqlOk): ?>
  <div class="row"><span class="label">Status</span><span class="val ok">✓ Connected</span></div>
  <div class="row"><span class="label">Version</span><span class="val"><?= htmlspecialchars($mysqlVer) ?></span></div>
  <div class="row"><span class="label">Folders in DB</span><span class="val"><?= count($dbFolders) ?></span></div>
  <div class="row"><span class="label">File records in DB</span><span class="val"><?= array_sum($dbFileCounts) ?></span></div>
  <?php elseif ($_db): ?>
  <div class="row"><span class="label">Status</span><span class="val err">✗ Query failed: <?= htmlspecialchars($mysqlErr) ?></span></div>
  <?php else: ?>
  <div class="row"><span class="label">Status</span><span class="val warn">⚠️ Not configured — using JSON file fallback</span></div>
  <?php endif; ?>
</div>

<!-- File isolation table -->
<div class="card">
  <h2>📁 Folder Isolation State</h2>
  <p style="font-size:11px;opacity:.5;margin-bottom:8px;">
    FS = filesystem directory &nbsp;|&nbsp; DB = MySQL record &nbsp;|&nbsp; Widget = page folder widget on dashboard<br>
    Files are read from <strong>FS only</strong> — MySQL is metadata only. Two different dir_keys = two different physical paths = impossible to share files.
  </p>
  <p style="font-size:11px;opacity:.5;margin-bottom:10px;">Base path: <code><?= htmlspecialchars($baseDir) ?></code></p>

  <?php if (empty($allKeys)): ?>
  <p class="muted">No doc folders found anywhere. Create one from the dashboard.</p>
  <?php else: ?>
  <table>
    <thead>
      <tr>
        <th>dir_key</th>
        <th>Label</th>
        <th>FS dir</th>
        <th>Files (FS)</th>
        <th>Files (DB)</th>
        <th>Widget?</th>
      </tr>
    </thead>
    <tbody>
    <?php foreach ($allKeys as $dk): ?>
    <?php
      $fsInfo    = $fsFolders[$dk] ?? null;
      $dbLabel   = $dbFolders[$dk] ?? null;
      $label     = $fsInfo['label'] ?? $dbLabel ?? $dk;
      $fsFiles   = $fsInfo ? $fsInfo['files'] : null;
      $dbFiles   = $dbFileCounts[$dk] ?? null;
      $widgetPf  = array_filter($pageFolders, fn($p) => ($p['dir_key'] ?? '') === $dk);
      $hasWidget = !empty($widgetPf);
      $fsDir     = $fsInfo !== null;
      $hasMeta   = $fsInfo['has_meta'] ?? false;

      // Isolation check: FS and DB agree?
      $issue = '';
      if ($fsFiles !== null && $dbFiles !== null && $fsFiles !== $dbFiles) {
        $issue = "FS=$fsFiles vs DB=$dbFiles"; // DB may be stale — that's OK, FS wins
      }
    ?>
    <tr>
      <td><code><?= htmlspecialchars($dk) ?></code></td>
      <td><?= htmlspecialchars($label) ?></td>
      <td>
        <?php if ($fsDir): ?>
          <span class="badge badge-ok">✓ exists</span>
          <?= $hasMeta ? '' : '<span class="badge badge-warn" style="margin-left:3px;">no _meta</span>' ?>
        <?php else: ?>
          <span class="badge badge-err">✗ missing</span>
        <?php endif; ?>
      </td>
      <td><?= $fsFiles !== null ? $fsFiles : '<span class="muted">—</span>' ?></td>
      <td><?= $dbFiles !== null ? $dbFiles : '<span class="muted">—</span>' ?></td>
      <td><?= $hasWidget ? '<span class="badge badge-ok">✓</span>' : '<span class="badge badge-none">—</span>' ?></td>
    </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <?php endif; ?>
</div>

<!-- Page folder widgets detail -->
<div class="card">
  <h2>🖱 Page Folder Widgets (stored in DB)</h2>
  <?php if (empty($pageFolders)): ?>
  <p class="muted">No page folder widgets saved. Use '📁 + Folder' on the dashboard.</p>
  <?php else: ?>
  <table>
    <thead><tr><th>Widget ID</th><th>Label</th><th>dir_key</th><th>Position</th></tr></thead>
    <tbody>
    <?php foreach ($pageFolders as $pf): ?>
    <tr>
      <td><code><?= htmlspecialchars($pf['id'] ?? '—') ?></code></td>
      <td><?= htmlspecialchars($pf['label'] ?? '—') ?></td>
      <td>
        <?php $dk2 = $pf['dir_key'] ?? ''; ?>
        <?php if ($dk2): ?>
          <code><?= htmlspecialchars($dk2) ?></code>
          <?php if (!isset($fsFolders[$dk2])): ?>
            <span class="badge badge-err" style="margin-left:3px;">FS dir missing!</span>
          <?php else: ?>
            <span class="badge badge-ok" style="margin-left:3px;">✓ FS ok</span>
          <?php endif; ?>
        <?php else: ?>
          <span class="badge badge-err">NO dir_key — will show wrong content!</span>
        <?php endif; ?>
      </td>
      <td><?= (int)($pf['pos_x']??0) ?>, <?= (int)($pf['pos_y']??0) ?></td>
    </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <?php endif; ?>
</div>

<!-- Wipe actions -->
<div class="card">
  <h2>🗑 Clean Slate Actions</h2>
  <p style="font-size:12px;opacity:.6;margin-bottom:12px;">
    Use these if folders are showing wrong content. Wiping only removes doc folder data — your links, settings, and wallpapers are untouched.
  </p>

  <form method="POST" onsubmit="return confirm('Delete ALL doc folders and their files for user: <?= htmlspecialchars($_dashUser) ?>?\n\nThis cannot be undone.')">
    <input type="hidden" name="act" value="wipe_folders">
    <button type="submit" class="btn-danger">🗑 Wipe All Doc Folder Data (files + MySQL)</button>
  </form>
  <p style="font-size:11px;opacity:.4;margin-top:6px;">Removes all folder directories from <code>uploads/docs/<?= htmlspecialchars($_dashUser) ?>/</code> and clears MySQL folder/file records. Page folder widgets on the dashboard remain — open one to start fresh.</p>

  <hr style="border:none;border-top:1px solid rgba(255,255,255,.07);margin:16px 0;">

  <form method="POST" onsubmit="return confirm('Remove all 📁 folder widgets from the dashboard?\n\nYou can re-create them with the + Folder button.')">
    <input type="hidden" name="act" value="wipe_page_folders">
    <button type="submit" class="btn-warn">📁 Remove All Page Folder Widgets from Dashboard</button>
  </form>
  <p style="font-size:11px;opacity:.4;margin-top:6px;">Removes all 📁 icons from the dashboard. Does not delete uploaded files.</p>
</div>

<!-- Navigation -->
<div class="actions">
  <a href="index.php"   class="btn btn-back">← Dashboard</a>
  <a href="options.php" class="btn btn-back">⚙️ Options</a>
  <a href="migrate.php" class="btn btn-back">🗄 Migration</a>
  <a href="diag.php"    class="btn btn-primary">🔄 Refresh</a>
</div>

<p style="font-size:10px;opacity:.25;margin-top:14px;">diag.php — only visible to logged-in users. Delete when not needed.</p>

</body>
</html>

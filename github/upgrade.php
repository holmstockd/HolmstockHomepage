<?php
/**
 * Dashboard Upgrade / Install Helper
 * Run this page AFTER extracting a new ZIP over your existing installation.
 * It detects existing data files and lets you choose what to do.
 */

// ── Data files that belong to the user (should NOT be wiped on upgrade) ──────
$DATA_FILES = [
    'dash_config.php'       => 'Login credentials & site title',
    'dash_links.json'       => 'Dashboard columns and cards',
    'dash_state.json'       => 'Theme, search engine, and other settings',
    'dash_drives.json'      => 'Monitored drives list',
    'dash_monitor.json'     => 'Widget visibility settings',
    'dash_custom_bg.json'   => 'Custom background images/videos',
    'dash_custom_theme.json'=> 'Custom CSS theme overrides',
    'dash_hidden_themes.json'=> 'Hidden theme list',
    'dash_html_widgets.json'=> 'Custom HTML widgets',
    'dash_layouts.json'     => 'Named layout profiles (JSON fallback)',
    'dash_page_folders.json'=> 'Page folder widgets',
    'dash.sqlite'           => 'SQLite database (layout profiles)',
    'uploads/'              => 'Uploaded images / site logo',
];

// ── PHP files that ARE replaced by a clean extract (code, not data) ───────────
$CODE_FILES = [
    'index.php','options.php','setup.php','upgrade.php','login.php',
    'auth.php','db.php','stats.php','save_links.php','save_layout.php',
    'save_state.php','save_stat_pos.php','scan_drives.php',
    'favicon.svg','README.md',
];

$found   = [];
$missing = [];
foreach ($DATA_FILES as $file => $desc) {
    $path = __DIR__ . '/' . $file;
    if (is_file($path) || is_dir($path)) {
        $found[$file]   = $desc;
    } else {
        $missing[$file] = $desc;
    }
}
$isExisting = !empty($found);

// ── Handle actions ────────────────────────────────────────────────────────────
$msg = '';
$msgType = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $act = $_POST['act'] ?? '';

    if ($act === 'backup') {
        // Back up all found data files to backup_YYYY-MM-DD/
        $backupDir = __DIR__ . '/backup_' . date('Y-m-d_His');
        if (!is_dir($backupDir)) mkdir($backupDir, 0755, true);
        $backed = [];
        foreach (array_keys($found) as $file) {
            $src = __DIR__.'/'.$file;
            $dst = $backupDir.'/'.str_replace('/','_',$file);
            if (is_file($src))      { copy($src, $dst); $backed[] = $file; }
            elseif (is_dir($src)) {
                // Recursively copy directory
                $iter = new RecursiveIteratorIterator(
                    new RecursiveDirectoryIterator($src, RecursiveDirectoryIterator::SKIP_DOTS),
                    RecursiveIteratorIterator::SELF_FIRST
                );
                $dstDir = $backupDir.'/'.basename(rtrim($file,'/'));
                foreach ($iter as $item) {
                    $target = $dstDir.'/'.$iter->getSubPathname();
                    if ($item->isDir())  mkdir($target, 0755, true);
                    else                 copy($item, $target);
                }
                $backed[] = $file;
            }
        }
        $msg     = 'Backup created at <code>'.htmlspecialchars(basename($backupDir)).'</code> ('.count($backed).' items). Your data is safe.';
        $msgType = 'ok';
    }

    if ($act === 'wipe') {
        // Clean install — delete all data files
        if (empty($_POST['confirm_wipe'])) {
            $msg     = 'You must check the confirmation box to wipe data.';
            $msgType = 'err';
        } else {
            $wiped = [];
            foreach (array_keys($found) as $file) {
                $path = __DIR__.'/'.$file;
                if (is_file($path))      { unlink($path); $wiped[] = $file; }
                elseif (is_dir($path))   {
                    // Remove directory recursively
                    $iter = new RecursiveIteratorIterator(
                        new RecursiveDirectoryIterator($path, RecursiveDirectoryIterator::SKIP_DOTS),
                        RecursiveIteratorIterator::CHILD_FIRST
                    );
                    foreach ($iter as $item) {
                        $item->isDir() ? rmdir($item) : unlink($item);
                    }
                    rmdir($path);
                    $wiped[] = $file;
                }
            }
            $msg     = 'Clean install complete. '.count($wiped).' data file(s) removed. <a href="setup.php" style="color:#4a9eff;">Run the setup wizard →</a>';
            $msgType = 'ok';
            // Refresh found/missing
            $found = []; $missing = $DATA_FILES;
        }
    }
}

// ── Quick DB migration check (add new columns safely) ─────────────────────────
$dbStatus = '';
if (class_exists('SQLite3') && file_exists(__DIR__.'/dash.sqlite')) {
    try {
        require_once 'db.php';
        getDashDb(); // runs migrations
        $dbStatus = '✅ SQLite schema is up to date.';
    } catch (Exception $e) {
        $dbStatus = '⚠️ SQLite error: '.htmlspecialchars($e->getMessage());
    }
}
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Upgrade / Install — Dashboard</title>
<link rel="icon" type="image/svg+xml" href="favicon.svg">
<style>
*{box-sizing:border-box;margin:0;padding:0;}
body{font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',sans-serif;background:#0a0a1a;color:#e0e6ff;min-height:100vh;padding:28px 20px;}
h1{font-size:22px;margin-bottom:6px;}
.subtitle{font-size:13px;opacity:.5;margin-bottom:28px;}
.card{background:#111827;border:1px solid rgba(255,255,255,.1);border-radius:12px;padding:20px;margin-bottom:18px;}
.card h2{font-size:15px;font-weight:700;margin-bottom:12px;}
.status-ok{color:#4ade80;} .status-warn{color:#facc15;} .status-err{color:#f87171;}
table{width:100%;border-collapse:collapse;font-size:13px;}
td,th{padding:6px 10px;border-bottom:1px solid rgba(255,255,255,.06);text-align:left;}
th{font-size:11px;text-transform:uppercase;opacity:.4;letter-spacing:.05em;}
td:first-child{font-family:monospace;font-size:12px;color:#93c5fd;}
.badge{display:inline-block;font-size:10px;padding:2px 7px;border-radius:4px;font-weight:600;}
.badge-found{background:rgba(74,222,128,.2);color:#4ade80;border:1px solid rgba(74,222,128,.3);}
.badge-missing{background:rgba(255,255,255,.08);color:rgba(255,255,255,.4);border:1px solid rgba(255,255,255,.12);}
.msg{padding:12px 16px;border-radius:8px;margin-bottom:18px;font-size:13px;line-height:1.5;}
.msg.ok{background:rgba(74,222,128,.15);border:1px solid rgba(74,222,128,.3);color:#4ade80;}
.msg.err{background:rgba(248,113,113,.15);border:1px solid rgba(248,113,113,.3);color:#f87171;}
button,input[type=submit]{padding:9px 18px;border-radius:8px;border:none;cursor:pointer;font-size:13px;font-weight:600;}
.btn-primary{background:#4a9eff;color:#fff;}
.btn-danger{background:rgba(200,50,50,.3);color:#ff9999;border:1px solid rgba(200,50,50,.4);}
.btn-secondary{background:rgba(255,255,255,.1);color:#fff;border:1px solid rgba(255,255,255,.2);}
.form-row{display:flex;gap:10px;align-items:center;flex-wrap:wrap;margin-top:14px;}
label{font-size:13px;display:flex;align-items:center;gap:6px;cursor:pointer;}
code{background:rgba(255,255,255,.08);padding:1px 5px;border-radius:4px;font-size:12px;}
a{color:#4a9eff;}
hr{border:none;border-top:1px solid rgba(255,255,255,.08);margin:18px 0;}
</style>
</head>
<body>
<h1>🛠 Dashboard Upgrade / Install Helper</h1>
<p class="subtitle">Run this after extracting a new dashboard ZIP to check compatibility and manage your data.</p>

<?php if ($msg): ?>
<div class="msg <?= $msgType ?>"><?= $msg ?></div>
<?php endif; ?>

<!-- Status banner -->
<div class="card">
  <h2>📋 Installation Status</h2>
  <?php if ($isExisting): ?>
  <p style="color:#facc15;font-size:13px;margin-bottom:12px;">⚠️ <strong>Existing installation detected</strong> — <?= count($found) ?> data file(s) found. Your data is preserved by default when you extract a new ZIP over this folder.</p>
  <?php else: ?>
  <p style="color:#4ade80;font-size:13px;margin-bottom:12px;">✅ <strong>Fresh installation</strong> — no existing data files found. <a href="setup.php">Run the setup wizard →</a></p>
  <?php endif; ?>
  <?php if ($dbStatus): ?><p style="font-size:12px;opacity:.7;margin-bottom:8px;"><?= $dbStatus ?></p><?php endif; ?>
</div>

<!-- Data file inventory -->
<div class="card">
  <h2>📁 Your Data Files</h2>
  <p style="font-size:12px;opacity:.5;margin-bottom:12px;">These files are created by you and are NOT included in the distribution ZIP — they will survive a new ZIP extraction automatically.</p>
  <table>
    <tr><th>File</th><th>Description</th><th>Status</th></tr>
    <?php foreach ($DATA_FILES as $file => $desc): ?>
    <?php $exists = isset($found[$file]); ?>
    <tr>
      <td><?= htmlspecialchars($file) ?></td>
      <td style="opacity:.6;"><?= htmlspecialchars($desc) ?></td>
      <td><span class="badge <?= $exists ? 'badge-found' : 'badge-missing' ?>"><?= $exists ? '✓ exists' : '— not created' ?></span></td>
    </tr>
    <?php endforeach; ?>
  </table>
</div>

<!-- Actions -->
<div class="card">
  <h2>🔧 Actions</h2>

  <h3 style="font-size:13px;font-weight:600;margin-bottom:8px;color:#93c5fd;">Option A — Upgrade (recommended)</h3>
  <p style="font-size:13px;opacity:.7;margin-bottom:10px;">
    Extract the new ZIP over this folder. PHP code files are replaced; your data files are untouched.<br>
    Optionally back them up first just to be safe:
  </p>
  <form method="POST">
    <input type="hidden" name="act" value="backup">
    <button type="submit" class="btn-primary" <?= empty($found)?'disabled':'' ?>>📦 Back Up My Data Now</button>
  </form>
  <p style="font-size:11px;opacity:.4;margin-top:6px;">Creates a <code>backup_YYYY-MM-DD_HHiiss/</code> folder next to your data files.</p>

  <hr>

  <h3 style="font-size:13px;font-weight:600;margin-bottom:8px;color:#f87171;">Option B — Clean Install (deletes your data)</h3>
  <p style="font-size:13px;opacity:.7;margin-bottom:10px;">
    Wipes all existing data files so you can start completely fresh. <strong>This cannot be undone.</strong><br>
    After wiping, run the setup wizard to configure from scratch.
  </p>
  <form method="POST" onsubmit="return confirm('Are you sure? This permanently deletes your config, links, and settings.')">
    <input type="hidden" name="act" value="wipe">
    <div class="form-row">
      <label><input type="checkbox" name="confirm_wipe" value="1"> I understand this is irreversible</label>
      <button type="submit" class="btn-danger" <?= empty($found)?'disabled':'' ?>>🗑 Wipe All Data &amp; Start Fresh</button>
    </div>
  </form>

  <hr>
  <div class="form-row">
    <a href="index.php" class="btn-secondary" style="text-decoration:none;padding:9px 18px;border-radius:8px;display:inline-block;">← Back to Dashboard</a>
    <a href="setup.php" class="btn-secondary" style="text-decoration:none;padding:9px 18px;border-radius:8px;display:inline-block;">⚙️ Setup Wizard</a>
    <a href="options.php" class="btn-secondary" style="text-decoration:none;padding:9px 18px;border-radius:8px;display:inline-block;">🔧 Options</a>
  </div>
</div>

<p style="font-size:11px;opacity:.3;margin-top:12px;">Delete or rename <code>upgrade.php</code> when you are done with it.</p>
</body>
</html>

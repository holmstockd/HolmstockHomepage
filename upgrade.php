<?php
/**
 * Dashboard Upgrade / Install Helper
 * Run this page AFTER extracting a new ZIP over your existing installation.
 * It detects existing data files, handles MySQL setup, and migrates your data.
 */

// ── Data files that belong to the user (preserved on upgrade) ─────────────────
$DATA_FILES = [
    'dash_config.php'        => 'Login credentials & site title',
    'dash_links.json'        => 'Dashboard columns and cards',
    'dash_state.json'        => 'Theme, search engine, and other settings',
    'dash_drives.json'       => 'Monitored drives list',
    'dash_monitor.json'      => 'Widget visibility settings',
    'dash_custom_bg.json'    => 'Custom background images/videos',
    'dash_custom_theme.json' => 'Custom CSS theme overrides',
    'dash_hidden_themes.json'=> 'Hidden theme list',
    'dash_html_widgets.json' => 'Custom HTML widgets',
    'dash_layouts.json'      => 'Named layout profiles (JSON fallback)',
    'dash_page_folders.json' => 'Page folder widgets',
    'dash.sqlite'            => 'SQLite database (legacy)',
    'uploads/'               => 'Uploaded images / site logo',
];

$found   = [];
$missing = [];
foreach ($DATA_FILES as $file => $desc) {
    $path = __DIR__ . '/' . $file;
    if (is_file($path) || is_dir($path)) $found[$file]   = $desc;
    else                                  $missing[$file] = $desc;
}
$isExisting = !empty($found);

// ── Detect current DB / config state ─────────────────────────────────────────
$cfgFile = __DIR__ . '/dash_config.php';
$cfgExists = file_exists($cfgFile);
$currentDbType   = 'none';
$currentUsername = 'admin';
$cfgSrc          = '';
if ($cfgExists) {
    $cfgSrc = (string)(@file_get_contents($cfgFile) ?: '');
    if ($cfgSrc && preg_match("/define\s*\(\s*'DASH_DB_TYPE'\s*,\s*'([^']+)'/", $cfgSrc, $m)) $currentDbType   = $m[1];
    if ($cfgSrc && preg_match("/define\s*\(\s*'DASH_USERNAME'\s*,\s*'([^']+)'/", $cfgSrc, $m)) $currentUsername = $m[1];
}

// ── Actually TEST the MySQL connection (config says mysql ≠ mysql is working) ──
$mysqlAlreadyConfigured = false;  // true only when config says mysql AND connection succeeds
$mysqlConfigExists      = ($currentDbType === 'mysql');
$mysqlConnError         = '';
if ($mysqlConfigExists) {
    $dbHost = 'localhost'; $dbPort = '3306'; $dbName = ''; $dbUser = ''; $dbPass = '';
    if ($cfgSrc) {
        if (preg_match("/define\s*\(\s*'DASH_DB_HOST'\s*,\s*'([^']*)'/", $cfgSrc, $m)) $dbHost = $m[1];
        if (preg_match("/define\s*\(\s*'DASH_DB_PORT'\s*,\s*'([^']*)'/", $cfgSrc, $m)) $dbPort = $m[1];
        if (preg_match("/define\s*\(\s*'DASH_DB_NAME'\s*,\s*'([^']*)'/", $cfgSrc, $m)) $dbName = $m[1];
        if (preg_match("/define\s*\(\s*'DASH_DB_USER'\s*,\s*'([^']*)'/", $cfgSrc, $m)) $dbUser = $m[1];
        // Password may contain special chars — use a broader match
        if (preg_match("/define\s*\(\s*'DASH_DB_PASS'\s*,\s*'((?:[^'\\\\]|\\\\.)*)'/", $cfgSrc, $m)) $dbPass = stripslashes($m[1]);
    }
    try {
        $dsn      = "mysql:host={$dbHost};port={$dbPort};dbname={$dbName};charset=utf8mb4";
        $testPdo  = new PDO($dsn, $dbUser, $dbPass,
                        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 4]);
        $testPdo->query('SELECT 1'); // lightweight ping — connection works
        // NOW check whether the required tables actually exist in this database
        $tableCheck = $testPdo->query(
            "SELECT COUNT(*) FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = DATABASE()
             AND TABLE_NAME IN ('dash_settings','dash_links','dash_profiles')"
        );
        $tableCount = (int)$tableCheck->fetchColumn();
        if ($tableCount >= 3) {
            $mysqlAlreadyConfigured = true;   // connected AND tables exist
        } else {
            // Connected but tables missing — need migration, NOT "already connected"
            $mysqlConnError = "⚠️ Connected to '{$dbName}' but required tables are missing "
                            . "({$tableCount}/3 found). You need to run the migration.";
        }
    } catch (Throwable $e) {
        $mysqlConnError = $e->getMessage();
    }
    unset($testPdo, $tableCheck);
}
// True when MySQL creds are correct but the DB is empty (tables not yet created)
// strpos() used instead of str_starts_with() for PHP 7.x compatibility
$tablesAreMissing = ($mysqlConfigExists && !$mysqlAlreadyConfigured
                     && strpos($mysqlConnError, '⚠️ Connected') === 0);

// ── AJAX: test MySQL connection ───────────────────────────────────────────────
if (!empty($_GET['ajax']) && $_GET['ajax'] === 'test_mysql') {
    header('Content-Type: application/json');
    $host = trim($_POST['db_host'] ?? 'localhost');
    $port = (int)($_POST['db_port'] ?? 3306);
    $name = trim($_POST['db_name'] ?? '');
    $user = trim($_POST['db_user'] ?? '');
    $pass = $_POST['db_pass'] ?? '';
    if (!$name || !$user) { echo json_encode(['ok'=>false,'error'=>'Database name and username are required.']); exit; }
    try {
        $dsn = "mysql:host={$host};port={$port};dbname={$name};charset=utf8mb4";
        $pdo = new PDO($dsn, $user, $pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 5]);
        $ver = $pdo->query('SELECT VERSION()')->fetchColumn();
        echo json_encode(['ok'=>true,'msg'=>"Connected successfully! MySQL version: {$ver}"]);
    } catch (Throwable $e) {
        echo json_encode(['ok'=>false,'error'=>$e->getMessage()]);
    }
    exit;
}

// ── Handle POST actions ───────────────────────────────────────────────────────
$msg = '';
$msgType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $act = $_POST['act'] ?? '';

    // ── Backup data files ─────────────────────────────────────────────────────
    if ($act === 'backup') {
        $backupDir = __DIR__ . '/backup_' . date('Y-m-d_His');
        if (!is_dir($backupDir)) mkdir($backupDir, 0755, true);
        $backed = [];
        foreach (array_keys($found) as $file) {
            $src = __DIR__.'/'.$file;
            $dst = $backupDir.'/'.str_replace('/','_',$file);
            if (is_file($src)) { copy($src, $dst); $backed[] = $file; }
            elseif (is_dir($src)) {
                $iter = new RecursiveIteratorIterator(
                    new RecursiveDirectoryIterator($src, RecursiveDirectoryIterator::SKIP_DOTS),
                    RecursiveIteratorIterator::SELF_FIRST
                );
                $dstDir = $backupDir.'/'.basename(rtrim($file,'/'));
                foreach ($iter as $item) {
                    $target = $dstDir.'/'.$iter->getSubPathname();
                    if ($item->isDir()) mkdir($target, 0755, true);
                    else copy($item, $target);
                }
                $backed[] = $file;
            }
        }
        $msg     = 'Backup created at <code>'.htmlspecialchars(basename($backupDir)).'</code> ('.count($backed).' items). Your data is safe.';
        $msgType = 'ok';
    }

    // ── Wipe all data ─────────────────────────────────────────────────────────
    if ($act === 'wipe') {
        if (empty($_POST['confirm_wipe'])) {
            $msg     = 'You must check the confirmation box to wipe data.';
            $msgType = 'err';
        } else {
            $wiped = [];
            foreach (array_keys($found) as $file) {
                $path = __DIR__.'/'.$file;
                if (is_file($path)) { unlink($path); $wiped[] = $file; }
                elseif (is_dir($path)) {
                    $iter = new RecursiveIteratorIterator(
                        new RecursiveDirectoryIterator($path, RecursiveDirectoryIterator::SKIP_DOTS),
                        RecursiveIteratorIterator::CHILD_FIRST
                    );
                    foreach ($iter as $item) { $item->isDir() ? rmdir($item) : unlink($item); }
                    rmdir($path);
                    $wiped[] = $file;
                }
            }
            $msg     = 'Clean install complete. '.count($wiped).' data file(s) removed. <a href="setup.php" style="color:#4a9eff;">Run the setup wizard →</a>';
            $msgType = 'ok';
            $found = []; $missing = $DATA_FILES;
        }
    }

    // ── MySQL setup + migration ───────────────────────────────────────────────
    if ($act === 'setup_mysql') {
        $host = trim($_POST['db_host'] ?? 'localhost');
        $port = (int)($_POST['db_port'] ?? 3306);
        $name = trim($_POST['db_name'] ?? '');
        $user = trim($_POST['db_user'] ?? '');
        $pass = $_POST['db_pass'] ?? '';

        if (!$name || !$user) {
            $msg     = 'Database name and username are required.';
            $msgType = 'err';
        } else {
            // Step 1: test connection
            try {
                $dsn = "mysql:host={$host};port={$port};dbname={$name};charset=utf8mb4";
                $pdo = new PDO($dsn, $user, $pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
            } catch (Throwable $e) {
                $msg     = 'MySQL connection failed: '.htmlspecialchars($e->getMessage()).'<br>Check your credentials and try again.';
                $msgType = 'err';
                $pdo     = null;
            }

            if (!empty($pdo)) {
                // Step 2: write MySQL credentials into dash_config.php
                $writeOk = false;
                if ($cfgExists) {
                    $src = (string)(@file_get_contents($cfgFile) ?: '<?php' . "\n");
                    // Remove any existing DB lines
                    $src = (string)preg_replace("/\s*define\s*\(\s*'DASH_DB_TYPE'[^\n]*\n?/", '', $src);
                    $src = (string)preg_replace("/\s*define\s*\(\s*'DASH_DB_HOST'[^\n]*\n?/", '', $src);
                    $src = (string)preg_replace("/\s*define\s*\(\s*'DASH_DB_PORT'[^\n]*\n?/", '', $src);
                    $src = (string)preg_replace("/\s*define\s*\(\s*'DASH_DB_NAME'[^\n]*\n?/", '', $src);
                    $src = (string)preg_replace("/\s*define\s*\(\s*'DASH_DB_USER'[^\n]*\n?/", '', $src);
                    $src = (string)preg_replace("/\s*define\s*\(\s*'DASH_DB_PASS'[^\n]*\n?/", '', $src);
                    // Remove trailing PHP close tag if present
                    $src = (string)preg_replace('/\s*\?>\s*$/', '', $src);
                    $src = rtrim($src);
                    // Append MySQL config block
                    $passEsc = addslashes($pass);
                    $src .= "\n// ── MySQL (added by upgrade.php) ─────────────────────────────────────────────\n";
                    $src .= "define('DASH_DB_TYPE', 'mysql');\n";
                    $src .= "define('DASH_DB_HOST', ".var_export($host,true).");\n";
                    $src .= "define('DASH_DB_PORT', ".var_export((string)$port,true).");\n";
                    $src .= "define('DASH_DB_NAME', ".var_export($name,true).");\n";
                    $src .= "define('DASH_DB_USER', ".var_export($user,true).");\n";
                    $src .= "define('DASH_DB_PASS', ".var_export($pass,true).");\n";
                    $writeOk = (@file_put_contents($cfgFile, $src) !== false);
                } else {
                    // No config file at all — create a minimal one
                    $src  = "<?php\n";
                    $src .= "define('DASH_SETUP_DONE', true);\n";
                    $src .= "define('DASH_USERNAME', 'admin');\n";
                    $src .= "define('DASH_PASSWORD_HASH', '');\n";
                    $src .= "define('DASH_TITLE', 'Server Dashboard');\n";
                    $src .= "define('DASH_GRID_COLS', 3);\n";
                    $src .= "define('DASH_DB_TYPE', 'mysql');\n";
                    $src .= "define('DASH_DB_HOST', ".var_export($host,true).");\n";
                    $src .= "define('DASH_DB_PORT', ".var_export((string)$port,true).");\n";
                    $src .= "define('DASH_DB_NAME', ".var_export($name,true).");\n";
                    $src .= "define('DASH_DB_USER', ".var_export($user,true).");\n";
                    $src .= "define('DASH_DB_PASS', ".var_export($pass,true).");\n";
                    $writeOk = (@file_put_contents($cfgFile, $src) !== false);
                }

                if (!$writeOk) {
                    $msg     = '⚠️ Connected to MySQL but could not write to <code>dash_config.php</code>. Check that the file (or folder) is writable by the web server, then add these lines manually:<br><br>'
                             . '<code>define(\'DASH_DB_TYPE\', \'mysql\');<br>'
                             . 'define(\'DASH_DB_HOST\', \'' . htmlspecialchars($host) . '\');<br>'
                             . 'define(\'DASH_DB_PORT\', \'' . $port . '\');<br>'
                             . 'define(\'DASH_DB_NAME\', \'' . htmlspecialchars($name) . '\');<br>'
                             . 'define(\'DASH_DB_USER\', \'' . htmlspecialchars($user) . '\');<br>'
                             . 'define(\'DASH_DB_PASS\', \'…\');</code>';
                    $msgType = 'err';
                } else {
                    // Step 3: create tables
                    require_once __DIR__ . '/db.php';
                    _dashCreateTables($pdo);

                    // Step 4: run migration for the admin user
                    require_once __DIR__ . '/migrate.php';
                    $targetUser = $currentUsername ?: 'admin';
                    $done = dashRunMigration($pdo, $targetUser);

                    $mysqlAlreadyConfigured = true;
                    $currentDbType = 'mysql';

                    $msg  = '✅ <strong>MySQL connected, tables created, and data migrated!</strong><br><br>';
                    $msg .= 'Credentials saved to <code>dash_config.php</code>.<br>';
                    if ($done) {
                        $msg .= '<br><strong>Migrated from JSON files:</strong><ul style="margin:8px 0 0 20px;font-size:12px;">';
                        foreach ($done as $item) $msg .= '<li>'.htmlspecialchars($item).'</li>';
                        $msg .= '</ul>';
                    } else {
                        $msg .= '<br>No JSON data files found to migrate (dashboard starts fresh from MySQL).';
                    }
                    $msg .= '<br><br><a href="index.php" style="color:#4ade80;font-weight:600;">→ Go to dashboard</a>';
                    $msgType = 'ok';
                }
            }
        }
    }

    // ── MySQL reconnect + create tables (tables-missing or connection-failed branches) ──
    if ($act === 'connect_mysql') {
        $host = trim($_POST['db_host'] ?? 'localhost');
        $port = (int)($_POST['db_port'] ?? 3306);
        $name = trim($_POST['db_name'] ?? '');
        $user = trim($_POST['db_user'] ?? '');
        $pass = $_POST['db_pass'] ?? '';

        // If password left blank, fall back to whatever is already in dash_config.php
        if ($pass === '' && $cfgExists && $cfgSrc) {
            if (preg_match("/define\s*\(\s*'DASH_DB_PASS'\s*,\s*'((?:[^'\\\\]|\\\\.)*)'/", $cfgSrc, $pm)) {
                $pass = stripslashes($pm[1]);
            }
        }

        if (!$name || !$user) {
            $msg     = 'Database name and username are required.';
            $msgType = 'err';
        } else {
            $pdo2 = null;
            try {
                $dsn  = "mysql:host={$host};port={$port};dbname={$name};charset=utf8mb4";
                $pdo2 = new PDO($dsn, $user, $pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
            } catch (Throwable $e) {
                $msg     = 'MySQL connection failed: ' . htmlspecialchars($e->getMessage()) . '<br>Check your credentials and try again.';
                $msgType = 'err';
            }

            if ($pdo2) {
                // Write (or update) credentials in dash_config.php
                $src2 = $cfgExists ? ((string)(@file_get_contents($cfgFile) ?: '<?php' . "\n")) : "<?php\n";
                foreach (['DASH_DB_TYPE','DASH_DB_HOST','DASH_DB_PORT','DASH_DB_NAME','DASH_DB_USER','DASH_DB_PASS'] as $_k) {
                    $src2 = (string)preg_replace("/\s*define\s*\(\s*'" . $_k . "'[^\n]*\n?/", '', $src2);
                }
                $src2 = rtrim((string)preg_replace('/\s*\?>\s*$/', '', $src2));
                $src2 .= "\n// ── MySQL ────────────────────────────────────────────────────────────\n";
                $src2 .= "define('DASH_DB_TYPE', 'mysql');\n";
                $src2 .= "define('DASH_DB_HOST', " . var_export($host,       true) . ");\n";
                $src2 .= "define('DASH_DB_PORT', " . var_export((string)$port, true) . ");\n";
                $src2 .= "define('DASH_DB_NAME', " . var_export($name,       true) . ");\n";
                $src2 .= "define('DASH_DB_USER', " . var_export($user,       true) . ");\n";
                $src2 .= "define('DASH_DB_PASS', " . var_export($pass,       true) . ");\n";

                if (@file_put_contents($cfgFile, $src2) === false) {
                    $msg     = '⚠️ Connected but could not write <code>dash_config.php</code>. Check file permissions.';
                    $msgType = 'err';
                } else {
                    require_once __DIR__ . '/db.php';
                    _dashCreateTables($pdo2);
                    require_once __DIR__ . '/migrate.php';
                    $targetUser = $currentUsername ?: 'admin';
                    // Reset migration_done so it actually runs even if set previously
                    try { $pdo2->prepare("DELETE FROM dash_settings WHERE username=? AND setting_key='migration_done'")->execute([$targetUser]); } catch (Throwable $_e) {}
                    $done2 = dashRunMigration($pdo2, $targetUser);

                    $mysqlAlreadyConfigured = true;
                    $currentDbType = 'mysql';
                    $msg  = '✅ <strong>Tables created and data migrated!</strong><br><br>';
                    $msg .= 'Credentials saved to <code>dash_config.php</code>.<br>';
                    if ($done2) {
                        $msg .= '<br><strong>Migrated from JSON files:</strong><ul style="margin:8px 0 0 20px;font-size:12px;">';
                        foreach ($done2 as $item) $msg .= '<li>' . htmlspecialchars($item) . '</li>';
                        $msg .= '</ul>';
                    } else {
                        $msg .= '<br>No JSON data files found — dashboard starts fresh from MySQL.';
                    }
                    $msg .= '<br><br><a href="index.php" style="color:#4ade80;font-weight:600;">→ Go to dashboard</a>';
                    $msgType = 'ok';
                }
            }
        }
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
.msg{padding:12px 16px;border-radius:8px;margin-bottom:18px;font-size:13px;line-height:1.6;}
.msg.ok{background:rgba(74,222,128,.15);border:1px solid rgba(74,222,128,.3);color:#4ade80;}
.msg.err{background:rgba(248,113,113,.15);border:1px solid rgba(248,113,113,.3);color:#f87171;}
.msg.warn{background:rgba(250,204,21,.1);border:1px solid rgba(250,204,21,.3);color:#facc15;}
button,input[type=submit]{padding:9px 18px;border-radius:8px;border:none;cursor:pointer;font-size:13px;font-weight:600;}
.btn-primary{background:#4a9eff;color:#fff;}
.btn-primary:disabled{opacity:.4;cursor:default;}
.btn-green{background:rgba(30,160,80,.8);color:#fff;}
.btn-danger{background:rgba(200,50,50,.3);color:#ff9999;border:1px solid rgba(200,50,50,.4);}
.btn-secondary{background:rgba(255,255,255,.1);color:#fff;border:1px solid rgba(255,255,255,.2);}
.form-row{display:flex;gap:10px;align-items:center;flex-wrap:wrap;margin-top:14px;}
label{font-size:13px;display:flex;align-items:center;gap:6px;cursor:pointer;}
input[type=text],input[type=password],input[type=number]{
  padding:8px 12px;border-radius:8px;border:1px solid rgba(255,255,255,.15);
  background:#1e293b;color:#e0e6ff;font-size:13px;width:100%;margin-bottom:10px;}
.field-row{display:grid;grid-template-columns:1fr 1fr;gap:12px;}
@media(max-width:520px){.field-row{grid-template-columns:1fr;}}
code{background:rgba(255,255,255,.08);padding:1px 5px;border-radius:4px;font-size:12px;}
a{color:#4a9eff;}
hr{border:none;border-top:1px solid rgba(255,255,255,.08);margin:18px 0;}
.db-badge{display:inline-block;padding:3px 10px;border-radius:20px;font-size:11px;font-weight:700;letter-spacing:.03em;}
.db-mysql{background:rgba(74,222,128,.2);color:#4ade80;border:1px solid rgba(74,222,128,.35);}
.db-json{background:rgba(250,204,21,.15);color:#facc15;border:1px solid rgba(250,204,21,.3);}
#test-result{margin-top:10px;font-size:12px;padding:8px 12px;border-radius:6px;display:none;}
#test-result.ok{background:rgba(74,222,128,.15);border:1px solid rgba(74,222,128,.3);color:#4ade80;display:block;}
#test-result.err{background:rgba(248,113,113,.15);border:1px solid rgba(248,113,113,.3);color:#f87171;display:block;}
fieldset{border:1px solid rgba(255,255,255,.1);border-radius:8px;padding:14px;margin:14px 0;}
legend{font-size:12px;opacity:.5;padding:0 6px;}
</style>
</head>
<body>
<h1>🛠 Dashboard Upgrade Helper</h1>
<p class="subtitle">Run this after extracting a new dashboard ZIP to check compatibility, connect to MySQL, and migrate your data.</p>

<?php if ($msg): ?>
<div class="msg <?= $msgType ?>"><?= $msg ?></div>
<?php endif; ?>

<!-- Status banner -->
<div class="card">
  <h2>📋 Installation Status</h2>
  <p style="font-size:13px;margin-bottom:8px;">
    Current data storage:
    <span class="db-badge <?= $mysqlAlreadyConfigured ? 'db-mysql' : 'db-json' ?>">
      <?= $mysqlAlreadyConfigured ? '✅ MySQL' : '⚠️ JSON files (no MySQL)' ?>
    </span>
  </p>
  <?php if ($isExisting): ?>
  <p style="color:#facc15;font-size:13px;margin-top:8px;">⚠️ <strong>Existing installation detected</strong> — <?= count($found) ?> data file(s) found. Your data is preserved when you extract a new ZIP over this folder.</p>
  <?php else: ?>
  <p style="color:#4ade80;font-size:13px;margin-top:8px;">✅ <strong>Fresh installation</strong> — no existing data files found. <a href="setup.php">Run the setup wizard →</a></p>
  <?php endif; ?>
</div>

<?php if ($tablesAreMissing): ?>
<!-- ===== Connected to DB but tables not yet created — need migration ===== -->
<div class="card" style="border-color:rgba(251,191,36,.4);">
  <h2 style="color:#fbbf24;">⚠️ Connected — But Tables Are Missing</h2>
  <p style="font-size:13px;margin-bottom:10px;">
    The dashboard connected to <strong><?= htmlspecialchars($dbName ?? '') ?></strong> successfully,
    but the required tables do not exist yet. This happens when you create a fresh database
    with the same credentials as an old one.
  </p>
  <p style="font-size:13px;opacity:.8;">
    Click the button below to create the tables and import your existing JSON data files.
  </p>
</div>
<div class="card" style="border-color:rgba(74,222,128,.3);">
  <h2>🚀 Create Tables &amp; Migrate Data</h2>
  <p style="font-size:13px;opacity:.8;margin-bottom:14px;">
    Credentials are already saved correctly — just click to run the migration.
  </p>
  <form method="POST" id="mysql-form">
    <input type="hidden" name="act"     value="connect_mysql">
    <input type="hidden" name="db_host" value="<?= htmlspecialchars($dbHost ?? 'localhost') ?>">
    <input type="hidden" name="db_port" value="<?= htmlspecialchars($dbPort ?? '3306') ?>">
    <input type="hidden" name="db_name" value="<?= htmlspecialchars($dbName ?? '') ?>">
    <input type="hidden" name="db_user" value="<?= htmlspecialchars($dbUser ?? '') ?>">
    <div class="field-row">
      <label>Password <span style="opacity:.5;font-size:11px;">(leave blank — read from dash_config.php; fill only if changed)</span></label>
      <input type="password" name="db_pass" placeholder="(unchanged)">
    </div>
    <div style="margin-top:10px;">
      <button type="submit" class="btn-green" id="submit-btn">🚀 Create Tables &amp; Migrate Data</button>
    </div>
  </form>
</div>
<?php elseif ($mysqlConfigExists && !$mysqlAlreadyConfigured): ?>
<!-- ===== Config says MySQL but connection hard-failed ===== -->
<div class="card" style="border-color:rgba(239,68,68,.4);">
  <h2 style="color:#f87171;">❌ MySQL Configured — But Connection Failed</h2>
  <p style="font-size:13px;opacity:.9;margin-bottom:10px;">
    <code>dash_config.php</code> says MySQL, but the connection attempt failed:
  </p>
  <pre style="background:#0f0f1a;padding:10px 14px;border-radius:8px;font-size:12px;color:#f87171;white-space:pre-wrap;word-break:break-all;margin-bottom:14px;"><?= htmlspecialchars($mysqlConnError) ?></pre>
  <p style="font-size:13px;opacity:.8;">
    Enter the correct credentials below to reconnect, or scroll down to switch to JSON storage.
  </p>
</div>
<div class="card">
  <h2>🔌 Re-enter MySQL Credentials</h2>
  <form method="POST" id="mysql-form">
    <input type="hidden" name="act" value="connect_mysql">
    <div class="field-row"><label>Host</label><input type="text" name="db_host" value="<?= htmlspecialchars($dbHost ?? 'localhost') ?>"></div>
    <div class="field-row"><label>Port</label><input type="number" name="db_port" value="<?= htmlspecialchars($dbPort ?? '3306') ?>"></div>
    <div class="field-row"><label>Database name</label><input type="text" name="db_name" value="<?= htmlspecialchars($dbName ?? '') ?>" required></div>
    <div class="field-row"><label>Username</label><input type="text" name="db_user" value="<?= htmlspecialchars($dbUser ?? '') ?>" required></div>
    <div class="field-row"><label>Password</label><input type="password" name="db_pass" value=""></div>
    <div style="display:flex;gap:8px;margin-top:6px;">
      <button type="button" class="btn-secondary" onclick="testMysql()">🔌 Test Connection</button>
      <button type="submit" class="btn-green" id="submit-btn">🚀 Reconnect &amp; Migrate</button>
    </div>
  </form>
</div>
<?php elseif (!$mysqlAlreadyConfigured): ?>
<!-- ===== STEP 1: First-time MySQL setup ===== -->
<div class="card" style="border-color:rgba(74,222,128,.3);">
  <h2 style="color:#4ade80;">🗄 Step 1 — Connect to MySQL &amp; Migrate Your Data</h2>
  <p style="font-size:13px;opacity:.8;margin-bottom:14px;line-height:1.6;">
    This dashboard now uses <strong>MySQL</strong> as its database. Your current data is still in JSON files.
    Fill in your MySQL credentials below — the wizard will:
  </p>
  <ol style="font-size:13px;opacity:.8;margin:0 0 16px 22px;line-height:2;">
    <li>Test the connection</li>
    <li>Create all required tables</li>
    <li>Copy all your existing JSON data into MySQL automatically</li>
  </ol>
  <p style="font-size:12px;color:#facc15;margin-bottom:16px;">
    ⚠️ You need a MySQL/MariaDB database and user already created on your server.
    If you don't have one, contact your host or create it via phpMyAdmin / the command line first.
  </p>
  <form method="POST" id="mysql-form">
    <input type="hidden" name="act" value="setup_mysql">
    <fieldset>
      <legend>Database connection</legend>
      <div class="field-row">
        <div>
          <label for="db_host" style="display:block;margin-bottom:4px;font-size:12px;opacity:.6;">Host</label>
          <input type="text" name="db_host" id="db_host" value="localhost" placeholder="localhost">
        </div>
        <div>
          <label for="db_port" style="display:block;margin-bottom:4px;font-size:12px;opacity:.6;">Port</label>
          <input type="number" name="db_port" id="db_port" value="3306" placeholder="3306">
        </div>
      </div>
      <div>
        <label for="db_name" style="display:block;margin-bottom:4px;font-size:12px;opacity:.6;">Database name</label>
        <input type="text" name="db_name" id="db_name" placeholder="dashboard" autocomplete="off">
      </div>
      <div class="field-row">
        <div>
          <label for="db_user" style="display:block;margin-bottom:4px;font-size:12px;opacity:.6;">Username</label>
          <input type="text" name="db_user" id="db_user" placeholder="db_user" autocomplete="off">
        </div>
        <div>
          <label for="db_pass" style="display:block;margin-bottom:4px;font-size:12px;opacity:.6;">Password</label>
          <input type="password" name="db_pass" id="db_pass" placeholder="(password)" autocomplete="new-password">
        </div>
      </div>
    </fieldset>
    <div id="test-result"></div>
    <div class="form-row">
      <button type="button" class="btn-secondary" onclick="testMysql()">🔌 Test Connection</button>
      <button type="submit" class="btn-green" id="submit-btn">🚀 Connect, Create Tables &amp; Migrate Data</button>
    </div>
    <p style="font-size:11px;opacity:.4;margin-top:8px;">
      Credentials are saved to <code>dash_config.php</code>. Migration is safe to run multiple times — existing MySQL records are never overwritten.
    </p>
  </form>
</div>
<?php else: ?>
<!-- ===== MySQL connected AND tables exist ===== -->
<div class="card" style="border-color:rgba(74,222,128,.3);">
  <h2 style="color:#4ade80;">✅ MySQL Connected &amp; Tables Ready</h2>
  <p style="font-size:13px;opacity:.8;margin-bottom:10px;">
    Your dashboard is using MySQL and all required tables exist. To re-run the data
    migration (e.g. after restoring JSON backups), visit <a href="migrate.php">migrate.php</a>.
  </p>
</div>
<?php endif; ?>

<!-- Data file inventory -->
<div class="card">
  <h2>📁 Your Data Files</h2>
  <p style="font-size:12px;opacity:.5;margin-bottom:12px;">These files contain your data and are NOT replaced when you extract a new ZIP.</p>
  <table>
    <tr><th>File</th><th>Description</th><th>Status</th></tr>
    <?php foreach ($DATA_FILES as $file => $desc): ?>
    <?php $exists = isset($found[$file]); ?>
    <tr>
      <td><?= htmlspecialchars($file) ?></td>
      <td style="opacity:.6;"><?= htmlspecialchars($desc) ?></td>
      <td><span class="badge <?= $exists ? 'badge-found' : 'badge-missing' ?>"><?= $exists ? '✓ exists' : '— not present' ?></span></td>
    </tr>
    <?php endforeach; ?>
  </table>
</div>

<!-- Backup / Wipe -->
<div class="card">
  <h2>🔧 Other Actions</h2>

  <h3 style="font-size:13px;font-weight:600;margin-bottom:8px;color:#93c5fd;">Back Up Data Files</h3>
  <p style="font-size:13px;opacity:.7;margin-bottom:10px;">
    Creates a timestamped backup folder of all your existing data files before you upgrade.
  </p>
  <form method="POST">
    <input type="hidden" name="act" value="backup">
    <button type="submit" class="btn-primary" <?= empty($found)?'disabled':'' ?>>📦 Back Up My Data Now</button>
  </form>
  <p style="font-size:11px;opacity:.4;margin-top:6px;">Creates a <code>backup_YYYY-MM-DD_HHiiss/</code> folder next to your data files.</p>

  <hr>

  <h3 style="font-size:13px;font-weight:600;margin-bottom:8px;color:#f87171;">Clean Install (deletes all your data)</h3>
  <p style="font-size:13px;opacity:.7;margin-bottom:10px;">
    Wipes all existing data files so you can start completely fresh. <strong>This cannot be undone.</strong>
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
    <a href="index.php"  class="btn-secondary" style="text-decoration:none;padding:9px 18px;border-radius:8px;display:inline-block;">← Dashboard</a>
    <a href="setup.php"  class="btn-secondary" style="text-decoration:none;padding:9px 18px;border-radius:8px;display:inline-block;">⚙️ Setup Wizard</a>
    <a href="options.php" class="btn-secondary" style="text-decoration:none;padding:9px 18px;border-radius:8px;display:inline-block;">🔧 Options</a>
    <?php if ($mysqlAlreadyConfigured): ?>
    <a href="migrate.php" class="btn-secondary" style="text-decoration:none;padding:9px 18px;border-radius:8px;display:inline-block;">🗄 Re-run Migration</a>
    <?php endif; ?>
  </div>
</div>

<p style="font-size:11px;opacity:.3;margin-top:12px;">Delete or rename <code>upgrade.php</code> when you are done with it.</p>

<script>
function testMysql() {
  const form = document.getElementById('mysql-form');
  const res  = document.getElementById('test-result');
  res.className = '';
  res.textContent = '⏳ Testing connection…';
  res.style.display = 'block';
  const fd = new FormData();
  fd.append('db_host', document.getElementById('db_host').value);
  fd.append('db_port', document.getElementById('db_port').value);
  fd.append('db_name', document.getElementById('db_name').value);
  fd.append('db_user', document.getElementById('db_user').value);
  fd.append('db_pass', document.getElementById('db_pass').value);
  fetch('upgrade.php?ajax=test_mysql', {method:'POST', body: fd})
    .then(r => r.json())
    .then(d => {
      res.className = d.ok ? 'ok' : 'err';
      res.textContent = d.ok ? ('✅ ' + d.msg) : ('❌ ' + d.error);
    })
    .catch(e => { res.className = 'err'; res.textContent = '❌ Request failed: ' + e; });
}
</script>
</body>
</html>

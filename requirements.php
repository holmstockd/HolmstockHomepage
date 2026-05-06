<?php
/* ─────────────────────────────────────────────────────────────────────────────
   requirements.php — PHP / server requirements checker for HomepageDash
   Detects web server, PHP version, extensions, writable dirs, and optional
   dependencies.  Shows a colour-coded pass/warn/fail table.
───────────────────────────────────────────────────────────────────────────── */
$checks = [];

/* ── helpers ──────────────────────────────────────────────────────────────── */
function _pass(string $label, string $value = ''): array {
    return ['status'=>'pass','label'=>$label,'value'=>$value];
}
function _warn(string $label, string $value = ''): array {
    return ['status'=>'warn','label'=>$label,'value'=>$value];
}
function _fail(string $label, string $value = ''): array {
    return ['status'=>'fail','label'=>$label,'value'=>$value];
}

/* ── 1. Web server detection ──────────────────────────────────────────────── */
$sw = $_SERVER['SERVER_SOFTWARE'] ?? 'Unknown';
$isApache = stripos($sw, 'apache') !== false;
$isNginx  = stripos($sw, 'nginx')  !== false;
$isLitespeed = stripos($sw, 'litespeed') !== false;

if ($isApache) {
    $checks[] = _pass('Web server', 'Apache — ' . htmlspecialchars($sw));
    // Check mod_rewrite
    $checks[] = function_exists('apache_get_modules') && in_array('mod_rewrite', apache_get_modules())
        ? _pass('mod_rewrite', 'Enabled')
        : _warn('mod_rewrite', 'Cannot detect — verify AllowOverride All in httpd.conf');
    // Check .htaccess
    $htaccess = __DIR__ . '/.htaccess';
    $checks[] = file_exists($htaccess)
        ? _pass('.htaccess', 'Present ('.number_format(filesize($htaccess)).' bytes)')
        : _warn('.htaccess', 'Missing — URL rewriting may not work');
} elseif ($isNginx) {
    $checks[] = _pass('Web server', 'Nginx — ' . htmlspecialchars($sw));
    $checks[] = _warn('Nginx config', '.htaccess is NOT used by Nginx — configure try_files and PHP-FPM manually in your nginx.conf');
} elseif ($isLitespeed) {
    $checks[] = _pass('Web server', 'LiteSpeed — ' . htmlspecialchars($sw));
    $checks[] = _pass('LiteSpeed .htaccess', 'LiteSpeed honours Apache .htaccess files natively');
} else {
    $checks[] = _warn('Web server', htmlspecialchars($sw) ?: 'Unknown — running under CLI or unrecognised server');
}

/* ── 2. PHP version ───────────────────────────────────────────────────────── */
$phpVer   = phpversion();
$phpVerNo = version_compare($phpVer, '8.0', '>=');
$phpVer83 = version_compare($phpVer, '8.3', '>=');
if ($phpVer83) {
    $checks[] = _pass('PHP version', $phpVer . ' ✓ (8.3+ recommended)');
} elseif ($phpVerNo) {
    $checks[] = _warn('PHP version', $phpVer . ' — works, but 8.3+ is recommended for best performance');
} else {
    $checks[] = _fail('PHP version', $phpVer . ' — PHP 8.0+ required (upgrade immediately)');
}

/* ── 3. Required extensions ───────────────────────────────────────────────── */
$required_exts = [
    'pdo'        => 'PDO (database abstraction layer)',
    'pdo_mysql'  => 'PDO MySQL driver',
    'json'       => 'JSON encode / decode',
    'session'    => 'Session management (login)',
    'hash'       => 'Password hashing',
    'curl'       => 'cURL (RSS feeds, weather, external APIs)',
];
foreach ($required_exts as $ext => $desc) {
    $checks[] = extension_loaded($ext)
        ? _pass($desc, $ext . ' ✓')
        : _fail($desc, $ext . ' — MISSING (install php-' . $ext . ')');
}

/* ── 4. Recommended extensions ────────────────────────────────────────────── */
$optional_exts = [
    'mbstring'  => 'mbstring (UTF-8 / multibyte text)',
    'fileinfo'  => 'fileinfo (MIME-type detection for uploads)',
    'zip'       => 'zip (ZIP export feature)',
    'gd'        => 'GD image library (thumbnail generation)',
    'intl'      => 'intl (locale / date formatting)',
    'opcache'   => 'OPcache (PHP bytecode cache — big performance boost)',
];
foreach ($optional_exts as $ext => $desc) {
    $checks[] = extension_loaded($ext)
        ? _pass($desc, $ext . ' ✓')
        : _warn($desc, $ext . ' — optional, install php-' . $ext . ' for best results');
}

/* ── 5. PHP settings ──────────────────────────────────────────────────────── */
$maxUp  = ini_get('upload_max_filesize');
$maxPost= ini_get('post_max_size');
$memLim = ini_get('memory_limit');
$execT  = ini_get('max_execution_time');

function _parseIni(string $v): int {
    $v = trim($v);
    $u = strtolower(substr($v,-1));
    $n = (int)$v;
    if($u==='g') return $n*1024*1024*1024;
    if($u==='m') return $n*1024*1024;
    if($u==='k') return $n*1024;
    return $n;
}

$checks[] = _parseIni($maxUp) >= 32*1024*1024
    ? _pass('upload_max_filesize', $maxUp . ' — good (32 MB+)')
    : _warn('upload_max_filesize', $maxUp . ' — set to at least 32M for large file uploads');

$checks[] = _parseIni($maxPost) >= 32*1024*1024
    ? _pass('post_max_size', $maxPost . ' — good')
    : _warn('post_max_size', $maxPost . ' — should be >= upload_max_filesize');

$checks[] = _parseIni($memLim) >= 128*1024*1024 || $memLim === '-1'
    ? _pass('memory_limit', $memLim . ' — good')
    : _warn('memory_limit', $memLim . ' — set to 128M or higher');

$checks[] = (int)$execT >= 30 || (int)$execT === 0
    ? _pass('max_execution_time', $execT . 's — good')
    : _warn('max_execution_time', $execT . 's — increase to 30+ for large uploads');

/* ── 6. Writable directories ──────────────────────────────────────────────── */
$dirs = [
    __DIR__ . '/uploads'            => 'uploads/ (file storage)',
    __DIR__ . '/uploads/docs'       => 'uploads/docs/ (document folders)',
    __DIR__ . '/uploads/icons'      => 'uploads/icons/ (custom link icons)',
];
foreach ($dirs as $dir => $label) {
    if (!is_dir($dir)) @mkdir($dir, 0755, true);
    $checks[] = is_writable($dir)
        ? _pass($label, 'Writable ✓')
        : _fail($label, 'NOT writable — run: chmod 755 ' . basename($dir) . '/ or chown www-data:www-data');
}

$checks[] = is_writable(__DIR__)
    ? _pass('Root directory', 'Writable ✓ (needed to create dash_config.php on first setup)')
    : _warn('Root directory', 'Not writable — setup.php won\'t be able to write dash_config.php');

/* ── 7. Config / DB ───────────────────────────────────────────────────────── */
$cfgFile = __DIR__ . '/dash_config.php';
if (file_exists($cfgFile)) {
    $checks[] = _pass('dash_config.php', 'Present — dashboard configured');
    require_once $cfgFile;
    if (defined('DASH_DB_TYPE') && DASH_DB_TYPE === 'mysql') {
        // Test MySQL connection
        try {
            $dsn = 'mysql:host=' . DASH_DB_HOST . ';port=' . (DASH_DB_PORT ?: 3306) . ';dbname=' . DASH_DB_NAME . ';charset=utf8mb4';
            $pdo = new PDO($dsn, DASH_DB_USER, DASH_DB_PASS, [PDO::ATTR_TIMEOUT=>5]);
            $checks[] = _pass('MySQL connection', 'Connected to ' . DASH_DB_NAME . '@' . DASH_DB_HOST . ' ✓');
            // Check tables
            $tables = $pdo->query("SHOW TABLES LIKE 'dash_%'")->fetchAll(PDO::FETCH_COLUMN);
            $checks[] = count($tables) >= 6
                ? _pass('MySQL tables', count($tables) . ' dash_ tables found ✓')
                : _warn('MySQL tables', count($tables) . ' tables found — visit upgrade.php to create missing tables');
        } catch (PDOException $e) {
            $checks[] = _fail('MySQL connection', 'FAILED: ' . $e->getMessage());
        }
    } else {
        $checks[] = _warn('Database backend', 'JSON/flat-file mode — MySQL recommended for multi-user use');
    }
} else {
    $checks[] = _warn('dash_config.php', 'Not present — run setup.php to configure');
}

/* ── 8. cURL (for RSS / Weather) ─────────────────────────────────────────── */
if (extension_loaded('curl')) {
    $ch = curl_init('https://httpbin.org/get');
    curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>5,CURLOPT_SSL_VERIFYPEER=>true]);
    curl_exec($ch);
    $err = curl_errno($ch);
    curl_close($ch);
    $checks[] = $err === 0
        ? _pass('External HTTP (cURL)', 'Outbound HTTPS works ✓ — RSS feeds and weather API will work')
        : _warn('External HTTP (cURL)', 'Cannot reach external hosts (cURL error '.$err.') — RSS / weather may fail');
}

/* ── Render ───────────────────────────────────────────────────────────────── */
$pass = count(array_filter($checks, fn($c) => $c['status'] === 'pass'));
$warn = count(array_filter($checks, fn($c) => $c['status'] === 'warn'));
$fail = count(array_filter($checks, fn($c) => $c['status'] === 'fail'));
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>HomepageDash — Requirements Check</title>
<style>
*{box-sizing:border-box;margin:0;padding:0;}
body{background:#0d1117;color:#e6edf3;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',sans-serif;font-size:14px;padding:24px;}
h1{font-size:20px;margin-bottom:6px;color:#f0f6fc;}
.sub{font-size:12px;opacity:.5;margin-bottom:24px;}
.summary{display:flex;gap:16px;margin-bottom:24px;}
.sum-card{flex:1;background:#161b22;border:1px solid #30363d;border-radius:8px;padding:12px 16px;text-align:center;}
.sum-card .num{font-size:28px;font-weight:700;}
.sum-card .lbl{font-size:11px;opacity:.6;margin-top:4px;text-transform:uppercase;letter-spacing:.05em;}
.num-pass{color:#3fb950;} .num-warn{color:#d29922;} .num-fail{color:#f85149;}
table{width:100%;border-collapse:collapse;background:#161b22;border:1px solid #30363d;border-radius:8px;overflow:hidden;}
th{text-align:left;padding:10px 14px;font-size:11px;text-transform:uppercase;letter-spacing:.08em;opacity:.5;border-bottom:1px solid #30363d;background:#0d1117;}
td{padding:10px 14px;border-bottom:1px solid #21262d;vertical-align:top;}
tr:last-child td{border-bottom:none;}
.badge{display:inline-block;padding:2px 8px;border-radius:12px;font-size:11px;font-weight:600;width:48px;text-align:center;}
.badge-pass{background:rgba(63,185,80,.15);color:#3fb950;border:1px solid rgba(63,185,80,.3);}
.badge-warn{background:rgba(210,153,34,.15);color:#d29922;border:1px solid rgba(210,153,34,.3);}
.badge-fail{background:rgba(248,81,73,.15);color:#f85149;border:1px solid rgba(248,81,73,.3);}
.val{font-size:12px;opacity:.7;margin-top:3px;}
.actions{display:flex;gap:10px;margin-top:20px;}
.btn{display:inline-block;padding:8px 18px;border-radius:6px;font-size:13px;text-decoration:none;border:1px solid;cursor:pointer;}
.btn-primary{background:#1f6feb;border-color:#388bfd;color:#fff;}
.btn-secondary{background:#21262d;border-color:#30363d;color:#e6edf3;}
</style>
</head>
<body>
<h1>🔍 HomepageDash — Requirements Check</h1>
<div class="sub">PHP <?= PHP_VERSION ?> · <?= htmlspecialchars($sw) ?> · <?= date('Y-m-d H:i:s') ?></div>

<div class="summary">
  <div class="sum-card"><div class="num num-pass"><?= $pass ?></div><div class="lbl">Pass</div></div>
  <div class="sum-card"><div class="num num-warn"><?= $warn ?></div><div class="lbl">Warning</div></div>
  <div class="sum-card"><div class="num num-fail"><?= $fail ?></div><div class="lbl">Fail</div></div>
</div>

<table>
<thead><tr><th style="width:60px">Status</th><th>Check</th><th>Detail</th></tr></thead>
<tbody>
<?php foreach ($checks as $c): ?>
<tr>
  <td><span class="badge badge-<?= $c['status'] ?>"><?= strtoupper($c['status']) ?></span></td>
  <td><strong><?= htmlspecialchars($c['label']) ?></strong></td>
  <td class="val"><?= htmlspecialchars($c['value']) ?></td>
</tr>
<?php endforeach; ?>
</tbody>
</table>

<div class="actions">
  <?php if (file_exists(__DIR__.'/upgrade.php')): ?>
  <a href="upgrade.php" class="btn btn-primary">⚙️ Setup / Upgrade DB</a>
  <?php endif; ?>
  <?php if (file_exists(__DIR__.'/index.php') && file_exists(__DIR__.'/dash_config.php')): ?>
  <a href="index.php" class="btn btn-secondary">🏠 Go to Dashboard</a>
  <?php endif; ?>
  <a href="requirements.php" class="btn btn-secondary">🔄 Recheck</a>
</div>

<?php if ($isNginx): ?>
<div style="margin-top:24px;background:#161b22;border:1px solid #f85149;border-radius:8px;padding:16px;">
  <h2 style="font-size:14px;color:#f85149;margin-bottom:8px;">⚠️ Nginx Configuration Required</h2>
  <p style="opacity:.8;margin-bottom:10px;">Your server runs Nginx. <code>.htaccess</code> files are ignored. Add the following to your <code>server {}</code> block:</p>
  <pre style="background:#0d1117;padding:12px;border-radius:6px;font-size:12px;overflow-x:auto;color:#79c0ff;">location / {
    try_files $uri $uri/ /index.php?$query_string;
}

location ~ \.php$ {
    include snippets/fastcgi-php.conf;
    fastcgi_pass unix:/run/php/php<?= PHP_MAJOR_VERSION.'.'.PHP_MINOR_VERSION ?>-fpm.sock;
    fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
    include fastcgi_params;
}</pre>
</div>
<?php endif; ?>
</body>
</html>

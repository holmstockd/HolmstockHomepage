<?php
if (session_status() === PHP_SESSION_NONE) session_start();

define('COOKIE_NAME', 'dash_auth');
define('COOKIE_DAYS', 60);

function getDashConfig() {
    $cfg = ['username' => 'admin', 'password_hash' => '', 'title' => 'Server Dashboard', 'grid_cols' => 3];
    $f = __DIR__ . '/dash_config.php';
    if (file_exists($f)) {
        $out = @shell_exec('php -r "include \''.addslashes($f).'\'; echo json_encode([\'u\'=>defined(\'DASH_USERNAME\')?DASH_USERNAME:\'admin\',\'h\'=>defined(\'DASH_PASSWORD_HASH\')?DASH_PASSWORD_HASH:\'\',\'t\'=>defined(\'DASH_TITLE\')?DASH_TITLE:\'Server Dashboard\',\'c\'=>defined(\'DASH_GRID_COLS\')?DASH_GRID_COLS:3]);" 2>/dev/null');
        if ($out) {
            $d = json_decode($out, true);
            if ($d) {
                $cfg['username']      = $d['u'] ?? 'admin';
                $cfg['password_hash'] = $d['h'] ?? '';
                $cfg['title']         = $d['t'] ?? 'Server Dashboard';
                $cfg['grid_cols']     = (int)($d['c'] ?? 3);
                return $cfg;
            }
        }
        if (!defined('DASH_USERNAME')) {
            @include_once $f;
        }
        if (defined('DASH_USERNAME'))      $cfg['username']      = DASH_USERNAME;
        if (defined('DASH_PASSWORD_HASH')) $cfg['password_hash'] = DASH_PASSWORD_HASH;
        if (defined('DASH_TITLE'))         $cfg['title']         = DASH_TITLE;
        if (defined('DASH_GRID_COLS'))     $cfg['grid_cols']     = (int)DASH_GRID_COLS;
    }
    return $cfg;
}

function isConfigured() {
    $f = __DIR__ . '/dash_config.php';
    if (!file_exists($f)) return false;
    $c = @file_get_contents($f) ?: '';
    return strpos($c, 'DASH_SETUP_DONE') !== false
        && strpos($c, "DASH_SETUP_DONE', false") === false;
}

// ─── MySQL-configured flag (safe to call before getDashDb) ─────────────────
function _authMysqlConfigured(): bool {
    static $checked = null;
    if ($checked !== null) return $checked;
    $f = __DIR__ . '/dash_config.php';
    if (!file_exists($f)) { $checked = false; return false; }
    $c = @file_get_contents($f) ?: '';
    $checked = (strpos($c, "DASH_DB_TYPE', 'mysql'") !== false);
    return $checked;
}

// ─── Sub-user helpers (MySQL-strict when configured, JSON only when no MySQL) ─

function getSubUsers(): array {
    $db = _authGetDb();
    if ($db) return dashGetUsers($db);
    // If MySQL is configured but connection failed → return empty (no JSON fallback for security)
    if (_authMysqlConfigured()) return [];
    $f = __DIR__ . '/dash_users.json';
    return json_decode(@file_get_contents($f) ?: '[]', true) ?: [];
}

function getSubUserByCredentials(string $username, string $password): ?array {
    $db = _authGetDb();
    if ($db) return dashVerifyUser($db, $username, $password);
    if (_authMysqlConfigured()) return null;
    foreach (getSubUsers() as $u) {
        if ($u['username'] === $username && password_verify($password, $u['password_hash']))
            return $u;
    }
    return null;
}

// Restore sub-user from remember-me cookie
function restoreSubUserFromCookie(string $token): bool {
    foreach (getSubUsers() as $u) {
        $expected = hash('sha256', $u['username'] . ($_SERVER['HTTP_USER_AGENT'] ?? '') . 'dash_secret_salt_2024');
        if (hash_equals($expected, $token)) {
            $_SESSION['logged_in'] = true;
            $_SESSION['sub_user']  = $u['username'];
            $_SESSION['sub_role']  = ($u['role'] ?: 'user');
            return true;
        }
    }
    return false;
}

// Get current session username (admin or sub-user)
function getCurrentUsername(): string {
    if (!empty($_SESSION['sub_user'])) return $_SESSION['sub_user'];
    $cfg = getDashConfig();
    return $cfg['username'];
}

// Get current session role ('admin', 'user', 'readonly')
function getCurrentRole(): string {
    // sub_user is ONLY set for sub-user sessions, never for the main admin.
    // Check it first so an empty/null sub_role still resolves to 'user', not 'admin'.
    if (!empty($_SESSION['sub_user'])) {
        $r = $_SESSION['sub_role'] ?? '';
        return ($r === 'readonly') ? 'readonly' : 'user';
    }
    if (!empty($_SESSION['logged_in'])) return 'admin';
    return 'admin';
}

function isAdmin(): bool {
    return getCurrentRole() === 'admin';
}

function isLoggedIn(): bool {
    if (!empty($_SESSION['logged_in'])) return true;
    if (isset($_COOKIE[COOKIE_NAME])) {
        $token = $_COOKIE[COOKIE_NAME];
        $cfg   = getDashConfig();
        $expected = hash('sha256', $cfg['username'] . ($_SERVER['HTTP_USER_AGENT'] ?? '') . 'dash_secret_salt_2024');
        if (hash_equals($expected, $token)) {
            $_SESSION['logged_in'] = true;
            // Trigger auto-migration silently on first login after upgrade
            _authTriggerMigration($cfg['username']);
            return true;
        }
        if (restoreSubUserFromCookie($token)) {
            _authTriggerMigration($_SESSION['sub_user'] ?? $cfg['username']);
            return true;
        }
    }
    return false;
}

// ─── Migration trigger ─────────────────────────────────────────────────────

function _authTriggerMigration(string $username): void {
    $db = _authGetDb();
    if (!$db) return;
    // Check if migration already done for this user
    $s = $db->prepare('SELECT setting_val FROM dash_settings WHERE username=? AND setting_key=?');
    $s->execute([$username, 'migration_done']);
    if ($s->fetch()) return; // already migrated
    // Run migration silently (best-effort)
    @include_once __DIR__ . '/migrate.php';
    if (function_exists('dashRunMigration')) {
        dashRunMigration($db, $username);
    }
}

// Lazy-load db (avoids circular includes)
function _authGetDb(): ?PDO {
    static $loaded = false;
    if (!$loaded) {
        $loaded = true;
        if (!function_exists('getDashDb')) @include_once __DIR__ . '/db.php';
    }
    return function_exists('getDashDb') ? getDashDb() : null;
}

// Build an absolute URL for same-directory redirects (works in root and subdirectory installs)
function _authRedirect(string $file): void {
    $proto = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host  = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $dir   = rtrim(str_replace('\\', '/', dirname($_SERVER['PHP_SELF'] ?? '/')), '/');
    header('Location: ' . $proto . '://' . $host . $dir . '/' . $file);
    exit;
}

// Redirect to setup if not configured
if (!isConfigured()) {
    _authRedirect('setup.php');
}

// Handle logout
if (isset($_GET['logout'])) {
    setcookie(COOKIE_NAME, '', time() - 3600, '/');
    session_destroy();
    _authRedirect('login.php');
}

// Require login
if (!isLoggedIn()) {
    _authRedirect('login.php');
}

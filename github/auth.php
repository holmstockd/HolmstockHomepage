<?php
if (session_status() === PHP_SESSION_NONE) session_start();

define('COOKIE_NAME', 'dash_auth');
define('COOKIE_DAYS', 60);

function getDashConfig() {
    $cfg = ['username' => 'admin', 'password_hash' => '', 'title' => 'Server Dashboard', 'grid_cols' => 3];
    $f = __DIR__ . '/dash_config.php';
    if (file_exists($f)) {
        // Use a subprocess to avoid re-defining constants
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
        // Fallback: parse directly (works fine if constants not already defined)
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
    // Setup is complete when the setup wizard has written DASH_SETUP_DONE=true
    return strpos($c, 'DASH_SETUP_DONE') !== false
        && strpos($c, "DASH_SETUP_DONE', false") === false;
}

// ─── Sub-user helpers ─────────────────────────────────────────────────────────
function getSubUsers() {
    $f = __DIR__ . '/dash_users.json';
    return json_decode(@file_get_contents($f) ?: '[]', true) ?: [];
}

function getSubUserByCredentials($username, $password) {
    foreach (getSubUsers() as $u) {
        if ($u['username'] === $username && password_verify($password, $u['password_hash'])) {
            return $u;
        }
    }
    return null;
}

// Restore sub-user from remember-me cookie
function restoreSubUserFromCookie($token) {
    foreach (getSubUsers() as $u) {
        $expected = hash('sha256', $u['username'] . ($_SERVER['HTTP_USER_AGENT'] ?? '') . 'dash_secret_salt_2024');
        if (hash_equals($expected, $token)) {
            $_SESSION['logged_in']    = true;
            $_SESSION['sub_user']     = $u['username'];
            $_SESSION['sub_role']     = $u['role'] ?? 'user';
            return true;
        }
    }
    return false;
}

// Get current session username (admin or sub-user)
function getCurrentUsername() {
    if (!empty($_SESSION['sub_user'])) return $_SESSION['sub_user'];
    $cfg = getDashConfig();
    return $cfg['username'];
}

// Get current session role ('admin', 'user', 'readonly')
function getCurrentRole() {
    if (!empty($_SESSION['sub_role'])) return $_SESSION['sub_role'];
    if (!empty($_SESSION['logged_in'])) return 'admin';
    return 'admin';
}

function isAdmin() {
    return getCurrentRole() === 'admin';
}

function isLoggedIn() {
    if (!empty($_SESSION['logged_in'])) return true;
    if (isset($_COOKIE[COOKIE_NAME])) {
        $token = $_COOKIE[COOKIE_NAME];
        // Check admin token
        $cfg = getDashConfig();
        $expected = hash('sha256', $cfg['username'] . ($_SERVER['HTTP_USER_AGENT'] ?? '') . 'dash_secret_salt_2024');
        if (hash_equals($expected, $token)) {
            $_SESSION['logged_in'] = true;
            return true;
        }
        // Check sub-user tokens
        if (restoreSubUserFromCookie($token)) return true;
    }
    return false;
}

// Redirect to setup if not configured
if (!isConfigured()) {
    header('Location: setup.php'); exit;
}

// Handle logout
if (isset($_GET['logout'])) {
    setcookie(COOKIE_NAME, '', time() - 3600, '/');
    session_destroy();
    header('Location: login.php');
    exit;
}

// Require login
if (!isLoggedIn()) {
    header('Location: login.php');
    exit;
}

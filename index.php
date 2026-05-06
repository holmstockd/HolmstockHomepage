<?php require_once 'auth.php';
require_once 'db.php';
require_once 'presets.php';

// v1.4.1 — defeat browser/proxy caching of dashboard HTML.
// Without this, a sub-user can sometimes see the admin's previously-cached
// page after switching accounts (especially in shared / CDN environments).
header('Cache-Control: no-cache, no-store, must-revalidate, private');
header('Pragma: no-cache');
header('Expires: 0');

// ── Emergency: switch to JSON/flat-file mode without running setup again ──
if (isset($_GET['action']) && $_GET['action'] === 'switch_to_json') {
    $cfgFile = __DIR__ . '/dash_config.php';
    if (file_exists($cfgFile)) {
        $src = file_get_contents($cfgFile);
        // Remove all DASH_DB_* lines
        $src = preg_replace("/^define\('DASH_DB_[^']+',.*\);\n?/m", '', $src);
        // Inject DASH_DB_TYPE=none right after DASH_SETUP_DONE line
        $src = preg_replace(
            "/(define\('DASH_SETUP_DONE',true\);)/",
            "$1\ndefine('DASH_DB_TYPE','none');",
            $src
        );
        file_put_contents($cfgFile, $src);
    }
    header('Location: index.php');
    exit;
}

$cfg       = getDashConfig();
$title     = $cfg['title'];
$grid_cols = max(1, min(100, (int)$cfg['grid_cols']));
$_db            = getDashDb();
// Detect MySQL-configured-but-failed: affects banner and sub-user access
$_db_connect_failed = ($_db === null && defined('DASH_DB_TYPE') && DASH_DB_TYPE === 'mysql');
$_dash_role     = getCurrentRole();   // 'admin' | 'user' | 'readonly'
$_dash_uname    = getCurrentUsername();
$_dash_is_admin = isAdmin();

// If MySQL is configured but the connection failed, block sub-users with a
// clear error (in JSON-only mode they can continue — db.php now writes a
// separate dash_*_<user>.json file per user). Admin always continues; even
// without DB they have full access via the JSON fallback.
if ($_db_connect_failed && !$_dash_is_admin) {
    http_response_code(503);
    ?><!DOCTYPE html>
<html lang="en">
<head><meta charset="utf-8"><title>Database Unavailable</title>
<style>*{box-sizing:border-box;margin:0;padding:0;}body{font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',sans-serif;background:#07090f;color:#fff;min-height:100vh;display:flex;align-items:center;justify-content:center;}
.box{background:#0f1623;border:1px solid rgba(255,80,30,.3);border-radius:16px;padding:40px 36px;max-width:460px;text-align:center;}
h1{font-size:18px;margin:16px 0 10px;}p{font-size:13px;color:rgba(255,255,255,.5);line-height:1.7;margin-bottom:20px;}
a{color:#4a9eff;text-decoration:none;}a:hover{text-decoration:underline;}</style></head>
<body><div class="box">
<div style="font-size:40px;">⚠️</div>
<h1>Database Unavailable</h1>
<p>The MySQL database is configured but could not be reached.<br>
Please contact your administrator to restore the database connection.</p>
<p><a href="?logout=1">← Sign out</a></p>
</div></body></html><?php
    exit;
}

// ===== MACHINE UUID (per-device last-used recall, server-side cookie) =====
$_muuid = preg_replace('/[^0-9a-f\-]/', '', $_COOKIE['dash_machine_uuid'] ?? '');
if (strlen($_muuid) !== 36) {
    $_muuid = sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
        mt_rand(0,0xffff), mt_rand(0,0xffff), mt_rand(0,0xffff),
        mt_rand(0,0x0fff)|0x4000, mt_rand(0,0x3fff)|0x8000,
        mt_rand(0,0xffff), mt_rand(0,0xffff), mt_rand(0,0xffff)
    );
    setcookie('dash_machine_uuid', $_muuid, [
        'expires'  => time() + 86400 * 3650,
        'path'     => '/',
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
}

// ── Device → Profile auto-link (file-based, no MySQL needed) ──────────────
$_dp_file = __DIR__ . '/dash_device_profiles_' . preg_replace('/[^a-z0-9_\-]/i', '', $_dash_uname) . '.json';
$_dp_map  = json_decode(@file_get_contents($_dp_file) ?: '{}', true) ?: [];
$_php_device_profile = (strlen($_muuid) === 36 && !empty($_dp_map[$_muuid])) ? (string)$_dp_map[$_muuid] : '';

// Load all per-user data (MySQL-first, JSON fallback via db.php helpers)
$_all_settings = dashGetSettings($_db, $_dash_uname);
// Machine-specific last-used recall: override global settings for this device.
// MySQL mode: dashGetMachine reads last_theme/last_variant/last_size from DB.
// Flat-file mode: read from per-device dash_machine_state_<uuid>.json (written
// by save_state.php and save_layout.php) so loading a profile on PC never
// overwrites the theme/size/variant that Mac sees on its next page load.
$_machine = dashGetMachine($_db, $_dash_uname, $_muuid);
if (!empty($_machine['last_theme']))   $_all_settings['hp-theme']   = $_machine['last_theme'];
if (!empty($_machine['last_variant'])) $_all_settings['hp-variant'] = $_machine['last_variant'];
if (!empty($_machine['last_size']) && (int)$_machine['last_size'] > 0)
    $_all_settings['hp-size'] = (string)(int)$_machine['last_size'];
// Flat-file mode supplement: per-device state file (no MySQL required)
$_mstate = [];
if (!$_db && strlen($_muuid) === 36) {
    $_mstate_file = __DIR__ . '/dash_machine_state_' . $_muuid . '.json';
    $_mstate = json_decode(@file_get_contents($_mstate_file) ?: '{}', true) ?: [];
    if (!empty($_mstate['last_theme']))   $_all_settings['hp-theme']   = $_mstate['last_theme'];
    if (!empty($_mstate['last_variant'])) $_all_settings['hp-variant'] = $_mstate['last_variant'];
    if (!empty($_mstate['last_size']) && (int)$_mstate['last_size'] > 0)
        $_all_settings['hp-size'] = (string)(int)$_mstate['last_size'];
}
$_dash_state   = $_all_settings; // alias for compatibility

$_dr_raw  = $_all_settings['drives']  ?? null;
$drives   = $_dr_raw !== null ? (json_decode($_dr_raw, true) ?: [])
                               : _dashJsonRead(__DIR__.'/dash_drives.json', []);
$_mon_raw = $_all_settings['monitor'] ?? null;
$monitor  = $_mon_raw !== null ? (json_decode($_mon_raw, true) ?: ['cpu'=>true,'ram'=>true,'storage'=>true])
                               : (json_decode(@file_get_contents(__DIR__.'/dash_monitor.json') ?: '{}', true)
                                  ?: ['cpu'=>true,'ram'=>true,'storage'=>true]);
$html_widgets    = dashGetWidgets($_db, $_dash_uname, 'html');
$weather_city_widgets = dashGetWidgets($_db, $_dash_uname, 'weather_city');
$timezone_widgets     = dashGetWidgets($_db, $_dash_uname, 'timezone');
$links           = dashGetLinks($_db, $_dash_uname);
$bgs           = dashGetCustomBgs($_db, $_dash_uname);
// Detect first-time sub-user login (no record yet) → show setup wizard.
// Works in both MySQL mode (no dash_links row) and JSON mode (no per-user
// dash_links_<user>.json file). Use the row-existence check, NOT empty($links):
// an intentionally-empty set of columns still creates a record → wizard
// would otherwise stay dismissed forever.
$_is_first_run = (!$_dash_is_admin && $_dash_role !== 'readonly' && !dashLinksRowExists($_db, $_dash_uname));

// ── Shared resources (accepted by this user from others) ─────────────────
$_sh_rss  = dashGetAcceptedSharesByType($_db, $_dash_uname, 'rss');
$_sh_cam  = dashGetAcceptedSharesByType($_db, $_dash_uname, 'camera');
$_sh_cal  = dashGetAcceptedSharesByType($_db, $_dash_uname, 'calendar');
$_sh_cd   = dashGetAcceptedSharesByType($_db, $_dash_uname, 'countdown');
$_sh_lnk  = dashGetAcceptedSharesByType($_db, $_dash_uname, 'links_col');
$_sh_sn   = dashGetAcceptedSharesByType($_db, $_dash_uname, 'sticky');
$_shared_rss = $_shared_cam = $_shared_cal = $_shared_cd = $_shared_link_cols = $_shared_stickies = [];
foreach ($_sh_rss as $_r) {
    foreach (dashGetWidgets($_db, $_r['from_user'], 'rss') as $w) {
        if (($w['id']??'') === $_r['resource_id']) { $w['_shared_from']=$_r['from_user']; $_shared_rss[] = $w; }
    }
}
foreach ($_sh_cam as $_r) {
    foreach (dashGetWidgets($_db, $_r['from_user'], 'camera') as $w) {
        if (($w['id']??'') === $_r['resource_id']) { $w['_shared_from']=$_r['from_user']; $_shared_cam[] = $w; }
    }
}
foreach ($_sh_cal as $_r) {
    foreach (dashGetWidgets($_db, $_r['from_user'], 'calendar') as $w) {
        if (($w['id']??'') === $_r['resource_id']) { $w['_shared_from']=$_r['from_user']; $_shared_cal[] = $w; }
    }
}
foreach ($_sh_cd as $_r) {
    foreach (dashGetWidgets($_db, $_r['from_user'], 'countdown') as $w) {
        if (($w['id']??'') === $_r['resource_id']) { $w['_shared_from']=$_r['from_user']; $_shared_cd[] = $w; }
    }
}
foreach ($_sh_lnk as $_r) {
    foreach (dashGetLinks($_db, $_r['from_user']) as $col) {
        if (($col['id']??'') === $_r['resource_id']) { $col['_shared_from']=$_r['from_user']; $_shared_link_cols[] = $col; }
    }
}
foreach ($_sh_sn as $_r) {
    $ownerNotes = json_decode(dashGetSetting($_db, $_r['from_user'], 'sticky_notes', '[]') ?: '[]', true) ?: [];
    foreach ($ownerNotes as $sn) {
        if (($sn['id']??'') === $_r['resource_id']) { $sn['_shared_from']=$_r['from_user']; $_shared_stickies[] = $sn; }
    }
}
$ctJson        = dashGetSetting($_db, $_dash_uname, 'custom_theme', '{}') ?: '{}';
$_ht_raw       = dashGetSetting($_db, $_dash_uname, 'hidden_themes', '[]');
$hidden_themes = json_decode($_ht_raw, true) ?: [];

// Per-theme layout files — each theme gets its own saved positions.
$_allowed_themes_php = ['win98','win9x','win2k','winxp','winxp2','winphone','aqua','ios26',
                        'jellybean','palmos','palmtreo','palmv','palmpilot','pocketpc','macos','macos9','mac9',
                        'macosx','osxtiger','ubuntu','c64','os2','webos','professional','cute',
                        'amiga','nextstep','beos','norton','atarist','irix','miku',
                        'spring','summer','autumn','winter','thanksgiving','july4','christmas','custom'];
// Site logo (uploaded via Options → General)
$_dash_logo = '';
foreach (['jpg','jpeg','png','gif','webp','svg'] as $_lext) {
    if (file_exists(__DIR__.'/uploads/site_logo.'.$_lext)) {
        $_dash_logo = 'uploads/site_logo.'.$_lext; break;
    }
}
$_dash_search_engine = $_dash_state['search_engine'] ?? 'google';
$all_themes = [
    // ── Windows ──────────────────────────────────────────────────────────
    'win9x'    =>'🖥 WIN 9X Retro',
    'win2k'    =>'🖥 Win 2000',
    'winxp'    =>'🪟 Win XP',
    'winphone' =>'📱 Win Phone',
    // ── Mac ──────────────────────────────────────────────────────────────
    'mac9'     =>'🌈 Mac OS 9 Retro',
    'macosx'   =>'🍎 Mac OSX Retro',
    'osxtiger' =>'🐯 Mac OSX Tiger',
    'aqua'     =>'💧 OSX Aqua',
    'macos9'   =>'🌈 Mac OS 9',
    'macos'    =>'🍎 macOS',
    // ── Android / Linux ──────────────────────────────────────────────────
    'jellybean' =>'🤖 Android 4',
    'ubuntu'    =>'🟠 Ubuntu',
    // ── iOS ──────────────────────────────────────────────────────────────
    'ios26'    =>'✨ iOS 26',
    // ── Palm ─────────────────────────────────────────────────────────────
    'palmos'   =>'📟 Palm OS',
    'palmv'    =>'🔳 Palm V / Vx',
    'palmpilot'=>'📟 Palm Pilot',
    'pocketpc' =>'📲 Pocket PC 6',
    'webos'    =>'🌙 Palm webOS',
    // ── Other Retro ──────────────────────────────────────────────────────
    'amiga'    =>'🖥 Amiga Workbench',
    'nextstep' =>'⬛ NeXTSTEP',
    'beos'     =>'🟡 BeOS',
    'norton'   =>'💙 DOS / Norton Commander',
    'atarist'  =>'🕹 Atari ST / TOS',
    'irix'     =>'🌊 IRIX / SGI',
    'c64'      =>'🕹 Commodore 64',
    'os2'      =>'🗄 OS/2 Warp',
    'miku'     =>'🎵 Hatsune Miku',
    // ── Seasonal / Other ─────────────────────────────────────────────────
    'professional'=>'👔 Professional',
    'cute'    =>'🌸 Cute',
    'spring'   =>'🌷 Spring',
    'summer'   =>'🏖 Summer',
    'autumn'   =>'🍂 Autumn',
    'winter'   =>'❄️ Winter',
    'thanksgiving'=>'🦃 Thanksgiving',
    'july4'    =>'🎆 July 4th',
    'christmas'=>'✝️ Christmas',
];
// Only show 'custom' if the user has created one
$_has_custom = !empty(json_decode($ctJson, true));
if ($_has_custom) $all_themes['custom'] = '🎨 Custom';
$visible_themes = array_filter($all_themes, fn($k) => !in_array($k, $hidden_themes), ARRAY_FILTER_USE_KEY);
// Load page folders (file folder widgets placed on the dashboard)
$page_folders = dashGetPageFolders($_db, $_dash_uname);
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= htmlspecialchars($title) ?></title>
<link rel="icon" type="image/svg+xml" href="favicon.svg">
<link rel="shortcut icon" href="favicon.svg">
<style>
@import url('https://fonts.googleapis.com/css2?family=Share+Tech+Mono&display=swap');
/* ===== RESET ===== */
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0;}
a{text-decoration:none;color:inherit;}

/* ===== CSS VARS (Win98 default) ===== */
:root{
  --card-bg:#c0c0c0;--card-border-light:#fff;--card-border-dark:#808080;
  --card-text:#000;--card-hover-bg:#000080;--card-hover-text:#fff;
  --section-title-bg:linear-gradient(to right,#000080,#1084d0);--section-title-text:#fff;
  --search-bg:#fff;--search-border:#808080;--search-text:#000;
  --font:'Arial',sans-serif;--card-radius:0px;--card-shadow:none;--card-transition:none;
  --widget-text:#000;--header-bg:#c0c0c0;--header-border:2px solid #fff;
}

/* ===== WALLPAPER KEYFRAMES ===== */
@keyframes tealPulse{0%,100%{background-size:4px 4px}50%{background-size:5px 5px}}
@keyframes circlesPulse{0%{background-size:20px 20px}50%{background-size:22px 22px}100%{background-size:20px 20px}}
@keyframes sandDrift{0%{background-position:0 0}100%{background-position:20px 20px}}
@keyframes forestBreeze{0%,100%{background-size:6px 6px}50%{background-size:7px 7px}}
@keyframes purpleFlow{0%{background-position:0 0}100%{background-position:12px 0}}
@keyframes navyPulse{0%,100%{background-size:4px 4px}50%{background-size:5px 5px}}
@keyframes brickShift{0%{background-position:0 0,0 0}100%{background-position:2px 0,2px 0}}
@keyframes cloudDrift{0%{background-position:0% 30%,100% 60%,50% 20%}100%{background-position:15% 30%,85% 60%,60% 20%}}
@keyframes metalSheen{0%{background-position:0 0}100%{background-position:6px 0}}
@keyframes auroraFlow{0%{background-position:0% 50%}50%{background-position:100% 50%}100%{background-position:0% 50%}}
@keyframes nebulaShift{0%{background-position:0% 0%,100% 100%}50%{background-position:8% 8%,92% 92%}100%{background-position:0% 0%,100% 100%}}
@keyframes matrixRain{0%{background-position:0 0}100%{background-position:0 100px}}
@keyframes lavaBlob{0%{background-position:0% 0%}25%{background-position:100% 0%}50%{background-position:100% 100%}75%{background-position:0% 100%}100%{background-position:0% 0%}}
@keyframes gridGlow{0%,100%{opacity:.85}50%{opacity:1}}
@keyframes waveFlow{0%{background-position:0% 50%}50%{background-position:100% 50%}100%{background-position:0% 50%}}
@keyframes diamondShift{0%{background-position:0 0,10px 10px}100%{background-position:20px 20px,30px 30px}}
@keyframes stripeDrift{0%{background-position:0 0}100%{background-position:80px 80px}}
@keyframes starfield{0%{background-position:0 0,200px 300px,400px 100px}100%{background-position:0 -600px,200px -300px,400px -500px}}
@keyframes plasmaShift{0%{background-position:0% 0%,100% 100%,50% 50%}33%{background-position:100% 0%,0% 100%,60% 40%}66%{background-position:50% 100%,50% 0%,30% 70%}100%{background-position:0% 0%,100% 100%,50% 50%}}
/* ── Global animated wallpaper classes — work on top of any theme ── */
#wallpaper.wall-aurora{background:linear-gradient(135deg,#0a0030 0%,#12006a 20%,#002860 40%,#003c28 60%,#001835 80%,#0a0030 100%)!important;background-size:400% 400%!important;animation:auroraFlow 14s ease infinite!important;}
#wallpaper.wall-nebula{background-color:#050010!important;background-image:radial-gradient(ellipse at 20% 50%,rgba(140,0,255,.35) 0%,transparent 52%),radial-gradient(ellipse at 80% 30%,rgba(0,80,255,.28) 0%,transparent 48%),radial-gradient(ellipse at 50% 85%,rgba(255,0,100,.22) 0%,transparent 40%)!important;background-size:200% 200%!important;animation:nebulaShift 18s ease-in-out infinite!important;}
#wallpaper.wall-matrix{background-color:#000!important;background-image:repeating-linear-gradient(0deg,transparent,transparent 18px,rgba(0,255,60,.05) 18px,rgba(0,255,60,.05) 19px),repeating-linear-gradient(90deg,transparent,transparent 10px,rgba(0,255,60,.04) 10px,rgba(0,255,60,.04) 11px)!important;background-size:11px 19px!important;animation:matrixRain 1.2s linear infinite!important;}
#wallpaper.wall-lava{background-color:#160000!important;background-image:radial-gradient(ellipse at 50% 50%,rgba(255,60,0,.42) 0%,transparent 62%),radial-gradient(ellipse at 80% 20%,rgba(255,140,0,.38) 0%,transparent 50%),radial-gradient(ellipse at 20% 80%,rgba(200,0,60,.35) 0%,transparent 50%)!important;background-size:400% 400%!important;animation:lavaBlob 9s ease-in-out infinite!important;}
#wallpaper.wall-grid{background-color:#000810!important;background-image:linear-gradient(rgba(0,200,255,.14) 1px,transparent 1px),linear-gradient(90deg,rgba(0,200,255,.14) 1px,transparent 1px)!important;background-size:40px 40px!important;animation:gridGlow 3.5s ease-in-out infinite!important;}
#wallpaper.wall-waves{background:linear-gradient(135deg,#142040,#1e2870,#182088,#0e1e48,#142050)!important;background-size:400% 400%!important;animation:waveFlow 9s ease infinite!important;}
#wallpaper.wall-diamonds{background-color:#08082a!important;background-image:linear-gradient(45deg,rgba(100,100,255,.16) 25%,transparent 25%,transparent 75%,rgba(100,100,255,.16) 75%),linear-gradient(45deg,rgba(100,100,255,.16) 25%,transparent 25%,transparent 75%,rgba(100,100,255,.16) 75%)!important;background-size:20px 20px!important;background-position:0 0,10px 10px!important;animation:diamondShift 5s linear infinite!important;}
#wallpaper.wall-stripes{background-color:#080e28!important;background-image:repeating-linear-gradient(45deg,transparent,transparent 10px,rgba(80,120,255,.11) 10px,rgba(80,120,255,.11) 20px)!important;animation:stripeDrift 7s linear infinite!important;}
#wallpaper.wall-starfield{background-color:#000!important;background-image:radial-gradient(1px 1px at 10% 20%,#fff 0%,transparent 100%),radial-gradient(1px 1px at 35% 70%,rgba(255,255,255,.8) 0%,transparent 100%),radial-gradient(1px 1px at 70% 40%,rgba(255,255,255,.6) 0%,transparent 100%)!important;background-size:300px 300px,200px 200px,250px 250px!important;animation:starfield 20s linear infinite!important;}
#wallpaper.wall-plasma{background-color:#0a0018!important;background-image:radial-gradient(ellipse at 0% 0%,rgba(255,0,180,.25) 0%,transparent 50%),radial-gradient(ellipse at 100% 100%,rgba(0,200,255,.22) 0%,transparent 50%),radial-gradient(ellipse at 50% 50%,rgba(120,0,255,.18) 0%,transparent 55%)!important;background-size:300% 300%!important;animation:plasmaShift 12s ease-in-out infinite!important;}
@keyframes metroShift{0%{background-position:0% 50%}50%{background-position:100% 50%}100%{background-position:0% 50%}}
@keyframes aquaShimmer{0%,100%{background-position:50% 0%}50%{background-position:50% 8%}}
@keyframes win2kPulse{0%,100%{background-color:#3a6ea5}50%{background-color:#2a5a95}}
@keyframes jellydrift{0%{background-position:50% 0%,80% 100%}100%{background-position:50% 5%,80% 95%}}
@keyframes ios26drift{0%,100%{background-position:0% 0%,100% 100%,50% 50%}50%{background-position:5% 10%,95% 90%,52% 48%}}
@keyframes palmPulse{0%,100%{background-size:3px 3px}50%{background-size:3.5px 3.5px}}
@keyframes ppcShift{0%{background-position:0% 50%}50%{background-position:100% 50%}100%{background-position:0% 50%}}
@keyframes macosOrb{0%,100%{background-position:30% 30%,70% 70%,50% 20%}50%{background-position:35% 25%,65% 75%,55% 25%}}
@keyframes ubuntuShift{0%{background-position:0% 50%}50%{background-position:100% 50%}100%{background-position:0% 50%}}

/* ===== WALLPAPER ===== */
#wallpaper{position:fixed;inset:0;z-index:0;background-color:#008080;background-image:radial-gradient(circle,#006666 1px,transparent 1px);background-size:4px 4px;animation:tealPulse 4s ease-in-out infinite;transition:background-color 0.5s;}
#wallpaper.wall-circles{background-color:#800000!important;background-image:radial-gradient(circle at 50% 50%,#cc0000 30%,#800000 31%,#800000 60%,#990000 61%)!important;background-size:20px 20px!important;animation:circlesPulse 3s ease-in-out infinite!important;}
#wallpaper.wall-sandstone{background-color:#c8a882!important;background-image:repeating-linear-gradient(45deg,#c8a882 0px,#b89060 2px,#c8a882 4px)!important;animation:sandDrift 6s linear infinite!important;}
#wallpaper.wall-forest{background-color:#2d5a1b!important;background-image:radial-gradient(circle,#1a3d0d 1px,transparent 1px)!important;background-size:6px 6px!important;animation:forestBreeze 5s ease-in-out infinite!important;}
#wallpaper.wall-purple{background-color:#4a0080!important;background-image:repeating-linear-gradient(90deg,#4a0080 0px,#5a1090 2px,#4a0080 4px)!important;animation:purpleFlow 4s linear infinite!important;}
#wallpaper.wall-navy{background-color:#000080!important;background-image:radial-gradient(circle,#0000aa 1px,transparent 1px)!important;background-size:4px 4px!important;animation:navyPulse 3s ease-in-out infinite!important;}
#wallpaper.wall-bricks{background-color:#8b2500!important;background-image:repeating-linear-gradient(0deg,#6b1500 0px,#6b1500 2px,#8b2500 2px,#8b2500 18px),repeating-linear-gradient(90deg,#6b1500 0px,#6b1500 2px,#8b2500 2px,#8b2500 38px)!important;background-size:40px 20px!important;animation:brickShift 8s linear infinite!important;}
#wallpaper.wall-clouds{background-color:#87ceeb!important;background-image:radial-gradient(ellipse 80px 50px at 20% 30%,rgba(255,255,255,.95) 0%,transparent 70%),radial-gradient(ellipse 100px 60px at 70% 60%,rgba(255,255,255,.9) 0%,transparent 70%)!important;background-size:300px 200px!important;animation:cloudDrift 15s ease-in-out infinite alternate!important;}
#wallpaper.wall-metal{background-color:#808080!important;background-image:repeating-linear-gradient(90deg,#909090 0px,#707070 1px,#808080 2px)!important;animation:metalSheen 3s linear infinite!important;}

/* ===== THEME-SPECIFIC ANIMATED WALLPAPERS ===== */
@keyframes warmShift{0%{background-position:0% 50%}50%{background-position:100% 50%}100%{background-position:0% 50%}}
@keyframes shimmerH{0%{background-position:-200% 0}100%{background-position:200% 0}}
@keyframes phaseShift{0%{background-position:0% 0%}25%{background-position:100% 0%}50%{background-position:100% 100%}75%{background-position:0% 100%}100%{background-position:0% 0%}}
@keyframes greenPulse{0%,100%{background-position:0% 50%}50%{background-position:100% 50%}}
@keyframes crtDrift{0%{background-position:0 0}100%{background-position:0 60px}}
@keyframes frostShift{0%,100%{background-position:0% 0%}50%{background-position:4% 4%}}
@keyframes roseGlow{0%,100%{background-position:0% 50%}50%{background-position:100% 50%}}
@keyframes copperBars{0%{background-position:0 0%}100%{background-position:0 200%}}
@keyframes nebulaFloat{0%,100%{background-position:0% 0%,100% 100%,50% 50%}33%{background-position:50% 0%,50% 100%,60% 40%}66%{background-position:100% 50%,0% 50%,40% 60%}100%{background-position:0% 0%,100% 100%,50% 50%}}

/* ── WinXP Plus! style ── */
#wallpaper.wall-xp-space{background-color:#000!important;background-image:radial-gradient(ellipse 50% 25% at 50% 40%,rgba(160,180,255,.1) 0%,transparent 60%),radial-gradient(1px 1px at 10% 25%,rgba(255,255,255,.9) 0%,transparent 100%),radial-gradient(1px 1px at 65% 70%,rgba(255,255,255,.7) 0%,transparent 100%),radial-gradient(1px 1px at 85% 15%,rgba(255,255,255,.8) 0%,transparent 100%)!important;background-size:cover,200px 200px,300px 300px,250px 250px!important;animation:starfield 25s linear infinite!important;}
#wallpaper.wall-xp-nature{background:linear-gradient(180deg,#5a9fd4 0%,#70b0d8 33%,#9acc50 36%,#4a9a1e 40%,#2e7a10 65%,#1a5808 100%)!important;background-size:300% 300%!important;animation:greenPulse 18s ease infinite!important;}
#wallpaper.wall-xp-energy{background:linear-gradient(135deg,#001060,#0028b0,#0050e0,#0028b0,#001060)!important;background-size:400% 400%!important;animation:waveFlow 7s ease infinite!important;}
#wallpaper.wall-xp-crystal{background:linear-gradient(135deg,#b8e0f8,#d8f0ff,#a0d8f0,#c8ecff,#b8e0f8)!important;background-size:400% 400%!important;animation:auroraFlow 12s ease infinite!important;}
#wallpaper.wall-xp-royal{background:linear-gradient(135deg,#000840,#0a1880,#0030c0,#001880,#000840)!important;background-size:400% 400%!important;animation:waveFlow 12s ease infinite!important;}
#wallpaper.wall-xp-zune{background:linear-gradient(135deg,#1a0800,#3c1000,#6a2000,#3c1000,#1a0800)!important;background-size:400% 400%!important;animation:warmShift 12s ease infinite!important;}
#wallpaper.wall-xp-luna{background:linear-gradient(135deg,#a0b8d0,#8090c0,#6070b0,#8090c0,#a0b8d0)!important;background-size:400% 400%!important;animation:auroraFlow 16s ease infinite!important;}

/* ── Windows Phone Metro accents ── */
#wallpaper.wall-wp-dark{background-color:#1a1a1a!important;background-image:repeating-linear-gradient(0deg,transparent,transparent 59px,rgba(255,255,255,.04) 59px,rgba(255,255,255,.04) 60px)!important;animation:crtDrift 5s linear infinite!important;}
#wallpaper.wall-wp-cyan{background:linear-gradient(135deg,#00b4d8,#0096c7,#0077b6,#0096c7,#00b4d8)!important;background-size:300% 300%!important;animation:metroShift 7s ease infinite!important;}
#wallpaper.wall-wp-magenta{background:linear-gradient(135deg,#c8005a,#e91e8c,#c8005a,#e91e8c,#c8005a)!important;background-size:300% 300%!important;animation:metroShift 7s ease infinite!important;}
#wallpaper.wall-wp-lime{background:linear-gradient(135deg,#2d8a00,#56bb00,#2d8a00,#56bb00,#2d8a00)!important;background-size:300% 300%!important;animation:metroShift 7s ease infinite!important;}
#wallpaper.wall-wp-amber{background:linear-gradient(135deg,#c05000,#f08020,#c05000,#f08020,#c05000)!important;background-size:300% 300%!important;animation:warmShift 7s ease infinite!important;}
#wallpaper.wall-wp-slate{background:linear-gradient(135deg,#2a3a50,#3a5070,#2a3a50,#3a5070,#2a3a50)!important;background-size:300% 300%!important;animation:waveFlow 10s ease infinite!important;}

/* ── Mac OS X Tiger ── */
#wallpaper.wall-tiger-aqua{background:linear-gradient(135deg,#003880,#0058c8,#1888e8,#60c0f8,#1888e8,#003880)!important;background-size:500% 500%!important;animation:auroraFlow 12s ease infinite!important;}
#wallpaper.wall-tiger-spectrum{background:linear-gradient(135deg,#ff0040,#ff8000,#ffe000,#00e040,#0080ff,#8000ff,#ff0040)!important;background-size:700% 100%!important;animation:shimmerH 6s linear infinite!important;}
#wallpaper.wall-tiger-metal{background:linear-gradient(90deg,#d0d0d0,#f0f0f0,#c0c0c0,#e8e8e8,#b8b8b8,#d8d8d8)!important;background-size:500% 100%!important;animation:shimmerH 5s linear infinite!important;}
#wallpaper.wall-tiger-quartz{background-color:#0e1828!important;background-image:linear-gradient(135deg,rgba(255,255,255,.18) 0%,rgba(180,220,255,.1) 50%,rgba(255,255,255,.15) 100%)!important;background-size:400% 400%!important;animation:auroraFlow 16s ease-in-out infinite!important;}
#wallpaper.wall-tiger-garden{background:linear-gradient(135deg,#1a3010,#304820,#486030,#304820,#1a3010)!important;background-size:400% 400%!important;animation:greenPulse 16s ease infinite!important;}
#wallpaper.wall-tiger-beach{background:linear-gradient(135deg,#c87820,#e8a040,#f0c060,#c08030,#a06010)!important;background-size:400% 400%!important;animation:warmShift 14s ease infinite!important;}

/* ── macOS Modern ── */
#wallpaper.wall-mac-bigsur{background:linear-gradient(160deg,#ff5200,#ff9000,#ffc400,#ff5080,#9818c8,#3010a0,#ff5200)!important;background-size:500% 500%!important;animation:lavaBlob 12s ease-in-out infinite!important;}
#wallpaper.wall-mac-monterey{background:linear-gradient(160deg,#2d0060,#680080,#b020a0,#e04080,#8020b0,#2d0060)!important;background-size:400% 400%!important;animation:auroraFlow 14s ease infinite!important;}
/* Sequoia: sky→golden horizon→deep forest — visually distinct layers */
#wallpaper.wall-mac-sequoia{background:linear-gradient(180deg,#1a4870 0%,#2d6a9f 18%,#e89050 34%,#c06820 40%,#1a5c14 50%,#0a3c08 72%,#052006 100%)!important;background-size:200% 200%!important;animation:auroraFlow 28s ease infinite!important;}
/* Midnight: deep space with star points */
#wallpaper.wall-mac-midnight{background-color:#02020c!important;background-image:radial-gradient(1px 1px at 14% 22%,rgba(255,255,255,.88) 0%,transparent 100%),radial-gradient(1px 1px at 50% 48%,rgba(255,255,255,.72) 0%,transparent 100%),radial-gradient(1px 1px at 78% 72%,rgba(255,255,255,.82) 0%,transparent 100%),radial-gradient(1px 1px at 32% 80%,rgba(255,255,255,.62) 0%,transparent 100%),radial-gradient(1px 1px at 88% 16%,rgba(255,255,255,.78) 0%,transparent 100%),radial-gradient(1px 1px at 62% 10%,rgba(255,255,255,.55) 0%,transparent 100%),radial-gradient(ellipse 70% 40% at 38% 35%,rgba(20,15,70,.55) 0%,transparent 55%),linear-gradient(160deg,#02020c,#060610,#030312)!important;background-size:280px 280px,220px 220px,300px 300px,190px 190px,240px 240px,200px 200px,cover,cover!important;animation:starfield 48s linear infinite!important;}
#wallpaper.wall-mac-ventura{background:linear-gradient(160deg,#3a0010,#800028,#c04020,#e07030,#901830,#3a0010)!important;background-size:400% 400%!important;animation:lavaBlob 12s ease-in-out infinite!important;}

/* ── macOS Aqua / Classic ── */
#wallpaper.wall-aqua-ripple{background:linear-gradient(180deg,#0048a8,#0068d8,#20a0f8,#0068d8,#0048a8)!important;background-size:300% 500%!important;animation:waveFlow 9s ease infinite!important;}
#wallpaper.wall-aqua-silk{background:linear-gradient(135deg,#1060b8,#3090e0,#60c0ff,#3090e0,#1060b8,#3090e0)!important;background-size:500% 100%!important;animation:shimmerH 6s linear infinite!important;}
#wallpaper.wall-aqua-cosmos{background-color:#02040a!important;background-image:radial-gradient(ellipse 80% 40% at 60% 30%,rgba(80,140,255,.15) 0%,transparent 60%),radial-gradient(1px 1px at 20% 40%,rgba(255,255,255,.9) 0%,transparent 100%),radial-gradient(1px 1px at 75% 65%,rgba(255,255,255,.7) 0%,transparent 100%)!important;background-size:cover,250px 250px,200px 200px!important;animation:starfield 30s linear infinite!important;}
#wallpaper.wall-aqua-brushed{background:linear-gradient(90deg,#c8c8c8,#f0f0f0,#b8b8b8,#e8e8e8,#b0b0b0,#d8d8d8)!important;background-size:500% 100%!important;animation:shimmerH 5s linear infinite!important;}

/* ── iOS ── */
#wallpaper.wall-ios-dusk{background:linear-gradient(160deg,#3a0060,#800090,#e05020,#f08040,#c03060,#3a0060)!important;background-size:400% 400%!important;animation:lavaBlob 14s ease-in-out infinite!important;}
#wallpaper.wall-ios-midnight{background:linear-gradient(160deg,#030610,#060d1e,#0a1430,#030610)!important;background-size:300% 300%!important;animation:waveFlow 20s ease infinite!important;}
#wallpaper.wall-ios-celestial{background:linear-gradient(160deg,#002050,#0040a0,#20a0c0,#0060b0,#002050)!important;background-size:400% 400%!important;animation:auroraFlow 13s ease infinite!important;}
#wallpaper.wall-ios-rose{background:linear-gradient(160deg,#4a0028,#a02060,#e060a0,#f090c0,#c04080,#4a0028)!important;background-size:400% 400%!important;animation:roseGlow 12s ease infinite!important;}
#wallpaper.wall-ios-azure{background:linear-gradient(160deg,#001880,#0038d0,#0070ff,#40a0ff,#0048c0,#001880)!important;background-size:400% 400%!important;animation:waveFlow 10s ease infinite!important;}

/* ── Android JellyBean ── */
#wallpaper.wall-jb-galaxy{background-color:#020410!important;background-image:radial-gradient(ellipse 60% 40% at 40% 50%,rgba(30,80,200,.2) 0%,transparent 60%),radial-gradient(1px 1px at 25% 35%,rgba(255,255,255,.85) 0%,transparent 100%),radial-gradient(1px 1px at 72% 68%,rgba(255,255,255,.65) 0%,transparent 100%)!important;background-size:cover,220px 220px,280px 280px!important;animation:starfield 22s linear infinite!important;}
#wallpaper.wall-jb-holo{background:linear-gradient(160deg,#000a1a,#001840,#003080,#001840,#000a1a)!important;background-size:400% 400%!important;animation:waveFlow 11s ease infinite!important;}
#wallpaper.wall-jb-spectrum{background:linear-gradient(135deg,#00ffcc,#0088ff,#8800ff,#ff0088,#ff8800,#00ffcc)!important;background-size:700% 100%!important;animation:shimmerH 6s linear infinite!important;filter:brightness(.3)!important;}
#wallpaper.wall-jb-beam{background:linear-gradient(160deg,#001820,#003040,#006060,#00a090,#004040,#001820)!important;background-size:400% 400%!important;animation:auroraFlow 12s ease infinite!important;}
#wallpaper.wall-jb-phase{background:linear-gradient(160deg,#1a0030,#400060,#8000a0,#c020e0,#600090,#1a0030)!important;background-size:400% 400%!important;animation:plasmaShift 12s ease-in-out infinite!important;}

/* ── Ubuntu/GNOME ── */
#wallpaper.wall-ub-aubergine{background:linear-gradient(135deg,#1a0028,#2e004a,#3c0968,#2e004a,#1a0028)!important;background-size:300% 300%!important;animation:auroraFlow 16s ease infinite!important;}
#wallpaper.wall-ub-yaru{background:linear-gradient(135deg,#6a1800,#b83000,#e04820,#b83000,#6a1800)!important;background-size:300% 300%!important;animation:warmShift 11s ease infinite!important;}
#wallpaper.wall-ub-focal{background:linear-gradient(160deg,#1a0038,#380060,#5a0090,#7020a0,#3c0060,#1a0038)!important;background-size:400% 400%!important;animation:auroraFlow 14s ease infinite!important;}
/* Ubuntu Dark: GNOME ambient purple+orange glow on near-black */
#wallpaper.wall-ub-dark{background-color:#111111!important;background-image:radial-gradient(ellipse 55% 40% at 85% 85%,rgba(230,84,0,.2) 0%,transparent 60%),radial-gradient(ellipse 70% 50% at 15% 15%,rgba(119,33,111,.22) 0%,transparent 60%),repeating-linear-gradient(0deg,transparent,transparent 59px,rgba(255,255,255,.025) 59px,rgba(255,255,255,.025) 60px)!important;background-size:cover,cover,100% 60px!important;animation:crtDrift 8s linear infinite!important;}
#wallpaper.wall-ub-bionic{background:linear-gradient(135deg,#0a1428,#1a2848,#2a3c68,#1a2848,#0a1428)!important;background-size:300% 300%!important;animation:waveFlow 16s ease infinite!important;}

/* ── C64 phosphor screens ── */
#wallpaper.wall-c64-amber{background-color:#080400!important;background-image:repeating-linear-gradient(0deg,rgba(255,160,0,.14) 0px,rgba(255,160,0,.14) 1px,transparent 1px,transparent 3px)!important;background-size:100% 3px!important;animation:crtDrift 0.08s linear infinite!important;}
#wallpaper.wall-c64-green{background-color:#000800!important;background-image:repeating-linear-gradient(0deg,rgba(0,240,80,.14) 0px,rgba(0,240,80,.14) 1px,transparent 1px,transparent 3px)!important;background-size:100% 3px!important;animation:crtDrift 0.08s linear infinite!important;}
#wallpaper.wall-c64-demo{background:linear-gradient(135deg,#00008b,#8b0000,#006400,#00008b)!important;background-size:400% 400%!important;animation:phaseShift 4s ease infinite!important;}
#wallpaper.wall-c64-spectrum{background:linear-gradient(135deg,#0000cc,#cc0000,#cccc00,#00cc00,#00cccc,#0000cc)!important;background-size:700% 100%!important;animation:shimmerH 3s linear infinite!important;}

/* ── WebOS ── */
#wallpaper.wall-wos-orion{background:linear-gradient(160deg,#010210,#040830,#080f40,#040830,#010210)!important;background-size:300% 300%!important;animation:waveFlow 20s ease infinite!important;}
#wallpaper.wall-wos-ripple{background:linear-gradient(160deg,#001828,#003050,#0068a0,#004060,#001828)!important;background-size:400% 400%!important;animation:waveFlow 11s ease infinite!important;}
#wallpaper.wall-wos-neon{background-color:#010108!important;background-image:radial-gradient(ellipse 50% 30% at 50% 50%,rgba(100,0,255,.22) 0%,transparent 60%),radial-gradient(ellipse 30% 60% at 20% 70%,rgba(0,180,255,.15) 0%,transparent 50%)!important;background-size:300% 300%!important;animation:plasmaShift 11s ease-in-out infinite!important;}
#wallpaper.wall-wos-dark{background-color:#0c0c14!important;background-image:radial-gradient(ellipse 40% 60% at 70% 30%,rgba(60,0,120,.2) 0%,transparent 60%)!important;background-size:300% 300%!important;animation:nebulaShift 20s ease-in-out infinite!important;}

/* ── OS/2 Warp ── */
#wallpaper.wall-os2-warp{background:linear-gradient(135deg,#000080,#0000c0,#1010d0,#000080)!important;background-size:300% 300%!important;animation:waveFlow 13s ease infinite!important;}
#wallpaper.wall-os2-steel{background:linear-gradient(135deg,#203040,#304858,#406070,#203040)!important;background-size:300% 300%!important;animation:waveFlow 16s ease infinite!important;}
#wallpaper.wall-os2-olive{background:linear-gradient(135deg,#1c2410,#2e3c18,#3c5020,#1c2410)!important;background-size:300% 300%!important;animation:greenPulse 16s ease infinite!important;}
/* OS/2 Dark: deep blue CRT-glow on near-black */
#wallpaper.wall-os2-dark{background-color:#040410!important;background-image:radial-gradient(ellipse 80% 40% at 50% 50%,rgba(0,0,190,.18) 0%,transparent 60%),repeating-linear-gradient(0deg,transparent,transparent 19px,rgba(30,80,210,.1) 19px,rgba(30,80,210,.1) 20px)!important;background-size:cover,100% 20px!important;animation:crtDrift 3.5s linear infinite!important;}

/* ── Win2K ── */
#wallpaper.wall-w2k-steel{background:linear-gradient(135deg,#1e344e,#2e4a6e,#3e5a7e,#1e344e)!important;background-size:300% 300%!important;animation:waveFlow 16s ease infinite!important;}
#wallpaper.wall-w2k-olive{background:linear-gradient(135deg,#2a3010,#3c4818,#4e6020,#2a3010)!important;background-size:300% 300%!important;animation:greenPulse 16s ease infinite!important;}
#wallpaper.wall-w2k-corp{background:linear-gradient(160deg,#0a1430,#142040,#1a2c50,#142040,#0a1430)!important;background-size:300% 300%!important;animation:waveFlow 18s ease infinite!important;}
/* W2K Graphite: steel shimmer across dark bands */
#wallpaper.wall-w2k-graphite{background:linear-gradient(90deg,#151518,#28282c,#1e1e22,#323236,#141418,#262628,#1c1c1e)!important;background-size:600% 100%!important;animation:shimmerH 12s linear infinite!important;}

/* ── Cute ── */
#wallpaper.wall-cute-bubblegum{background:linear-gradient(135deg,#ff1493,#ff69b4,#ffb6c1,#ff69b4,#ff1493)!important;background-size:400% 400%!important;animation:roseGlow 10s ease infinite!important;}
#wallpaper.wall-cute-lavender{background:linear-gradient(135deg,#4b0082,#9b59b6,#d7bde2,#9b59b6,#4b0082)!important;background-size:400% 400%!important;animation:auroraFlow 13s ease infinite!important;}
#wallpaper.wall-cute-cotton{background:linear-gradient(135deg,#fadadd,#b5d5f5,#fadadd,#b5d5f5)!important;background-size:400% 400%!important;animation:auroraFlow 16s ease infinite!important;}
#wallpaper.wall-cute-rose{background:linear-gradient(135deg,#b5451b,#e09050,#f8c090,#e09050,#b5451b)!important;background-size:400% 400%!important;animation:warmShift 13s ease infinite!important;}

/* ── Professional ── */
#wallpaper.wall-pro-carbon{background-color:#101010!important;background-image:repeating-linear-gradient(45deg,transparent,transparent 4px,rgba(255,255,255,.03) 4px,rgba(255,255,255,.03) 8px)!important;animation:sandDrift 10s linear infinite!important;}
#wallpaper.wall-pro-slate{background:linear-gradient(135deg,#1e2a38,#2c3e50,#34495e,#1e2a38)!important;background-size:300% 300%!important;animation:waveFlow 18s ease infinite!important;}
/* Pro Midnight: deep space with blue star glow */
#wallpaper.wall-pro-midnight{background-color:#030610!important;background-image:radial-gradient(ellipse 80% 50% at 40% 40%,rgba(10,40,100,.3) 0%,transparent 60%),radial-gradient(1px 1px at 18% 38%,rgba(120,160,255,.75) 0%,transparent 100%),radial-gradient(1px 1px at 65% 62%,rgba(120,160,255,.58) 0%,transparent 100%),radial-gradient(1px 1px at 82% 25%,rgba(120,160,255,.68) 0%,transparent 100%),radial-gradient(1px 1px at 44% 82%,rgba(120,160,255,.5) 0%,transparent 100%),linear-gradient(135deg,#030610,#060c1a,#040810)!important;background-size:cover,240px 240px,200px 200px,260px 260px,210px 210px,cover!important;animation:starfield 55s linear infinite!important;}
#wallpaper.wall-pro-steel{background:linear-gradient(90deg,#1a2a3a,#2a3a4a,#3a4a5a,#2a3a4a,#1e2e3e,#2a3a4a)!important;background-size:500% 100%!important;animation:shimmerH 9s linear infinite!important;}

/* ── Spring ── */
#wallpaper.wall-spr-meadow{background:linear-gradient(160deg,#a8e8d0,#60d8a0,#98e8c0,#60c890,#a8e8d0)!important;background-size:400% 400%!important;animation:greenPulse 16s ease infinite!important;}
#wallpaper.wall-spr-sky{background:linear-gradient(180deg,#87ceeb,#b0e2ff,#d0f0ff,#87ceeb)!important;background-size:300% 300%!important;animation:auroraFlow 20s ease infinite!important;}
#wallpaper.wall-spr-rose{background:linear-gradient(135deg,#ff9ec8,#ffb8d8,#ffd0e8,#ffb8d8,#ff9ec8)!important;background-size:400% 400%!important;animation:roseGlow 13s ease infinite!important;}
#wallpaper.wall-spr-lavender{background:linear-gradient(135deg,#7c4da0,#a868c8,#d0a0e8,#a868c8,#7c4da0)!important;background-size:400% 400%!important;animation:auroraFlow 15s ease infinite!important;}
#wallpaper.wall-spr-sunrise{background:linear-gradient(160deg,#ff6b35,#ffb347,#ffe082,#ffca28,#ff8f00)!important;background-size:400% 400%!important;animation:warmShift 15s ease infinite!important;}

/* ── Summer ── */
#wallpaper.wall-sum-ocean{background:linear-gradient(160deg,#003060,#0058a0,#0088c8,#40b0e0,#0068a8,#003060)!important;background-size:400% 400%!important;animation:waveFlow 10s ease infinite!important;}
#wallpaper.wall-sum-sand{background:linear-gradient(160deg,#d4a040,#e8c060,#f8e090,#e8c060,#d4a040)!important;background-size:400% 400%!important;animation:warmShift 16s ease infinite!important;}
#wallpaper.wall-sum-sunset{background:linear-gradient(160deg,#ff4400,#ff8800,#ffcc00,#ff6600,#cc2200,#ff4400)!important;background-size:400% 400%!important;animation:lavaBlob 13s ease-in-out infinite!important;}
#wallpaper.wall-sum-tropical{background:linear-gradient(160deg,#00c8a0,#00e0b0,#40f8d0,#00e0b0,#00a880)!important;background-size:400% 400%!important;animation:greenPulse 12s ease infinite!important;}

/* ── Autumn ── */
#wallpaper.wall-aut-amber{background:linear-gradient(160deg,#6a2000,#b84000,#e07820,#c05010,#8a3000,#6a2000)!important;background-size:400% 400%!important;animation:warmShift 13s ease infinite!important;}
#wallpaper.wall-aut-rust{background:linear-gradient(160deg,#4a1000,#8a2800,#c04020,#8a2800,#4a1000)!important;background-size:300% 300%!important;animation:lavaBlob 15s ease-in-out infinite!important;}
#wallpaper.wall-aut-mahog{background:linear-gradient(160deg,#3a0800,#6a1800,#922c18,#6a1800,#3a0800)!important;background-size:300% 300%!important;animation:warmShift 16s ease infinite!important;}
#wallpaper.wall-aut-pumpkin{background:linear-gradient(160deg,#882000,#d04010,#e86820,#d04010,#882000)!important;background-size:300% 300%!important;animation:lavaBlob 11s ease-in-out infinite!important;}

/* ── Winter ── */
#wallpaper.wall-win-ice{background:linear-gradient(160deg,#a8d8f0,#c8e8f8,#e8f4ff,#c8e8f8,#a8d8f0)!important;background-size:400% 400%!important;animation:frostShift 18s ease infinite!important;}
#wallpaper.wall-win-arctic{background:linear-gradient(160deg,#e0f4ff,#c8ecff,#a8dcf8,#c8ecff,#e0f4ff)!important;background-size:400% 400%!important;animation:frostShift 22s ease infinite!important;}
#wallpaper.wall-win-silver{background:linear-gradient(135deg,#808090,#a0a0b0,#c0c0d0,#a0a0b0,#808090,#a0a0b0)!important;background-size:500% 100%!important;animation:shimmerH 9s linear infinite!important;}
#wallpaper.wall-win-tundra{background:linear-gradient(160deg,#0a2030,#10344a,#183a50,#10344a,#0a2030)!important;background-size:300% 300%!important;animation:waveFlow 20s ease infinite!important;}

/* ── Thanksgiving ── */
#wallpaper.wall-thx-harvest{background:linear-gradient(160deg,#3a1800,#6a2800,#a84800,#d06020,#a84000,#3a1800)!important;background-size:400% 400%!important;animation:warmShift 15s ease infinite!important;}
#wallpaper.wall-thx-maple{background:linear-gradient(160deg,#8a1a00,#c03010,#e86020,#c03010,#8a1a00)!important;background-size:400% 400%!important;animation:lavaBlob 12s ease-in-out infinite!important;}
#wallpaper.wall-thx-corn{background:linear-gradient(160deg,#785010,#a87020,#d0a040,#a87020,#785010)!important;background-size:400% 400%!important;animation:warmShift 18s ease infinite!important;}
#wallpaper.wall-thx-plum{background:linear-gradient(160deg,#2a0838,#501060,#781888,#501060,#2a0838)!important;background-size:400% 400%!important;animation:auroraFlow 16s ease infinite!important;}

/* ── July 4th ── */
#wallpaper.wall-j4-blaze{background:linear-gradient(160deg,#8a0000,#cc1800,#ff3000,#cc1800,#8a0000)!important;background-size:400% 400%!important;animation:lavaBlob 9s ease-in-out infinite!important;}
#wallpaper.wall-j4-glory{background:linear-gradient(160deg,#002060,#003090,#0050c0,#003090,#002060)!important;background-size:300% 300%!important;animation:waveFlow 11s ease infinite!important;}
#wallpaper.wall-j4-white{background:linear-gradient(135deg,#e8e8ff,#f0f0ff,#ffffff,#f0f0ff,#e8e8ff)!important;background-size:400% 400%!important;animation:frostShift 16s ease infinite!important;}
#wallpaper.wall-j4-dusk{background:linear-gradient(160deg,#000020,#001040,#002060,#000820,#000020)!important;background-size:300% 300%!important;animation:waveFlow 20s ease infinite!important;}

/* ── Christmas ── */
/* Xmas Holly: vivid Christmas greens with visible depth */
#wallpaper.wall-xmas-holly{background:linear-gradient(160deg,#01240a,#035010,#077c1a,#1a9430,#05640c,#022c08,#01240a)!important;background-size:400% 400%!important;animation:lavaBlob 20s ease-in-out infinite!important;}
#wallpaper.wall-xmas-cranberry{background:linear-gradient(160deg,#3a0008,#6a0010,#a00018,#6a0010,#3a0008)!important;background-size:300% 300%!important;animation:lavaBlob 15s ease-in-out infinite!important;}
#wallpaper.wall-xmas-gold{background:linear-gradient(135deg,#5a3800,#9a6800,#c89000,#9a6800,#5a3800)!important;background-size:400% 400%!important;animation:warmShift 13s ease infinite!important;}
#wallpaper.wall-xmas-frost{background:linear-gradient(160deg,#c8e8f0,#e0f4ff,#f8fcff,#e0f4ff,#c8e8f0)!important;background-size:400% 400%!important;animation:frostShift 22s ease infinite!important;}

/* ── Miku-specific ── */
#wallpaper.wall-miku-deep{background:linear-gradient(160deg,#001a20,#003038,#004840,#003038,#001a20)!important;background-size:400% 400%!important;animation:auroraFlow 18s ease infinite!important;}
#wallpaper.wall-miku-pink{background:linear-gradient(160deg,#1a0020,#380040,#780880,#380040,#1a0020)!important;background-size:400% 400%!important;animation:auroraFlow 15s ease infinite!important;}
#wallpaper.wall-miku-stage{background-color:#050010!important;background-image:radial-gradient(ellipse 80% 30% at 50% 90%,rgba(57,197,187,.35) 0%,transparent 60%),radial-gradient(ellipse 40% 60% at 20% 50%,rgba(255,120,180,.12) 0%,transparent 50%)!important;background-size:300% 300%!important;animation:nebulaShift 14s ease-in-out infinite!important;}
#wallpaper.wall-miku-cyber{background-color:#000c10!important;background-image:repeating-linear-gradient(90deg,transparent,transparent 39px,rgba(57,197,187,.08) 39px,rgba(57,197,187,.08) 40px),repeating-linear-gradient(0deg,transparent,transparent 39px,rgba(57,197,187,.06) 39px,rgba(57,197,187,.06) 40px)!important;animation:gridGlow 3s ease-in-out infinite!important;}
#wallpaper.wall-miku-sakura{background:linear-gradient(160deg,#0a0416,#150830,#0a0a20,#1a0830,#0a0416)!important;background-size:300% 300%!important;animation:nebulaShift 20s ease-in-out infinite!important;}

/* ── Amiga Workbench variants ── */
/* Kickstart 1.3 boot screen: maroon with CRT scanlines */
#wallpaper.wall-amiga-kickstart{background-color:#aa0000!important;background-image:repeating-linear-gradient(0deg,rgba(0,0,0,.13) 0px,rgba(0,0,0,.13) 1px,transparent 1px,transparent 8px)!important;background-size:100% 8px!important;animation:crtDrift 5s linear infinite!important;}
/* Workbench 2.0: medium-blue crosshatch */
#wallpaper.wall-amiga-wb2{background-color:#6688aa!important;background-image:repeating-linear-gradient(0deg,rgba(255,255,255,.07) 0px,rgba(255,255,255,.07) 1px,transparent 1px,transparent 2px),repeating-linear-gradient(90deg,rgba(0,0,0,.05) 0px,rgba(0,0,0,.05) 1px,transparent 1px,transparent 2px)!important;background-size:2px 2px!important;animation:frostShift 22s ease infinite!important;}
/* Copper bars: authentic Amiga demo-scene rainbow scroll */
#wallpaper.wall-amiga-copper{background-image:repeating-linear-gradient(180deg,#ff2200 0px,#ff8800 18px,#ffee00 36px,#00ff44 54px,#00ccff 72px,#0044ff 90px,#8800ff 108px,#ff0088 126px,#ff2200 144px)!important;background-color:#000!important;background-size:100% 288px!important;animation:copperBars 2s linear infinite!important;}
/* Workbench 3.x: lighter gray, subtle weave */
#wallpaper.wall-amiga-wb3{background-color:#aaaaaa!important;background-image:repeating-linear-gradient(45deg,rgba(255,255,255,.09) 0px,rgba(255,255,255,.09) 1px,transparent 1px,transparent 10px),repeating-linear-gradient(-45deg,rgba(0,0,0,.05) 0px,rgba(0,0,0,.05) 1px,transparent 1px,transparent 10px)!important;background-size:14px 14px!important;animation:forestBreeze 30s ease-in-out infinite!important;}

/* ── NeXTSTEP variants ── */
/* Marble: NeXT's distinctive stone-look texture */
#wallpaper.wall-next-marble{background-color:#2a2a2a!important;background-image:repeating-linear-gradient(22deg,transparent,transparent 8px,rgba(65,65,65,.45) 8px,rgba(65,65,65,.45) 9px,transparent 9px,transparent 17px,rgba(80,80,80,.28) 17px,rgba(80,80,80,.28) 18px)!important;animation:sandDrift 14s linear infinite!important;}
/* Grid Workspace: dark grid like NeXT Workspace Manager */
#wallpaper.wall-next-workspace{background-color:#1a1a1a!important;background-image:linear-gradient(90deg,rgba(255,255,255,.045) 1px,transparent 1px),linear-gradient(0deg,rgba(255,255,255,.045) 1px,transparent 1px)!important;background-size:48px 48px!important;animation:gridGlow 5s ease-in-out infinite!important;}
/* Magenta: NeXT magenta accent nebula glow */
#wallpaper.wall-next-magenta{background-color:#18001e!important;background-image:radial-gradient(ellipse 80% 55% at 50% 50%,rgba(190,0,255,.3) 0%,transparent 60%),radial-gradient(ellipse 40% 60% at 80% 20%,rgba(110,0,210,.18) 0%,transparent 50%)!important;background-size:300% 300%!important;animation:plasmaShift 13s ease-in-out infinite!important;}
/* Deep Blue: NeXT dark workspace blue */
#wallpaper.wall-next-blue{background:linear-gradient(135deg,#060818,#0e1438,#181a50,#0c1030,#060818)!important;background-size:400% 400%!important;animation:waveFlow 15s ease infinite!important;}

/* ── BeOS variants ── */
/* BeOS Blue Wave: classic Be Inc electric blue */
#wallpaper.wall-beos-blue{background:linear-gradient(135deg,#000a44,#0018a0,#0030d0,#0018a0,#000a44)!important;background-size:400% 400%!important;animation:waveFlow 12s ease infinite!important;}
/* BeOS Space: star nebula — Be had a space-themed wallpaper */
#wallpaper.wall-beos-space{background-color:#020208!important;background-image:radial-gradient(ellipse 60% 40% at 50% 50%,rgba(100,80,200,.28) 0%,transparent 60%),radial-gradient(1px 1px at 28% 42%,rgba(255,255,255,.9) 0%,transparent 100%),radial-gradient(1px 1px at 74% 28%,rgba(255,255,255,.7) 0%,transparent 100%),radial-gradient(1px 1px at 55% 72%,rgba(255,255,255,.75) 0%,transparent 100%),radial-gradient(1px 1px at 18% 70%,rgba(255,255,255,.6) 0%,transparent 100%)!important;background-size:cover,220px 220px,280px 280px,190px 190px,260px 260px!important;animation:starfield 24s linear infinite!important;}
/* BeOS Gold: bright gold shimmer matching Be's yellow UI accent */
#wallpaper.wall-beos-gold{background:linear-gradient(135deg,#3a2800,#c89000,#f8d030,#e0a800,#c89000,#3a2800)!important;background-size:500% 100%!important;animation:shimmerH 7s linear infinite!important;}
/* Haiku: open-source BeOS successor leaf green */
#wallpaper.wall-beos-haiku{background:linear-gradient(160deg,#082818,#165a2c,#229040,#165a2c,#082818)!important;background-size:400% 400%!important;animation:greenPulse 15s ease infinite!important;}

/* ── Norton Commander / DOS variants ── */
/* Cyan Grid: blue with cyan panel-line grid glow */
#wallpaper.wall-norton-cyan{background-color:#000088!important;background-image:linear-gradient(90deg,rgba(0,200,255,.2) 1px,transparent 1px),linear-gradient(0deg,rgba(0,200,255,.11) 1px,transparent 1px)!important;background-size:60px 14px!important;animation:gridGlow 3.5s ease-in-out infinite!important;}
/* Amber Phosphor: like an amber CRT monitor */
#wallpaper.wall-norton-amber{background-color:#0e0700!important;background-image:repeating-linear-gradient(0deg,rgba(255,175,0,.16) 0px,rgba(255,175,0,.16) 1px,transparent 1px,transparent 3px)!important;background-size:100% 3px!important;animation:crtDrift 0.1s linear infinite!important;}
/* Green Phosphor: classic green-screen terminal */
#wallpaper.wall-norton-green{background-color:#000800!important;background-image:repeating-linear-gradient(0deg,rgba(0,255,80,.13) 0px,rgba(0,255,80,.13) 1px,transparent 1px,transparent 3px)!important;background-size:100% 3px!important;animation:crtDrift 0.1s linear infinite!important;}
/* Matrix: green digital rain grid */
#wallpaper.wall-norton-matrix{background-color:#000!important;background-image:repeating-linear-gradient(0deg,transparent,transparent 18px,rgba(0,255,60,.07) 18px,rgba(0,255,60,.07) 19px),repeating-linear-gradient(90deg,transparent,transparent 10px,rgba(0,255,60,.05) 10px,rgba(0,255,60,.05) 11px)!important;background-size:11px 19px!important;animation:matrixRain 1.2s linear infinite!important;}

/* ── Atari ST / TOS variants ── */
/* Mint: Atari's GEM desktop teal accent */
#wallpaper.wall-atari-mint{background:linear-gradient(135deg,#005870,#007898,#00a8c8,#007898,#005870)!important;background-size:400% 400%!important;animation:waveFlow 13s ease infinite!important;}
/* Rainbow: TOS 1.x boot palette shimmer */
#wallpaper.wall-atari-rainbow{background:linear-gradient(135deg,#cc0000,#ff8800,#ffcc00,#00aa00,#0000cc,#8800cc,#cc0000)!important;background-size:700% 100%!important;animation:shimmerH 4s linear infinite!important;}
/* Falcon Blue: Atari Falcon 030 deep navy */
#wallpaper.wall-atari-falcon{background:linear-gradient(135deg,#000860,#001080,#1828c0,#0010a0,#000860)!important;background-size:400% 400%!important;animation:waveFlow 14s ease infinite!important;}
/* Dark: Atari TT high-res dark mode nebula */
#wallpaper.wall-atari-dark{background-color:#0a0a14!important;background-image:radial-gradient(ellipse 70% 40% at 50% 50%,rgba(0,0,150,.28) 0%,transparent 60%),radial-gradient(ellipse 40% 50% at 80% 30%,rgba(0,80,160,.18) 0%,transparent 50%)!important;background-size:300% 300%!important;animation:nebulaShift 18s ease-in-out infinite!important;}

/* ── IRIX / SGI variants ── */
/* Indigo: SGI Indigo workstation purple */
#wallpaper.wall-irix-indigo{background:linear-gradient(135deg,#18004a,#300078,#5200a8,#300078,#18004a)!important;background-size:400% 400%!important;animation:auroraFlow 14s ease infinite!important;}
/* Impact: SGI Impact deep navy-slate */
#wallpaper.wall-irix-impact{background:linear-gradient(135deg,#001828,#003050,#005078,#003050,#001828)!important;background-size:300% 300%!important;animation:waveFlow 14s ease infinite!important;}
/* Onyx: SGI Onyx near-black with teal glow */
#wallpaper.wall-irix-onyx{background-color:#040408!important;background-image:radial-gradient(ellipse 70% 40% at 50% 50%,rgba(0,80,120,.24) 0%,transparent 60%),radial-gradient(ellipse 40% 60% at 20% 80%,rgba(0,120,140,.14) 0%,transparent 50%)!important;background-size:300% 300%!important;animation:nebulaShift 22s ease-in-out infinite!important;}
/* Deep Teal: SGI's signature IRIX teal richer version */
#wallpaper.wall-irix-teal{background:linear-gradient(135deg,#003040,#006070,#009090,#006070,#003040)!important;background-size:400% 400%!important;animation:auroraFlow 12s ease infinite!important;}

#wallpaper.theme-aqua{background-color:#1b6ca8!important;background-image:radial-gradient(ellipse 200% 80% at 50% -20%,rgba(255,255,255,.15) 0%,transparent 50%),radial-gradient(ellipse 100% 40% at 50% 0%,#c8e8f8 0%,#2a7cc8 40%,#0a3c88 100%)!important;background-size:cover!important;animation:aquaShimmer 8s ease-in-out infinite!important;}
#wallpaper.theme-win2k{background-color:#3a6ea5!important;background-image:repeating-linear-gradient(0deg,rgba(255,255,255,.03) 0px,rgba(255,255,255,.03) 1px,transparent 1px,transparent 4px)!important;animation:win2kPulse 6s ease-in-out infinite!important;}
#wallpaper.theme-winxp{background:linear-gradient(to bottom,#3d8fc8 0%,#6abde0 30%,#9dd4f0 43%,#6cc83c 44%,#52b828 55%,#3a9020 100%)!important;background-image:radial-gradient(ellipse 120% 55% at 48% 43%,rgba(255,255,255,.32) 0%,transparent 55%)!important;}
#wallpaper.theme-winphone{background-image:linear-gradient(135deg,#0050ef 0%,#0078d7 40%,#00b4d8 100%)!important;background-size:300% 300%!important;animation:metroShift 8s ease infinite!important;}
#wallpaper.theme-jellybean{background-color:#0d0d1a!important;background-image:radial-gradient(ellipse 80% 40% at 50% 0%,rgba(51,170,255,.22) 0%,transparent 60%),radial-gradient(ellipse 40% 40% at 80% 80%,rgba(0,120,255,.12) 0%,transparent 60%),linear-gradient(180deg,#1a1a2e 0%,#0d0d1a 100%)!important;background-size:200% 200%!important;animation:jellydrift 10s ease-in-out infinite alternate!important;}
#wallpaper.theme-jellybean2{background-color:#080c14!important;background-image:linear-gradient(135deg,#080c14 0%,#0c1828 40%,#0a1020 100%)!important;animation:none!important;}
/* Palm OS: authentic gray-green LCD — like original Palm Pilot monochrome screen */
#wallpaper.theme-palmos{background-color:#8fa87a!important;background-image:repeating-linear-gradient(0deg,rgba(0,0,0,.06) 0px,rgba(0,0,0,.06) 1px,transparent 1px,transparent 3px),repeating-linear-gradient(90deg,rgba(0,0,0,.04) 0px,rgba(0,0,0,.04) 1px,transparent 1px,transparent 3px)!important;background-size:3px 3px!important;animation:none!important;}
/* Palm Treo: dark phone look, amber accent — very distinct from Palm OS */
#wallpaper.theme-palmtreo{background-color:#0e0e1a!important;background-image:linear-gradient(160deg,#0e0e1a 0%,#0a0a12 50%,#1a0800 100%)!important;animation:none!important;}
#wallpaper.theme-pocketpc{background-image:linear-gradient(135deg,#1a3a6e 0%,#2a5aae 30%,#3a7ace 60%,#1a4a9e 100%)!important;background-size:300% 300%!important;animation:ppcShift 10s ease infinite!important;}
/* macOS: light mode wallpaper fallback */
#wallpaper.theme-macos{background:linear-gradient(160deg,#dce9fa 0%,#bcd4f5 30%,#a8c8f0 60%,#c8e4fb 100%)!important;background-size:300% 300%!important;animation:macosOrb 12s ease-in-out infinite!important;}
/* Mac OS 9: platinum */
#wallpaper.theme-macos9{background-color:#bfbfbf!important;background-image:repeating-linear-gradient(0deg,rgba(255,255,255,.25) 0px,rgba(255,255,255,.25) 1px,transparent 1px,transparent 2px)!important;}
/* Ubuntu: Yaru purple */
#wallpaper.theme-ubuntu{background-image:linear-gradient(135deg,#300a24 0%,#3c0a3f 20%,#4e0068 40%,#6e3392 60%,#77216f 80%,#5c2d6e 100%)!important;background-size:300% 300%!important;animation:ubuntuShift 12s ease infinite!important;}
#wallpaper.theme-ios26{background:#120c28!important;background-image:none!important;}
#wallpaper.theme-miku{background-color:#020b10!important;background-image:radial-gradient(ellipse 140% 60% at 50% 50%,rgba(57,197,187,.09) 0%,transparent 55%),radial-gradient(ellipse 60% 40% at 15% 75%,rgba(255,105,180,.07) 0%,transparent 45%),linear-gradient(180deg,#020b10 0%,#030c14 60%,#020a0e 100%)!important;background-size:cover!important;}
#ios26-overlay{display:none;position:fixed;inset:0;z-index:0;pointer-events:none;background:radial-gradient(ellipse 90% 70% at 15% 25%,rgba(90,60,160,.55) 0%,transparent 60%),radial-gradient(ellipse 70% 90% at 85% 75%,rgba(50,100,180,.45) 0%,transparent 60%),radial-gradient(ellipse 60% 60% at 50% 10%,rgba(130,60,140,.35) 0%,transparent 50%),#120c28;background-size:300% 300%;animation:ios26drift 18s ease-in-out infinite;}

/* ===== LAYOUT ===== */
#app{position:relative;z-index:1;min-height:100vh;display:flex;flex-direction:column;padding:8px;}

/* ===== HEADER ===== */
#header{background:var(--header-bg,var(--card-bg));border-top:2px solid var(--card-border-light);border-left:2px solid var(--card-border-light);border-right:2px solid var(--card-border-dark);border-bottom:2px solid var(--card-border-dark);padding:8px 14px;display:flex;align-items:center;justify-content:center;gap:8px;margin-bottom:10px;font-family:var(--font);font-size:13px;color:var(--card-text);flex-wrap:wrap;row-gap:5px;min-height:46px;text-shadow:0 1px 2px rgba(0,0,0,.18);}
#logo{font-weight:bold;font-size:14px;white-space:nowrap;}
.widget{display:flex;align-items:center;gap:4px;font-size:12px;color:var(--widget-text);}
/* ===== STAT WIDGET SECTIONS ===== */
.stat-section{position:absolute;min-width:180px;background:var(--card-bg);border:2px solid var(--card-border-dark);border-radius:var(--card-radius);box-shadow:var(--card-shadow);font-family:var(--font);font-size:12px;color:var(--card-text);overflow:hidden;user-select:none;z-index:10;contain:layout paint;box-sizing:border-box;}
.stat-section-hdr{display:flex;align-items:center;gap:5px;padding:4px 8px;background:var(--section-title-bg);color:var(--section-title-text);font-weight:bold;font-size:11px;text-transform:uppercase;letter-spacing:.05em;cursor:default;}
body.edit-mode .stat-section-hdr{cursor:grab;}
body.edit-mode .stat-section-hdr:active{cursor:grabbing;}
.stat-close-btn{background:none;border:none;color:inherit;opacity:.5;cursor:pointer;font-size:14px;padding:0 0 0 4px;margin-left:auto;line-height:1;flex-shrink:0;transition:opacity .15s,color .15s;}
.stat-close-btn:hover{opacity:1;color:#f66;}
.stat-section-body{padding:8px 10px;display:flex;flex-direction:column;gap:6px;}
.stat-row{display:flex;align-items:center;gap:6px;font-size:11px;}
.stat-label{min-width:36px;opacity:.7;}
.stat-bar-wrap{flex:1;background:rgba(0,0,0,.25);border-radius:3px;height:8px;overflow:hidden;}
.stat-bar{height:8px;border-radius:3px;transition:width .5s ease;}
.stat-bar.bar-ok{background:#4caf50;}
.stat-bar.bar-warn{background:#ff9800;}
.stat-bar.bar-crit{background:#f44336;}
.stat-val{min-width:40px;text-align:right;opacity:.85;font-size:10px;}
#clock{margin-left:auto;font-size:13px;font-weight:bold;white-space:nowrap;}

/* ===== SIZE SLIDER (in-header only, visible in edit mode) ===== */
#hdr-size-ctrl{display:none;align-items:center;gap:4px;}
body.edit-mode #hdr-size-ctrl{display:flex;}

/* ===== SEARCH ===== */
#search-wrap{display:flex;gap:4px;align-items:center;}
#search-input{background:var(--search-bg);border-top:2px solid var(--search-border);border-left:2px solid var(--search-border);border-right:2px solid var(--card-border-light);border-bottom:2px solid var(--card-border-light);color:var(--search-text);font-family:var(--font);font-size:13px;padding:3px 8px;width:240px;outline:none;border-radius:var(--card-radius);}
#search-btn{background:var(--card-bg);border-top:2px solid var(--card-border-light);border-left:2px solid var(--card-border-light);border-right:2px solid var(--card-border-dark);border-bottom:2px solid var(--card-border-dark);color:var(--card-text);font-size:11px;padding:2px 7px;cursor:pointer;border-radius:var(--card-radius);}

/* ===== SERVICES GRID (free-drag) ===== */
#services{position:relative;width:100%;min-height:calc(100vh - 120px);max-width:1400px;margin:0 auto;}
.section{position:absolute;display:flex;flex-direction:column;gap:3px;cursor:default;transition:opacity .15s,box-shadow .15s;break-inside:avoid;min-width:160px;max-width:600px;width:240px;}
.section.sec-flash{animation:secFlash 1.4s ease-out;}
@keyframes secFlash{0%,100%{outline:0px solid transparent}20%{outline:3px solid #ffcc00}60%{outline:3px solid #ffcc00}90%{outline:0px solid transparent}}
.section.locked{cursor:default;}
.section.dragging{opacity:.5;cursor:grabbing;z-index:9000;box-shadow:0 8px 32px rgba(0,0,0,.4);}
body.edit-mode .section{cursor:grab;}
body.edit-mode .section.dragging{cursor:grabbing;}
.section.drop-highlight{outline:2px dashed rgba(255,255,255,.5);border-radius:4px;}
/* Page folder widget */
.page-folder{position:absolute;display:flex;flex-direction:column;cursor:grab;min-width:160px;max-width:280px;width:200px;user-select:none;}
.page-folder.locked{cursor:default;}
.page-folder.dragging{opacity:.5;cursor:grabbing;z-index:9000;}
.pf-icon{font-size:48px;text-align:center;line-height:1.1;filter:drop-shadow(0 2px 4px rgba(0,0,0,.3));}
.pf-label{text-align:center;font-family:var(--font);font-size:12px;color:var(--card-text);background:var(--card-bg);border-top:1px solid var(--card-border-light);padding:2px 6px;border-radius:0 0 var(--card-radius) var(--card-radius);}
.pf-add-btn{position:absolute;top:-8px;right:-8px;font-size:10px;background:#22c55e;color:#fff;border:none;border-radius:50%;width:20px;height:20px;cursor:pointer;display:none;align-items:center;justify-content:center;}
body.edit-mode .pf-add-btn{display:flex;}
/* card custom image icon */
.card-icon img{width:22px;height:22px;border-radius:50%;object-fit:cover;vertical-align:middle;}
.section-header{display:flex;flex-direction:column;}
.section-hdr-top{display:flex;align-items:center;justify-content:flex-end;gap:3px;padding:1px 3px;min-height:22px;}
.section-title{background:var(--section-title-bg);color:var(--section-title-text);font-family:var(--font);font-size:11px;font-weight:bold;padding:3px 8px;text-transform:uppercase;letter-spacing:.05em;border-radius:var(--card-radius);width:100%;box-sizing:border-box;}
.section-actions{display:flex;gap:3px;align-items:center;margin-left:auto;}
/* Lock + view buttons always visible on section header */
.section-view-btn,.section-lock-btn{opacity:.6;transition:opacity .2s;background:rgba(255,255,255,.1);border:1px solid rgba(255,255,255,.18);color:inherit;font-size:11px;padding:2px 6px;border-radius:4px;cursor:pointer;}
.section:hover .section-view-btn,.section:hover .section-lock-btn{opacity:1;background:rgba(255,255,255,.2);}
/* Lock indicator — non-interactive, shown only when NOT in edit mode */
.section-lock-indicator{font-size:10px;opacity:.45;cursor:default;user-select:none;padding:1px 3px;line-height:1;}
body.edit-mode .section-lock-indicator{display:none;}
body.edit-mode .section-view-btn{opacity:.7;}
/* ── Collapse button — always visible ── */
.section-collapse-btn{opacity:.55;transition:opacity .15s;background:rgba(255,255,255,.08);border:1px solid rgba(255,255,255,.15);color:inherit;font-size:9px;padding:1px 5px;border-radius:4px;cursor:pointer;flex-shrink:0;line-height:1.4;}
.section:hover .section-collapse-btn{opacity:1;background:rgba(255,255,255,.18);}
/* ── Collapsed state ── */
.section.collapsed .section-body{display:none;}
.section.collapsed{min-height:0;}
.section.collapsed .section-count{display:inline!important;font-size:10px;opacity:.5;margin-left:4px;}
.section-count{display:none;}
/* Page-folder only draggable in edit mode */
body:not(.edit-mode) .page-folder{cursor:default;}
/* + Add button only visible in edit mode */
.section-btn{opacity:0;transition:opacity .2s;pointer-events:none;background:rgba(255,255,255,.15);border:1px solid rgba(255,255,255,.2);color:inherit;font-size:11px;padding:2px 6px;border-radius:4px;cursor:pointer;}
body.edit-mode .section-btn{opacity:1;pointer-events:auto;}
body:not(.edit-mode) #page-folder-btn{display:none;}
body:not(.edit-mode) #add-sticky-btn,body:not(.edit-mode) #sticky-color-pick{display:none;}
body:not(.edit-mode) #add-preset-btn{display:none;}
/* Force theme selector dropdowns always readable — color-scheme:light overrides OS dark-mode native rendering */
#theme-sel,#variant-sel{background:#fff!important;color:#000!important;border:1px solid #888!important;color-scheme:light!important;}
#theme-sel option,#variant-sel option{background:#fff!important;color:#000!important;}
/* Header action buttons: readable on any theme background */
#crt-toggle-btn,#add-sticky-btn{color:inherit!important;}
body.theme-macos #crt-toggle-btn,body.theme-macos #add-sticky-btn,
body.theme-macos9 #crt-toggle-btn,body.theme-macos9 #add-sticky-btn,
body.theme-mac9 #crt-toggle-btn,body.theme-mac9 #add-sticky-btn,
body.theme-macosx #crt-toggle-btn,body.theme-macosx #add-sticky-btn,
body.theme-spring #crt-toggle-btn,body.theme-spring #add-sticky-btn{color:#111!important;}
.section-hide-btn{opacity:0;transition:opacity .2s;pointer-events:none;background:rgba(255,100,100,.25);border:1px solid rgba(255,100,100,.35);color:inherit;font-size:11px;padding:2px 5px;border-radius:4px;cursor:pointer;line-height:1;}
body.edit-mode .section-hide-btn{opacity:1;pointer-events:auto;}
body.edit-mode .section-hide-btn:hover{background:rgba(255,80,80,.5);}
body.edit-mode .section-del-btn{background:rgba(200,50,50,.25);border-color:rgba(200,80,80,.4);color:#ff8888;}
.section-body{background:var(--card-bg);border-top:2px solid var(--card-border-light);border-left:2px solid var(--card-border-light);border-right:2px solid var(--card-border-dark);border-bottom:2px solid var(--card-border-dark);padding:3px;display:flex;flex-direction:column;gap:2px;flex:1;}

/* ===== CARD ===== */
.card{display:flex;align-items:center;gap:8px;padding:5px 7px;background:var(--card-bg);border-top:2px solid var(--card-border-light);border-left:2px solid var(--card-border-light);border-right:2px solid var(--card-border-dark);border-bottom:2px solid var(--card-border-dark);color:var(--card-text);font-family:var(--font);font-size:12px;cursor:pointer;border-radius:var(--card-radius);box-shadow:var(--card-shadow);transition:var(--card-transition);user-select:none;position:relative;}
.card:hover{background:var(--card-hover-bg);color:var(--card-hover-text);}
.card-icon{font-size:calc(15px * var(--col-fs,1));flex-shrink:0;width:20px;text-align:center;}
.card-label{flex:1;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;font-size:calc(12px * var(--col-fs,1));}
.card-edit-btn{display:none;position:absolute;top:2px;right:3px;background:rgba(0,0,0,.6);color:#fff;border:none;border-radius:3px;font-size:9px;padding:1px 4px;cursor:pointer;z-index:10;}
body.edit-mode .card-edit-btn{display:block;}
body.edit-mode .card{padding-right:22px;padding-left:4px;}
/* card drag-to-reorder */
.card-drag-handle{display:none;align-items:center;justify-content:center;width:14px;font-size:11px;color:rgba(255,255,255,.35);cursor:grab;flex-shrink:0;user-select:none;margin-right:3px;}
body.edit-mode .card-drag-handle{display:flex;}
.card.card-is-dragging{opacity:.35;outline:2px dashed rgba(100,160,255,.6);}
.card.card-drop-above::before{content:'';display:block;height:3px;background:#4a9eff;border-radius:2px;margin-bottom:2px;pointer-events:none;}
.card.card-drop-below::after{content:'';display:block;height:3px;background:#4a9eff;border-radius:2px;margin-top:2px;pointer-events:none;}

/* ===== SWITCHER ===== */
#switcher{position:fixed;bottom:10px;right:10px;z-index:9999;display:flex;align-items:center;gap:5px;background:rgba(0,0,0,.72);padding:5px 9px;border-radius:8px;backdrop-filter:blur(4px);flex-wrap:wrap;}
#switcher select,#switcher input[type=range]{font-size:11px;padding:2px 4px;cursor:pointer;border-radius:3px;border:1px solid #888!important;background:#fff!important;color:#000!important;max-width:150px;}
#edit-mode-toggle{font-size:11px;padding:2px 7px;border-radius:4px;background:rgba(255,255,255,.12);border:1px solid rgba(255,255,255,.2);color:#fff;cursor:pointer;}
body.edit-mode #edit-mode-toggle{background:rgba(255,200,0,.3);border-color:gold;}

/* ===== BG MEDIA ===== */
#bg-video{display:none;position:fixed;inset:0;z-index:1;width:100%;height:100%;object-fit:cover;pointer-events:none;}
#bg-video.active{display:block;}
#bg-image{display:none;position:fixed;inset:0;z-index:1;width:100%;height:100%;background-size:cover;background-repeat:no-repeat;background-position:center;pointer-events:none;}
#bg-image.active{display:block;}
#bg-iframe{display:none;position:fixed;inset:0;z-index:1;width:100%;height:100%;border:none;pointer-events:none;}
#bg-iframe.active{display:block;}

/* ===== CANVAS ===== */
.screensaver-canvas{position:fixed;inset:0;z-index:0;display:none;pointer-events:none;}

/* ===== WIN RETRO TASKBAR ===== */
body.theme-startmenu #services,body.theme-win98 #services,body.theme-win2k #services,body.theme-winxp #services,body.theme-winxp2 #services{padding-bottom:50px;}
#winretro-taskbar{display:none;position:fixed;bottom:0;left:0;right:0;height:36px;background:#c0c0c0;border-top:2px solid #fff;z-index:99999;align-items:center;padding:0 4px;gap:4px;font-family:Arial,sans-serif;font-size:12px;}
body.theme-startmenu #winretro-taskbar,body.theme-win98 #winretro-taskbar,body.theme-win2k #winretro-taskbar,body.theme-winxp #winretro-taskbar,body.theme-winxp2 #winretro-taskbar{display:flex;}
#start-btn{height:28px;padding:0 10px;background:#c0c0c0;border-top:2px solid #fff;border-left:2px solid #fff;border-right:2px solid #000;border-bottom:2px solid #000;font-family:'Arial Black',Arial,sans-serif;font-size:12px;font-weight:bold;cursor:pointer;display:flex;align-items:center;gap:4px;user-select:none;}
#start-btn.active{border-top:2px solid #000;border-left:2px solid #000;border-right:2px solid #fff;border-bottom:2px solid #fff;}
#taskbar-clock{margin-left:auto;background:#c0c0c0;border-top:2px solid #808080;border-left:2px solid #808080;border-right:2px solid #fff;border-bottom:2px solid #fff;padding:2px 8px;font-size:12px;height:24px;display:flex;align-items:center;}
/* ===== WIN98 START MENU — cascading flyout ===== */
#start-menu{display:none;position:fixed;bottom:36px;left:0;background:#c0c0c0;border-top:2px solid #fff;border-left:2px solid #fff;border-right:2px solid #000;border-bottom:2px solid #000;z-index:999999;font-family:Arial,sans-serif;font-size:13px;box-shadow:4px 4px 0 rgba(0,0,0,.4);}
#start-menu.open{display:flex;}
#start-menu-sidebar{width:36px;background:linear-gradient(to top,#000080,#1084d0);display:flex;align-items:flex-end;justify-content:center;padding-bottom:8px;flex-shrink:0;}
#start-menu-sidebar span{color:#fff;font-weight:bold;writing-mode:vertical-rl;transform:rotate(180deg);font-size:13px;letter-spacing:2px;}
#start-menu-items{display:flex;flex-direction:column;min-width:200px;}
.sm-item{display:flex;align-items:center;gap:8px;padding:5px 12px;cursor:pointer;color:#000;text-decoration:none;white-space:nowrap;position:relative;}
.sm-item:hover{background:#000080;color:#fff;}
.sm-item:hover .sm-label,.sm-item:hover .sm-arrow{color:#fff;}
.sm-icon{font-size:16px;flex-shrink:0;width:22px;text-align:center;}
.sm-label{font-size:12px;flex:1;}
.sm-arrow{font-size:9px;margin-left:8px;color:#000;}
.sm-sep{border-top:1px solid #808080;border-bottom:1px solid #fff;margin:3px 0;}
/* cascading flyout */
.sm-has-flyout{position:relative;}
.sm-flyout{
  display:none;position:absolute;left:100%;top:-2px;
  background:#c0c0c0;
  border-top:2px solid #fff;border-left:2px solid #fff;
  border-right:2px solid #000;border-bottom:2px solid #000;
  box-shadow:4px 4px 0 rgba(0,0,0,.4);
  z-index:1000000;min-width:200px;max-height:70vh;overflow-y:auto;
}
.sm-has-flyout:hover>.sm-flyout{display:block;}
.sm-flyout-item{display:flex;align-items:center;gap:8px;padding:5px 12px;cursor:pointer;color:#000;text-decoration:none;white-space:nowrap;font-family:Arial,sans-serif;font-size:12px;position:relative;}
.sm-flyout-item:hover{background:#000080;color:#fff;}
.sm-flyout-item:hover .sm-arrow{color:#fff;}
.sm-flyout-sep{border-top:1px solid #808080;border-bottom:1px solid #fff;margin:3px 0;}
.sm-disabled{opacity:.5;cursor:default!important;}
.sm-disabled:hover{background:transparent!important;color:#000!important;}
/* second-level flyout: use fixed positioning set by JS so it escapes overflow:auto clipping */
.sm-flyout .sm-has-flyout>.sm-flyout{position:fixed;left:auto;top:auto;}

/* ===== macOS MENU BAR ===== */
#macos-menubar{display:none;position:fixed;top:0;left:0;right:0;height:24px;background:rgba(240,240,240,.85);backdrop-filter:blur(20px);-webkit-backdrop-filter:blur(20px);border-bottom:1px solid rgba(0,0,0,.12);z-index:99999;align-items:center;padding:0 8px;gap:0;font-family:-apple-system,BlinkMacSystemFont,'SF Pro Text',sans-serif;font-size:13px;font-weight:500;color:#000;}
body.theme-macos #macos-menubar{display:flex;}
body.theme-macos #app{padding-top:30px;}
.macos-apple{font-size:15px;padding:0 8px;cursor:pointer;line-height:24px;position:relative;}
.macos-apple:hover{background:rgba(0,0,0,.08);border-radius:4px;}
.macos-menu-item{padding:0 8px;cursor:pointer;line-height:24px;white-space:nowrap;border-radius:4px;}
.macos-menu-item:hover{background:rgba(0,0,0,.08);}
#macos-clock-bar{margin-left:auto;padding:0 8px;font-size:12px;opacity:.8;}
.macos-menu-popup{display:none;position:absolute;top:24px;left:0;min-width:200px;background:rgba(240,240,240,.95);backdrop-filter:blur(30px);border:1px solid rgba(0,0,0,.15);border-radius:6px;box-shadow:0 4px 20px rgba(0,0,0,.2);font-size:13px;padding:4px 0;z-index:999999;}
.macos-menu-popup.open{display:block;}
.macos-popup-item{padding:5px 16px;cursor:pointer;display:flex;align-items:center;gap:8px;color:#000;}
.macos-popup-item:hover{background:#0070d0;color:#fff;border-radius:4px;}
.macos-popup-sep{height:1px;background:rgba(0,0,0,.15);margin:4px 8px;}

/* ===== Mac OS 9 MENU BAR ===== */
#macos9-menubar{display:none;position:fixed;top:0;left:0;right:0;height:20px;background:linear-gradient(to bottom,#e0e0e0 0%,#c8c8c8 48%,#b8b8b8 50%,#d0d0d0 100%);border-bottom:1px solid #888;z-index:99999;align-items:center;padding:0;font-family:'Chicago',Arial,sans-serif;font-size:12px;font-weight:bold;color:#000;}
body.theme-macos9 #macos9-menubar{display:flex;}
body.theme-macos9 #app{padding-top:28px;}
.m9-apple{width:30px;text-align:center;font-size:14px;padding:0 4px;cursor:pointer;border-right:1px solid #aaa;height:20px;line-height:20px;}
.m9-item{padding:0 8px;cursor:pointer;height:20px;line-height:20px;white-space:nowrap;position:relative;}
.m9-item:hover,.m9-item.active{background:#000080;color:#fff;}
.m9-popup{display:none;position:absolute;top:20px;left:0;min-width:200px;background:#c0c0c0;border:1px solid #000;box-shadow:2px 2px 0 #000;padding:2px 0;z-index:999999;font-size:12px;font-weight:normal;}
.m9-item.active .m9-popup{display:block;}
.m9-popup-item{padding:3px 20px;cursor:pointer;display:flex;align-items:center;gap:8px;white-space:nowrap;}
.m9-popup-item:hover{background:#000080;color:#fff;}
.m9-popup-sep{height:1px;background:#808080;margin:2px 2px;}
.m9-clock{margin-left:auto;padding:0 8px;font-size:11px;font-weight:normal;}

/* ===== Ubuntu / GNOME Bar ===== */
#ubuntu-bar{display:none;position:fixed;top:0;left:0;right:0;height:28px;background:#2c001e;z-index:99999;align-items:center;padding:0 8px;gap:0;font-family:'Ubuntu','Segoe UI',sans-serif;font-size:13px;color:#fff;}
body.theme-ubuntu #ubuntu-bar{display:flex;}
body.theme-ubuntu #app{padding-top:36px;}
body.theme-ubuntu #header{display:none;}
.ubuntu-activities{padding:0 12px;height:28px;line-height:28px;cursor:pointer;font-weight:600;color:#fff;font-size:13px;}
.ubuntu-activities:hover{background:rgba(255,255,255,.1);}
.ubuntu-app-name{padding:0 12px;height:28px;line-height:28px;font-size:13px;font-weight:600;}
.ubuntu-menu-right{margin-left:auto;display:flex;align-items:center;gap:2px;}
.ubuntu-indicator{padding:0 8px;height:28px;line-height:28px;cursor:pointer;font-size:12px;}
.ubuntu-indicator:hover{background:rgba(255,255,255,.1);}

/* ===== WIN9X RETRO — same defaults as win98, taskbar shown ===== */
body.theme-win9x #winretro-taskbar{display:flex;}
body.theme-win9x #services{padding-bottom:50px;}
/* Wallpaper: Win95 teal (same as default) */
#wallpaper.theme-win9x:not([class*="wall-"]){background-color:#008080;background-image:radial-gradient(circle,#006666 1px,transparent 1px);background-size:4px 4px;animation:tealPulse 4s ease-in-out infinite;}
/* WIN9X 3-panel start menu */
#win9x-menu{display:none;position:fixed;bottom:36px;left:0;z-index:999999;font-family:Arial,sans-serif;font-size:13px;flex-direction:row;box-shadow:4px 4px 0 rgba(0,0,0,.4);}
#win9x-menu.open{display:flex;}
.w9x-col{background:#c0c0c0;border-top:2px solid #fff;border-left:2px solid #fff;border-right:2px solid #000;border-bottom:2px solid #000;min-width:200px;display:flex;flex-direction:column;}
.w9x-col+.w9x-col{border-left:1px solid #808080;}
.w9x-sidebar{width:36px;background:linear-gradient(to top,#000080,#1084d0);display:flex;align-items:flex-end;justify-content:center;padding-bottom:8px;flex-shrink:0;}
.w9x-sidebar span{color:#fff;font-weight:bold;writing-mode:vertical-rl;transform:rotate(180deg);font-size:13px;letter-spacing:2px;}
.w9x-col-inner{flex:1;display:flex;flex-direction:column;min-width:180px;max-height:70vh;overflow-y:auto;}
.w9x-item{display:flex;align-items:center;gap:8px;padding:5px 12px;cursor:pointer;color:#000;white-space:nowrap;user-select:none;border:1px solid transparent;}
.w9x-item:hover,.w9x-item.active{background:#000080;color:#fff;}
.w9x-item a{color:inherit;text-decoration:none;display:contents;}
.w9x-item-icon{font-size:16px;flex-shrink:0;width:22px;text-align:center;}
.w9x-item-label{flex:1;font-size:12px;}
.w9x-item-arrow{font-size:9px;margin-left:4px;}
.w9x-sep{border-top:1px solid #808080;border-bottom:1px solid #fff;margin:3px 0;}
.w9x-col-header{padding:3px 12px;font-size:10px;color:#808080;font-weight:bold;background:#d4d0c8;border-bottom:1px solid #808080;}

/* ===== MAC9 RETRO — Mac OS 9 Platinum, click-based Apple Menu ===== */
body.theme-mac9{--font:'Chicago','Charcoal',Arial,sans-serif;--card-bg:#c0c0c0;--card-border-light:#fff;--card-border-dark:#808080;--card-text:#000;--card-hover-bg:#000080;--card-hover-text:#fff;--section-title-bg:linear-gradient(to right,#000080,#1084d0);--section-title-text:#fff;--search-bg:#fff;--search-border:#808080;--search-text:#000;--card-radius:0;--card-shadow:none;--card-transition:none;}
body.theme-mac9 #app{padding-top:22px;}
#wallpaper.theme-mac9{background-color:#bdbdbd!important;background-image:repeating-linear-gradient(0deg,rgba(0,0,0,.03) 0px,rgba(0,0,0,.03) 1px,transparent 1px,transparent 2px),repeating-linear-gradient(90deg,rgba(0,0,0,.03) 0px,rgba(0,0,0,.03) 1px,transparent 1px,transparent 2px)!important;background-size:4px 4px!important;animation:none!important;}
#mac9-menubar{display:none;position:fixed;top:0;left:0;right:0;height:20px;background:linear-gradient(to bottom,#e8e8e8 0%,#d0d0d0 48%,#c0c0c0 50%,#d8d8d8 100%);border-bottom:2px solid #808080;z-index:99999;align-items:center;padding:0;font-family:'Chicago','Charcoal',Arial,sans-serif;font-size:12px;font-weight:bold;color:#000;}
body.theme-mac9 #mac9-menubar{display:flex;}
.mac9-apple-btn{width:34px;text-align:center;font-size:15px;cursor:pointer;border-right:1px solid #aaa;height:20px;line-height:18px;flex-shrink:0;position:relative;}
.mac9-apple-btn:hover,.mac9-apple-btn.active{background:#000080;color:#fff;}
.mac9-mitem{padding:0 8px;cursor:pointer;height:20px;line-height:20px;white-space:nowrap;position:relative;}
.mac9-mitem:hover,.mac9-mitem.active{background:#000080;color:#fff;}
.mac9-mpopup{display:none;position:absolute;top:20px;left:0;min-width:180px;background:#d4d0c8;border:1px solid #000;box-shadow:2px 2px 0 rgba(0,0,0,.5);z-index:999999;font-size:12px;font-weight:normal;padding:2px 0;}
.mac9-mitem.active .mac9-mpopup,.mac9-mitem.open .mac9-mpopup{display:block;}
.mac9-ap-col-header{padding:4px 10px;font-size:10px;font-weight:bold;background:#808080;color:#fff;border-bottom:1px solid #000;}
.mac9-mpopup-item{padding:3px 20px;cursor:pointer;display:flex;align-items:center;gap:8px;white-space:nowrap;}
.mac9-mpopup-item:hover{background:#000080;color:#fff;}
.mac9-mpopup-sep{height:1px;background:#808080;margin:3px 4px;}
.mac9-clock{margin-left:auto;padding:0 8px;font-size:11px;font-weight:normal;}
/* Mac9 Apple Menu — 2-column flyout */
#mac9-apple-panel{display:none;position:fixed;top:20px;left:0;z-index:9999999;flex-direction:row;}
#mac9-apple-panel.open{display:flex;}
.mac9-ap-col{background:#d4d0c8;border:1px solid #000;box-shadow:2px 2px 0 rgba(0,0,0,.5);min-width:200px;max-height:70vh;overflow-y:auto;}
.mac9-ap-item{padding:4px 20px 4px 10px;cursor:pointer;display:flex;align-items:center;gap:8px;white-space:nowrap;font-size:12px;font-family:'Chicago','Charcoal',Arial,sans-serif;}
.mac9-ap-item:hover,.mac9-ap-item.active{background:#000080;color:#fff;}
.mac9-ap-sep{height:1px;background:#808080;margin:3px 4px;}
.mac9-ap-arrow{margin-left:auto;font-size:9px;}

/* ===== MACOSX RETRO — Mac OS X Aqua era, click Apple menu ===== */
body.theme-macosx{--font:-apple-system,'Lucida Grande','Geneva',sans-serif;--card-bg:rgba(255,255,255,.75);--card-border-light:rgba(255,255,255,.9);--card-border-dark:rgba(100,140,200,.5);--card-text:#000;--card-hover-bg:linear-gradient(180deg,#4cacff,#0070d0);--card-hover-text:#fff;--section-title-bg:linear-gradient(180deg,rgba(140,200,255,.9),rgba(60,140,220,.9));--section-title-text:#fff;--search-bg:rgba(255,255,255,.9);--search-border:rgba(100,160,220,.5);--search-text:#000;--card-radius:8px;--card-shadow:0 2px 8px rgba(0,60,160,.2);--card-transition:all .15s;}
body.theme-macosx #app{padding-top:24px;}
/* ===== MAC OSX TIGER — brushed-metal Aqua, Tiger 10.4 ===== */
body.theme-osxtiger{--font:'Lucida Grande','Geneva',sans-serif;--card-bg:linear-gradient(180deg,#f5f5f5 0%,#e8e8e8 100%);--card-border-light:rgba(255,255,255,.95);--card-border-dark:#a0a0a0;--card-text:#1a1a1a;--card-hover-bg:linear-gradient(180deg,#90c8ff 0%,#4090e0 49%,#2070c8 50%,#60a8f0 100%);--card-hover-text:#fff;--section-title-bg:linear-gradient(180deg,#b8b8b8 0%,#909090 48%,#808080 50%,#a8a8a8 100%);--section-title-text:#fff;--search-bg:#fff;--search-border:#a0a0a0;--search-text:#000;--card-radius:4px;--card-shadow:0 1px 4px rgba(0,0,0,.35);--card-transition:all .15s ease;}
body.theme-osxtiger #app{padding-top:22px;}
#wallpaper.theme-osxtiger{background:linear-gradient(135deg,#0a0a28 0%,#1a0a4a 15%,#2a1070 30%,#160838 45%,#0a1858 60%,#0030a0 80%,#1050c8 100%)!important;background-size:300% 300%!important;animation:aquaShimmer 15s ease-in-out infinite!important;}
/* Tiger brushed-metal menu bar */
#osxtiger-menubar{display:none;position:fixed;top:0;left:0;right:0;height:22px;background:repeating-linear-gradient(0deg,rgba(255,255,255,.08) 0px,rgba(255,255,255,.08) 1px,transparent 1px,transparent 2px),linear-gradient(to bottom,#c8c8c8 0%,#a8a8a8 40%,#989898 50%,#b0b0b0 100%);border-bottom:1px solid #707070;z-index:99999;align-items:center;padding:0 6px;font-family:'Lucida Grande',Geneva,sans-serif;font-size:11px;font-weight:bold;color:#1a1a1a;gap:0;}
body.theme-osxtiger #osxtiger-menubar{display:flex;}
.tiger-apple{width:28px;text-align:center;font-size:14px;cursor:pointer;height:22px;line-height:22px;flex-shrink:0;border-right:1px solid #909090;}
.tiger-apple:hover{background:rgba(0,0,0,.15);}
.tiger-mitem{padding:0 8px;cursor:pointer;height:22px;line-height:22px;white-space:nowrap;position:relative;font-weight:bold;}
.tiger-mitem:hover,.tiger-mitem.open{background:linear-gradient(180deg,#2060d0,#1040b0);color:#fff;border-radius:2px;}
.tiger-mpopup{display:none;position:absolute;top:22px;left:0;min-width:180px;background:linear-gradient(180deg,#e8e8e8,#d8d8d8);border:1px solid #808080;box-shadow:0 4px 12px rgba(0,0,0,.35);z-index:999999;font-size:11px;font-weight:normal;padding:2px 0;border-radius:0 0 4px 4px;}
.tiger-mitem.open .tiger-mpopup{display:block;}
.tiger-mpopup-item{padding:3px 20px;cursor:pointer;display:flex;align-items:center;gap:8px;white-space:nowrap;color:#1a1a1a;}
.tiger-mpopup-item:hover{background:linear-gradient(90deg,#2060d0,#1040b0);color:#fff;border-radius:2px;margin:0 3px;}
.tiger-mpopup-sep{height:1px;background:#b0b0b0;margin:3px 6px;}
.tiger-clock{margin-left:auto;padding:0 8px;font-size:10px;font-weight:normal;color:#333;}
body.theme-macosx .section-body{background:rgba(255,255,255,.4);border:1px solid rgba(100,160,220,.3);border-radius:8px;}
body.theme-macosx .card{backdrop-filter:blur(6px);}
/* MacOSX Aqua wallpaper — iconic blue ripple gradient */
#wallpaper.theme-macosx{background:linear-gradient(160deg,#1a4a8a 0%,#2060a0 20%,#1a6ab5 35%,#0e4d8f 50%,#1a5fa8 65%,#2878c8 80%,#1a5a9a 100%)!important;animation:aquaShimmer 8s ease-in-out infinite!important;}
#macosx-menubar{display:none;position:fixed;top:0;left:0;right:0;height:22px;background:rgba(235,235,235,.92);backdrop-filter:blur(16px);-webkit-backdrop-filter:blur(16px);border-bottom:1px solid rgba(0,0,0,.18);z-index:99999;align-items:center;padding:0 4px;font-family:-apple-system,'Lucida Grande','Geneva',sans-serif;font-size:13px;color:#000;}
body.theme-macosx #macosx-menubar{display:flex;}
.mox-apple{font-size:15px;padding:0 8px;cursor:pointer;height:22px;line-height:22px;position:relative;border-radius:3px;}
.mox-apple:hover,.mox-apple.active{background:rgba(0,0,0,.1);}
.mox-item{padding:0 8px;height:22px;line-height:22px;cursor:pointer;white-space:nowrap;border-radius:3px;position:relative;}
.mox-item:hover,.mox-item.active{background:rgba(0,0,0,.08);}
.mox-popup{display:none;position:absolute;top:22px;left:0;min-width:200px;background:rgba(240,240,240,.96);backdrop-filter:blur(20px);border:1px solid rgba(0,0,0,.18);border-radius:5px;box-shadow:0 4px 20px rgba(0,0,0,.25);padding:4px 0;z-index:999999;font-size:13px;}
.mox-item.active .mox-popup,.mox-item.open .mox-popup{display:block;}
.mox-ap-col-header{padding:6px 16px;font-size:11px;font-weight:700;background:rgba(0,0,0,.05);border-bottom:1px solid rgba(0,0,0,.1);color:#333;}
.mox-popup-item{padding:4px 16px;cursor:pointer;display:flex;align-items:center;gap:8px;white-space:nowrap;}
.mox-popup-item:hover{background:#0070d0;color:#fff;border-radius:3px;}
.mox-popup-sep{height:1px;background:rgba(0,0,0,.1);margin:4px 8px;}
.mox-clock{margin-left:auto;font-size:12px;padding:0 8px;}
/* MacOSX Apple 2-column nav panel */
#macosx-apple-panel{display:none;position:fixed;top:22px;left:0;z-index:9999999;flex-direction:row;}
#macosx-apple-panel.open{display:flex;}
.mox-ap-col{min-width:220px;background:rgba(240,240,240,.97);backdrop-filter:blur(20px);border:1px solid rgba(0,0,0,.18);border-radius:0 0 6px 6px;box-shadow:0 4px 20px rgba(0,0,0,.25);max-height:70vh;overflow-y:auto;}
.mox-ap-col+.mox-ap-col{border-left:1px solid rgba(0,0,0,.1);border-radius:0 0 6px 0;}
.mox-ap-item{padding:5px 16px;cursor:pointer;display:flex;align-items:center;gap:8px;white-space:nowrap;font-size:13px;font-family:-apple-system,'Lucida Grande',sans-serif;}
.mox-ap-item:hover,.mox-ap-item.active{background:#0070d0;color:#fff;border-radius:3px;}
.mox-ap-sep{height:1px;background:rgba(0,0,0,.1);margin:4px 8px;}
.mox-ap-arrow{margin-left:auto;font-size:10px;}

/* ===== MODAL ===== */
#link-modal{display:none;position:fixed;inset:0;z-index:99999;background:rgba(0,0,0,.7);align-items:center;justify-content:center;}
#link-modal.open{display:flex;}
#link-modal-box{background:#1a1a2e;border:1px solid rgba(255,255,255,.2);border-radius:16px;padding:22px;width:440px;max-width:96vw;color:#fff;font-family:-apple-system,sans-serif;max-height:90vh;overflow-y:auto;}
#link-modal h3{margin-bottom:12px;font-size:16px;}
#link-modal label{display:block;font-size:12px;color:rgba(255,255,255,.6);margin-bottom:4px;margin-top:10px;}
#link-modal input[type=text],#link-modal select{width:100%;padding:7px 10px;border-radius:7px;border:1px solid rgba(255,255,255,.2);background:rgba(255,255,255,.1);color:#fff;font-size:13px;outline:none;}
.icon-cat-tabs{display:flex;gap:4px;flex-wrap:wrap;margin-bottom:8px;}
.icon-cat-tab{padding:3px 8px;background:rgba(255,255,255,.08);border:1px solid rgba(255,255,255,.12);border-radius:5px;cursor:pointer;font-size:11px;color:rgba(255,255,255,.6);}
.icon-cat-tab.active{background:rgba(74,158,255,.2);border-color:#4a9eff;color:#4a9eff;}
.icon-picker{display:flex;flex-wrap:wrap;gap:4px;max-height:120px;overflow-y:auto;padding:4px;background:rgba(0,0,0,.2);border-radius:8px;}
.icon-opt{font-size:18px;padding:4px 5px;border-radius:5px;cursor:pointer;border:2px solid transparent;transition:all .1s;}
.icon-opt:hover{background:rgba(255,255,255,.1);}
.icon-opt.selected{border-color:#4a9eff;background:rgba(74,158,255,.15);}
.modal-btns{display:flex;gap:8px;margin-top:16px;justify-content:flex-end;}
.modal-btn{padding:7px 14px;border-radius:8px;border:none;cursor:pointer;font-size:13px;font-weight:600;}
.modal-btn-cancel{background:rgba(255,255,255,.1);color:#fff;}
.modal-btn-save{background:#4a9eff;color:#fff;}
.modal-btn-delete{background:rgba(255,60,60,.3);color:#ff6060;}
/* ── Profile rows ── */
.profile-row{background:rgba(255,255,255,.05);border:1px solid rgba(255,255,255,.09);border-radius:9px;padding:10px 12px;display:flex;flex-direction:column;gap:6px;}
.profile-row-active{border-color:rgba(80,150,255,.45);background:rgba(80,150,255,.08);}
.profile-row-top{display:flex;align-items:center;gap:8px;flex-wrap:wrap;}
.profile-row-bot{display:flex;align-items:center;justify-content:space-between;gap:8px;}
.profile-row-actions{display:flex;gap:5px;}
.profile-name{font-size:13px;font-weight:600;color:#e0e6ff;}
.profile-date{font-size:10px;opacity:.45;}
.profile-theme-tag{font-size:10px;background:rgba(255,255,255,.1);border:1px solid rgba(255,255,255,.12);border-radius:4px;padding:1px 6px;opacity:.8;}
.profile-last-tag{font-size:10px;background:rgba(80,150,255,.25);border:1px solid rgba(80,150,255,.4);border-radius:4px;padding:1px 6px;color:#7ab4ff;}
.prof-btn{font-size:11px;padding:3px 9px;border-radius:6px;border:1px solid rgba(255,255,255,.18);background:rgba(255,255,255,.1);color:inherit;cursor:pointer;white-space:nowrap;transition:background .15s;}
.prof-btn:hover{background:rgba(255,255,255,.2);}
.prof-btn-load{border-color:rgba(80,200,80,.4);background:rgba(80,200,80,.15);}
.prof-btn-load:hover{background:rgba(80,200,80,.28);}
.prof-btn-over{border-color:rgba(80,150,255,.4);background:rgba(80,150,255,.15);}
.prof-btn-over:hover{background:rgba(80,150,255,.28);}
.prof-btn-del{border-color:rgba(200,50,50,.4);background:rgba(200,50,50,.15);color:#ff9999;}
.prof-btn-del:hover{background:rgba(200,50,50,.3);}

/* ===== THEME CSS VARS ===== */
body.theme-aqua{--font:'Lucida Grande','Geneva',sans-serif;--card-bg:linear-gradient(180deg,rgba(255,255,255,.95) 0%,rgba(210,235,250,.85) 49%,rgba(185,220,245,.9) 50%,rgba(210,235,250,.85) 100%);--card-border-light:rgba(255,255,255,.8);--card-border-dark:rgba(80,140,200,.6);--card-text:#000;--card-hover-bg:linear-gradient(180deg,#90d0ff 0%,#40a0f0 49%,#2080e0 50%,#50b0ff 100%);--card-hover-text:#fff;--section-title-bg:linear-gradient(180deg,#8ecff5 0%,#2a8fd4 100%);--section-title-text:#fff;--search-bg:rgba(255,255,255,.95);--search-border:rgba(80,140,200,.6);--search-text:#000;--card-radius:10px;--card-shadow:0 2px 6px rgba(0,60,120,.2);--card-transition:all .15s ease;}
body.theme-aqua .section-body{background:rgba(220,238,252,.5);border:1px solid rgba(80,140,200,.4);border-radius:10px;}
body.theme-aqua #header{background:linear-gradient(180deg,rgba(255,255,255,.95),rgba(210,235,250,.85));border:1px solid rgba(80,140,200,.5);border-radius:10px;}

body.theme-ios26{--font:-apple-system,BlinkMacSystemFont,'SF Pro Display',sans-serif;--card-bg:rgba(14,4,38,.75);--card-border-light:rgba(180,150,255,.35);--card-border-dark:rgba(80,50,180,.3);--card-text:#fff;--card-hover-bg:rgba(130,100,255,.45);--card-hover-text:#fff;--section-title-bg:transparent;--section-title-text:rgba(200,180,255,.85);--search-bg:rgba(20,8,50,.8);--search-border:rgba(160,130,255,.4);--search-text:#fff;--card-radius:18px;--card-shadow:0 4px 28px rgba(60,20,120,.55);--card-transition:all .35s cubic-bezier(.4,0,.2,1);--widget-text:rgba(200,180,255,.9);}
body.theme-ios26 #ios26-overlay{display:block;}
body.theme-ios26 .card{backdrop-filter:blur(22px);-webkit-backdrop-filter:blur(22px);border:1px solid rgba(160,130,255,.18);}
body.theme-ios26 .card:hover{transform:translateY(-3px) scale(1.02);border-color:rgba(180,150,255,.35);}
body.theme-ios26 .section-body{background:rgba(255,255,255,.04);border:1px solid rgba(160,130,255,.12);border-radius:18px;backdrop-filter:blur(10px);}
body.theme-ios26 .section-title{color:rgba(190,170,255,.55);font-size:10px;letter-spacing:.08em;}
body.theme-ios26 #header{background:rgba(255,255,255,.06);border:1px solid rgba(160,130,255,.18);border-radius:18px;backdrop-filter:blur(22px);}

/* ===== PALM webOS THEME ===== */
body.theme-webos{
  --font:'Helvetica Neue','Arial',sans-serif;
  --card-bg:rgba(30,30,30,.85);--card-border-light:rgba(80,80,80,.6);--card-border-dark:rgba(0,0,0,.8);
  --card-text:rgba(240,240,240,.95);--card-hover-bg:rgba(60,60,60,.9);--card-hover-text:#fff;
  --section-title-bg:linear-gradient(to right,#1a1a2e,#16213e);--section-title-text:rgba(200,200,255,.8);
  --search-bg:rgba(20,20,20,.8);--search-border:rgba(80,80,80,.5);--search-text:#fff;
  --card-radius:12px;--card-shadow:0 2px 10px rgba(0,0,0,.6);--card-transition:all .2s ease;
  --widget-text:rgba(180,200,255,.8);background:#0d0d1a;color:#e0e0f0;
}
@keyframes webosOrb{0%,100%{background-position:0% 50%}50%{background-position:100% 50%}}
#wallpaper.theme-webos{background:radial-gradient(ellipse at 30% 40%,#1a0a3a 0%,#0a0a1a 60%,#000 100%)!important;background-image:none!important;}
body.theme-webos #header{background:rgba(10,10,25,.95);border-bottom:1px solid rgba(60,60,100,.4);}
body.theme-webos .section-body{background:rgba(20,20,35,.8);border:1px solid rgba(60,60,100,.3);border-radius:12px;}
body.theme-webos .section-title{background:linear-gradient(to right,#1a1a2e,#16213e);color:rgba(160,180,255,.7);border-radius:8px;}
body.theme-webos .card{border:1px solid rgba(60,60,100,.3);border-radius:12px;}
body.theme-webos .card:hover{background:rgba(60,60,100,.6);transform:scale(1.02);}

/* ===== COMMODORE 64 THEME ===== */
body.theme-c64{
  --font:'Share Tech Mono','VT323','Courier New',monospace;
  --card-bg:#5555d0;--card-border-light:#8888ff;--card-border-dark:#3333aa;
  --card-text:#aaaaff;--card-hover-bg:#aaaaff;--card-hover-text:#5555d0;
  --section-title-bg:#5555d0;--section-title-text:#aaaaff;
  --search-bg:#3333aa;--search-border:#aaaaff;--search-text:#aaaaff;
  --card-radius:0px;--card-shadow:none;--card-transition:none;
  --widget-text:#aaaaff;--header-bg:#5555d0;--header-border:2px solid #3333aa;
  background:#5555d0;color:#aaaaff;
}
#wallpaper.theme-c64{background:#3333aa!important;background-image:repeating-linear-gradient(0deg,rgba(0,0,0,.18) 0px,rgba(0,0,0,.18) 1px,transparent 1px,transparent 3px),repeating-linear-gradient(90deg,rgba(80,80,200,.06) 0px,rgba(80,80,200,.06) 1px,transparent 1px,transparent 40px)!important;}
body.theme-c64 #header{background:#3333aa;border:2px solid #aaaaff;border-radius:0;padding:8px 14px;}
body.theme-c64 #logo::before{content:"* * * * ";color:#8888ff;}
body.theme-c64 #logo::after{content:" * * * *";color:#8888ff;}
body.theme-c64 .section-body{background:#5555d0;border:2px solid #8888ff;border-radius:0;}
body.theme-c64 .section-title{background:#5555d0;color:#8888ff;font-size:11px;text-transform:uppercase;letter-spacing:.1em;border:none;}
body.theme-c64 .card{border:none;border-bottom:1px solid rgba(136,136,255,.3);border-radius:0;padding:4px 8px;}
body.theme-c64 .card:hover{background:#aaaaff;color:#5555d0;}
body.theme-c64 #search-input,body.theme-c64 #search-btn{border-radius:0;}
body.theme-c64 #search-btn{background:#aaaaff;color:#5555d0;border:none;}

/* ===== OS/2 WARP THEME ===== */
@keyframes os2wave{0%{background-position:0 0}100%{background-position:40px 0}}
body.theme-os2{
  --font:'Arial','Helvetica',sans-serif;
  --card-bg:#c0c0c0;--card-border-light:#fff;--card-border-dark:#808080;
  --card-text:#000;--card-hover-bg:#004e98;--card-hover-text:#fff;
  --section-title-bg:linear-gradient(to right,#004e98,#0070c0);--section-title-text:#fff;
  --search-bg:#fff;--search-border:#808080;--search-text:#000;
  --card-radius:0px;--card-shadow:2px 2px 0 rgba(0,0,0,.35);--card-transition:all .1s;
  --widget-text:#000;--header-bg:#004e98;
  background:#008080;color:#000;
}
#wallpaper.theme-os2{background:#008080!important;background-image:repeating-linear-gradient(45deg,rgba(0,100,100,.15) 0px,rgba(0,100,100,.15) 2px,transparent 2px,transparent 14px)!important;background-size:20px 20px!important;animation:os2wave 8s linear infinite!important;}
body.theme-os2 #header{background:#004e98;border-top:2px solid #80b0ff;border-left:2px solid #80b0ff;border-right:2px solid #001870;border-bottom:2px solid #001870;border-radius:0;color:#fff;}
body.theme-os2 #logo,body.theme-os2 .widget{color:#fff;}
body.theme-os2 .section-body{background:#d0d0d0;border-top:2px solid #fff;border-left:2px solid #fff;border-right:2px solid #808080;border-bottom:2px solid #808080;border-radius:0;}
body.theme-os2 .section-title{background:linear-gradient(to right,#004e98,#0070c0);color:#fff;padding:3px 8px;}
body.theme-os2 .card{border-top:2px solid #fff;border-left:2px solid #fff;border-right:2px solid #808080;border-bottom:2px solid #808080;border-radius:0;}

/* ===== START MENU THEME (Win 95/98 Start Menu style) ===== */
body.theme-startmenu{--font:'Arial',sans-serif;--card-bg:#c0c0c0;--card-border-light:#fff;--card-border-dark:#808080;--card-text:#000;--card-hover-bg:#000080;--card-hover-text:#fff;--section-title-bg:linear-gradient(to right,#000080,#1084d0);--section-title-text:#fff;--card-radius:0px;}

/* ===== SUMMER THEME ===== */
@keyframes sunRay{0%{transform:rotate(0deg)}100%{transform:rotate(360deg)}}
@keyframes waveShift{0%,100%{background-position:0% 80%}50%{background-position:100% 80%}}
body.theme-summer{
  --font:'Arial','Helvetica',sans-serif;
  --card-bg:rgba(255,245,210,.85);--card-border-light:rgba(255,220,100,.8);--card-border-dark:rgba(200,140,20,.5);
  --card-text:#3a2a00;--card-hover-bg:rgba(255,180,30,.85);--card-hover-text:#1a1000;
  --section-title-bg:linear-gradient(to right,#e87000,#f5b800);--section-title-text:#fff;
  --search-bg:rgba(255,248,220,.9);--search-border:rgba(220,160,30,.5);--search-text:#3a2a00;
  --card-radius:8px;--card-shadow:0 2px 10px rgba(180,100,0,.2);--card-transition:all .2s ease;
  --widget-text:rgba(60,40,0,.8);background:#0a4080;color:#1a1000;
}
#wallpaper.theme-summer{background:linear-gradient(180deg,#0a6aba 0%,#1a8ad4 35%,#55b0e0 50%,#f5dfa0 55%,#f0c860 65%,#e88a00 80%,#1a5090 100%)!important;background-size:100% 100%!important;}
body.theme-summer #header{background:rgba(255,200,60,.92);border-bottom:2px solid rgba(220,140,0,.5);}
body.theme-summer #logo,body.theme-summer .widget,body.theme-summer #clock{color:#3a2000;}
body.theme-summer .section-body{background:rgba(255,245,200,.7);border:1px solid rgba(220,160,30,.3);border-radius:8px;}
body.theme-summer .card:hover{box-shadow:0 4px 16px rgba(200,120,0,.3);}

/* ===== THANKSGIVING THEME ===== */
@keyframes leafFall{0%{transform:translateY(-10px) rotate(0deg)}100%{transform:translateY(100vh) rotate(720deg)}}
@keyframes thanksPulse{0%,100%{background-position:0% 50%}50%{background-position:100% 50%}}
body.theme-thanksgiving{
  --font:'Georgia','Palatino Linotype',serif;
  --card-bg:rgba(90,42,10,.82);--card-border-light:rgba(220,140,50,.6);--card-border-dark:rgba(40,15,5,.9);
  --card-text:#f5daa0;--card-hover-bg:rgba(180,70,20,.85);--card-hover-text:#fff8e8;
  --section-title-bg:linear-gradient(to right,#7a2c00,#c05000);--section-title-text:#ffd8a0;
  --search-bg:rgba(70,30,5,.7);--search-border:rgba(220,130,40,.4);--search-text:#f5daa0;
  --card-radius:6px;--card-shadow:0 3px 12px rgba(0,0,0,.55);--card-transition:all .2s ease;
  --widget-text:rgba(240,180,80,.85);--header-bg:rgba(50,20,3,.97);
  background:linear-gradient(160deg,#1e0800,#3a1200,#501c00);color:#f5daa0;
}
body.theme-thanksgiving #header{background:rgba(50,20,3,.97);border-bottom:1px solid rgba(200,90,20,.3);}
body.theme-thanksgiving .section-body{background:rgba(60,22,5,.65);border:1px solid rgba(180,90,20,.2);border-radius:6px;}
body.theme-thanksgiving .card:hover{box-shadow:0 4px 18px rgba(180,70,20,.4);}
#wallpaper.theme-thanksgiving{background:linear-gradient(160deg,#1e0800,#3a1200,#501c00)!important;background-size:300% 300%!important;animation:thanksPulse 15s ease infinite!important;}

/* ===== 4TH OF JULY THEME ===== */
@keyframes starsGlow{0%,100%{opacity:.6}50%{opacity:1}}
@keyframes july4Wave{0%{background-position:0% 50%}50%{background-position:100% 50%}100%{background-position:0% 50%}}
body.theme-july4{
  --font:'Arial Black','Impact',sans-serif;
  --card-bg:rgba(5,30,90,.88);--card-border-light:rgba(200,210,255,.5);--card-border-dark:rgba(0,10,50,.9);
  --card-text:#fff;--card-hover-bg:rgba(180,20,20,.85);--card-hover-text:#fff;
  --section-title-bg:linear-gradient(to right,#8b0000,#cc0000);--section-title-text:#fff;
  --search-bg:rgba(5,20,70,.7);--search-border:rgba(200,210,255,.4);--search-text:#fff;
  --card-radius:4px;--card-shadow:0 3px 12px rgba(0,0,0,.55);--card-transition:all .2s ease;
  --widget-text:rgba(200,210,255,.85);--header-bg:rgba(10,15,60,.97);
  background:linear-gradient(160deg,#020818,#06133a,#0a1e5c);color:#fff;
}
body.theme-july4 #header{background:rgba(10,15,60,.97);border-bottom:2px solid #cc0000;}
body.theme-july4 .section-body{background:rgba(5,15,60,.65);border:1px solid rgba(200,210,255,.15);border-radius:4px;}
body.theme-july4 .section-title{background:linear-gradient(to right,#8b0000,#cc0000)!important;}
body.theme-july4 .card:hover{box-shadow:0 4px 20px rgba(200,30,30,.5);}
#wallpaper.theme-july4{background:linear-gradient(135deg,#020818 0%,#06133a 40%,#0a1e5c 80%,#020818 100%)!important;background-size:300% 300%!important;animation:july4Wave 20s ease infinite!important;}

/* ===== CHRISTMAS THEME (Christ-Centered) ===== */
@keyframes starShine{0%,100%{opacity:.5;transform:scale(1)}50%{opacity:1;transform:scale(1.15)}}
@keyframes christmasGlow{0%,100%{background-position:0% 50%}50%{background-position:100% 50%}}
body.theme-christmas{
  --font:'Georgia','Palatino Linotype',serif;
  --card-bg:rgba(8,45,20,.88);--card-border-light:rgba(200,170,80,.5);--card-border-dark:rgba(3,20,8,.9);
  --card-text:#e8f5e0;--card-hover-bg:rgba(140,20,20,.85);--card-hover-text:#fff;
  --section-title-bg:linear-gradient(to right,#8b0000,#006400);--section-title-text:#ffd700;
  --search-bg:rgba(5,30,12,.7);--search-border:rgba(200,170,80,.4);--search-text:#e8f5e0;
  --card-radius:6px;--card-shadow:0 3px 14px rgba(0,0,0,.55);--card-transition:all .2s ease;
  --widget-text:rgba(220,200,100,.85);--header-bg:rgba(5,25,10,.97);
  background:linear-gradient(160deg,#020c04,#081808,#0d2510);color:#e8f5e0;
}
body.theme-christmas #header{background:rgba(5,25,10,.97);border-bottom:2px solid rgba(180,140,40,.4);}
body.theme-christmas #logo::after{content:" ✦ Glory to God ✦";font-size:10px;color:rgba(220,200,80,.7);margin-left:8px;}
body.theme-christmas .section-body{background:rgba(5,28,10,.65);border:1px solid rgba(180,150,60,.18);border-radius:6px;}
body.theme-christmas .card:hover{box-shadow:0 4px 20px rgba(180,140,30,.4);}
#wallpaper.theme-christmas{background:linear-gradient(160deg,#020c04,#081808,#0d2510)!important;background-size:400% 400%!important;animation:christmasGlow 20s ease infinite!important;}

body.theme-winxp,body.theme-winxp2{--font:'Tahoma',sans-serif;--card-bg:linear-gradient(180deg,#f0f4ff 0%,#c4d4f0 100%);--card-border-light:rgba(255,255,255,.9);--card-border-dark:#7a9fd4;--card-text:#000;--card-hover-bg:linear-gradient(180deg,#ffe484 0%,#ff8c00 100%);--card-hover-text:#000;--section-title-bg:linear-gradient(180deg,#2a6dd9,#1a4fbb);--section-title-text:#fff;--search-bg:#fff;--search-border:#7a9fd4;--search-text:#000;--card-radius:6px;--card-shadow:0 2px 4px rgba(0,0,80,.3);--card-transition:all .1s ease;}
body.theme-winxp .section-body,body.theme-winxp2 .section-body{background:rgba(220,232,252,.6);border:1px solid #7a9fd4;border-radius:6px;}
body.theme-winxp #header,body.theme-winxp2 #header{background:linear-gradient(180deg,#f0f4ff,#d8e4f8);border:1px solid #7a9fd4;border-radius:8px;}

body.theme-win2k{--font:'Tahoma','Arial',sans-serif;--card-bg:#d4d0c8;--card-border-light:#fff;--card-border-dark:#808080;--card-text:#000;--card-hover-bg:#3a6ea5;--card-hover-text:#fff;--section-title-bg:#3a6ea5;--section-title-text:#fff;--search-bg:#fff;--search-border:#808080;--search-text:#000;--card-radius:0px;}
body.theme-win2k #header{background:#3a6ea5;border:none;color:#fff;}
body.theme-win2k #logo,body.theme-win2k .widget{color:#fff;}

body.theme-winphone{--font:'Segoe UI','Arial',sans-serif;--card-bg:rgba(0,80,239,.85);--card-border-light:transparent;--card-border-dark:transparent;--card-text:#fff;--card-hover-bg:rgba(0,120,215,1);--card-hover-text:#fff;--section-title-bg:transparent;--section-title-text:rgba(255,255,255,.4);--search-bg:rgba(255,255,255,.12);--search-border:rgba(255,255,255,.3);--search-text:#fff;--card-radius:0px;--widget-text:rgba(255,255,255,.8);}
body.theme-winphone #header{background:transparent;border:none;}
body.theme-winphone .section-body,.theme-winphone .card{border:none;border-radius:0;}

body.theme-jellybean,body.theme-jellybean2{--font:'Roboto','Droid Sans',sans-serif;--card-bg:linear-gradient(180deg,#2d2d2d 0%,#1a1a1a 100%);--card-border-light:#3a3a3a;--card-border-dark:#111;--card-text:#e0e0e0;--card-hover-bg:linear-gradient(180deg,#33aaff 0%,#1a88dd 100%);--card-hover-text:#fff;--section-title-bg:linear-gradient(180deg,#33aaff,#1a88dd);--section-title-text:#fff;--search-bg:#1a1a1a;--search-border:#33aaff;--search-text:#e0e0e0;--card-radius:4px;--card-shadow:0 1px 3px rgba(0,0,0,.5);--card-transition:all .2s ease;--widget-text:#33aaff;}
body.theme-jellybean .section-body,body.theme-jellybean2 .section-body{background:rgba(30,30,30,.8);border:1px solid #333;border-radius:4px;}
body.theme-jellybean #header,body.theme-jellybean2 #header{background:linear-gradient(180deg,#2d2d2d,#1a1a1a);border:1px solid #333;border-radius:6px;}

/* Palm OS: green monochrome LCD — original Palm Pilot look */
body.theme-palmos{--font:'Courier New','Lucida Console',monospace;--card-bg:#a8be88;--card-border-light:#c0d4a0;--card-border-dark:#3a5020;--card-text:#152808;--card-hover-bg:#2d4418;--card-hover-text:#c8dca0;--section-title-bg:linear-gradient(to right,#2a3c18,#3a5020);--section-title-text:#b0cc88;--search-bg:#c0d49c;--search-border:#3a5020;--search-text:#152808;--card-radius:0px;--widget-text:#2a4418;}
body.theme-palmos #header{background:linear-gradient(to right,#2a3c18,#3a5020);border-top:2px solid #c0d4a0;border-left:2px solid #c0d4a0;border-right:2px solid #1a2c10;border-bottom:2px solid #1a2c10;}
body.theme-palmos #header *{color:#b0cc88!important;}
body.theme-palmos .section-body{background:#9aaf7a;}
body.theme-palmos .section-title{background:linear-gradient(to right,#2a3c18,#3a5020)!important;color:#b0cc88!important;}
body.theme-palmos .card{border-radius:0!important;}
/* Palm Treo 650: distinct — dark OLED, amber backlight */
body.theme-palmtreo{--font:'Tahoma',sans-serif;--card-bg:linear-gradient(180deg,#1a1a2e,#0d0d1a);--card-border-light:#ff8c00;--card-border-dark:#4a3000;--card-text:#ff8c00;--card-hover-bg:linear-gradient(180deg,#ff8c00,#cc6600);--card-hover-text:#000;--section-title-bg:linear-gradient(to right,#cc6600,#ff8c00);--section-title-text:#000;--search-bg:#0a0a15;--search-border:#ff8c00;--search-text:#ff8c00;--card-radius:3px;--widget-text:#ff8c00;}
body.theme-palmtreo .section-body{background:rgba(10,10,20,.85);border:1px solid #ff8c00;}
body.theme-palmtreo #header{background:linear-gradient(180deg,#1a0800,#0d0400);border:1px solid #ff8c00;border-radius:0;}
body.theme-palmtreo #header *{color:#ff8c00!important;}
body.theme-palmtreo .section-title{background:linear-gradient(to right,#cc6600,#ff8c00)!important;color:#000!important;}

/* ── Palm V / Vx (1999): slim metallic dark chassis, sharp green LCD ── */
@keyframes palmVScan{0%{background-position:0 0}100%{background-position:0 80px}}
body.theme-palmv{--font:'Courier New','Lucida Console',monospace;--card-bg:#111a14;--card-border-light:#00cc60;--card-border-dark:#003318;--card-text:#00ee70;--card-hover-bg:#003320;--card-hover-text:#00ff80;--section-title-bg:linear-gradient(to right,#0a1a0e,#0f2416);--section-title-text:#00cc60;--search-bg:#080f0a;--search-border:#009940;--search-text:#00ee70;--card-radius:2px;--widget-text:#00cc60;}
#wallpaper.theme-palmv{background-color:#080e0a!important;background-image:repeating-linear-gradient(0deg,rgba(0,210,80,.05) 0px,rgba(0,210,80,.05) 1px,transparent 1px,transparent 4px)!important;animation:palmVScan 6s linear infinite!important;}
body.theme-palmv #header{background:linear-gradient(180deg,#1a1a1a,#111);border-bottom:2px solid #009940;border-top:2px solid #222;}
body.theme-palmv #header *{color:#00ee70!important;}
body.theme-palmv .section-body{background:rgba(0,15,8,.9);border:1px solid #004422;}
body.theme-palmv .section-title{background:linear-gradient(to right,#0a1a0e,#152012)!important;color:#00cc60!important;}
body.theme-palmv .card{border-radius:0!important;}

/* ── Palm Pilot original (1996-1998): beige plastic body, green-gray LCD ── */
@keyframes pilotScan{0%{background-position:0 0}100%{background-position:0 48px}}
body.theme-palmpilot{--font:'Courier New','Lucida Console',monospace;--card-bg:#c4c49a;--card-border-light:#dcdca8;--card-border-dark:#6a6a50;--card-text:#1a1a0a;--card-hover-bg:#3c3c28;--card-hover-text:#c4c49a;--section-title-bg:linear-gradient(to right,#3c3c28,#4c4c38);--section-title-text:#c4c49a;--search-bg:#d0d0a0;--search-border:#6a6a50;--search-text:#1a1a0a;--card-radius:0px;--widget-text:#282818;}
#wallpaper.theme-palmpilot{background-color:#b4b48a!important;background-image:repeating-linear-gradient(0deg,rgba(0,0,0,.06) 0px,rgba(0,0,0,.06) 1px,transparent 1px,transparent 2px)!important;animation:pilotScan 10s linear infinite!important;}
body.theme-palmpilot #header{background:linear-gradient(180deg,#505040,#3c3c2c);border:2px solid #c4c49a;}
body.theme-palmpilot #header *{color:#c4c49a!important;}
body.theme-palmpilot .section-body{background:#bcbc90;}
body.theme-palmpilot .section-title{background:linear-gradient(to right,#3c3c28,#4c4c38)!important;color:#c4c49a!important;}
body.theme-palmpilot .card{border-radius:0!important;}

/* ===== AMIGA WORKBENCH (1985–1992): gray desktop, blue/orange titlebars ===== */
body.theme-amiga{--font:'Topaz','Courier New',monospace;--card-bg:#aaaaaa;--card-border-light:#ffffff;--card-border-dark:#555555;--card-text:#000000;--card-hover-bg:#0055aa;--card-hover-text:#ffffff;--section-title-bg:linear-gradient(to right,#0055aa,#0077cc);--section-title-text:#ffffff;--search-bg:#cccccc;--search-border:#888888;--search-text:#000000;--card-radius:0px;--widget-text:#000000;}
#wallpaper.theme-amiga{background-color:#a8a8a8!important;background-image:repeating-linear-gradient(45deg,rgba(255,255,255,.07) 0px,rgba(255,255,255,.07) 1px,transparent 1px,transparent 10px),repeating-linear-gradient(-45deg,rgba(0,0,0,.04) 0px,rgba(0,0,0,.04) 1px,transparent 1px,transparent 10px)!important;background-size:14px 14px,14px 14px!important;}
body.theme-amiga #header{background:linear-gradient(to right,#0055aa,#0077cc);border-bottom:2px solid #003388;}
body.theme-amiga #header *{color:#fff!important;}
body.theme-amiga .section-header{background:linear-gradient(to right,#0055aa,#0077cc);border-bottom:1px solid #003388;}
body.theme-amiga .section-title{background:transparent!important;color:#fff!important;}
body.theme-amiga .section-count{color:rgba(255,255,255,.7)!important;}
body.theme-amiga .card{border-top:2px solid #fff;border-left:2px solid #fff;border-bottom:2px solid #555;border-right:2px solid #555;border-radius:0;}
body.theme-amiga .section-body{background:#aaaaaa;border:2px solid #aaa;}
body.theme-amiga .stat-section{border-top:2px solid #fff;border-left:2px solid #fff;border-bottom:2px solid #555;border-right:2px solid #555;}
body.theme-amiga .stat-section-hdr{background:linear-gradient(to right,#ff8800,#ffaa00);color:#000!important;}
/* Fix: #header *{color:#fff!important} would whiteout select/input text — override back for form controls */
body.theme-amiga #header select,body.theme-amiga #header input[type="text"],body.theme-amiga #search-input{color:#000!important;background:#d0d0d0!important;border:1px solid #777!important;}
body.theme-amiga #header select option{color:#000;background:#d0d0d0;}
body.theme-amiga #search-btn{color:#000!important;background:#bbbbbb!important;border:1px solid #777!important;}
body.theme-amiga #header input[type="range"]{accent-color:#ff8800;}

/* ===== NeXTSTEP (1989–1997): black, dark-gray panels, ultra-clean ===== */
body.theme-nextstep{--font:'Helvetica Neue','Arial',sans-serif;--card-bg:#2a2a2a;--card-border-light:#444;--card-border-dark:#111;--card-text:#ddd;--card-hover-bg:#444;--card-hover-text:#fff;--section-title-bg:linear-gradient(to bottom,#3a3a3a,#1a1a1a);--section-title-text:#ccc;--search-bg:#1a1a1a;--search-border:#444;--search-text:#eee;--card-radius:3px;--widget-text:#aaa;}
#wallpaper.theme-nextstep{background-color:#1c1c1c!important;background-image:radial-gradient(ellipse 180% 80% at 50% 120%,rgba(80,80,80,.18) 0%,transparent 60%),repeating-linear-gradient(110deg,rgba(255,255,255,.015) 0px,rgba(255,255,255,.015) 1px,transparent 1px,transparent 26px)!important;}
body.theme-nextstep #header{background:#1a1a1a;border-bottom:2px solid #444;}
body.theme-nextstep .section-header{background:linear-gradient(to bottom,#3a3a3a,#222);border-bottom:1px solid #111;}
body.theme-nextstep .stat-section-hdr{background:linear-gradient(to bottom,#3a3a3a,#1c1c1c);}

/* ===== BeOS (1995–2000): tan desktop, golden-yellow titlebars ===== */
body.theme-beos{--font:'Arial','Helvetica',sans-serif;--card-bg:#d4c890;--card-border-light:#e8d8a0;--card-border-dark:#8a7840;--card-text:#1a1200;--card-hover-bg:#f0c808;--card-hover-text:#000;--section-title-bg:linear-gradient(to right,#c8a400,#f0c808);--section-title-text:#1a0c00;--search-bg:#e8d890;--search-border:#8a7840;--search-text:#1a1200;--card-radius:3px;--widget-text:#3a2800;}
#wallpaper.theme-beos{background-color:#b8a870!important;background-image:radial-gradient(ellipse 200% 100% at 50% 110%,rgba(60,40,0,.22) 0%,transparent 55%),repeating-linear-gradient(135deg,rgba(255,255,255,.06) 0px,rgba(255,255,255,.06) 1px,transparent 1px,transparent 18px)!important;}
body.theme-beos #header{background:linear-gradient(180deg,#d8b400,#b89400);border-bottom:2px solid #8a7000;}
body.theme-beos #header *{color:#1a0c00!important;}
body.theme-beos .section-header{background:linear-gradient(to right,#d8b400,#f0c808);border-bottom:1px solid #8a7000;}
body.theme-beos .section-title{color:#1a0c00!important;}
body.theme-beos .stat-section-hdr{background:linear-gradient(to right,#e8c408,#f8d410);color:#1a0c00!important;}
body.theme-beos .card{border-top:2px solid #f8e890;border-left:2px solid #f8e890;border-bottom:2px solid #8a7000;border-right:2px solid #8a7000;}

/* ===== DOS / Norton Commander (1986–1996): bright blue panels ===== */
body.theme-norton{--font:'Courier New','Lucida Console',monospace;--card-bg:#0000aa;--card-border-light:#5555ff;--card-border-dark:#00007a;--card-text:#fff;--card-hover-bg:#00007a;--card-hover-text:#ffff55;--section-title-bg:#0000dd;--section-title-text:#ffff55;--search-bg:#00007a;--search-border:#5555ff;--search-text:#fff;--card-radius:0px;--widget-text:#aaaaff;}
#wallpaper.theme-norton{background-color:#0000aa!important;background-image:repeating-linear-gradient(0deg,rgba(0,0,0,.2) 0px,rgba(0,0,0,.2) 1px,transparent 1px,transparent 3px),repeating-linear-gradient(90deg,rgba(0,0,180,.08) 0px,rgba(0,0,180,.08) 1px,transparent 1px,transparent 60px)!important;}
body.theme-norton #header{background:#0000aa;border-bottom:2px solid #5555ff;}
body.theme-norton #header *{color:#ffff55!important;}
body.theme-norton .section-header{background:#0000cc;border:1px solid #5555ff;}
body.theme-norton .section-title{color:#ffff55!important;background:transparent!important;}
body.theme-norton .section-count{color:rgba(255,255,85,.7)!important;}
body.theme-norton .stat-section{background:#0000cc;border:1px solid #5555ff;}
body.theme-norton .stat-section-hdr{background:#0000ff;color:#ffff55!important;}
body.theme-norton .card:hover{background:#00007a!important;color:#ffff55!important;}

/* ===== Atari ST / TOS (1985–1994): gray desktop, blue titlebars ===== */
body.theme-atarist{--font:'Courier New',monospace;--card-bg:#cccccc;--card-border-light:#fff;--card-border-dark:#666;--card-text:#000;--card-hover-bg:#000080;--card-hover-text:#fff;--section-title-bg:linear-gradient(to right,#000080,#0000cc);--section-title-text:#fff;--search-bg:#ddd;--search-border:#888;--search-text:#000;--card-radius:0px;--widget-text:#000;}
#wallpaper.theme-atarist{background-color:#cccccc!important;background-image:repeating-linear-gradient(0deg,rgba(0,0,0,.06) 0px,rgba(0,0,0,.06) 1px,transparent 1px,transparent 2px),repeating-linear-gradient(90deg,rgba(0,0,0,.04) 0px,rgba(0,0,0,.04) 1px,transparent 1px,transparent 2px)!important;background-size:2px 2px!important;}
body.theme-atarist #header{background:linear-gradient(to right,#000080,#0000cc);border-bottom:2px solid #000033;}
body.theme-atarist #header *{color:#fff!important;}
body.theme-atarist .section-header{background:#000080;}
body.theme-atarist .section-title,.theme-atarist .section-count{color:#fff!important;}
body.theme-atarist .stat-section-hdr{background:#000080;color:#fff!important;}
body.theme-atarist .card{border-top:2px solid #fff;border-left:2px solid #fff;border-bottom:2px solid #666;border-right:2px solid #666;}

/* ===== IRIX / SGI (1991–2000): teal/indigo workstation UI ===== */
body.theme-irix{--font:'Helvetica Neue','Arial',sans-serif;--card-bg:#2a3a4a;--card-border-light:#5a7a8a;--card-border-dark:#0a1a2a;--card-text:#c0d8e8;--card-hover-bg:#3a5a6a;--card-hover-text:#fff;--section-title-bg:linear-gradient(to right,#1a6a7a,#2a8a9a);--section-title-text:#e0f4f8;--search-bg:#1a2a3a;--search-border:#3a5a6a;--search-text:#c0d8e8;--card-radius:2px;--widget-text:#90b8c8;}
#wallpaper.theme-irix{background-color:#1a2a3a!important;background-image:linear-gradient(160deg,#1a3a4a 0%,#1a2a3a 50%,#0a1a28 100%)!important,repeating-linear-gradient(135deg,rgba(60,140,160,.12) 0px,rgba(60,140,160,.12) 1px,transparent 1px,transparent 16px)!important;}
body.theme-irix #header{background:linear-gradient(to right,#1a6a7a,#2a8a9a);border-bottom:2px solid #0a3a4a;}
body.theme-irix .section-header{background:linear-gradient(to right,#1a5a6a,#2a7a8a);}
body.theme-irix .stat-section-hdr{background:linear-gradient(to right,#1a6a7a,#2a8a9a);}

/* ===== HATSUNE MIKU (2007–): teal/cyan vocaloid theme ===== */
@keyframes mikuGlow{0%,100%{box-shadow:0 0 8px rgba(57,197,187,.25),0 0 20px rgba(57,197,187,.1)}50%{box-shadow:0 0 14px rgba(57,197,187,.45),0 0 30px rgba(57,197,187,.18)}}
body.theme-miku{--font:'Share Tech Mono','Courier New',monospace;--card-bg:rgba(0,18,26,.88);--card-border-light:#39c5bb;--card-border-dark:#005f5b;--card-text:#39c5bb;--card-hover-bg:#39c5bb;--card-hover-text:#000;--section-title-bg:linear-gradient(135deg,#003c38,#39c5bb);--section-title-text:#fff;--search-bg:rgba(0,28,34,.92);--search-border:#39c5bb;--search-text:#39c5bb;--card-radius:4px;--card-shadow:0 0 8px rgba(57,197,187,.25);--card-transition:all .15s ease;--widget-text:#39c5bb;--header-bg:rgba(0,14,20,.97);}
body.theme-miku #header{background:rgba(0,14,20,.97);border-top:2px solid #39c5bb;border-left:2px solid #39c5bb;border-right:2px solid #005f5b;border-bottom:2px solid #005f5b;box-shadow:0 0 18px rgba(57,197,187,.3);}
body.theme-miku #header *{color:#39c5bb!important;}
body.theme-miku .section-header{background:linear-gradient(to right,rgba(0,40,48,.97),rgba(57,197,187,.18));border-bottom:1px solid rgba(57,197,187,.5);}
body.theme-miku .section-title{color:#39c5bb!important;background:transparent!important;}
body.theme-miku .section-count{color:rgba(57,197,187,.55)!important;}
body.theme-miku .section-body{background:rgba(0,14,20,.82);border:1px solid rgba(57,197,187,.22);}
body.theme-miku .card{border-color:rgba(57,197,187,.35)!important;box-shadow:0 0 5px rgba(57,197,187,.12);}
body.theme-miku .card:hover{box-shadow:0 0 14px rgba(57,197,187,.55)!important;}
body.theme-miku .stat-section{background:rgba(0,14,20,.92);border:1px solid rgba(57,197,187,.45);animation:mikuGlow 4s ease-in-out infinite;}
body.theme-miku .stat-section-hdr{background:linear-gradient(to right,rgba(0,40,48,.98),rgba(57,197,187,.22));border-bottom:1px solid rgba(57,197,187,.45);color:#39c5bb!important;}
body.theme-miku ::-webkit-scrollbar-thumb{background:rgba(57,197,187,.4);}
body.theme-miku .weather-zip-btn,.theme-miku .weather-unit-btn{border-color:rgba(57,197,187,.5);color:#39c5bb;}
body.theme-miku .section-btn,.theme-miku .section-del-btn,.theme-miku .section-hide-btn{color:#39c5bb;}

/* ===== CRT overlay (body.crt-on) ===== */
#crt-overlay{display:none;position:fixed;inset:0;pointer-events:none;z-index:999990;
  background:repeating-linear-gradient(0deg,rgba(0,0,0,.09) 0px,rgba(0,0,0,.09) 1px,transparent 1px,transparent 2px);}
body.crt-on #crt-overlay{display:block;}
body.crt-on #crt-overlay::after{content:'';position:absolute;inset:0;
  background:radial-gradient(ellipse at center,transparent 55%,rgba(0,0,0,.45) 100%);pointer-events:none;}
body.crt-on #wallpaper{filter:brightness(.93) contrast(1.06);}
/* Sticky note widgets */
.sticky-note-widget .stat-section-hdr{filter:brightness(.82);}
.sticky-note-widget .stat-section-body{padding:0!important;}
.sticky-note-ta{width:100%!important;height:100%!important;background:transparent;border:none;outline:none;resize:none;padding:8px;font-family:inherit;font-size:13px;color:#1a1000;line-height:1.5;}
/* Countdown widgets */
.countdown-display{font-size:22px;font-weight:700;font-family:monospace;letter-spacing:2px;}

body.theme-pocketpc{--font:'Tahoma','Segoe UI',sans-serif;--card-bg:linear-gradient(180deg,rgba(40,80,160,.9),rgba(20,50,120,.95));--card-border-light:rgba(120,180,255,.5);--card-border-dark:rgba(0,20,80,.8);--card-text:#d0e4ff;--card-hover-bg:linear-gradient(180deg,rgba(80,140,255,.9),rgba(40,100,200,.95));--card-hover-text:#fff;--section-title-bg:linear-gradient(180deg,rgba(60,100,200,.95),rgba(30,60,150,.95));--section-title-text:#c0d8ff;--search-bg:rgba(20,50,120,.8);--search-border:rgba(100,160,255,.5);--search-text:#d0e4ff;--card-radius:4px;--widget-text:#a0c4ff;}
body.theme-pocketpc #header{background:linear-gradient(180deg,rgba(30,70,160,.95),rgba(15,40,110,.98));border:1px solid rgba(120,180,255,.3);border-radius:4px;}
body.theme-pocketpc #logo,body.theme-pocketpc #clock{color:#c0d8ff;}
body.theme-pocketpc .section-body{background:rgba(20,50,120,.6);border:1px solid rgba(100,150,255,.3);border-radius:4px;}

body.theme-startmenu{--font:'Arial',sans-serif;--card-bg:#c0c0c0;--card-border-light:#fff;--card-border-dark:#808080;--card-text:#000;--card-hover-bg:#000080;--card-hover-text:#fff;--section-title-bg:linear-gradient(to right,#000080,#1084d0);--section-title-text:#fff;--card-radius:0px;}

/* ===== MODERN macOS (Big Sur/Ventura/Sonoma) ===== */
body.theme-macos{
  --font:-apple-system,BlinkMacSystemFont,'SF Pro Text','Helvetica Neue',sans-serif;
  --card-bg:rgba(255,255,255,0.7);--card-border-light:rgba(255,255,255,0.9);--card-border-dark:rgba(0,0,0,0.08);
  --card-text:#1d1d1f;--card-hover-bg:rgba(0,122,255,0.1);--card-hover-text:#007aff;
  --section-title-bg:transparent;--section-title-text:rgba(60,60,67,0.5);
  --search-bg:rgba(118,118,128,0.12);--search-border:transparent;--search-text:#1d1d1f;
  --card-radius:10px;--card-shadow:0 1px 3px rgba(0,0,0,0.1);--card-transition:all 0.2s ease;
  --widget-text:rgba(60,60,67,0.7);--header-bg:rgba(255,255,255,0.8);
}
body.theme-macos #header{background:rgba(255,255,255,0.75);backdrop-filter:blur(20px);-webkit-backdrop-filter:blur(20px);border:none;border-bottom:1px solid rgba(0,0,0,0.1);border-radius:0;}
body.theme-macos .card{backdrop-filter:blur(10px);-webkit-backdrop-filter:blur(10px);border:1px solid rgba(0,0,0,0.07);}
body.theme-macos .card:hover{background:rgba(0,122,255,0.08);border-color:rgba(0,122,255,0.2);}
body.theme-macos .section-body{background:rgba(255,255,255,0.5);border:1px solid rgba(0,0,0,0.06);border-radius:12px;backdrop-filter:blur(10px);}
body.theme-macos .section-title{font-size:10px;color:rgba(60,60,67,0.5);text-transform:uppercase;letter-spacing:0.06em;background:transparent;padding:6px 8px 3px;}
body.theme-macos #search-input{background:rgba(118,118,128,0.12);border:none;border-radius:8px;padding:5px 10px;}
body.theme-macos #search-btn{background:#007aff;color:#fff;border:none;border-radius:7px;font-weight:600;}

/* ===== CLASSIC Mac OS 9 (Platinum UI) ===== */
body.theme-macos9{
  --font:'Chicago',Arial,sans-serif;
  --card-bg:linear-gradient(180deg,#e0e0e0 0%,#c8c8c8 50%,#d0d0d0 100%);
  --card-border-light:#fff;--card-border-dark:#808080;
  --card-text:#000;--card-hover-bg:#000080;--card-hover-text:#fff;
  --section-title-bg:linear-gradient(180deg,#000080 0%,#3a3ab0 100%);
  --section-title-text:#fff;
  --search-bg:#fff;--search-border:#808080;--search-text:#000;
  --card-radius:0px;--card-shadow:inset 1px 1px 0 rgba(255,255,255,0.8),inset -1px -1px 0 rgba(0,0,0,0.2);
  --widget-text:#000;
}
body.theme-macos9 #header{background:linear-gradient(180deg,#e0e0e0,#c8c8c8);border-top:2px solid #fff;border-left:2px solid #fff;border-right:2px solid #808080;border-bottom:2px solid #808080;border-radius:0;}
body.theme-macos9 .section-body{background:#d4d4d4;border-top:2px solid #fff;border-left:2px solid #fff;border-right:2px solid #808080;border-bottom:2px solid #808080;}
body.theme-macos9 .card{border-top:2px solid #fff;border-left:2px solid #fff;border-right:2px solid #808080;border-bottom:2px solid #808080;border-radius:0;}
body.theme-macos9 .card:hover{background:#000080;color:#fff;}
body.theme-macos9 .section-title{background:linear-gradient(180deg,#000080,#3a3ab0);color:#fff;padding:3px 8px;}

/* ===== Ubuntu GNOME ===== */
body.theme-ubuntu{
  --font:'Ubuntu','Segoe UI',sans-serif;
  --card-bg:rgba(44,4,38,0.85);--card-border-light:rgba(255,255,255,0.12);--card-border-dark:rgba(0,0,0,0.3);
  --card-text:#fff;--card-hover-bg:rgba(233,84,32,0.7);--card-hover-text:#fff;
  --section-title-bg:linear-gradient(to right,rgba(119,33,111,0.9),rgba(233,84,32,0.8));
  --section-title-text:#fff;
  --search-bg:rgba(255,255,255,0.1);--search-border:rgba(255,255,255,0.2);--search-text:#fff;
  --card-radius:6px;--card-shadow:0 2px 8px rgba(0,0,0,0.4);--card-transition:all 0.2s ease;
  --widget-text:rgba(255,255,255,0.7);
}
body.theme-ubuntu #header{background:rgba(44,4,38,0.9);border:none;border-bottom:1px solid rgba(255,255,255,0.1);}
body.theme-ubuntu #logo,body.theme-ubuntu #clock{color:#fff;}
body.theme-ubuntu .section-body{background:rgba(44,4,38,0.6);border:1px solid rgba(255,255,255,0.08);border-radius:6px;}
body.theme-ubuntu .card:hover{box-shadow:0 0 0 2px rgba(233,84,32,0.5);}

/* ===== CUSTOM THEME ===== */
body.theme-custom{--font:var(--ct-font,'Arial',sans-serif);--card-bg:var(--ct-card-bg,#1a3a6a);--card-border-light:var(--ct-border-light,#4a8adf);--card-border-dark:var(--ct-border-dark,#0a1a40);--card-text:var(--ct-card-text,#fff);--card-hover-bg:var(--ct-hover-bg,#2a5aaf);--card-hover-text:var(--ct-hover-text,#fff);--section-title-bg:linear-gradient(to right,var(--ct-sec-from,#0a3080),var(--ct-sec-to,#1060d0));--section-title-text:var(--ct-sec-text,#fff);--card-radius:var(--ct-radius,4px);}

/* ===== PROFESSIONAL THEME ===== */
@keyframes proSlide{0%{background-position:0 0}100%{background-position:60px 60px}}
body.theme-professional{
  --font:'Segoe UI','Inter','Helvetica Neue',sans-serif;
  --card-bg:rgba(20,30,50,0.88);--card-border-light:rgba(80,140,220,0.3);--card-border-dark:rgba(10,20,40,0.8);
  --card-text:#dde6f0;--card-hover-bg:rgba(40,100,200,0.6);--card-hover-text:#fff;
  --section-title-bg:linear-gradient(to right,#0f2040,#1a4080);--section-title-text:#a0c8ff;
  --search-bg:rgba(255,255,255,0.07);--search-border:rgba(80,140,220,0.4);--search-text:#dde6f0;
  --card-radius:6px;--card-shadow:0 2px 10px rgba(0,0,0,.5);--card-transition:all 0.2s ease;
  --widget-text:rgba(160,200,255,0.8);--header-bg:rgba(8,15,30,0.95);
  background:#060f1e;color:#dde6f0;
}
body.theme-professional #header{background:rgba(8,15,30,0.95);border-bottom:1px solid rgba(80,140,220,0.2);}
body.theme-professional .section-body{background:rgba(12,22,42,0.7);border:1px solid rgba(80,140,220,0.15);border-radius:6px;}
body.theme-professional .card:hover{box-shadow:0 0 0 1px rgba(80,140,220,0.5),0 4px 20px rgba(40,100,200,0.3);}
body.theme-professional #wallpaper{background:linear-gradient(135deg,rgba(30,80,160,0.06) 1px,transparent 1px),linear-gradient(45deg,rgba(30,80,160,0.06) 1px,transparent 1px);background-size:60px 60px;animation:proSlide 20s linear infinite;}

/* ===== GIRLY / ROSE THEME ===== */
@keyframes heartFloat{0%,100%{transform:translateY(0) rotate(-5deg)}50%{transform:translateY(-8px) rotate(5deg)}}
body.theme-cute{
  --font:'Segoe UI',Georgia,sans-serif;
  --card-bg:rgba(255,220,235,0.88);--card-border-light:rgba(255,180,210,0.9);--card-border-dark:rgba(220,100,150,0.4);
  --card-text:#5a1040;--card-hover-bg:rgba(240,90,150,0.85);--card-hover-text:#fff;
  --section-title-bg:linear-gradient(to right,#d94085,#f070a0);--section-title-text:#fff;
  --search-bg:rgba(255,255,255,0.7);--search-border:rgba(240,120,160,0.5);--search-text:#5a1040;
  --card-radius:18px;--card-shadow:0 3px 12px rgba(220,80,140,0.2);--card-transition:all 0.2s ease;
  --widget-text:#a0206a;--header-bg:rgba(255,210,230,0.95);
  background:linear-gradient(135deg,#ffe0ee,#ffd0e8,#ffeef5);color:#5a1040;
}
body.theme-cute #header{background:rgba(255,210,230,0.95);border-bottom:2px solid rgba(240,120,160,0.3);border-radius:0;}
body.theme-cute .section-body{background:rgba(255,240,248,0.7);border:1px solid rgba(240,150,180,0.3);border-radius:14px;}
body.theme-cute .section-title{border-radius:14px;}
body.theme-cute .card{border-radius:14px;}
body.theme-cute .card:hover{box-shadow:0 4px 20px rgba(220,80,140,0.4);}
body.theme-cute #wallpaper{background:radial-gradient(ellipse at 20% 30%,rgba(255,180,220,0.25) 0%,transparent 50%),radial-gradient(ellipse at 80% 70%,rgba(240,120,200,0.2) 0%,transparent 50%),radial-gradient(ellipse at 50% 50%,rgba(255,200,230,0.15) 0%,transparent 70%);}

/* ===== SPRING THEME ===== */
@keyframes petalDrift{0%{background-position:0 0,30px 30px}100%{background-position:60px 120px,90px 150px}}
body.theme-spring{
  --font:'Segoe UI','Noto Serif',Georgia,sans-serif;
  --card-bg:rgba(240,255,240,0.85);--card-border-light:rgba(150,220,130,0.8);--card-border-dark:rgba(80,160,60,0.3);
  --card-text:#1a4020;--card-hover-bg:rgba(100,190,80,0.7);--card-hover-text:#fff;
  --section-title-bg:linear-gradient(to right,#2d8a30,#5cbb40);--section-title-text:#fff;
  --search-bg:rgba(255,255,255,0.75);--search-border:rgba(100,190,80,0.5);--search-text:#1a4020;
  --card-radius:14px;--card-shadow:0 2px 10px rgba(60,160,40,0.15);--card-transition:all 0.2s ease;
  --widget-text:#2d6030;--header-bg:rgba(230,255,230,0.96);
  background:linear-gradient(135deg,#e8fce8,#f5ffe8,#fff5fb);color:#1a4020;
}
body.theme-spring #header{background:rgba(230,255,230,0.95);border-bottom:2px solid rgba(100,190,80,0.25);}
body.theme-spring .section-body{background:rgba(240,255,240,0.65);border:1px solid rgba(120,200,100,0.25);border-radius:12px;}
body.theme-spring .card{border-radius:12px;}
body.theme-spring .card:hover{box-shadow:0 4px 16px rgba(60,180,40,0.3);}
body.theme-spring #wallpaper{background:radial-gradient(circle at 20% 80%,rgba(255,200,220,0.3) 0%,transparent 40%),radial-gradient(circle at 80% 20%,rgba(200,240,180,0.3) 0%,transparent 40%),radial-gradient(circle at 50% 50%,rgba(255,240,200,0.2) 0%,transparent 50%);animation:petalDrift 25s ease-in-out infinite alternate;}

/* ===== SUMMER THEME ===== */
@keyframes sunPulse{0%,100%{box-shadow:0 0 60px 20px rgba(255,210,60,0.12)}50%{box-shadow:0 0 100px 40px rgba(255,160,30,0.18)}}
body.theme-summer{
  --font:'Segoe UI','Arial Rounded MT Bold',sans-serif;
  --card-bg:rgba(255,248,220,0.88);--card-border-light:rgba(255,200,60,0.8);--card-border-dark:rgba(220,140,30,0.4);
  --card-text:#4a2800;--card-hover-bg:rgba(255,130,30,0.8);--card-hover-text:#fff;
  --section-title-bg:linear-gradient(to right,#e07000,#f5a000);--section-title-text:#fff;
  --search-bg:rgba(255,255,255,0.75);--search-border:rgba(255,180,40,0.5);--search-text:#4a2800;
  --card-radius:16px;--card-shadow:0 3px 14px rgba(255,160,30,0.2);--card-transition:all 0.2s ease;
  --widget-text:#804000;--header-bg:rgba(255,240,200,0.96);
  background:linear-gradient(160deg,#fff8d0,#ffeaa0,#ffd080);color:#4a2800;
}
body.theme-summer #header{background:rgba(255,240,200,0.96);border-bottom:2px solid rgba(255,180,40,0.3);}
body.theme-summer .section-body{background:rgba(255,250,230,0.65);border:1px solid rgba(255,200,60,0.25);border-radius:14px;}
body.theme-summer .card{border-radius:14px;}
body.theme-summer .card:hover{box-shadow:0 4px 20px rgba(255,140,30,0.35);}
body.theme-summer #wallpaper{background:radial-gradient(circle at 85% 10%,rgba(255,220,60,0.28) 0%,transparent 45%),radial-gradient(circle at 15% 90%,rgba(30,160,220,0.15) 0%,transparent 40%);animation:sunPulse 4s ease-in-out infinite;}

/* ===== AUTUMN THEME ===== */
@keyframes leafSway{0%,100%{background-position:0 0}50%{background-position:10px 5px}}
body.theme-autumn{
  --font:'Georgia','Palatino',serif;
  --card-bg:rgba(60,30,10,0.82);--card-border-light:rgba(200,110,40,0.6);--card-border-dark:rgba(30,15,5,0.8);
  --card-text:#f0d0a0;--card-hover-bg:rgba(180,80,20,0.8);--card-hover-text:#fff0d8;
  --section-title-bg:linear-gradient(to right,#6a2800,#b85000);--section-title-text:#ffd8a0;
  --search-bg:rgba(80,40,10,0.7);--search-border:rgba(200,110,40,0.4);--search-text:#f0d0a0;
  --card-radius:8px;--card-shadow:0 3px 12px rgba(0,0,0,.5);--card-transition:all 0.2s ease;
  --widget-text:rgba(220,160,80,0.8);--header-bg:rgba(35,15,5,0.96);
  background:linear-gradient(160deg,#1a0800,#2d1000,#3d1800);color:#f0d0a0;
}
body.theme-autumn #header{background:rgba(35,15,5,0.96);border-bottom:1px solid rgba(180,80,20,0.3);}
body.theme-autumn .section-body{background:rgba(50,20,5,0.65);border:1px solid rgba(180,100,30,0.2);border-radius:8px;}
body.theme-autumn .card:hover{box-shadow:0 4px 18px rgba(180,80,20,0.4);}
body.theme-autumn #wallpaper{background:radial-gradient(ellipse at 10% 20%,rgba(200,80,20,0.18) 0%,transparent 50%),radial-gradient(ellipse at 90% 80%,rgba(160,60,10,0.15) 0%,transparent 50%),radial-gradient(ellipse at 50% 50%,rgba(100,40,5,0.1) 0%,transparent 60%);animation:leafSway 8s ease-in-out infinite alternate;}

/* ===== WINTER / HOLIDAY THEME ===== */
@keyframes snowfall{0%{background-position:0 0,20px 20px,10px 10px}100%{background-position:0 200px,20px 220px,10px 210px}}
body.theme-winter{
  --font:'Segoe UI','Arial',sans-serif;
  --card-bg:rgba(15,30,60,0.88);--card-border-light:rgba(160,200,255,0.4);--card-border-dark:rgba(5,15,40,0.8);
  --card-text:#c8e0ff;--card-hover-bg:rgba(40,90,200,0.7);--card-hover-text:#fff;
  --section-title-bg:linear-gradient(to right,#0a1a50,#1a3a90);--section-title-text:#a0c8ff;
  --search-bg:rgba(20,40,80,0.7);--search-border:rgba(100,160,255,0.3);--search-text:#c8e0ff;
  --card-radius:10px;--card-shadow:0 3px 14px rgba(0,20,80,.5);--card-transition:all 0.2s ease;
  --widget-text:rgba(160,200,255,0.7);--header-bg:rgba(8,18,45,0.97);
  background:linear-gradient(160deg,#040d20,#081828,#0c2040);color:#c8e0ff;
}
body.theme-winter #header{background:rgba(8,18,45,0.97);border-bottom:1px solid rgba(100,160,255,0.2);}
body.theme-winter .section-body{background:rgba(10,22,50,0.65);border:1px solid rgba(80,130,220,0.18);border-radius:10px;}
body.theme-winter .card:hover{box-shadow:0 4px 20px rgba(60,120,255,0.3);}
body.theme-winter #wallpaper{background:radial-gradient(circle at 80% 10%,rgba(200,130,30,0.12) 0%,transparent 35%),radial-gradient(circle at 20% 90%,rgba(100,160,255,0.1) 0%,transparent 40%);animation:snowfall 10s linear infinite;}

/* ===== MISSING THEME WALLPAPERS — every theme must show something ===== */
body.theme-c64 #wallpaper{background:#3333aa;background-image:repeating-linear-gradient(0deg,rgba(0,0,0,.13) 0,rgba(0,0,0,.13) 1px,transparent 1px,transparent 3px);animation:c64Glow 3s ease-in-out infinite alternate;}
@keyframes c64Glow{0%{filter:brightness(1);}100%{filter:brightness(1.08) saturate(1.1);}}
body.theme-os2 #wallpaper{background:linear-gradient(180deg,#005ea8 0%,#004e98 45%,#002e70 100%);background-image:radial-gradient(ellipse at 50% 20%,rgba(0,140,230,.28) 0%,transparent 55%),radial-gradient(ellipse at 20% 80%,rgba(0,60,140,.2) 0%,transparent 40%);}
body.theme-norton #wallpaper{background:#0000aa;background-image:repeating-linear-gradient(0deg,rgba(0,0,0,.18) 0,rgba(0,0,0,.18) 1px,transparent 1px,transparent 3px),repeating-linear-gradient(90deg,rgba(80,80,255,.04) 0,rgba(80,80,255,.04) 1px,transparent 1px,transparent 40px);}
body.theme-beos #wallpaper{background:linear-gradient(135deg,#d0b828 0%,#e0c838 40%,#b09818 100%);background-image:radial-gradient(ellipse at 30% 25%,rgba(255,245,120,.35) 0%,transparent 50%),radial-gradient(ellipse at 75% 75%,rgba(100,70,0,.2) 0%,transparent 45%);}
body.theme-thanksgiving #wallpaper{background:linear-gradient(160deg,rgba(160,55,5,.85) 0%,rgba(100,25,2,.95) 55%,rgba(70,15,0,.98) 100%);background-image:radial-gradient(ellipse at 25% 8%,rgba(240,130,15,.38) 0%,transparent 50%),radial-gradient(ellipse at 75% 85%,rgba(190,70,8,.22) 0%,transparent 48%);animation:thxGlow 6s ease-in-out infinite alternate;}
@keyframes thxGlow{0%{filter:brightness(1);}100%{filter:brightness(1.12);}}
body.theme-atarist #wallpaper{background:#c8c8c8;background-image:repeating-linear-gradient(0deg,rgba(0,0,0,.04) 0,rgba(0,0,0,.04) 1px,transparent 1px,transparent 2px),repeating-linear-gradient(90deg,rgba(255,255,255,.07) 0,rgba(255,255,255,.07) 1px,transparent 1px,transparent 2px);}
body.theme-irix #wallpaper{background:linear-gradient(135deg,#1a6b58 0%,#0d4a46 50%,#072830 100%);background-image:radial-gradient(ellipse at 50% 0%,rgba(40,180,150,.22) 0%,transparent 52%),radial-gradient(ellipse at 80% 90%,rgba(0,100,100,.15) 0%,transparent 40%);}
body.theme-amiga #wallpaper{background:#aaaaaa;background-image:repeating-linear-gradient(180deg,rgba(255,255,255,.09) 0,rgba(255,255,255,.09) 1px,transparent 1px,transparent 2px);}
body.theme-nextstep #wallpaper{background:#1c1c1c;background-image:radial-gradient(ellipse at 50% 50%,rgba(55,55,55,.45) 0%,transparent 70%),radial-gradient(ellipse at 20% 80%,rgba(35,35,35,.3) 0%,transparent 40%);}
body.theme-osxtiger #wallpaper{background:radial-gradient(ellipse at 35% 45%,rgba(90,45,170,.75) 0%,transparent 55%),radial-gradient(ellipse at 72% 60%,rgba(22,65,185,.55) 0%,transparent 50%),linear-gradient(135deg,#030810 0%,#070c24 50%,#030810 100%);animation:tigerPulse 8s ease-in-out infinite alternate;}
@keyframes tigerPulse{0%{filter:brightness(1);}100%{filter:brightness(1.18) hue-rotate(15deg);}}
body.theme-winphone #wallpaper{background:linear-gradient(180deg,#1a1a1a 0%,#282828 100%);background-image:radial-gradient(ellipse at 50% -8%,rgba(0,174,239,.14) 0%,transparent 52%);}
body.theme-startmenu #wallpaper{background:linear-gradient(180deg,#0a5ea0 0%,#157ac8 30%,#0e64b0 65%,#083c7a 100%);background-image:radial-gradient(ellipse at 18% 55%,rgba(255,255,255,.07) 0%,transparent 50%);}
body.theme-mac9 #wallpaper,body.theme-macos9 #wallpaper{background:linear-gradient(180deg,#bebebe 0%,#cacaca 50%,#ababab 100%);background-image:radial-gradient(ellipse at 50% 0%,rgba(255,255,255,.3) 0%,transparent 55%);}
body.theme-palmv #wallpaper{background:linear-gradient(180deg,#4a7c5a 0%,#3a6848 100%);}
body.theme-palmpilot #wallpaper{background:linear-gradient(180deg,#8aaa68 0%,#6a8846 100%);}
body.theme-solaris #wallpaper{background:radial-gradient(ellipse at 50% -5%,rgba(255,138,0,.42) 0%,transparent 55%),linear-gradient(180deg,#100a02 0%,#1e0e04 50%,#160802 100%);animation:solPulse 5s ease-in-out infinite alternate;}
@keyframes solPulse{0%{filter:brightness(1);}100%{filter:brightness(1.14);}}
body.theme-july4 #wallpaper{background:radial-gradient(ellipse at 50% 50%,rgba(10,10,40,.8) 0%,transparent 70%),linear-gradient(180deg,#01010c 0%,#050518 100%);}
body.theme-christmas #wallpaper{background:linear-gradient(180deg,#020408 0%,#0a1220 50%,#050810 100%);background-image:radial-gradient(ellipse at 50% 0%,rgba(20,70,40,.32) 0%,transparent 50%);}

/* ===== PER-SECTION FOLDER/LIST TOGGLE ===== */
/* Section in folder-icon view — cards become a grid of desktop-style icons */
.section[data-view="folder"] .section-body{
  display:grid!important;
  grid-template-columns:repeat(auto-fill,minmax(78px,1fr));
  gap:6px;padding:8px!important;background:transparent!important;
}
.section[data-view="folder"] .card{
  flex-direction:column;align-items:center;justify-content:flex-start;
  padding:10px 4px 8px;text-align:center;height:auto;min-width:0;
  background:transparent!important;border:none!important;box-shadow:none!important;
  gap:4px;
}
.section[data-view="folder"] .card:hover{
  background:rgba(255,255,255,.12)!important;border-radius:6px!important;
}
.section[data-view="folder"] .card-icon{font-size:32px;width:auto;line-height:1;}
.section[data-view="folder"] .card-label{font-size:10px;max-width:72px;white-space:normal;text-align:center;line-height:1.2;word-break:break-word;}
.section[data-view="folder"] .card-edit-btn{display:none;}
.section-view-btn{padding:2px 7px;font-size:11px;border:1px solid var(--card-border-light);background:transparent;color:var(--card-text);border-radius:4px;cursor:pointer;opacity:.65;font-family:var(--font);}
.section-view-btn:hover{opacity:1;background:var(--card-hover-bg);}
/* Win98/XP folder view — icons look like Explorer */
body.theme-win98 .section[data-view="folder"] .card:hover,
body.theme-winxp .section[data-view="folder"] .card:hover{background:rgba(0,0,128,.15)!important;border-radius:2px!important;}
body.theme-win98 .section[data-view="folder"] .card-label,
body.theme-winxp .section[data-view="folder"] .card-label{color:#000;}
/* macOS folder view */
body.theme-macos .section[data-view="folder"] .card:hover{background:rgba(0,122,255,.15)!important;border-radius:8px!important;}
/* iOS26 folder view */
body.theme-ios26 .section[data-view="folder"] .card:hover{background:rgba(130,100,255,.25)!important;border-radius:12px!important;}

/* (Global folder-view mode removed — per-section toggle used instead) */

/* ===== FOLDER PANEL POPUP ===== */
#folder-panel{display:none;position:fixed;inset:0;z-index:99990;background:rgba(0,0,0,.65);align-items:center;justify-content:center;backdrop-filter:blur(4px);}
#folder-panel.open{display:flex;}
#folder-panel-box{
  background:var(--card-bg);
  border-top:2px solid var(--card-border-light);
  border-left:2px solid var(--card-border-light);
  border-right:2px solid var(--card-border-dark);
  border-bottom:2px solid var(--card-border-dark);
  border-radius:var(--card-radius);
  padding:12px;min-width:280px;max-width:500px;width:90vw;max-height:75vh;overflow-y:auto;
  font-family:var(--font);
}
body.theme-macos #folder-panel-box,body.theme-ios26 #folder-panel-box{background:rgba(255,255,255,.9);backdrop-filter:blur(20px);border:1px solid rgba(0,0,0,.1);border-radius:14px;}
body.theme-jellybean #folder-panel-box,body.theme-ubuntu #folder-panel-box{background:#1a1a2e;border:1px solid #333;}
#folder-panel-title{font-size:13px;font-weight:bold;color:var(--card-text);padding:3px 5px 8px;border-bottom:1px solid var(--card-border-dark);margin-bottom:6px;display:flex;align-items:center;gap:6px;}
#folder-panel-title .fp-icon{font-size:20px;}
#folder-panel-cards{display:flex;flex-direction:column;gap:3px;}
#folder-panel-close{margin-top:10px;width:100%;padding:5px;background:var(--card-bg);border-top:2px solid var(--card-border-light);border-left:2px solid var(--card-border-light);border-right:2px solid var(--card-border-dark);border-bottom:2px solid var(--card-border-dark);color:var(--card-text);font-family:var(--font);font-size:12px;cursor:pointer;border-radius:var(--card-radius);}

/* ===== SECTION FOLDER ICON (always present, hidden in grid view) ===== */
.section-folder-icon{display:none;}

/* ===== DOCUMENT PANEL ===== */
#doc-panel{display:none;position:fixed;inset:0;z-index:99980;background:rgba(0,0,0,.7);align-items:center;justify-content:center;backdrop-filter:blur(4px);}
#doc-panel.open{display:flex;}
#doc-panel-box{background:var(--card-bg);border-top:2px solid var(--card-border-light);border-left:2px solid var(--card-border-light);border-right:2px solid var(--card-border-dark);border-bottom:2px solid var(--card-border-dark);border-radius:var(--card-radius);width:min(700px,96vw);max-height:82vh;display:flex;flex-direction:column;font-family:var(--font);}
body.theme-macos #doc-panel-box,body.theme-ios26 #doc-panel-box{background:rgba(240,240,245,.95);backdrop-filter:blur(20px);border:1px solid rgba(0,0,0,.12);border-radius:14px;color:#111;}
/* ===== DOC PANEL — OPEN ANIMATION + DRAGGABLE HEADER ===== */
@keyframes docWinOpen {
  from { opacity:0; transform:scale(0.87) translateY(14px); }
  to   { opacity:1; transform:scale(1)    translateY(0); }
}
#doc-panel.open #doc-panel-box{ animation:docWinOpen .2s cubic-bezier(.34,1.4,.64,1) both; }
#doc-panel-header{ cursor:move; user-select:none; }
/* win-buttons: traffic-lights style — overridden per theme below */
#doc-win-btns{ display:flex; gap:5px; align-items:center; flex-shrink:0; }
.doc-win-btn{ width:13px;height:13px;border-radius:50%;border:none;cursor:pointer;display:inline-block;flex-shrink:0; }
.doc-win-btn.close-btn{ background:#ff5f57; }
.doc-win-btn.min-btn{   background:#ffbd2e; }
.doc-win-btn.max-btn{   background:#28c840; }
/* ===== WIN98 EXPLORER STYLE DOC PANEL ===== */
body.theme-win98 #doc-panel-box,body.theme-winxp #doc-panel-box,body.theme-win2k #doc-panel-box{
  background:#fff;border:none;border-radius:0;
  box-shadow:2px 2px 8px rgba(0,0,0,.4),inset 1px 1px 0 #fff,inset -1px -1px 0 #808080;
  color:#000;
}
body.theme-win98 #doc-panel-header,body.theme-winxp #doc-panel-header,body.theme-win2k #doc-panel-header{
  background:linear-gradient(to right,#000080,#1084d0);color:#fff;
  padding:4px 8px;font-size:12px;border-bottom:2px solid #808080;
}
body.theme-win98 #doc-panel-close,body.theme-winxp #doc-panel-close,body.theme-win2k #doc-panel-close{
  background:#c0c0c0;border:1px solid;border-color:#fff #808080 #808080 #fff;
  color:#000;font-size:10px;padding:1px 5px;
}
body.theme-win98 #doc-sidebar,body.theme-winxp #doc-sidebar,body.theme-win2k #doc-sidebar{
  background:#e0e0e0;border-right:2px solid #808080;
}
body.theme-win98 .doc-folder-btn,body.theme-winxp .doc-folder-btn,body.theme-win2k .doc-folder-btn{color:#000;}
body.theme-win98 .doc-folder-btn.active,body.theme-winxp .doc-folder-btn.active,body.theme-win2k .doc-folder-btn.active{background:#000080;color:#fff;}
#doc-view-toggle{background:rgba(255,255,255,.12);border:1px solid rgba(255,255,255,.2);color:inherit;font-size:13px;padding:2px 7px;border-radius:4px;cursor:pointer;flex-shrink:0;}
#doc-view-toggle:hover{background:rgba(255,255,255,.22);}
body.theme-win98 #doc-toolbar,body.theme-winxp #doc-toolbar,body.theme-win2k #doc-toolbar{
  background:#d4d0c8;border-bottom:1px solid #808080;
}
body.theme-win98 #doc-upload-btn,body.theme-winxp #doc-upload-btn,body.theme-win2k #doc-upload-btn{
  background:#d4d0c8;border:1px solid;border-color:#fff #808080 #808080 #fff;
  color:#000;border-radius:0;
}
body.theme-win98 #doc-files,body.theme-winxp #doc-files,body.theme-win2k #doc-files{background:#fff;}
body.theme-win98 .doc-file-row:hover,body.theme-winxp .doc-file-row:hover,body.theme-win2k .doc-file-row:hover{background:rgba(0,0,128,.08);}
body.theme-win98 .doc-file-dl,body.theme-winxp .doc-file-dl,body.theme-win2k .doc-file-dl{
  background:#d4d0c8;border:1px solid;border-color:#fff #808080 #808080 #fff;color:#000;border-radius:0;
}
/* Win98 icon-grid view for doc files */
body.theme-win98 #doc-files.icon-grid,body.theme-winxp #doc-files.icon-grid,body.theme-win2k #doc-files.icon-grid{
  display:grid;grid-template-columns:repeat(auto-fill,minmax(80px,1fr));gap:6px;padding:10px;align-content:start;
}
body.theme-win98 #doc-files.icon-grid .doc-file-row,
body.theme-winxp #doc-files.icon-grid .doc-file-row,
body.theme-win2k #doc-files.icon-grid .doc-file-row{
  flex-direction:column;align-items:center;justify-content:flex-start;
  padding:8px 4px;height:auto;text-align:center;gap:4px;
}
body.theme-win98 #doc-files.icon-grid .doc-file-icon,
body.theme-winxp #doc-files.icon-grid .doc-file-icon,
body.theme-win2k #doc-files.icon-grid .doc-file-icon{font-size:36px;width:auto;}
body.theme-win98 #doc-files.icon-grid .doc-file-info,
body.theme-winxp #doc-files.icon-grid .doc-file-info,
body.theme-win2k #doc-files.icon-grid .doc-file-info{min-width:0;width:100%;}
body.theme-win98 #doc-files.icon-grid .doc-file-name,
body.theme-winxp #doc-files.icon-grid .doc-file-name,
body.theme-win2k #doc-files.icon-grid .doc-file-name{font-size:10px;white-space:normal;text-align:center;word-break:break-word;}
body.theme-win98 #doc-files.icon-grid .doc-file-size{ display:none; }
/* ===== MAC FINDER STYLE DOC PANEL ===== */
body.theme-macos #doc-panel-header,body.theme-macosx #doc-panel-header,body.theme-ios26 #doc-panel-header,body.theme-osxtiger #doc-panel-header{
  background:linear-gradient(180deg,#eeeeee,#d0d0d0);color:#000;
  border-bottom:1px solid #b0b0b0;padding:6px 10px;
}
body.theme-macos #doc-panel-box,body.theme-macosx #doc-panel-box,body.theme-osxtiger #doc-panel-box{
  background:rgba(240,240,245,.96);border:1px solid rgba(0,0,0,.14);border-radius:12px;color:#111;
}
body.theme-macos #doc-win-btns,body.theme-macosx #doc-win-btns,body.theme-ios26 #doc-win-btns,body.theme-osxtiger #doc-win-btns{ order:-1; }
body.theme-macos #doc-panel-close,body.theme-macosx #doc-panel-close,body.theme-ios26 #doc-panel-close,body.theme-osxtiger #doc-panel-close{ display:none; }
/* Mac OS 9 — pinstripe */
body.theme-macos9 #doc-panel-box,body.theme-mac9 #doc-panel-box{
  background:#e8e8e8;border:2px solid #888;border-radius:6px;color:#000;
}
body.theme-macos9 #doc-panel-header,body.theme-mac9 #doc-panel-header{
  background:repeating-linear-gradient(90deg,#c8c8c8 0px,#b8b8b8 1px,#d4d4d4 2px);
  color:#000;border-bottom:1px solid #888;padding:4px 8px;
}
body.theme-macos9 #doc-win-btns,body.theme-mac9 #doc-win-btns{ order:-1; }
body.theme-macos9 #doc-panel-close,body.theme-mac9 #doc-panel-close{ display:none; }
/* ===== AMIGA WORKBENCH DOC PANEL ===== */
body.theme-amiga #doc-panel-box{
  background:#aaaaaa;border-top:2px solid #fff;border-left:2px solid #fff;
  border-bottom:2px solid #555;border-right:2px solid #555;border-radius:0;color:#000;
}
body.theme-amiga #doc-panel-header{
  background:linear-gradient(to right,#ff8800,#ffaa00);color:#000;font-family:'Courier New',monospace;
  border-bottom:2px solid #555;padding:3px 8px;
}
body.theme-amiga #doc-win-btns{ margin-left:auto; order:2; }
body.theme-amiga .doc-win-btn.close-btn{ background:#aaaaaa;border:1px solid;border-color:#fff #555 #555 #fff;border-radius:0;width:18px;height:14px; }
body.theme-amiga .doc-win-btn.min-btn,.theme-amiga .doc-win-btn.max-btn{ display:none; }
body.theme-amiga #doc-panel-close{ display:none; }
/* ===== NeXTSTEP DOC PANEL ===== */
body.theme-nextstep #doc-panel-box{
  background:#2a2a2a;border:2px solid #555;border-radius:3px;color:#ddd;
}
body.theme-nextstep #doc-panel-header{
  background:linear-gradient(180deg,#3c3c3c,#222);color:#ccc;
  border-bottom:1px solid #111;padding:5px 10px;
}
body.theme-nextstep #doc-win-btns{ order:-1; }
body.theme-nextstep .doc-win-btn.close-btn{ background:#888;border-radius:2px; }
body.theme-nextstep .doc-win-btn.min-btn,.theme-nextstep .doc-win-btn.max-btn{ display:none; }
body.theme-nextstep #doc-panel-close{ display:none; }
/* ===== BeOS DOC PANEL ===== */
body.theme-beos #doc-panel-box{
  background:#d4c890;border:2px solid #8a7840;border-radius:4px;color:#1a1200;
}
body.theme-beos #doc-panel-header{
  background:linear-gradient(to right,#d8b400,#f0c808);color:#1a0c00;
  border-bottom:1px solid #8a7000;padding:4px 8px;
}
body.theme-beos .doc-win-btn.close-btn{ background:#e83030; }
body.theme-beos .doc-win-btn.min-btn  { background:#c8c800; }
body.theme-beos .doc-win-btn.max-btn  { background:#20a020; }
body.theme-beos #doc-panel-close{ display:none; }
/* ===== DOS / NORTON COMMANDER DOC PANEL ===== */
body.theme-norton #doc-panel-box{
  background:#0000aa;border:2px solid #5555ff;border-radius:0;color:#fff;font-family:'Courier New',monospace;
}
body.theme-norton #doc-panel-header{
  background:#0000ff;color:#ffff55;border-bottom:2px solid #5555ff;padding:3px 8px;
}
body.theme-norton #doc-win-btns{ display:none; }
body.theme-norton #doc-panel-close{ background:#0000aa;border:1px solid #5555ff;color:#ffff55;border-radius:0;font-family:monospace; }
/* ===== ATARI ST DOC PANEL ===== */
body.theme-atarist #doc-panel-box{
  background:#cccccc;border-top:2px solid #fff;border-left:2px solid #fff;
  border-bottom:2px solid #666;border-right:2px solid #666;border-radius:0;color:#000;
}
body.theme-atarist #doc-panel-header{
  background:#000080;color:#fff;border-bottom:2px solid #000033;padding:3px 8px;
}
body.theme-atarist #doc-win-btns{ margin-left:auto; order:2; }
body.theme-atarist .doc-win-btn.close-btn{ background:#cccccc;border:1px solid;border-color:#fff #666 #666 #fff;border-radius:0;width:16px;height:12px; }
body.theme-atarist .doc-win-btn.min-btn,.theme-atarist .doc-win-btn.max-btn{ display:none; }
body.theme-atarist #doc-panel-close{ display:none; }
/* ===== IRIX / SGI DOC PANEL ===== */
body.theme-irix #doc-panel-box{
  background:#2a3a4a;border:2px solid #3a5a6a;border-radius:3px;color:#c0d8e8;
}
body.theme-irix #doc-panel-header{
  background:linear-gradient(to right,#1a6a7a,#2a8a9a);color:#e0f4f8;
  border-bottom:1px solid #0a3a4a;padding:5px 10px;
}
body.theme-irix #doc-win-btns{ order:-1; }
body.theme-irix #doc-panel-close{ display:none; }
/* ===== C64 DOC PANEL ===== */
body.theme-c64 #doc-panel-box{
  background:#5555d0;border:2px solid #8888ff;border-radius:0;color:#fff;font-family:'Courier New',monospace;
}
body.theme-c64 #doc-panel-header{
  background:#3333aa;color:#aaaaff;border-bottom:1px solid #8888ff;padding:3px 8px;
}
body.theme-c64 #doc-win-btns{ display:none; }
body.theme-c64 #doc-panel-close{ background:#3333aa;border:1px solid #8888ff;color:#aaaaff;border-radius:0; }
/* ===== OS/2 DOC PANEL ===== */
body.theme-os2 #doc-panel-box{
  background:#d4d0c8;border-top:2px solid #fff;border-left:2px solid #fff;
  border-bottom:2px solid #808080;border-right:2px solid #808080;border-radius:0;color:#000;
}
body.theme-os2 #doc-panel-header{
  background:linear-gradient(to right,#00007a,#0000d0);color:#fff;
  border-bottom:2px solid #808080;padding:4px 8px;
}
body.theme-os2 #doc-win-btns{ margin-left:auto; order:2; }
body.theme-os2 .doc-win-btn.close-btn{ background:#d4d0c8;border:1px solid;border-color:#fff #808080 #808080 #fff;border-radius:0; }
body.theme-os2 .doc-win-btn.min-btn,.theme-os2 .doc-win-btn.max-btn{ display:none; }
body.theme-os2 #doc-panel-close{ display:none; }
/* ── Palm V / Vx wallpaper variants ── */
#wallpaper.theme-palmv.wall-palmv-amber{background-color:#160900!important;background-image:repeating-linear-gradient(0deg,rgba(220,140,0,.07) 0px,rgba(220,140,0,.07) 1px,transparent 1px,transparent 4px)!important;animation:palmVScan 4s linear infinite!important;}
#wallpaper.theme-palmv.wall-palmv-blue{background-color:#000d1a!important;background-image:repeating-linear-gradient(0deg,rgba(30,110,255,.07) 0px,rgba(30,110,255,.07) 1px,transparent 1px,transparent 4px)!important;animation:palmVScan 5s linear infinite!important;}
#wallpaper.theme-palmv.wall-palmv-dark{background-color:#040404!important;background-image:repeating-linear-gradient(0deg,rgba(0,160,60,.025) 0px,rgba(0,160,60,.025) 1px,transparent 1px,transparent 4px)!important;animation:palmVScan 10s linear infinite!important;}
#wallpaper.theme-palmv.wall-palmv-gray{background-color:#181818!important;background-image:repeating-linear-gradient(0deg,rgba(200,200,200,.05) 0px,rgba(200,200,200,.05) 1px,transparent 1px,transparent 4px)!important;animation:palmVScan 7s linear infinite!important;}
#wallpaper.theme-palmv.wall-palmv-red{background-color:#150000!important;background-image:repeating-linear-gradient(0deg,rgba(255,30,30,.07) 0px,rgba(255,30,30,.07) 1px,transparent 1px,transparent 4px)!important;animation:palmVScan 5s linear infinite!important;}
/* ── Palm Pilot wallpaper variants ── */
@keyframes pilotScan{0%{background-position:0 0}100%{background-position:0 64px}}
#wallpaper.theme-palmpilot{background-color:#8a9e7a!important;background-image:repeating-linear-gradient(0deg,rgba(0,0,0,.06) 0px,rgba(0,0,0,.06) 1px,transparent 1px,transparent 4px)!important;animation:pilotScan 8s linear infinite!important;}
#wallpaper.theme-palmpilot.wall-pilot-green{background-color:#041208!important;background-image:repeating-linear-gradient(0deg,rgba(0,210,60,.07) 0px,rgba(0,210,60,.07) 1px,transparent 1px,transparent 4px)!important;animation:pilotScan 6s linear infinite!important;}
#wallpaper.theme-palmpilot.wall-pilot-dark{background-color:#060606!important;background-image:repeating-linear-gradient(0deg,rgba(100,160,100,.03) 0px,rgba(100,160,100,.03) 1px,transparent 1px,transparent 4px)!important;animation:pilotScan 10s linear infinite!important;}
#wallpaper.theme-palmpilot.wall-pilot-blue{background-color:#050c1a!important;background-image:repeating-linear-gradient(0deg,rgba(40,120,230,.06) 0px,rgba(40,120,230,.06) 1px,transparent 1px,transparent 4px)!important;animation:pilotScan 6s linear infinite!important;}
#wallpaper.theme-palmpilot.wall-pilot-warm{background-color:#1a1308!important;background-image:repeating-linear-gradient(0deg,rgba(210,185,100,.05) 0px,rgba(210,185,100,.05) 1px,transparent 1px,transparent 4px)!important;animation:pilotScan 7s linear infinite!important;}
#wallpaper.theme-palmpilot.wall-pilot-mono{background-color:#0e0e0e!important;background-image:repeating-linear-gradient(0deg,rgba(220,220,220,.04) 0px,rgba(220,220,220,.04) 1px,transparent 1px,transparent 4px)!important;animation:pilotScan 9s linear infinite!important;}
/* ===== PALM OS / TREO / WEBOS DOC PANEL ===== */
body.theme-palmos #doc-panel-box,body.theme-palmtreo #doc-panel-box,
body.theme-palmv #doc-panel-box,body.theme-palmpilot #doc-panel-box{
  background:#d4d0b0;border:2px solid #8a8a60;border-radius:0;color:#000;
}
body.theme-palmos #doc-panel-header,body.theme-palmtreo #doc-panel-header,
body.theme-palmv #doc-panel-header,body.theme-palmpilot #doc-panel-header{
  background:linear-gradient(180deg,#606050,#3c3c2c);color:#c4c49a;
  padding:4px 8px;border-bottom:1px solid #8a8a60;
}
body.theme-webos #doc-panel-box{
  background:#1a1a2a;border:1px solid #334;border-radius:12px;color:#dde;
}
body.theme-webos #doc-panel-header{
  background:linear-gradient(180deg,#223,#112);color:#aac;border-bottom:1px solid #334;border-radius:12px 12px 0 0;
}
/* ===== UBUNTU DOC PANEL ===== */
body.theme-ubuntu #doc-panel-box{
  background:#2c001e;border:1px solid #77216f;border-radius:8px;color:#fff;
}
body.theme-ubuntu #doc-panel-header{
  background:linear-gradient(180deg,#5c3566,#2c001e);color:#fff;
  border-bottom:1px solid #77216f;border-radius:8px 8px 0 0;
}
body.theme-ubuntu #doc-win-btns{ order:-1; }
body.theme-ubuntu .doc-win-btn.close-btn{ background:#cc3333; }
body.theme-ubuntu #doc-panel-close{ display:none; }
/* ===== JELLY BEAN DOC PANEL ===== */
body.theme-jellybean #doc-panel-box{
  background:#1c2f3a;border:1px solid #2a5560;border-radius:4px;color:#aadde8;
}
body.theme-jellybean #doc-panel-header{
  background:linear-gradient(180deg,#1a5a6a,#0c2a36);color:#aadde8;border-bottom:1px solid #2a5560;
}
body.theme-macos #doc-files.icon-grid{
  display:grid;grid-template-columns:repeat(auto-fill,minmax(90px,1fr));gap:8px;padding:12px;align-content:start;
}
body.theme-macos #doc-files.icon-grid .doc-file-row{
  flex-direction:column;align-items:center;padding:10px 6px;text-align:center;gap:5px;
}
body.theme-macos #doc-files.icon-grid .doc-file-icon{font-size:40px;width:auto;}
body.theme-macos #doc-files.icon-grid .doc-file-name{font-size:11px;white-space:normal;text-align:center;word-break:break-word;}
body.theme-macos #doc-files.icon-grid .doc-file-size{font-size:10px;color:#888;}
/* Doc panel toolbar view toggle */
#doc-view-toggle{padding:4px 8px;font-size:12px;border:1px solid #c0c0c0;background:transparent;cursor:pointer;border-radius:3px;margin-left:4px;}
body.theme-win98 #doc-view-toggle{border-color:#808080;background:#d4d0c8;border-style:solid;border-width:1px;border-color:#fff #808080 #808080 #fff;}
#doc-panel-header{display:flex;align-items:center;gap:8px;padding:8px 12px;border-bottom:1px solid var(--card-border-dark);font-size:13px;font-weight:700;flex-shrink:0;}
#doc-panel-close{margin-left:auto;background:none;border:none;color:inherit;cursor:pointer;font-size:16px;padding:2px 6px;}
#doc-panel-body{display:flex;flex:1;overflow:hidden;}
#doc-sidebar{width:148px;flex-shrink:0;border-right:1px solid var(--card-border-dark);padding:6px;overflow-y:auto;}
.doc-type-btn{display:flex;align-items:center;gap:6px;width:100%;padding:6px 8px;background:none;border:none;border-radius:5px;color:var(--card-text);font-family:var(--font);font-size:12px;cursor:pointer;text-align:left;}
.doc-type-btn:hover{background:var(--card-hover-bg);color:var(--card-hover-text);}
.doc-type-btn.active{background:var(--card-hover-bg);color:var(--card-hover-text);font-weight:700;}
.doc-type-btn .dtcount{margin-left:auto;font-size:10px;opacity:.6;background:rgba(255,255,255,.1);padding:1px 5px;border-radius:8px;}
.doc-folder-btn{display:flex;align-items:center;gap:6px;width:100%;padding:5px 8px;background:none;border:none;border-radius:5px;color:var(--card-text);font-family:var(--font);font-size:11px;cursor:pointer;text-align:left;}
.doc-folder-btn:hover{background:var(--card-hover-bg);color:var(--card-hover-text);}
.doc-folder-btn.active{background:var(--card-hover-bg);color:var(--card-hover-text);font-weight:700;}
.doc-folder-btn .dfcount{margin-left:auto;font-size:10px;opacity:.6;}
#doc-main{flex:1;display:flex;flex-direction:column;overflow:hidden;}
#doc-toolbar{padding:6px 10px;border-bottom:1px solid var(--card-border-dark);display:flex;gap:6px;align-items:center;flex-shrink:0;flex-wrap:wrap;}
#doc-file-input{display:none;}
#doc-upload-btn{padding:4px 10px;background:var(--card-hover-bg);border:1px solid var(--card-border-light);border-radius:4px;color:var(--card-hover-text);cursor:pointer;font-size:12px;font-family:var(--font);}
#doc-folder-name{font-size:15px;font-weight:700;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;max-width:260px;}
#doc-search{flex:1;min-width:60px;max-width:160px;padding:4px 8px;background:rgba(255,255,255,.08);border:1px solid var(--card-border-dark);border-radius:4px;color:var(--card-text);font-size:12px;font-family:var(--font);outline:none;}
#doc-search:focus{border-color:var(--card-hover-bg);background:rgba(255,255,255,.12);}
#doc-search::placeholder{opacity:.5;}
#doc-files{flex:1;overflow-y:auto;padding:6px;}
#doc-no-results{padding:24px;text-align:center;opacity:.4;font-size:13px;}
.doc-file-row{display:flex;align-items:center;gap:8px;padding:6px 8px;border-radius:5px;cursor:default;}
.doc-file-row:hover{background:rgba(255,255,255,.06);}
.doc-file-icon{font-size:20px;width:24px;text-align:center;flex-shrink:0;}
.doc-file-info{flex:1;min-width:0;}
.doc-file-name{font-size:13px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;}
.doc-file-size{font-size:10px;opacity:.5;margin-top:1px;}
.doc-file-dl{padding:3px 10px;background:var(--card-bg);border:1px solid var(--card-border-light);color:var(--card-text);font-size:11px;border-radius:4px;cursor:pointer;font-family:var(--font);text-decoration:none;}
.doc-file-del{padding:3px 8px;background:rgba(200,40,40,.2);border:1px solid rgba(200,40,40,.4);color:#f87171;font-size:11px;border-radius:4px;cursor:pointer;font-family:var(--font);}
#doc-drop-zone{border:2px dashed rgba(255,255,255,.2);border-radius:8px;padding:24px;text-align:center;font-size:13px;opacity:.5;margin:10px;cursor:pointer;}
#doc-drop-zone.dragging{opacity:1;border-color:var(--card-hover-bg);}
#doc-new-folder-row{display:flex;gap:5px;padding:4px 6px;}
#doc-new-folder-row input{flex:1;padding:4px 8px;background:rgba(255,255,255,.07);border:1px solid rgba(255,255,255,.14);border-radius:5px;color:inherit;font-size:12px;font-family:var(--font);outline:none;}
#doc-new-folder-row button{padding:4px 10px;background:var(--card-hover-bg);border:none;border-radius:5px;color:var(--card-hover-text);font-size:11px;cursor:pointer;font-family:var(--font);}
.doc-folder-row{display:flex;align-items:center;gap:2px;padding:1px 0;position:relative;flex-wrap:wrap;}
.doc-folder-row.active .doc-folder-btn{background:var(--card-hover-bg);color:var(--card-hover-text);font-weight:700;}
.doc-folder-row .doc-folder-btn{flex:1;}
.doc-folder-del{opacity:0;background:none;border:none;cursor:pointer;padding:2px 5px;font-size:12px;color:rgba(255,100,100,.7);border-radius:4px;transition:opacity .15s;flex-shrink:0;}
.doc-folder-pin-btn{opacity:.45;background:none;border:none;cursor:pointer;padding:2px 4px;font-size:11px;border-radius:4px;transition:opacity .15s;flex-shrink:0;color:inherit;}
.doc-folder-pin-btn:hover{opacity:1;background:rgba(255,255,255,.12);}
.doc-folder-row:hover .doc-folder-del{opacity:.7;}
.doc-folder-pin-picker{display:none;width:100%;background:var(--card-bg);border:1px solid var(--card-border-light);border-radius:7px;padding:6px;margin-top:2px;margin-bottom:4px;box-shadow:0 4px 16px rgba(0,0,0,.3);}
.doc-folder-pin-picker.open{display:block;}
.doc-pin-opt{display:flex;align-items:center;gap:6px;width:100%;padding:5px 8px;background:none;border:none;border-radius:5px;color:var(--card-text);font-family:var(--font);font-size:11px;cursor:pointer;text-align:left;}
.doc-pin-opt:hover{background:var(--card-hover-bg);color:var(--card-hover-text);}
.doc-pin-opt.active{background:var(--card-hover-bg);color:var(--card-hover-text);font-weight:700;}
.doc-pin-opt .pin-check{margin-left:auto;font-size:10px;}
.doc-folder-row:hover .doc-folder-del{opacity:1;}
.doc-folder-del:hover{background:rgba(255,80,80,.15);}

::-webkit-scrollbar{width:12px;}
::-webkit-scrollbar-track{background:#c0c0c0;}
::-webkit-scrollbar-thumb{background:#a0a0a0;border:1px solid #808080;}
body.theme-macos ::-webkit-scrollbar{width:8px;}
body.theme-macos ::-webkit-scrollbar-thumb{background:rgba(0,0,0,0.2);border-radius:4px;}
body.theme-ubuntu ::-webkit-scrollbar-thumb{background:rgba(233,84,32,0.5);}

/* ===== CLOCK WIDGET ===== */
.clock-widget{width:190px;}
.clock-digital-wrap{text-align:center;padding:6px 0 2px;}
.clock-digital-time{font-size:30px;font-weight:bold;font-family:'Courier New',monospace;color:var(--widget-text);letter-spacing:3px;line-height:1;}
.clock-digital-secs{font-size:18px;opacity:.55;}
.clock-date-line{text-align:center;font-size:11px;color:var(--widget-text);opacity:.65;margin-top:3px;}
.clock-mode-btn{display:block;width:100%;margin-top:6px;background:rgba(128,128,128,.12);border:1px solid rgba(128,128,128,.25);color:var(--widget-text);font-size:10px;padding:3px 0;border-radius:3px;cursor:pointer;font-family:var(--font);}
.clock-mode-btn:hover{background:rgba(128,128,128,.25);}
/* Analog face */
.analog-face{width:120px;height:120px;border-radius:50%;border:2px solid var(--card-border-dark);background:var(--card-bg);position:relative;margin:6px auto 2px;}
.analog-face::before{content:'';position:absolute;inset:6px;border-radius:50%;border:1px solid rgba(128,128,128,.2);}
.clock-hand{position:absolute;bottom:50%;left:50%;transform-origin:bottom center;border-radius:3px 3px 0 0;}
.hand-hour{width:5px;margin-left:-2.5px;height:36px;background:var(--card-text);}
.hand-minute{width:3px;margin-left:-1.5px;height:50px;background:var(--card-text);opacity:.8;}
.hand-second{width:2px;margin-left:-1px;height:54px;background:#e44;opacity:.9;}
.analog-center{position:absolute;top:50%;left:50%;width:10px;height:10px;background:var(--card-text);border-radius:50%;transform:translate(-50%,-50%);z-index:2;}
.analog-second-center{position:absolute;top:50%;left:50%;width:6px;height:6px;background:#e44;border-radius:50%;transform:translate(-50%,-50%);z-index:3;}

/* ===== WIDGET SIZE % INPUT ===== */
.widget-size-ctrl{display:none;align-items:center;gap:4px;opacity:.85;margin-left:auto;flex-shrink:0;user-select:none;}
body.edit-mode .widget-size-ctrl{display:flex;}
.widget-pct-input{width:46px;padding:2px 4px;background:rgba(255,255,255,.12);border:1px solid rgba(255,255,255,.28);border-radius:4px;color:inherit;font-size:10px;font-family:monospace;text-align:center;outline:none;}
.widget-pct-input:focus{border-color:rgba(74,158,255,.7);background:rgba(74,158,255,.1);}
.wsc-label{font-size:9px;opacity:.6;color:inherit;white-space:nowrap;}
/* ===== COLUMN WIDTH SLIDERS ===== */
.section-width-ctrl{display:none;align-items:center;gap:3px;font-size:9px;opacity:.85;user-select:none;margin-left:4px;flex-shrink:0;}
body.edit-mode .section-width-ctrl{display:flex;}
.section-width-num{width:40px;padding:2px 3px;background:rgba(255,255,255,.12);border:1px solid rgba(255,255,255,.28);border-radius:4px;color:inherit;font-size:9px;font-family:monospace;text-align:center;outline:none;}
.section-width-num:focus{border-color:rgba(74,158,255,.7);background:rgba(74,158,255,.1);}
.section-w-label{font-size:9px;opacity:.7;white-space:nowrap;}

/* ===== CUSTOM HTML WIDGET ===== */
.hw-widget{min-width:200px;overflow:visible;z-index:12;}
.hw-widget .stat-section-body{padding:8px;overflow:visible;}
.hw-widget-content{overflow:visible;}
.hw-widget-content iframe{max-width:100%;border:none;}

/* ===== WEATHER WIDGET ===== */
.weather-widget{width:230px;}
.weather-current{display:flex;align-items:center;gap:10px;padding:4px 0 2px;}
.weather-icon-big{font-size:44px;line-height:1;animation:wFloat 3s ease-in-out infinite;}
@keyframes wFloat{0%,100%{transform:translateY(0)}50%{transform:translateY(-5px)}}
.weather-temp-big{font-size:30px;font-weight:bold;color:var(--widget-text);font-family:'Courier New',monospace;}
.weather-unit{font-size:14px;opacity:.6;}
.weather-desc-line{font-size:11px;color:var(--widget-text);opacity:.75;margin-top:2px;}
.weather-meta-line{font-size:10px;color:var(--widget-text);opacity:.55;margin-top:2px;}
.weather-forecast{display:flex;gap:4px;margin-top:8px;padding-top:6px;border-top:1px solid rgba(128,128,128,.18);}
.wf-day{flex:1;text-align:center;font-size:10px;color:var(--widget-text);}
.wf-icon{font-size:20px;display:block;margin-bottom:2px;}
.wf-name{opacity:.6;font-size:9px;display:block;}
.wf-temps{opacity:.8;display:block;}
.weather-zip-row{display:flex;gap:4px;margin-top:6px;}
.weather-zip-input{flex:1;background:var(--search-bg);border:1px solid rgba(128,128,128,.3);color:var(--search-text);font-size:11px;padding:3px 6px;border-radius:3px;outline:none;font-family:var(--font);}
.weather-zip-btn{background:var(--card-bg);border:1px solid var(--card-border-dark);border-right:2px solid var(--card-border-dark);border-bottom:2px solid var(--card-border-dark);color:var(--card-text);font-size:10px;padding:3px 7px;border-radius:3px;cursor:pointer;font-family:var(--font);}
.weather-unit-row{display:flex;gap:4px;margin-top:4px;font-size:10px;color:var(--widget-text);opacity:.7;align-items:center;}
.weather-unit-btn{background:none;border:1px solid rgba(128,128,128,.3);color:var(--widget-text);font-size:10px;padding:1px 5px;border-radius:3px;cursor:pointer;}
.weather-unit-btn.active{background:rgba(128,128,128,.25);font-weight:bold;}
.weather-err{text-align:center;font-size:11px;color:var(--widget-text);opacity:.6;padding:6px;}
/* ===== EXTRA WEATHER CITY WIDGET ===== */
.wx-city-widget{min-width:220px;}
/* ===== TIMEZONE WIDGET ===== */
.tz-widget{min-width:180px;}
.tz-digital-wrap{text-align:center;padding:6px 0 2px;}
.tz-digital-time{font-size:28px;font-weight:bold;font-family:'Courier New',monospace;color:var(--widget-text);letter-spacing:2px;line-height:1;}
.tz-digital-secs{font-size:16px;opacity:.5;}
.tz-date-line{text-align:center;font-size:11px;color:var(--widget-text);opacity:.65;margin-top:3px;}
.tz-zone-label{text-align:center;font-size:9px;color:var(--widget-text);opacity:.4;margin-top:4px;font-family:monospace;letter-spacing:.04em;}
</style>
</head>
<body>

<?php if (!empty($_db_connect_failed) && $_dash_is_admin): ?>
<div id="db-warn-banner" style="position:fixed;top:0;left:0;right:0;z-index:88888;background:rgba(180,40,20,.94);color:#fff;font-size:12px;padding:8px 16px;display:flex;align-items:center;gap:10px;box-shadow:0 2px 12px rgba(0,0,0,.5);">
  ⚠️ <strong>MySQL connection failed</strong> — running in JSON fallback mode.
  <a href="options.php" style="color:#ffd;text-decoration:underline;">Fix in Options → MySQL</a>
  &nbsp;·&nbsp;
  <a href="index.php?action=switch_to_json" onclick="return confirm('Switch permanently to JSON file storage? MySQL credentials will be removed from dash_config.php.')" style="color:#ffd;text-decoration:underline;">Switch to JSON permanently</a>
  <button onclick="document.getElementById('db-warn-banner').remove()" style="margin-left:auto;background:none;border:none;color:#fff;cursor:pointer;font-size:18px;line-height:1;opacity:.7;padding:0 2px;">×</button>
</div>
<?php endif; ?>

<?php if (!empty($_is_first_run)): ?>
<div id="first-run-overlay" style="position:fixed;inset:0;z-index:99999;background:rgba(7,9,15,.97);display:flex;align-items:center;justify-content:center;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',sans-serif;">
  <div style="background:#0f1623;border:1px solid rgba(255,255,255,.13);border-radius:16px;max-width:600px;width:92%;padding:36px 32px;max-height:90vh;overflow-y:auto;box-shadow:0 8px 60px rgba(0,0,0,.7);">
    <div style="font-size:28px;margin-bottom:10px;">👋</div>
    <h1 style="font-size:20px;font-weight:700;color:#fff;margin:0 0 6px;">Welcome, <?= htmlspecialchars($_dash_uname) ?>!</h1>
    <p style="font-size:13px;color:rgba(255,255,255,.45);margin:0 0 24px;line-height:1.6;">Your personal dashboard is ready. Pick some starter columns below, import bookmarks, then click <strong style="color:#fff;">Start</strong>. You can change everything later — this is just your own space.</p>

    <div style="font-size:11px;font-weight:700;color:rgba(255,255,255,.5);text-transform:uppercase;letter-spacing:.08em;margin-bottom:10px;">Starter Columns (pick any)</div>
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px;margin-bottom:22px;">
      <?php
      // Pulled from the shared preset library so the wizard, the Quick-Pick
      // panel inside Add-Link, and the Options "Add Preset Column" buttons
      // all stay in sync. To add a new starter, edit presets.php.
      foreach (dashGetPresets() as $key => $info): ?>
      <label class="fr-lbl" style="display:flex;align-items:center;gap:10px;padding:10px 12px;background:rgba(255,255,255,.05);border:1px solid rgba(255,255,255,.09);border-radius:9px;cursor:pointer;user-select:none;transition:background .15s,border .15s;">
        <input type="checkbox" class="fr-col-cb" value="<?= htmlspecialchars($key) ?>" style="accent-color:#4a9eff;width:16px;height:16px;cursor:pointer;" onchange="frToggle(this)">
        <span style="font-size:20px;line-height:1;"><?= $info['icon'] ?></span>
        <span><strong style="font-size:13px;color:#fff;display:block;margin-bottom:1px;"><?= htmlspecialchars($key) ?></strong><span style="font-size:11px;color:rgba(255,255,255,.35);"><?= htmlspecialchars($info['desc']) ?></span></span>
      </label>
      <?php endforeach; ?>
    </div>

    <div style="font-size:11px;font-weight:700;color:rgba(255,255,255,.5);text-transform:uppercase;letter-spacing:.08em;margin-bottom:8px;">Import Browser Bookmarks <span style="font-weight:400;text-transform:none;color:rgba(255,255,255,.3);">(optional)</span></div>
    <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;margin-bottom:8px;">
      <label style="display:inline-flex;align-items:center;gap:7px;padding:7px 14px;background:rgba(255,255,255,.07);border:1px solid rgba(255,255,255,.16);border-radius:8px;font-size:13px;cursor:pointer;color:#cbd5e1;">
        📂 Choose bookmark HTML file
        <input type="file" id="fr-bm-file" accept=".html,.htm" style="display:none;" onchange="frLoadBookmarks(this)">
      </label>
      <span id="fr-bm-status" style="font-size:12px;color:rgba(255,255,255,.35);"></span>
    </div>
    <p style="font-size:11px;color:rgba(255,255,255,.25);margin:0 0 20px;">Export from Chrome/Firefox/Edge: Bookmarks Manager → ⋮ → Export bookmarks</p>

    <div style="font-size:11px;font-weight:700;color:rgba(255,255,255,.5);text-transform:uppercase;letter-spacing:.08em;margin-bottom:10px;">Widgets <span style="font-weight:400;text-transform:none;color:rgba(255,255,255,.3);">(optional — add or remove any time in ⚙️ Settings)</span></div>
    <div style="display:flex;flex-direction:column;gap:8px;margin-bottom:22px;">
      <label style="display:flex;align-items:center;gap:10px;padding:10px 12px;background:rgba(255,255,255,.05);border:1px solid rgba(255,255,255,.09);border-radius:9px;cursor:pointer;flex-wrap:wrap;">
        <input type="checkbox" id="fr-wgt-weather" style="accent-color:#4a9eff;width:16px;height:16px;">
        <span style="font-size:18px;">☁️</span>
        <span><strong style="font-size:13px;color:#fff;">Weather</strong><span style="font-size:11px;color:rgba(255,255,255,.35);display:block;">Live conditions &amp; 3-day forecast</span></span>
        <input type="text" id="fr-wgt-weather-city" placeholder="City name or ZIP…" onclick="event.stopPropagation()" style="margin-left:auto;background:#07090f;border:1px solid rgba(255,255,255,.12);border-radius:6px;padding:5px 9px;color:#cbd5e1;font-size:12px;width:160px;">
      </label>
      <label style="display:flex;align-items:center;gap:10px;padding:10px 12px;background:rgba(255,255,255,.05);border:1px solid rgba(255,255,255,.09);border-radius:9px;cursor:pointer;flex-wrap:wrap;">
        <input type="checkbox" id="fr-wgt-rss" style="accent-color:#4a9eff;width:16px;height:16px;">
        <span style="font-size:18px;">📰</span>
        <span><strong style="font-size:13px;color:#fff;">RSS Feed</strong><span style="font-size:11px;color:rgba(255,255,255,.35);display:block;">Live news/blog headlines</span></span>
        <input type="url" id="fr-wgt-rss-url" placeholder="https://feeds.bbci.co.uk/news/rss.xml" onclick="event.stopPropagation()" style="margin-left:auto;background:#07090f;border:1px solid rgba(255,255,255,.12);border-radius:6px;padding:5px 9px;color:#cbd5e1;font-size:12px;width:240px;">
      </label>
      <label style="display:flex;align-items:center;gap:10px;padding:10px 12px;background:rgba(255,255,255,.05);border:1px solid rgba(255,255,255,.09);border-radius:9px;cursor:pointer;">
        <input type="checkbox" id="fr-wgt-sticky" style="accent-color:#4a9eff;width:16px;height:16px;">
        <span style="font-size:18px;">📝</span>
        <span><strong style="font-size:13px;color:#fff;">Sticky Note</strong><span style="font-size:11px;color:rgba(255,255,255,.35);display:block;">Draggable notepad, auto-saves</span></span>
      </label>
    </div>

    <div style="display:flex;gap:10px;">
      <button id="fr-start-btn" onclick="frStart()" style="flex:1;padding:13px;background:#4a9eff;border:none;border-radius:9px;color:#fff;font-size:14px;font-weight:700;cursor:pointer;transition:opacity .15s;">🚀 Start My Dashboard</button>
      <button onclick="frSkip()" style="padding:13px 20px;background:rgba(255,255,255,.07);border:1px solid rgba(255,255,255,.13);border-radius:9px;color:rgba(255,255,255,.55);font-size:13px;cursor:pointer;">Skip →</button>
    </div>
  </div>
</div>
<script>
function frToggle(cb){const lbl=cb.closest('label');lbl.style.background=cb.checked?'rgba(80,160,255,.13)':'rgba(255,255,255,.05)';lbl.style.border=cb.checked?'1px solid rgba(80,160,255,.4)':'1px solid rgba(255,255,255,.09)';}

// ── Bookmark file import ──────────────────────────────────────────────────────
let _frBmCols = [];
function frLoadBookmarks(input) {
  const file = input.files[0]; if (!file) return;
  const st = document.getElementById('fr-bm-status');
  st.textContent = '⏳ Reading…';
  const reader = new FileReader();
  reader.onload = function(e) {
    _frBmCols = [];
    try {
      const parser = new DOMParser();
      const doc = parser.parseFromString(e.target.result, 'text/html');
      const folders = doc.querySelectorAll('DT > H3');
      folders.forEach(h3 => {
        const folderName = h3.textContent.trim();
        if (!folderName) return;
        const dl = h3.nextElementSibling;
        if (!dl || dl.tagName !== 'DL') return;
        const cards = [];
        dl.querySelectorAll('A').forEach(a => {
          if (a.href && a.href.startsWith('http'))
            cards.push({icon:'🔗', label:(a.textContent.trim()||a.href).slice(0,60), url:a.href});
        });
        if (cards.length) _frBmCols.push({id:'bm-'+Date.now()+'-'+_frBmCols.length, title:folderName, icon:'📁', cards});
      });
      if (!_frBmCols.length) {
        const cards = [];
        doc.querySelectorAll('A').forEach(a => {
          if (a.href && a.href.startsWith('http'))
            cards.push({icon:'🔗', label:(a.textContent.trim()||a.href).slice(0,60), url:a.href});
        });
        if (cards.length) _frBmCols.push({id:'bm-all-'+Date.now(), title:'Imported Bookmarks', icon:'📚', cards});
      }
      st.textContent = _frBmCols.length ? '✅ ' + _frBmCols.length + ' folder' + (_frBmCols.length!==1?'s':'') + ' ready' : '⚠️ No folders found';
    } catch(err) { st.textContent = '❌ Error reading file'; }
    input.value = '';
  };
  reader.readAsText(file);
}

async function frSkip(){
  document.getElementById('first-run-overlay').remove();
  const fd=new FormData();fd.append('action','save_links');fd.append('links_json','[]');
  await fetch('save_links.php',{method:'POST',body:fd}).catch(()=>{});
}
async function frStart(){
  const btn=document.getElementById('fr-start-btn');btn.disabled=true;btn.textContent='⏳ Setting up…';
  const selected=[...document.querySelectorAll('.fr-col-cb:checked')].map(cb=>cb.value);
  const cols=[];
  const meta=(typeof PRESET_META!=='undefined')?PRESET_META:{};
  selected.forEach((cat,i)=>{
    const items=((typeof PREBUILT_LINKS!=='undefined'&&PREBUILT_LINKS[cat])||[]).slice(0,12);
    if(!items.length)return;
    const icon=(meta[cat]&&meta[cat].icon)||'📁';
    cols.push({id:'col_'+Math.random().toString(36).slice(2,10),icon:icon,title:cat,pos_x:20+i*270,pos_y:10,cards:items.map(it=>({icon:it.icon,label:it.label,url:it.url}))});
  });
  // Append any bookmark folders parsed from the file
  _frBmCols.forEach((bc,j)=>{bc.pos_x=20+cols.length*270;bc.pos_y=10;cols.push(bc);});
  if(!cols.length)cols.push({id:'col_'+Math.random().toString(36).slice(2,10),icon:'🖥',title:'My Dashboard',pos_x:20,pos_y:10,cards:[{icon:'⚙️',label:'My Settings',url:'options.php'}]});
  try{
    const fd=new FormData();fd.append('action','save_links');fd.append('links_json',JSON.stringify(cols));
    await fetch('save_links.php',{method:'POST',body:fd});
    // Save selected widgets via add_widget.php
    const saves=[];
    if(document.getElementById('fr-wgt-weather')?.checked){
      const city=(document.getElementById('fr-wgt-weather-city')?.value||'').trim();
      const wfd=new FormData();wfd.append('widget_type','weather_city');wfd.append('params',JSON.stringify({city}));
      saves.push(fetch('add_widget.php',{method:'POST',body:wfd}).catch(()=>{}));
    }
    if(document.getElementById('fr-wgt-rss')?.checked){
      const url=(document.getElementById('fr-wgt-rss-url')?.value||'').trim();
      if(url){const wfd=new FormData();wfd.append('widget_type','rss');wfd.append('params',JSON.stringify({url}));saves.push(fetch('add_widget.php',{method:'POST',body:wfd}).catch(()=>{}));}
    }
    if(document.getElementById('fr-wgt-sticky')?.checked){
      const wfd=new FormData();wfd.append('widget_type','sticky');wfd.append('params','{}');
      saves.push(fetch('add_widget.php',{method:'POST',body:wfd}).catch(()=>{}));
    }
    await Promise.all(saves);
    window.location.reload();
  }catch(e){btn.disabled=false;btn.textContent='🚀 Start My Dashboard';}
}
</script>
<?php endif; ?>

<!-- ===== PRESET COLUMN PICKER MODAL (v1.4.3) ===========================
     Accessible to ALL non-readonly users from the 📦 Presets toolbar button
     (visible whenever Edit Mode is active). Posts to add_preset.php which
     writes to the current user's own links file — full isolation guaranteed.
  ======================================================================== -->
<div id="preset-modal" style="display:none;position:fixed;inset:0;z-index:99998;background:rgba(7,9,15,.92);overflow-y:auto;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',sans-serif;">
  <div style="max-width:860px;margin:40px auto 60px;padding:0 16px;">
    <!-- Header -->
    <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:24px;flex-wrap:wrap;gap:12px;">
      <div>
        <h2 style="font-size:22px;font-weight:700;color:#fff;margin:0 0 4px;">📦 Add Preset Column</h2>
        <p style="font-size:13px;color:rgba(255,255,255,.45);margin:0;">Pick any category to add a fully-stocked column to <strong style="color:rgba(255,255,255,.8);">your</strong> dashboard. Each user's columns are completely private.</p>
      </div>
      <button onclick="closePresetModal()" style="padding:8px 18px;background:rgba(255,255,255,.1);border:1px solid rgba(255,255,255,.2);border-radius:8px;color:#fff;font-size:13px;cursor:pointer;">✕ Close</button>
    </div>

    <!-- Success/error toast area -->
    <div id="preset-toast" style="display:none;padding:12px 18px;border-radius:8px;font-size:13px;margin-bottom:18px;font-weight:600;"></div>

    <!-- Preset grid — server-rendered from presets.php (same data as wizard + options) -->
    <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(220px,1fr));gap:10px;">
      <?php foreach (dashGetPresets() as $_pm_key => $_pm_info):
        $__preview = implode(', ', array_slice(array_column($_pm_info['items'], 'label'), 0, 4));
      ?>
      <div class="preset-card"
           data-cat="<?= htmlspecialchars($_pm_key, ENT_QUOTES) ?>"
           onclick="addPresetCol(this)"
           style="display:flex;align-items:center;gap:12px;padding:14px 16px;background:rgba(255,255,255,.05);border:1px solid rgba(255,255,255,.1);border-radius:10px;cursor:pointer;user-select:none;transition:background .15s,border .15s;"
           onmouseover="this.style.background='rgba(80,200,120,.12)';this.style.borderColor='rgba(80,200,120,.35)'"
           onmouseout="if(!this.classList.contains('pc-loading')&&!this.classList.contains('pc-done')){this.style.background='rgba(255,255,255,.05)';this.style.borderColor='rgba(255,255,255,.1)';}">
        <span class="pc-icon" style="font-size:28px;line-height:1;flex:0 0 auto;"><?= $_pm_info['icon'] ?></span>
        <span style="flex:1;min-width:0;">
          <strong class="pc-label" style="display:block;font-size:14px;color:#fff;margin-bottom:3px;"><?= htmlspecialchars($_pm_key) ?></strong>
          <span style="display:block;font-size:11px;color:rgba(255,255,255,.35);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;"><?= count($_pm_info['items']) ?> links · <?= htmlspecialchars($__preview) ?>…</span>
        </span>
        <span class="pc-plus" style="font-size:18px;color:rgba(255,255,255,.4);flex:0 0 auto;">＋</span>
      </div>
      <?php endforeach; ?>
    </div>

    <p style="font-size:11px;color:rgba(255,255,255,.25);margin-top:24px;text-align:center;">
      Added columns appear on your dashboard after this modal closes. You can rename, reorder, and delete them freely in Edit Mode.
      Columns you already have with the same name will be suffixed with (2) rather than overwritten.
    </p>
  </div>
</div>

<div id="wallpaper"></div>
<div id="ios26-overlay"></div>
<video id="bg-video" loop muted playsinline autoplay></video>
<iframe id="bg-iframe" scrolling="no" allow="autoplay"></iframe>
<div id="bg-image"></div>

<!-- ===== macOS MENU BAR ===== -->
<div id="macos-menubar">
  <div class="macos-apple" onclick="toggleMacMenu('apple-popup')" title="Apple Menu">&#xf8ff;</div>
  <div id="apple-popup" class="macos-menu-popup">
    <div class="macos-popup-item" onclick="saveAndGo('options.php')">⚙️ Dashboard Options…</div>
    <div class="macos-popup-sep"></div>
    <?php foreach(array_slice($links,0,5) as $sec): foreach(array_slice($sec['cards']??[],0,3) as $c): ?>
    <div class="macos-popup-item" onclick="window.open('<?= htmlspecialchars($c['url']) ?>','_blank')">
      <?= htmlspecialchars($c['icon']??'🔗') ?> <?= htmlspecialchars($c['label']) ?>
    </div>
    <?php endforeach; endforeach; ?>
    <div class="macos-popup-sep"></div>
    <div class="macos-popup-item" onclick="window.location='?logout=1'">🚪 Log Out</div>
  </div>
  <div class="macos-menu-item" style="font-weight:700;"><?= htmlspecialchars($title) ?></div>
  <div class="macos-menu-item" onclick="toggleEditMode()">Edit</div>
  <div class="macos-menu-item"><a href="options.php" style="color:inherit;">Options</a></div>
  <div id="macos-clock-bar"></div>
</div>

<!-- ===== Mac OS 9 MENU BAR ===== -->
<div id="macos9-menubar">
  <div class="m9-apple" onclick="toggleM9Menu(this)">🌈
    <div class="m9-popup">
      <div class="m9-popup-item" onclick="saveAndGo('options.php')">⚙️ Options…</div>
      <div class="m9-popup-sep"></div>
      <?php foreach($links as $idx=>$sec): ?>
      <div class="m9-popup-item" onclick="toggleM9Menu(this.closest('.m9-apple'));scrollToSection(<?= $idx ?>)"><?= htmlspecialchars($sec['icon']??'📁') ?> <?= htmlspecialchars($sec['title']) ?></div>
      <?php endforeach; ?>
      <?php if (!empty($page_folders)): ?><div class="m9-popup-sep"></div><?php foreach($page_folders as $pf): ?>
      <div class="m9-popup-item" onclick="toggleM9Menu(this.closest('.m9-apple'));openPageFolder('<?= htmlspecialchars(addslashes($pf['dir_key']??'')) ?>','<?= htmlspecialchars(addslashes($pf['label'])) ?>')">📂 <?= htmlspecialchars($pf['label']) ?></div>
      <?php endforeach; endif; ?>
      <div class="m9-popup-sep"></div>
      <div class="m9-popup-item" onclick="window.location='?logout=1'">Shut Down…</div>
    </div>
  </div>
  <div class="m9-item" onclick="toggleM9Menu(this)">File<div class="m9-popup">
    <div class="m9-popup-item" onclick="addLink(null)">New Bookmark…</div>
    <div class="m9-popup-item" onclick="saveAndGo('options.php')">Options…</div>
  </div></div>
  <div class="m9-item" onclick="toggleM9Menu(this)">Edit<div class="m9-popup">
    <div class="m9-popup-item" onclick="toggleEditMode()">Toggle Edit Mode</div>
  </div></div>
  <div class="m9-item" onclick="toggleM9Menu(this)">View<div class="m9-popup">
    <div class="m9-popup-item" onclick="applySize(100)">Normal Size</div>
    <div class="m9-popup-item" onclick="applySize(80)">Smaller</div>
    <div class="m9-popup-item" onclick="applySize(120)">Larger</div>
  </div></div>
  <div class="m9-clock" id="m9-clock-bar"></div>
</div>

<!-- ===== MAC9 RETRO MENU BAR ===== -->
<div id="mac9-menubar">
  <div class="mac9-apple-btn" id="mac9-apple-btn" onclick="toggleMac9Apple(event)">🌈</div>
  <div class="mac9-mitem" onclick="toggleMac9Item(this)">File
    <div class="mac9-mpopup">
      <div class="mac9-mpopup-item" onclick="addLink(null)">New Bookmark…</div>
      <div class="mac9-mpopup-item" onclick="saveAndGo('options.php')">Options…</div>
    </div>
  </div>
  <div class="mac9-mitem" onclick="toggleMac9Item(this)">Edit
    <div class="mac9-mpopup">
      <div class="mac9-mpopup-item" onclick="toggleEditMode()">Toggle Edit Mode</div>
    </div>
  </div>
  <div class="mac9-mitem" onclick="toggleMac9Item(this)">View
    <div class="mac9-mpopup">
      <div class="mac9-mpopup-item" onclick="applySize(100)">Normal Size</div>
      <div class="mac9-mpopup-item" onclick="applySize(80)">Compact</div>
      <div class="mac9-mpopup-item" onclick="applySize(120)">Large</div>
    </div>
  </div>
  <div class="mac9-clock" id="mac9-clock-bar"></div>
</div>
<!-- MAC9 Apple Menu 2-column panel -->
<div id="mac9-apple-panel">
  <div class="mac9-ap-col" id="mac9-ap-col1">
    <div class="mac9-ap-item" onclick="saveAndGo('options.php')">⚙️ Control Panels…</div>
    <div class="mac9-ap-sep"></div>
    <?php foreach ($links as $idx => $sec): ?>
    <div class="mac9-ap-item" id="mac9-sec-<?= $idx ?>" onclick="mac9ClickSection(<?= $idx ?>,this)">
      <span><?= htmlspecialchars($sec['icon']??'📁') ?> <?= htmlspecialchars($sec['title']) ?></span>
      <?php if (!empty($sec['cards'])): ?><span class="mac9-ap-arrow">▶</span><?php endif; ?>
    </div>
    <?php endforeach; ?>
    <div class="mac9-ap-sep"></div>
    <div class="mac9-ap-item" onclick="window.location='?logout=1'">🖥 Shut Down…</div>
  </div>
  <div class="mac9-ap-col" id="mac9-ap-col2" style="display:none;">
    <!-- Populated by JS -->
  </div>
</div>

<!-- ===== MACOSX RETRO MENU BAR ===== -->
<div id="macosx-menubar">
  <div class="mox-apple" id="macosx-apple-btn" onclick="toggleMacOSXApple(event)">&#xf8ff;</div>
  <div class="mox-item" style="font-weight:700;"><?= htmlspecialchars($title) ?></div>
  <div class="mox-item" onclick="toggleMoxItem(this)">File
    <div class="mox-popup">
      <div class="mox-popup-item" onclick="addLink(null)">New Bookmark…</div>
      <div class="mox-popup-sep"></div>
      <div class="mox-popup-item" onclick="saveAndGo('options.php')">Preferences…</div>
    </div>
  </div>
  <div class="mox-item" onclick="toggleMoxItem(this)">Edit
    <div class="mox-popup">
      <div class="mox-popup-item" onclick="toggleEditMode()">Toggle Edit Mode</div>
    </div>
  </div>
  <div class="mox-clock" id="macosx-clock-bar"></div>
</div>
<!-- MacOSX Apple 2-column nav panel -->
<div id="macosx-apple-panel">
  <div class="mox-ap-col" id="macosx-ap-col1">
    <div class="mox-ap-item" onclick="saveAndGo('options.php')">🍎 About This Dashboard…</div>
    <div class="mox-ap-sep"></div>
    <?php foreach ($links as $idx => $sec): ?>
    <div class="mox-ap-item" id="macosx-sec-<?= $idx ?>" onclick="macosxClickSection(<?= $idx ?>,this)">
      <span><?= htmlspecialchars($sec['icon']??'📁') ?> <?= htmlspecialchars($sec['title']) ?></span>
      <?php if (!empty($sec['cards'])): ?><span class="mox-ap-arrow">▶</span><?php endif; ?>
    </div>
    <?php endforeach; ?>
    <div class="mox-ap-sep"></div>
    <div class="mox-ap-item" onclick="saveAndGo('options.php')">⚙️ System Preferences…</div>
    <div class="mox-ap-item" onclick="window.location='?logout=1'">🚪 Log Out…</div>
  </div>
  <div class="mox-ap-col" id="macosx-ap-col2" style="display:none;">
    <!-- Populated by JS -->
  </div>
</div>

<!-- ===== Mac OSX Tiger Brushed-Metal Menu Bar ===== -->
<div id="osxtiger-menubar">
  <span class="tiger-apple">🍎</span>
  <div class="tiger-mitem" style="font-weight:900;"><?= htmlspecialchars($title) ?></div>
  <div class="tiger-mitem" onclick="this.classList.toggle('open')">File
    <div class="tiger-mpopup">
      <div class="tiger-mpopup-item" onclick="addLink(null)">📄 New Bookmark…</div>
      <div class="tiger-mpopup-sep"></div>
      <div class="tiger-mpopup-item" onclick="saveAndGo('options.php')">⚙️ Preferences…</div>
    </div>
  </div>
  <div class="tiger-mitem" onclick="this.classList.toggle('open')">Edit
    <div class="tiger-mpopup">
      <div class="tiger-mpopup-item" onclick="toggleEditMode()">✏️ Edit Layout</div>
    </div>
  </div>
  <div class="tiger-mitem" onclick="this.classList.toggle('open')">Bookmarks
    <div class="tiger-mpopup">
      <?php foreach ($links as $sec): ?>
      <div class="tiger-mpopup-sep"></div>
      <?php foreach ($sec['cards']??[] as $c): ?>
      <a class="tiger-mpopup-item" href="<?= htmlspecialchars($c['url']) ?>" target="_blank"><?= htmlspecialchars($c['icon']??'🔗') ?> <?= htmlspecialchars($c['label']??$c['title']??'Link') ?></a>
      <?php endforeach; ?>
      <?php endforeach; ?>
    </div>
  </div>
  <div class="tiger-clock" id="osxtiger-clock"></div>
</div>

<!-- ===== Ubuntu Bar ===== -->
<div id="ubuntu-bar">
  <div class="ubuntu-activities" onclick="toggleUbuntuOverview()">Activities</div>
  <div class="ubuntu-app-name"><?= htmlspecialchars($title) ?></div>
  <div class="ubuntu-menu-right">
    <span class="ubuntu-indicator" onclick="toggleEditMode()" title="Edit Mode">✏️</span>
    <span class="ubuntu-indicator" onclick="toggleUbuntuThemePicker(event)" title="Change Theme">🎨</span>
    <span class="ubuntu-indicator" id="ubuntu-clock"></span>
    <span class="ubuntu-indicator" onclick="saveAndGo('options.php')" title="Settings">⚙️</span>
    <span class="ubuntu-indicator" onclick="window.location='?logout=1'" title="Log Out">⏻</span>
  </div>
</div>

<!-- Ubuntu Theme Picker -->
<div id="ubuntu-theme-picker" style="display:none;position:fixed;top:30px;right:8px;z-index:999999;background:#2c001e;border:1px solid rgba(255,255,255,.2);border-radius:6px;padding:10px 12px;box-shadow:0 4px 20px rgba(0,0,0,.6);font-family:'Ubuntu','Segoe UI',sans-serif;font-size:12px;color:#fff;min-width:220px;">
  <div style="font-weight:600;margin-bottom:8px;opacity:.7;font-size:11px;text-transform:uppercase;letter-spacing:.05em;">🎨 Change Theme</div>
  <select onchange="onThemeChange(this.value);document.getElementById('ubuntu-theme-picker').style.display='none';" style="width:100%;padding:5px 8px;background:#4e0068;border:1px solid rgba(255,255,255,.2);border-radius:4px;color:#fff;font-size:13px;cursor:pointer;">
    <?php foreach ($visible_themes as $tk => $tl): ?>
    <option value="<?= htmlspecialchars($tk) ?>"><?= htmlspecialchars($tl) ?></option>
    <?php endforeach; ?>
  </select>
</div>

<!-- Ubuntu Activities Overlay -->
<div id="ubuntu-overview" style="display:none;position:fixed;inset:0;z-index:99998;background:rgba(44,4,38,.95);backdrop-filter:blur(10px);padding:40px;overflow-y:auto;">
  <div style="text-align:center;color:#fff;font-size:20px;font-weight:600;margin-bottom:24px;font-family:'Ubuntu',sans-serif;">Activities</div>
  <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(100px,1fr));gap:16px;max-width:800px;margin:0 auto;" id="ubuntu-app-grid">
    <?php foreach($links as $sec): foreach($sec['cards']??[] as $c): ?>
    <a href="<?= htmlspecialchars($c['url']) ?>" target="_blank" onclick="document.getElementById('ubuntu-overview').style.display='none'" style="display:flex;flex-direction:column;align-items:center;gap:8px;padding:14px 8px;border-radius:10px;background:rgba(255,255,255,.08);color:#fff;text-align:center;font-family:'Ubuntu',sans-serif;font-size:12px;text-decoration:none;transition:background .2s;" onmouseover="this.style.background='rgba(233,84,32,.4)'" onmouseout="this.style.background='rgba(255,255,255,.08)'">
      <span style="font-size:32px"><?= htmlspecialchars($c['icon']??'🔗') ?></span>
      <span style="overflow:hidden;text-overflow:ellipsis;white-space:nowrap;width:100%"><?= htmlspecialchars($c['label']) ?></span>
    </a>
    <?php endforeach; endforeach; ?>
  </div>
  <div style="text-align:center;margin-top:20px;"><button onclick="document.getElementById('ubuntu-overview').style.display='none'" style="padding:8px 20px;background:rgba(255,255,255,.15);border:1px solid rgba(255,255,255,.2);border-radius:6px;color:#fff;cursor:pointer;font-size:13px;">✕ Close</button></div>
</div>

<div id="app">
<!-- CRT phosphor overlay — shown when body.crt-on is set -->
<div id="crt-overlay" aria-hidden="true"></div>
  <div id="header">
    <span id="logo"><?php if ($_dash_logo): ?><img src="<?= htmlspecialchars($_dash_logo) ?>" style="height:22px;vertical-align:middle;border-radius:3px;" alt="Logo"><?php else: ?>🖥 <?= htmlspecialchars($title) ?><?php endif; ?></span>
    <?php if ($monitor['storage']??true): foreach ($drives as $d): ?>
    <div class="widget">
      <span id="icon-<?= htmlspecialchars($d['key']) ?>"><?= htmlspecialchars($d['icon']??'💾') ?></span>
      <span id="w-<?= htmlspecialchars($d['key']) ?>" title="<?= htmlspecialchars($d['label']) ?>"><?= htmlspecialchars($d['label']) ?> --</span>
    </div>
    <?php endforeach; endif; ?>
    <div id="search-wrap">
      <input id="search-input" type="text" placeholder="Search…" onkeydown="if(event.key==='Enter')doSearch()">
      <button id="search-btn" onclick="doSearch()">Go</button>
    </div>
    <span id="clock" onclick="toggleClock24h()" title="Click to toggle 12 / 24-hour time" style="cursor:pointer;"></span>
    <a href="diag.php" title="Diagnostics / folder debug" style="font-size:10px;padding:2px 6px;border-radius:4px;background:rgba(128,128,128,.12);border:1px solid rgba(128,128,128,.2);color:inherit;opacity:.5;text-decoration:none;font-family:monospace;" target="_blank">v1.4.3</a>
    <?php if ($_dash_is_admin): ?>
    <a href="options.php" style="font-size:11px;padding:3px 10px;border-radius:4px;background:rgba(80,150,255,.35);border:1px solid rgba(80,150,255,.6);color:#fff;font-weight:600;text-decoration:none;letter-spacing:.02em;" title="Dashboard Settings">⚙️ Settings</a>
    <?php elseif ($_dash_role !== 'readonly'): ?>
    <a href="options.php" style="font-size:11px;padding:3px 10px;border-radius:4px;background:rgba(80,150,255,.18);border:1px solid rgba(80,150,255,.35);color:rgba(180,210,255,.9);font-weight:600;text-decoration:none;" title="My Settings">⚙️ Settings</a>
    <?php else: ?>
    <span title="Logged in as <?= htmlspecialchars($_dash_uname) ?> (read-only)"
      style="font-size:11px;padding:2px 7px;border-radius:4px;background:rgba(80,150,255,.15);border:1px solid rgba(80,150,255,.3);color:rgba(180,210,255,.9);cursor:default;">
      👤 <?= htmlspecialchars($_dash_uname) ?>
    </span>
    <?php endif; ?>
    <a href="?logout=1" style="font-size:11px;padding:2px 7px;border-radius:4px;background:rgba(255,60,60,.2);border:1px solid rgba(255,60,60,.3);color:inherit;" title="Logout">🚪</a>
    <button id="page-folder-btn" onclick="addPageFolder()" title="Add a file folder to the page" style="font-size:11px;padding:2px 7px;border-radius:4px;background:rgba(255,255,255,.12);border:1px solid rgba(255,255,255,.2);color:inherit;cursor:pointer;">📁 + Folder</button>
    <button id="add-preset-btn" onclick="openPresetModal()" title="Add a preset column (all 13 starter categories)" style="font-size:11px;padding:2px 7px;border-radius:4px;background:rgba(80,200,120,.2);border:1px solid rgba(80,200,120,.4);color:inherit;cursor:pointer;">📦 Presets</button>
    <button id="edit-mode-toggle" onclick="toggleEditMode()" title="Edit Mode">✏️ Edit</button>
    <button id="spread-btn" onclick="spreadOutSections()" title="Spread sections out into a grid (fixes stacked sections)" style="display:none;font-size:11px;padding:2px 7px;border-radius:4px;background:rgba(255,165,0,.2);border:1px solid rgba(255,165,0,.4);color:inherit;cursor:pointer;">🗂 Spread Out</button>
    <div id="layout-ctrl" style="display:none;gap:6px;align-items:center;">
      <button onclick="openProfilesModal()" title="Save, load, or manage layout profiles" style="font-size:11px;padding:2px 7px;border-radius:4px;background:rgba(80,150,255,.2);border:1px solid rgba(80,150,255,.4);color:inherit;cursor:pointer;">📋 Profiles</button>
      <span id="save-indicator" style="font-size:10px;opacity:.55;white-space:nowrap;transition:opacity .4s;"></span>
    </div>
    <div id="hdr-size-ctrl">
      <input type="range" id="size-slider-top" min="60" max="200" value="100" step="5" oninput="applySize(this.value)" style="width:70px;accent-color:#4a9eff;">
      <span id="size-label-top" style="font-size:11px;min-width:32px;">100%</span>
    </div>
    <select id="theme-sel" onchange="onThemeChange(this.value)" style="font-size:11px;padding:2px 4px;border-radius:3px;border:1px solid #888;background:#fff;color:#000;max-width:160px;cursor:pointer;">
      <?php foreach ($visible_themes as $tk => $tl): ?>
      <option value="<?= htmlspecialchars($tk) ?>"><?= htmlspecialchars($tl) ?></option>
      <?php endforeach; ?>
    </select>
    <select id="variant-sel" onchange="onVariantChange(this.value)" style="font-size:11px;padding:2px 4px;border-radius:3px;border:1px solid #888;background:#fff;color:#000;max-width:150px;cursor:pointer;"></select>
    <button id="crt-toggle-btn" onclick="toggleCRT()" title="Toggle CRT scanline effect"
            style="font-size:12px;padding:2px 7px;border-radius:3px;border:1px solid #888;background:rgba(0,0,0,.3);color:#fff;cursor:pointer;opacity:.5;line-height:1.6;">📺</button>
    <button id="sound-toggle-btn" onclick="toggleSound()" title="Toggle OS theme sounds"
            style="font-size:12px;padding:2px 7px;border-radius:3px;border:1px solid #888;background:rgba(0,0,0,.3);color:#fff;cursor:pointer;opacity:<?= ($_state['theme_sound'] ?? '1') !== '0' ? '1' : '.5' ?>;line-height:1.6;">🔊</button>
    <input type="color" id="sticky-color-pick" value="#f6e87e" title="Choose sticky note color"
           style="width:22px;height:22px;padding:0;border:1px solid #888;border-radius:3px;cursor:pointer;background:none;vertical-align:middle;flex-shrink:0;">
    <button id="add-sticky-btn" onclick="window._addStickyNote&&_addStickyNote(document.getElementById('sticky-color-pick').value||'#f6e87e')" title="Add sticky note in chosen color"
            style="font-size:12px;padding:2px 7px;border-radius:3px;border:1px solid #888;background:rgba(0,0,0,.3);cursor:pointer;line-height:1.6;">📌</button>
  </div>

  <div id="services">
    <?php
    // ===== STAT WIDGET SECTIONS =====
    // Flat-file mode: prefer per-device stat_pos so each device keeps its own
    // widget layout. Falls back to global only when no device-specific data exists.
    if (!$_db && !empty($_mstate['stat_pos_json'])) {
        $_sp_raw = $_mstate['stat_pos_json'];
    } else {
        $_sp_raw = dashGetSetting($_db, $_dash_uname, 'stat_pos_json', '{}');
    }
    $stat_pos = json_decode($_sp_raw ?: '{}', true) ?: [];
    if ($monitor['cpu'] ?? true):
      $sp = $stat_pos['cpu'] ?? ['x'=>10,'y'=>10];
    ?>
    <div class="stat-section" id="stat-cpu" style="left:<?= (int)$sp['x'] ?>px;top:<?= (int)$sp['y'] ?>px;<?= isset($sp['w'])? 'width:'.(int)$sp['w'].'px;':'' ?><?= isset($sp['h'])? 'height:'.(int)$sp['h'].'px;':'' ?>" data-stat="cpu">
      <div class="stat-section-hdr">⚡ CPU <button class="stat-close-btn" onclick="hideStatWidget('stat-cpu',event)" title="Hide widget">×</button></div>
      <div class="stat-section-body">
        <div class="stat-row">
          <span class="stat-label">Usage</span>
          <div class="stat-bar-wrap"><div class="stat-bar bar-ok" id="stat-cpu-bar" style="width:0%"></div></div>
          <span class="stat-val" id="stat-cpu-val">--</span>
        </div>
      </div>
    </div>
    <?php endif; ?>
    <?php if ($monitor['ram'] ?? true):
      $sp = $stat_pos['ram'] ?? ['x'=>200,'y'=>10];
    ?>
    <div class="stat-section" id="stat-ram" style="left:<?= (int)$sp['x'] ?>px;top:<?= (int)$sp['y'] ?>px;<?= isset($sp['w'])? 'width:'.(int)$sp['w'].'px;':'' ?>" data-stat="ram">
      <div class="stat-section-hdr">🧠 RAM <button class="stat-close-btn" onclick="hideStatWidget('stat-ram',event)" title="Hide widget">×</button></div>
      <div class="stat-section-body">
        <div class="stat-row">
          <span class="stat-label">Used</span>
          <div class="stat-bar-wrap"><div class="stat-bar bar-ok" id="stat-ram-bar" style="width:0%"></div></div>
          <span class="stat-val" id="stat-ram-val">--</span>
        </div>
      </div>
    </div>
    <?php endif; ?>
    <?php if ($monitor['storage'] ?? true): foreach ($drives as $di => $d):
      $sp = $stat_pos['drv-'.$d['key']] ?? ['x'=>390+$di*200,'y'=>10];
    ?>
    <div class="stat-section" id="stat-drv-<?= htmlspecialchars($d['key']) ?>" style="left:<?= (int)$sp['x'] ?>px;top:<?= (int)$sp['y'] ?>px;<?= isset($sp['w'])? 'width:'.(int)$sp['w'].'px;':'' ?>" data-stat="drv-<?= htmlspecialchars($d['key']) ?>">
      <div class="stat-section-hdr"><?= htmlspecialchars($d['icon']??'💾') ?> <?= htmlspecialchars($d['label']) ?> <button class="stat-close-btn" onclick="hideStatWidget('stat-drv-<?= htmlspecialchars($d['key']) ?>',event)" title="Hide widget">×</button></div>
      <div class="stat-section-body">
        <div class="stat-row">
          <span class="stat-label">Used</span>
          <div class="stat-bar-wrap"><div class="stat-bar bar-ok" id="stat-drv-<?= htmlspecialchars($d['key']) ?>-bar" style="width:0%"></div></div>
          <span class="stat-val" id="stat-drv-<?= htmlspecialchars($d['key']) ?>-val">--</span>
        </div>
      </div>
    </div>
    <?php endforeach; endif; ?>

    <?php /* ── Clock widget ── */
      if ($monitor['clock'] ?? true):
        $sp = $stat_pos['clock'] ?? ['x'=>600,'y'=>10]; ?>
    <div class="stat-section clock-widget" id="stat-clock" data-stat="clock" style="left:<?= (int)$sp['x'] ?>px;top:<?= (int)$sp['y'] ?>px;<?= isset($sp['w'])? 'width:'.(int)$sp['w'].'px;':'' ?>">
      <div class="stat-section-hdr">🕐 Clock <button class="stat-close-btn" onclick="hideStatWidget('stat-clock',event)" title="Hide">×</button></div>
      <div class="stat-section-body" id="clock-widget-body">
        <div class="clock-digital-wrap">
          <div class="clock-digital-time"><span id="cw-hm">00:00</span><span class="clock-digital-secs" id="cw-s">:00</span></div>
        </div>
        <div class="clock-date-line" id="cw-date"></div>
        <div class="analog-face" id="cw-analog" style="display:none;">
          <div class="clock-hand hand-hour"  id="cw-hour"   style="transform:rotate(0deg)"></div>
          <div class="clock-hand hand-minute" id="cw-min"    style="transform:rotate(0deg)"></div>
          <div class="clock-hand hand-second" id="cw-sec"    style="transform:rotate(0deg)"></div>
          <div class="analog-center"></div>
          <div class="analog-second-center"></div>
        </div>
        <button class="clock-mode-btn" id="cw-mode-btn" onclick="toggleClockMode()">Switch to Analog</button>
      </div>
    </div>
    <?php endif; ?>

    <?php /* ── Weather widget ── */
      if ($monitor['weather'] ?? true):
        $sp = $stat_pos['weather'] ?? ['x'=>810,'y'=>10]; ?>
    <div class="stat-section weather-widget" id="stat-weather" data-stat="weather" style="left:<?= (int)$sp['x'] ?>px;top:<?= (int)$sp['y'] ?>px;<?= isset($sp['w'])? 'width:'.(int)$sp['w'].'px;':'' ?>">
      <div class="stat-section-hdr">🌤 Weather <button class="stat-close-btn" onclick="hideStatWidget('stat-weather',event)" title="Hide">×</button></div>
      <div class="stat-section-body" id="weather-body">
        <div class="weather-err" id="weather-msg">Enter zip code below</div>
        <div class="weather-zip-row">
          <input class="weather-zip-input" id="weather-zip" placeholder="ZIP / city" maxlength="20" onkeydown="if(event.key==='Enter')fetchWeather()">
          <button class="weather-zip-btn" onclick="fetchWeather()">Go</button>
        </div>
        <div class="weather-unit-row">Units:
          <button class="weather-unit-btn active" id="wu-f" onclick="setWeatherUnit('F')">°F</button>
          <button class="weather-unit-btn" id="wu-c" onclick="setWeatherUnit('C')">°C</button>
        </div>
      </div>
    </div>
    <?php endif; ?>

    <?php /* ── Extra Weather City Widgets ── */
      foreach ($weather_city_widgets as $wxc):
        $wxcId  = preg_replace('/[^a-zA-Z0-9_-]/', '', $wxc['id'] ?? '');
        if (!$wxcId) continue;
        $wxcSp   = $stat_pos['wxc-'.$wxcId] ?? ['x'=>(int)($wxc['x']??860),'y'=>(int)($wxc['y']??10)];
        $wxcName = htmlspecialchars($wxc['name'] ?? 'Weather');
        $wxcZip  = htmlspecialchars($wxc['zip'] ?? '');
        $wxcUnit = in_array($wxc['unit']??'F',['F','C']) ? $wxc['unit'] : 'F'; ?>
    <div class="stat-section weather-widget wx-city-widget" id="stat-wxc-<?= $wxcId ?>" data-stat="wxc-<?= $wxcId ?>"
         data-wxc-zip="<?= $wxcZip ?>" data-wxc-unit="<?= $wxcUnit ?>"
         style="left:<?= (int)$wxcSp['x'] ?>px;top:<?= (int)$wxcSp['y'] ?>px;<?= isset($wxcSp['w'])? 'width:'.(int)$wxcSp['w'].'px;':'' ?>">
      <div class="stat-section-hdr">🌤 <?= $wxcName ?> <button class="stat-close-btn" onclick="hideStatWidget('stat-wxc-<?= $wxcId ?>',event)" title="Hide">×</button></div>
      <div class="stat-section-body" id="wxc-body-<?= $wxcId ?>">
        <div class="weather-err" id="wxc-msg-<?= $wxcId ?>">Loading…</div>
        <div class="weather-zip-row">
          <input class="weather-zip-input" id="wxc-inp-<?= $wxcId ?>" placeholder="ZIP / city" maxlength="20" value="<?= $wxcZip ?>"
                 onkeydown="if(event.key==='Enter')fetchWeatherCity('<?= $wxcId ?>')">
          <button class="weather-zip-btn" onclick="fetchWeatherCity('<?= $wxcId ?>')">Go</button>
        </div>
        <div class="weather-unit-row">Units:
          <button class="weather-unit-btn <?= $wxcUnit==='F'?'active':'' ?>" id="wxc-wuf-<?= $wxcId ?>" onclick="setWeatherCityUnit('<?= $wxcId ?>','F')">°F</button>
          <button class="weather-unit-btn <?= $wxcUnit==='C'?'active':'' ?>" id="wxc-wuc-<?= $wxcId ?>" onclick="setWeatherCityUnit('<?= $wxcId ?>','C')">°C</button>
        </div>
      </div>
    </div>
    <?php endforeach; ?>

    <?php /* ── Timezone Widgets ── */
      foreach ($timezone_widgets as $tzw):
        $tzwId   = preg_replace('/[^a-zA-Z0-9_-]/', '', $tzw['id'] ?? '');
        if (!$tzwId) continue;
        $tzwSp   = $stat_pos['tz-'.$tzwId] ?? ['x'=>(int)($tzw['x']??620),'y'=>(int)($tzw['y']??10)];
        $tzwName = htmlspecialchars($tzw['name'] ?? 'World Clock');
        $tzwZone = htmlspecialchars($tzw['tz'] ?? 'UTC'); ?>
    <div class="stat-section tz-widget" id="stat-tz-<?= $tzwId ?>" data-stat="tz-<?= $tzwId ?>"
         data-tz-zone="<?= $tzwZone ?>"
         style="left:<?= (int)$tzwSp['x'] ?>px;top:<?= (int)$tzwSp['y'] ?>px;<?= isset($tzwSp['w'])? 'width:'.(int)$tzwSp['w'].'px;':'' ?>">
      <div class="stat-section-hdr">🕐 <?= $tzwName ?> <button class="stat-close-btn" onclick="hideStatWidget('stat-tz-<?= $tzwId ?>',event)" title="Hide">×</button></div>
      <div class="stat-section-body">
        <div class="tz-digital-wrap">
          <div class="tz-digital-time"><span id="tz-hm-<?= $tzwId ?>">--:--</span><span class="tz-digital-secs" id="tz-s-<?= $tzwId ?>">:--</span></div>
        </div>
        <div class="tz-date-line" id="tz-date-<?= $tzwId ?>"></div>
        <div class="tz-zone-label"><?= $tzwZone ?></div>
      </div>
    </div>
    <?php endforeach; ?>

    <?php /* ── Custom HTML Widgets ── */
      foreach ($html_widgets as $hw):
        $hwId = preg_replace('/[^a-zA-Z0-9_-]/', '', $hw['id'] ?? '');
        if (!$hwId) continue;
        $hwSp  = $stat_pos[$hwId] ?? ['x' => (int)($hw['x']??820), 'y' => (int)($hw['y']??80)];
        $hwName = htmlspecialchars($hw['name'] ?? 'Widget'); ?>
    <div class="stat-section hw-widget" id="stat-<?= $hwId ?>" data-stat="<?= $hwId ?>" style="left:<?= (int)$hwSp['x'] ?>px;top:<?= (int)$hwSp['y'] ?>px;<?= isset($hwSp['w'])? 'width:'.(int)$hwSp['w'].'px;':'' ?><?= isset($hwSp['h'])? 'height:'.(int)$hwSp['h'].'px;':'' ?>">
      <div class="stat-section-hdr">🧩 <?= $hwName ?> <button class="stat-close-btn" onclick="hideStatWidget('stat-<?= $hwId ?>',event)" title="Hide">×</button></div>
      <div class="stat-section-body">
        <div class="hw-widget-content"><?= $hw['html'] ?></div>
      </div>
    </div>
    <?php endforeach; ?>

    <?php /* ── RSS Feed Widgets ── */
      $rss_widgets = dashGetWidgets($_db, $_dash_uname, 'rss');
      foreach ($rss_widgets as $rw):
        $rwId  = preg_replace('/[^a-zA-Z0-9_-]/', '', $rw['id'] ?? '');
        if (!$rwId) continue;
        $rwSp  = $stat_pos[$rwId] ?? ['x' => (int)($rw['x']??840), 'y' => (int)($rw['y']??60)];
        $rwName= htmlspecialchars($rw['name'] ?? 'RSS Feed');
        $rwUrl = htmlspecialchars($rw['url'] ?? '');
        $rwMax = (int)($rw['max'] ?? 8); ?>
    <div class="stat-section rss-widget" id="stat-<?= $rwId ?>" data-stat="<?= $rwId ?>"
         data-rss-url="<?= $rwUrl ?>" data-rss-max="<?= $rwMax ?>"
         style="left:<?= (int)$rwSp['x'] ?>px;top:<?= (int)$rwSp['y'] ?>px;<?= isset($rwSp['w'])? 'width:'.(int)$rwSp['w'].'px;':'min-width:280px;' ?><?= isset($rwSp['h'])? 'height:'.(int)$rwSp['h'].'px;':'' ?>">
      <div class="stat-section-hdr">📰 <?= $rwName ?> <button class="stat-close-btn" onclick="hideStatWidget('stat-<?= $rwId ?>',event)" title="Hide">×</button></div>
      <div class="stat-section-body" style="padding:0;">
        <div class="rss-feed-body" style="max-height:260px;overflow-y:auto;padding:8px 12px;">
          <div style="opacity:.4;font-size:12px;text-align:center;padding:16px 0;">⏳ Loading feed…</div>
        </div>
      </div>
    </div>
    <?php endforeach; ?>

    <?php foreach ($_shared_rss as $rw):
        $rwId  = 'sh-'.preg_replace('/[^a-zA-Z0-9_-]/', '', $rw['id'] ?? '');
        if (!$rwId || $rwId === 'sh-') continue;
        $rwSp  = $stat_pos[$rwId] ?? ['x' => (int)($rw['x']??840), 'y' => (int)($rw['y']??60)+100];
        $rwName= htmlspecialchars($rw['name'] ?? 'RSS Feed');
        $rwUrl = htmlspecialchars($rw['url'] ?? '');
        $rwMax = (int)($rw['max'] ?? 8);
        $rwFrom= htmlspecialchars($rw['_shared_from'] ?? ''); ?>
    <div class="stat-section rss-widget" id="stat-<?= $rwId ?>" data-stat="<?= $rwId ?>"
         data-rss-url="<?= $rwUrl ?>" data-rss-max="<?= $rwMax ?>"
         style="left:<?= (int)$rwSp['x'] ?>px;top:<?= (int)$rwSp['y'] ?>px;<?= isset($rwSp['w'])? 'width:'.(int)$rwSp['w'].'px;':'min-width:280px;' ?><?= isset($rwSp['h'])? 'height:'.(int)$rwSp['h'].'px;':'' ?>">
      <div class="stat-section-hdr">📰 <?= $rwName ?> <span style="font-size:10px;background:rgba(80,160,255,.25);border:1px solid rgba(80,160,255,.4);color:#9cf;padding:1px 6px;border-radius:10px;margin-left:4px;">🔗 <?= $rwFrom ?></span></div>
      <div class="stat-section-body" style="padding:0;">
        <div class="rss-feed-body" style="max-height:260px;overflow-y:auto;padding:8px 12px;">
          <div style="opacity:.4;font-size:12px;text-align:center;padding:16px 0;">⏳ Loading feed…</div>
        </div>
      </div>
    </div>
    <?php endforeach; ?>

    <?php
    // ===== CAMERA WIDGETS =====
    $cam_widgets = dashGetWidgets($_db, $_dash_uname, 'camera');
    foreach ($cam_widgets as $cw):
      $cwId   = preg_replace('/[^a-zA-Z0-9_-]/', '', $cw['id'] ?? '');
      if (!$cwId) continue;
      $cwSp   = $stat_pos[$cwId] ?? ['x'=>(int)($cw['x']??900),'y'=>(int)($cw['y']??60)];
      $cwName = htmlspecialchars($cw['name'] ?? 'Camera');
      $cwUrl  = htmlspecialchars($cw['url'] ?? '');
      $cwType = $cw['type'] ?? 'iframe'; // iframe | video | mjpeg
      $cwW    = isset($cwSp['w']) ? 'width:'.(int)$cwSp['w'].'px;' : 'width:340px;';
      $cwH    = isset($cwSp['h']) ? 'height:'.(int)$cwSp['h'].'px;' : '';
    ?>
    <div class="stat-section cam-widget" id="stat-<?= $cwId ?>" data-stat="<?= $cwId ?>"
         style="left:<?= (int)$cwSp['x'] ?>px;top:<?= (int)$cwSp['y'] ?>px;<?= $cwW ?><?= $cwH ?>" >
      <div class="stat-section-hdr">📷 <?= $cwName ?>
        <?php if (!empty($cw['record_url'])): ?>
        <button onclick="triggerCamRecord('<?= htmlspecialchars(addslashes($cw['record_url'])) ?>')" title="Trigger recording" style="margin-left:4px;font-size:11px;padding:1px 5px;background:rgba(200,30,30,.4);border:1px solid rgba(255,80,80,.4);color:#fff;border-radius:4px;cursor:pointer;">⏺</button>
        <?php endif; ?>
        <button class="stat-close-btn" onclick="hideStatWidget('stat-<?= $cwId ?>',event)" title="Hide">×</button>
      </div>
      <div class="stat-section-body" style="padding:0;overflow:hidden;<?= $cwH ? 'height:calc(100% - 28px);' : 'height:200px;' ?>">
        <?php if ($cwType === 'video' || $cwType === 'mjpeg'): ?>
        <img src="<?= $cwUrl ?>" alt="<?= $cwName ?>" style="width:100%;height:100%;object-fit:cover;display:block;" onerror="this.alt='Feed unavailable'">
        <?php else: ?>
        <iframe src="<?= $cwUrl ?>" style="width:100%;height:100%;border:none;display:block;" allow="autoplay;camera" allowfullscreen></iframe>
        <?php endif; ?>
      </div>
    </div>
    <?php endforeach; ?>

    <?php foreach ($_shared_cam as $cw):
        $cwId   = 'sh-'.preg_replace('/[^a-zA-Z0-9_-]/', '', $cw['id'] ?? '');
        if (!$cwId || $cwId === 'sh-') continue;
        $cwSp   = $stat_pos[$cwId] ?? ['x'=>(int)($cw['x']??900),'y'=>(int)($cw['y']??60)+100];
        $cwName = htmlspecialchars($cw['name'] ?? 'Camera');
        $cwUrl  = htmlspecialchars($cw['url'] ?? '');
        $cwType = $cw['type'] ?? 'iframe';
        $cwW    = isset($cwSp['w']) ? 'width:'.(int)$cwSp['w'].'px;' : 'width:340px;';
        $cwH    = isset($cwSp['h']) ? 'height:'.(int)$cwSp['h'].'px;' : '';
        $cwFrom = htmlspecialchars($cw['_shared_from'] ?? ''); ?>
    <div class="stat-section cam-widget" id="stat-<?= $cwId ?>" data-stat="<?= $cwId ?>"
         style="left:<?= (int)$cwSp['x'] ?>px;top:<?= (int)$cwSp['y'] ?>px;<?= $cwW ?><?= $cwH ?>">
      <div class="stat-section-hdr">📷 <?= $cwName ?> <span style="font-size:10px;background:rgba(80,160,255,.25);border:1px solid rgba(80,160,255,.4);color:#9cf;padding:1px 6px;border-radius:10px;margin-left:4px;">🔗 <?= $cwFrom ?></span></div>
      <div class="stat-section-body" style="padding:0;overflow:hidden;<?= $cwH ? 'height:calc(100% - 28px);' : 'height:200px;' ?>">
        <?php if ($cwType === 'video' || $cwType === 'mjpeg'): ?>
        <img src="<?= $cwUrl ?>" alt="<?= $cwName ?>" style="width:100%;height:100%;object-fit:cover;display:block;">
        <?php else: ?>
        <iframe src="<?= $cwUrl ?>" style="width:100%;height:100%;border:none;display:block;" allow="autoplay;camera" allowfullscreen></iframe>
        <?php endif; ?>
      </div>
    </div>
    <?php endforeach; ?>

    <?php
    // ===== CALENDAR WIDGETS =====
    $cal_widgets = dashGetWidgets($_db, $_dash_uname, 'calendar');
    foreach ($cal_widgets as $calw):
      $calId   = preg_replace('/[^a-zA-Z0-9_-]/', '', $calw['id'] ?? '');
      if (!$calId) continue;
      $calSp   = $stat_pos[$calId] ?? ['x'=>(int)($calw['x']??860),'y'=>(int)($calw['y']??60)];
      $calName = htmlspecialchars($calw['name'] ?? 'Calendar');
      $calW    = isset($calSp['w']) ? 'width:'.(int)$calSp['w'].'px;' : 'width:420px;';
      $calH    = isset($calSp['h']) ? 'height:'.(int)$calSp['h'].'px;' : 'height:360px;';
      // Build multi-calendar embed URL
      $calIds  = array_filter(array_map('trim', explode(',', $calw['cal_ids'] ?? '')));
      $calSrc  = 'https://calendar.google.com/calendar/embed?';
      $params  = [];
      foreach ($calIds as $ci => $cid) {
        $params[] = ($ci===0 ? 'src=' : 'src=').urlencode($cid);
      }
      $calSrc .= implode('&', $params) . '&ctz=' . urlencode($calw['tz'] ?? 'UTC');
    ?>
    <div class="stat-section cal-widget" id="stat-<?= $calId ?>" data-stat="<?= $calId ?>"
         style="left:<?= (int)$calSp['x'] ?>px;top:<?= (int)$calSp['y'] ?>px;<?= $calW ?><?= $calH ?>">
      <div class="stat-section-hdr">📅 <?= $calName ?>
        <a href="https://calendar.google.com/calendar/r/eventedit" target="_blank" title="New event" style="margin-left:4px;font-size:11px;padding:1px 5px;background:rgba(60,120,250,.3);border:1px solid rgba(100,160,255,.4);color:#adf;border-radius:4px;text-decoration:none;">+ New</a>
        <button class="stat-close-btn" onclick="hideStatWidget('stat-<?= $calId ?>',event)" title="Hide">×</button>
      </div>
      <div class="stat-section-body" style="padding:0;overflow:hidden;height:calc(100% - 28px);">
        <iframe src="<?= htmlspecialchars($calSrc) ?>" style="width:100%;height:100%;border:none;display:block;" frameborder="0" scrolling="no"></iframe>
      </div>
    </div>
    <?php endforeach; ?>

    <?php foreach ($_shared_cal as $calw):
        $calId   = 'sh-'.preg_replace('/[^a-zA-Z0-9_-]/', '', $calw['id'] ?? '');
        if (!$calId || $calId === 'sh-') continue;
        $calSp   = $stat_pos[$calId] ?? ['x'=>(int)($calw['x']??860),'y'=>(int)($calw['y']??60)+100];
        $calName = htmlspecialchars($calw['name'] ?? 'Calendar');
        $calFrom = htmlspecialchars($calw['_shared_from'] ?? '');
        $calW    = isset($calSp['w']) ? 'width:'.(int)$calSp['w'].'px;' : 'width:420px;';
        $calH    = isset($calSp['h']) ? 'height:'.(int)$calSp['h'].'px;' : 'height:360px;';
        $calIds  = array_filter(array_map('trim', explode(',', $calw['cal_ids'] ?? '')));
        $calPrms = array_map(fn($c) => 'src='.urlencode($c), $calIds);
        $calSrc  = 'https://calendar.google.com/calendar/embed?'.implode('&',$calPrms).'&ctz='.urlencode($calw['tz']??'UTC'); ?>
    <div class="stat-section cal-widget" id="stat-<?= $calId ?>" data-stat="<?= $calId ?>"
         style="left:<?= (int)$calSp['x'] ?>px;top:<?= (int)$calSp['y'] ?>px;<?= $calW ?><?= $calH ?>">
      <div class="stat-section-hdr">📅 <?= $calName ?> <span style="font-size:10px;background:rgba(80,160,255,.25);border:1px solid rgba(80,160,255,.4);color:#9cf;padding:1px 6px;border-radius:10px;margin-left:4px;">🔗 <?= $calFrom ?></span></div>
      <div class="stat-section-body" style="padding:0;overflow:hidden;height:calc(100% - 28px);">
        <iframe src="<?= htmlspecialchars($calSrc) ?>" style="width:100%;height:100%;border:none;display:block;" frameborder="0" scrolling="no"></iframe>
      </div>
    </div>
    <?php endforeach; ?>

    <?php
    // ===== STICKY NOTES =====
    $_sn_raw = dashGetSetting($_db, $_dash_uname, 'sticky_notes', '[]');
    $_sticky_notes = json_decode($_sn_raw ?: '[]', true) ?: [];
    foreach ($_sticky_notes as $sn):
      $snId    = preg_replace('/[^a-zA-Z0-9_-]/', '', $sn['id'] ?? '');
      if (!$snId) continue;
      $snSp    = $stat_pos['sn-'.$snId] ?? ['x'=>(int)($sn['x']??900),'y'=>(int)($sn['y']??80)];
      $snW     = isset($snSp['w']) ? 'width:'.(int)$snSp['w'].'px;' : 'width:220px;';
      $snH     = isset($snSp['h']) ? 'height:'.(int)$snSp['h'].'px;' : 'height:160px;';
      $snColor = preg_match('/^#[0-9a-fA-F]{3,6}$/', $sn['color']??'') ? $sn['color'] : '#f6e87e';
      $snText  = htmlspecialchars($sn['text'] ?? '', ENT_QUOTES);
    ?>
    <div class="stat-section sticky-note-widget" id="stat-sn-<?= $snId ?>" data-stat="sn-<?= $snId ?>"
         style="left:<?= (int)$snSp['x'] ?>px;top:<?= (int)$snSp['y'] ?>px;<?= $snW ?><?= $snH ?>;background:<?= $snColor ?>;">
      <div class="stat-section-hdr" style="background:<?= $snColor ?>;">
        📌 Note <button class="stat-close-btn" onclick="deleteStickyNote('<?= $snId ?>',event)" title="Delete note">×</button>
      </div>
      <div class="stat-section-body" style="padding:0;height:calc(100% - 28px);overflow:hidden;">
        <textarea class="sticky-note-ta" data-note-id="<?= $snId ?>"
                  onkeyup="stickyNoteChanged('<?= $snId ?>',this)"><?= $snText ?></textarea>
      </div>
    </div>
    <?php endforeach; ?>

    <?php foreach ($_shared_stickies as $sn):
        $snId   = 'sh-'.preg_replace('/[^a-zA-Z0-9_-]/', '', $sn['id'] ?? '');
        if (!$snId || $snId === 'sh-') continue;
        $snSp   = $stat_pos['sn-'.$snId] ?? ['x'=>(int)($sn['x']??900),'y'=>(int)($sn['y']??80)+100];
        $snW    = isset($snSp['w']) ? 'width:'.(int)$snSp['w'].'px;' : 'width:220px;';
        $snH    = isset($snSp['h']) ? 'height:'.(int)$snSp['h'].'px;' : 'height:160px;';
        $snColor= preg_match('/^#[0-9a-fA-F]{3,6}$/', $sn['color']??'') ? $sn['color'] : '#f6e87e';
        $snText = htmlspecialchars($sn['text'] ?? '', ENT_QUOTES);
        $snFrom = htmlspecialchars($sn['_shared_from'] ?? ''); ?>
    <div class="stat-section sticky-note-widget" id="stat-<?= $snId ?>" data-stat="<?= $snId ?>"
         style="left:<?= (int)$snSp['x'] ?>px;top:<?= (int)$snSp['y'] ?>px;<?= $snW ?><?= $snH ?>;background:<?= $snColor ?>;">
      <div class="stat-section-hdr" style="background:<?= $snColor ?>;">
        📌 Note <span style="font-size:10px;background:rgba(0,0,0,.18);color:rgba(0,0,0,.65);padding:1px 5px;border-radius:10px;margin-left:4px;">🔗 <?= $snFrom ?></span>
      </div>
      <div class="stat-section-body" style="padding:0;height:calc(100% - 28px);overflow:hidden;">
        <textarea class="sticky-note-ta" readonly style="cursor:default;"><?= $snText ?></textarea>
      </div>
    </div>
    <?php endforeach; ?>

    <?php
    // ===== COUNTDOWN TIMER WIDGETS =====
    $countdown_widgets = dashGetWidgets($_db, $_dash_uname, 'countdown');
    foreach ($countdown_widgets as $cdw):
      $cdId   = preg_replace('/[^a-zA-Z0-9_-]/', '', $cdw['id'] ?? '');
      if (!$cdId) continue;
      $cdSp   = $stat_pos[$cdId] ?? ['x'=>(int)($cdw['x']??920),'y'=>(int)($cdw['y']??80)];
      $cdName = htmlspecialchars($cdw['name'] ?? 'Countdown');
      $cdDate = htmlspecialchars($cdw['target_date'] ?? '');
      $cdW    = isset($cdSp['w']) ? 'width:'.(int)$cdSp['w'].'px;' : 'width:200px;';
    ?>
    <div class="stat-section countdown-widget" id="stat-<?= $cdId ?>" data-stat="<?= $cdId ?>"
         data-target="<?= $cdDate ?>"
         style="left:<?= (int)$cdSp['x'] ?>px;top:<?= (int)$cdSp['y'] ?>px;<?= $cdW ?>;text-align:center;">
      <div class="stat-section-hdr">⏳ <?= $cdName ?> <button class="stat-close-btn" onclick="hideStatWidget('stat-<?= $cdId ?>',event)" title="Hide">×</button></div>
      <div class="stat-section-body" style="padding:12px 8px;">
        <div class="countdown-display">--:--:--</div>
        <div style="font-size:10px;opacity:.5;margin-top:4px;"><?= $cdDate ?></div>
      </div>
    </div>
    <?php endforeach; ?>

    <?php foreach ($_shared_cd as $cdw):
        $cdId   = 'sh-'.preg_replace('/[^a-zA-Z0-9_-]/', '', $cdw['id'] ?? '');
        if (!$cdId || $cdId === 'sh-') continue;
        $cdSp   = $stat_pos[$cdId] ?? ['x'=>(int)($cdw['x']??920),'y'=>(int)($cdw['y']??80)+100];
        $cdName = htmlspecialchars($cdw['name'] ?? 'Countdown');
        $cdDate = htmlspecialchars($cdw['target_date'] ?? '');
        $cdFrom = htmlspecialchars($cdw['_shared_from'] ?? '');
        $cdW    = isset($cdSp['w']) ? 'width:'.(int)$cdSp['w'].'px;' : 'width:200px;'; ?>
    <div class="stat-section countdown-widget" id="stat-<?= $cdId ?>" data-stat="<?= $cdId ?>"
         data-target="<?= $cdDate ?>"
         style="left:<?= (int)$cdSp['x'] ?>px;top:<?= (int)$cdSp['y'] ?>px;<?= $cdW ?>;text-align:center;">
      <div class="stat-section-hdr">⏳ <?= $cdName ?> <span style="font-size:10px;background:rgba(80,160,255,.25);border:1px solid rgba(80,160,255,.4);color:#9cf;padding:1px 6px;border-radius:10px;margin-left:4px;">🔗 <?= $cdFrom ?></span></div>
      <div class="stat-section-body" style="padding:12px 8px;">
        <div class="countdown-display">--:--:--</div>
        <div style="font-size:10px;opacity:.5;margin-top:4px;"><?= $cdDate ?></div>
      </div>
    </div>
    <?php endforeach; ?>

    <?php if (empty($links)): ?>
    <div class="section" data-id="default" style="left:20px;top:10px;">
      <div class="section-header"><div class="section-hdr-top"><span class="section-count">1 item</span><button class="section-collapse-btn" onclick="toggleCollapse(event,this)" title="Collapse / Expand">▼</button><div class="section-actions"><button class="section-btn" onclick="addLink(this)">+ Add</button></div></div><div class="section-title">🖥 My Server</div></div>
      <div class="section-body">
        <a class="card" href="options.php" target="_blank"><span class="card-icon">⚙️</span><span class="card-label">Dashboard Options</span><button class="card-edit-btn" onclick="editCard(event,this)">✏️</button></a>
      </div>
    </div>
    <?php else: ?>
    <?php foreach ($links as $i => $sec): $cardCount = count($sec['cards']??[]);
      $posX = ($i % $grid_cols) * 265 + 10;
      $posY = floor($i / $grid_cols) * 290 + 10;
      $px   = $sec['pos_x'] ?? $posX;
      $py   = $sec['pos_y'] ?? $posY;
    ?>
    <?php $secView = $sec['view'] ?? 'list'; $secCollapsed = !empty($sec['collapsed']); ?>
    <?php $secW = (int)($sec['width'] ?? 0); ?>
    <div class="section<?= $secCollapsed ? ' collapsed' : '' ?>" data-id="<?= htmlspecialchars($sec['id']??'') ?>" data-view="<?= htmlspecialchars($secView) ?>" style="left:<?= (int)$px ?>px;top:<?= (int)$py ?>px;<?= ($secW >= 160 && $secW <= 600) ? 'width:'.$secW.'px;' : '' ?>" onclick="handleSectionClick(event,this)">
      <div class="section-header">
        <div class="section-hdr-top">
          <span class="section-count"><?= $cardCount ?> item<?= $cardCount!==1?'s':'' ?></span>
          <button class="section-collapse-btn" onclick="toggleCollapse(event,this)" title="Collapse / Expand"><?= $secCollapsed ? '▶' : '▼' ?></button>
<?php $secPct = ($secW >= 160 && $secW <= 600) ? (int)round($secW/240*100) : 100; ?>
          <div class="section-width-ctrl" title="Column width — type % and press Enter" onmousedown="event.stopPropagation()" onclick="event.stopPropagation()">
            <span class="section-w-label">Size</span>
            <input type="number" class="section-width-num" min="25" max="400" step="5" value="<?= $secPct ?>"
              onchange="(function(inp){var pct=Math.max(25,Math.min(400,parseInt(inp.value)||100));inp.value=pct;var s=inp.closest('.section');var px=Math.round(240*pct/100);s.style.width=px+'px';s.style.setProperty('--col-fs',Math.max(0.75,Math.min(1.5,px/240)).toFixed(3));saveLinksToServer();})(this)"
              onkeydown="if(event.key==='Enter'){this.blur();event.stopPropagation();}"
              onmousedown="event.stopPropagation()" onclick="event.stopPropagation()">
            <span class="section-w-label">%</span>
          </div>
          <div class="section-actions">
            <span class="section-lock-indicator" title="Layout locked — click ✏️ Edit to rearrange">🔒</span>
            <button class="section-view-btn" onclick="toggleSectionView(event,this)" title="Toggle grid/list view"><?= $secView==='folder' ? '☰' : '⊞' ?></button>
            <button class="section-btn" onclick="addLink(this)">+ Add</button>
            <button class="section-btn section-del-btn" onclick="deleteSection(event,this)" title="Delete this column">🗑</button>
            <button class="section-hide-btn" onclick="hideColumn(event,this)" title="Hide this column (restore in Options → Widgets)">×</button>
          </div>
        </div>
        <div class="section-title"><?= htmlspecialchars($sec['icon']??'📁') ?> <?= htmlspecialchars($sec['title']) ?></div>
      </div>
      <div class="section-body">
        <?php foreach ($sec['cards']??[] as $card): ?>
        <a class="card" href="<?= htmlspecialchars($card['url']) ?>" target="_blank">
          <span class="card-icon"><?php if (!empty($card['icon_img'])): ?><img src="<?= htmlspecialchars($card['icon_img']) ?>" alt=""><?php else: ?><?= htmlspecialchars($card['icon']??'🔗') ?><?php endif; ?></span>
          <span class="card-label"><?= htmlspecialchars($card['label']) ?></span>
          <button class="card-edit-btn" onclick="editCard(event,this)">✏️</button>
        </a>
        <?php endforeach; ?>
      </div>
    </div>
    <?php endforeach; ?>

    <?php foreach ($_shared_link_cols as $i => $sec):
        $sfrom     = htmlspecialchars($sec['_shared_from'] ?? '');
        $cardCount = count($sec['cards'] ?? []);
        $px        = $sec['pos_x'] ?? (20 + (count($links) + $i) * 265);
        $py        = $sec['pos_y'] ?? 10; ?>
    <div class="section" data-id="shared-<?= htmlspecialchars($sec['id']??'') ?>" data-view="list"
         style="left:<?= (int)$px ?>px;top:<?= (int)$py ?>px;">
      <div class="section-header">
        <div class="section-hdr-top">
          <span class="section-count"><?= $cardCount ?> item<?= $cardCount!==1?'s':'' ?></span>
          <span style="font-size:10px;background:rgba(80,160,255,.25);border:1px solid rgba(80,160,255,.4);color:#9cf;padding:1px 6px;border-radius:10px;margin-left:auto;">🔗 <?= $sfrom ?></span>
        </div>
        <div class="section-title"><?= htmlspecialchars($sec['icon']??'📁') ?> <?= htmlspecialchars($sec['title']) ?></div>
      </div>
      <div class="section-body">
        <?php foreach ($sec['cards']??[] as $card): ?>
        <a class="card" href="<?= htmlspecialchars($card['url']) ?>" target="_blank">
          <span class="card-icon"><?php if (!empty($card['icon_img'])): ?><img src="<?= htmlspecialchars($card['icon_img']) ?>" alt=""><?php else: ?><?= htmlspecialchars($card['icon']??'🔗') ?><?php endif; ?></span>
          <span class="card-label"><?= htmlspecialchars($card['label']) ?></span>
        </a>
        <?php endforeach; ?>
      </div>
    </div>
    <?php endforeach; ?>
    <?php endif; ?>
    <?php foreach ($page_folders as $pf): ?>
    <div class="page-folder" data-pf-id="<?= htmlspecialchars($pf['id']) ?>" data-dir-key="<?= htmlspecialchars($pf['dir_key']??'') ?>" style="left:<?= (int)($pf['pos_x']??400) ?>px;top:<?= (int)($pf['pos_y']??20) ?>px;" ondblclick="openPageFolder('<?= htmlspecialchars(addslashes($pf['dir_key']??'')) ?>','<?= htmlspecialchars(addslashes($pf['label'])) ?>')">
      <div class="pf-icon">📁</div>
      <div class="pf-label"><?= htmlspecialchars($pf['label']) ?></div>
      <button class="pf-add-btn" onclick="event.stopPropagation();removePageFolder('<?= htmlspecialchars($pf['id']) ?>')" title="Remove folder">✕</button>
    </div>
    <?php endforeach; ?>
  </div>
</div>

<!-- Screensaver canvases -->
<canvas id="canvas-pipes"    class="screensaver-canvas"></canvas>
<canvas id="canvas-aqua"     class="screensaver-canvas"></canvas>
<canvas id="canvas-ios26"    class="screensaver-canvas"></canvas>
<canvas id="canvas-nexus"    class="screensaver-canvas"></canvas>
<canvas id="canvas-nexus2"   class="screensaver-canvas"></canvas>
<canvas id="canvas-aquarium" class="screensaver-canvas"></canvas>
<canvas id="canvas-palmos"   class="screensaver-canvas"></canvas>
<canvas id="canvas-pocketpc" class="screensaver-canvas"></canvas>
<canvas id="canvas-macos"    class="screensaver-canvas"></canvas>
<canvas id="canvas-macosx"  class="screensaver-canvas"></canvas>
<canvas id="canvas-ubuntu"    class="screensaver-canvas"></canvas>
<canvas id="canvas-snow"      class="screensaver-canvas"></canvas>
<canvas id="canvas-leaves"    class="screensaver-canvas"></canvas>
<canvas id="canvas-petals"    class="screensaver-canvas"></canvas>
<canvas id="canvas-fireworks" class="screensaver-canvas"></canvas>
<canvas id="canvas-stars"     class="screensaver-canvas"></canvas>
<canvas id="canvas-bliss"     class="screensaver-canvas"></canvas>
<canvas id="canvas-summer"    class="screensaver-canvas"></canvas>
<canvas id="canvas-webos"     class="screensaver-canvas"></canvas>
<canvas id="canvas-miku"      class="screensaver-canvas"></canvas>
<canvas id="canvas-miku2"     class="screensaver-canvas"></canvas>
<canvas id="canvas-miku3"     class="screensaver-canvas"></canvas>
<canvas id="canvas-cute"      class="screensaver-canvas"></canvas>
<canvas id="canvas-spring2"   class="screensaver-canvas"></canvas>
<canvas id="canvas-spring3"   class="screensaver-canvas"></canvas>
<canvas id="canvas-aurora"    class="screensaver-canvas"></canvas>
<canvas id="canvas-blizzard"  class="screensaver-canvas"></canvas>
<canvas id="canvas-christmas2" class="screensaver-canvas"></canvas>
<canvas id="canvas-summer2"   class="screensaver-canvas"></canvas>
<canvas id="canvas-summer3"   class="screensaver-canvas"></canvas>
<canvas id="canvas-autumn2"   class="screensaver-canvas"></canvas>
<canvas id="canvas-autumn3"   class="screensaver-canvas"></canvas>
<canvas id="canvas-c64"       class="screensaver-canvas"></canvas>
<canvas id="canvas-amiga"     class="screensaver-canvas"></canvas>
<canvas id="canvas-nextstep"  class="screensaver-canvas"></canvas>
<canvas id="canvas-beos"      class="screensaver-canvas"></canvas>
<canvas id="canvas-thanksgiving" class="screensaver-canvas"></canvas>
<canvas id="canvas-osxtiger"  class="screensaver-canvas"></canvas>

<!-- Windows Start Menu (Win98/2K/XP/StartMenu) — Win98 cascading style -->
<div id="winretro-taskbar">
  <button id="start-btn" onclick="toggleStartMenu()"><img src="data:image/svg+xml;base64,PHN2ZyB3aWR0aD0iMTQiIGhlaWdodD0iMTQiIHZpZXdCb3g9IjAgMCAxNCAxNCIgeG1sbnM9Imh0dHA6Ly93d3cudzMub3JnLzIwMDAvc3ZnIj48cmVjdCB3aWR0aD0iNiIgaGVpZ2h0PSI2IiBmaWxsPSIjZjAwIi8+PHJlY3QgeD0iOCIgd2lkdGg9IjYiIGhlaWdodD0iNiIgZmlsbD0iIzBmMCIvPjxyZWN0IHk9IjgiIHdpZHRoPSI2IiBoZWlnaHQ9IjYiIGZpbGw9IiMwMGYiLz48cmVjdCB4PSI4IiB5PSI4IiB3aWR0aD0iNiIgaGVpZ2h0PSI2IiBmaWxsPSIjZmZlMDAwIi8+PC9zdmc+" alt="" style="width:14px;height:14px;image-rendering:pixelated;"> Start</button>
  <div id="taskbar-clock"></div>
</div>
<div id="start-menu">
  <div id="start-menu-sidebar"><span><?= htmlspecialchars($title) ?></span></div>
  <div id="start-menu-items">
    <!-- Programs (flat list — each section + page folders are direct nav items) -->
    <div class="sm-item sm-has-flyout">
      <span class="sm-icon">📁</span><span class="sm-label">Programs</span><span class="sm-arrow">▶</span>
      <div class="sm-flyout">
        <?php foreach ($links as $idx => $sec): $secCards = $sec['cards'] ?? []; ?>
        <?php if (!empty($secCards)): ?>
        <div class="sm-flyout-item sm-has-flyout">
          <span class="sm-icon"><?= htmlspecialchars($sec['icon']??'📁') ?></span>
          <span class="sm-label"><?= htmlspecialchars($sec['title']) ?></span>
          <span class="sm-arrow">▶</span>
          <div class="sm-flyout">
            <div class="sm-flyout-item" onclick="scrollToSection(<?= $idx ?>);closeStartMenu();">
              <span class="sm-icon">🖥</span><span class="sm-label" style="font-style:italic;">Jump to section…</span>
            </div>
            <div class="sm-flyout-sep"></div>
            <?php foreach ($secCards as $card): ?>
            <a class="sm-flyout-item" href="<?= htmlspecialchars($card['url']) ?>" target="_blank" onclick="closeStartMenu()">
              <span class="sm-icon"><?php if (!empty($card['icon_img'])): ?><img src="<?= htmlspecialchars($card['icon_img']) ?>" style="width:16px;height:16px;border-radius:2px;object-fit:cover;" alt=""><?php else: ?><?= htmlspecialchars($card['icon']??'🔗') ?><?php endif; ?></span>
              <span class="sm-label"><?= htmlspecialchars($card['label']) ?></span>
            </a>
            <?php endforeach; ?>
          </div>
        </div>
        <?php else: ?>
        <div class="sm-flyout-item" onclick="scrollToSection(<?= $idx ?>);closeStartMenu();">
          <span class="sm-icon"><?= htmlspecialchars($sec['icon']??'📁') ?></span>
          <span class="sm-label"><?= htmlspecialchars($sec['title']) ?></span>
        </div>
        <?php endif; ?>
        <?php endforeach; ?>
        <?php if (!empty($page_folders)): ?>
        <div class="sm-flyout-sep"></div>
        <?php foreach ($page_folders as $pf): ?>
        <div class="sm-flyout-item" onclick="closeStartMenu();openPageFolder('<?= htmlspecialchars(addslashes($pf['dir_key']??'')) ?>','<?= htmlspecialchars(addslashes($pf['label'])) ?>')">
          <span class="sm-icon">📂</span>
          <span class="sm-label"><?= htmlspecialchars($pf['label']) ?></span>
        </div>
        <?php endforeach; ?>
        <?php endif; ?>
      </div>
    </div>
    <!-- Documents -->
    <a class="sm-item" href="#" onclick="closeStartMenu();openDocPanel();return false;">
      <span class="sm-icon">📄</span><span class="sm-label">Documents</span>
    </a>
    <!-- Settings -->
    <div class="sm-item sm-has-flyout">
      <span class="sm-icon">⚙️</span><span class="sm-label">Settings</span><span class="sm-arrow">▶</span>
      <div class="sm-flyout">
        <a class="sm-flyout-item" href="options.php" onclick="closeStartMenu()">
          <span class="sm-icon">🖥</span><span class="sm-label">Control Panel</span>
        </a>
        <div class="sm-flyout-sep"></div>
        <div class="sm-flyout-item" onclick="toggleEditMode();closeStartMenu();">
          <span class="sm-icon">✏️</span><span class="sm-label">Edit Mode</span>
        </div>
      </div>
    </div>
    <!-- Find -->
    <div class="sm-item sm-has-flyout">
      <span class="sm-icon">🔍</span><span class="sm-label">Find</span><span class="sm-arrow">▶</span>
      <div class="sm-flyout">
        <div class="sm-flyout-item" onclick="closeStartMenu();document.getElementById('search-input')?.focus();">
          <span class="sm-icon">🔍</span><span class="sm-label">Search Web…</span>
        </div>
      </div>
    </div>
    <!-- Help -->
    <a class="sm-item" href="https://github.com" target="_blank" onclick="closeStartMenu()">
      <span class="sm-icon">❓</span><span class="sm-label">Help</span>
    </a>
    <!-- Run -->
    <div class="sm-item" onclick="smRun()">
      <span class="sm-icon">🏃</span><span class="sm-label">Run…</span>
    </div>
    <div class="sm-sep"></div>
    <!-- Log Out -->
    <a class="sm-item" href="?logout=1">
      <span class="sm-icon">🚪</span><span class="sm-label">Log Out…</span>
    </a>
  </div>
</div>

<!-- WIN9X 3-Panel Click-Based Start Menu -->
<div id="win9x-menu">
  <!-- Column 1: Main menu -->
  <div class="w9x-col" style="flex-direction:row;">
    <div class="w9x-sidebar"><span><?= htmlspecialchars($title) ?></span></div>
    <div class="w9x-col-inner">
      <div class="w9x-item" id="w9x-programs" onclick="w9xClickPrograms()">
        <span class="w9x-item-icon">📁</span><span class="w9x-item-label">Programs</span><span class="w9x-item-arrow">▶</span>
      </div>
      <div class="w9x-item" onclick="closeWin9xMenu();openDocPanel()">
        <span class="w9x-item-icon">📄</span><span class="w9x-item-label">Documents</span>
      </div>
      <div class="w9x-item" id="w9x-settings" onclick="w9xClickSettings()">
        <span class="w9x-item-icon">⚙️</span><span class="w9x-item-label">Settings</span><span class="w9x-item-arrow">▶</span>
      </div>
      <div class="w9x-item" onclick="closeWin9xMenu();document.getElementById('search-input')?.focus()">
        <span class="w9x-item-icon">🔍</span><span class="w9x-item-label">Find…</span>
      </div>
      <div class="w9x-sep"></div>
      <a class="w9x-item" href="?logout=1" onclick="closeWin9xMenu()">
        <span class="w9x-item-icon">🚪</span><span class="w9x-item-label">Shut Down…</span>
      </a>
    </div>
  </div>
  <!-- Column 2: Section list (shown when Programs clicked) -->
  <div class="w9x-col" id="w9x-col2" style="display:none;">
    <div class="w9x-col-inner" id="w9x-col2-body">
      <?php foreach ($links as $idx => $sec): ?>
      <div class="w9x-item" data-idx="<?= $idx ?>" onclick="w9xClickSection(<?= $idx ?>)">
        <span class="w9x-item-icon"><?= htmlspecialchars($sec['icon']??'📁') ?></span>
        <span class="w9x-item-label"><?= htmlspecialchars($sec['title']) ?></span>
        <?php if (!empty($sec['cards'])): ?><span class="w9x-item-arrow">▶</span><?php endif; ?>
      </div>
      <?php endforeach; ?>
      <?php if (empty($links)): ?>
      <div class="w9x-item" style="opacity:.5;cursor:default;">(No sections yet)</div>
      <?php endif; ?>
      <?php if (!empty($page_folders)): ?>
      <div class="w9x-sep"></div>
      <?php foreach ($page_folders as $pf): ?>
      <div class="w9x-item" onclick="closeWin9xMenu();openPageFolder('<?= htmlspecialchars(addslashes($pf['dir_key']??'')) ?>','<?= htmlspecialchars(addslashes($pf['label'])) ?>')">
        <span class="w9x-item-icon">📂</span>
        <span class="w9x-item-label"><?= htmlspecialchars($pf['label']) ?></span>
      </div>
      <?php endforeach; ?>
      <?php endif; ?>
    </div>
    <!-- Settings sub-panel (hidden by default) -->
    <div class="w9x-col-inner" id="w9x-col2-settings" style="display:none;">
      <div class="w9x-col-header">Settings</div>
      <div class="w9x-item" onclick="closeWin9xMenu();saveAndGo('options.php')">
        <span class="w9x-item-icon">🖥</span><span class="w9x-item-label">Control Panel</span>
      </div>
      <div class="w9x-sep"></div>
      <div class="w9x-item" onclick="closeWin9xMenu();toggleEditMode()">
        <span class="w9x-item-icon">✏️</span><span class="w9x-item-label">Edit Mode</span>
      </div>
    </div>
  </div>
  <!-- Column 3: Links (shown when a section clicked) -->
  <div class="w9x-col" id="w9x-col3" style="display:none;">
    <div class="w9x-col-header" id="w9x-col3-hdr" style="cursor:pointer;user-select:none;" title="Click to go to this section on the desktop">📁 Links</div>
    <div class="w9x-col-inner" id="w9x-col3-body">
      <!-- Populated by JS -->
    </div>
  </div>
</div>
<!-- WIN9X links data for JS -->
<script>const WIN9X_LINKS=<?= json_encode(array_values($links)) ?>;</script>

<!-- DOCUMENT PANEL -->
<div id="doc-panel">
  <div id="doc-panel-box">
    <div id="doc-panel-header">
      <span id="doc-win-btns">
        <button class="doc-win-btn close-btn" onclick="closeDocPanel()" title="Close"></button>
        <button class="doc-win-btn min-btn"   title="Minimise (drag to move)"></button>
        <button class="doc-win-btn max-btn"   title="Maximise"></button>
      </span>
      <span id="doc-panel-title" style="flex:1;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">🗂 Documents</span>
      <button id="doc-panel-close" onclick="closeDocPanel()" title="Close">✕</button>
    </div>
    <div id="doc-panel-body">
      <div id="doc-sidebar">
        <div id="doc-type-list"></div>
        <div style="border-top:1px solid var(--card-border-dark);margin-top:8px;padding-top:8px;">
          <div style="font-size:10px;opacity:.6;padding:2px 6px;margin-bottom:4px;text-transform:uppercase;letter-spacing:.04em;">Folders</div>
          <div id="doc-folder-list"></div>
        </div>
      </div>
      <div id="doc-main">
        <div id="doc-toolbar">
          <span id="doc-folder-name">Documents</span>
          <input id="doc-search" type="search" placeholder="Search…" oninput="docSearchChanged(this.value)" autocomplete="off">
          <button id="doc-view-toggle" onclick="toggleDocView()" title="Toggle icon/list view">☰</button>
          <label id="doc-upload-btn">📤 Upload
            <input id="doc-file-input" type="file" multiple onchange="uploadDocFiles(this)">
          </label>
          <button class="doc-file-del" style="margin-left:4px;" onclick="deleteAllDocFiles()" title="Delete all files in folder">🗑 All</button>
        </div>
        <div id="doc-files">
          <div id="doc-drop-zone" onclick="document.getElementById('doc-file-input').click()">
            Drop files here or click to upload
          </div>
        </div>
      </div>
    </div>
  </div>
</div>

<!-- FOLDER PANEL -->
<div id="folder-panel">
  <div id="folder-panel-box">
    <div id="folder-panel-title"><span class="fp-icon">📂</span><span id="fp-title-text">Section</span></div>
    <div id="folder-panel-cards"></div>
    <button id="folder-panel-close" onclick="closeFolderPanel()">✕ Close</button>
  </div>
</div>

<!-- LINK MODAL -->
<div id="link-modal">
  <div id="link-modal-box">
    <h3 id="modal-title">Add Link</h3>
    <!-- Prebuilt quick-add library -->
    <div id="prebuilt-panel" style="margin-bottom:10px;">
      <div id="prebuilt-toggle" onclick="togglePrebuilt()" style="cursor:pointer;font-size:12px;color:rgba(128,200,255,.85);display:flex;align-items:center;gap:5px;margin-bottom:6px;user-select:none;">
        <span id="prebuilt-arrow">▶</span> Quick-add a popular service…
      </div>
      <div id="prebuilt-body" style="display:none;">
        <div style="display:flex;gap:4px;flex-wrap:wrap;margin-bottom:8px;" id="prebuilt-cats"></div>
        <div id="prebuilt-grid" style="display:grid;grid-template-columns:repeat(auto-fill,minmax(130px,1fr));gap:5px;max-height:200px;overflow-y:auto;padding:2px;"></div>
      </div>
    </div>
    <label>Icon — <span id="icon-preview" style="font-size:18px">🔗</span></label>
    <div class="icon-cat-tabs" id="icon-cat-tabs"></div>
    <div class="icon-picker" id="icon-picker"></div>
    <label>Label <span id="icon-suggest-hint" style="font-size:11px;color:rgba(128,200,255,.8);display:none;">— suggested from name</span></label>
    <input type="text" id="modal-label" placeholder="My Service" oninput="suggestIconFromLabel()">
    <label>URL</label>
    <input type="text" id="modal-url" placeholder="https://..." oninput="suggestIconFromUrl()">
    <label>Section / Column</label>
    <select id="modal-section" onchange="handleSectionSelect()" style="width:100%;padding:8px 10px;background:rgba(255,255,255,.07);border:1px solid rgba(255,255,255,.15);border-radius:8px;color:#fff;font-size:14px;">
      <?php foreach ($links as $s): ?>
      <option value="<?= htmlspecialchars($s['title']) ?>"><?= htmlspecialchars($s['title']) ?></option>
      <?php endforeach; ?>
      <option value="__new__">── New column… ──</option>
    </select>
    <input type="text" id="modal-section-new" placeholder="New column name…" style="display:none;width:100%;margin-top:6px;padding:8px 10px;background:rgba(255,255,255,.07);border:1px solid rgba(255,255,255,.15);border-radius:8px;color:#fff;font-size:14px;">
    <div id="modal-copy-row" style="display:none;margin-top:6px;font-size:12px;opacity:.75;align-items:center;gap:6px;">
      <input type="checkbox" id="modal-copy-check" style="cursor:pointer;">
      <label for="modal-copy-check" style="cursor:pointer;">Also copy to original column (keep in both)</label>
    </div>
    <div class="modal-btns">
      <button class="modal-btn modal-btn-delete" id="modal-delete" onclick="deleteCard()" style="display:none">🗑 Delete</button>
      <button class="modal-btn modal-btn-cancel" onclick="closeModal()">Cancel</button>
      <button class="modal-btn modal-btn-save" onclick="saveCard()">💾 Save</button>
    </div>
  </div>
</div>

<script>
// _patchActiveProfile stub — real impl is in the screensaver script block below.
// This stub prevents "not defined" errors when applySize/applyWallpaper fire during init.
function _patchActiveProfile(){}
// ===== SERVER-SIDE DATA =====
const SERVER_BG = <?= json_encode($bgs) ?>;
const CUSTOM_THEME_SERVER = <?= $ctJson ?>;
const HP_USER = <?= json_encode($_dash_uname) ?>;
const PHP_STATE = <?= json_encode($_dash_state) ?>;
const MACHINE_UUID = <?= json_encode($_muuid) ?>;
const MACHINE_DATA = <?= json_encode($_machine) ?>;
const HIDDEN_WIDGETS = <?= json_encode(json_decode(dashGetSetting($_db,$_dash_uname,'hidden_widgets','[]')??'[]',true)?:[]) ?>;
// ===== USER ROLE =====
const DASH_ROLE = <?= json_encode($_dash_role) ?>;        // 'admin'|'user'|'readonly'
const DASH_IS_ADMIN = <?= $_dash_is_admin ? 'true' : 'false' ?>;
const DASH_CAN_EDIT = <?= ($_dash_role !== 'readonly') ? 'true' : 'false' ?>;

// ===== COMPREHENSIVE ICON LIBRARY =====
const ICON_CATS = {
  'System':   ['🖥','💻','🖨','⌨️','🖱','📱','⌚','📡','🔌','🔋','💾','💿','📀','🖲','🖼','📺','🎙','🎚','🎛','📻','📷','📹'],
  'Files':    ['📁','📂','🗂','📄','📝','📃','📋','📊','📈','📉','🗒','🗓','📑','🗃','📦','🗑','✉️','📨','📩','📬','📭','📮'],
  'Network':  ['☁️','🌐','🔗','📡','🛜','📶','🌍','🌎','🌏','🌩','🔒','🔓','🛡','🔐','🔑','🗝','🔀','🔁'],
  'Media':    ['🎵','🎶','🎬','🎞','📸','🖼','🎤','🎧','🎨','🖌','✏️','📐','🎮','🕹','🎲','♟','🎯','🏆'],
  'Dev':      ['⚙️','🔧','🛠','🔩','⚡','🐳','🐧','💡','🔬','🧪','⚗️','🏗','🧰','🔨','🧱','🪛','🪚','⛏','🔱'],
  'Apps':     ['📧','💬','📞','📲','🗺','📍','🏠','🏢','🏦','🏥','🛒','🍽','☕','🍵','🍺','🚀','✈️','🚢'],
  'Database': ['🗄','🗃','🗂','💿','💾','🧮','📊','📈','📉','🔢','⌗','⛃','⛅','💎','🪣','🧲'],
  'Status':   ['✅','❌','⚠️','ℹ️','🔴','🟠','🟡','🟢','🔵','🟣','⭐','💫','🔥','❄️','♻️','🆕','🆙','💯','🆗','🆒'],
  'People':   ['👤','👥','👨‍💻','🧑‍💻','👩‍💻','🧑‍🔬','👨‍🔬','🤖','👾','🦊','🐳','🐝','🦋','🐧','🦁','🐝'],
  'Misc':     ['🌈','🌙','⭐','🌟','💎','🏅','🥇','🪐','🌀','🔮','🪄','🎩','🎪','🎠','🎡','🎢','🎭','🤹'],
};

let _selectedIcon = '🔗';
let _activeIconCat = 'System';

// ===== ICON KEYWORD SUGGESTIONS =====
const ICON_KEYWORDS = {
  // Media servers
  'jellyfin':'🎬','plex':'🎬','emby':'🎬','kodi':'🎬','stremio':'🎬',
  'navidrome':'🎵','airsonic':'🎵','music':'🎵','audio':'🎵','podcast':'🎵',
  'immich':'📸','photoprism':'📸','photo':'📸','gallery':'🖼','image':'🖼',
  // Cloud / Files
  'nextcloud':'☁️','owncloud':'☁️','cloud':'☁️','s3':'☁️',
  'filebrowser':'📁','filerun':'📁','files':'📁','storage':'📁',
  'stirling':'📄','pdf':'📄','paperless':'📄','docs':'📄','document':'📄',
  // Monitoring
  'grafana':'📊','prometheus':'📊','netdata':'📡','uptime kuma':'📶','uptime':'📶',
  'healthcheck':'💊','status':'📶','monitor':'📊','dashboard':'📊','stats':'📊',
  // Smart home
  'home assistant':'🏠','homeassistant':'🏠','smarthome':'🏠','iot':'🏠','ha ':'🏠',
  // Network / DNS
  'pihole':'⬛','adguard':'🛡','vpn':'🔒','wireguard':'🔒','openvpn':'🔒',
  'unifi':'📡','network':'📡','router':'📡','dns':'📡','firewall':'🛡',
  'traefik':'🔀','nginx':'🟢','apache':'🔴','haproxy':'🔀','proxy':'🔀',
  // Git / Dev
  'gitea':'🦊','gitlab':'🦊','github':'🐙','forgejo':'🦊','git':'🦊',
  'code':'💻','vscode':'💻','dev':'💻','ide':'💻','ci':'⚙️','jenkins':'⚙️',
  // Password
  'vaultwarden':'🔐','bitwarden':'🔐','password':'🔐','keypass':'🔐','secret':'🔐',
  // Database
  'phpmyadmin':'🗄','mysql':'🐬','mariadb':'🐬','postgres':'🗄','database':'🗄',
  'adminer':'🗄','redis':'🗄','mongo':'🗄',
  // Containers
  'portainer':'🐳','docker':'🐳','kubernetes':'🐳','k8s':'🐳',
  // Download managers
  'qbittorrent':'⬇️','transmission':'⬇️','nzbget':'⬇️','sabnzbd':'⬇️','download':'⬇️','torrent':'⬇️',
  // Arr apps
  'sonarr':'📺','radarr':'🎬','lidarr':'🎵','readarr':'📚','prowlarr':'🔍','bazarr':'💬',
  // Communication
  'mail':'📧','email':'📧','roundcube':'📧','webmail':'📧',
  'chat':'💬','matrix':'💬','rocket':'💬','discord':'💬','signal':'💬',
  // Wiki / Notes
  'wiki':'📚','bookstack':'📚','outline':'📝','joplin':'📝','notion':'📝','notes':'📝',
  'blog':'✍️','wordpress':'✍️','ghost':'✍️',
  // Printing
  'print':'🖨','printer':'🖨','cups':'🖨',
  // Feeds
  'freshrss':'📰','rss':'📰','news':'📰','reader':'📰','inoreader':'📰',
  // Search
  'searx':'🔍','search':'🔍','whoogle':'🔍',
  // AI
  'ollama':'🤖','ai':'🤖','gpt':'🤖','llm':'🤖','openai':'🤖',
  // Backup
  'backup':'💾','duplicati':'💾','restic':'💾','restore':'💾',
  // Server generic
  'server':'🖥','linux':'🐧','ubuntu':'🐧','admin':'⚙️','panel':'⚙️',
  // Shopping / Catalog
  'catalog':'🗂','catalog site':'🗂','shop':'🛒','store':'🏪','ecommerce':'🛒','cart':'🛒',
  // Maps / GPS
  'map':'🗺','gps':'📍','location':'📍','weather':'🌤',
  // Calendar / Tasks
  'calendar':'📅','tasks':'✅','todo':'✅','planner':'📅',
  // Games
  'game':'🎮','gaming':'🎮','steam':'🎮',
  // Finance
  'invoice':'💰','finance':'💰','account':'💰','billing':'💰','budget':'💰',
};

// Suggest icon based on label text (when user is typing label)
let _iconSuggested = false;
function suggestIconFromLabel() {
  if (_editingCard) return; // Don't override when editing
  const raw = document.getElementById('modal-label').value.toLowerCase();
  const ico = _lookupIconKeyword(raw);
  if (ico && ico !== '🔗') {
    _applyIconSuggestion(ico);
  }
}
// Also suggest from URL (domain name often reveals service)
function suggestIconFromUrl() {
  if (_editingCard) return;
  const url = document.getElementById('modal-url').value.toLowerCase();
  const label = document.getElementById('modal-label').value.toLowerCase();
  const combined = url + ' ' + label;
  const ico = _lookupIconKeyword(combined);
  if (ico && ico !== '🔗') {
    _applyIconSuggestion(ico);
  }
}
function _lookupIconKeyword(text) {
  for (const [k, v] of Object.entries(ICON_KEYWORDS)) {
    if (text.includes(k)) return v;
  }
  return null;
}
function _applyIconSuggestion(ico) {
  if (_selectedIcon !== ico) {
    selectIconValue(ico);
    document.getElementById('icon-suggest-hint').style.display = 'inline';
    _iconSuggested = true;
  }
}
// Select icon by value (without needing a DOM element)
function selectIconValue(ico) {
  _selectedIcon = ico;
  const prev = document.getElementById('icon-preview');
  if (prev) prev.textContent = ico;
  buildIconPicker();
}

function buildIconPicker() {
  const tabsEl = document.getElementById('icon-cat-tabs');
  const pickerEl = document.getElementById('icon-picker');
  tabsEl.innerHTML = Object.keys(ICON_CATS).map(cat =>
    `<span class="icon-cat-tab ${cat===_activeIconCat?'active':''}" onclick="switchIconCat('${cat}')">${cat}</span>`
  ).join('');
  pickerEl.innerHTML = (ICON_CATS[_activeIconCat]||[]).map(ico =>
    `<span class="icon-opt ${ico===_selectedIcon?'selected':''}" onclick="selectIcon(this,'${ico}')">${ico}</span>`
  ).join('');
}
function switchIconCat(cat) {
  _activeIconCat = cat;
  buildIconPicker();
}
function selectIcon(el, ico) {
  document.querySelectorAll('.icon-opt').forEach(e=>e.classList.remove('selected'));
  el.classList.add('selected');
  _selectedIcon = ico;
  document.getElementById('icon-preview').textContent = ico;
  // Hide suggestion hint when user manually picks
  document.getElementById('icon-suggest-hint').style.display='none';
  _iconSuggested = false;
}

// ===== THEME VARIANTS =====
const VARIANTS = {
  // ── Windows Retro ── (retro era gets retro wallpapers: the tiled/patterned ones fit perfectly)
  win9x:    [{v:'w-teal',l:'🟦 Teal (Classic)'},{v:'w-navy',l:'🔵 Navy Blue'},{v:'w-bricks',l:'🧱 Red Brick'},{v:'w-forest',l:'🟢 Forest Green'},{v:'w-metal',l:'⚙️ Metal'},{v:'w-purple',l:'🟣 Purple'}],
  win2k:    [{v:'default',l:'🔧 3D Pipes'},{v:'w-w2k-steel',l:'🔵 Steel Blue'},{v:'w-w2k-olive',l:'🟢 Olive Green'},{v:'w-w2k-corp',l:'💙 Corporate Dark'},{v:'w-w2k-graphite',l:'⬛ Graphite'}],
  winxp:    [{v:'default',l:'🌄 Bliss'},{v:'winxp2',l:'🐟 Aquarium'},{v:'w-xp-space',l:'🚀 Space (Plus!)'},{v:'w-xp-nature',l:'🌿 Nature (Plus!)'},{v:'w-xp-energy',l:'⚡ Energy Blue'},{v:'w-xp-crystal',l:'💎 Crystal'}],
  winphone: [{v:'default',l:'⬛ Metro Dark'},{v:'w-wp-dark',l:'⬛ Charcoal'},{v:'w-wp-cyan',l:'🔵 Cyan'},{v:'w-wp-amber',l:'🟠 Amber'},{v:'w-wp-slate',l:'🔷 Slate Blue'}],
  startmenu:[{v:'default',l:'🪟 Luna Blue'},{v:'w-navy',l:'🔵 Navy'},{v:'w-bricks',l:'🧱 Bricks'},{v:'w-clouds',l:'☁️ Clouds'},{v:'w-forest',l:'🟢 Forest'}],
  // ── Mac / Apple ────────────────────────────────────────────────────────
  macos:    [{v:'default',l:'🌅 Sonoma Orbs'},{v:'w-mac-bigsur',l:'🌅 Big Sur'},{v:'w-mac-monterey',l:'🟣 Monterey'},{v:'w-mac-sequoia',l:'🌲 Sequoia'},{v:'w-mac-ventura',l:'🔴 Ventura'}],
  macos9:   [{v:'default',l:'🌈 Platinum'},{v:'w-clouds',l:'☁️ Mac Clouds'},{v:'w-metal',l:'⚙️ Brushed Metal'},{v:'w-navy',l:'🔵 Mac Classic'}],
  aqua:     [{v:'default',l:'💧 Silk Ribbons'},{v:'w-aqua-ripple',l:'🌊 Aqua Ripple'},{v:'w-aqua-silk',l:'💠 Silk'},{v:'w-aqua-cosmos',l:'🌌 Deep Space'}],
  mac9:     [{v:'default',l:'🌈 Platinum Gray'},{v:'w-clouds',l:'☁️ Mac Clouds'},{v:'w-metal',l:'⚙️ Brushed Metal'},{v:'w-navy',l:'🔵 Mac Classic'}],
  macosx:   [{v:'default',l:'💧 Aqua Blue'},{v:'w-aqua-ripple',l:'🌊 Aqua Ripple'},{v:'w-aqua-silk',l:'💠 Silk'},{v:'w-aqua-cosmos',l:'🌌 Deep Space'}],
  osxtiger: [{v:'default',l:'🐯 Deep Space'},{v:'w-tiger-aqua',l:'💧 Tiger Aqua'},{v:'w-tiger-spectrum',l:'🌈 Spectrum'},{v:'w-tiger-metal',l:'⚙️ Brushed Aluminum'},{v:'w-tiger-garden',l:'🌿 Garden'}],
  // ── iOS / Modern Mobile ────────────────────────────────────────────────
  ios26:    [{v:'default',l:'✨ Swirling Blobs'},{v:'w-ios-dusk',l:'🌅 Dusk'},{v:'w-ios-midnight',l:'🌑 Midnight'},{v:'w-ios-celestial',l:'💙 Celestial'},{v:'w-ios-rose',l:'🌸 Rose'}],
  jellybean:[{v:'default',l:'🌌 Nexus Live'},{v:'jellybean2',l:'💡 Circuit Board'},{v:'w-jb-galaxy',l:'🌌 Galaxy'},{v:'w-jb-holo',l:'💙 Holo Blue'},{v:'w-jb-spectrum',l:'🌈 Spectrum'}],
  // ── Linux ─────────────────────────────────────────────────────────────
  ubuntu:   [{v:'default',l:'🟠 GNOME Yaru'},{v:'w-ub-aubergine',l:'🟣 Aubergine'},{v:'w-ub-yaru',l:'🟠 Yaru Orange'},{v:'w-ub-dark',l:'⬛ Yaru Dark'}],
  // ── Palm / Pocket ─────────────────────────────────────────────────────
  palmos:   [{v:'default',l:'📟 Palm LCD'},{v:'palmtreo',l:'📱 Palm Treo'},{v:'w-stripes',l:'〰️ Stripes'},{v:'w-clouds',l:'☁️ Clouds'},{v:'w-metal',l:'⚙️ Metal'}],
  pocketpc: [{v:'default',l:'📲 WM6 Bubbles'},{v:'w-wp-slate',l:'🔷 Steel Slate'},{v:'w-w2k-corp',l:'💙 Corporate Blue'},{v:'w-jb-holo',l:'💙 Holo Blue'},{v:'w-metal',l:'⚙️ Metal'}],
  webos:    [{v:'default',l:'🌙 Glowing Orbs'},{v:'w-wos-orion',l:'🌌 Orion Dark'},{v:'w-wos-ripple',l:'🌊 Ripple'},{v:'w-wos-neon',l:'💜 Neon'},{v:'w-wos-dark',l:'⬛ Dark Matter'}],
  // ── Classic Retro ─────────────────────────────────────────────────────
  c64:      [{v:'default',l:'🕹 BASIC Blue'},{v:'w-c64-amber',l:'🟡 Amber Phosphor'},{v:'w-c64-green',l:'🟢 Green Phosphor'},{v:'w-c64-demo',l:'🎆 Demo Scene'},{v:'w-c64-spectrum',l:'🌈 Spectrum'}],
  os2:      [{v:'default',l:'🗄 Warp 4'},{v:'w-os2-warp',l:'💙 Blue Warp'},{v:'w-os2-steel',l:'⚙️ Steel'},{v:'w-os2-olive',l:'🟢 Olive'},{v:'w-os2-dark',l:'⬛ Dark CRT'}],
  solaris:  [{v:'default',l:'☀️ Solaris'},{v:'w-sum-sunset',l:'🌅 Sunburst'},{v:'w-xp-energy',l:'⚡ Energy'},{v:'w-jb-beam',l:'🔵 Teal'},{v:'w-mac-midnight',l:'🌑 Night'}],
  // ── Professional / Lifestyle ──────────────────────────────────────────
  professional:[{v:'default',l:'👔 Corporate'},{v:'w-pro-carbon',l:'⬛ Carbon Fiber'},{v:'w-pro-slate',l:'🔷 Slate'},{v:'w-pro-midnight',l:'🌑 Midnight'},{v:'w-pro-steel',l:'⚙️ Steel Sheen'}],
  cute:    [{v:'default',l:'🌸 Rose Gradient'},{v:'cute-hearts',l:'💕 Hearts & Sparkles'},{v:'w-cute-bubblegum',l:'💗 Bubblegum'},{v:'w-cute-lavender',l:'💜 Lavender'},{v:'w-cute-rose',l:'🌹 Rose Gold'}],
  // ── Seasonal ─────────────────────────────────────────────────────────
  spring:   [{v:'default',l:'🌸 Cherry Petals'},{v:'spring-rain',l:'🌧️ Spring Rain'},{v:'spring-meadow',l:'🌿 Spring Meadow'},{v:'w-spr-rose',l:'🌸 Rose'},{v:'w-spr-meadow',l:'🌿 Meadow'}],
  summer:   [{v:'default',l:'🌊 Beach Waves'},{v:'summer-fireflies',l:'🌙 Tropical Fireflies'},{v:'summer-sunset',l:'🌅 Ocean Sunset'},{v:'w-sum-ocean',l:'🌊 Deep Ocean'},{v:'w-sum-sunset',l:'🌅 Sunset'}],
  autumn:   [{v:'default',l:'🍂 Falling Leaves'},{v:'autumn-forest',l:'🍁 Maple Forest'},{v:'autumn-moon',l:'🌕 Harvest Moon'},{v:'w-aut-amber',l:'🍂 Amber Fall'},{v:'w-aut-rust',l:'🦊 Rust'}],
  winter:   [{v:'default',l:'❄️ Snowfall'},{v:'winter-aurora',l:'🌌 Aurora Borealis'},{v:'winter-blizzard',l:'❄️ Blizzard'},{v:'w-win-ice',l:'🧊 Ice Blue'},{v:'w-win-arctic',l:'🌨 Arctic White'}],
  thanksgiving:[{v:'default',l:'🦃 Harvest'},{v:'w-thx-harvest',l:'🍁 Golden Harvest'},{v:'w-thx-maple',l:'🍂 Maple Blaze'},{v:'w-thx-corn',l:'🌽 Corn Gold'},{v:'w-thx-plum',l:'🍇 Wild Plum'}],
  july4:    [{v:'default',l:'🎆 Fireworks'},{v:'w-j4-blaze',l:'🔥 Blaze Red'},{v:'w-j4-glory',l:'🔵 Glory Blue'},{v:'w-j4-white',l:'⚪ Star White'},{v:'w-j4-dusk',l:'🌑 Night Sky'}],
  christmas:[{v:'default',l:'❄️ Holy Night'},{v:'christmas-night',l:'🎄 Christmas Night'},{v:'w-xmas-holly',l:'🌲 Holly Green'},{v:'w-xmas-cranberry',l:'❤️ Cranberry'},{v:'w-xmas-gold',l:'✨ Gold'}],
  custom:   [{v:'default',l:'🎨 Custom Theme'}],
  miku:     [{v:'default',l:'🎵 Floating Notes'},{v:'miku-concert',l:'🎤 Concert Stage'},{v:'miku-cyber',l:'🌐 Cyber Rain'},{v:'w-miku-deep',l:'🌊 Deep Teal'},{v:'w-miku-sakura',l:'🌸 Sakura Dream'}],
  // ── Hidden retro themes ───────────────────────────────────────────────
  amiga:    [{v:'default',l:'⬜ Workbench Gray'},{v:'wall-amiga-copper',l:'🌈 Copper Bars'},{v:'wall-amiga-kickstart',l:'🔴 Kickstart 1.3'},{v:'wall-amiga-wb2',l:'🔵 Workbench 2.0'},{v:'wall-amiga-wb3',l:'⬜ Workbench 3.x'}],
  nextstep: [{v:'default',l:'⬛ Dark Workspace'},{v:'wall-next-marble',l:'🪨 Marble'},{v:'wall-next-workspace',l:'⬛ Grid Workspace'},{v:'wall-next-magenta',l:'💜 Magenta Glow'},{v:'wall-next-blue',l:'💙 Deep Blue'}],
  beos:     [{v:'default',l:'🟡 Tan Desktop'},{v:'wall-beos-blue',l:'💙 Blue Wave'},{v:'wall-beos-space',l:'🌌 Space'},{v:'wall-beos-gold',l:'✨ Gold Shimmer'},{v:'wall-beos-haiku',l:'🌿 Haiku Green'}],
  norton:   [{v:'default',l:'💙 Classic Blue'},{v:'wall-norton-cyan',l:'🔵 Cyan Grid'},{v:'wall-norton-amber',l:'🟡 Amber Phosphor'},{v:'wall-norton-green',l:'🟢 Green Phosphor'},{v:'wall-norton-matrix',l:'💚 Matrix'}],
  atarist:  [{v:'default',l:'🕹 TOS Gray'},{v:'wall-atari-mint',l:'🔵 Mint'},{v:'wall-atari-rainbow',l:'🌈 Rainbow'},{v:'wall-atari-falcon',l:'💙 Falcon Blue'},{v:'wall-atari-dark',l:'⬛ Dark'}],
  irix:     [{v:'default',l:'🌊 IRIX Teal'},{v:'wall-irix-indigo',l:'💜 Indigo Purple'},{v:'wall-irix-impact',l:'💙 Impact Blue'},{v:'wall-irix-onyx',l:'⬛ Onyx Black'},{v:'wall-irix-teal',l:'🔵 Deep Teal'}],
  // ── Palm V / Pilot standalone variants ───────────────────────────────
  palmv:    [{v:'default',l:'📟 Green LCD'},{v:'wall-palmv-amber',l:'🟡 Amber LCD'},{v:'wall-palmv-blue',l:'💙 Blue Backlit'},{v:'wall-palmv-dark',l:'⬛ Dark Mode'}],
  palmpilot:[{v:'default',l:'📟 Pilot LCD'},{v:'wall-pilot-green',l:'🟢 Green Phosphor'},{v:'wall-pilot-dark',l:'⬛ Dark Mode'},{v:'wall-pilot-blue',l:'💙 Blue Steel'},{v:'wall-pilot-warm',l:'🟡 Warm Ivory'},{v:'wall-pilot-mono',l:'⬜ True Mono'}],
};

const themeClasses=[
  'theme-aqua','theme-ios26','theme-winxp','theme-winxp2','theme-win2k','theme-winphone',
  'theme-jellybean','theme-jellybean2','theme-win9x','theme-osxtiger',
  'theme-palmos','theme-palmtreo','theme-palmv','theme-palmpilot','theme-pocketpc',
  'theme-macos','theme-macos9','theme-mac9','theme-macosx','theme-ubuntu','theme-custom',
  'theme-c64','theme-amiga','theme-nextstep','theme-beos',
  'theme-os2','theme-webos','theme-norton','theme-atarist','theme-irix','theme-solaris',
  'theme-miku','theme-miku-concert','theme-miku-cyber',
  'theme-professional','theme-cute','theme-cute-hearts',
  'theme-spring','theme-spring-rain','theme-spring-meadow',
  'theme-summer','theme-summer-fireflies','theme-summer-sunset',
  'theme-autumn','theme-autumn-forest','theme-autumn-moon',
  'theme-winter','theme-winter-aurora','theme-winter-blizzard',
  'theme-thanksgiving','theme-july4','theme-christmas','theme-christmas-night'];
const wallClasses=['wall-circles','wall-sandstone','wall-forest','wall-purple','wall-navy','wall-bricks','wall-clouds','wall-metal','wall-aurora','wall-nebula','wall-matrix','wall-lava','wall-grid','wall-waves','wall-diamonds','wall-stripes','wall-starfield','wall-plasma',
  'wall-xp-space','wall-xp-nature','wall-xp-energy','wall-xp-crystal','wall-xp-royal','wall-xp-zune','wall-xp-luna',
  'wall-wp-dark','wall-wp-cyan','wall-wp-magenta','wall-wp-lime','wall-wp-amber','wall-wp-slate',
  'wall-tiger-aqua','wall-tiger-spectrum','wall-tiger-metal','wall-tiger-quartz','wall-tiger-garden','wall-tiger-beach',
  'wall-mac-bigsur','wall-mac-monterey','wall-mac-sequoia','wall-mac-midnight','wall-mac-ventura',
  'wall-aqua-ripple','wall-aqua-silk','wall-aqua-cosmos','wall-aqua-brushed',
  'wall-ios-dusk','wall-ios-midnight','wall-ios-celestial','wall-ios-rose','wall-ios-azure',
  'wall-jb-galaxy','wall-jb-holo','wall-jb-spectrum','wall-jb-beam','wall-jb-phase',
  'wall-ub-aubergine','wall-ub-yaru','wall-ub-focal','wall-ub-dark','wall-ub-bionic',
  'wall-c64-amber','wall-c64-green','wall-c64-demo','wall-c64-spectrum',
  'wall-wos-orion','wall-wos-ripple','wall-wos-neon','wall-wos-dark',
  'wall-os2-warp','wall-os2-steel','wall-os2-olive','wall-os2-dark',
  'wall-w2k-steel','wall-w2k-olive','wall-w2k-corp','wall-w2k-graphite',
  'wall-cute-bubblegum','wall-cute-lavender','wall-cute-cotton','wall-cute-rose',
  'wall-pro-carbon','wall-pro-slate','wall-pro-midnight','wall-pro-steel',
  'wall-spr-meadow','wall-spr-sky','wall-spr-rose','wall-spr-lavender','wall-spr-sunrise',
  'wall-sum-ocean','wall-sum-sand','wall-sum-sunset','wall-sum-tropical',
  'wall-aut-amber','wall-aut-rust','wall-aut-mahog','wall-aut-pumpkin',
  'wall-win-ice','wall-win-arctic','wall-win-silver','wall-win-tundra',
  'wall-thx-harvest','wall-thx-maple','wall-thx-corn','wall-thx-plum',
  'wall-j4-blaze','wall-j4-glory','wall-j4-white','wall-j4-dusk',
  'wall-xmas-holly','wall-xmas-cranberry','wall-xmas-gold','wall-xmas-frost',
  'wall-miku-deep','wall-miku-pink','wall-miku-stage','wall-miku-cyber','wall-miku-sakura',
  'wall-amiga-kickstart','wall-amiga-wb2','wall-amiga-copper','wall-amiga-wb3',
  'wall-next-marble','wall-next-workspace','wall-next-magenta','wall-next-blue',
  'wall-beos-blue','wall-beos-space','wall-beos-gold','wall-beos-haiku',
  'wall-norton-cyan','wall-norton-amber','wall-norton-green','wall-norton-matrix',
  'wall-atari-mint','wall-atari-rainbow','wall-atari-falcon','wall-atari-dark',
  'wall-irix-indigo','wall-irix-impact','wall-irix-onyx','wall-irix-teal',
  'wall-palmv-amber','wall-palmv-blue','wall-palmv-dark','wall-palmv-gray','wall-palmv-red',
  'wall-pilot-green','wall-pilot-dark','wall-pilot-blue','wall-pilot-warm','wall-pilot-mono'];

let _currentBaseTheme='win9x', _currentVariant='default';
let _variantRestoreTimer=null; // guards against stale delayed variant-restore overriding a newer theme switch

function _saveState(patch) {
  // Write a key-value patch to server state (dash_state.json) and mirror to localStorage
  Object.keys(patch).forEach(k => {
    if (patch[k] === null) localStorage.removeItem(k);
    else localStorage.setItem(k, typeof patch[k] === 'string' ? patch[k] : JSON.stringify(patch[k]));
  });
  fetch('save_state.php', {method:'POST', keepalive:true, headers:{'Content-Type':'application/json'}, body:JSON.stringify(patch)}).catch(()=>{});
}
function onThemeChange(base) {
  // Cancel any pending variant-restore from a previous theme switch so it doesn't
  // override this new selection (race condition: user clicks two themes quickly).
  clearTimeout(_variantRestoreTimer); _variantRestoreTimer = null;
  _currentBaseTheme = base; _currentVariant = 'default';
  _playThemeSound(base);
  _saveState({'hp-theme': base});
  _patchActiveProfile({theme: base, wallpaper_variant:''});
  updateVariantDropdown(base);
  applyTheme(base);
  if (base === 'win98' || base === 'win9x') {
    applyWallpaper(localStorage.getItem('hp-wall') || 'teal');
    return;
  }
  // Restore saved background/variant for this theme (server state first, then localStorage)
  const savedV = PHP_STATE['variant-'+base] || localStorage.getItem('variant-'+base);
  if (savedV && savedV !== 'default') {
    const sel = document.getElementById('variant-sel');
    if (sel) sel.value = savedV;
    _variantRestoreTimer = setTimeout(() => { _variantRestoreTimer = null; onVariantChange(savedV); }, 80);
  }
}

function _getNamedBgList(theme) {
  const raw = SERVER_BG[theme];
  if (!raw) return [];
  if (Array.isArray(raw)) return raw; // new array format [{name,type,url}]
  if (raw.url) return [{name:'Custom',type:raw.type,url:raw.url}]; // legacy single object
  return [];
}

function updateVariantDropdown(base) {
  const sel=document.getElementById('variant-sel');
  if(!sel) return;
  let variants=VARIANTS[base]||[{v:'default',l:'Default'}];
  // Append named custom BG variants from server config
  const namedBgs=_getNamedBgList(base);
  if(namedBgs.length>0){
    namedBgs.forEach((bg,i)=>{
      const ico=bg.type==='iframe_url'?'🌐':(bg.type?.startsWith('image')?'🖼':'🎬');
      variants=variants.concat([{v:'cbg-'+i,l:ico+' '+(bg.name||'Custom '+(i+1))}]);
    });
  }
  sel.innerHTML=variants.map(v=>`<option value="${v.v}">${v.l}</option>`).join('');
  // For win98/win9x the first option is a wall-* variant, not 'default'
  if(base==='win98'||base==='win9x'){
    const saved='w-'+(localStorage.getItem('hp-wall')||'teal');
    sel.value=saved;
    if(!sel.value)sel.selectedIndex=0;
  } else {
    sel.value='default';
  }
}

function onVariantChange(variant) {
  _currentVariant=variant;
  // Persist to server (cross-device) AND localStorage (instant restore same browser)
  // Always save under the BASE theme key (theme-sel value) to avoid mismatch with
  // variant theme keys like 'winxp2' that aren't in the theme-sel dropdown.
  const _vtsel=document.getElementById('theme-sel');
  const _vbase=_vtsel?_vtsel.value:_currentBaseTheme;
  const vKey='variant-'+_vbase;
  _saveState({[vKey]: variant==='default' ? null : variant});
  _patchActiveProfile({wallpaper_variant: variant==='default'?'':variant});
  if(variant.startsWith('w-')){applyWallpaper(variant.replace('w-',''));stopBgMedia();stopAllCanvases();return;}
  if(variant==='custom'){const bg=getCustomBg(_currentBaseTheme);if(bg)activateBg(bg);return;}
  if(variant.startsWith('cbg-')){
    const idx=parseInt(variant.slice(4));
    // Use the theme-sel value (always the true base theme) for BG lookup,
    // because _currentBaseTheme may be a variant key (e.g. 'winxp2') when
    // the BG was saved under the base key ('winxp').
    const tsel=document.getElementById('theme-sel');
    const bgTheme=tsel?tsel.value:_currentBaseTheme;
    const list=_getNamedBgList(bgTheme);
    if(list[idx])activateBg(list[idx]);
    return;
  }
  stopBgMedia();
  if(variant==='default'){if(_currentBaseTheme==='win98')applyWallpaper(localStorage.getItem('hp-wall')||'teal');else applyTheme(_currentBaseTheme);}
  else applyTheme(variant);
}

function getCustomBg(theme){
  const list=_getNamedBgList(theme);
  if(list.length>0)return list[0];
  const ls=JSON.parse(localStorage.getItem('dash-videos')||'{}');
  if(ls[theme])return{type:'video_url',url:ls[theme]};
  return null;
}
function activateBg(bg){
  const vid=document.getElementById('bg-video'),img=document.getElementById('bg-image'),frm=document.getElementById('bg-iframe');
  if(!bg?.url){stopBgMedia();return;}
  // Normalise URL: absolute (http/https//) stays as-is; relative paths are used directly (they resolve from index.php location)
  const url=bg.url;
  // Stop everything first
  vid.classList.remove('active');vid.pause();vid.src='';
  img.classList.remove('active');img.style.display='none';img.style.backgroundImage='';img.style.backgroundRepeat='';img.style.backgroundSize='';
  frm.classList.remove('active');frm.style.display='none';frm.src='';
  if(bg.type==='iframe_url'){
    frm.src=url;frm.style.display='block';frm.classList.add('active');
  } else if(bg.type?.startsWith('image')){
    img.style.backgroundImage=`url('${url.replace(/'/g,"\\'")}')`;
    // Determine fit mode — 'fit' key preferred; fall back to legacy tile:true boolean
    const fit=bg.fit||(bg.tile?'tile':'fill');
    switch(fit){
      case 'tile':
        // Repeat like classic Windows wallpaper
        img.style.backgroundRepeat='repeat';
        img.style.backgroundSize='auto';
        img.style.backgroundPosition='top left';
        break;
      case 'stretch':
        // Distort image to exact viewport size (100% × 100%)
        img.style.backgroundRepeat='no-repeat';
        img.style.backgroundSize='100% 100%';
        img.style.backgroundPosition='top left';
        break;
      case 'center':
        // Show image at its natural size, centred, no scaling
        img.style.backgroundRepeat='no-repeat';
        img.style.backgroundSize='auto';
        img.style.backgroundPosition='center';
        break;
      default: // 'fill' — scale to cover, no distortion (default)
        img.style.backgroundRepeat='no-repeat';
        img.style.backgroundSize='cover';
        img.style.backgroundPosition='center';
        break;
    }
    img.style.display='block';img.classList.add('active');
  } else {
    vid.src=url;vid.classList.add('active');vid.play().catch(()=>{});
  }
}
function stopAllCanvases(){['_stopPipes','_stopNexus','_stopNexus2','_stopAqua','_stopIos26','_stopAquarium','_stopPalmos','_stopPocketpc','_stopMacos','_stopMacosx','_stopUbuntu','_stopSnow','_stopLeaves','_stopPetals','_stopBliss','_stopFireworks','_stopSummer','_stopWebos','_stopMiku','_stopMiku2','_stopMiku3','_stopCute','_stopSpring2','_stopSpring3','_stopAurora','_stopBlizzard','_stopChristmas','_stopSummer2','_stopSummer3','_stopAutumn2','_stopAutumn3','_stopC64','_stopAmiga','_stopNextstep','_stopBeos','_stopThanksgiving','_stopOsxtiger'].forEach(fn=>{if(window[fn])window[fn]();});document.querySelectorAll('.screensaver-canvas').forEach(c=>c.style.display='none');}
function stopBgMedia(){
  const vid=document.getElementById('bg-video'),img=document.getElementById('bg-image'),frm=document.getElementById('bg-iframe');
  if(vid){vid.classList.remove('active');vid.pause();vid.src='';}
  if(img){img.classList.remove('active');img.style.display='none';img.style.backgroundImage='';img.style.backgroundRepeat='';img.style.backgroundSize='';img.style.backgroundPosition='';}
  if(frm){frm.classList.remove('active');frm.style.display='none';frm.src='';}
}

// ===== APPLY THEME =====
function applyTheme(theme) {
  stopBgMedia(); // always clear any active custom background when switching themes
  stopAllCanvases();
  themeClasses.forEach(c=>{document.body.classList.remove(c);document.getElementById('wallpaper').classList.remove(c);});
  wallClasses.forEach(c=>document.getElementById('wallpaper').classList.remove(c));
  const wp=document.getElementById('wallpaper');
  if(theme==='win98'||theme==='win9x'){applyWallpaper(localStorage.getItem('hp-wall')||'teal');
    if(theme==='win9x'){document.body.classList.add('theme-win9x');wp.classList.add('theme-win9x');}
  }
  else{document.body.classList.add('theme-'+theme);wp.classList.add('theme-'+theme);
    if(theme==='win2k')       {showC('canvas-pipes');     setTimeout(()=>_startPipes&&_startPipes(),100);}
    if(theme==='jellybean')   {showC('canvas-nexus2');    setTimeout(()=>_startNexus2&&_startNexus2(),100);}
    if(theme==='jellybean2')  {showC('canvas-nexus');     setTimeout(()=>_startNexus&&_startNexus(),100);}
    if(theme==='aqua')        {showC('canvas-aqua');      setTimeout(()=>_startAqua&&_startAqua(),100);}
    if(theme==='ios26')       {showC('canvas-ios26');     setTimeout(()=>_startIos26&&_startIos26(),100);}
    if(theme==='winxp')       {showC('canvas-bliss');     setTimeout(()=>_startBliss&&_startBliss(),100);}
    if(theme==='winxp2')      {showC('canvas-aquarium');  setTimeout(()=>_startAquarium&&_startAquarium(),100);}
    if(theme==='palmos'||theme==='palmtreo'){showC('canvas-palmos');setTimeout(()=>_startPalmos&&_startPalmos(theme),100);}
    if(theme==='pocketpc')    {showC('canvas-pocketpc');  setTimeout(()=>_startPocketpc&&_startPocketpc(),100);}
    if(theme==='macos')       {showC('canvas-macos');     setTimeout(()=>_startMacos&&_startMacos(),100);}
    if(theme==='macosx')      {showC('canvas-macosx');    setTimeout(()=>_startMacosx&&_startMacosx(),100);}
    if(theme==='ubuntu')      {showC('canvas-ubuntu');    setTimeout(()=>_startUbuntu&&_startUbuntu(),100);}
    if(theme==='winter')      {showC('canvas-snow');      setTimeout(()=>_startSnow&&_startSnow(),100);}
    if(theme==='autumn')      {showC('canvas-leaves');    setTimeout(()=>_startLeaves&&_startLeaves(),100);}
    if(theme==='spring')      {showC('canvas-petals');    setTimeout(()=>_startPetals&&_startPetals(),100);}
    if(theme==='july4')       {showC('canvas-fireworks'); setTimeout(()=>_startFireworks&&_startFireworks(),100);}
    if(theme==='christmas')      {showC('canvas-snow');       setTimeout(()=>_startSnow&&_startSnow(),100);}
    if(theme==='christmas-night'){showC('canvas-christmas2'); setTimeout(()=>_startChristmas&&_startChristmas(),100);}
    if(theme==='summer')         {showC('canvas-summer');     setTimeout(()=>_startSummer&&_startSummer(),100);}
    if(theme==='summer-fireflies'){showC('canvas-summer2');   setTimeout(()=>_startSummer2&&_startSummer2(),100);}
    if(theme==='summer-sunset')  {showC('canvas-summer3');    setTimeout(()=>_startSummer3&&_startSummer3(),100);}
    if(theme==='autumn-forest')  {showC('canvas-autumn2');    setTimeout(()=>_startAutumn2&&_startAutumn2(),100);}
    if(theme==='autumn-moon')    {showC('canvas-autumn3');    setTimeout(()=>_startAutumn3&&_startAutumn3(),100);}
    if(theme==='winter-aurora')  {showC('canvas-aurora');     setTimeout(()=>_startAurora&&_startAurora(),100);}
    if(theme==='winter-blizzard'){showC('canvas-blizzard');   setTimeout(()=>_startBlizzard&&_startBlizzard(),100);}
    if(theme==='spring-rain')    {showC('canvas-spring2');    setTimeout(()=>_startSpring2&&_startSpring2(),100);}
    if(theme==='spring-meadow')  {showC('canvas-spring3');    setTimeout(()=>_startSpring3&&_startSpring3(),100);}
    if(theme==='webos')          {showC('canvas-webos');      setTimeout(()=>_startWebos&&_startWebos(),100);}
    if(theme==='miku')           {showC('canvas-miku');       setTimeout(()=>_startMiku&&_startMiku(),100);}
    if(theme==='miku-concert')   {showC('canvas-miku2');      setTimeout(()=>_startMiku2&&_startMiku2(),100);}
    if(theme==='miku-cyber')     {showC('canvas-miku3');      setTimeout(()=>_startMiku3&&_startMiku3(),100);}
    if(theme==='cute'||theme==='cute-hearts'){showC('canvas-cute');setTimeout(()=>_startCute&&_startCute(),100);}
    if(theme==='c64')            {showC('canvas-c64');       setTimeout(()=>_startC64&&_startC64(),100);}
    if(theme==='amiga')          {showC('canvas-amiga');     setTimeout(()=>_startAmiga&&_startAmiga(),100);}
    if(theme==='nextstep')       {showC('canvas-nextstep');  setTimeout(()=>_startNextstep&&_startNextstep(),100);}
    if(theme==='beos')           {showC('canvas-beos');      setTimeout(()=>_startBeos&&_startBeos(),100);}
    if(theme==='thanksgiving')   {showC('canvas-thanksgiving');setTimeout(()=>_startThanksgiving&&_startThanksgiving(),100);}
    if(theme==='osxtiger')       {showC('canvas-osxtiger');  setTimeout(()=>_startOsxtiger&&_startOsxtiger(),100);}
    if(theme==='custom')         applyCustomTheme();
  }
  _saveState({'hp-theme': theme});
  const tsel=document.getElementById('theme-sel');
  const baseMap={winxp2:'winxp',jellybean2:'jellybean',palmtreo:'palmos'};
  if(tsel){const base=baseMap[theme]||theme;if(tsel.value!==base)tsel.value=base;}
}
function showC(id){const el=document.getElementById(id);if(el)el.style.display='block';}

// ===== WALLPAPERS =====
function applyWallpaper(wall){const wp=document.getElementById('wallpaper');wallClasses.forEach(c=>wp.classList.remove(c));themeClasses.forEach(c=>wp.classList.remove(c));if(wall!=='teal')wp.classList.add('wall-'+wall);_saveState({'hp-wall':wall});_patchActiveProfile({wallpaper_variant:wall});}

// ===== CUSTOM THEME VARS =====
function applyCustomTheme(){const ct=JSON.parse(localStorage.getItem('dash-custom-theme')||'null')||CUSTOM_THEME_SERVER||{};if(!Object.keys(ct).length)return;const r=document.documentElement.style;['bg','card_bg','border_light','border_dark','card_text','hover_bg','hover_text','sec_from','sec_to','sec_text','radius','font'].forEach(k=>{if(ct[k])r.setProperty('--ct-'+k.replace('_','-'),ct[k]+(k==='radius'?'px':''));});if(ct.wallpaper&&ct.wallpaper!=='none')applyWallpaper(ct.wallpaper);}

// ===== SIZE =====
function applySize(val){const tS=document.getElementById('size-slider-top'),tL=document.getElementById('size-label-top');if(tS&&tS.value!=val)tS.value=val;if(tL)tL.textContent=val+'%';val=parseInt(val);const sv=document.getElementById('services');sv.style.transform='scale('+val/100+')';sv.style.transformOrigin='top left';sv.style.marginBottom=((val/100-1)*sv.scrollHeight)+'px';_saveState({'hp-size':String(val)});_patchActiveProfile({size:val});}

// ===== CLOCKS =====
let _clock24h = (PHP_STATE['hp-clock-24h']==='1')||(localStorage.getItem('hp-clock-24h')==='1');
function toggleClock24h(){
  _clock24h=!_clock24h;
  localStorage.setItem('hp-clock-24h',_clock24h?'1':'0');
  _saveState({'hp-clock-24h':_clock24h?'1':null});
  updateClock();updateTaskbarClock();
}
function updateClock(){
  const now=new Date(),h12=!_clock24h;
  const s=now.toLocaleString('en-US',{weekday:'short',month:'short',day:'numeric',hour:'numeric',minute:'2-digit',hour12:h12});
  document.getElementById('clock').textContent=s;
  const t=now.toLocaleTimeString('en-US',{hour:'numeric',minute:'2-digit',hour12:h12});
  ['macos-clock-bar','m9-clock-bar','mac9-clock-bar','macosx-clock-bar','ubuntu-clock','osxtiger-clock'].forEach(id=>{const el=document.getElementById(id);if(el)el.textContent=t;});
}
function updateTaskbarClock(){const el=document.getElementById('taskbar-clock');if(el){const now=new Date();el.textContent=now.toLocaleTimeString('en-US',{hour:'numeric',minute:'2-digit',hour12:!_clock24h});}}

// ===== CLOCK WIDGET =====
let _cwMode = localStorage.getItem('cw-mode') || 'digital';
function _initClockWidget(){
  const w=document.getElementById('stat-clock');if(!w)return;
  _applyCwMode();
  setInterval(_tickClock,1000);
  _tickClock();
}
function _tickClock(){
  const now=new Date();
  // digital display
  const hm=document.getElementById('cw-hm'),cs=document.getElementById('cw-s'),cd=document.getElementById('cw-date');
  if(hm)hm.textContent=now.toLocaleTimeString('en-US',{hour:'2-digit',minute:'2-digit',hour12:!_clock24h}).replace(/^24:/,'00:');
  if(cs)cs.textContent=':'+(now.getSeconds()<10?'0':'')+now.getSeconds();
  if(cd)cd.textContent=now.toLocaleDateString('en-US',{weekday:'long',month:'long',day:'numeric'});
  // analog hands
  const h=now.getHours()%12,m=now.getMinutes(),s=now.getSeconds();
  const hDeg=(h*30)+(m*0.5),mDeg=(m*6)+(s*0.1),sDeg=s*6;
  const hEl=document.getElementById('cw-hour'),mEl=document.getElementById('cw-min'),sEl=document.getElementById('cw-sec');
  if(hEl)hEl.style.transform='rotate('+hDeg+'deg)';
  if(mEl)mEl.style.transform='rotate('+mDeg+'deg)';
  if(sEl)sEl.style.transform='rotate('+sDeg+'deg)';
}
function toggleClockMode(){
  _cwMode=(_cwMode==='digital'?'analog':'digital');
  localStorage.setItem('cw-mode',_cwMode);
  _applyCwMode();
}
function _applyCwMode(){
  const dw=document.querySelector('.clock-digital-wrap'),af=document.getElementById('cw-analog'),btn=document.getElementById('cw-mode-btn');
  if(!dw)return;
  if(_cwMode==='analog'){dw.style.display='none';if(af)af.style.display='block';if(btn)btn.textContent='Switch to Digital';}
  else{dw.style.display='block';if(af)af.style.display='none';if(btn)btn.textContent='Switch to Analog';}
}

// ===== WEATHER WIDGET =====
let _wxUnit=localStorage.getItem('wx-unit')||'F';
let _wxZip=localStorage.getItem('wx-zip')||'';
let _wxData=null;
let _wxRefreshTimer=null;
const _WX_ICONS={113:'☀️',116:'⛅',119:'☁️',122:'☁️',143:'🌫️',176:'🌦️',179:'🌨️',182:'🌧️',185:'🌧️',200:'⛈️',227:'❄️',230:'❄️',248:'🌫️',260:'🌫️',263:'🌦️',266:'🌦️',281:'🌧️',284:'🌧️',293:'🌦️',296:'🌦️',299:'🌧️',302:'🌧️',305:'🌧️',308:'🌧️',311:'🌧️',314:'🌧️',317:'🌨️',320:'🌨️',323:'🌨️',326:'🌨️',329:'❄️',332:'❄️',335:'❄️',338:'❄️',350:'🌧️',353:'🌦️',356:'🌧️',359:'🌧️',362:'🌨️',365:'🌨️',368:'🌨️',371:'❄️',374:'🌧️',377:'🌧️',386:'⛈️',389:'⛈️',392:'🌩️',395:'⛈️'};
function _wxIcon(code){return _WX_ICONS[code]||'🌡️';}
async function fetchWeather(){
  const inp=document.getElementById('weather-zip');if(!inp)return;
  const z=inp.value.trim();if(!z)return;
  _wxZip=z;localStorage.setItem('wx-zip',z);
  document.getElementById('weather-msg').textContent='Loading…';
  document.getElementById('weather-msg').style.display='block';
  try{
    const r=await fetch('https://wttr.in/'+encodeURIComponent(z)+'?format=j1');
    if(!r.ok)throw new Error('HTTP '+r.status);
    _wxData=await r.json();
    _renderWeather();
    if(_wxRefreshTimer)clearInterval(_wxRefreshTimer);
    _wxRefreshTimer=setInterval(fetchWeather,30*60*1000);
  }catch(e){
    document.getElementById('weather-msg').textContent='Could not load weather. Check your location.';
  }
}
function setWeatherUnit(u){
  _wxUnit=u;localStorage.setItem('wx-unit',u);
  document.getElementById('wu-f').classList.toggle('active',u==='F');
  document.getElementById('wu-c').classList.toggle('active',u==='C');
  if(_wxData)_renderWeather();
}
function _wxTemp(c){return _wxUnit==='F'?Math.round(c*9/5+32):Math.round(c);}
function _renderWeather(){
  const d=_wxData;if(!d)return;
  const msg=document.getElementById('weather-msg');if(msg)msg.style.display='none';
  const body=document.getElementById('weather-body');if(!body)return;
  const cur=d.current_condition[0];
  const descObj=cur.weatherDesc&&cur.weatherDesc[0]?cur.weatherDesc[0].value:'';
  const code=parseInt(cur.weatherCode||113);
  const icon=_wxIcon(code);
  const tempC=parseFloat(cur.temp_C);
  const temp=_wxTemp(tempC);
  const hum=cur.humidity||'--';
  const wind=Math.round((cur.windspeedKmph||0)*0.621);
  // build forecast (up to 3 days)
  const days=d.weather||[];
  const DAY_NAMES=['Sun','Mon','Tue','Wed','Thu','Fri','Sat'];
  const fHtml=days.slice(0,3).map(day=>{
    const dt=new Date(day.date+'T12:00:00');
    const name=DAY_NAMES[dt.getDay()];
    const dc=parseInt(day.hourly&&day.hourly[4]?day.hourly[4].weatherCode:113);
    const lo=_wxTemp(parseFloat(day.mintempC));
    const hi=_wxTemp(parseFloat(day.maxtempC));
    return `<div class="wf-day"><span class="wf-icon">${_wxIcon(dc)}</span><span class="wf-name">${name}</span><span class="wf-temps">${lo}°/${hi}°</span></div>`;
  }).join('');
  // find existing weather content or create it
  let wc=body.querySelector('.weather-content-inner');
  if(!wc){
    wc=document.createElement('div');
    wc.className='weather-content-inner';
    // insert before zip row
    const zr=body.querySelector('.weather-zip-row');
    if(zr)body.insertBefore(wc,zr);else body.prepend(wc);
  }
  wc.innerHTML=`
    <div class="weather-current">
      <span class="weather-icon-big">${icon}</span>
      <div>
        <div class="weather-temp-big">${temp}<span class="weather-unit">°${_wxUnit}</span></div>
        <div class="weather-desc-line">${descObj}</div>
        <div class="weather-meta-line">💧${hum}%  💨${wind}mph</div>
      </div>
    </div>
    <div class="weather-forecast">${fHtml}</div>`;
}
function _initWeatherWidget(){
  const w=document.getElementById('stat-weather');if(!w)return;
  // restore unit
  setWeatherUnit(_wxUnit);
  // restore zip and auto-fetch
  const inp=document.getElementById('weather-zip');
  if(_wxZip&&inp){inp.value=_wxZip;fetchWeather();}
}

updateClock();setInterval(updateClock,1000);
updateTaskbarClock();setInterval(updateTaskbarClock,1000);
_initClockWidget();
_initWeatherWidget();

// ===== EXTRA WEATHER CITY WIDGETS =====
const _wxcData={}, _wxcUnit={}, _wxcTimers={};
function fetchWeatherCity(id){
  const inp=document.getElementById('wxc-inp-'+id);if(!inp)return;
  const z=inp.value.trim();if(!z)return;
  const msg=document.getElementById('wxc-msg-'+id);
  if(msg){msg.textContent='Loading…';msg.style.display='block';}
  fetch('https://wttr.in/'+encodeURIComponent(z)+'?format=j1')
    .then(r=>r.ok?r.json():Promise.reject(r.status))
    .then(d=>{_wxcData[id]=d;_renderWeatherCity(id);
      if(_wxcTimers[id])clearInterval(_wxcTimers[id]);
      _wxcTimers[id]=setInterval(()=>fetchWeatherCity(id),30*60*1000);
    })
    .catch(()=>{if(msg){msg.textContent='Could not load. Check location.';msg.style.display='block';}});
}
function setWeatherCityUnit(id,u){
  _wxcUnit[id]=u;
  document.getElementById('wxc-wuf-'+id)?.classList.toggle('active',u==='F');
  document.getElementById('wxc-wuc-'+id)?.classList.toggle('active',u==='C');
  if(_wxcData[id])_renderWeatherCity(id);
}
function _wxcTemp(c,id){const u=_wxcUnit[id]||'F';return u==='F'?Math.round(c*9/5+32):Math.round(c);}
function _renderWeatherCity(id){
  const d=_wxcData[id];if(!d)return;
  const msg=document.getElementById('wxc-msg-'+id);if(msg)msg.style.display='none';
  const body=document.getElementById('wxc-body-'+id);if(!body)return;
  const cur=d.current_condition[0];
  const code=parseInt(cur.weatherCode||113);
  const icon=_wxIcon(code);
  const temp=_wxcTemp(parseFloat(cur.temp_C),id);
  const u=_wxcUnit[id]||'F';
  const hum=cur.humidity||'--';
  const wind=Math.round((cur.windspeedKmph||0)*0.621);
  const desc=(cur.weatherDesc&&cur.weatherDesc[0]?cur.weatherDesc[0].value:'');
  const days=d.weather||[];
  const DAY=['Sun','Mon','Tue','Wed','Thu','Fri','Sat'];
  const fHtml=days.slice(0,3).map(day=>{
    const dt=new Date(day.date+'T12:00:00');
    const dc=parseInt(day.hourly&&day.hourly[4]?day.hourly[4].weatherCode:113);
    const lo=_wxcTemp(parseFloat(day.mintempC),id);
    const hi=_wxcTemp(parseFloat(day.maxtempC),id);
    return `<div class="wf-day"><span class="wf-icon">${_wxIcon(dc)}</span><span class="wf-name">${DAY[dt.getDay()]}</span><span class="wf-temps">${lo}°/${hi}°</span></div>`;
  }).join('');
  let wc=body.querySelector('.weather-content-inner');
  if(!wc){wc=document.createElement('div');wc.className='weather-content-inner';
    const zr=body.querySelector('.weather-zip-row');if(zr)body.insertBefore(wc,zr);else body.prepend(wc);}
  wc.innerHTML=`<div class="weather-current"><span class="weather-icon-big">${icon}</span><div>
    <div class="weather-temp-big">${temp}<span class="weather-unit">°${u}</span></div>
    <div class="weather-desc-line">${desc}</div>
    <div class="weather-meta-line">💧${hum}%  💨${wind}mph</div></div></div>
    <div class="weather-forecast">${fHtml}</div>`;
}
// Init all extra weather city widgets on page load
document.querySelectorAll('.wx-city-widget').forEach(el=>{
  const id=el.dataset.stat.replace('wxc-','');
  const zip=el.dataset.wxcZip||'';
  const unit=el.dataset.wxcUnit||'F';
  _wxcUnit[id]=unit;
  if(zip)fetchWeatherCity(id);
  else{const msg=document.getElementById('wxc-msg-'+id);if(msg)msg.textContent='Enter a ZIP or city above';}
});

// ===== TIMEZONE WIDGETS =====
function _updateTimezoneWidget(id,tz){
  try{
    const now=new Date();
    const hm=new Intl.DateTimeFormat('en-US',{hour:'2-digit',minute:'2-digit',hour12:false,timeZone:tz}).format(now);
    const ss=new Intl.DateTimeFormat('en-US',{second:'2-digit',timeZone:tz}).format(now);
    const dateStr=new Intl.DateTimeFormat('en-US',{weekday:'short',month:'short',day:'numeric',timeZone:tz}).format(now);
    const hmEl=document.getElementById('tz-hm-'+id);
    const sEl=document.getElementById('tz-s-'+id);
    const dEl=document.getElementById('tz-date-'+id);
    if(hmEl)hmEl.textContent=hm;
    if(sEl)sEl.textContent=':'+ss.padStart(2,'0');
    if(dEl)dEl.textContent=dateStr;
  }catch(e){}
}
document.querySelectorAll('.tz-widget').forEach(el=>{
  const id=el.dataset.stat.replace('tz-','');
  const tz=el.dataset.tzZone||'UTC';
  _updateTimezoneWidget(id,tz);
  setInterval(()=>_updateTimezoneWidget(id,tz),1000);
});

// ===== SEARCH =====
const _SEARCH_ENGINES={google:'https://www.google.com/search?q=',bing:'https://www.bing.com/search?q=',duckduckgo:'https://duckduckgo.com/?q=',brave:'https://search.brave.com/search?q=',ecosia:'https://www.ecosia.org/search?q=',kagi:'https://kagi.com/search?q=',yahoo:'https://search.yahoo.com/search?p=',startpage:'https://www.startpage.com/search?q='};
let _activeSearchEngine = PHP_STATE['search_engine'] || '<?= addslashes($_dash_search_engine) ?>';
function doSearch(){const q=document.getElementById('search-input').value.trim();if(q){const base=_SEARCH_ENGINES[_activeSearchEngine]||_SEARCH_ENGINES.google;window.open(base+encodeURIComponent(q),'_blank');}}
// Named layout functions moved to Profiles modal — see openProfilesModal() at bottom of script

// ===== STATS =====
async function fetchStats(){try{const r=await fetch('stats.php'),d=await r.json();
  const c=document.getElementById('w-cpu');if(c)c.textContent='CPU '+(d.cpu!==null?d.cpu+'%':'--');
  const ic=document.getElementById('icon-cpu');if(ic)ic.textContent=d.cpu>=90?'🔴':d.cpu>=70?'🟠':'⚡';
  const ra=document.getElementById('w-ram');if(ra)ra.textContent='RAM '+(d.ram_used||'--')+'GB/'+(d.ram_total||'--')+'GB';
  const drives=d.drives||{};Object.keys(d).forEach(k=>{if(!['cpu','ram_used','ram_total','drives'].includes(k)&&d[k]?.free!==undefined)drives[k]=d[k];});
  Object.keys(drives).forEach(key=>{const info=drives[key],el=document.getElementById('w-'+key),ic=document.getElementById('icon-'+key);if(!el||!info)return;el.textContent=info.free+info.unit+' free';el.style.color=info.used_pct>=95?'#ff4444':info.used_pct>=85?'#ffaa00':'';if(ic)ic.textContent=info.used_pct>=95?'🔴':info.used_pct>=85?'🟠':'💾';});
}catch(e){}}
fetchStats();setInterval(fetchStats,5000);

// ===== RSS WIDGET LOADER =====
async function _loadRssWidget(el){
  const url=el.dataset.rssUrl,max=parseInt(el.dataset.rssMax)||8;
  const body=el.querySelector('.rss-feed-body');
  if(!body||!url)return;
  try{
    const r=await fetch('rss_proxy.php?url='+encodeURIComponent(url)+'&max='+max);
    const d=await r.json();
    if(d.error){body.innerHTML='<div style="color:#ff7777;font-size:12px;padding:8px;">⚠ '+d.error+'</div>';return;}
    body.innerHTML=d.items.map(it=>`
      <div style="padding:7px 0;border-bottom:1px solid rgba(255,255,255,.07);">
        <a href="${it.link}" target="_blank" rel="noopener"
           style="font-size:12px;font-weight:600;color:#7ec8ff;text-decoration:none;line-height:1.3;display:block;"
           onmouseover="this.style.textDecoration='underline'" onmouseout="this.style.textDecoration='none'">
          ${it.title}
        </a>
        ${it.desc?`<div style="font-size:10px;opacity:.45;margin-top:2px;line-height:1.4;">${it.desc}</div>`:''}
        ${it.date?`<div style="font-size:9px;opacity:.3;margin-top:2px;">${it.date}</div>`:''}
      </div>`).join('');
  }catch(e){body.innerHTML='<div style="color:#ff7777;font-size:12px;padding:8px;">⚠ Failed to load feed.</div>';}
}
document.querySelectorAll('.rss-widget').forEach(_loadRssWidget);
setInterval(()=>document.querySelectorAll('.rss-widget').forEach(_loadRssWidget),5*60*1000);

// ===== DELETE SECTION / COLUMN =====
function deleteSection(e, btn) {
  e.stopPropagation();
  const sec = btn.closest('.section');
  if (!sec) return;
  const title = sec.querySelector('.section-title')?.textContent?.trim() || 'this column';
  if (!confirm('Delete "' + title + '" and all its links?\nThis cannot be undone.')) return;
  sec.remove();
  saveLinksToServer();
}
function toggleCollapse(e, btn) {
  e.stopPropagation();
  const sec = btn.closest('.section');
  if (!sec) return;
  const collapsed = sec.classList.toggle('collapsed');
  btn.textContent = collapsed ? '▶' : '▼';
  // Update count text when collapsing so user knows how many items are inside
  const cnt = sec.querySelectorAll('.card').length;
  const countEl = sec.querySelector('.section-count');
  if (countEl) countEl.textContent = cnt + ' item' + (cnt !== 1 ? 's' : '');
  saveLinksToServer();
}

// ===== START MENU (Windows) =====
function toggleStartMenu(){if(_currentBaseTheme==='win9x'){toggleWin9xMenu();return;}const m=document.getElementById('start-menu'),b=document.getElementById('start-btn');const open=m.classList.toggle('open');b.classList.toggle('active',open);}
function closeStartMenu(){document.getElementById('start-menu')?.classList.remove('open');document.getElementById('start-btn')?.classList.remove('active');}
function smRun(){const url=prompt('Open Location (URL):','https://');if(url&&url.startsWith('http'))window.open(url,'_blank');closeStartMenu();}
document.addEventListener('click',e=>{if(!e.target.closest('#start-menu')&&!e.target.closest('#start-btn'))closeStartMenu();});

// Fix win2000 start-menu 3rd-level flyout: the parent flyout has overflow-y:auto
// which CSS-clips any absolutely-positioned grandchildren. To escape it we switch
// nested flyouts to position:fixed and set their coordinates via JS on mouseenter.
(function(){
  document.querySelectorAll('#start-menu .sm-flyout .sm-has-flyout').forEach(function(item){
    var sub=item.querySelector(':scope>.sm-flyout');
    if(!sub)return;
    item.addEventListener('mouseenter',function(){
      var r=item.getBoundingClientRect();
      sub.style.left=r.right+'px';
      sub.style.top=r.top+'px';
      sub.style.zIndex='9999999';
      // After the browser has laid it out, prevent bottom overflow
      requestAnimationFrame(function(){
        var sh=sub.offsetHeight;
        if(r.top+sh>window.innerHeight-8){
          sub.style.top=Math.max(0,window.innerHeight-sh-8)+'px';
        }
      });
    });
  });
})();

// ===== WIN9X 3-PANEL MENU =====
let _w9xOpen=false;
function toggleWin9xMenu(e){e&&e.stopPropagation();const m=document.getElementById('win9x-menu');_w9xOpen=!_w9xOpen;m.style.display=_w9xOpen?'flex':'none';if(!_w9xOpen)_w9xReset();}
function closeWin9xMenu(){const m=document.getElementById('win9x-menu');if(m)m.style.display='none';_w9xOpen=false;_w9xReset();}
function _w9xReset(){const c2=document.getElementById('w9x-col2'),c3=document.getElementById('w9x-col3');if(c2)c2.style.display='none';if(c3)c3.style.display='none';const b=document.getElementById('w9x-col2-body'),s=document.getElementById('w9x-col2-settings');if(b)b.style.display='';if(s)s.style.display='none';document.querySelectorAll('.w9x-item.active').forEach(el=>el.classList.remove('active'));}
function w9xClickPrograms(){_w9xShowCol2Programs();document.getElementById('w9x-programs')?.classList.add('active');}
function _w9xShowCol2Programs(){const c2=document.getElementById('w9x-col2'),b=document.getElementById('w9x-col2-body'),s=document.getElementById('w9x-col2-settings'),c3=document.getElementById('w9x-col3');c2.style.display='flex';b.style.display='';if(s)s.style.display='none';if(c3)c3.style.display='none';}
function w9xClickSettings(){document.getElementById('w9x-settings')?.classList.add('active');const c2=document.getElementById('w9x-col2'),b=document.getElementById('w9x-col2-body'),s=document.getElementById('w9x-col2-settings'),c3=document.getElementById('w9x-col3');c2.style.display='flex';if(b)b.style.display='none';if(s)s.style.display='flex';if(c3)c3.style.display='none';}
// ===== SCROLL TO SECTION — closes all menus and scrolls desktop to section =====
function scrollToSection(idx){
  closeStartMenu();closeWin9xMenu();
  closeMac9Apple&&closeMac9Apple();closeMacOSXApple&&closeMacOSXApple();
  // Also close macos9 m9 popup menu
  document.querySelectorAll('.m9-item,.m9-apple').forEach(x=>x.classList.remove('active'));
  setTimeout(()=>{
    const el=document.querySelector('.section[data-idx="'+idx+'"]');
    if(!el)return;
    el.scrollIntoView({behavior:'smooth',block:'center'});
    el.classList.add('sec-flash');
    setTimeout(()=>el.classList.remove('sec-flash'),1400);
  },80);
}
function w9xClickSection(idx){
  const sec=WIN9X_LINKS[idx];if(!sec)return;
  // Highlight the clicked section row in col2
  document.querySelectorAll('#w9x-col2-body .w9x-item').forEach(el=>el.classList.remove('active'));
  const rows=document.querySelectorAll('#w9x-col2-body .w9x-item');
  // Find the row matching this idx
  const row=document.querySelector('#w9x-col2-body .w9x-item[data-idx="'+idx+'"]');
  if(row)row.classList.add('active');
  // Show links in col3 — do NOT close the menu (scrollToSection was closing it)
  const c3=document.getElementById('w9x-col3'),body=document.getElementById('w9x-col3-body');
  if(!c3||!body)return;
  const cards=sec.cards||[];
  if(!cards.length){c3.style.display='none';return;}
  // Col3 header lets user scroll to the section on desktop
  const hdr=document.getElementById('w9x-col3-hdr');
  if(hdr){hdr.textContent=(sec.icon||'📁')+' '+(sec.title||'Links');hdr.onclick=()=>{closeWin9xMenu();setTimeout(()=>scrollToSection(idx),80);};}
  body.innerHTML=cards.map(c=>`<a class="w9x-item" href="${c.url||'#'}" target="_blank" onclick="closeWin9xMenu()"><span class="w9x-item-icon">${c.icon||'🔗'}</span><span class="w9x-item-label">${c.label||c.title||'Link'}</span></a>`).join('');
  c3.style.display='flex';
}
document.addEventListener('click',e=>{if(_w9xOpen&&!e.target.closest('#win9x-menu')&&!e.target.closest('#start-btn'))closeWin9xMenu();});

// ===== MAC9 RETRO APPLE MENU =====
let _mac9AppleOpen=false;
function toggleMac9Apple(e){e&&e.stopPropagation();_mac9AppleOpen=!_mac9AppleOpen;const p=document.getElementById('mac9-apple-panel');p.style.display=_mac9AppleOpen?'flex':'none';if(!_mac9AppleOpen)_mac9Reset();}
function closeMac9Apple(){document.getElementById('mac9-apple-panel').style.display='none';_mac9AppleOpen=false;_mac9Reset();}
function _mac9Reset(){const c2=document.getElementById('mac9-ap-col2');if(c2){c2.style.display='none';c2.innerHTML=''}document.querySelectorAll('#mac9-ap-col1 .mac9-ap-item.active').forEach(el=>el.classList.remove('active'));}
function mac9ClickSection(idx,el){
  const sec=WIN9X_LINKS[idx];if(!sec)return;
  const cards=sec.cards||[];
  if(!cards.length){ scrollToSection(idx); closeMac9Apple(); return; }
  document.querySelectorAll('#mac9-ap-col1 .mac9-ap-item').forEach(e=>e.classList.remove('active'));
  if(el)el.classList.add('active');
  const c2=document.getElementById('mac9-ap-col2');
  c2.innerHTML=`<div class="mac9-ap-col-header">${sec.icon||'📁'} ${sec.title}</div>`+cards.map(c=>`<a class="mac9-ap-item" href="${c.url||'#'}" target="_blank" onclick="closeMac9Apple()"><span>${c.icon||'🔗'} ${c.label||c.title||'Link'}</span></a>`).join('');
  c2.style.display='flex';
}
function toggleMac9Item(el){el.classList.toggle('open');}
document.addEventListener('click',e=>{if(_mac9AppleOpen&&!e.target.closest('#mac9-apple-panel')&&!e.target.closest('#mac9-apple-btn'))closeMac9Apple();document.querySelectorAll('.mac9-mitem.open').forEach(m=>{if(!m.contains(e.target))m.classList.remove('open');});});

// ===== MACOSX RETRO APPLE MENU =====
let _macosxAppleOpen=false;
function toggleMacOSXApple(e){e&&e.stopPropagation();_macosxAppleOpen=!_macosxAppleOpen;const p=document.getElementById('macosx-apple-panel');p.style.display=_macosxAppleOpen?'flex':'none';if(!_macosxAppleOpen)_macosxReset();}
function closeMacOSXApple(){document.getElementById('macosx-apple-panel').style.display='none';_macosxAppleOpen=false;_macosxReset();}
function _macosxReset(){const c2=document.getElementById('macosx-ap-col2');if(c2){c2.style.display='none';c2.innerHTML=''}document.querySelectorAll('#macosx-ap-col1 .mox-ap-item.active').forEach(el=>el.classList.remove('active'));}
function macosxClickSection(idx,el){
  const sec=WIN9X_LINKS[idx];if(!sec)return;
  const cards=sec.cards||[];
  if(!cards.length){ scrollToSection(idx); closeMacOSXApple(); return; }
  document.querySelectorAll('#macosx-ap-col1 .mox-ap-item').forEach(e=>e.classList.remove('active'));
  if(el)el.classList.add('active');
  const c2=document.getElementById('macosx-ap-col2');
  c2.innerHTML=`<div class="mox-ap-col-header">${sec.icon||'📁'} ${sec.title}</div>`+cards.map(c=>`<a class="mox-ap-item" href="${c.url||'#'}" target="_blank" onclick="closeMacOSXApple()"><span>${c.icon||'🔗'} ${c.label||c.title||'Link'}</span></a>`).join('');
  c2.style.display='flex';
}
function toggleMoxItem(el){el.classList.toggle('open');}
document.addEventListener('click',e=>{if(_macosxAppleOpen&&!e.target.closest('#macosx-apple-panel')&&!e.target.closest('#macosx-apple-btn'))closeMacOSXApple();document.querySelectorAll('.mox-item.open').forEach(m=>{if(!m.contains(e.target))m.classList.remove('open');});});

// Move apple panels to end of <body> to guarantee they sit above all stacking contexts
(function(){['mac9-apple-panel','macosx-apple-panel'].forEach(id=>{const el=document.getElementById(id);if(el)document.body.appendChild(el);});})();

// ===== T010: Script re-execution for dynamically-injected HTML widget content =====
// When hw_html is set via innerHTML (e.g., after a profile load or AJAX update),
// browsers don't execute <script> tags. This utility clones them so they run.
function _execWidgetScripts(container) {
  container.querySelectorAll('script').forEach(orig => {
    const s = document.createElement('script');
    [...orig.attributes].forEach(a => s.setAttribute(a.name, a.value));
    s.textContent = orig.textContent;
    orig.parentNode.replaceChild(s, orig);
  });
}
// Apply on all hw-widget-content elements at load time (in case any were SSR'd with scripts)
document.querySelectorAll('.hw-widget-content').forEach(_execWidgetScripts);

// ===== THEME SOUNDS (Web Audio API — no files needed) =====
let _soundEnabled = (PHP_STATE['theme_sound'] || localStorage.getItem('theme_sound') || '1') !== '0';
function _playThemeSound(theme) {
  if (!_soundEnabled) return;
  try {
    const ctx = new (window.AudioContext || window.webkitAudioContext)();
    // Each entry: [frequency_hz, start_sec, duration_sec, waveform]
    const seqs = {
      win98:    [[523,.00,.12,'sine'],[659,.13,.12,'sine'],[784,.26,.12,'sine'],[1047,.39,.30,'sine']],
      win9x:    [[523,.00,.12,'sine'],[659,.13,.12,'sine'],[784,.26,.12,'sine'],[1047,.39,.30,'sine']],
      win2k:    [[440,.00,.10,'sine'],[554,.11,.10,'sine'],[659,.22,.15,'sine']],
      winxp:    [[392,.00,.08,'sine'],[523,.09,.08,'sine'],[659,.18,.10,'sine'],[784,.29,.20,'sine']],
      winxp2:   [[392,.00,.08,'sine'],[523,.09,.08,'sine'],[659,.18,.10,'sine'],[784,.29,.20,'sine']],
      winphone: [[523,.00,.06,'sine'],[659,.07,.06,'sine'],[784,.14,.20,'sine']],
      startmenu:[[440,.00,.08,'sine'],[523,.09,.08,'sine'],[659,.18,.15,'sine']],
      amiga:    [[440,.00,.08,'square'],[550,.09,.08,'square'],[660,.18,.08,'square'],[880,.27,.20,'sine']],
      nextstep: [[880,.00,.18,'sine'],[660,.20,.18,'sine'],[440,.40,.35,'sine']],
      beos:     [[523,.00,.08,'sine'],[784,.09,.08,'sine'],[1047,.18,.18,'sine']],
      norton:   [[440,.00,.04,'square'],[440,.05,.04,'square'],[880,.10,.12,'square']],
      macos:    [[523,.00,.08,'sine'],[659,.09,.08,'sine'],[784,.18,.08,'sine'],[1047,.27,.25,'sine']],
      macos9:   [[392,.00,.10,'sine'],[523,.11,.10,'sine'],[659,.22,.10,'sine'],[784,.33,.20,'sine']],
      mac9:     [[392,.00,.10,'sine'],[523,.11,.10,'sine'],[659,.22,.10,'sine'],[784,.33,.20,'sine']],
      macosx:   [[440,.00,.10,'sine'],[587,.11,.10,'sine'],[698,.22,.15,'sine']],
      aqua:     [[494,.00,.08,'sine'],[659,.09,.14,'sine'],[784,.24,.20,'sine']],
      osxtiger: [[523,.00,.08,'sine'],[698,.09,.10,'sine'],[880,.20,.22,'sine']],
      ios26:    [[698,.00,.10,'sine'],[880,.11,.14,'sine'],[1047,.26,.22,'sine']],
      jellybean:[[440,.00,.06,'triangle'],[587,.07,.08,'triangle'],[784,.16,.20,'triangle']],
      jellybean2:[[440,.00,.06,'triangle'],[587,.07,.08,'triangle'],[784,.16,.20,'triangle']],
      c64:      [[440,.00,.04,'square'],[587,.05,.04,'square'],[698,.10,.04,'square'],[880,.15,.12,'square']],
      os2:      [[523,.00,.10,'sine'],[659,.11,.10,'sine'],[784,.22,.10,'sine']],
      ubuntu:   [[440,.00,.10,'triangle'],[554,.11,.10,'triangle'],[659,.22,.20,'triangle']],
      atarist:  [[523,.00,.07,'square'],[659,.08,.07,'square'],[784,.16,.12,'square']],
      irix:     [[392,.00,.08,'sine'],[523,.09,.08,'sine'],[659,.18,.08,'sine'],[784,.27,.08,'sine'],[1047,.36,.20,'sine']],
      solaris:  [[392,.00,.08,'sine'],[494,.09,.08,'sine'],[659,.18,.18,'sine']],
      palmos:   [[1047,.00,.04,'square'],[1319,.05,.10,'square']],
      palmtreo: [[1047,.00,.04,'square'],[1319,.05,.10,'square']],
      palmv:    [[880,.00,.04,'square'],[1047,.05,.10,'square']],
      palmpilot:[[880,.00,.06,'square'],[1047,.07,.10,'square']],
      pocketpc: [[440,.00,.08,'triangle'],[587,.09,.08,'triangle'],[698,.18,.18,'triangle']],
      webos:    [[440,.00,.12,'sine'],[659,.13,.25,'sine']],
      professional:[[440,.00,.10,'sine'],[554,.11,.10,'sine'],[659,.22,.20,'sine']],
      spring:   [[523,.00,.12,'sine'],[659,.13,.12,'sine'],[784,.26,.12,'sine'],[1047,.39,.28,'sine']],
      summer:   [[392,.00,.08,'sine'],[494,.09,.08,'sine'],[587,.18,.20,'sine']],
      autumn:   [[330,.00,.10,'sine'],[392,.11,.20,'sine']],
      winter:   [[523,.00,.07,'sine'],[659,.08,.07,'sine'],[784,.16,.07,'sine'],[1047,.24,.28,'sine']],
      christmas:[[523,.00,.07,'sine'],[659,.08,.07,'sine'],[784,.16,.07,'sine'],[1047,.24,.28,'sine']],
      thanksgiving:[[349,.00,.10,'sine'],[440,.11,.20,'sine']],
      july4:    [[523,.00,.04,'square'],[784,.05,.04,'square'],[1047,.10,.04,'square'],[1319,.15,.20,'square']],
      miku:     [[523,.00,.06,'sine'],[659,.07,.06,'sine'],[784,.14,.06,'sine'],[1047,.21,.06,'sine'],[1319,.28,.25,'sine']],
      cute:     [[523,.00,.10,'sine'],[659,.11,.10,'sine'],[784,.22,.10,'sine'],[1047,.33,.28,'sine']],
    };
    const seq = seqs[theme] || [[440,.00,.15,'sine'],[554,.16,.15,'sine'],[659,.32,.20,'sine']];
    seq.forEach(([freq,t,dur,type]) => {
      const osc = ctx.createOscillator(), g = ctx.createGain();
      osc.type = type || 'sine'; osc.frequency.value = freq;
      osc.connect(g); g.connect(ctx.destination);
      g.gain.setValueAtTime(0.14, ctx.currentTime + t);
      g.gain.exponentialRampToValueAtTime(0.001, ctx.currentTime + t + dur);
      osc.start(ctx.currentTime + t);
      osc.stop(ctx.currentTime + t + dur + 0.05);
    });
  } catch(e) {}
}

// ===== SOUND TOGGLE =====
function toggleSound() {
  _soundEnabled = !_soundEnabled;
  _saveState({theme_sound: _soundEnabled ? '1' : '0'});
  const btn = document.getElementById('sound-toggle-btn');
  if (btn) btn.style.opacity = _soundEnabled ? '1' : '.5';
  if (_soundEnabled) _playThemeSound(_currentBaseTheme || 'macos');
}
// Init button state on load
document.addEventListener('DOMContentLoaded', () => {
  const sb = document.getElementById('sound-toggle-btn');
  if (sb) sb.style.opacity = _soundEnabled ? '1' : '.5';
});

// ===== CRT OVERLAY TOGGLE =====
let _crtOn = (PHP_STATE['crt_overlay'] || '0') === '1';
if (_crtOn) document.body.classList.add('crt-on');
function toggleCRT() {
  _crtOn = !_crtOn;
  document.body.classList.toggle('crt-on', _crtOn);
  _saveState({crt_overlay: _crtOn ? '1' : '0'});
  const btn = document.getElementById('crt-toggle-btn');
  if (btn) btn.style.opacity = _crtOn ? '1' : '.5';
}

// ===== STICKY NOTES =====
let _stickyNotes = <?= json_encode(array_values($_sticky_notes ?? [])) ?>;
let _stickyNoteTimer = null;
function stickyNoteChanged(id, ta) {
  const note = _stickyNotes.find(n => n.id === id);
  if (note) note.text = ta.value;
  clearTimeout(_stickyNoteTimer);
  _stickyNoteTimer = setTimeout(() => {
    _saveState({sticky_notes: JSON.stringify(_stickyNotes)});
  }, 700);
}
function addStickyNote(color) {
  color = color || '#f6e87e';
  const id = 'sn' + Date.now();
  _stickyNotes.push({id, color, text:'', x:120, y:140, w:220, h:160});
  _saveState({sticky_notes: JSON.stringify(_stickyNotes)});
  // Preserve edit mode across the reload so the user can keep adding things
  if (editMode) sessionStorage.setItem('_restoreEditMode','1');
  setTimeout(() => location.reload(), 500);
}
function deleteStickyNote(id, e) {
  if (e) { e.stopPropagation(); e.preventDefault(); }
  if (!confirm('Delete this sticky note?')) return;
  _stickyNotes = _stickyNotes.filter(n => n.id !== id);
  _saveState({sticky_notes: JSON.stringify(_stickyNotes)});
  document.getElementById('stat-sn-' + id)?.remove();
}
// Expose addStickyNote to header button (added dynamically)
window._addStickyNote = addStickyNote;

// ===== COUNTDOWN TIMERS =====
(function _initCountdowns() {
  document.querySelectorAll('.countdown-widget').forEach(el => {
    const target = el.dataset.target;
    const display = el.querySelector('.countdown-display');
    if (!display || !target) return;
    function update() {
      const diff = new Date(target).getTime() - Date.now();
      if (isNaN(diff)) { display.textContent = '?'; return; }
      if (diff <= 0) { display.textContent = '🎉 Done!'; return; }
      const d = Math.floor(diff / 86400000);
      const h = Math.floor((diff % 86400000) / 3600000);
      const m = Math.floor((diff % 3600000) / 60000);
      const s = Math.floor((diff % 60000) / 1000);
      display.textContent = d > 0
        ? d + 'd ' + String(h).padStart(2,'0') + ':' + String(m).padStart(2,'0') + ':' + String(s).padStart(2,'0')
        : String(h).padStart(2,'0') + ':' + String(m).padStart(2,'0') + ':' + String(s).padStart(2,'0');
    }
    update();
    setInterval(update, 1000);
  });
})();

// ===== CAMERA RECORD TRIGGER =====
function triggerCamRecord(url){
  fetch(url,{method:'POST'}).then(r=>r.ok?alert('⏺ Recording triggered'):alert('Record failed: '+r.status)).catch(e=>alert('Error: '+e));
}

// ===== STAT WIDGET HIDE / SHOW =====
function hideStatWidget(id,e){if(e){e.stopPropagation();e.preventDefault();}const el=document.getElementById(id);if(!el)return;el.style.display='none';const h=JSON.parse(PHP_STATE['dash_hidden_stats']||localStorage.getItem('dash_hidden_stats')||'[]');if(!h.includes(id)){h.push(id);_saveState({'dash_hidden_stats':JSON.stringify(h)});}}
// Restore hidden stat widgets on page load — server is authoritative
(function(){const src=PHP_STATE['dash_hidden_stats']||localStorage.getItem('dash_hidden_stats')||'[]';JSON.parse(src).forEach(id=>{const el=document.getElementById(id);if(el)el.style.display='none';});})();
// Restore hidden floating widgets (HTML/RSS/camera/calendar) — server is authoritative
(function(){(HIDDEN_WIDGETS||[]).forEach(id=>{const el=document.getElementById(id);if(el)el.style.display='none';});})();

// ===== COLUMN (SECTION) HIDE / SHOW =====
function hideColumn(e,btn){
  e.stopPropagation();e.preventDefault();
  const sec=btn.closest('.section');if(!sec)return;
  const title=(sec.querySelector('.section-title')?.textContent||'').replace(/^\W+\s*/,'').trim();
  const id=sec.dataset.id||title;
  sec.style.display='none';
  const h=JSON.parse(PHP_STATE['dash_hidden_cols']||localStorage.getItem('dash_hidden_cols')||'[]');
  if(!h.find(x=>x.id===id)){
    h.push({id,title:title||id});
    _saveState({'dash_hidden_cols':JSON.stringify(h)});
  }
}
function restoreColumn(id){
  // Use PHP_STATE (server) as source of truth, merge with localStorage
  let h;
  try{ h=JSON.parse(PHP_STATE['dash_hidden_cols']||localStorage.getItem('dash_hidden_cols')||'[]'); }
  catch(e){ h=[]; }
  const updated=h.filter(x=>(typeof x==='string'?x:x.id)!==id);
  localStorage.setItem('dash_hidden_cols',JSON.stringify(updated));
  _saveState({'dash_hidden_cols':updated.length?JSON.stringify(updated):null});
  // Show the section in the DOM immediately (no reload needed)
  document.querySelectorAll('.section').forEach(sec=>{
    const t=(sec.querySelector('.section-title')?.textContent||'').replace(/^\W+\s*/,'').trim();
    if(sec.dataset.id===id||t===id) sec.style.display='';
  });
}
// Hide columns that were hidden on page load
(function(){
  const src=PHP_STATE['dash_hidden_cols']||localStorage.getItem('dash_hidden_cols')||'[]';
  JSON.parse(src).forEach(item=>{
    const id=typeof item==='string'?item:item.id;
    document.querySelectorAll('.section').forEach(sec=>{
      const t=(sec.querySelector('.section-title')?.textContent||'').replace(/^\W+\s*/,'').trim();
      if(sec.dataset.id===id||t===id)sec.style.display='none';
    });
  });
})();

// ===== PER-SECTION VIEW TOGGLE =====
function toggleSectionView(e, btn) {
  e.stopPropagation();
  const sec = btn.closest('.section');
  if (!sec) return;
  const cur = sec.dataset.view || 'list';
  const next = cur === 'list' ? 'folder' : 'list';
  sec.dataset.view = next;
  btn.textContent = next === 'folder' ? '☰' : '⊞';
  saveLinksToServer();
}

// ===== macOS MENU =====
function toggleMacMenu(id){const p=document.getElementById(id);document.querySelectorAll('.macos-menu-popup').forEach(x=>{if(x.id!==id)x.classList.remove('open');});p.classList.toggle('open');}
document.addEventListener('click',e=>{if(!e.target.closest('#macos-menubar'))document.querySelectorAll('.macos-menu-popup').forEach(p=>p.classList.remove('open'));});

// ===== Mac OS 9 MENU =====
function toggleM9Menu(el){const was=el.classList.contains('active');document.querySelectorAll('.m9-item,.m9-apple').forEach(x=>x.classList.remove('active'));if(!was)el.classList.add('active');}
document.addEventListener('click',e=>{if(!e.target.closest('#macos9-menubar'))document.querySelectorAll('.m9-item,.m9-apple').forEach(x=>x.classList.remove('active'));});

// ===== Ubuntu Overview =====
function toggleUbuntuOverview(){const ov=document.getElementById('ubuntu-overview');ov.style.display=ov.style.display==='none'?'block':'none';}
function toggleUbuntuThemePicker(e){e&&e.stopPropagation();const p=document.getElementById('ubuntu-theme-picker');const isOpen=p.style.display!=='none';p.style.display=isOpen?'none':'block';if(!isOpen){const sel=p.querySelector('select');if(sel){const bm={winxp2:'winxp',jellybean2:'jellybean',palmtreo:'palmos'};sel.value=bm[_currentBaseTheme]||_currentBaseTheme;}}}
document.addEventListener('click',e=>{if(!e.target.closest('#ubuntu-theme-picker')&&!e.target.classList.contains('ubuntu-indicator'))document.getElementById('ubuntu-theme-picker').style.display='none';});

// ===== EDIT MODE =====
let editMode=false;
let _zTop=20; // tracks the highest z-index for bring-to-front
// Restore edit mode when a sticky note was added (page reloads to render new note)
if(sessionStorage.getItem('_restoreEditMode')==='1'){
  sessionStorage.removeItem('_restoreEditMode');
  setTimeout(toggleEditMode,80);
}
function toggleEditMode(){
  editMode=!editMode;
  document.body.classList.toggle('edit-mode',editMode);
  document.getElementById('edit-mode-toggle').textContent=editMode?'✅ Done':'✏️ Edit';
  const sb=document.getElementById('spread-btn');
  if(sb) sb.style.display=editMode?'':'none';
  const lc=document.getElementById('layout-ctrl');
  if(lc){ lc.style.display=editMode?'flex':'none'; if(editMode) refreshLayoutList(); }
  if(editMode) initAllCardSorts();
}

// ── Card drag-to-reorder within a column ─────────────────────────────────────
let _cardDrag=null, _cardDragOver=null;

function _injectCardHandle(card){
  if(card.querySelector('.card-drag-handle')) return;
  const h=document.createElement('span');
  h.className='card-drag-handle';
  h.textContent='⠿';
  h.title='Drag to reorder';
  h.addEventListener('mousedown',e=>{
    if(!editMode) return;
    e.preventDefault();
    e.stopPropagation(); // prevent section drag from triggering
    _cardDrag=card;
    _cardDragOver=null;
    card.classList.add('card-is-dragging');
  });
  card.prepend(h);
}

function initAllCardSorts(){
  document.querySelectorAll('#services .section .card').forEach(_injectCardHandle);
}

document.addEventListener('mousemove',e=>{
  if(!_cardDrag) return;
  document.querySelectorAll('.card').forEach(c=>c.classList.remove('card-drop-above','card-drop-below'));
  const els=document.elementsFromPoint(e.clientX, e.clientY);
  const target=els.find(el=>el.classList?.contains('card') && el!==_cardDrag);
  _cardDragOver=null;
  if(target){
    const rect=target.getBoundingClientRect();
    if(e.clientY < rect.top+rect.height/2){
      target.classList.add('card-drop-above');
      _cardDragOver={card:target,pos:'above'};
    } else {
      target.classList.add('card-drop-below');
      _cardDragOver={card:target,pos:'below'};
    }
  }
});

document.addEventListener('mouseup',e=>{
  if(!_cardDrag) return;
  document.querySelectorAll('.card').forEach(c=>c.classList.remove('card-drop-above','card-drop-below'));
  if(_cardDragOver){
    const {card:target,pos}=_cardDragOver;
    const body=target.closest('.section-body');
    if(body && body===_cardDrag.closest('.section-body')){
      if(pos==='above') body.insertBefore(_cardDrag,target);
      else { const nx=target.nextSibling; nx ? body.insertBefore(_cardDrag,nx) : body.appendChild(_cardDrag); }
    }
  }
  _cardDrag.classList.remove('card-is-dragging');
  _cardDrag=null; _cardDragOver=null;
  saveLinksToServer();
});
function spreadOutSections(){
  const sections=[...document.getElementById('services').querySelectorAll('.section')];
  if(!sections.length)return;
  const cols=Math.max(1,Math.floor((window.innerWidth-40)/260));
  const padX=20,padY=20,gapX=20,gapY=20,w=240,h=200;
  sections.forEach((s,i)=>{
    const col=i%cols,row=Math.floor(i/cols);
    s.style.left=(padX+col*(w+gapX))+'px';
    s.style.top=(padY+row*(h+gapY))+'px';
  });
  saveLinksToServer();
}

// ===== FREE-DRAG GRID =====
// Each .section and .page-folder is absolutely positioned. Drag to move, lock to pin.
// Page-folders are only draggable while edit mode is active.
let _dragEl=null, _dragOffX=0, _dragOffY=0;

function _getScale() {
  const sv = document.getElementById('services');
  const m = (sv.style.transform || '').match(/scale\(([\d.]+)\)/);
  return m ? parseFloat(m[1]) : 1;
}

function initFreeDrag(el) {
  el.addEventListener('mousedown', e => {
    // Always bring clicked element to front (fixes overlap click issues)
    el.style.zIndex = ++_zTop;
    // Nothing drags unless edit mode is active
    if (!editMode) return;
    if (e.target.closest('.card') || e.target.closest('.section-btn') || e.target.closest('.section-view-btn') || e.target.closest('.section-lock-indicator') || e.target.closest('.card-edit-btn') || e.target.closest('.pf-add-btn')) return;
    e.preventDefault();
    _dragEl = el;
    const rect = el.getBoundingClientRect();
    const sc = _getScale();
    _dragOffX = (e.clientX - rect.left) / sc;
    _dragOffY = (e.clientY - rect.top)  / sc;
    el.classList.add('dragging');
  });
}

document.addEventListener('mousemove', e => {
  if (!_dragEl) return;
  const sv = document.getElementById('services');
  const svRect = sv.getBoundingClientRect();
  const sc = _getScale();
  const x = Math.max(0, (e.clientX - svRect.left) / sc - _dragOffX);
  const y = Math.max(0, (e.clientY - svRect.top)  / sc - _dragOffY);
  _dragEl.style.left = x + 'px';
  _dragEl.style.top  = y + 'px';
});

document.addEventListener('mouseup', e => {
  if (!_dragEl) return;
  _dragEl.classList.remove('dragging');
  // Update services height so page scrolls correctly
  updateServicesHeight();
  if (_dragEl.classList.contains('section')) saveLinksToServer();
  else if (_dragEl.classList.contains('page-folder')) savePageFolders();
  _dragEl = null;
});

function updateServicesHeight() {
  const sv = document.getElementById('services');
  let maxH = 200;
  sv.querySelectorAll('.section,.page-folder').forEach(el => {
    maxH = Math.max(maxH, parseInt(el.style.top||0) + el.offsetHeight + 20);
  });
  sv.style.height = maxH + 'px';
}

// Per-section locking removed — layout lock is global via edit mode

// Init drag on all existing sections and page folders
document.querySelectorAll('.section,.page-folder').forEach(initFreeDrag);
updateServicesHeight();
// T009: apply column font scale on load for sections with saved non-default widths
document.querySelectorAll('.section').forEach(function(s){var w=parseInt(s.style.width)||240;if(w!==240)s.style.setProperty('--col-fs',Math.max(0.75,Math.min(1.5,w/240)).toFixed(3));});

// ===== STAT WIDGET SECTIONS =====
(function() {
  // Init drag on stat-sections (drag by header only)
  document.querySelectorAll('.stat-section').forEach(el => {
    const hdr = el.querySelector('.stat-section-hdr');
    if (!hdr) return;
    let ox=0,oy=0,sx=0,sy=0,dragging=false;
    hdr.addEventListener('mousedown', e => {
      if (!editMode) return; // locked unless in edit mode
      if (e.target.closest('.stat-close-btn')) return;
      dragging=true; sx=e.clientX; sy=e.clientY;
      ox=parseInt(el.style.left)||0; oy=parseInt(el.style.top)||0;
      e.preventDefault();
    });
    document.addEventListener('mousemove', e => {
      if(!dragging)return;
      const sc=_getScale();
      el.style.left=(ox+(e.clientX-sx)/sc)+'px';
      el.style.top =(oy+(e.clientY-sy)/sc)+'px';
    });
    document.addEventListener('mouseup', e => {
      if(!dragging)return; dragging=false;
      saveStatPos();
    });
  });

  function saveStatPos() {
    const pos={};
    document.querySelectorAll('.stat-section[data-stat]').forEach(el=>{
      const entry={x:parseInt(el.style.left)||0,y:parseInt(el.style.top)||0};
      if(el.style.width)  entry.w=parseInt(el.style.width);
      if(el.style.height) entry.h=parseInt(el.style.height);
      pos[el.dataset.stat]=entry;
    });
    const posStr=JSON.stringify(pos);
    localStorage.setItem('hp-stat-pos',posStr);
    fetch('save_stat_pos.php',{method:'POST',keepalive:true,headers:{'Content-Type':'application/json'},body:posStr});
    _patchActiveProfile({stat_pos_json:posStr});
  }
  // Stat widget positions come from PHP (server) — no localStorage override needed.

  // ── Per-widget universal size % input ──────────────────────────────────────
  // One number field (percentage) in the widget header, visible in edit-mode.
  // Typing 150 and pressing Enter/Tab scales BOTH width and height by 1.5×.
  // Base sizes are captured once on first creation and stored as data attrs.
  const _NO_RESIZE_IDS=['stat-clock','stat-weather'];
  const _FONT_SCALE_SKIP=['cam-widget','cal-widget'];
  const _FONT_BASE_W=220;
  function _applyFontScale(el,w){
    if(_FONT_SCALE_SKIP.some(c=>el.classList.contains(c)))return;
    const ratio=Math.max(0.72,Math.min(1.8,w/_FONT_BASE_W));
    const body=el.querySelector('.stat-section-body');
    if(body){body.style.fontSize=(12*ratio).toFixed(1)+'px';body.style.lineHeight=(ratio*1.4).toFixed(2);}
  }
  function _makeWidgetSliders(el){
    if(_NO_RESIZE_IDS.includes(el.id))return;
    if(el.querySelector('.widget-size-ctrl'))return;
    const hdr=el.querySelector('.stat-section-hdr');
    if(!hdr)return;
    // Capture base (default) size once, store on element
    if(!el.dataset.baseW) el.dataset.baseW=parseInt(el.style.width)||_FONT_BASE_W;
    if(!el.dataset.baseH) el.dataset.baseH=parseInt(el.style.height)||180;
    const baseW=parseInt(el.dataset.baseW);
    const baseH=parseInt(el.dataset.baseH);
    const curW=parseInt(el.style.width)||baseW;
    const curPct=Math.round((curW/baseW)*100);
    const ctrl=document.createElement('div');
    ctrl.className='widget-size-ctrl';
    ctrl.innerHTML='<span class="wsc-label">Size</span>'
      +'<input type="number" class="widget-pct-input" min="25" max="400" step="5" value="'+curPct+'" title="Widget size % — type a number and press Enter">'
      +'<span class="wsc-label">%</span>';
    const closeBtn=hdr.querySelector('.stat-close-btn');
    if(closeBtn) hdr.insertBefore(ctrl,closeBtn);
    else hdr.appendChild(ctrl);
    const inp=ctrl.querySelector('.widget-pct-input');
    function applyPct(raw){
      const pct=Math.max(25,Math.min(400,parseInt(raw)||100));
      inp.value=pct;
      el.style.width=Math.round(baseW*pct/100)+'px';
      el.style.height=Math.round(baseH*pct/100)+'px';
      _applyFontScale(el,Math.round(baseW*pct/100));
    }
    inp.addEventListener('change',e=>{e.stopPropagation();applyPct(inp.value);saveStatPos();});
    inp.addEventListener('keydown',e=>{
      if(e.key==='Enter'){e.preventDefault();e.stopPropagation();applyPct(inp.value);saveStatPos();}
      else e.stopPropagation();
    });
    inp.addEventListener('mousedown',e=>e.stopPropagation());
    inp.addEventListener('pointerdown',e=>e.stopPropagation());
    inp.addEventListener('click',e=>e.stopPropagation());
    _applyFontScale(el,curW);
  }
  function initResizeHandles(){
    document.querySelectorAll('.stat-section').forEach(_makeWidgetSliders);
  }
  initResizeHandles();

  function barClass(pct) {
    if(pct>=90)return'bar-crit';
    if(pct>=70)return'bar-warn';
    return'bar-ok';
  }

  function updateStatBars(data) {
    // cpu
    if(data.cpu!==undefined && data.cpu!==null){
      const pct=parseFloat(data.cpu)||0;
      const bar=document.getElementById('stat-cpu-bar');
      const val=document.getElementById('stat-cpu-val');
      if(bar){bar.style.width=pct+'%';bar.className='stat-bar '+barClass(pct);}
      if(val)val.textContent=pct+'%';
    }
    // ram (stats.php returns ram_used / ram_total)
    const ru=parseFloat(data.ram_used)||0, rt=parseFloat(data.ram_total)||0;
    if(rt>0){
      const pct=Math.round(100*ru/rt);
      const bar=document.getElementById('stat-ram-bar');
      const val=document.getElementById('stat-ram-val');
      if(bar){bar.style.width=pct+'%';bar.className='stat-bar '+barClass(pct);}
      if(val)val.textContent=ru.toFixed(1)+'/'+rt.toFixed(1)+' GB';
    }
    // drives (stats.php returns drives:{key:{free,total,used_pct,unit}})
    if(data.drives && typeof data.drives==='object'){
      Object.entries(data.drives).forEach(([key,d])=>{
        if(!d)return;
        const bar=document.getElementById('stat-drv-'+key+'-bar');
        const val=document.getElementById('stat-drv-'+key+'-val');
        if(bar){bar.style.width=(d.used_pct||0)+'%';bar.className='stat-bar '+barClass(d.used_pct||0);}
        if(val)val.textContent=(d.total-d.free).toFixed(1)+'/'+d.total+' '+(d.unit||'GB');
      });
    }
  }

  async function pollStats() {
    try {
      const r = await fetch('stats.php');
      const d = await r.json();
      updateStatBars(d);
    } catch(e) {}
  }

  // Only poll if stat sections exist
  if(document.querySelector('.stat-section')) {
    pollStats();
    setInterval(pollStats, 5000);
  }
})();

// ===== MODAL =====
let _editingCard=null;
function _getSectionValue(){
  const sel=document.getElementById('modal-section');
  if(sel.value==='__new__'){return document.getElementById('modal-section-new').value.trim();}
  return sel.value;
}
function handleSectionSelect(){
  const sel=document.getElementById('modal-section');
  const newInp=document.getElementById('modal-section-new');
  newInp.style.display=sel.value==='__new__'?'block':'none';
  if(sel.value==='__new__')newInp.focus();
}
function _setSectionValue(val){
  const sel=document.getElementById('modal-section');
  const newInp=document.getElementById('modal-section-new');
  let found=false;
  for(let i=0;i<sel.options.length;i++){if(sel.options[i].value===val){sel.value=val;found=true;break;}}
  if(!found){sel.value='__new__';newInp.style.display='block';newInp.value=val;}
  else{newInp.style.display='none';newInp.value='';}
}
// ===== PREBUILT LINK LIBRARY =====
// Generated server-side from presets.php — single source of truth for the
// first-run wizard, the Add-Link Quick-Pick panel, and the Options
// "Add Preset Column" buttons. Edit presets.php to add or change entries.
<?php
$_dash_presets = dashGetPresets();
$_dash_preset_items = [];
$_dash_preset_meta  = [];
foreach ($_dash_presets as $_pk => $_pv) {
    $_dash_preset_items[$_pk] = $_pv['items'];
    $_dash_preset_meta[$_pk]  = ['icon' => $_pv['icon'], 'desc' => $_pv['desc']];
}
?>
const PREBUILT_LINKS = <?= json_encode($_dash_preset_items, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
const PRESET_META    = <?= json_encode($_dash_preset_meta,  JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
window.PREBUILT_LINKS = PREBUILT_LINKS;  // legacy callers that look on window
window.PRESET_META    = PRESET_META;

let _prebuiltOpen=false, _prebuiltCat='Search';
function togglePrebuilt(){
  _prebuiltOpen=!_prebuiltOpen;
  document.getElementById('prebuilt-body').style.display=_prebuiltOpen?'block':'none';
  document.getElementById('prebuilt-arrow').textContent=_prebuiltOpen?'▼':'▶';
  if(_prebuiltOpen)renderPrebuilt(_prebuiltCat);
}
function renderPrebuilt(cat){
  _prebuiltCat=cat;
  const cats=Object.keys(PREBUILT_LINKS);
  document.getElementById('prebuilt-cats').innerHTML=cats.map(c=>`<button onclick="renderPrebuilt('${c}')" style="padding:3px 8px;border-radius:20px;border:1px solid rgba(255,255,255,.2);background:${c===cat?'rgba(80,160,255,.35)':'rgba(255,255,255,.07)'};color:#fff;font-size:11px;cursor:pointer;">${c}</button>`).join('');
  const items=PREBUILT_LINKS[cat]||[];
  document.getElementById('prebuilt-grid').innerHTML=items.map(it=>`<button onclick="fillPrebuilt('${it.icon}','${it.label.replace(/'/g,"\\'")}','${it.url}')" style="display:flex;align-items:center;gap:6px;padding:5px 8px;border-radius:7px;border:1px solid rgba(255,255,255,.1);background:rgba(255,255,255,.06);color:#fff;font-size:12px;cursor:pointer;text-align:left;overflow:hidden;white-space:nowrap;"><span>${it.icon}</span><span style="overflow:hidden;text-overflow:ellipsis">${it.label}</span></button>`).join('');
}
function fillPrebuilt(icon,label,url){
  _selectedIcon=icon;
  document.getElementById('icon-preview').textContent=icon;
  document.getElementById('modal-label').value=label;
  document.getElementById('modal-url').value=url;
  buildIconPicker();
  // collapse the panel after picking
  _prebuiltOpen=false;
  document.getElementById('prebuilt-body').style.display='none';
  document.getElementById('prebuilt-arrow').textContent='▶';
}

function addLink(btn){
  if(!DASH_CAN_EDIT){alert('Your account is read-only. Ask an admin to make changes.');return;}
  _editingCard=null;_iconSuggested=false;
  _prebuiltOpen=false;
  document.getElementById('prebuilt-body').style.display='none';
  document.getElementById('prebuilt-arrow').textContent='▶';
  document.getElementById('modal-title').textContent='Add Link';
  const copyRow=document.getElementById('modal-copy-row');if(copyRow)copyRow.style.display='none';
  document.getElementById('modal-label').value='';
  document.getElementById('modal-url').value='';
  const sectionTitle=btn?btn.closest('.section').querySelector('.section-title').textContent.replace(/^\W+\s*/,'').trim():'';
  _setSectionValue(sectionTitle);
  document.getElementById('modal-delete').style.display='none';
  document.getElementById('icon-suggest-hint').style.display='none';
  _selectedIcon='🔗';buildIconPicker();
  document.getElementById('link-modal').classList.add('open');
}
let _editingIconImg = null; // tracks uploaded image icon URL when editing

function _cardIconHtml(icon, iconImg) {
  if (iconImg) return `<span class="card-icon"><img src="${iconImg.startsWith('/')?iconImg:'/'+iconImg}" alt="" style="width:22px;height:22px;border-radius:50%;object-fit:cover;vertical-align:middle;"></span>`;
  return `<span class="card-icon">${icon||'🔗'}</span>`;
}

function editCard(e,btn){
  e.preventDefault();e.stopPropagation();
  if(!DASH_CAN_EDIT){return;}
  const card=btn.closest('.card');_editingCard=card;_iconSuggested=false;
  document.getElementById('modal-title').textContent='Edit Link';
  document.getElementById('modal-label').value=card.querySelector('.card-label').textContent;
  document.getElementById('modal-url').value=card.getAttribute('href')||'';
  const sTitle=card.closest('.section').querySelector('.section-title').textContent.replace(/^\W+\s*/,'').trim();
  _setSectionValue(sTitle);
  document.getElementById('modal-delete').style.display='inline-flex';
  document.getElementById('icon-suggest-hint').style.display='none';
  // Show move/copy row when editing
  const copyRow=document.getElementById('modal-copy-row');
  if(copyRow){copyRow.style.display='flex';document.getElementById('modal-copy-check').checked=false;}
  // Preserve icon_img (uploaded image icon)
  const iconEl = card.querySelector('.card-icon');
  const imgEl = iconEl ? iconEl.querySelector('img') : null;
  if (imgEl) { _editingIconImg = imgEl.getAttribute('src'); _selectedIcon = '🖼'; }
  else { _editingIconImg = null; _selectedIcon = iconEl?.textContent || '🔗'; }
  buildIconPicker();document.getElementById('link-modal').classList.add('open');
}
function closeModal(){document.getElementById('link-modal').classList.remove('open');}
function saveCard(){
  const label=document.getElementById('modal-label').value.trim(),url=document.getElementById('modal-url').value.trim(),section=_getSectionValue();
  if(!label||!url){alert('Label and URL required');return;}
  // If user picked a new emoji icon (not 🖼 placeholder), clear the icon_img
  if (_selectedIcon !== '🖼') _editingIconImg = null;
  const iconHtml = _cardIconHtml(_selectedIcon, _editingIconImg);
  if(_editingCard){
    _editingCard.querySelector('.card-label').textContent=label;
    _editingCard.setAttribute('href',url);
    _editingCard.querySelector('.card-icon').outerHTML=iconHtml;
    // Move or copy to a different section if section changed
    const _normT=el=>(el.querySelector('.section-title')?.textContent||'').replace(/^\W+\s*/,'').trim();
    const curSec=_editingCard.closest('.section');
    const curTitle=curSec?_normT(curSec):'';
    if(section&&section!==curTitle&&curSec){
      const targetSec=[...document.querySelectorAll('#services .section')].find(s=>_normT(s)===section);
      if(targetSec){
        const doCopy=document.getElementById('modal-copy-check')?.checked;
        if(doCopy){
          const clone=_editingCard.cloneNode(true);
          clone.querySelector('.card-edit-btn')?.addEventListener('click',e=>editCard(e,clone.querySelector('.card-edit-btn')));
          if(editMode)_injectCardHandle(clone);
          targetSec.querySelector('.section-body').appendChild(clone);
        } else {
          targetSec.querySelector('.section-body').appendChild(_editingCard);
        }
      }
    }
  } else {
    // Exact-match the section title (strip leading icon + spaces before comparing)
    const _normTitle = el => (el.querySelector('.section-title')?.textContent||'').replace(/^\W+\s*/,'').trim();
    let secEl=[...document.querySelectorAll('#services .section')].find(s=>_normTitle(s)===section);
    if(!secEl){
      const sv=document.getElementById('services');
      // Place new section below all existing ones, spread horizontally to avoid overlap
      const allSecs=[...sv.querySelectorAll('.section')];
      const nx=allSecs.length>0?Math.max(...allSecs.map(s=>parseInt(s.style.left||0)+s.offsetWidth+20),20):20;
      const ny=allSecs.length>0?Math.min(...allSecs.map(s=>parseInt(s.style.top||0)),10):10;
      secEl=document.createElement('div');
      secEl.className='section';
      secEl.dataset.id='sec-'+Date.now();
      secEl.style.left=nx+'px'; secEl.style.top=ny+'px';
      secEl.innerHTML=`<div class="section-header"><div class="section-hdr-top"><span class="section-count">0 items</span><button class="section-collapse-btn" onclick="toggleCollapse(event,this)" title="Collapse / Expand">▼</button><div class="section-actions"><span class="section-lock-indicator" title="Layout locked — click ✏️ Edit to rearrange">🔒</span><button class="section-view-btn" onclick="toggleSectionView(event,this)" title="Toggle grid/list view">⊞</button><button class="section-btn" onclick="addLink(this)">+ Add</button><button class="section-btn section-del-btn" onclick="deleteSection(event,this)" title="Delete this column">🗑</button></div></div><div class="section-title">${_selectedIcon} ${section||'New'}</div></div><div class="section-body"></div>`;
      sv.appendChild(secEl);
      initFreeDrag(secEl);
      updateServicesHeight();
      // Add to the section select so subsequent "+ Add" on this section finds it
      const sel=document.getElementById('modal-section');
      const newOpt=document.createElement('option');
      newOpt.value=section; newOpt.textContent=section;
      sel.insertBefore(newOpt, sel.querySelector('option[value="__new__"]'));
    }
    const card=document.createElement('a');card.className='card';card.href=url;card.target='_blank';
    card.innerHTML=`${iconHtml}<span class="card-label">${label}</span><button class="card-edit-btn" onclick="editCard(event,this)">✏️</button>`;
    secEl.querySelector('.section-body').appendChild(card);
    if(editMode) _injectCardHandle(card);
  }
  closeModal();saveLinksToServer();
}
function deleteCard(){if(_editingCard&&confirm('Delete this link?')){_editingCard.remove();closeModal();saveLinksToServer();}}
function _buildLinksPayload(){
  return [...document.getElementById('services').querySelectorAll('.section')].map(s=>{
    const titleEl = s.querySelector('.section-title');
    const rawTitle = (titleEl ? titleEl.textContent : '').trim();
    // Use spread iterator — correctly handles surrogate pairs (emoji = 1 element, not 2)
    const parts = [...rawTitle];
    const icon = parts[0] || '🔗';
    // Skip icon code point + optional variation selector (U+FE0F) + leading spaces
    let ts = 1;
    if (parts[ts] === '\uFE0F') ts++;
    while (ts < parts.length && parts[ts] === ' ') ts++;
    const title = parts.slice(ts).join('').trim() || icon;
    const secW=parseInt(s.style.width)||0;
    return {
      id:s.dataset.id||'sec-'+Date.now(),
      title,icon,
      pos_x:parseInt(s.style.left)||0,
      pos_y:parseInt(s.style.top)||0,
      width:(secW>=160&&secW<=600)?secW:0,
      locked:s.classList.contains('locked'),
      collapsed:s.classList.contains('collapsed'),
      view:s.dataset.view||'list',
      cards:[...s.querySelectorAll('.card')].map(c=>{
        const ico=c.querySelector('.card-icon');
        const img=ico?.querySelector('img');
        return {
          icon: img ? '' : (ico?.textContent?.replace(/⠿/g,'')||'🔗').trim(),
          icon_img: img ? img.getAttribute('src') : undefined,
          label:(c.querySelector('.card-label')?.textContent||'').trim(),
          url:c.getAttribute('href')||''
        };
      }).filter(c=>c.url)
    };
  });
}
// ── Layout persistence ────────────────────────────────────────────────────────
// TWO-LAYER SAVE STRATEGY:
//   Layer 1 — localStorage: instant, same-browser backup (survives crashes)
//   Layer 2 — Server (dash_links.json): cross-device canonical store
//
// sendBeacon is deliberately NOT used — it's designed for analytics pings and
// browsers drop it silently on unload.  fetch({keepalive:true}) is far more
// reliable for actual data delivery during page unload.

const _POS_KEY   = 'hp-positions-' + HP_USER;
const _FULL_KEY  = 'hp-layout-'    + HP_USER;  // full layout snapshot

// ── Snapshot the full layout to localStorage (synchronous, always works) ──
function _saveLayoutLocal(){
  try{
    const data = _buildLinksPayload();
    localStorage.setItem(_FULL_KEY, JSON.stringify(data));
    // also update the position-only map for legacy restore
    const map={};
    data.forEach(s=>{ if(s.id) map[s.id]={x:s.pos_x||0, y:s.pos_y||0, view:s.view||'list'}; });
    localStorage.setItem(_POS_KEY, JSON.stringify(map));
  }catch(e){}
}

// ── Restore positions AND view from localStorage on page load ──────────────
function _restoreLocalLayout(){
  try{
    const raw=localStorage.getItem(_POS_KEY);
    if(!raw) return;
    const map=JSON.parse(raw);
    document.querySelectorAll('#services .section').forEach(s=>{
      const id=s.dataset.id;
      if(!id || !map[id]) return;
      s.style.left = (map[id].x||0)+'px';
      s.style.top  = (map[id].y||0)+'px';
      if(map[id].view){
        s.dataset.view = map[id].view;
        const btn=s.querySelector('.section-view-btn');
        if(btn) btn.textContent = map[id].view==='folder' ? '☰' : '⊞';
      }
    });
  }catch(e){}
}

// ── POST to server with fetch + keepalive (reliable even during page unload) ─
let _saveIndicatorTimer=null;
function _setSaveIndicator(text,color){
  const el=document.getElementById('save-indicator');
  if(!el) return;
  el.textContent=text;
  el.style.color=color||'';
  el.style.opacity='.8';
  clearTimeout(_saveIndicatorTimer);
  if(text&&text.startsWith('✓')){
    _saveIndicatorTimer=setTimeout(()=>{el.style.opacity='.35';},6000);
  }
}
function _postToServer(payload){
  _setSaveIndicator('Saving…','#aac4ff');
  const fd=new FormData();
  fd.append('action','save_links');
  fd.append('links_json', JSON.stringify(payload));
  return fetch('save_links.php',{method:'POST', body:fd, keepalive:true})
    .then(r=>r.json())
    .then(j=>{
      const t=new Date().toLocaleTimeString('en-US',{hour:'numeric',minute:'2-digit'});
      if(j&&j.ok){
        _setSaveIndicator('✓ Saved '+t,'#6ee7b7');
        // Keep a timestamped local backup so the user can restore if data is ever lost
        try{localStorage.setItem('hp-links-backup-'+HP_USER, JSON.stringify({t:Date.now(),d:payload}));}catch(e){}
      } else _setSaveIndicator('⚠ Save failed','#fca5a5');
    })
    .catch(()=>_setSaveIndicator('⚠ Save failed','#fca5a5'));
}

// ── Main save: local first, then server ───────────────────────────────────
function saveLinksToServer(){
  _saveLayoutLocal();              // synchronous local snapshot first
  _postToServer(_buildLinksPayload()); // reliable fetch to server
}

// ── Debounced save: prevents rapid back-to-back server hits ──────────────
let _saveTimer=null;
function saveLinksDebounced(){
  _saveLayoutLocal();              // always snapshot locally right away
  clearTimeout(_saveTimer);
  _saveTimer=setTimeout(()=>_postToServer(_buildLinksPayload()), 800);
}

// saveAndGo: AWAIT the save, then navigate.
// Previously used fire-and-forget; the PHP on the destination page could load
// before save_links.php finished writing, causing it to read stale link data.
async function saveAndGo(url){
  _saveLayoutLocal();
  const fd=new FormData();
  fd.append('action','save_links');
  fd.append('links_json',JSON.stringify(_buildLinksPayload()));
  await fetch('save_links.php',{method:'POST',body:fd}).catch(()=>{});
  window.location=url;
}

// Flag: set to true when we are deliberately navigating to options.php.
// Used to suppress the pagehide keepalive save in that specific case — see below.
let _navToOptions = false;

// Intercept clicks to options.php: AWAIT the save, THEN navigate.
// This is the critical fix: without await the browser starts loading options.php
// immediately, and options.php reads dash_links.json before save_links.php has
// finished writing — so any link added on the dashboard since the last save is lost.
document.addEventListener('click',e=>{
  const a=e.target.closest('a[href]');
  if(!a) return;
  const href=a.getAttribute('href')||'';
  if(!href.includes('options.php')) return;
  e.preventDefault();
  _navToOptions=true;   // tell pagehide: skip redundant save
  _saveLayoutLocal();
  const fd=new FormData();
  fd.append('action','save_links');
  fd.append('links_json',JSON.stringify(_buildLinksPayload()));
  fetch('save_links.php',{method:'POST',body:fd})
    .catch(()=>{})
    .finally(()=>{ window.location.href=href; });
});

// Auto-save every 30 seconds
setInterval(saveLinksToServer, 30000);

// pagehide: local snapshot + ONE keepalive fetch only.
// sendBeacon was removed — it fired a SECOND concurrent write to save_links.php,
// which (with no file locking) caused two PHP processes to interleave writes and
// corrupt dash_links.json → columns silently wiped.  keepalive fetch is sufficient.
//
// RACE-CONDITION FIX: when navigating to options.php the click handler above
// already saves S1.  If pagehide also fires a keepalive save of S1, that request
// can arrive at the server *after* any lajax changes the user makes in options.php,
// overwriting the whole link file back to S1 (i.e. "every column is restored").
// Solution: skip the keepalive save when _navToOptions is true.
window.addEventListener('pagehide',()=>{
  _saveLayoutLocal();
  if (_navToOptions) return;  // click handler already saved; don't race with lajax
  const fd=new FormData();
  fd.append('action','save_links');
  fd.append('links_json', JSON.stringify(_buildLinksPayload()));
  fetch('save_links.php',{method:'POST',body:fd,keepalive:true}).catch(()=>{});
});

// BFCACHE FIX: when the user hits the browser back-button from options.php, the
// browser restores this page from its back-forward cache (bfcache) — old DOM with
// old links.  The 30-second auto-save then immediately fires and overwrites the
// server with that stale state, undoing any changes made in options.php.
// Solution: detect a bfcache restore (event.persisted===true) and force a fresh
// server load so PHP renders the up-to-date links.
window.addEventListener('pageshow', e => {
  if (e.persisted) window.location.reload();
});

// ===== PAGE FOLDER WIDGETS =====
let _pageFolders = <?= json_encode($page_folders) ?>;

async function addPageFolder() {
  const label = prompt('Folder name:', 'My Files');
  if (!label) return;
  // Create server-side folder FIRST and get its unique dir_key
  let dirKey = '';
  try {
    const fd = new FormData();
    fd.append('action', 'add_folder');
    fd.append('label', label);
    fd.append('icon', '📁');
    const resp = await fetch('download.php', {method:'POST', body:fd});
    if (!resp.ok) throw new Error('Server returned ' + resp.status);
    const data = await resp.json();
    if (data.error || data.ok === false) {
      alert('Server error: ' + (data.error || 'Unknown error') + '\n\nCheck that uploads/ is writable by PHP (chmod 755 or chown www-data).');
      return;
    }
    dirKey = data.dir || data.dir_key || '';
  } catch(e) {
    alert('Could not create folder: ' + e.message);
    return;
  }
  if (!dirKey) {
    alert('Folder creation failed — server returned no folder ID.\n\nCheck PHP write permissions on uploads/ directory.');
    return;
  }
  const id = 'pf-' + Date.now();
  const sv = document.getElementById('services');
  const svRect = sv.getBoundingClientRect();
  const x = Math.max(10, (window.innerWidth/2 - svRect.left - 80));
  const y = Math.max(10, (window.scrollY + 100 - svRect.top));
  const pf = {id, label, dir_key: dirKey, pos_x: Math.round(x), pos_y: Math.round(y)};
  _pageFolders.push(pf);
  savePageFolders();
  // Render it
  const el = document.createElement('div');
  el.className = 'page-folder';
  el.dataset.pfId = id;
  el.dataset.dirKey = dirKey;
  el.style.left = pf.pos_x + 'px';
  el.style.top  = pf.pos_y + 'px';
  el.innerHTML = `<div class="pf-icon">📁</div><div class="pf-label">${label}</div><button class="pf-add-btn" onclick="event.stopPropagation();removePageFolder('${id}')" title="Remove">✕</button>`;
  el.addEventListener('dblclick', () => openPageFolder(dirKey, label));
  sv.appendChild(el);
  initFreeDrag(el);
  updateServicesHeight();
}

function removePageFolder(id) {
  if (!confirm('Remove this folder from the page?')) return;
  _pageFolders = _pageFolders.filter(f => f.id !== id);
  savePageFolders();
  document.querySelector(`.page-folder[data-pf-id="${id}"]`)?.remove();
}

function savePageFolders() {
  // Sync positions from DOM
  document.querySelectorAll('.page-folder').forEach(el => {
    const pf = _pageFolders.find(f => f.id === el.dataset.pfId);
    if (pf) { pf.pos_x = parseInt(el.style.left)||0; pf.pos_y = parseInt(el.style.top)||0; }
  });
  const fd = new FormData();
  fd.append('action','save_page_folders');
  fd.append('folders_json', JSON.stringify(_pageFolders));
  fetch('save_links.php', {method:'POST',body:fd}).catch(()=>{});
}

async function openPageFolder(dirKey, label) {
  // Open the doc panel locked to one specific folder.
  // Rule: ALWAYS clear stale content first. NEVER fall back to label-matching
  // (that was the source of cross-folder content leaking).
  _docLockedFolder   = null;
  _docCurrentFolder  = null;
  const panel   = document.getElementById('doc-panel');
  const el      = document.getElementById('doc-files');
  const nameEl  = document.getElementById('doc-folder-name');
  const sidebar = document.getElementById('doc-sidebar');

  // 1. Open panel, hide sidebar, wipe old content BEFORE any async work
  panel.classList.add('open');
  if (sidebar) sidebar.style.display = 'none';
  // Update BOTH the header title and the toolbar folder name
  const titleEl = document.getElementById('doc-panel-title');
  if (titleEl)  titleEl.textContent = '📂 ' + (label || 'Folder');
  if (nameEl)   nameEl.textContent  = label || 'Folder';
  if (el)       el.innerHTML = '<div style="padding:32px;text-align:center;opacity:.45;">Loading…</div>';

  // 2. No dir_key = this widget was never properly initialised
  if (!dirKey) {
    if (el) el.innerHTML =
      '<div style="padding:32px;text-align:center;color:#f87171;font-size:13px;">' +
      '⚠️ This folder has no ID.<br>' +
      '<span style="opacity:.6;font-size:11px;">Remove it with the ✕ button and create a new one.<br>' +
      'Visit <a href="diag.php" target="_blank" style="color:#4a9eff;">diag.php</a> to check folder state.</span></div>';
    return;
  }

  // 3. Lock and render — direct filesystem-backed fetch, no label matching
  _docLockedFolder  = dirKey;
  _docCurrentFolder = dirKey;
  await renderDocFiles(dirKey);
}

// ===== FOLDER VIEW (per-section only — global mode removed) =====
function handleSectionClick(e, section) {
  if (section.dataset.view !== 'folder') return;
  if (e.target.closest('.card') || e.target.closest('.card-edit-btn') || e.target.closest('.section-btn') || e.target.closest('.section-view-btn') || e.target.closest('.section-lock-indicator')) return;
  e.preventDefault();
  e.stopPropagation();
  openFolderPanel(section);
}

function openFolderPanel(section) {
  const icon = section.querySelector('.section-folder-icon')?.textContent || '📂';
  const title = section.querySelector('.section-title')?.textContent.trim() || 'Folder';
  const cards = [...section.querySelectorAll('.card')];
  document.getElementById('fp-title-text').textContent = title;
  document.querySelector('#folder-panel-title .fp-icon').textContent = '📂';
  const container = document.getElementById('folder-panel-cards');
  if (!cards.length) {
    container.innerHTML = '<div style="padding:12px;font-size:13px;opacity:.6;">This folder is empty. Use Edit mode to add links.</div>';
  } else {
    container.innerHTML = cards.map(c => {
      const iconEl = c.querySelector('.card-icon');
      const imgEl = iconEl ? iconEl.querySelector('img') : null;
      const iconHtml = imgEl
        ? `<span class="card-icon"><img src="${imgEl.getAttribute('src')}" alt="" style="width:22px;height:22px;border-radius:50%;object-fit:cover;vertical-align:middle;"></span>`
        : `<span class="card-icon">${iconEl?.textContent||'🔗'}</span>`;
      return `<a class="card" href="${c.getAttribute('href')}" target="_blank">${iconHtml}<span class="card-label">${c.querySelector('.card-label')?.textContent||''}</span></a>`;
    }).join('');
  }
  document.getElementById('folder-panel').classList.add('open');
}

function closeFolderPanel() {
  document.getElementById('folder-panel').classList.remove('open');
}
document.addEventListener('click', e => {
  if (e.target.id === 'folder-panel') closeFolderPanel();
});
document.addEventListener('keydown', e => { if (e.key === 'Escape') closeFolderPanel(); });

// ===== DOCUMENT PANEL =====
let _docFolders = [];
let _docCurrentFolder = null;
let _docLockedFolder = null;
let _docTypeFilter = 'all';
let _docSearch = '';
let _docAllFiles = []; // cached files for current folder (set by renderDocFiles)

const DOC_TYPES = [
  { key:'all',     label:'All Files', icon:'📂' },
  { key:'image',   label:'Images',    icon:'🖼️' },
  { key:'video',   label:'Videos',    icon:'🎬' },
  { key:'audio',   label:'Audio',     icon:'🎵' },
  { key:'doc',     label:'Documents', icon:'📄' },
  { key:'archive', label:'Archives',  icon:'🗜️' },
  { key:'other',   label:'Other',     icon:'📎' },
];
const DOC_EXT = {
  image:   ['jpg','jpeg','png','gif','webp','svg','ico','bmp','tiff','avif','heic'],
  video:   ['mp4','webm','mov','avi','mkv','flv','m4v','wmv','3gp'],
  audio:   ['mp3','wav','ogg','flac','aac','m4a','wma','opus'],
  doc:     ['pdf','doc','docx','xls','xlsx','ppt','pptx','odt','ods','txt','md','csv','rtf','pages','numbers','keynote'],
  archive: ['zip','tar','gz','rar','7z','bz2','xz','tar.gz'],
};
function _docFileType(name) {
  const ext = (name.split('.').pop() || '').toLowerCase();
  for (const [type, exts] of Object.entries(DOC_EXT)) if (exts.includes(ext)) return type;
  return 'other';
}
function _applyDocFilters(files) {
  let out = files;
  if (_docTypeFilter !== 'all') out = out.filter(f => _docFileType(f.name) === _docTypeFilter);
  const q = _docSearch.trim().toLowerCase();
  if (q) out = out.filter(f => f.name.toLowerCase().includes(q));
  return out;
}
function renderDocTypeTabs(allFiles) {
  const el = document.getElementById('doc-type-list');
  if (!el) return;
  const counts = { all: allFiles.length };
  for (const t of Object.keys(DOC_EXT)) counts[t] = allFiles.filter(f => _docFileType(f.name) === t).length;
  counts.other = allFiles.filter(f => _docFileType(f.name) === 'other').length;
  el.innerHTML = DOC_TYPES.map(t =>
    `<button class="doc-type-btn${_docTypeFilter===t.key?' active':''}" onclick="setDocTypeFilter('${t.key}')">
      <span>${t.icon}</span>
      <span style="flex:1">${t.label}</span>
      ${counts[t.key]>0?`<span class="dtcount">${counts[t.key]}</span>`:''}
    </button>`
  ).join('');
}
function setDocTypeFilter(type) {
  _docTypeFilter = type;
  renderDocTypeTabs(_docAllFiles);
  _renderDocFileList(_applyDocFilters(_docAllFiles));
}
function docSearchChanged(val) {
  _docSearch = val;
  renderDocTypeTabs(_docAllFiles);
  _renderDocFileList(_applyDocFilters(_docAllFiles));
}

async function openDocPanel() {
  _docLockedFolder = null; // no lock — show full sidebar
  document.getElementById('doc-panel').classList.add('open');
  await loadDocFolders();
}
function closeDocPanel() {
  _docLockedFolder = null;
  const box = document.getElementById('doc-panel-box');
  if (box) { box.style.transform=''; box.style.transition=''; box.style.animation=''; }
  _docDragTx = 0; _docDragTy = 0;
  document.getElementById('doc-panel').classList.remove('open');
}
document.addEventListener('click', e => { if(e.target.id==='doc-panel') closeDocPanel(); });

// ===== DOC PANEL DRAG (titlebar → freely draggable window) =====
let _docDragging = false, _docDragOx = 0, _docDragOy = 0, _docDragTx = 0, _docDragTy = 0;
document.addEventListener('mousedown', e => {
  const hdr = e.target.closest('#doc-panel-header');
  if (!hdr) return;
  if (e.target.closest('#doc-panel-close,.doc-win-btn')) return;
  const box = document.getElementById('doc-panel-box');
  if (!box) return;
  _docDragging = true;
  const m = new DOMMatrix(getComputedStyle(box).transform);
  _docDragTx = m.m41 || 0; _docDragTy = m.m42 || 0;
  _docDragOx = e.clientX; _docDragOy = e.clientY;
  box.style.transition = 'none'; box.style.animation = 'none';
  e.preventDefault();
});
document.addEventListener('mousemove', e => {
  if (!_docDragging) return;
  const box = document.getElementById('doc-panel-box');
  if (!box) return;
  _docDragTx += e.clientX - _docDragOx;
  _docDragTy += e.clientY - _docDragOy;
  _docDragOx = e.clientX; _docDragOy = e.clientY;
  box.style.transform = `translate(${_docDragTx}px,${_docDragTy}px)`;
});
document.addEventListener('mouseup', () => { _docDragging = false; });

async function loadDocFolders() {
  try {
    const r = await fetch('download.php?action=list');
    const d = await r.json();
    if (!d.ok) return;
    _docFolders = d.folders || [];
    renderDocSidebar();
    if (_docLockedFolder) {
      // Widget-locked view: always show the locked folder's files
      _docCurrentFolder = _docLockedFolder;
      renderDocFiles(_docLockedFolder);
    } else if (_docFolders.length) {
      // Stay on the current folder if it still exists after the reload
      const stillExists = _docCurrentFolder &&
        _docFolders.some(f => _folderDirKey(f) === _docCurrentFolder);
      if (!stillExists) {
        // Current folder was deleted or never set — fall back to first folder
        _docCurrentFolder = _folderDirKey(_docFolders[0]);
      }
      renderDocFiles(_docCurrentFolder);
    } else {
      // No folders at all — show empty state
      _docCurrentFolder = null;
      const nameEl = document.getElementById('doc-folder-name');
      const filesEl = document.getElementById('doc-files');
      if (nameEl) nameEl.textContent = '';
      if (filesEl) filesEl.innerHTML = '<div style="padding:24px;text-align:center;opacity:.5;">Create a folder to get started</div>';
    }
  } catch(e) { console.error(e); }
}

function _folderDirKey(f) { return f.dir_key || f.path; }

function renderDocSidebar() {
  const sidebar = document.getElementById('doc-sidebar');
  const el = document.getElementById('doc-folder-list');
  if (!el) return;
  if (_docLockedFolder) {
    // T001: widget-locked view — hide entire sidebar (folder nav + new folder row)
    if (sidebar) sidebar.style.display = 'none';
    return;
  }
  if (sidebar) sidebar.style.display = '';
  const _typeIcons = {all:'📁',image:'🖼️',video:'🎬',audio:'🎵',doc:'📄',archive:'🗜️',other:'📎'};
  const _typeNames = {all:'All Files',image:'Images',video:'Videos',audio:'Audio',doc:'Documents',archive:'Archives',other:'Other'};
  el.innerHTML = _docFolders.map(f => {
    const dk = _folderDirKey(f);
    const active = _docCurrentFolder === dk ? ' active' : '';
    const pt = f.pinned_type || 'all';
    const fIcon = pt !== 'all' ? (_typeIcons[pt] || f.icon || '📁') : (f.icon || '📁');
    const pinOpts = Object.entries(_typeIcons).map(([t,ico]) =>
      `<button class="doc-pin-opt${pt===t?' active':''}" onclick="setFolderPin('${dk}','${t}',event)">
        <span>${ico} ${_typeNames[t]}</span>
        ${pt===t?'<span class="pin-check">✓</span>':''}
      </button>`
    ).join('');
    return `<div class="doc-folder-row${active}" id="dfr-${dk}">
      <button class="doc-folder-btn" onclick="selectDocFolder('${dk}')" title="${f.label}">
        <span>${fIcon}</span>
        <span style="flex:1;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">${f.label}</span>
        <span class="dfcount">${f.files.length}</span>
      </button>
      <button class="doc-folder-pin-btn" onclick="toggleFolderPinPicker('${dk}',event)" title="Pin a category to this folder">📌</button>
      <button class="doc-folder-del" onclick="deleteDocFolder('${dk}','${f.label.replace(/'/g,"\\'")}',event)" title="Delete folder">🗑</button>
      <div class="doc-folder-pin-picker" id="dfpp-${dk}">
        <div style="font-size:10px;opacity:.5;padding:3px 8px 5px;text-transform:uppercase;letter-spacing:.05em;">Pin this folder to…</div>
        ${pinOpts}
      </div>
    </div>`;
  }).join('');
}

async function deleteDocFolder(dirKey, label, e) {
  if (e) e.stopPropagation();
  if (!confirm('Delete folder "' + label + '" and ALL its files? This cannot be undone.')) return;
  const fd = new FormData();
  fd.append('folder', dirKey);
  await fetch('download.php?action=delete_folder', {method:'POST', body:fd});
  if (_docCurrentFolder === dirKey) _docCurrentFolder = null;
  await loadDocFolders();
}

function toggleFolderPinPicker(dk, e) {
  if (e) e.stopPropagation();
  const all = document.querySelectorAll('.doc-folder-pin-picker');
  all.forEach(p => { if (p.id !== 'dfpp-' + dk) p.classList.remove('open'); });
  const el = document.getElementById('dfpp-' + dk);
  if (el) el.classList.toggle('open');
}
// Close pin picker when clicking outside
document.addEventListener('click', () => {
  document.querySelectorAll('.doc-folder-pin-picker.open').forEach(p => p.classList.remove('open'));
});

async function setFolderPin(dk, type, e) {
  if (e) e.stopPropagation();
  // Close all pickers immediately
  document.querySelectorAll('.doc-folder-pin-picker').forEach(p => p.classList.remove('open'));
  const fd = new FormData();
  fd.append('folder', dk);
  fd.append('pinned_type', type);
  const resp = await fetch('download.php?action=set_folder_pin', {method:'POST', body:fd});
  const result = await resp.json().catch(()=>({}));
  if (!result.ok) { alert('Could not save pin — check server.'); return; }
  // Reload folders from server so every folder's pinned_type is authoritative
  await loadDocFolders();
  // If this is the active folder, apply the new filter right now
  if (_docCurrentFolder === dk) {
    _docTypeFilter = type;
    renderDocTypeTabs(_docAllFiles);
    _renderDocFileList(_applyDocFilters(_docAllFiles));
  }
}

async function selectDocFolder(dirKey) {
  _docCurrentFolder = dirKey;         // pin before any async work
  try {
    const r = await fetch('download.php?action=list');
    const d = await r.json();
    if (d.ok) _docFolders = d.folders || [];
  } catch(e) {}
  renderDocSidebar();
  renderDocFiles(_docCurrentFolder);  // use the pinned value, not the closure arg
}

let _docViewMode = 'auto'; // 'auto'|'grid'|'list'

function toggleDocView() {
  const el = document.getElementById('doc-files');
  const btn = document.getElementById('doc-view-toggle');
  if (_docViewMode !== 'grid') { _docViewMode = 'grid'; el.classList.add('icon-grid'); btn.textContent = '☰'; }
  else { _docViewMode = 'list'; el.classList.remove('icon-grid'); btn.textContent = '⊞'; }
}

function _autoDocView() {
  const el = document.getElementById('doc-files');
  const btn = document.getElementById('doc-view-toggle');
  if (!el || !btn) return;
  if (_docViewMode === 'auto') {
    const gridThemes = ['theme-win98','theme-winxp','theme-winxp2','theme-win2k',
                        'theme-macos','theme-macos9','theme-ios26','theme-palmos','theme-palmtreo'];
    const isGrid = gridThemes.some(t=>document.body.classList.contains(t));
    if (isGrid) { el.classList.add('icon-grid'); btn.textContent = '☰'; }
    else { el.classList.remove('icon-grid'); btn.textContent = '⊞'; }
  }
}

async function deleteAllDocFiles() {
  const dk = _docLockedFolder || _docCurrentFolder;
  if (!dk) return;
  const folder = _docFolders.find(f => _folderDirKey(f) === dk);
  const label = folder ? folder.label : dk;
  const count = _docAllFiles.length;
  if (!count) { alert('No files to delete.'); return; }
  if (!confirm(`Delete all ${count} file${count===1?'':'s'} in "${label}"? Files are permanently removed from disk.`)) return;
  try {
    const fd = new FormData();
    fd.append('folder', dk);
    const r = await fetch('download.php?action=clear_folder', {method:'POST', body:fd});
    const d = await r.json().catch(()=>({}));
    if (!d.ok) { alert('Clear failed — check server.'); return; }
  } catch(e) { alert('Network error: ' + e.message); return; }
  if (_docLockedFolder) await renderDocFiles(_docLockedFolder);
  else await loadDocFolders();
}

async function renderDocFiles(dirKey) {
  if (!dirKey) return;
  const el = document.getElementById('doc-files');
  const nameEl = document.getElementById('doc-folder-name');
  if (el) el.innerHTML = '<div style="padding:16px;opacity:.5;">Loading…</div>';
  let data;
  try {
    const r = await fetch('download.php?action=files&folder=' + encodeURIComponent(dirKey));
    data = await r.json();
  } catch(e) { if (el) el.innerHTML = '<div style="padding:16px;color:red;">Load error</div>'; return; }
  if (!data.ok) return;
  if (nameEl) nameEl.textContent = (data.icon || '📁') + ' ' + (data.label || dirKey);
  // Always reset to this folder's pinned type — handles missing, 'all', and specific types
  _docTypeFilter = data.pinned_type || 'all';
  const srch = document.getElementById('doc-search');
  if (srch) srch.value = '';
  _docSearch = '';
  _docAllFiles = data.files || [];
  renderDocTypeTabs(_docAllFiles);
  _renderDocFileList(_applyDocFilters(_docAllFiles));
}

function _renderDocFileList(files) {
  const el = document.getElementById('doc-files');
  if (!el) return;
  const upload = '<div id="doc-drop-zone" style="margin-top:8px;" onclick="document.getElementById(\'doc-file-input\').click()">+ Upload more files</div>';
  if (!files.length) {
    const emptyMsg = _docSearch || _docTypeFilter !== 'all'
      ? `<div id="doc-no-results">No files match your filter.</div>`
      : `<div id="doc-drop-zone" onclick="document.getElementById('doc-file-input').click()">Drop files here or click to upload</div>`;
    el.innerHTML = emptyMsg;
    setupDocDrop(); _autoDocView(); return;
  }
  el.innerHTML = files.map(f =>
    `<div class="doc-file-row">
      <span class="doc-file-icon">${f.icon}</span>
      <div class="doc-file-info">
        <div class="doc-file-name" title="${f.name}">${f.name}</div>
        <div class="doc-file-size">${f.size_h} · ${new Date(f.mtime*1000).toLocaleDateString()}</div>
      </div>
      <a class="doc-file-dl" href="${f.url}" download="${f.name}" title="Download">⬇</a>
      <button class="doc-file-del" onclick="deleteDocFile('${f.folder}','${f.name}')" title="Delete file">🗑</button>
    </div>`
  ).join('') + upload;
  setupDocDrop();
  _autoDocView();
}

function setupDocDrop() {
  const dz = document.getElementById('doc-drop-zone');
  if (!dz) return;
  dz.addEventListener('dragover', e => { e.preventDefault(); dz.classList.add('dragging'); });
  dz.addEventListener('dragleave', () => dz.classList.remove('dragging'));
  dz.addEventListener('drop', e => {
    e.preventDefault(); dz.classList.remove('dragging');
    if (e.dataTransfer.files.length) uploadDocFilesRaw(e.dataTransfer.files);
  });
}

async function uploadDocFiles(input) {
  if (!input.files.length) return;
  await uploadDocFilesRaw(input.files);
  input.value = '';
}

async function uploadDocFilesRaw(files) {
  const fd = new FormData();
  fd.append('folder', _docCurrentFolder || 'docs');
  for (const f of files) fd.append('files[]', f);
  try {
    const r = await fetch('download.php?action=upload', {method:'POST',body:fd});
    const d = await r.json();
    if (d.errors?.length) alert('Some files failed: ' + d.errors.join('\n'));
    // In locked-folder mode refresh just this folder directly; otherwise reload all
    if (_docLockedFolder) await renderDocFiles(_docLockedFolder);
    else await loadDocFolders();
  } catch(e) { alert('Upload error: ' + e.message); }
}

async function deleteDocFile(folder, name) {
  if (!confirm('Delete ' + name + '?')) return;
  await fetch('download.php?action=delete&folder='+encodeURIComponent(folder)+'&file='+encodeURIComponent(name));
  if (_docLockedFolder) await renderDocFiles(_docLockedFolder);
  else await loadDocFolders();
}

async function addDocFolder() {
  const inp = document.getElementById('doc-new-folder-input');
  const label = inp.value.trim();
  if (!label) return;
  const fd = new FormData();
  fd.append('label', label);
  fd.append('icon', '📁');
  const r = await fetch('download.php?action=add_folder', {method:'POST', body:fd});
  const d = await r.json().catch(()=>({}));
  inp.value = '';
  // Auto-select the new folder so uploads go to the right place
  if (d.dir) _docCurrentFolder = d.dir;
  else if (d.path) _docCurrentFolder = d.path;
  await loadDocFolders();
}

// ===== INIT =====
(function(){
  const valid=['win98','win9x','win2k','winxp','winxp2','winphone','aqua','ios26','jellybean','jellybean2','palmos','palmtreo','palmv','palmpilot','pocketpc','macos','macos9','mac9','macosx','osxtiger','ubuntu','c64','os2','webos','professional','cute','spring','summer','autumn','winter','thanksgiving','july4','christmas','custom','miku','amiga','nextstep','beos','norton','atarist','irix','solaris'];
  // Note: halloween, valentine, newyear, easter removed in v1.3
  // Server state is authoritative — fall back to localStorage only for first-time visitors
  let t=PHP_STATE['hp-theme']||localStorage.getItem('hp-theme')||'win9x';
  let s=parseInt(PHP_STATE['hp-size']||localStorage.getItem('hp-size'))||100;
  if(t==='win98') t='win9x'; // v1.4.1 — win98 deprecated, alias to win9x (identical look)
  if(!valid.includes(t))t='win9x';if(s<60||s>200)s=100;
  _currentBaseTheme=t;
  const baseMap={winxp2:'winxp',jellybean2:'jellybean',palmtreo:'palmos'};
  const tsel=document.getElementById('theme-sel');
  if(tsel)tsel.value=baseMap[t]||t;
  updateVariantDropdown(baseMap[t]||t);
  applyTheme(t);
  // Restore wallpaper — server first
  const savedWall=PHP_STATE['hp-wall']||localStorage.getItem('hp-wall')||'teal';
  if(t==='win98'||t==='win9x')applyWallpaper(savedWall);
  // Restore saved background variant — server is authoritative, localStorage as fallback
  // Use the BASE theme key (e.g. 'winxp' not 'winxp2') so the lookup matches what was saved
  const baseT=baseMap[t]||t;
  const savedVariant=PHP_STATE['variant-'+baseT]||localStorage.getItem('variant-'+baseT)||localStorage.getItem('hp-variant-'+baseT)||PHP_STATE['variant-'+t]||localStorage.getItem('variant-'+t);
  if(savedVariant&&savedVariant!=='default'){
    // Small delay so canvas animations are initialized first
    setTimeout(()=>onVariantChange(savedVariant),200);
  }
  // Auto-activate specific custom background if options.php just saved one for this theme
  // The stored value is the index of the bg to activate (e.g., "0", "1", "2"…)
  const activateSig=localStorage.getItem('hp-activate-bg-'+t);
  if(activateSig!==null){
    localStorage.removeItem('hp-activate-bg-'+t);
    const bgIdx=parseInt(activateSig)||0;
    const bgKey='cbg-'+bgIdx;
    const bgList=_getNamedBgList(t);
    if(bgList.length>bgIdx){
      setTimeout(()=>{
        const dd=document.getElementById('variant-sel');
        if(dd){dd.value=bgKey;onVariantChange(bgKey);}
      },300);
    }
  }
  applySize(s);
  buildIconPicker();
})();

// ===== PRESET COLUMN PICKER MODAL (v1.4.3) =====
// Opens/closes the 📦 Presets modal (always available to all non-readonly
// users in edit mode — each add writes ONLY to the current user's own data).

function openPresetModal(){
  const m=document.getElementById('preset-modal');
  if(!m)return;
  m.style.display='block';
  document.body.style.overflow='hidden';
  // Reset any lingering states from previous opens
  document.querySelectorAll('.preset-card').forEach(c=>{
    c.classList.remove('pc-loading','pc-done','pc-err');
    c.querySelector('.pc-plus').textContent='＋';
    c.querySelector('.pc-plus').style.color='rgba(255,255,255,.4)';
    c.style.opacity='1';
    c.style.pointerEvents='auto';
    c.style.background='rgba(255,255,255,.05)';
    c.style.borderColor='rgba(255,255,255,.1)';
  });
  const t=document.getElementById('preset-toast');
  t.style.display='none';
}

function closePresetModal(){
  const m=document.getElementById('preset-modal');
  if(m)m.style.display='none';
  document.body.style.overflow='';
  // If any preset was added, reload so the new columns appear.
  // We set a flag on the modal itself when a successful add happens.
  if(m && m.dataset.needsReload==='1'){
    m.dataset.needsReload='0';
    window.location.reload();
  }
}

async function addPresetCol(card){
  if(!DASH_CAN_EDIT){alert('Your account is read-only.');return;}
  if(card.classList.contains('pc-loading')||card.classList.contains('pc-done'))return;
  const cat=card.dataset.cat;
  if(!cat)return;
  // Loading state
  card.classList.add('pc-loading');
  card.querySelector('.pc-plus').textContent='⏳';
  card.style.pointerEvents='none';
  card.style.background='rgba(80,150,255,.12)';
  card.style.borderColor='rgba(80,150,255,.35)';
  try{
    const fd=new FormData();
    fd.append('preset_cat',cat);
    const r=await fetch('add_preset.php',{method:'POST',body:fd});
    const d=await r.json();
    if(d.ok){
      // Success state — mark card as done
      card.classList.remove('pc-loading');
      card.classList.add('pc-done');
      card.querySelector('.pc-plus').textContent='✅';
      card.querySelector('.pc-plus').style.color='#4ade80';
      card.style.background='rgba(80,200,120,.14)';
      card.style.borderColor='rgba(80,200,120,.4)';
      // Show toast
      const t=document.getElementById('preset-toast');
      t.style.display='block';
      t.style.background='rgba(80,200,120,.2)';
      t.style.border='1px solid rgba(80,200,120,.4)';
      t.style.color='#4ade80';
      t.textContent='✅ Added "'+d.title+'" with '+d.count+' links — click Close to reload your dashboard.';
      // Mark modal as needing a reload on close
      document.getElementById('preset-modal').dataset.needsReload='1';
    }else{
      throw new Error(d.error||'Server error');
    }
  }catch(e){
    card.classList.remove('pc-loading');
    card.classList.add('pc-err');
    card.querySelector('.pc-plus').textContent='❌';
    card.querySelector('.pc-plus').style.color='#f87171';
    card.style.background='rgba(200,50,50,.12)';
    card.style.borderColor='rgba(200,80,80,.35)';
    card.style.pointerEvents='auto';
    const t=document.getElementById('preset-toast');
    t.style.display='block';
    t.style.background='rgba(200,50,50,.2)';
    t.style.border='1px solid rgba(200,80,80,.4)';
    t.style.color='#f87171';
    t.textContent='❌ Could not add preset: '+e.message;
    // Re-enable card after error so user can retry
    setTimeout(()=>{
      card.classList.remove('pc-err');
      card.querySelector('.pc-plus').textContent='＋';
      card.querySelector('.pc-plus').style.color='rgba(255,255,255,.4)';
      card.style.background='rgba(255,255,255,.05)';
      card.style.borderColor='rgba(255,255,255,.1)';
      card.style.pointerEvents='auto';
    },2500);
  }
}
// Close preset modal on Escape key
document.addEventListener('keydown',function(e){
  if(e.key==='Escape'){
    const m=document.getElementById('preset-modal');
    if(m&&m.style.display!=='none')closePresetModal();
  }
});
</script>

<!-- ===== SCREENSAVER ANIMATIONS ===== -->
<script>
// (Flying Toasters removed — use Custom background with iframe URL if desired)

// 3D Pipes (Win2K)
(function(){const cv=document.getElementById('canvas-pipes'),ctx=cv.getContext('2d');let pipes=[],animId=null;const CELL=40,COLORS=['#ff2020','#20ff20','#2060ff','#ff8000','#ff20ff','#20ffff','#ffff20'],DIRS=[[1,0],[0,1],[-1,0],[0,-1]];function resize(){cv.width=window.innerWidth;cv.height=window.innerHeight;}function newPipe(){const c=COLORS[Math.floor(Math.random()*COLORS.length)];return{x:Math.floor(Math.random()*Math.floor(cv.width/CELL))*CELL+CELL/2,y:Math.floor(Math.random()*Math.floor(cv.height/CELL))*CELL+CELL/2,dir:Math.floor(Math.random()*4),color:c,pipeW:8+Math.floor(Math.random()*3)*4,progress:0,alive:true,age:0,maxAge:80+Math.floor(Math.random()*120)};}function draw3D(x1,y1,x2,y2,c,w,d){ctx.strokeStyle=c;ctx.lineWidth=w;ctx.lineCap='square';ctx.beginPath();ctx.moveTo(x1,y1);ctx.lineTo(x2,y2);ctx.stroke();ctx.strokeStyle='rgba(255,255,255,.35)';ctx.lineWidth=w*.3;ctx.beginPath();d===0||d===2?ctx.moveTo(x1,y1-w*.25):ctx.moveTo(x1-w*.25,y1);d===0||d===2?ctx.lineTo(x2,y2-w*.25):ctx.lineTo(x2-w*.25,y2);ctx.stroke();}function drawJ(x,y,c,w){const r=w*.75,g=ctx.createRadialGradient(x-r*.3,y-r*.3,r*.1,x,y,r);g.addColorStop(0,'rgba(255,255,255,.7)');g.addColorStop(.4,c);g.addColorStop(1,'rgba(0,0,0,.6)');ctx.fillStyle=g;ctx.beginPath();ctx.arc(x,y,r,0,Math.PI*2);ctx.fill();}function animate(){if(cv.style.display==='none'){animId=null;return;}if(pipes.length<8&&Math.random()<.04)pipes.push(newPipe());pipes.forEach(p=>{if(!p.alive)return;p.age++;if(p.age>p.maxAge){p.alive=false;return;}const d=DIRS[p.dir],nx=p.x+d[0]*2,ny=p.y+d[1]*2;draw3D(p.x,p.y,nx,ny,p.color,p.pipeW,p.dir);p.x=nx;p.y=ny;p.progress+=2;if(p.progress>=CELL){p.progress=0;p.x=Math.round(p.x/CELL)*CELL;p.y=Math.round(p.y/CELL)*CELL;drawJ(p.x,p.y,p.color,p.pipeW);if(Math.random()<.4){const t=p.dir%2===0?[1,3]:[0,2];p.dir=t[Math.floor(Math.random()*2)];}const nd=DIRS[p.dir],fx=p.x+nd[0]*CELL,fy=p.y+nd[1]*CELL;if(fx<0||fx>cv.width||fy<0||fy>cv.height)p.dir=(p.dir+2)%4;}});pipes=pipes.filter(p=>p.alive);animId=requestAnimationFrame(animate);}window.addEventListener('resize',resize);resize();window._startPipes=()=>{ctx.fillStyle='#000';ctx.fillRect(0,0,cv.width,cv.height);pipes=[];if(!animId)animate();};window._stopPipes=()=>{if(animId){cancelAnimationFrame(animId);animId=null;}ctx.clearRect(0,0,cv.width,cv.height);};})();

// Android 4.0 ICS JellyBean2 — "Cascade / Holo" (authentic ICS dark wallpaper + Holo #33b5e5 design language)
// Real ICS wallpaper: very dark navy background, Holo blue (#33b5e5) flowing cascade ribbons + geometric diamonds
(function(){const cv=document.getElementById('canvas-nexus'),ctx=cv.getContext('2d');let t=0,animId=null,cascades=[];function rnd(a,b){return a+Math.random()*(b-a);}function initCascades(){cascades=[];const W=cv.width,H=cv.height;for(let i=0;i<24;i++)cascades.push({type:'ribbon',x:rnd(0,W),y:rnd(-H,H),w:rnd(1.5,10),speed:rnd(.25,.85),hue:rnd(194,212),alpha:rnd(.07,.25),len:rnd(H*.12,H*.48)});for(let i=0;i<9;i++)cascades.push({type:'diamond',x:rnd(0,W),y:rnd(0,H),s:rnd(18,65),rot:Math.PI/4,rs:rnd(-.007,.007),vy:rnd(.08,.28),alpha:rnd(.05,.14),hue:204});}function animate(){if(cv.style.display==='none'){animId=null;return;}t+=.009;const W=cv.width,H=cv.height;ctx.fillStyle='rgba(0,6,16,.17)';ctx.fillRect(0,0,W,H);cascades.forEach(s=>{if(s.type==='diamond'){s.rot+=s.rs;s.y+=s.vy;if(s.y>H+100)s.y=-100;ctx.save();ctx.translate(s.x,s.y);ctx.rotate(s.rot+t*.28);ctx.strokeStyle=`hsla(${s.hue},100%,65%,${s.alpha})`;ctx.lineWidth=1;ctx.beginPath();ctx.moveTo(0,-s.s);ctx.lineTo(s.s*.58,0);ctx.lineTo(0,s.s);ctx.lineTo(-s.s*.58,0);ctx.closePath();ctx.stroke();ctx.fillStyle=`hsla(${s.hue},100%,60%,${s.alpha*.38})`;ctx.fill();ctx.restore();return;}s.y+=s.speed;if(s.y>H+s.len)s.y=-s.len;const g=ctx.createLinearGradient(s.x,s.y,s.x,s.y+s.len);g.addColorStop(0,`hsla(${s.hue},100%,62%,0)`);g.addColorStop(.28,`hsla(${s.hue},100%,65%,${s.alpha})`);g.addColorStop(.72,`hsla(${s.hue},96%,70%,${s.alpha*.82})`);g.addColorStop(1,`hsla(${s.hue},100%,62%,0)`);ctx.fillStyle=g;ctx.fillRect(s.x,s.y,s.w,s.len);const ey=s.y+s.len*.68;ctx.shadowBlur=s.w*3.5;ctx.shadowColor=`hsla(${s.hue},100%,72%,1)`;ctx.fillStyle=`hsla(${s.hue},100%,88%,${s.alpha*2.2})`;ctx.fillRect(s.x,ey,s.w,2.5);ctx.shadowBlur=0;});ctx.strokeStyle='rgba(51,181,229,.028)';ctx.lineWidth=.5;for(let x=0;x<W;x+=72){ctx.beginPath();ctx.moveTo(x,0);ctx.lineTo(x,H);ctx.stroke();}for(let y=0;y<H;y+=72){ctx.beginPath();ctx.moveTo(0,y);ctx.lineTo(W,y);ctx.stroke();}animId=requestAnimationFrame(animate);}window.addEventListener('resize',()=>{cv.width=window.innerWidth;cv.height=window.innerHeight;initCascades();});cv.width=window.innerWidth;cv.height=window.innerHeight;window._startNexus=()=>{ctx.fillStyle='#000610';ctx.fillRect(0,0,cv.width,cv.height);initCascades();if(!animId)animate();};window._stopNexus=()=>{if(animId){cancelAnimationFrame(animId);animId=null;}ctx.clearRect(0,0,cv.width,cv.height);cascades=[];};})();

// Android 4.1 JellyBean — "Phase Beam" (authentic default wallpaper recreation)
// The real Phase Beam: colorful arcing light beams (magenta/teal/amber/violet) on pure black
(function(){const cv=document.getElementById('canvas-nexus2'),ctx=cv.getContext('2d');let beams=[],animId=null,t=0;const PALETTES=[[300,342],[168,198],[24,48],[272,308],[185,215],[48,68],[328,355]];function rnd(a,b){return a+Math.random()*(b-a);}function newBeam(){const W=cv.width,H=cv.height,pal=PALETTES[Math.floor(Math.random()*PALETTES.length)];const hue=rnd(pal[0],pal[1]),edge=Math.floor(Math.random()*4);let sx,sy;if(edge===0){sx=rnd(0,W);sy=-25;}else if(edge===1){sx=W+25;sy=rnd(0,H);}else if(edge===2){sx=rnd(0,W);sy=H+25;}else{sx=-25;sy=rnd(0,H);}return{sx,sy,ex:rnd(W*.08,W*.92),ey:rnd(H*.08,H*.92),cpx:rnd(W*.05,W*.95),cpy:rnd(H*.05,H*.95),hue,life:0,maxLife:280+rnd(0,240),speed:rnd(.0032,.0088),width:rnd(1.1,3.0),glow:rnd(13,30),prog:0,trail:[],fadeIn:42,fadeOut:58};}function initBeams(){beams=[];for(let i=0;i<14;i++){const b=newBeam();b.life=Math.random()*b.maxLife;beams.push(b);}}function bez(u,p0,p1,p2){return(1-u)*(1-u)*p0+2*(1-u)*u*p1+u*u*p2;}function animate(){if(cv.style.display==='none'){animId=null;return;}t+=.01;ctx.fillStyle='rgba(0,0,0,.055)';ctx.fillRect(0,0,cv.width,cv.height);beams.forEach(b=>{b.life++;b.prog=Math.min(b.prog+b.speed,1);const alpha=b.life<b.fadeIn?b.life/b.fadeIn:b.life>b.maxLife-b.fadeOut?(b.maxLife-b.life)/b.fadeOut:1;if(b.life>b.maxLife){Object.assign(b,newBeam());return;}const px=bez(b.prog,b.sx,b.cpx,b.ex),py=bez(b.prog,b.sy,b.cpy,b.ey);b.trail.push({x:px,y:py});if(b.trail.length>100)b.trail.shift();if(b.trail.length>2){for(let i=1;i<b.trail.length;i++){const ta=i/b.trail.length;ctx.shadowBlur=b.glow*ta*.65;ctx.shadowColor=`hsla(${b.hue},100%,68%,1)`;ctx.strokeStyle=`hsla(${b.hue},96%,72%,${alpha*ta*.52})`;ctx.lineWidth=b.width*ta*2.3;ctx.beginPath();ctx.moveTo(b.trail[i-1].x,b.trail[i-1].y);ctx.lineTo(b.trail[i].x,b.trail[i].y);ctx.stroke();}}ctx.shadowBlur=b.glow*2.6;ctx.shadowColor=`hsla(${b.hue},100%,88%,${alpha})`;const g=ctx.createRadialGradient(px,py,0,px,py,b.glow*1.9);g.addColorStop(0,`hsla(${b.hue},100%,97%,${alpha})`);g.addColorStop(.18,`hsla(${b.hue},100%,76%,${alpha*.72})`);g.addColorStop(1,`hsla(${b.hue},90%,58%,0)`);ctx.fillStyle=g;ctx.beginPath();ctx.arc(px,py,b.glow*1.9,0,Math.PI*2);ctx.fill();ctx.shadowBlur=0;});animId=requestAnimationFrame(animate);}window.addEventListener('resize',()=>{cv.width=window.innerWidth;cv.height=window.innerHeight;});cv.width=window.innerWidth;cv.height=window.innerHeight;window._startNexus2=()=>{ctx.fillStyle='#000';ctx.fillRect(0,0,cv.width,cv.height);initBeams();if(!animId)animate();};window._stopNexus2=()=>{if(animId){cancelAnimationFrame(animId);animId=null;}ctx.clearRect(0,0,cv.width,cv.height);beams=[];};})();

// OSX Aqua Ribbons
(function(){const cv=document.getElementById('canvas-aqua'),ctx=cv.getContext('2d');let t=0,animId=null;function resize(){cv.width=window.innerWidth;cv.height=window.innerHeight;}function animate(){if(cv.style.display==='none'){animId=null;return;}t+=.005;ctx.fillStyle='rgba(20,70,140,.08)';ctx.fillRect(0,0,cv.width,cv.height);for(let i=0;i<4;i++){ctx.beginPath();const amp=cv.height*.12,freq=.003+i*.001,spd=t*(.4+i*.15),off=i*cv.height*.25;for(let x=0;x<=cv.width;x+=4){const y=off+amp*Math.sin(x*freq+spd)+amp*.5*Math.sin(x*freq*1.5-spd*.8);x===0?ctx.moveTo(x,y):ctx.lineTo(x,y);}ctx.lineTo(cv.width,cv.height);ctx.lineTo(0,cv.height);ctx.closePath();const g=ctx.createLinearGradient(0,0,cv.width,0);const h=[210,200,195,205][i];g.addColorStop(0,`hsla(${h},80%,70%,.18)`);g.addColorStop(.5,`hsla(${h},90%,80%,.25)`);g.addColorStop(1,`hsla(${h},80%,70%,.18)`);ctx.fillStyle=g;ctx.fill();}animId=requestAnimationFrame(animate);}window.addEventListener('resize',resize);resize();window._startAqua=()=>{ctx.fillStyle='#1b6ca8';ctx.fillRect(0,0,cv.width,cv.height);if(!animId)animate();};window._stopAqua=()=>{if(animId){cancelAnimationFrame(animId);animId=null;}ctx.clearRect(0,0,cv.width,cv.height);};})();

// iOS 26 — soft lavender orbs, NO screen blend (avoids white blow-out)
(function(){const cv=document.getElementById('canvas-ios26'),ctx=cv.getContext('2d');let t=0,animId=null;function resize(){cv.width=window.innerWidth;cv.height=window.innerHeight;}function blob(cx,cy,r,innerCol,outerCol,ph){ctx.beginPath();for(let i=0;i<=18;i++){const a=(i/18)*Math.PI*2,w=r*(.82+.18*Math.sin(a*3+ph+t*.5));ctx.lineTo(cx+Math.cos(a)*w,cy+Math.sin(a)*w);}ctx.closePath();const g=ctx.createRadialGradient(cx-r*.25,cy-r*.25,r*.05,cx,cy,r);g.addColorStop(0,innerCol);g.addColorStop(1,outerCol);ctx.fillStyle=g;ctx.fill();}function animate(){if(cv.style.display==='none'){animId=null;return;}t+=.005;const W=cv.width,H=cv.height;// Fill dark bg each frame
ctx.fillStyle='#120c28';ctx.fillRect(0,0,W,H);// Draw blobs with source-over only — no screen composite
ctx.globalCompositeOperation='source-over';blob(W*.55+Math.sin(t*.35)*W*.12,H*.25+Math.cos(t*.28)*H*.1,H*.38,'rgba(140,80,200,.38)','rgba(80,40,160,0)',0);blob(W*.4+Math.cos(t*.3)*W*.1,H*.6+Math.sin(t*.25)*H*.08,H*.32,'rgba(60,90,200,.32)','rgba(40,60,180,0)',2.1);blob(W*.7+Math.sin(t*.38)*W*.09,H*.75+Math.cos(t*.32)*H*.07,H*.35,'rgba(60,140,180,.3)','rgba(30,90,150,0)',4.2);blob(W*.3+Math.cos(t*.25)*W*.08,H*.35+Math.sin(t*.3)*H*.06,H*.28,'rgba(160,80,180,.28)','rgba(100,40,140,0)',1);animId=requestAnimationFrame(animate);}window.addEventListener('resize',resize);resize();window._startIos26=()=>{ctx.fillStyle='#120c28';ctx.fillRect(0,0,cv.width,cv.height);if(!animId)animate();};window._stopIos26=()=>{if(animId){cancelAnimationFrame(animId);animId=null;}ctx.clearRect(0,0,cv.width,cv.height);};})();

// XP Aquarium
(function(){const cv=document.getElementById('canvas-aquarium'),ctx=cv.getContext('2d');let fish=[],animId=null,t=0;function resize(){cv.width=window.innerWidth;cv.height=window.innerHeight;spawnFish();}function spawnFish(){fish=[];for(let i=0;i<14;i++){const dir=Math.random()>.5?1:-1;fish.push({x:dir>0?-80:cv.width+80,y:50+Math.random()*(cv.height-100),sx:(.5+Math.random()*.8)*dir,wy:Math.random()*Math.PI*2,hue:180+Math.floor(Math.random()*6)*30,sz:.6+Math.random()*.8,tail:0});}}function drawFish(f){ctx.save();ctx.translate(f.x,f.y);if(f.sx<0)ctx.scale(-1,1);const s=f.sz*40;ctx.fillStyle=`hsl(${f.hue},80%,55%)`;ctx.beginPath();ctx.ellipse(0,0,s,s*.4,0,0,Math.PI*2);ctx.fill();ctx.beginPath();ctx.moveTo(-s,0);ctx.lineTo(-s-s*.5,s*.3+Math.sin(f.tail)*.1*s);ctx.lineTo(-s-s*.5,-(s*.3+Math.sin(f.tail)*.1*s));ctx.closePath();ctx.fillStyle=`hsl(${f.hue},60%,40%)`;ctx.fill();ctx.restore();}function animate(){if(cv.style.display==='none'){animId=null;return;}t+=.016;ctx.fillStyle='rgba(0,30,80,.12)';ctx.fillRect(0,0,cv.width,cv.height);fish.forEach(f=>{f.x+=f.sx;f.y+=Math.sin(f.wy+t)*.3;f.tail+=.18;if(f.x>cv.width+120)f.x=-80;if(f.x<-120)f.x=cv.width+80;drawFish(f);});animId=requestAnimationFrame(animate);}window.addEventListener('resize',resize);resize();window._startAquarium=()=>{ctx.fillStyle='#001a40';ctx.fillRect(0,0,cv.width,cv.height);if(!animId)animate();};window._stopAquarium=()=>{if(animId){cancelAnimationFrame(animId);animId=null;}ctx.clearRect(0,0,cv.width,cv.height);};})();

// Palm OS — two distinct animations: Pilot (bouncing app windows) & Treo (phone notification screen)
(function(){
  const cv=document.getElementById('canvas-palmos'),ctx=cv.getContext('2d');
  let animId=null,_th='palmos',W=0,H=0;

  // ── Utility ──
  function rr(x,y,w,h,r){ctx.beginPath();ctx.moveTo(x+r,y);ctx.lineTo(x+w-r,y);ctx.quadraticCurveTo(x+w,y,x+w,y+r);ctx.lineTo(x+w,y+h-r);ctx.quadraticCurveTo(x+w,y+h,x+w-r,y+h);ctx.lineTo(x+r,y+h);ctx.quadraticCurveTo(x,y+h,x,y+h-r);ctx.lineTo(x,y+r);ctx.quadraticCurveTo(x,y,x+r,y);ctx.closePath();}

  // ═══════════════════════════════════════════
  // PALM OS 5 Garnet — authentic launcher grid
  // Real Palm OS: sage-green LCD, 4-col icon grid, tap animation, graffiti area
  // ═══════════════════════════════════════════
  const PALM_ICONS=[
    {n:'Date Book',c:'#2244b8',e:'📅'},{n:'Address',c:'#b82222',e:'📇'},
    {n:'To Do',    c:'#1a6a1a',e:'✔'}, {n:'Memo Pad',c:'#b06018',e:'📝'},
    {n:'Prefs',    c:'#555555',e:'⚙'}, {n:'Calculator',c:'#186878',e:'🖩'},
    {n:'HotSync',  c:'#c03818',e:'🔄'},{n:'Security',c:'#6a1a6a',e:'🔒'},
    {n:'Expense',  c:'#228022',e:'$'}, {n:'Web',c:'#1a4890',e:'🌐'},
    {n:'Mail',     c:'#104e8a',e:'✉'}, {n:'Clock',c:'#3a3a3a',e:'⏰'},
  ];
  let wins=[],_tapIdx=-1,_tapA=0,_tapT=0;
  function initWins(){wins=[];_tapIdx=-1;_tapA=0;_tapT=0;}
  function drawPilotBg(){
    ctx.fillStyle='#8fa87a';ctx.fillRect(0,0,W,H);
    ctx.fillStyle='rgba(0,0,0,.09)';
    for(let x=0;x<W;x+=3)for(let y=0;y<H;y+=3)ctx.fillRect(x,y,1,1);
  }
  function animatePilot(){
    ctx.clearRect(0,0,W,H);drawPilotBg();
    const now=new Date();
    // Title bar
    const tbH=18,tbY=Math.floor(H*.04);
    ctx.fillStyle='#1a2c10';ctx.fillRect(12,tbY,W-24,tbH);
    ctx.strokeStyle='#3a5020';ctx.lineWidth=1;ctx.strokeRect(12,tbY,W-24,tbH);
    ctx.fillStyle='#c0d8a0';ctx.font='bold 9px monospace';
    ctx.textAlign='left';ctx.fillText('All',20,tbY+12);
    ctx.textAlign='right';
    ctx.fillText(`${now.getHours()}:${String(now.getMinutes()).padStart(2,'0')}`,W-20,tbY+12);
    // Icon grid (4 columns)
    const cols=4,iw=Math.floor((W-24)/cols),ih=58,gY=tbY+tbH+6;
    PALM_ICONS.forEach((ic,idx)=>{
      if(gY+Math.floor(idx/cols)*ih>H-42)return;
      const col=idx%cols,row=Math.floor(idx/cols);
      const cx=12+col*iw+iw/2,cy=gY+row*ih+20;
      if(_tapIdx===idx&&_tapA>0){
        ctx.fillStyle=`rgba(255,255,255,${_tapA*.3})`;
        ctx.fillRect(12+col*iw+2,gY+row*ih+2,iw-4,ih-4);
      }
      ctx.fillStyle=ic.c;ctx.fillRect(cx-13,cy-17,26,26);
      ctx.strokeStyle='rgba(200,225,180,.5)';ctx.lineWidth=1;ctx.strokeRect(cx-13,cy-17,26,26);
      ctx.font=ic.e==='$'?'bold 12px monospace':'13px serif';
      ctx.textAlign='center';ctx.fillStyle='#fff';ctx.fillText(ic.e,cx,cy-1);
      ctx.fillStyle='#1a2c10';ctx.font='6px monospace';ctx.textAlign='center';
      ctx.fillText(ic.n.substring(0,9),cx,cy+14);
    });
    // Graffiti silk-screen area
    const gfY=H-32;
    ctx.fillStyle='rgba(0,0,0,.2)';ctx.fillRect(12,gfY,W-24,22);
    ctx.strokeStyle='rgba(180,210,150,.25)';ctx.lineWidth=1;ctx.strokeRect(12,gfY,W-24,22);
    ctx.strokeStyle='rgba(100,140,80,.3)';ctx.lineWidth=.5;
    ctx.beginPath();ctx.moveTo(W/2,gfY+3);ctx.lineTo(W/2,gfY+19);ctx.stroke();
    ctx.fillStyle='#5a7848';ctx.font='7px monospace';ctx.textAlign='center';
    ctx.fillText('abc',W/4+6,gfY+14);ctx.fillText('123',3*W/4-6,gfY+14);
    // Tap animation
    _tapT++;
    if(_tapA>0)_tapA=Math.max(0,_tapA-.02);
    if(_tapT%135===0){_tapIdx=Math.floor(Math.random()*PALM_ICONS.length);_tapA=1;}
    // LCD scanlines
    ctx.fillStyle='rgba(0,0,0,.028)';
    for(let sy=0;sy<H;sy+=2)ctx.fillRect(0,sy,W,1);
  }

  // ═══════════════════════════════════════════
  // PALM TREO — phone screen with sliding notifications
  // ═══════════════════════════════════════════
  const NOTIFS=[
    {icon:'📞',title:'Missed Call',body:'John Smith',sub:'2 calls · 5 min ago'},
    {icon:'💬',title:'SMS Message',body:"Hey, still at the office?",sub:'Mom · just now'},
    {icon:'📅',title:'Reminder',body:'Team Standup in 15 min',sub:'Conference Room B'},
    {icon:'📧',title:'Email',body:'Server Alert: CPU at 98%',sub:'alerts@corp.net · now'},
    {icon:'🔋',title:'Battery Low',body:'Please connect to charger',sub:'11% remaining'},
    {icon:'📡',title:'Network',body:'Wi-Fi connected',sub:'HomeNet-5G · just now'},
  ];
  let cards=[],notifTimer=0,notifIdx=0;
  function initPhone(){cards=[];notifTimer=160;notifIdx=0;}

  function drawTreoBg(){
    ctx.fillStyle='#060612';ctx.fillRect(0,0,W,H);
    const g=ctx.createRadialGradient(W*.5,H*.5,0,W*.5,H*.5,H*.55);
    g.addColorStop(0,'rgba(255,100,0,.07)');g.addColorStop(1,'rgba(0,0,0,0)');
    ctx.fillStyle=g;ctx.fillRect(0,0,W,H);
  }
  function animateTreo(){
    ctx.clearRect(0,0,W,H);drawTreoBg();
    // Phone dimensions — centred, scales with window
    const pw=Math.min(320,W*.55),ph=Math.min(560,H*.8);
    const px=(W-pw)*.5,py=(H-ph)*.5;
    // Phone shell
    ctx.fillStyle='#111118';rr(px-10,py-22,pw+20,ph+44,28);ctx.fill();
    ctx.strokeStyle='rgba(255,140,0,.28)';ctx.lineWidth=1.5;rr(px-10,py-22,pw+20,ph+44,28);ctx.stroke();
    // Screen area
    ctx.fillStyle='#0a0a16';rr(px,py,pw,ph,8);ctx.fill();
    // Status bar
    ctx.fillStyle='#cc6600';ctx.fillRect(px,py,pw,24);
    // Time
    const now=new Date();
    ctx.fillStyle='#000';ctx.font='bold 14px Tahoma,sans-serif';ctx.textAlign='left';
    ctx.fillText(`${now.getHours()}:${String(now.getMinutes()).padStart(2,'0')}`,px+8,py+17);
    // Signal bars
    for(let i=0;i<5;i++){const bh=4+i*3,bx=px+pw-56+i*10,by=py+22-bh;ctx.fillStyle=i<4?'#000':'rgba(0,0,0,.3)';ctx.fillRect(bx,by,7,bh-1);}
    // Battery
    ctx.strokeStyle='#000';ctx.lineWidth=1.5;ctx.strokeRect(px+pw-12,py+6,10,13);
    ctx.fillStyle='#000';ctx.fillRect(px+pw-11,py+7,7,11);ctx.fillRect(px+pw-9,py+4,4,3);
    // Date bar
    ctx.fillStyle='#0e0e22';ctx.fillRect(px,py+24,pw,38);
    ctx.fillStyle='#ff8c00';ctx.font='bold 19px Tahoma,sans-serif';ctx.textAlign='left';
    ctx.fillText(now.toLocaleDateString('en-US',{weekday:'short',month:'short',day:'numeric'}),px+10,py+50);
    // Separator
    ctx.strokeStyle='rgba(255,140,0,.45)';ctx.lineWidth=1;
    ctx.beginPath();ctx.moveTo(px,py+62);ctx.lineTo(px+pw,py+62);ctx.stroke();
    // Spawn new card
    notifTimer++;
    if(notifTimer>200||cards.length===0){
      notifTimer=0;
      const slot=py+72+cards.filter(c=>c.phase!=='gone').length*76;
      cards.push({n:NOTIFS[notifIdx%NOTIFS.length],y:py-90,target:slot,phase:'enter',wait:0});
      notifIdx++;
    }
    // Clip to screen interior
    ctx.save();ctx.beginPath();ctx.rect(px,py+63,pw,ph-93);ctx.clip();
    cards=cards.filter(c=>c.phase!=='gone');
    cards.forEach(c=>{
      if(c.phase==='enter'){c.y+=(c.target-c.y)*.12;if(Math.abs(c.y-c.target)<1){c.y=c.target;c.phase='show';}}
      else if(c.phase==='show'){c.wait++;if(c.wait>300)c.phase='leave';}
      else if(c.phase==='leave'){c.y-=4;if(c.y<py-100)c.phase='gone';}
      // Card
      const cx=px+8,cw=pw-16,ch=68,cy=Math.round(c.y);
      ctx.fillStyle='#1a1a2e';rr(cx,cy,cw,ch,6);ctx.fill();
      // Orange left accent
      ctx.fillStyle='#ff8c00';ctx.fillRect(cx,cy,4,ch);
      rr(cx,cy,4,ch,0);// square left edge
      // Icon
      ctx.font='18px serif';ctx.textAlign='left';ctx.fillText(c.n.icon,cx+10,cy+28);
      // Title
      ctx.fillStyle='#ff8c00';ctx.font='bold 11px Tahoma,sans-serif';ctx.fillText(c.n.title,cx+36,cy+24);
      // Body
      ctx.fillStyle='#ddaa88';ctx.font='10px Tahoma,sans-serif';ctx.fillText(c.n.body,cx+36,cy+40);
      // Sub
      ctx.fillStyle='#8a6644';ctx.font='9px Tahoma,sans-serif';ctx.fillText(c.n.sub,cx+36,cy+56);
      // Border
      ctx.strokeStyle='rgba(255,140,0,.22)';ctx.lineWidth=1;rr(cx,cy,cw,ch,6);ctx.stroke();
    });
    ctx.restore();
    // Softkey bar
    ctx.fillStyle='#12080a';ctx.fillRect(px,py+ph-32,pw,32);
    ctx.fillStyle='#ff8c00';ctx.font='bold 10px Tahoma,sans-serif';
    ctx.textAlign='left';ctx.fillText('Menu',px+10,py+ph-11);
    ctx.textAlign='right';ctx.fillText('Done',px+pw-10,py+ph-11);
    ctx.fillStyle='#663322';ctx.textAlign='center';ctx.fillText('· · ·',px+pw/2,py+ph-11);
    // Home button
    ctx.fillStyle='#0e0612';rr(px+pw/2-16,py+ph+4,32,20,10);ctx.fill();
    ctx.strokeStyle='rgba(255,140,0,.35)';ctx.lineWidth=1;rr(px+pw/2-16,py+ph+4,32,20,10);ctx.stroke();
    ctx.fillStyle='rgba(255,140,0,.4)';ctx.font='10px monospace';ctx.textAlign='center';
    ctx.fillText('⌂',px+pw/2,py+ph+17);
  }

  // ── Shared ──
  function resize(){W=cv.width=window.innerWidth;H=cv.height=window.innerHeight;if(_th==='palmos')initWins();else initPhone();}
  function animate(){if(cv.style.display==='none'){animId=null;return;}if(_th==='palmtreo')animateTreo();else animatePilot();animId=requestAnimationFrame(animate);}
  window.addEventListener('resize',resize);
  window._startPalmos=(th)=>{_th=th||'palmos';W=cv.width=window.innerWidth;H=cv.height=window.innerHeight;if(_th==='palmos'){ctx.fillStyle='#8fa87a';ctx.fillRect(0,0,W,H);initWins();}else{ctx.fillStyle='#060612';ctx.fillRect(0,0,W,H);initPhone();}if(!animId)animate();};
  window._stopPalmos=()=>{if(animId){cancelAnimationFrame(animId);animId=null;}ctx.clearRect(0,0,cv.width,cv.height);};
})();

// Windows Mobile 6 / Pocket PC — authentic dark-navy night city (real WM6 Today screen aesthetic)
// WM6 shipped with a dark navy gradient + city silhouette + Windows flag watermark
(function(){const cv=document.getElementById('canvas-pocketpc'),ctx=cv.getContext('2d');let t=0,animId=null,stars=[],buildings=[],windowLights=[];function resize(){cv.width=window.innerWidth;cv.height=window.innerHeight;initScene();}function initScene(){const W=cv.width,H=cv.height;stars=Array.from({length:48},()=>({x:Math.random()*W,y:Math.random()*H*.62,r:.5+Math.random()*.9,p:Math.random()*Math.PI*2}));const rawB=[[0,.84,3.4],[3.4,.69,2.6],[6,.77,3.9],[9.9,.64,3.1],[13,.79,2.6],[15.6,.71,3.4],[19,.87,3.1],[22,.74,2.6],[24.6,.81,3.4]];buildings=rawB.map(([bx,bh,bwm])=>({rx:bx/27.5*W,ry:(1-bh)*H,rw:bwm/27.5*W,rh:bh*H}));windowLights=[];buildings.forEach(b=>{for(let wy=b.ry+7;wy<b.ry+b.rh-12;wy+=12){for(let wx=b.rx+4;wx<b.rx+b.rw-5;wx+=9){if(Math.random()>.48)windowLights.push({x:wx,y:wy,on:Math.random()>.28,amber:Math.random()>.7});}}});}function animate(){if(cv.style.display==='none'){animId=null;return;}t+=.006;const W=cv.width,H=cv.height;const sky=ctx.createLinearGradient(0,0,0,H*.88);sky.addColorStop(0,'#00040f');sky.addColorStop(.32,'#000c26');sky.addColorStop(.65,'#001844');sky.addColorStop(1,'#002560');ctx.fillStyle=sky;ctx.fillRect(0,0,W,H);for(let i=0;i<3;i++){const ay=H*(.11+i*.23)+Math.sin(t*.38+i)*H*.028;const ag=ctx.createLinearGradient(0,ay-22,0,ay+22);const a=.038+.018*Math.sin(t*.65+i*2.2);ag.addColorStop(0,'rgba(0,50,160,0)');ag.addColorStop(.5,`rgba(8,80,200,${a})`);ag.addColorStop(1,'rgba(0,50,160,0)');ctx.fillStyle=ag;ctx.fillRect(0,ay-22,W,44);}stars.forEach(s=>{s.p+=.016;ctx.fillStyle=`rgba(180,210,255,${.22+.18*Math.sin(s.p)})`;ctx.beginPath();ctx.arc(s.x,s.y,s.r,0,Math.PI*2);ctx.fill();});const fx=W*.5-21,fy=H*.22,fs=18,gap=3;['#f25022','#7fba00','#00a4ef','#ffb900'].forEach((c,i)=>{const ix=i%2,iy=Math.floor(i/2);ctx.fillStyle=c;ctx.globalAlpha=.12+.035*Math.sin(t*.45+i);ctx.fillRect(fx+ix*(fs+gap),fy+iy*(fs+gap),fs,fs);});ctx.globalAlpha=1;buildings.forEach(b=>{const bg=ctx.createLinearGradient(0,b.ry,0,b.ry+b.rh);bg.addColorStop(0,'#000820');bg.addColorStop(1,'#000510');ctx.fillStyle=bg;ctx.fillRect(b.rx,b.ry,b.rw,b.rh);});windowLights.forEach(wl=>{ctx.fillStyle=wl.on?(wl.amber?'rgba(255,200,80,.58)':'rgba(180,220,255,.52)'):'rgba(30,60,120,.06)';ctx.fillRect(wl.x,wl.y,4,5);});animId=requestAnimationFrame(animate);}window.addEventListener('resize',resize);resize();window._startPocketpc=()=>{ctx.fillStyle='#000410';ctx.fillRect(0,0,cv.width,cv.height);if(!animId)animate();};window._stopPocketpc=()=>{if(animId){cancelAnimationFrame(animId);animId=null;}ctx.clearRect(0,0,cv.width,cv.height);};})();

// macOS X Panther/Tiger — blue wave ribbons (matches classic blue Aqua wallpaper)
(function(){const cv=document.getElementById('canvas-macosx'),ctx=cv.getContext('2d');let t=0,animId=null;function resize(){cv.width=window.innerWidth;cv.height=window.innerHeight;}function animate(){if(cv.style.display==='none'){animId=null;return;}t+=.004;ctx.fillStyle='rgba(18,60,120,.12)';ctx.fillRect(0,0,cv.width,cv.height);// Draw 6 layered wave ribbons in deep-blue tones
const layers=[{amp:.18,freq:.0025,spd:.55,off:.05,h:210,l:62,a:.22},{amp:.22,freq:.002,spd:.4,off:.18,h:205,l:58,a:.2},{amp:.16,freq:.003,spd:.7,off:.34,h:215,l:70,a:.18},{amp:.26,freq:.0018,spd:.3,off:.5,h:200,l:55,a:.16},{amp:.14,freq:.0035,spd:.9,off:.66,h:218,l:75,a:.14},{amp:.2,freq:.0022,spd:.5,off:.82,h:207,l:65,a:.12}];layers.forEach((l,i)=>{ctx.beginPath();const yBase=cv.height*l.off;for(let x=0;x<=cv.width;x+=3){const y=yBase+cv.height*l.amp*Math.sin(x*l.freq+t*l.spd+i)+cv.height*l.amp*.5*Math.sin(x*l.freq*1.7-t*l.spd*.8+i*.5);x===0?ctx.moveTo(x,y):ctx.lineTo(x,y);}ctx.lineTo(cv.width,cv.height);ctx.lineTo(0,cv.height);ctx.closePath();const g=ctx.createLinearGradient(0,0,cv.width,0);g.addColorStop(0,`hsla(${l.h},80%,${l.l}%,${l.a*.5})`);g.addColorStop(.4,`hsla(${l.h},90%,${l.l+8}%,${l.a})`);g.addColorStop(.7,`hsla(${l.h-5},85%,${l.l+4}%,${l.a*.8})`);g.addColorStop(1,`hsla(${l.h},80%,${l.l}%,${l.a*.5})`);ctx.fillStyle=g;ctx.fill();});// Subtle highlight pass
ctx.beginPath();for(let x=0;x<=cv.width;x+=3){const y=cv.height*.28+cv.height*.08*Math.sin(x*.002+t*.6)+cv.height*.04*Math.sin(x*.004-t*.9);x===0?ctx.moveTo(x,y):ctx.lineTo(x,y);}ctx.lineTo(cv.width,cv.height*.22);ctx.lineTo(0,cv.height*.22);ctx.closePath();const hl=ctx.createLinearGradient(0,0,cv.width,0);hl.addColorStop(0,'rgba(200,230,255,0)');hl.addColorStop(.5,'rgba(200,230,255,.08)');hl.addColorStop(1,'rgba(200,230,255,0)');ctx.fillStyle=hl;ctx.fill();animId=requestAnimationFrame(animate);}window.addEventListener('resize',resize);resize();window._startMacosx=()=>{ctx.fillStyle='#1a4a8a';ctx.fillRect(0,0,cv.width,cv.height);if(!animId)animate();};window._stopMacosx=()=>{if(animId){cancelAnimationFrame(animId);animId=null;}ctx.clearRect(0,0,cv.width,cv.height);};})();

// macOS Sonoma — soft color orbs (light mode canvas overlay)
(function(){const cv=document.getElementById('canvas-macos'),ctx=cv.getContext('2d');let t=0,animId=null;function resize(){cv.width=window.innerWidth;cv.height=window.innerHeight;}function blob(cx,cy,r,h,ph){ctx.beginPath();for(let i=0;i<=20;i++){const a=(i/20)*Math.PI*2,w=r*(.88+.12*Math.sin(a*4+ph+t*.5));ctx.lineTo(cx+Math.cos(a)*w,cy+Math.sin(a)*w);}ctx.closePath();const g=ctx.createRadialGradient(cx,cy,0,cx,cy,r);g.addColorStop(0,`hsla(${h},70%,75%,.5)`);g.addColorStop(1,`hsla(${h},60%,80%,0)`);ctx.fillStyle=g;ctx.fill();}function animate(){if(cv.style.display==='none'){animId=null;return;}t+=.004;const W=cv.width,H=cv.height;ctx.clearRect(0,0,W,H);ctx.globalCompositeOperation='multiply';blob(W*.35+Math.sin(t*.3)*W*.1,H*.4+Math.cos(t*.25)*H*.1,H*.55,220,0);blob(W*.7+Math.cos(t*.28)*W*.08,H*.35+Math.sin(t*.32)*H*.08,H*.45,200,2);blob(W*.55+Math.sin(t*.35)*W*.06,H*.7+Math.cos(t*.3)*H*.07,H*.5,250,4);ctx.globalCompositeOperation='source-over';animId=requestAnimationFrame(animate);}window.addEventListener('resize',resize);resize();window._startMacos=()=>{if(!animId)animate();};window._stopMacos=()=>{if(animId){cancelAnimationFrame(animId);animId=null;}ctx.clearRect(0,0,cv.width,cv.height);};})();

// ===== READ-ONLY USER: hide editing controls =====
if (!DASH_CAN_EDIT) {
  document.querySelectorAll('.card-edit-btn, .section-btn, #edit-mode-toggle').forEach(el => el.style.display = 'none');
}

// Ubuntu 22.04 "Jammy Jellyfish" — animated bioluminescent jellyfish in deep ocean
(function(){const cv=document.getElementById('canvas-ubuntu'),ctx=cv.getContext('2d');let t=0,animId=null,bubbles=[];function resize(){cv.width=window.innerWidth;cv.height=window.innerHeight;bubbles=Array.from({length:65},()=>({x:Math.random()*cv.width,y:cv.height+Math.random()*cv.height*.6,r:.7+Math.random()*2.0,sp:.18+Math.random()*.55,sway:Math.random()*Math.PI*2,swayS:.012+Math.random()*.022,alpha:.12+Math.random()*.28}));}function drawJellyfish(W,H){const cx=W*.5,cy=H*.4+Math.sin(t*.38)*H*.022;const bw=Math.min(W,H)*.26,bh=bw*.52;const pulse=1+.048*Math.sin(t*1.75);// Outer glow halo
const halo=ctx.createRadialGradient(cx,cy,bw*.3,cx,cy,bw*2.4);halo.addColorStop(0,'rgba(100,200,220,.07)');halo.addColorStop(.6,'rgba(60,140,180,.03)');halo.addColorStop(1,'rgba(0,0,0,0)');ctx.fillStyle=halo;ctx.beginPath();ctx.arc(cx,cy,bw*2.4,0,Math.PI*2);ctx.fill();// Bell dome
ctx.save();ctx.translate(cx,cy);ctx.scale(pulse,pulse*.93);const bell=ctx.createRadialGradient(0,-bh*.25,bh*.04,0,-bh*.1,bw);bell.addColorStop(0,'rgba(215,245,255,.5)');bell.addColorStop(.25,'rgba(155,215,235,.34)');bell.addColorStop(.6,'rgba(80,160,210,.2)');bell.addColorStop(1,'rgba(35,90,180,.05)');ctx.fillStyle=bell;ctx.beginPath();ctx.moveTo(-bw,0);for(let i=0;i<=44;i++){const a=Math.PI*(i/44);ctx.lineTo(Math.cos(a)*bw,-Math.abs(Math.sin(a))*bh);}ctx.closePath();ctx.fill();// Bell edge shimmer
ctx.strokeStyle='rgba(180,235,252,.38)';ctx.lineWidth=1.8;ctx.beginPath();ctx.moveTo(-bw,0);for(let i=0;i<=44;i++){const a=Math.PI*(i/44);ctx.lineTo(Math.cos(a)*bw,-Math.abs(Math.sin(a))*bh);}ctx.stroke();// Inner radial ribs (veins)
for(let i=0;i<10;i++){const a=Math.PI*(i/9);const bright=.07+.04*Math.sin(t*1.2+i*.7);ctx.strokeStyle=`rgba(160,225,245,${bright})`;ctx.lineWidth=.8;ctx.beginPath();ctx.moveTo(0,-bh*.04);ctx.lineTo(Math.cos(a)*bw*.88,-Math.abs(Math.sin(a))*bh*.92);ctx.stroke();}// Sub-umbrella scalloped fringe
for(let i=0;i<16;i++){const a=Math.PI*(i/15);const fx=Math.cos(a)*bw,fy=0;const fr=bw*.07;const fa=.12+.06*Math.sin(t*2+i*.6);ctx.fillStyle=`rgba(180,235,252,${fa})`;ctx.beginPath();ctx.arc(fx,fy,fr,0,Math.PI*2);ctx.fill();}ctx.restore();// Tentacles
const numT=16;for(let i=0;i<numT;i++){const tx0=cx-bw*.88+(i/(numT-1))*bw*1.76,baseY=cy+3;const tlen=bh*(1.7+.7*Math.sin(t*.85+i*.65));ctx.strokeStyle=`rgba(${120+i*6},${195+i*2},232,${.1+.07*Math.sin(t*.7+i*.5)})`;ctx.lineWidth=.9+.5*Math.sin(t*.9+i);ctx.beginPath();ctx.moveTo(tx0,baseY);for(let s=0;s<=14;s++){ctx.lineTo(tx0+Math.sin(t*1.15+i*.75+s*.42)*17*(s/14),baseY+s*(tlen/14));}ctx.stroke();}// Oral arms (thick inner tentacles)
for(let i=0;i<5;i++){const tx0=cx-bw*.22+(i/4)*bw*.44,tlen=bh*2.5;ctx.strokeStyle=`rgba(185,238,255,${.16+.06*Math.sin(t*.75+i)})`;ctx.lineWidth=1.8;ctx.beginPath();ctx.moveTo(tx0,cy+2);for(let s=0;s<=18;s++){ctx.lineTo(tx0+Math.sin(t+i*1.4+s*.48)*24*(s/18),cy+2+s*(tlen/18));}ctx.stroke();}}function animate(){if(cv.style.display==='none'){animId=null;return;}t+=.011;const W=cv.width,H=cv.height;// Deep ocean gradient (Ubuntu aubergine-to-black-ocean)
const ocean=ctx.createLinearGradient(0,0,0,H);ocean.addColorStop(0,'#04070c');ocean.addColorStop(.35,'#050b14');ocean.addColorStop(.75,'#060c16');ocean.addColorStop(1,'#030810');ctx.fillStyle=ocean;ctx.fillRect(0,0,W,H);// Caustic light patches on ocean floor
for(let i=0;i<3;i++){const lx=W*(.15+i*.35)+Math.sin(t*.28+i)*W*.05;const lr=W*.14;const lg=ctx.createRadialGradient(lx,H*.9,0,lx,H*.9,lr);lg.addColorStop(0,`rgba(30,110,150,.05)`);lg.addColorStop(1,'rgba(0,0,0,0)');ctx.fillStyle=lg;ctx.beginPath();ctx.arc(lx,H*.9,lr,0,Math.PI*2);ctx.fill();}// Ascending bubbles
bubbles.forEach(b=>{b.sway+=b.swayS;b.x+=Math.sin(b.sway)*.38;b.y-=b.sp;if(b.y<-15){b.y=H+10;b.x=Math.random()*W;}ctx.strokeStyle=`rgba(90,175,215,${b.alpha})`;ctx.lineWidth=.55;ctx.beginPath();ctx.arc(b.x,b.y,b.r,0,Math.PI*2);ctx.stroke();});drawJellyfish(W,H);animId=requestAnimationFrame(animate);}window.addEventListener('resize',resize);resize();window._startUbuntu=()=>{ctx.fillStyle='#04070c';ctx.fillRect(0,0,cv.width,cv.height);if(!animId)animate();};window._stopUbuntu=()=>{if(animId){cancelAnimationFrame(animId);animId=null;}ctx.clearRect(0,0,cv.width,cv.height);};})();

// ===== WINTER SNOWFALL =====
(function(){const cv=document.getElementById('canvas-snow'),ctx=cv.getContext('2d');let flakes=[],animId=null;function resize(){cv.width=window.innerWidth;cv.height=window.innerHeight;flakes=[];for(let i=0;i<160;i++)flakes.push({x:Math.random()*cv.width,y:Math.random()*cv.height,r:1+Math.random()*3,sp:0.4+Math.random()*1.2,sw:Math.random()*Math.PI*2,sd:0.005+Math.random()*.01,op:0.5+Math.random()*.5});}function animate(){if(cv.style.display==='none'){animId=null;return;}ctx.clearRect(0,0,cv.width,cv.height);flakes.forEach(f=>{f.y+=f.sp;f.x+=Math.sin(f.sw)*0.5;f.sw+=f.sd;if(f.y>cv.height+10){f.y=-10;f.x=Math.random()*cv.width;}if(f.x>cv.width+10)f.x=-10;if(f.x<-10)f.x=cv.width+10;ctx.beginPath();ctx.arc(f.x,f.y,f.r,0,Math.PI*2);ctx.fillStyle=`rgba(220,240,255,${f.op})`;ctx.fill();});animId=requestAnimationFrame(animate);}window.addEventListener('resize',resize);resize();window._startSnow=()=>{if(!animId)animate();};window._stopSnow=()=>{if(animId){cancelAnimationFrame(animId);animId=null;}ctx.clearRect(0,0,cv.width,cv.height);};})();

// ===== AUTUMN LEAF FALL =====
(function(){const cv=document.getElementById('canvas-leaves'),ctx=cv.getContext('2d');const COLS=['#c0392b','#e67e22','#f39c12','#d35400','#922b21','#cb4335'];let leaves=[],animId=null;function resize(){cv.width=window.innerWidth;cv.height=window.innerHeight;leaves=[];for(let i=0;i<80;i++)leaves.push({x:Math.random()*cv.width,y:Math.random()*cv.height,r:6+Math.random()*12,sp:0.6+Math.random()*1.4,sw:Math.random()*Math.PI*2,sd:0.008+Math.random()*.012,rot:Math.random()*Math.PI*2,rotSpd:(Math.random()-.5)*.06,col:COLS[Math.floor(Math.random()*COLS.length)],op:0.6+Math.random()*.4});}function drawLeaf(l){ctx.save();ctx.translate(l.x,l.y);ctx.rotate(l.rot);ctx.globalAlpha=l.op;ctx.fillStyle=l.col;ctx.beginPath();ctx.ellipse(0,0,l.r,l.r*.5,0,0,Math.PI*2);ctx.fill();ctx.restore();}function animate(){if(cv.style.display==='none'){animId=null;return;}ctx.clearRect(0,0,cv.width,cv.height);leaves.forEach(l=>{l.y+=l.sp;l.x+=Math.sin(l.sw)*.8;l.sw+=l.sd;l.rot+=l.rotSpd;if(l.y>cv.height+20){l.y=-20;l.x=Math.random()*cv.width;}drawLeaf(l);});ctx.globalAlpha=1;animId=requestAnimationFrame(animate);}window.addEventListener('resize',resize);resize();window._startLeaves=()=>{if(!animId)animate();};window._stopLeaves=()=>{if(animId){cancelAnimationFrame(animId);animId=null;}ctx.clearRect(0,0,cv.width,cv.height);};})();

// ===== SPRING PETALS =====
(function(){const cv=document.getElementById('canvas-petals'),ctx=cv.getContext('2d');const COLS=['#ffb7c5','#ff90a8','#ffc0e0','#ffaad4','#ffe0f0','#f8c8d8'];let petals=[],animId=null;function resize(){cv.width=window.innerWidth;cv.height=window.innerHeight;petals=[];for(let i=0;i<100;i++)petals.push({x:Math.random()*cv.width,y:Math.random()*cv.height,r:4+Math.random()*9,sp:0.4+Math.random()*1,sw:Math.random()*Math.PI*2,sd:0.006+Math.random()*.01,rot:Math.random()*Math.PI*2,rotSpd:(Math.random()-.5)*.04,col:COLS[Math.floor(Math.random()*COLS.length)],op:0.5+Math.random()*.5});}function drawPetal(p){ctx.save();ctx.translate(p.x,p.y);ctx.rotate(p.rot);ctx.globalAlpha=p.op;ctx.fillStyle=p.col;ctx.beginPath();ctx.ellipse(0,0,p.r,p.r*.45,0,0,Math.PI*2);ctx.fill();ctx.restore();}function animate(){if(cv.style.display==='none'){animId=null;return;}ctx.clearRect(0,0,cv.width,cv.height);petals.forEach(p=>{p.y+=p.sp;p.x+=Math.sin(p.sw)*.6;p.sw+=p.sd;p.rot+=p.rotSpd;if(p.y>cv.height+20){p.y=-20;p.x=Math.random()*cv.width;}drawPetal(p);});ctx.globalAlpha=1;animId=requestAnimationFrame(animate);}window.addEventListener('resize',resize);resize();window._startPetals=()=>{if(!animId)animate();};window._stopPetals=()=>{if(animId){cancelAnimationFrame(animId);animId=null;}ctx.clearRect(0,0,cv.width,cv.height);};})();

// ===== 4TH OF JULY FIREWORKS =====
(function(){const cv=document.getElementById('canvas-fireworks'),ctx=cv.getContext('2d');let rockets=[],animId=null;const COLS=['#ff4444','#ffffff','#4444ff','#ff6666','#aaaaff','#ffaaaa'];function spawn(){const x=0.2*cv.width+Math.random()*0.6*cv.width,ty=0.1*cv.height+Math.random()*0.4*cv.height;const parts=[];for(let i=0;i<60;i++){const a=Math.random()*Math.PI*2,sp=1+Math.random()*5;parts.push({x,y:cv.height,tx:x,ty,vx:Math.cos(a)*sp,vy:Math.sin(a)*sp,col:COLS[Math.floor(Math.random()*COLS.length)],life:1,phase:'fly',startX:x,startY:cv.height});}rockets.push({parts,phase:'fly',ty,sy:cv.height,cx:x,cy:cv.height,col:COLS[Math.floor(Math.random()*COLS.length)]});}function animate(){if(cv.style.display==='none'){animId=null;return;}ctx.fillStyle='rgba(2,8,24,.25)';ctx.fillRect(0,0,cv.width,cv.height);rockets=rockets.filter(r=>{if(r.phase==='fly'){r.cy-=12;if(r.cy<=r.ty){r.phase='burst';r.parts.forEach(p=>{p.x=r.cx;p.y=r.cy;});}else{ctx.beginPath();ctx.arc(r.cx,r.cy,2,0,Math.PI*2);ctx.fillStyle='rgba(255,220,100,.9)';ctx.fill();}return true;}if(r.phase==='burst'){let alive=false;r.parts.forEach(p=>{if(p.life<=0)return;p.x+=p.vx;p.y+=p.vy;p.vy+=.07;p.life-=.018;p.vx*=.97;if(p.life>0){alive=true;ctx.beginPath();ctx.arc(p.x,p.y,1.5,0,Math.PI*2);ctx.fillStyle=p.col.replace(')',`,${p.life})`).replace('rgb','rgba');const [rr,gg,bb]=[parseInt(p.col.slice(1,3),16),parseInt(p.col.slice(3,5),16),parseInt(p.col.slice(5,7),16)];ctx.fillStyle=`rgba(${rr},${gg},${bb},${p.life})`;ctx.fill();}});return alive;}return false;});if(Math.random()<.025)spawn();animId=requestAnimationFrame(animate);}window.addEventListener('resize',()=>{cv.width=window.innerWidth;cv.height=window.innerHeight;});cv.width=window.innerWidth;cv.height=window.innerHeight;window._startFireworks=()=>{if(!animId)animate();};window._stopFireworks=()=>{if(animId){cancelAnimationFrame(animId);animId=null;}ctx.clearRect(0,0,cv.width,cv.height);rockets=[];};})();

// ===== CHRISTMAS STARS (now just snowfall — same as winter) =====
// christmas reuses canvas-snow, no separate canvas-stars needed
// canvas-stars kept for compatibility but unused
(function(){const cv=document.getElementById('canvas-stars'),ctx=cv.getContext('2d');window._startStars=()=>{};window._stopStars=()=>{if(cv)ctx.clearRect(0,0,cv.width,cv.height);};})();

// ===== WIN XP BLISS — Rolling green hills + animated clouds =====
(function(){const cv=document.getElementById('canvas-bliss'),ctx=cv.getContext('2d');let clouds=[],animId=null,t=0;function resize(){cv.width=window.innerWidth;cv.height=window.innerHeight;initClouds();}function initClouds(){clouds=[];for(let i=0;i<7;i++)clouds.push({x:Math.random()*cv.width,y:60+Math.random()*cv.height*.25,sx:.18+Math.random()*.2,r:40+Math.random()*60,alpha:.85+Math.random()*.15});}function drawCloud(c){ctx.save();ctx.globalAlpha=c.alpha;ctx.fillStyle='#fff';const r=c.r;ctx.beginPath();ctx.arc(c.x,c.y,r,0,Math.PI*2);ctx.arc(c.x+r*.8,c.y-r*.3,r*.7,0,Math.PI*2);ctx.arc(c.x-r*.7,c.y-r*.1,r*.6,0,Math.PI*2);ctx.arc(c.x+r*.4,c.y+r*.25,r*.5,0,Math.PI*2);ctx.fill();ctx.restore();}function animate(){if(cv.style.display==='none'){animId=null;return;}t+=.005;const W=cv.width,H=cv.height;// Sky gradient
const sky=ctx.createLinearGradient(0,0,0,H*.55);sky.addColorStop(0,'#3a9ad9');sky.addColorStop(1,'#70c5f0');ctx.fillStyle=sky;ctx.fillRect(0,0,W,H*.55);// Bliss hills
const hill=ctx.createLinearGradient(0,H*.4,0,H);hill.addColorStop(0,'#5db050');hill.addColorStop(.4,'#4ba040');hill.addColorStop(1,'#2d6e20');ctx.fillStyle=hill;ctx.beginPath();ctx.moveTo(0,H);// Main hill
const midY=H*.48,sw=W;for(let x=0;x<=sw;x+=4){const y=midY+H*.08*Math.sin((x/sw)*Math.PI*1.2+.3)+H*.04*Math.sin((x/sw)*Math.PI*2.5+t*.3);x===0?ctx.lineTo(x,y):ctx.lineTo(x,y);}ctx.lineTo(W,H);ctx.closePath();ctx.fill();// Move clouds
clouds.forEach(c=>{c.x+=c.sx;if(c.x>W+c.r*2)c.x=-c.r*2;drawCloud(c);});animId=requestAnimationFrame(animate);}window.addEventListener('resize',resize);resize();window._startBliss=()=>{if(!animId)animate();};window._stopBliss=()=>{if(animId){cancelAnimationFrame(animId);animId=null;}ctx.clearRect(0,0,cv.width,cv.height);};})();

// ===== SUMMER — Beach waves + sun rays =====
(function(){const cv=document.getElementById('canvas-summer'),ctx=cv.getContext('2d');let t=0,animId=null;function resize(){cv.width=window.innerWidth;cv.height=window.innerHeight;}function animate(){if(cv.style.display==='none'){animId=null;return;}t+=.012;const W=cv.width,H=cv.height;// Sky
const sky=ctx.createLinearGradient(0,0,0,H*.5);sky.addColorStop(0,'#0a6aba');sky.addColorStop(1,'#55b0e0');ctx.fillStyle=sky;ctx.fillRect(0,0,W,H*.5);// Sun
const sx=W*.78,sy=H*.15,sr=Math.min(W,H)*.08;// Rays
ctx.save();ctx.translate(sx,sy);for(let i=0;i<12;i++){ctx.save();ctx.rotate(i*Math.PI/6+t*.3);ctx.globalAlpha=.15+.08*Math.sin(t*2+i);ctx.fillStyle='#fff9a0';ctx.beginPath();ctx.moveTo(sr*1.2,-4);ctx.lineTo(sr*2.5,0);ctx.lineTo(sr*1.2,4);ctx.closePath();ctx.fill();ctx.restore();}ctx.restore();// Sun disc
const sg=ctx.createRadialGradient(sx,sy,0,sx,sy,sr);sg.addColorStop(0,'#fff5a0');sg.addColorStop(.6,'#f5c300');sg.addColorStop(1,'#f5a000');ctx.fillStyle=sg;ctx.globalAlpha=1;ctx.beginPath();ctx.arc(sx,sy,sr,0,Math.PI*2);ctx.fill();// Sand
const sand=ctx.createLinearGradient(0,H*.5,0,H);sand.addColorStop(0,'#f5dfa0');sand.addColorStop(1,'#e8c060');ctx.fillStyle=sand;ctx.fillRect(0,H*.5,W,H*.5);// Ocean waves
for(let i=0;i<3;i++){const wY=H*.5+i*18;ctx.beginPath();for(let x=0;x<=W;x+=6){const y=wY+8*Math.sin((x/W)*Math.PI*5+t*(1.5-i*.3)+i*1.2);x===0?ctx.moveTo(x,y):ctx.lineTo(x,y);}ctx.lineTo(W,H*.5);ctx.lineTo(0,H*.5);ctx.closePath();const wg=ctx.createLinearGradient(0,wY,0,wY+20);wg.addColorStop(0,`rgba(${80+i*20},${160+i*10},${230-i*10},${.5-i*.1})`);wg.addColorStop(1,'rgba(245,224,160,0)');ctx.fillStyle=wg;ctx.fill();}// Foam
ctx.globalAlpha=.55;for(let i=0;i<3;i++){ctx.beginPath();for(let x=0;x<=W;x+=6){const y=H*.5+i*18+8*Math.sin((x/W)*Math.PI*5+t*(1.5-i*.3)+i*1.2);x===0?ctx.moveTo(x,y):ctx.lineTo(x,y);}ctx.strokeStyle='rgba(255,255,255,.6)';ctx.lineWidth=2;ctx.stroke();}ctx.globalAlpha=1;animId=requestAnimationFrame(animate);}window.addEventListener('resize',resize);resize();window._startSummer=()=>{if(!animId)animate();};window._stopSummer=()=>{if(animId){cancelAnimationFrame(animId);animId=null;}ctx.clearRect(0,0,cv.width,cv.height);};})();

// ===== PROFILES MODAL =====
function openProfilesModal(){
  const m=document.getElementById('profiles-modal');if(!m)return;
  m.style.display='flex';
  _refreshProfilesList();
}
function closeProfilesModal(){
  const m=document.getElementById('profiles-modal');if(m)m.style.display='none';
}
function _refreshProfilesList(){
  const list=document.getElementById('profiles-list');if(!list)return;
  list.innerHTML='<div style="opacity:.5;font-size:12px;padding:8px 0;">Loading…</div>';
  fetch('save_layout.php?action=list').then(r=>r.json()).then(json=>{
    const profiles=json.layouts||[];
    const lastLoaded=localStorage.getItem('dash-last-profile')||'';
    if(!profiles.length){
      list.innerHTML='<div style="opacity:.5;font-size:12px;padding:8px 0;text-align:center;">No saved profiles yet.</div>';
      return;
    }
    list.innerHTML='';
    profiles.forEach(p=>{
      const isLast=(p.name===lastLoaded);
      const row=document.createElement('div');
      row.className='profile-row'+(isLast?' profile-row-active':'');
      const themeTag=p.theme?`<span class="profile-theme-tag">${_esc(p.theme)}${p.wallpaper_variant&&p.wallpaper_variant!=='default'?' · '+_esc(p.wallpaper_variant):''}</span>`:'';
      const lastTag=isLast?'<span class="profile-last-tag">★ this machine</span>':'';
      // Build rows using DOM, not innerHTML onclick, to avoid attribute escaping issues
      const top=document.createElement('div');top.className='profile-row-top';
      const nm=document.createElement('span');nm.className='profile-name';nm.textContent=p.name;
      top.appendChild(nm);
      if(p.theme){const tt=document.createElement('span');tt.className='profile-theme-tag';tt.textContent=p.theme+(p.wallpaper_variant&&p.wallpaper_variant!=='default'?' · '+p.wallpaper_variant:'');top.appendChild(tt);}
      if(isLast){const lt=document.createElement('span');lt.className='profile-last-tag';lt.textContent='★ this machine';top.appendChild(lt);}
      const bot=document.createElement('div');bot.className='profile-row-bot';
      const dt=document.createElement('span');dt.className='profile-date';dt.textContent=p.saved||'';
      const acts=document.createElement('div');acts.className='profile-row-actions';
      const bLoad=document.createElement('button');bLoad.className='prof-btn prof-btn-load';bLoad.textContent='📥 Load';bLoad.onclick=()=>_profileLoad(p.name);
      const bOver=document.createElement('button');bOver.className='prof-btn prof-btn-over';bOver.textContent='💾 Overwrite';bOver.onclick=()=>_profileOverwrite(p.name);
      const bDel=document.createElement('button');bDel.className='prof-btn prof-btn-del';bDel.textContent='🗑';bDel.onclick=()=>_profileDelete(p.name,bDel);
      acts.append(bLoad,bOver,bDel);bot.append(dt,acts);row.append(top,bot);
      list.appendChild(row);
    });
  }).catch(()=>{
    const list=document.getElementById('profiles-list');
    if(list)list.innerHTML='<div style="color:#ff8080;font-size:12px;padding:8px;">Could not fetch profiles.</div>';
  });
}
function _esc(s){const d=document.createElement('div');d.textContent=s;return d.innerHTML;}
// ===== ACTIVE PROFILE TRACKING =====
let _activeProfileName = localStorage.getItem('dash-active-profile') || '';
// ── Auto-load the profile linked to this device (if no profile is already active) ──
const PHP_DEVICE_PROFILE = <?= json_encode($_php_device_profile) ?>;
const PHP_MACHINE_UUID   = <?= json_encode($_muuid) ?>;
(function _autoDeviceProfile() {
  if (_activeProfileName || !PHP_DEVICE_PROFILE) return;
  // Silently load the device-linked profile (no confirm dialog)
  fetch('save_layout.php', {method:'POST', headers:{'Content-Type':'application/json'},
    body: JSON.stringify({action:'load', name: PHP_DEVICE_PROFILE})
  }).then(r => r.json()).then(j => {
    if (!j.ok) return;
    _activeProfileName = PHP_DEVICE_PROFILE;
    localStorage.setItem('dash-active-profile', PHP_DEVICE_PROFILE);
    localStorage.setItem('dash-last-profile', PHP_DEVICE_PROFILE);
    if (j.theme) {
      localStorage.setItem('hp-theme', j.theme);
      if (j.wallpaper_variant) localStorage.setItem('variant-' + j.theme, j.wallpaper_variant);
      const patch = {'hp-theme': j.theme, 'hp-size': String(j.size || 100)};
      if (j.wallpaper_variant) patch['variant-' + j.theme] = j.wallpaper_variant;
      fetch('save_state.php', {method:'POST', headers:{'Content-Type':'application/json'},
        body: JSON.stringify(patch)}).then(() => location.reload()).catch(() => location.reload());
    } else { location.reload(); }
  }).catch(() => {});
})();
// Debounced auto-save to active profile (theme/size/stat positions only — NOT links)
let _profilePatchTimer = null;
function _patchActiveProfile(fields) {
  if (!_activeProfileName) return;
  clearTimeout(_profilePatchTimer);
  _profilePatchTimer = setTimeout(() => {
    const payload = Object.assign({action:'patch', name:_activeProfileName}, fields);
    fetch('save_layout.php',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify(payload)}).catch(()=>{});
  }, 1200);
}

async function _profileLoad(name){
  if(!confirm('Load profile "'+name+'"?\nThis will apply the saved theme, wallpaper, and size. The page will reload.'))return;
  try{
    const r=await fetch('save_layout.php',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({action:'load',name})});
    const j=await r.json();
    if(!j.ok){alert('Error: '+(j.error||'?'));return;}
    // Mark this as the active profile — auto-saves will target it
    localStorage.setItem('dash-last-profile',name);
    localStorage.setItem('dash-active-profile',name);
    if(j.theme){
      localStorage.setItem('hp-theme',j.theme);
      if(j.wallpaper_variant) localStorage.setItem('variant-'+j.theme,j.wallpaper_variant);
      const patch={'hp-theme':j.theme};
      if(j.wallpaper_variant) patch['variant-'+j.theme]=j.wallpaper_variant;
      // T005: always store size so INIT block restores it on reload (even if 100)
      patch['hp-size']=String(j.size||100);
      await fetch('save_state.php',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify(patch)});
    }
    window.location.reload();
  }catch(e){alert('Network error: '+e.message);}
}
function _profileOverwrite(name){
  if(!confirm('Overwrite profile "'+name+'" with the current layout, theme, and wallpaper?'))return;
  _profileSave(name);
}
function _profileDelete(name,btn){
  if(!confirm('Permanently delete profile "'+name+'"?'))return;
  btn.disabled=true;
  fetch('save_layout.php',{
    method:'POST',headers:{'Content-Type':'application/json'},
    body:JSON.stringify({action:'delete',name})
  }).then(r=>r.json()).then(j=>{
    if(j.ok){
      // Clear "last loaded" tag if it was this profile
      if(localStorage.getItem('dash-last-profile')===name)localStorage.removeItem('dash-last-profile');
      // Clear active profile so _patchActiveProfile stops targeting the deleted profile
      if(localStorage.getItem('dash-active-profile')===name){
        localStorage.removeItem('dash-active-profile');
        _activeProfileName='';
      }
      _refreshProfilesList();
    }else alert('Error: '+(j.error||'?'));
  }).catch(()=>{btn.disabled=false;});
}
function _profileSave(name){
  if(!name)return;
  // T004: Links are independent of profiles — only save theme, wallpaper, size, positions
  const size=parseInt(document.getElementById('size-slider-top')?.value)||100;
  const statPosStr=localStorage.getItem('hp-stat-pos')||'{}';
  const payload={
    action:'save',name,
    theme:_currentBaseTheme,
    wallpaper_variant:_currentVariant,
    size,
    stat_pos_json:statPosStr
  };
  fetch('save_layout.php',{
    method:'POST',headers:{'Content-Type':'application/json'},
    body:JSON.stringify(payload)
  }).then(r=>r.json()).then(j=>{
    if(j.ok){
      // Mark as active profile after saving
      _activeProfileName=name;
      localStorage.setItem('dash-active-profile',name);
      _refreshProfilesList();
    }else alert('Error saving profile: '+(j.error||'unknown'));
  }).catch(e=>alert('Network error: '+e.message));
}
function saveProfileNew(){
  const inp=document.getElementById('new-profile-name');
  const name=(inp?.value||'').trim();
  if(!name){inp?.focus();return;}
  _profileSave(name);
  if(inp)inp.value='';
}
// Keep old name so existing callers (toolbar Done button) still work
function saveCurrentLayout(){openProfilesModal();}
function refreshLayoutList(){_refreshProfilesList();}
// HP/Palm webOS — "Orbit" wallpaper (authentic teal + coral bokeh on near-black)
// webOS signature: teal #00aaa0, coral/salmon, slate-blue — very soft bokeh orbs
(function(){const cv=document.getElementById('canvas-webos'),ctx=cv.getContext('2d');let t=0,animId=null;function resize(){cv.width=window.innerWidth;cv.height=window.innerHeight;}// Authentic webOS Orbit palette — teal, coral, cool-blue, muted-teal, warm-salmon
const ORBS=[{hx:.28,hy:.52,hr:.52,hue:174,sat:78,lt:36,ph:0.0},{hx:.74,hy:.38,hr:.44,hue:4,sat:72,lt:40,ph:1.85},{hx:.52,hy:.76,hr:.38,hue:196,sat:62,lt:34,ph:3.3},{hx:.14,hy:.28,hr:.31,hue:165,sat:68,lt:33,ph:0.95},{hx:.86,hy:.68,hr:.33,hue:356,sat:66,lt:38,ph:2.6},{hx:.62,hy:.18,hr:.26,hue:188,sat:60,lt:30,ph:4.1}];function bokeh(o){const W=cv.width,H=cv.height;const cx=W*o.hx+Math.sin(t*.24+o.ph)*W*.055,cy=H*o.hy+Math.cos(t*.21+o.ph)*H*.048;const r=Math.min(W,H)*o.hr*(1+.038*Math.sin(t*.48+o.ph));// Multi-layer bokeh (soft out-of-focus sphere illusion)
[[.12,.32],[.30,.22],[.55,.13],[.80,.07],[1.1,.032],[1.6,.015]].forEach(([s,a])=>{const g=ctx.createRadialGradient(cx,cy,0,cx,cy,r*s);g.addColorStop(0,`hsla(${o.hue},${o.sat}%,${o.lt+10}%,${a})`);g.addColorStop(.45,`hsla(${o.hue},${o.sat}%,${o.lt}%,${a*.55})`);g.addColorStop(1,`hsla(${o.hue},${o.sat-10}%,${o.lt-6}%,0)`);ctx.fillStyle=g;ctx.beginPath();ctx.arc(cx,cy,r*s,0,Math.PI*2);ctx.fill();});}function animate(){if(cv.style.display==='none'){animId=null;return;}t+=.0038;const W=cv.width,H=cv.height;// Near-black base — authentic webOS darkness (#08080c)
ctx.fillStyle='rgba(8,8,12,.14)';ctx.fillRect(0,0,W,H);ORBS.forEach(o=>bokeh(o));animId=requestAnimationFrame(animate);}window.addEventListener('resize',resize);resize();window._startWebos=()=>{ctx.fillStyle='#08080c';ctx.fillRect(0,0,cv.width,cv.height);if(!animId)animate();};window._stopWebos=()=>{if(animId){cancelAnimationFrame(animId);animId=null;}ctx.clearRect(0,0,cv.width,cv.height);};})();
</script>

<!-- ===== PROFILES MODAL ===== -->
<div id="profiles-modal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.72);z-index:9999;align-items:center;justify-content:center;padding:20px;" onclick="if(event.target===this)closeProfilesModal()">
  <div style="background:#1a1e2e;border:1px solid rgba(255,255,255,.13);border-radius:14px;width:100%;max-width:500px;max-height:82vh;display:flex;flex-direction:column;overflow:hidden;box-shadow:0 8px 40px rgba(0,0,0,.6);">
    <!-- Header -->
    <div style="display:flex;align-items:center;justify-content:space-between;padding:16px 18px 12px;border-bottom:1px solid rgba(255,255,255,.08);">
      <strong style="font-size:15px;color:#e0e6ff;">📋 Layout Profiles</strong>
      <button onclick="closeProfilesModal()" style="background:none;border:none;color:#aaa;font-size:18px;cursor:pointer;line-height:1;padding:0 2px;" title="Close">✕</button>
    </div>
    <!-- Save new -->
    <div style="padding:14px 18px 12px;border-bottom:1px solid rgba(255,255,255,.08);">
      <div style="font-size:11px;text-transform:uppercase;letter-spacing:.06em;opacity:.5;margin-bottom:8px;">Save current layout as new profile</div>
      <div style="display:flex;gap:8px;">
        <input id="new-profile-name" type="text" placeholder="Profile name (e.g. Work, Gaming, Laptop…)"
          style="flex:1;padding:7px 10px;border-radius:7px;border:1px solid rgba(255,255,255,.18);background:rgba(255,255,255,.07);color:#fff;font-size:13px;outline:none;"
          onkeydown="if(event.key==='Enter')saveProfileNew()">
        <button onclick="saveProfileNew()" style="padding:7px 14px;border-radius:7px;border:none;background:#4a9eff;color:#fff;font-size:13px;font-weight:600;cursor:pointer;white-space:nowrap;">💾 Save New</button>
      </div>
      <div style="font-size:10px;opacity:.4;margin-top:6px;">Captures: theme · wallpaper variant · zoom level · widget positions</div>
    </div>
    <!-- Profile list -->
    <div id="profiles-list" style="overflow-y:auto;flex:1;padding:10px 18px 16px;display:flex;flex-direction:column;gap:8px;"></div>
    <!-- Footer -->
    <div style="padding:10px 18px;border-top:1px solid rgba(255,255,255,.08);font-size:10px;opacity:.35;text-align:center;">
      Profiles are stored on the server · This machine remembers the last loaded profile locally
    </div>
  </div>
</div>

<script>
// ===== HATSUNE MIKU CANVAS (floating notes + teal sakura) =====
(function(){
  const C=document.getElementById('canvas-miku');
  if(!C)return;
  let raf=null,W=0,H=0,particles=[];
  const NOTES=['♪','♫','♩','♬','♭','♮'];
  const TEAL='rgba(57,197,187,', PINK='rgba(255,120,180,';
  function resize(){W=C.width=window.innerWidth;H=C.height=window.innerHeight;}
  function spawnParticle(){
    const isNote=Math.random()>.35;
    return {
      x:Math.random()*W,
      y:H+20,
      vx:(Math.random()-.5)*0.6,
      vy:-(0.4+Math.random()*0.8),
      alpha:0,
      size:isNote?14+Math.random()*18:5+Math.random()*9,
      rot:Math.random()*Math.PI*2,
      rotV:(Math.random()-.5)*.03,
      text:isNote?NOTES[Math.floor(Math.random()*NOTES.length)]:null,
      color:isNote?TEAL:Math.random()>.5?TEAL:PINK,
      life:0,maxLife:180+Math.random()*120,
      fadeIn:20,fadeOut:40,
      sway:Math.random()*2,swaySpeed:.015+Math.random()*.02,swayT:Math.random()*100
    };
  }
  function init(){
    resize();particles=[];
    for(let i=0;i<40;i++){const p=spawnParticle();p.y=Math.random()*H;p.life=Math.random()*p.maxLife;particles.push(p);}
    window.addEventListener('resize',resize);
  }
  function draw(){
    const ctx=C.getContext('2d');
    ctx.clearRect(0,0,W,H);
    particles.forEach(p=>{
      p.life++;
      p.swayT+=p.swaySpeed;
      p.x+=p.vx+Math.sin(p.swayT)*p.sway*.3;
      p.y+=p.vy;
      p.rot+=p.rotV;
      if(p.life<p.fadeIn)p.alpha=p.life/p.fadeIn;
      else if(p.life>p.maxLife-p.fadeOut)p.alpha=(p.maxLife-p.life)/p.fadeOut;
      else p.alpha=.75;
      p.alpha=Math.max(0,p.alpha);
      ctx.save();
      ctx.globalAlpha=p.alpha;
      ctx.translate(p.x,p.y);
      ctx.rotate(p.rot);
      if(p.text){
        ctx.font=`bold ${p.size}px monospace`;
        ctx.fillStyle=p.color+'1)';
        ctx.shadowColor='rgba(57,197,187,.7)';
        ctx.shadowBlur=8;
        ctx.fillText(p.text,0,0);
      } else {
        // petal
        ctx.beginPath();
        ctx.ellipse(0,0,p.size*.6,p.size,0,0,Math.PI*2);
        ctx.fillStyle=p.color+'.85)';
        ctx.shadowColor=p.color+'1)';
        ctx.shadowBlur=6;
        ctx.fill();
      }
      ctx.restore();
      if(p.life>=p.maxLife||p.y<-40)Object.assign(p,spawnParticle());
    });
    if(particles.length<60&&Math.random()>.92)particles.push(spawnParticle());
    raf=requestAnimationFrame(draw);
  }
  window._startMiku=function(){C.style.display='block';if(!particles.length)init();if(!raf)draw();};
  window._stopMiku=function(){if(raf){cancelAnimationFrame(raf);raf=null;}C.style.display='none';};
})();

// ===== HQ CANVAS: SPRING RAIN =====
(function(){const cv=document.getElementById('canvas-spring2'),ctx=cv.getContext('2d');let drops=[],ripples=[],animId=null,t=0;function resize(){cv.width=window.innerWidth;cv.height=window.innerHeight;drops=Array.from({length:220},()=>({x:Math.random()*cv.width,y:Math.random()*cv.height,l:8+Math.random()*14,sp:6+Math.random()*8,op:.3+Math.random()*.5}));ripples=[];}function drawRainbow(W,H){const cx=W*.38,cy=H*.3;ctx.save();for(let i=6;i>=0;i--){const hue=i*40,r=H*.28+i*22;ctx.beginPath();ctx.arc(cx,cy,r,Math.PI,Math.PI*2);ctx.strokeStyle='hsla('+hue+',85%,55%,.09)';ctx.lineWidth=12;ctx.stroke();}ctx.restore();}function animate(){if(cv.style.display==='none'){animId=null;return;}t+=.016;const W=cv.width,H=cv.height;const sky=ctx.createLinearGradient(0,0,0,H);sky.addColorStop(0,'#1a2d1a');sky.addColorStop(.6,'#243820');sky.addColorStop(1,'#2a3c22');ctx.fillStyle=sky;ctx.fillRect(0,0,W,H);drawRainbow(W,H);ctx.strokeStyle='rgba(180,220,255,.35)';ctx.lineWidth=1;drops.forEach(d=>{d.y+=d.sp;d.x-=1.2;if(d.y>H+20||d.x<-10){d.y=Math.random()*-H*.5;d.x=Math.random()*W*1.2;if(Math.random()>.7)ripples.push({x:d.x,y:H-8,r:0,maxR:18+Math.random()*22,alpha:.5});}ctx.globalAlpha=d.op;ctx.beginPath();ctx.moveTo(d.x,d.y);ctx.lineTo(d.x-d.l*.15,d.y+d.l);ctx.stroke();});ctx.globalAlpha=1;ripples=ripples.filter(r=>{r.r+=.7;r.alpha-=.022;if(r.alpha<=0)return false;ctx.strokeStyle='rgba(140,200,255,'+r.alpha+')';ctx.lineWidth=.8;ctx.beginPath();ctx.ellipse(r.x,r.y,r.r,r.r*.3,0,0,Math.PI*2);ctx.stroke();return true;});const gnd=ctx.createLinearGradient(0,H*.85,0,H);gnd.addColorStop(0,'#1f3a12');gnd.addColorStop(1,'#152908');ctx.fillStyle=gnd;ctx.fillRect(0,H*.85,W,H*.15);animId=requestAnimationFrame(animate);}window.addEventListener('resize',resize);resize();window._startSpring2=function(){if(!animId)animate();};window._stopSpring2=function(){if(animId){cancelAnimationFrame(animId);animId=null;}ctx.clearRect(0,0,cv.width,cv.height);};})();

// ===== HQ CANVAS: SPRING MEADOW =====
(function(){const cv=document.getElementById('canvas-spring3'),ctx=cv.getContext('2d');let t=0,animId=null,butterflies=[];const FX=[.12,.25,.38,.52,.65,.78,.88,.95,.3,.55,.7];const FCOLS=['#ff6b9d','#ffcc00','#ff8c42','#ff4444','#cc44ff','#ff69b4','#ffd700'];function W(){return cv.width;}function H(){return cv.height;}function resize(){cv.width=window.innerWidth;cv.height=window.innerHeight;butterflies=Array.from({length:8},()=>({x:Math.random()*cv.width,y:H()*.3+Math.random()*H()*.4,bx:Math.random()*Math.PI*2,by:Math.random()*Math.PI*2,hue:20+Math.floor(Math.random()*6)*30,sz:.6+Math.random()*.6,spx:.3+Math.random()*.5,spy:.2+Math.random()*.3}));}function drawButterfly(b){ctx.save();ctx.translate(b.x,b.y);const flap=Math.sin(t*4+b.bx)*.5+.5;ctx.globalAlpha=.8;for(let side=0;side<2;side++){ctx.save();if(side)ctx.scale(-1,1);ctx.beginPath();ctx.ellipse(b.sz*10*flap,0,b.sz*14*flap,b.sz*10,-.4,0,Math.PI*2);ctx.fillStyle='hsla('+b.hue+',80%,60%,.7)';ctx.fill();ctx.restore();}ctx.globalAlpha=1;ctx.restore();}function animate(){if(cv.style.display==='none'){animId=null;return;}t+=.016;const W_=W(),H_=H();const sky=ctx.createLinearGradient(0,0,0,H_*.7);sky.addColorStop(0,'#87ceeb');sky.addColorStop(1,'#c8e8ff');ctx.fillStyle=sky;ctx.fillRect(0,0,W_,H_);const sg=ctx.createRadialGradient(W_*.15,H_*.12,0,W_*.15,H_*.12,H_*.25);sg.addColorStop(0,'rgba(255,240,150,.22)');sg.addColorStop(1,'rgba(255,220,80,0)');ctx.fillStyle=sg;ctx.fillRect(0,0,W_,H_);for(let i=0;i<3;i++){const cx_=((t*12*(1+i*.3)+W_*(.2+i*.28))%(W_+200))-100,cy_=H_*(.08+i*.05);ctx.fillStyle='rgba(255,255,255,.65)';ctx.beginPath();ctx.ellipse(cx_,cy_,55+i*15,22+i*6,0,0,Math.PI*2);ctx.fill();ctx.beginPath();ctx.ellipse(cx_+30,cy_-8,38,18,0,0,Math.PI*2);ctx.fill();}[.72,.82,.92].forEach((frac,i)=>{ctx.beginPath();const hues=['#2d5a1b','#336622','#3a7a28'];for(let x=0;x<=W_;x+=4){const y=H_*frac+Math.sin(x*.003+t*.12+i*1.5)*H_*.04+Math.sin(x*.007+i)*H_*.02;x===0?ctx.moveTo(x,y):ctx.lineTo(x,y);}ctx.lineTo(W_,H_);ctx.lineTo(0,H_);ctx.closePath();ctx.fillStyle=hues[i];ctx.fill();});FX.forEach((fx,i)=>{const x=fx*W_,y=H_*.83+Math.sin(i*2.7)*H_*.03;ctx.strokeStyle='#2d7a1b';ctx.lineWidth=1.5;ctx.beginPath();ctx.moveTo(x,y);ctx.lineTo(x,y-H_*.06);ctx.stroke();for(let p=0;p<5;p++){const a=p/5*Math.PI*2;ctx.beginPath();ctx.ellipse(x+Math.cos(a)*6,y-H_*.06+Math.sin(a)*6,4,3,a,0,Math.PI*2);ctx.fillStyle=FCOLS[i%FCOLS.length];ctx.globalAlpha=.9;ctx.fill();}ctx.beginPath();ctx.arc(x,y-H_*.06,3,0,Math.PI*2);ctx.fillStyle='#ffee55';ctx.fill();ctx.globalAlpha=1;});butterflies.forEach(b=>{b.x+=Math.sin(b.bx+t)*b.spx;b.y+=Math.cos(b.by+t*.7)*b.spy;b.bx+=.012;b.by+=.009;if(b.x>W_+50)b.x=-50;if(b.x<-50)b.x=W_+50;drawButterfly(b);});animId=requestAnimationFrame(animate);}window.addEventListener('resize',resize);resize();window._startSpring3=function(){if(!animId)animate();};window._stopSpring3=function(){if(animId){cancelAnimationFrame(animId);animId=null;}ctx.clearRect(0,0,cv.width,cv.height);};})();

// ===== HQ CANVAS: AURORA BOREALIS =====
(function(){const cv=document.getElementById('canvas-aurora'),ctx=cv.getContext('2d');let t=0,animId=null,stars=[];function resize(){cv.width=window.innerWidth;cv.height=window.innerHeight;stars=Array.from({length:120},()=>({x:Math.random()*cv.width,y:Math.random()*cv.height*.65,r:.4+Math.random()*.9,p:Math.random()*Math.PI*2,sp:.006+Math.random()*.01}));}function drawAurora(W,H){const bands=[{c1:'rgba(0,255,120,',c2:'rgba(0,200,80,',yB:.15,amp:.08,freq:.0015,spd:.22,wd:.28},{c1:'rgba(100,60,240,',c2:'rgba(60,30,180,',yB:.22,amp:.06,freq:.002,spd:.15,wd:.22},{c1:'rgba(0,200,255,',c2:'rgba(0,150,220,',yB:.18,amp:.07,freq:.0018,spd:.18,wd:.2},{c1:'rgba(180,50,220,',c2:'rgba(120,20,180,',yB:.28,amp:.05,freq:.0022,spd:.1,wd:.16}];bands.forEach(b=>{const yMid=H*b.yB,pts=[];for(let x=0;x<=W;x+=8)pts.push({x,y:yMid+Math.sin(x*b.freq+t*b.spd)*H*b.amp+Math.sin(x*b.freq*1.7-t*b.spd*.6)*H*b.amp*.4});ctx.beginPath();ctx.moveTo(pts[0].x,pts[0].y);pts.slice(1).forEach(p=>ctx.lineTo(p.x,p.y));const rev=[...pts].reverse();rev.forEach((p,i)=>{ctx.lineTo(p.x,p.y+H*b.wd*(0.5+0.5*Math.sin((pts.length-1-i)/(pts.length-1)*Math.PI)));});ctx.closePath();const g=ctx.createLinearGradient(0,yMid,0,yMid+H*b.wd);g.addColorStop(0,b.c1+'0)');g.addColorStop(.2,b.c1+'.18)');g.addColorStop(.6,b.c2+'.12)');g.addColorStop(1,b.c2+'0)');ctx.fillStyle=g;ctx.globalCompositeOperation='screen';ctx.fill();ctx.globalCompositeOperation='source-over';});}function animate(){if(cv.style.display==='none'){animId=null;return;}t+=.008;const W=cv.width,H=cv.height;ctx.fillStyle='#00020a';ctx.fillRect(0,0,W,H);stars.forEach(s=>{s.p+=s.sp;ctx.fillStyle='rgba(200,220,255,'+(0.4+0.3*Math.sin(s.p))+')';ctx.beginPath();ctx.arc(s.x,s.y,s.r,0,Math.PI*2);ctx.fill();});drawAurora(W,H);const gnd=ctx.createLinearGradient(0,H*.78,0,H);gnd.addColorStop(0,'#c8e0f8');gnd.addColorStop(1,'#e8f4ff');ctx.fillStyle=gnd;ctx.beginPath();for(let x=0;x<=W;x+=6){const y=H*.8+Math.sin(x*.012)*H*.02+Math.sin(x*.007+1)*H*.015;x===0?ctx.moveTo(x,y):ctx.lineTo(x,y);}ctx.lineTo(W,H);ctx.lineTo(0,H);ctx.closePath();ctx.fill();[[.1,.95],[.22,.88],[.35,.92],[.78,.87],[.88,.93],[.96,.89]].forEach(([fx,fy])=>{ctx.fillStyle='#0a0f18';ctx.beginPath();ctx.moveTo(fx*W,fy*H-H*.12);ctx.lineTo(fx*W-H*.018,fy*H);ctx.lineTo(fx*W+H*.018,fy*H);ctx.closePath();ctx.fill();});animId=requestAnimationFrame(animate);}window.addEventListener('resize',resize);resize();window._startAurora=function(){ctx.fillStyle='#00020a';ctx.fillRect(0,0,cv.width,cv.height);if(!animId)animate();};window._stopAurora=function(){if(animId){cancelAnimationFrame(animId);animId=null;}ctx.clearRect(0,0,cv.width,cv.height);};})();

// ===== HQ CANVAS: BLIZZARD =====
(function(){const cv=document.getElementById('canvas-blizzard'),ctx=cv.getContext('2d');let flakes=[],animId=null,t=0;function resize(){cv.width=window.innerWidth;cv.height=window.innerHeight;flakes=Array.from({length:300},()=>({x:Math.random()*cv.width,y:Math.random()*cv.height,r:.5+Math.random()*2.5,sp:3+Math.random()*6,sx:2+Math.random()*4,op:.3+Math.random()*.7}));}function animate(){if(cv.style.display==='none'){animId=null;return;}t+=.016;const W=cv.width,H=cv.height;const sky=ctx.createLinearGradient(0,0,0,H);sky.addColorStop(0,'#060a12');sky.addColorStop(1,'#1a2535');ctx.fillStyle=sky;ctx.fillRect(0,0,W,H);ctx.strokeStyle='rgba(200,220,255,.06)';ctx.lineWidth=1;for(let i=0;i<8;i++){const gy=H*(.1+i*.12)+Math.sin(t*.5+i)*H*.03;ctx.beginPath();ctx.moveTo(0,gy);ctx.bezierCurveTo(W*.3,gy+20*Math.sin(t+i),W*.7,gy-15*Math.cos(t*.8+i),W,gy+10);ctx.stroke();}ctx.fillStyle='rgba(220,235,255,.9)';flakes.forEach(f=>{f.y+=f.sp;f.x+=f.sx+Math.sin(t*.8+f.sp)*1.5;if(f.y>H+10){f.y=-10;f.x=Math.random()*W;}if(f.x>W+20)f.x=-20;ctx.globalAlpha=f.op;ctx.beginPath();ctx.arc(f.x,f.y,f.r,0,Math.PI*2);ctx.fill();});ctx.globalAlpha=1;const gnd=ctx.createLinearGradient(0,H*.88,0,H);gnd.addColorStop(0,'#d8eeff');gnd.addColorStop(1,'#ffffff');ctx.fillStyle=gnd;ctx.beginPath();for(let x=0;x<=W;x+=6){const y=H*.9+Math.sin(x*.008+t*.05)*H*.025;x===0?ctx.moveTo(x,y):ctx.lineTo(x,y);}ctx.lineTo(W,H);ctx.lineTo(0,H);ctx.closePath();ctx.fill();animId=requestAnimationFrame(animate);}window.addEventListener('resize',resize);resize();window._startBlizzard=function(){if(!animId)animate();};window._stopBlizzard=function(){if(animId){cancelAnimationFrame(animId);animId=null;}ctx.clearRect(0,0,cv.width,cv.height);};})();

// ===== HQ CANVAS: CHRISTMAS NIGHT =====
(function(){const cv=document.getElementById('canvas-christmas2'),ctx=cv.getContext('2d');let flakes=[],lights=[],ornaments=[],animId=null,t=0;const LCOLS=['#ff3333','#33ff33','#3388ff','#ffff33','#ff33ff','#33ffff','#ff8833'];function resize(){cv.width=window.innerWidth;cv.height=window.innerHeight;flakes=Array.from({length:120},()=>({x:Math.random()*cv.width,y:Math.random()*cv.height,r:1+Math.random()*2.5,sp:.4+Math.random()*1.2,sw:Math.random()*Math.PI*2,sd:.005+Math.random()*.008,op:.5+Math.random()*.5}));lights=Array.from({length:40},()=>({fx:Math.random(),fy:.2+Math.random()*.7,col:LCOLS[Math.floor(Math.random()*LCOLS.length)],blink:Math.random()*Math.PI*2,bs:.04+Math.random()*.08}));ornaments=Array.from({length:8},()=>({x:-50,y:Math.random()*cv.height*.5+50,vy:.3+Math.random()*.6,vx:.5+Math.random(),hue:Math.floor(Math.random()*360),size:8+Math.random()*12}));}function drawTree(W,H){const tx=W*.5,by=H*.9,layers=6;for(let l=0;l<layers;l++){const y=by-l*(H*.1),hw=W*.18*(1-l/layers)+W*.02;ctx.fillStyle='hsl('+(130+l*3)+',65%,'+(22+l*4)+'%)';ctx.beginPath();ctx.moveTo(tx,y-H*.12);ctx.lineTo(tx-hw,y);ctx.lineTo(tx+hw,y);ctx.closePath();ctx.fill();ctx.fillStyle='rgba(230,245,255,.7)';ctx.beginPath();ctx.ellipse(tx,y,hw*.8,H*.01,0,0,Math.PI*2);ctx.fill();}const starY=by-layers*H*.1-H*.1;ctx.fillStyle='#ffee44';ctx.shadowColor='#ffee44';ctx.shadowBlur=22;for(let p=0;p<5;p++){const a1=p/5*Math.PI*2-Math.PI/2,a2=(p+.5)/5*Math.PI*2-Math.PI/2;ctx.beginPath();ctx.moveTo(tx,starY);ctx.lineTo(tx+Math.cos(a1)*16,starY+Math.sin(a1)*16);ctx.lineTo(tx+Math.cos(a2)*7,starY+Math.sin(a2)*7);ctx.fill();}ctx.shadowBlur=0;ctx.fillStyle='#5c3317';ctx.fillRect(tx-12,by,24,H*.06);}function animate(){if(cv.style.display==='none'){animId=null;return;}t+=.012;const W=cv.width,H=cv.height;const sky=ctx.createLinearGradient(0,0,0,H);sky.addColorStop(0,'#020408');sky.addColorStop(1,'#0a1220');ctx.fillStyle=sky;ctx.fillRect(0,0,W,H);for(let i=0;i<60;i++){const sx=(Math.sin(i*172.3)*W+W)%W,sy=(Math.sin(i*93.7)*H*.55+H*.55)%(H*.55);ctx.fillStyle='rgba(220,230,255,'+(0.3+0.25*Math.sin(t*.5+i))+')';ctx.beginPath();ctx.arc(sx,sy,.8,0,Math.PI*2);ctx.fill();}drawTree(W,H);lights.forEach(l=>{const blink=.5+.5*Math.sin(t*l.bs+l.blink);ctx.fillStyle=l.col;ctx.shadowColor=l.col;ctx.shadowBlur=blink*12;ctx.globalAlpha=blink*.8+.2;ctx.beginPath();ctx.arc(l.fx*W*.4+W*.3,l.fy*H*.5+H*.25,4+blink*2,0,Math.PI*2);ctx.fill();ctx.globalAlpha=1;ctx.shadowBlur=0;});flakes.forEach(f=>{f.y+=f.sp;f.x+=Math.sin(f.sw)*.5;f.sw+=f.sd;if(f.y>H+10){f.y=-10;f.x=Math.random()*W;}ctx.globalAlpha=f.op;ctx.fillStyle='rgba(230,245,255,1)';ctx.beginPath();ctx.arc(f.x,f.y,f.r,0,Math.PI*2);ctx.fill();});ctx.globalAlpha=1;ornaments.forEach(o=>{o.x+=o.vx;o.y+=o.vy;if(o.x>W+50||o.y>H+50){o.x=-50;o.y=Math.random()*H*.4;}const og=ctx.createRadialGradient(o.x-o.size*.25,o.y-o.size*.25,o.size*.1,o.x,o.y,o.size);og.addColorStop(0,'hsla('+o.hue+',90%,80%,.9)');og.addColorStop(1,'hsla('+o.hue+',80%,40%,.9)');ctx.fillStyle=og;ctx.beginPath();ctx.arc(o.x,o.y,o.size,0,Math.PI*2);ctx.fill();ctx.fillStyle='rgba(255,255,255,.6)';ctx.beginPath();ctx.ellipse(o.x-o.size*.25,o.y-o.size*.25,o.size*.2,o.size*.15,-.5,0,Math.PI*2);ctx.fill();});const gnd=ctx.createLinearGradient(0,H*.88,0,H);gnd.addColorStop(0,'#c8dcf0');gnd.addColorStop(1,'#e8f2ff');ctx.fillStyle=gnd;ctx.beginPath();for(let x=0;x<=W;x+=8){const y=H*.9+Math.sin(x*.01)*H*.02+Math.sin(x*.006+1)*H*.015;x===0?ctx.moveTo(x,y):ctx.lineTo(x,y);}ctx.lineTo(W,H);ctx.lineTo(0,H);ctx.closePath();ctx.fill();animId=requestAnimationFrame(animate);}window.addEventListener('resize',resize);resize();window._startChristmas=function(){ctx.fillStyle='#020408';ctx.fillRect(0,0,cv.width,cv.height);if(!animId)animate();};window._stopChristmas=function(){if(animId){cancelAnimationFrame(animId);animId=null;}ctx.clearRect(0,0,cv.width,cv.height);};})();

// ===== HQ CANVAS: TROPICAL FIREFLIES =====
(function(){const cv=document.getElementById('canvas-summer2'),ctx=cv.getContext('2d');let fireflies=[],animId=null,t=0;function resize(){cv.width=window.innerWidth;cv.height=window.innerHeight;fireflies=Array.from({length:60},()=>({x:Math.random()*cv.width,y:cv.height*.2+Math.random()*cv.height*.6,bx:Math.random()*Math.PI*2,by:Math.random()*Math.PI*2,sp:.3+Math.random()*.5,phase:Math.random()*Math.PI*2,period:80+Math.random()*120}));}function drawPalms(W,H){[[.08,.95],[.18,.9],[.92,.93],[.82,.88]].forEach(([fx,fy],i)=>{const x=fx*W,y=fy*H,lean=i%2===0?1:-1;ctx.strokeStyle='#1a3d08';ctx.lineWidth=8+i*2;ctx.beginPath();ctx.moveTo(x,y);ctx.quadraticCurveTo(x+lean*30,y-H*.15,x+lean*50,y-H*.3);ctx.stroke();for(let f=0;f<6;f++){const fa=f/6*Math.PI*1.2-Math.PI*.6+lean*.3;const fx2=x+lean*50+Math.cos(fa)*H*.14,fy2=y-H*.3+Math.sin(fa)*H*.14;ctx.strokeStyle='#2d6b10';ctx.lineWidth=3;ctx.beginPath();ctx.moveTo(x+lean*50,y-H*.3);ctx.quadraticCurveTo((x+lean*50+fx2)*.5,(y-H*.3+fy2)*.5-15,fx2,fy2);ctx.stroke();}});}function animate(){if(cv.style.display==='none'){animId=null;return;}t+=.016;const W=cv.width,H=cv.height;const sky=ctx.createLinearGradient(0,0,0,H);sky.addColorStop(0,'#020308');sky.addColorStop(.4,'#0c1a28');sky.addColorStop(.7,'#12221a');sky.addColorStop(1,'#0a1208');ctx.fillStyle=sky;ctx.fillRect(0,0,W,H);for(let i=0;i<80;i++){const sx=(Math.sin(i*197.3)*W+W)%W,sy=(Math.sin(i*83.7)*H*.5)%(H*.5);ctx.fillStyle='rgba(220,240,255,'+(0.2+0.15*Math.sin(t*.3+i))+')';ctx.beginPath();ctx.arc(sx,sy,.6,0,Math.PI*2);ctx.fill();}const mx=W*.8,my=H*.12;const mg=ctx.createRadialGradient(mx-8,my-8,3,mx,my,38);mg.addColorStop(0,'rgba(255,250,200,.9)');mg.addColorStop(1,'rgba(255,240,140,0)');ctx.fillStyle=mg;ctx.beginPath();ctx.arc(mx,my,38,0,Math.PI*2);ctx.fill();drawPalms(W,H);const water=ctx.createLinearGradient(0,H*.7,0,H);water.addColorStop(0,'#08141e');water.addColorStop(1,'#0c1c2a');ctx.fillStyle=water;ctx.fillRect(0,H*.7,W,H*.3);fireflies.forEach(f=>{f.x+=Math.sin(f.bx+t)*f.sp;f.y+=Math.cos(f.by+t*.7)*f.sp*.5;f.bx+=.008;f.by+=.006;const on=Math.sin(f.phase+t*6.28/f.period*60)>.2;if(on){const glow=ctx.createRadialGradient(f.x,f.y,0,f.x,f.y,12);glow.addColorStop(0,'rgba(180,255,100,.9)');glow.addColorStop(.4,'rgba(140,255,80,.4)');glow.addColorStop(1,'rgba(100,200,50,0)');ctx.fillStyle=glow;ctx.beginPath();ctx.arc(f.x,f.y,12,0,Math.PI*2);ctx.fill();}if(f.x<0||f.x>W)f.x=Math.random()*W;if(f.y<H*.15||f.y>H*.85)f.y=H*.3+Math.random()*H*.4;});animId=requestAnimationFrame(animate);}window.addEventListener('resize',resize);resize();window._startSummer2=function(){if(!animId)animate();};window._stopSummer2=function(){if(animId){cancelAnimationFrame(animId);animId=null;}ctx.clearRect(0,0,cv.width,cv.height);};})();

// ===== HQ CANVAS: OCEAN SUNSET =====
(function(){const cv=document.getElementById('canvas-summer3'),ctx=cv.getContext('2d');let t=0,animId=null,birds=[];function resize(){cv.width=window.innerWidth;cv.height=window.innerHeight;birds=Array.from({length:6},()=>({x:Math.random()*cv.width,y:cv.height*.12+Math.random()*cv.height*.12,sp:.4+Math.random()*.6,bphase:Math.random()*Math.PI*2}));}function animate(){if(cv.style.display==='none'){animId=null;return;}t+=.01;const W=cv.width,H=cv.height;const sky=ctx.createLinearGradient(0,0,0,H*.65);sky.addColorStop(0,'#1a0a2e');sky.addColorStop(.25,'#4a1060');sky.addColorStop(.5,'#c0382a');sky.addColorStop(.75,'#e8701a');sky.addColorStop(1,'#f9c842');ctx.fillStyle=sky;ctx.fillRect(0,0,W,H*.65);const sx=W*.5,sy=H*.58;const sg=ctx.createRadialGradient(sx,sy,0,sx,sy,H*.12);sg.addColorStop(0,'rgba(255,255,200,.95)');sg.addColorStop(.3,'rgba(255,220,80,.8)');sg.addColorStop(1,'rgba(255,160,20,0)');ctx.fillStyle=sg;ctx.beginPath();ctx.arc(sx,sy,H*.12,0,Math.PI*2);ctx.fill();ctx.strokeStyle='rgba(255,200,60,.08)';for(let i=0;i<12;i++){const a=i/12*Math.PI*2+t*.05;ctx.lineWidth=H*.04;ctx.beginPath();ctx.moveTo(sx+Math.cos(a)*H*.1,sy+Math.sin(a)*H*.1);ctx.lineTo(sx+Math.cos(a)*H*.45,sy+Math.sin(a)*H*.45);ctx.stroke();}const ocean=ctx.createLinearGradient(0,H*.63,0,H);ocean.addColorStop(0,'#0a1a3a');ocean.addColorStop(1,'#060e22');ctx.fillStyle=ocean;ctx.fillRect(0,H*.63,W,H*.37);for(let i=0;i<5;i++){ctx.beginPath();for(let x=0;x<=W;x+=4){const y=H*(.63+i*.065)+Math.sin(x*.006+t*(1.2-i*.15)+i)*H*.012+Math.sin(x*.012-t*.8)*H*.006;x===0?ctx.moveTo(x,y):ctx.lineTo(x,y);}ctx.strokeStyle='rgba(180,210,255,'+(0.06+i*.02)+')';ctx.lineWidth=1.5;ctx.stroke();}birds.forEach(b=>{b.x-=b.sp;b.bphase+=.08;if(b.x<-30)b.x=W+30;const wingY=Math.sin(b.bphase)*4;ctx.strokeStyle='rgba(20,10,30,.8)';ctx.lineWidth=1.5;ctx.beginPath();ctx.moveTo(b.x-8,b.y-wingY);ctx.quadraticCurveTo(b.x,b.y-4,b.x+8,b.y-wingY);ctx.stroke();});animId=requestAnimationFrame(animate);}window.addEventListener('resize',resize);resize();window._startSummer3=function(){if(!animId)animate();};window._stopSummer3=function(){if(animId){cancelAnimationFrame(animId);animId=null;}ctx.clearRect(0,0,cv.width,cv.height);};})();

// ===== HQ CANVAS: MAPLE FOREST =====
(function(){const cv=document.getElementById('canvas-autumn2'),ctx=cv.getContext('2d');let leaves=[],animId=null,t=0;const MCOLS=['#c0392b','#e74c3c','#e67e22','#d35400','#f39c12','#cb4335','#922b21','#a93226','#f0b27a','#eb984e'];function resize(){cv.width=window.innerWidth;cv.height=window.innerHeight;leaves=Array.from({length:120},()=>spawnLeaf(false));}function spawnLeaf(atTop){return{x:atTop?Math.random()*cv.width:Math.random()*cv.width,y:atTop?-30:Math.random()*cv.height,r:6+Math.random()*14,sp:.5+Math.random()*1.5,sw:Math.random()*Math.PI*2,sd:.006+Math.random()*.012,rot:Math.random()*Math.PI*2,rotSpd:(Math.random()-.5)*.05,col:MCOLS[Math.floor(Math.random()*MCOLS.length)],op:.7+Math.random()*.3,depth:.3+Math.random()*.7};}function drawMapleLeaf(x,y,r,rot,col,op){ctx.save();ctx.translate(x,y);ctx.rotate(rot);ctx.globalAlpha=op;ctx.fillStyle=col;ctx.beginPath();ctx.moveTo(0,-r);for(let l=0;l<5;l++){const a1=l/5*Math.PI*2-Math.PI/2,a2=(l+.5)/5*Math.PI*2-Math.PI/2,a3=(l+1)/5*Math.PI*2-Math.PI/2;ctx.lineTo(Math.cos(a1)*r*.9,Math.sin(a1)*r*.9);ctx.bezierCurveTo(Math.cos(a1)*r*1.4,Math.sin(a1)*r*1.4,Math.cos(a2)*r*1.4,Math.sin(a2)*r*1.4,Math.cos(a2)*r,Math.sin(a2)*r);ctx.lineTo(Math.cos(a3)*r*.9,Math.sin(a3)*r*.9);}ctx.closePath();ctx.fill();ctx.strokeStyle=col;ctx.lineWidth=.5;ctx.globalAlpha=op*.7;ctx.beginPath();ctx.moveTo(0,0);ctx.lineTo(0,r*.6);ctx.stroke();ctx.restore();}function drawBg(W,H){const sky=ctx.createLinearGradient(0,0,0,H*.5);sky.addColorStop(0,'#1a0c04');sky.addColorStop(1,'#3d1c08');ctx.fillStyle=sky;ctx.fillRect(0,0,W,H*.5);for(let layer=3;layer>=0;layer--){for(let x=-W*.1;x<W*1.1;x+=W*.12){const tH=H*(.35+Math.sin(x*.003+layer)*.08);ctx.fillStyle='hsla('+(20+layer*8)+',65%,'+(18+layer*6)+'%,'+(0.15+layer*.12)+')';ctx.beginPath();ctx.moveTo(x,H*.1+tH*.2);ctx.bezierCurveTo(x-W*.05,H*.1-tH*.2,x+W*.05,H*.1-tH*.2,x+W*.03,H*.1+tH*.2);ctx.closePath();ctx.fill();}}const gnd=ctx.createLinearGradient(0,H*.65,0,H);gnd.addColorStop(0,'#2a0e04');gnd.addColorStop(1,'#1a0802');ctx.fillStyle=gnd;ctx.fillRect(0,H*.65,W,H*.35);for(let i=0;i<30;i++){const gx=(Math.sin(i*173.1)*W+W)%W,gy=H*.7+Math.sin(i*2.3)*(H*.25);ctx.fillStyle=MCOLS[i%MCOLS.length];ctx.globalAlpha=.3+Math.sin(i)*.15;ctx.beginPath();ctx.ellipse(gx,gy,6+Math.sin(i*3)*6,3+Math.sin(i*2)*3,Math.sin(i),0,Math.PI*2);ctx.fill();}ctx.globalAlpha=1;}function animate(){if(cv.style.display==='none'){animId=null;return;}t+=.016;const W=cv.width,H=cv.height;drawBg(W,H);[...leaves].sort((a,b)=>a.depth-b.depth).forEach(l=>{const p=l.depth;l.y+=l.sp*p;l.x+=Math.sin(l.sw)*.8*p;l.sw+=l.sd;l.rot+=l.rotSpd;if(l.y>H+30||l.x<-50||l.x>W+50)Object.assign(l,spawnLeaf(true));drawMapleLeaf(l.x,l.y,l.r*p,l.rot,l.col,l.op*Math.min(1,p*1.5));});animId=requestAnimationFrame(animate);}window.addEventListener('resize',resize);resize();window._startAutumn2=function(){if(!animId)animate();};window._stopAutumn2=function(){if(animId){cancelAnimationFrame(animId);animId=null;}ctx.clearRect(0,0,cv.width,cv.height);};})();

// ===== HQ CANVAS: HARVEST MOON =====
(function(){const cv=document.getElementById('canvas-autumn3'),ctx=cv.getContext('2d');let bats=[],wisps=[],animId=null,t=0;function resize(){cv.width=window.innerWidth;cv.height=window.innerHeight;bats=Array.from({length:8},()=>({x:Math.random()*cv.width,y:cv.height*.1+Math.random()*cv.height*.35,sp:.8+Math.random()*.8,by:Math.random()*Math.PI*2,wing:Math.random()*Math.PI*2,dir:Math.random()>.5?1:-1}));wisps=Array.from({length:12},()=>({x:Math.random()*cv.width,y:cv.height*.55+Math.random()*cv.height*.35,phase:Math.random()*Math.PI*2}));}function drawBat(b){ctx.save();ctx.translate(b.x,b.y);if(b.dir<0)ctx.scale(-1,1);const wing=Math.sin(b.wing)*.6;ctx.fillStyle='rgba(10,5,20,.9)';ctx.beginPath();ctx.moveTo(0,0);ctx.bezierCurveTo(-5,-4,-18,-8+wing*8,-22,-3+wing*6);ctx.bezierCurveTo(-20,2,-12,4,-8,2);ctx.closePath();ctx.fill();ctx.beginPath();ctx.moveTo(0,0);ctx.bezierCurveTo(5,-4,18,-8+wing*8,22,-3+wing*6);ctx.bezierCurveTo(20,2,12,4,8,2);ctx.closePath();ctx.fill();ctx.beginPath();ctx.ellipse(0,0,4,5,0,0,Math.PI*2);ctx.fill();ctx.restore();}function animate(){if(cv.style.display==='none'){animId=null;return;}t+=.016;const W=cv.width,H=cv.height;const sky=ctx.createLinearGradient(0,0,0,H);sky.addColorStop(0,'#050208');sky.addColorStop(.4,'#100512');sky.addColorStop(.7,'#200c08');sky.addColorStop(1,'#2a1008');ctx.fillStyle=sky;ctx.fillRect(0,0,W,H);const mx=W*.7,my=H*.18;const glow=ctx.createRadialGradient(mx,my,0,mx,my,H*.22);glow.addColorStop(0,'rgba(255,200,80,.3)');glow.addColorStop(1,'rgba(200,120,20,0)');ctx.fillStyle=glow;ctx.beginPath();ctx.arc(mx,my,H*.22,0,Math.PI*2);ctx.fill();const mg=ctx.createRadialGradient(mx-H*.04,my-H*.04,H*.01,mx,my,H*.1);mg.addColorStop(0,'#fff8e8');mg.addColorStop(.4,'#ffe8a0');mg.addColorStop(1,'#e89020');ctx.fillStyle=mg;ctx.shadowColor='rgba(255,200,80,.8)';ctx.shadowBlur=30;ctx.beginPath();ctx.arc(mx,my,H*.1,0,Math.PI*2);ctx.fill();ctx.shadowBlur=0;for(let i=0;i<50;i++){const sx=(Math.sin(i*197)*W+W)%W,sy=(Math.sin(i*84)*H*.5)%(H*.5);ctx.fillStyle='rgba(200,180,160,'+(0.2+0.15*Math.sin(t*.3+i))+')';ctx.beginPath();ctx.arc(sx,sy,.6,0,Math.PI*2);ctx.fill();}for(let tx=0;tx<W;tx+=W*.12){const th=H*.25+Math.sin(tx*.003)*H*.08;ctx.fillStyle='#0a0504';ctx.beginPath();ctx.moveTo(tx,H*.65);ctx.lineTo(tx-W*.03,H*.65-th*.2);ctx.lineTo(tx-W*.015,H*.65-th*.6);ctx.lineTo(tx,H*.65-th);ctx.lineTo(tx+W*.015,H*.65-th*.6);ctx.lineTo(tx+W*.03,H*.65-th*.2);ctx.closePath();ctx.fill();}const gnd=ctx.createLinearGradient(0,H*.65,0,H);gnd.addColorStop(0,'#1a0a05');gnd.addColorStop(1,'#100604');ctx.fillStyle=gnd;ctx.fillRect(0,H*.65,W,H*.35);wisps.forEach(w=>{w.phase+=.02;ctx.fillStyle='rgba(200,160,120,'+(0.06+0.04*Math.sin(w.phase))+')';ctx.beginPath();ctx.ellipse(w.x+Math.sin(w.phase*.3)*20,w.y,80+Math.sin(w.phase*.7)*30,12,0,0,Math.PI*2);ctx.fill();});[[.15,.93],[.28,.89],[.5,.91],[.72,.88],[.88,.92]].forEach(([fx,fy])=>{const x=fx*W,y=fy*H,s=20;for(let r=0;r<3;r++){ctx.fillStyle='hsl('+(22+r*5)+',90%,'+(38+r*6)+'%)';ctx.beginPath();ctx.ellipse(x+(r-1)*s*.35*.7,y,s*.35,s*.42,0,0,Math.PI*2);ctx.fill();}ctx.strokeStyle='#2d5a10';ctx.lineWidth=2.5;ctx.beginPath();ctx.moveTo(x,y-s*.45);ctx.quadraticCurveTo(x+8,y-s*.7,x+12,y-s*.65);ctx.stroke();});bats.forEach(b=>{b.x+=b.sp*b.dir;b.y+=Math.sin(b.by+t)*.8;b.wing+=.15;b.by+=.02;if(b.x>W+50){b.x=-50;b.y=H*.1+Math.random()*H*.3;}if(b.x<-50)b.x=W+50;drawBat(b);});animId=requestAnimationFrame(animate);}window.addEventListener('resize',resize);resize();window._startAutumn3=function(){ctx.fillStyle='#050208';ctx.fillRect(0,0,cv.width,cv.height);if(!animId)animate();};window._stopAutumn3=function(){if(animId){cancelAnimationFrame(animId);animId=null;}ctx.clearRect(0,0,cv.width,cv.height);};})();

// ===== HQ CANVAS: MIKU CONCERT STAGE =====
(function(){const cv=document.getElementById('canvas-miku2'),ctx=cv.getContext('2d');let t=0,animId=null,notes=[],crowd=[],spotA=[-.4,0,.4];const NC=['♪','♫','♩','♬','♭'];function resize(){cv.width=window.innerWidth;cv.height=window.innerHeight;crowd=Array.from({length:Math.floor(cv.width/18)},(_,i)=>({x:i*18+9,sway:Math.random()*Math.PI*2,col:Math.random()>.5?'rgba(57,197,187,':'rgba(255,120,180,'}));}function spawnNote(){return{x:cv.width*.5+(Math.random()-.5)*cv.width*.4,y:cv.height*.45,vy:-.8-Math.random()*1.2,vx:(Math.random()-.5)*1.5,alpha:1,char:NC[Math.floor(Math.random()*NC.length)],size:14+Math.random()*18,hue:Math.random()>.5?174:310};}function animate(){if(cv.style.display==='none'){animId=null;return;}t+=.016;const W=cv.width,H=cv.height;const bg=ctx.createLinearGradient(0,0,0,H);bg.addColorStop(0,'#020412');bg.addColorStop(.6,'#0a0a20');bg.addColorStop(1,'#04080c');ctx.fillStyle=bg;ctx.fillRect(0,0,W,H);spotA.forEach((baseA,i)=>{const a=baseA+Math.sin(t*.4+i*1.2)*.3;const sg=ctx.createLinearGradient(W*.5,H*.02,W*.5+Math.tan(a)*H*.85,H*.87);const cols=['rgba(57,197,187,','rgba(255,120,180,','rgba(180,140,255,'];sg.addColorStop(0,cols[i]+'0)');sg.addColorStop(.3,cols[i]+'.06)');sg.addColorStop(1,cols[i]+'0)');ctx.fillStyle=sg;ctx.beginPath();ctx.moveTo(W*.5,H*.02);ctx.lineTo(W*.5+Math.tan(a)*H*.85-W*.06,H*.87);ctx.lineTo(W*.5+Math.tan(a)*H*.85+W*.06,H*.87);ctx.closePath();ctx.fill();ctx.fillStyle=cols[i]+'.12)';ctx.beginPath();ctx.ellipse(W*.5+Math.tan(a)*H*.85,H*.87,W*.07,H*.018,0,0,Math.PI*2);ctx.fill();});const stage=ctx.createLinearGradient(0,H*.82,0,H*.9);stage.addColorStop(0,'#1a1a2e');stage.addColorStop(1,'#0d0d1a');ctx.fillStyle=stage;ctx.fillRect(0,H*.82,W,H*.18);ctx.strokeStyle='rgba(57,197,187,.25)';ctx.lineWidth=2;ctx.beginPath();ctx.moveTo(0,H*.82);ctx.lineTo(W,H*.82);ctx.stroke();crowd.forEach(c=>{c.sway+=.04;const gy=H*.75+Math.sin(c.sway)*H*.025;ctx.strokeStyle=c.col+'.6)';ctx.lineWidth=2;ctx.shadowColor=c.col+'.8)';ctx.shadowBlur=8;ctx.beginPath();ctx.moveTo(c.x,H*.82);ctx.lineTo(c.x,gy);ctx.stroke();ctx.fillStyle=c.col+'.9)';ctx.beginPath();ctx.arc(c.x,gy,3,0,Math.PI*2);ctx.fill();});ctx.shadowBlur=0;if(Math.random()>.9)notes.push(spawnNote());notes=notes.filter(n=>{n.y+=n.vy;n.x+=n.vx;n.alpha-=.012;if(n.alpha<=0)return false;ctx.save();ctx.globalAlpha=n.alpha;ctx.font='bold '+n.size+'px monospace';ctx.fillStyle='hsla('+n.hue+',90%,70%,1)';ctx.shadowColor='hsla('+n.hue+',100%,80%,.8)';ctx.shadowBlur=12;ctx.fillText(n.char,n.x,n.y);ctx.restore();return true;});const mx=W*.5,my=H*.72;ctx.fillStyle='rgba(20,30,40,.95)';ctx.beginPath();ctx.ellipse(mx,my-H*.05,H*.02,H*.02,0,0,Math.PI*2);ctx.fill();ctx.fillRect(mx-H*.012,my-H*.04,H*.024,H*.06);ctx.strokeStyle='rgba(57,197,187,.5)';ctx.lineWidth=3;ctx.shadowColor='rgba(57,197,187,.6)';ctx.shadowBlur=15;for(let s=0;s<2;s++){const tx=mx+(s===0?-1:1)*H*.04;ctx.beginPath();ctx.moveTo(tx,my-H*.03);ctx.quadraticCurveTo(tx+(s===0?-H*.06:H*.06),my+H*.04,tx+(s===0?-H*.04:H*.04),my+H*.1);ctx.stroke();}ctx.shadowBlur=0;animId=requestAnimationFrame(animate);}window.addEventListener('resize',resize);resize();window._startMiku2=function(){ctx.fillStyle='#020412';ctx.fillRect(0,0,cv.width,cv.height);if(!animId)animate();};window._stopMiku2=function(){if(animId){cancelAnimationFrame(animId);animId=null;}ctx.clearRect(0,0,cv.width,cv.height);};})();

// ===== HQ CANVAS: CYBER RAIN (MIKU) =====
(function(){const cv=document.getElementById('canvas-miku3'),ctx=cv.getContext('2d');let cols=[],animId=null;const CHARS='ミクVOCALOID音楽初音♪♫♩♬012345678ABCDEF';function resize(){cv.width=window.innerWidth;cv.height=window.innerHeight;const cw=16;cols=Array.from({length:Math.ceil(cv.width/cw)},(_,i)=>({x:i*cw,y:Math.random()*-cv.height,sp:1+Math.random()*2.5,hue:Math.random()>.5?174:310}));}function animate(){if(cv.style.display==='none'){animId=null;return;}ctx.fillStyle='rgba(0,0,0,.085)';ctx.fillRect(0,0,cv.width,cv.height);cols.forEach(c=>{const ch=CHARS[Math.floor(Math.random()*CHARS.length)];ctx.font='13px monospace';const fade=Math.max(0,1-c.y/cv.height*.8);const isHead=c.y>0&&c.y<20;if(isHead){ctx.fillStyle='hsl('+c.hue+',100%,90%)';ctx.shadowColor='hsl('+c.hue+',100%,80%)';ctx.shadowBlur=12;}else{ctx.fillStyle='hsla('+c.hue+',90%,'+(50+fade*20)+'%,'+(0.5+fade*.4)+')';ctx.shadowBlur=0;}ctx.fillText(ch,c.x,c.y);ctx.shadowBlur=0;c.y+=c.sp;if(c.y>cv.height+20){c.y=-30-Math.random()*cv.height*.3;c.hue=Math.random()>.5?174:310;}});animId=requestAnimationFrame(animate);}window.addEventListener('resize',resize);resize();window._startMiku3=function(){ctx.fillStyle='#000';ctx.fillRect(0,0,cv.width,cv.height);if(!animId)animate();};window._stopMiku3=function(){if(animId){cancelAnimationFrame(animId);animId=null;}ctx.clearRect(0,0,cv.width,cv.height);};})();

// ===== HQ CANVAS: CUTE HEARTS & SPARKLES =====
(function(){const cv=document.getElementById('canvas-cute'),ctx=cv.getContext('2d');let hearts=[],sparks=[],animId=null,t=0;const HUES=[340,310,280,350,320,360,290];function resize(){cv.width=window.innerWidth;cv.height=window.innerHeight;hearts=Array.from({length:55},()=>spawnHeart(false));sparks=Array.from({length:40},()=>spawnSpark());}function spawnHeart(atTop){return{x:Math.random()*cv.width,y:atTop?cv.height+20:Math.random()*cv.height,size:8+Math.random()*22,sp:.25+Math.random()*.65,vx:(Math.random()-.5)*.4,hue:HUES[Math.floor(Math.random()*HUES.length)],alpha:.5+Math.random()*.5,rot:Math.random()*Math.PI*.2-.1,rotV:(Math.random()-.5)*.015,sway:Math.random()*Math.PI*2,swayS:.008+Math.random()*.012};}function spawnSpark(){return{x:Math.random()*cv.width,y:Math.random()*cv.height,size:2+Math.random()*4,phase:Math.random()*Math.PI*2,sp:.003+Math.random()*.008,hue:HUES[Math.floor(Math.random()*HUES.length)],alpha:.4+Math.random()*.6};}function drawHeart(x,y,s,col,op,rot){ctx.save();ctx.translate(x,y);ctx.rotate(rot);ctx.globalAlpha=op;ctx.fillStyle=col;ctx.shadowColor=col;ctx.shadowBlur=s*.6;ctx.beginPath();ctx.moveTo(0,-s*.2);ctx.bezierCurveTo(s*.6,-s*.9,s*1.1,s*.1,0,s*.9);ctx.bezierCurveTo(-s*1.1,s*.1,-s*.6,-s*.9,0,-s*.2);ctx.closePath();ctx.fill();ctx.restore();}function animate(){if(cv.style.display==='none'){animId=null;return;}t+=.016;const W=cv.width,H=cv.height;const bg=ctx.createLinearGradient(0,0,0,H);bg.addColorStop(0,'#1a0820');bg.addColorStop(.5,'#220d28');bg.addColorStop(1,'#1a0a18');ctx.fillStyle=bg;ctx.fillRect(0,0,W,H);sparks.forEach(s=>{s.phase+=s.sp*60;const a=s.alpha*(.5+.5*Math.sin(s.phase));const sz=s.size*(.5+.5*Math.sin(s.phase));ctx.fillStyle='hsla('+s.hue+',90%,75%,'+a+')';for(let p=0;p<4;p++){const a2=p/4*Math.PI*2;ctx.fillRect(s.x+Math.cos(a2)*sz-1,s.y+Math.sin(a2)*sz-1,2,2);}ctx.beginPath();ctx.arc(s.x,s.y,sz*.4,0,Math.PI*2);ctx.fillStyle='hsla('+s.hue+',100%,90%,'+(a*.8)+')';ctx.fill();});hearts.forEach(h=>{h.y-=h.sp;h.x+=h.vx+Math.sin(h.sway+t)*.5;h.sway+=h.swayS;h.rot+=h.rotV;if(h.y<-30||h.x<-50||h.x>W+50)Object.assign(h,spawnHeart(true));drawHeart(h.x,h.y,h.size,'hsl('+h.hue+',90%,65%)',h.alpha,h.rot);});for(let i=0;i<5;i++){const bx=W*(.1+i*.18+Math.sin(t*.08+i)*.05),by=H*(.3+Math.sin(t*.06+i*1.4)*.25);const bg2=ctx.createRadialGradient(bx,by,0,bx,by,H*.2);bg2.addColorStop(0,'hsla('+HUES[i]+',80%,60%,.05)');bg2.addColorStop(1,'rgba(0,0,0,0)');ctx.fillStyle=bg2;ctx.fillRect(0,0,W,H);}animId=requestAnimationFrame(animate);}window.addEventListener('resize',resize);resize();window._startCute=function(){if(!animId)animate();};window._stopCute=function(){if(animId){cancelAnimationFrame(animId);animId=null;}ctx.clearRect(0,0,cv.width,cv.height);};})();

// ===== CANVAS: COMMODORE 64 BASIC SCREEN =====
(function(){const cv=document.getElementById('canvas-c64'),ctx=cv.getContext('2d');let t=0,animId=null,cursor=true,curT=0,lines=[''],bootIdx=0,typPos=0;const BLUE='#3333aa',LIGHT='#aaaaff',CW=8,CH=14,COLS=40,ROWS=25;const SRC=[' **** COMMODORE 64 BASIC V2 ****',' 64K RAM SYSTEM  38911 BASIC BYTES FREE','','READY.','>LOAD "DASHBOARD",8,1','','SEARCHING FOR DASHBOARD','FOUND  DASHBOARD','LOADING.','VERIFYING.','','READY.','>RUN','','INITIALIZING DASHBOARD V1.3...','','SYS 49152','','?READY','','>10 PRINT "DASH";:GOTO 10','RUN'];function resize(){cv.width=window.innerWidth;cv.height=window.innerHeight;}function animate(){if(cv.style.display==='none'){animId=null;return;}t+=.016;curT+=.016;if(curT>.55){curT=0;cursor=!cursor;}const W=cv.width,H=cv.height;ctx.fillStyle=BLUE;ctx.fillRect(0,0,W,H);for(let y=0;y<H;y+=3){ctx.fillStyle='rgba(0,0,0,.11)';ctx.fillRect(0,y,W,1);}const sx=Math.max(0,(W-COLS*CW)/2),sy=Math.max(0,(H-ROWS*CH)/2);ctx.font='bold '+CH+'px monospace';const visLines=lines.slice(-ROWS);visLines.forEach((line,r)=>{for(let c=0;c<Math.min(line.length,COLS);c++){const ch=line[c];ctx.fillStyle=LIGHT;ctx.fillText(ch,sx+c*CW,sy+r*CH+CH-2);}});if(cursor){const cl=visLines[visLines.length-1]||'';ctx.fillStyle=LIGHT;ctx.fillRect(sx+Math.min(cl.length,COLS)*CW,sy+(visLines.length-1)*CH,CW,CH-2);}if(bootIdx<SRC.length&&Math.floor(t*9)>typPos){const src=SRC[bootIdx];if(typPos<src.length){lines[lines.length-1]=(lines[lines.length-1]||'')+src[typPos];typPos++;}else{lines.push('');typPos=0;bootIdx++;if(lines.length>ROWS*2)lines=lines.slice(-ROWS);}}animId=requestAnimationFrame(animate);}window.addEventListener('resize',resize);resize();window._startC64=function(){if(!animId)animate();};window._stopC64=function(){if(animId){cancelAnimationFrame(animId);animId=null;}ctx.clearRect(0,0,cv.width,cv.height);lines=[''];bootIdx=0;typPos=0;cursor=true;};})();

// ===== CANVAS: AMIGA COPPER BARS =====
(function(){const cv=document.getElementById('canvas-amiga'),ctx=cv.getContext('2d');let t=0,animId=null;const BARS=[{hue:0,spd:.9,off:0,w:.14},{hue:120,spd:.65,off:Math.PI*.66,w:.12},{hue:240,spd:1.05,off:Math.PI*1.33,w:.13},{hue:55,spd:.75,off:Math.PI*.33,w:.1},{hue:300,spd:.85,off:Math.PI,w:.11},{hue:180,spd:.55,off:Math.PI*1.6,w:.09}];function resize(){cv.width=window.innerWidth;cv.height=window.innerHeight;}function animate(){if(cv.style.display==='none'){animId=null;return;}t+=.012;const W=cv.width,H=cv.height;ctx.fillStyle='#aaaaaa';ctx.fillRect(0,0,W,H);for(let y=0;y<H;y+=2){ctx.fillStyle='rgba(0,0,0,.04)';ctx.fillRect(0,y,W,1);}BARS.forEach(b=>{const cy=H*.5+Math.sin(t*b.spd+b.off)*H*.38;const bh=H*b.w;for(let py=0;py<bh;py++){const frac=py/bh,a=Math.sin(frac*Math.PI)*.82,l=35+Math.sin(frac*Math.PI)*35;ctx.fillStyle='hsla('+b.hue+',100%,'+l+'%,'+a+')';ctx.fillRect(0,cy-bh/2+py,W,1);}});const tbH=24;const tbg=ctx.createLinearGradient(0,0,0,tbH);tbg.addColorStop(0,'#c0c0c0');tbg.addColorStop(1,'#888888');ctx.fillStyle=tbg;ctx.fillRect(0,0,W,tbH);ctx.fillStyle='#000080';ctx.fillRect(2,3,W*.48,tbH-6);ctx.fillStyle='#fff';ctx.font='bold 12px monospace';ctx.fillText('\u25a0 Workbench 3.1',8,tbH-6);ctx.fillStyle='#888';ctx.fillRect(0,tbH,W,1);const diskOn=Math.random()>.9;ctx.fillStyle=diskOn?'#ff4400':'#550000';ctx.beginPath();ctx.arc(W-18,12,5,0,Math.PI*2);ctx.fill();ctx.fillStyle='rgba(255,255,255,.4)';ctx.beginPath();ctx.arc(W-20,10,2,0,Math.PI*2);ctx.fill();animId=requestAnimationFrame(animate);}window.addEventListener('resize',resize);resize();window._startAmiga=function(){if(!animId)animate();};window._stopAmiga=function(){if(animId){cancelAnimationFrame(animId);animId=null;}ctx.clearRect(0,0,cv.width,cv.height);};})();

// ===== CANVAS: NEXTSTEP DARK MARBLE =====
(function(){const cv=document.getElementById('canvas-nextstep'),ctx=cv.getContext('2d');let t=0,animId=null,orbs=[];function resize(){cv.width=window.innerWidth;cv.height=window.innerHeight;orbs=Array.from({length:10},()=>({x:Math.random()*cv.width,y:Math.random()*cv.height,r:25+Math.random()*70,vx:(Math.random()-.5)*.12,vy:(Math.random()-.5)*.12,alpha:.04+Math.random()*.07}));}function animate(){if(cv.style.display==='none'){animId=null;return;}t+=.012;const W=cv.width,H=cv.height;ctx.fillStyle='#1c1c1c';ctx.fillRect(0,0,W,H);ctx.strokeStyle='rgba(55,55,55,.14)';ctx.lineWidth=.6;for(let i=0;i<10;i++){const y=H*i/10+Math.sin(t*.08+i*0.7)*H*.04;ctx.beginPath();ctx.moveTo(0,y);ctx.bezierCurveTo(W*.28,y+H*.03*Math.sin(t*.13+i),W*.72,y-H*.025*Math.cos(t*.10+i),W,y+H*.02*Math.sin(t*.09+i*1.3));ctx.stroke();}ctx.strokeStyle='rgba(55,55,55,.07)';ctx.lineWidth=.4;for(let x=0;x<W;x+=40){ctx.beginPath();ctx.moveTo(x,0);ctx.lineTo(x,H);ctx.stroke();}for(let y=0;y<H;y+=40){ctx.beginPath();ctx.moveTo(0,y);ctx.lineTo(W,y);ctx.stroke();}orbs.forEach(o=>{o.x+=o.vx;o.y+=o.vy;if(o.x<-o.r)o.x=W+o.r;if(o.x>W+o.r)o.x=-o.r;if(o.y<-o.r)o.y=H+o.r;if(o.y>H+o.r)o.y=-o.r;const g=ctx.createRadialGradient(o.x-o.r*.3,o.y-o.r*.3,2,o.x,o.y,o.r);g.addColorStop(0,'rgba(220,220,220,'+o.alpha+')');g.addColorStop(.5,'rgba(80,80,80,'+(o.alpha*.4)+')');g.addColorStop(1,'rgba(0,0,0,0)');ctx.fillStyle=g;ctx.beginPath();ctx.arc(o.x,o.y,o.r,0,Math.PI*2);ctx.fill();});const big=ctx.createRadialGradient(W*.5,H*.5,0,W*.5,H*.5,Math.min(W,H)*.38);big.addColorStop(0,'rgba(60,60,60,.04)');big.addColorStop(1,'rgba(0,0,0,0)');ctx.fillStyle=big;ctx.fillRect(0,0,W,H);animId=requestAnimationFrame(animate);}window.addEventListener('resize',resize);resize();window._startNextstep=function(){ctx.fillStyle='#1c1c1c';ctx.fillRect(0,0,cv.width,cv.height);if(!animId)animate();};window._stopNextstep=function(){if(animId){cancelAnimationFrame(animId);animId=null;}ctx.clearRect(0,0,cv.width,cv.height);};})();

// ===== CANVAS: BEOS BOUNCING BALLS =====
(function(){const cv=document.getElementById('canvas-beos'),ctx=cv.getContext('2d');let t=0,animId=null,balls=[];function resize(){cv.width=window.innerWidth;cv.height=window.innerHeight;balls=Array.from({length:14},()=>({x:50+Math.random()*(cv.width-100),y:30+Math.random()*cv.height*.5,vx:(Math.random()-.5)*2.8,vy:Math.random()*2+.5,r:14+Math.random()*28,hue:20+Math.floor(Math.random()*8)*35}));}function animate(){if(cv.style.display==='none'){animId=null;return;}t+=.016;const W=cv.width,H=cv.height;const bg=ctx.createLinearGradient(0,0,0,H);bg.addColorStop(0,'#dcc838');bg.addColorStop(.5,'#c8b428');bg.addColorStop(1,'#b8a018');ctx.fillStyle=bg;ctx.fillRect(0,0,W,H);for(let y=0;y<H;y+=2){ctx.fillStyle='rgba(0,0,0,.028)';ctx.fillRect(0,y,W,1);}const floor=H*.88;balls.forEach(b=>{b.vy+=.14;b.y+=b.vy;b.x+=b.vx;if(b.y>floor-b.r){b.y=floor-b.r;b.vy*=-.72+Math.random()*-.06;}if(b.x<b.r||b.x>W-b.r){b.vx*=-1;b.x=Math.max(b.r,Math.min(W-b.r,b.x));}const squish=Math.abs(b.vy)>.5&&b.y>floor-b.r-2?1+Math.abs(b.vy)*.08:1;ctx.fillStyle='rgba(0,0,0,.12)';ctx.beginPath();ctx.ellipse(b.x,floor+2,b.r*.85/squish,b.r*.18*squish,0,0,Math.PI*2);ctx.fill();const g=ctx.createRadialGradient(b.x-b.r*.32,b.y-b.r*.32,b.r*.04,b.x,b.y,b.r);g.addColorStop(0,'hsl('+b.hue+',100%,88%)');g.addColorStop(.35,'hsl('+b.hue+',95%,62%)');g.addColorStop(.75,'hsl('+b.hue+',85%,38%)');g.addColorStop(1,'hsl('+b.hue+',75%,18%)');ctx.fillStyle=g;ctx.save();ctx.translate(b.x,b.y);ctx.scale(1/squish,squish);ctx.beginPath();ctx.arc(0,0,b.r,0,Math.PI*2);ctx.fill();ctx.restore();ctx.fillStyle='rgba(255,255,255,.45)';ctx.beginPath();ctx.ellipse(b.x-b.r*.28,b.y-b.r*.28,b.r*.25,b.r*.16,-.7,0,Math.PI*2);ctx.fill();});const tbH=22;const tbg=ctx.createLinearGradient(0,0,0,tbH);tbg.addColorStop(0,'#f0cc20');tbg.addColorStop(1,'#c09800');ctx.fillStyle=tbg;ctx.fillRect(0,0,W,tbH);ctx.fillStyle='rgba(0,0,0,.65)';ctx.font='bold 11px sans-serif';ctx.fillText('BeOS R5 Professional \u2014 Desktop',8,tbH-5);animId=requestAnimationFrame(animate);}window.addEventListener('resize',resize);resize();window._startBeos=function(){if(!animId)animate();};window._stopBeos=function(){if(animId){cancelAnimationFrame(animId);animId=null;}ctx.clearRect(0,0,cv.width,cv.height);};})();

// ===== CANVAS: THANKSGIVING HARVEST =====
(function(){const cv=document.getElementById('canvas-thanksgiving'),ctx=cv.getContext('2d');let leaves=[],animId=null,t=0;const LCOLS=['#c0390b','#d35400','#e67e22','#f39c12','#d4ac0d','#b7770d','#a04000','#922b21','#cb4335','#f0b27a'];function resize(){cv.width=window.innerWidth;cv.height=window.innerHeight;leaves=Array.from({length:90},()=>spawnLeaf(false));}function spawnLeaf(atTop){return{x:atTop?Math.random()*cv.width:Math.random()*cv.width,y:atTop?-20:Math.random()*cv.height,r:8+Math.random()*18,sp:.4+Math.random()*1.2,sw:Math.random()*Math.PI*2,sd:.005+Math.random()*.01,rot:Math.random()*Math.PI*2,rotS:(Math.random()-.5)*.04,col:LCOLS[Math.floor(Math.random()*LCOLS.length)],op:.6+Math.random()*.4};}function drawLeaf(x,y,r,rot,col,op){ctx.save();ctx.translate(x,y);ctx.rotate(rot);ctx.globalAlpha=op;ctx.fillStyle=col;ctx.beginPath();ctx.moveTo(0,-r);ctx.bezierCurveTo(r*.6,-r*.6,r,.2,0,r*.5);ctx.bezierCurveTo(-r,.2,-r*.6,-r*.6,0,-r);ctx.closePath();ctx.fill();ctx.strokeStyle=col;ctx.lineWidth=.8;ctx.globalAlpha=op*.6;ctx.beginPath();ctx.moveTo(0,-r*.8);ctx.lineTo(0,r*.4);ctx.stroke();ctx.restore();}function animate(){if(cv.style.display==='none'){animId=null;return;}t+=.016;const W=cv.width,H=cv.height;const sky=ctx.createLinearGradient(0,0,0,H);sky.addColorStop(0,'#180900');sky.addColorStop(.35,'#300e02');sky.addColorStop(.7,'#4a1804');sky.addColorStop(1,'#180a00');ctx.fillStyle=sky;ctx.fillRect(0,0,W,H);const mx=W*.5,my=H*.22;const mg=ctx.createRadialGradient(mx,my,0,mx,my,H*.35);mg.addColorStop(0,'rgba(230,110,10,.32)');mg.addColorStop(.4,'rgba(180,70,5,.18)');mg.addColorStop(1,'rgba(0,0,0,0)');ctx.fillStyle=mg;ctx.beginPath();ctx.arc(mx,my,H*.35,0,Math.PI*2);ctx.fill();for(let i=0;i<30;i++){const sx=(Math.sin(i*197.3)*W+W)%W,sy=(Math.sin(i*83.7)*H*.45)%(H*.45);ctx.fillStyle='rgba(220,160,80,'+(0.15+0.1*Math.sin(t*.3+i))+')';ctx.beginPath();ctx.arc(sx,sy,.7,0,Math.PI*2);ctx.fill();}const gnd=ctx.createLinearGradient(0,H*.72,0,H);gnd.addColorStop(0,'#2a0e02');gnd.addColorStop(1,'#180800');ctx.fillStyle=gnd;ctx.fillRect(0,H*.72,W,H*.28);for(let tx=0;tx<W;tx+=W*.1){const th=H*.18+Math.sin(tx*.004)*H*.06;ctx.fillStyle='rgba(15,5,0,.9)';ctx.beginPath();ctx.moveTo(tx,H*.72);ctx.bezierCurveTo(tx-W*.04,H*.72-th*.3,tx+W*.04,H*.72-th*.7,tx+W*.02,H*.72-th);ctx.bezierCurveTo(tx+W*.06,H*.72-th*.7,tx+W*.08,H*.72-th*.3,tx+W*.1,H*.72);ctx.closePath();ctx.fill();}leaves.forEach(l=>{l.y+=l.sp;l.x+=Math.sin(l.sw)*.7;l.sw+=l.sd;l.rot+=l.rotS;if(l.y>H+20||l.x<-40||l.x>W+40)Object.assign(l,spawnLeaf(true));drawLeaf(l.x,l.y,l.r,l.rot,l.col,l.op);});animId=requestAnimationFrame(animate);}window.addEventListener('resize',resize);resize();window._startThanksgiving=function(){if(!animId)animate();};window._stopThanksgiving=function(){if(animId){cancelAnimationFrame(animId);animId=null;}ctx.clearRect(0,0,cv.width,cv.height);};})();

// ===== CANVAS: MAC OS X TIGER NEBULA =====
(function(){const cv=document.getElementById('canvas-osxtiger'),ctx=cv.getContext('2d');let t=0,animId=null,stars=[],nebulae=[];function resize(){cv.width=window.innerWidth;cv.height=window.innerHeight;stars=Array.from({length:180},()=>({x:Math.random()*cv.width,y:Math.random()*cv.height,r:.3+Math.random()*.9,p:Math.random()*Math.PI*2,sp:.003+Math.random()*.008}));nebulae=Array.from({length:5},()=>({x:Math.random()*cv.width,y:Math.random()*cv.height,rx:100+Math.random()*200,ry:80+Math.random()*150,rot:Math.random()*Math.PI,hue:200+Math.floor(Math.random()*5)*30,alpha:.04+Math.random()*.08,speed:.0008+Math.random()*.0005}));}function animate(){if(cv.style.display==='none'){animId=null;return;}t+=.008;const W=cv.width,H=cv.height;const bg=ctx.createRadialGradient(W*.35,H*.45,0,W*.35,H*.45,Math.max(W,H)*.8);bg.addColorStop(0,'#0a0520');bg.addColorStop(.35,'#070c28');bg.addColorStop(.7,'#040818');bg.addColorStop(1,'#020408');ctx.fillStyle=bg;ctx.fillRect(0,0,W,H);nebulae.forEach(n=>{n.rot+=n.speed;const g=ctx.createRadialGradient(n.x,n.y,0,n.x,n.y,Math.max(n.rx,n.ry));g.addColorStop(0,'hsla('+n.hue+',80%,55%,'+(n.alpha*1.5)+')');g.addColorStop(.4,'hsla('+n.hue+',70%,45%,'+n.alpha+')');g.addColorStop(1,'hsla('+n.hue+',60%,30%,0)');ctx.save();ctx.translate(n.x,n.y);ctx.rotate(n.rot);ctx.scale(1,n.ry/n.rx);ctx.fillStyle=g;ctx.globalCompositeOperation='screen';ctx.beginPath();ctx.arc(0,0,n.rx,0,Math.PI*2);ctx.fill();ctx.globalCompositeOperation='source-over';ctx.restore();});stars.forEach(s=>{s.p+=s.sp;const a=.25+.22*Math.sin(s.p),sz=s.r*(1+.3*Math.sin(s.p));ctx.fillStyle='rgba(200,215,255,'+a+')';ctx.beginPath();ctx.arc(s.x,s.y,sz,0,Math.PI*2);ctx.fill();});const cx=W*.5,cy=H*.48,cr=Math.min(W,H)*.18;const cg=ctx.createRadialGradient(cx-cr*.2,cy-cr*.2,cr*.02,cx,cy,cr);cg.addColorStop(0,'rgba(160,100,255,.18)');cg.addColorStop(.3,'rgba(80,60,200,.1)');cg.addColorStop(.7,'rgba(40,30,140,.05)');cg.addColorStop(1,'rgba(0,0,0,0)');ctx.fillStyle=cg;ctx.beginPath();ctx.arc(cx,cy,cr,0,Math.PI*2);ctx.fill();animId=requestAnimationFrame(animate);}window.addEventListener('resize',resize);resize();window._startOsxtiger=function(){ctx.fillStyle='#020408';ctx.fillRect(0,0,cv.width,cv.height);if(!animId)animate();};window._stopOsxtiger=function(){if(animId){cancelAnimationFrame(animId);animId=null;}ctx.clearRect(0,0,cv.width,cv.height);};})();
</script>
</body>
</html>

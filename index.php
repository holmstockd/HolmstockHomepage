<?php require_once 'auth.php';
$cfg       = getDashConfig();
$title     = $cfg['title'];
$grid_cols = max(1, min(12, (int)$cfg['grid_cols']));
$drives    = json_decode(@file_get_contents(__DIR__.'/dash_drives.json') ?: '[]', true) ?: [];
$monitor      = json_decode(@file_get_contents(__DIR__.'/dash_monitor.json') ?: '{}', true)
                ?: ['cpu'=>true,'ram'=>true,'storage'=>true]; // default all on
$html_widgets = json_decode(@file_get_contents(__DIR__.'/dash_html_widgets.json') ?: '[]', true) ?: [];
$_dash_role     = getCurrentRole();   // 'admin' | 'user' | 'readonly'
$_dash_uname    = getCurrentUsername();
$_dash_is_admin = isAdmin();
// Per-theme layout files — each theme gets its own saved positions.
$_allowed_themes_php = ['win98','win9x','win2k','winxp','winxp2','winphone','aqua','ios26',
                        'jellybean','palmos','palmtreo','pocketpc','macos','macos9','mac9',
                        'macosx','osxtiger','ubuntu','c64','os2','webos','professional','girly',
                        'spring','summer','autumn','winter','thanksgiving','july4','christmas','custom'];
// Layout is shared across all themes — one file, one source of truth
$_links_file = __DIR__.'/dash_links.json';
$links = json_decode(@file_get_contents($_links_file) ?: '[]', true) ?: [];
$bgs           = json_decode(@file_get_contents(__DIR__.'/dash_custom_bg.json') ?: '{}', true) ?: [];
$ctJson        = @file_get_contents(__DIR__.'/dash_custom_theme.json') ?: '{}';
$_dash_state   = json_decode(@file_get_contents(__DIR__.'/dash_state.json') ?: '{}', true) ?: [];
$hidden_themes = json_decode(@file_get_contents(__DIR__.'/dash_hidden_themes.json') ?: '[]', true) ?: [];
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
    'win98'    =>'💾 Win 98',
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
    'pocketpc' =>'📲 Pocket PC 6',
    'webos'    =>'🌙 Palm webOS',
    // ── Other Retro ──────────────────────────────────────────────────────
    'c64'      =>'🕹 Commodore 64',
    'os2'      =>'🗄 OS/2 Warp',
    // ── Seasonal / Other ─────────────────────────────────────────────────
    'professional'=>'👔 Professional',
    'girly'    =>'🌸 Girly',
    'spring'   =>'🌷 Spring',
    'summer'   =>'🏖 Summer',
    'autumn'   =>'🍂 Autumn',
    'winter'   =>'❄️ Winter',
    'thanksgiving'=>'🦃 Thanksgiving',
    'july4'    =>'🎆 July 4th',
    'christmas'=>'✝️ Christmas',
];
// Only show 'custom' if the user has created one
$_has_custom = !empty(json_decode(@file_get_contents(__DIR__.'/dash_custom_theme.json') ?: '{}', true));
if ($_has_custom) $all_themes['custom'] = '🎨 Custom';
$visible_themes = array_filter($all_themes, fn($k) => !in_array($k, $hidden_themes), ARRAY_FILTER_USE_KEY);
// Load page folders (file folder widgets placed on the dashboard)
$page_folders = json_decode(@file_get_contents(__DIR__.'/dash_page_folders.json') ?: '[]', true) ?: [];
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
#wallpaper.theme-aqua{background-color:#1b6ca8!important;background-image:radial-gradient(ellipse 200% 80% at 50% -20%,rgba(255,255,255,.15) 0%,transparent 50%),radial-gradient(ellipse 100% 40% at 50% 0%,#c8e8f8 0%,#2a7cc8 40%,#0a3c88 100%)!important;background-size:cover!important;animation:aquaShimmer 8s ease-in-out infinite!important;}
#wallpaper.theme-win2k{background-color:#3a6ea5!important;background-image:repeating-linear-gradient(0deg,rgba(255,255,255,.03) 0px,rgba(255,255,255,.03) 1px,transparent 1px,transparent 4px)!important;animation:win2kPulse 6s ease-in-out infinite!important;}
#wallpaper.theme-winxp{background-color:#4a90d9!important;background-image:none!important;}
#wallpaper.theme-winphone{background-image:linear-gradient(135deg,#0050ef 0%,#0078d7 40%,#00b4d8 100%)!important;background-size:300% 300%!important;animation:metroShift 8s ease infinite!important;}
#wallpaper.theme-jellybean{background-color:#111!important;background-image:radial-gradient(ellipse 80% 40% at 50% 0%,rgba(51,170,255,.2) 0%,transparent 60%),linear-gradient(180deg,#1a1a2e 0%,#111 100%)!important;background-size:200% 200%!important;animation:jellydrift 10s ease-in-out infinite alternate!important;}
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
#ios26-overlay{display:none;position:fixed;inset:0;z-index:0;pointer-events:none;background:radial-gradient(ellipse 90% 70% at 15% 25%,rgba(90,60,160,.55) 0%,transparent 60%),radial-gradient(ellipse 70% 90% at 85% 75%,rgba(50,100,180,.45) 0%,transparent 60%),radial-gradient(ellipse 60% 60% at 50% 10%,rgba(130,60,140,.35) 0%,transparent 50%),#120c28;background-size:300% 300%;animation:ios26drift 18s ease-in-out infinite;}

/* ===== LAYOUT ===== */
#app{position:relative;z-index:1;min-height:100vh;display:flex;flex-direction:column;padding:8px;}

/* ===== HEADER ===== */
#header{background:var(--header-bg,var(--card-bg));border-top:2px solid var(--card-border-light);border-left:2px solid var(--card-border-light);border-right:2px solid var(--card-border-dark);border-bottom:2px solid var(--card-border-dark);padding:8px 14px;display:flex;align-items:center;justify-content:center;gap:8px;margin-bottom:10px;font-family:var(--font);font-size:13px;color:var(--card-text);flex-wrap:wrap;row-gap:5px;min-height:46px;}
#logo{font-weight:bold;font-size:14px;white-space:nowrap;}
.widget{display:flex;align-items:center;gap:4px;font-size:12px;color:var(--widget-text);}
/* ===== STAT WIDGET SECTIONS ===== */
.stat-section{position:absolute;min-width:180px;background:var(--card-bg);border:2px solid var(--card-border-dark);border-radius:var(--card-radius);box-shadow:var(--card-shadow);font-family:var(--font);font-size:12px;color:var(--card-text);overflow:hidden;user-select:none;z-index:10;}
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
.section{position:absolute;display:flex;flex-direction:column;gap:3px;cursor:default;transition:opacity .15s,box-shadow .15s;break-inside:avoid;min-width:180px;max-width:340px;width:240px;}
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
.section-header{display:flex;align-items:center;justify-content:space-between;}
.section-title{background:var(--section-title-bg);color:var(--section-title-text);font-family:var(--font);font-size:11px;font-weight:bold;padding:3px 8px;text-transform:uppercase;letter-spacing:.05em;border-radius:var(--card-radius);flex:1;}
.section-actions{display:flex;gap:3px;align-items:center;}
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
body.edit-mode .section-del-btn{background:rgba(200,50,50,.25);border-color:rgba(200,80,80,.4);color:#ff8888;}
.section-body{background:var(--card-bg);border-top:2px solid var(--card-border-light);border-left:2px solid var(--card-border-light);border-right:2px solid var(--card-border-dark);border-bottom:2px solid var(--card-border-dark);padding:3px;display:flex;flex-direction:column;gap:2px;flex:1;}

/* ===== CARD ===== */
.card{display:flex;align-items:center;gap:8px;padding:5px 7px;background:var(--card-bg);border-top:2px solid var(--card-border-light);border-left:2px solid var(--card-border-light);border-right:2px solid var(--card-border-dark);border-bottom:2px solid var(--card-border-dark);color:var(--card-text);font-family:var(--font);font-size:12px;cursor:pointer;border-radius:var(--card-radius);box-shadow:var(--card-shadow);transition:var(--card-transition);user-select:none;position:relative;}
.card:hover{background:var(--card-hover-bg);color:var(--card-hover-text);}
.card-icon{font-size:15px;flex-shrink:0;width:20px;text-align:center;}
.card-label{flex:1;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}
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
#switcher select,#switcher input[type=range]{font-size:11px;padding:2px 4px;cursor:pointer;border-radius:3px;border:1px solid #888;background:#fff;color:#000;max-width:150px;}
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
/* second-level flyout positions to the right */
.sm-flyout .sm-has-flyout>.sm-flyout{left:100%;top:-2px;}

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
#wallpaper.theme-c64{background:#5555d0!important;background-image:none!important;}
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
body.theme-girly{
  --font:'Segoe UI',Georgia,sans-serif;
  --card-bg:rgba(255,220,235,0.88);--card-border-light:rgba(255,180,210,0.9);--card-border-dark:rgba(220,100,150,0.4);
  --card-text:#5a1040;--card-hover-bg:rgba(240,90,150,0.85);--card-hover-text:#fff;
  --section-title-bg:linear-gradient(to right,#d94085,#f070a0);--section-title-text:#fff;
  --search-bg:rgba(255,255,255,0.7);--search-border:rgba(240,120,160,0.5);--search-text:#5a1040;
  --card-radius:18px;--card-shadow:0 3px 12px rgba(220,80,140,0.2);--card-transition:all 0.2s ease;
  --widget-text:#a0206a;--header-bg:rgba(255,210,230,0.95);
  background:linear-gradient(135deg,#ffe0ee,#ffd0e8,#ffeef5);color:#5a1040;
}
body.theme-girly #header{background:rgba(255,210,230,0.95);border-bottom:2px solid rgba(240,120,160,0.3);border-radius:0;}
body.theme-girly .section-body{background:rgba(255,240,248,0.7);border:1px solid rgba(240,150,180,0.3);border-radius:14px;}
body.theme-girly .section-title{border-radius:14px;}
body.theme-girly .card{border-radius:14px;}
body.theme-girly .card:hover{box-shadow:0 4px 20px rgba(220,80,140,0.4);}
body.theme-girly #wallpaper{background:radial-gradient(ellipse at 20% 30%,rgba(255,180,220,0.25) 0%,transparent 50%),radial-gradient(ellipse at 80% 70%,rgba(240,120,200,0.2) 0%,transparent 50%),radial-gradient(ellipse at 50% 50%,rgba(255,200,230,0.15) 0%,transparent 70%);}

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
body.theme-macos #doc-panel-header{
  background:linear-gradient(180deg,#eeeeee,#d0d0d0);color:#000;
  border-bottom:1px solid #b0b0b0;padding:6px 10px;
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
#doc-sidebar{width:140px;flex-shrink:0;border-right:1px solid var(--card-border-dark);padding:6px;overflow-y:auto;}
.doc-folder-btn{display:flex;align-items:center;gap:6px;width:100%;padding:6px 8px;background:none;border:none;border-radius:5px;color:var(--card-text);font-family:var(--font);font-size:12px;cursor:pointer;text-align:left;}
.doc-folder-btn:hover{background:var(--card-hover-bg);color:var(--card-hover-text);}
.doc-folder-btn.active{background:var(--card-hover-bg);color:var(--card-hover-text);font-weight:700;}
.doc-folder-btn .dfcount{margin-left:auto;font-size:10px;opacity:.6;}
#doc-main{flex:1;display:flex;flex-direction:column;overflow:hidden;}
#doc-toolbar{padding:8px 10px;border-bottom:1px solid var(--card-border-dark);display:flex;gap:6px;align-items:center;flex-shrink:0;flex-wrap:wrap;}
#doc-file-input{display:none;}
#doc-upload-btn{padding:5px 12px;background:var(--card-hover-bg);border:1px solid var(--card-border-light);border-radius:4px;color:var(--card-hover-text);cursor:pointer;font-size:12px;font-family:var(--font);}
#doc-folder-name{font-size:12px;font-weight:700;margin-right:auto;}
#doc-files{flex:1;overflow-y:auto;padding:6px;}
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

/* ===== WIDGET RESIZE HANDLE ===== */
.stat-resize-handle{display:none;position:absolute;right:0;top:50%;transform:translateY(-50%);width:10px;height:30px;cursor:e-resize;z-index:20;border-radius:0 4px 4px 0;}
.stat-resize-handle::after{content:'⋮';position:absolute;inset:0;display:flex;align-items:center;justify-content:center;font-size:12px;opacity:.35;}
body.edit-mode .stat-resize-handle{display:flex;align-items:center;justify-content:center;background:rgba(255,255,255,.12);}

/* ===== CUSTOM HTML WIDGET ===== */
.hw-widget{min-width:200px;max-width:420px;overflow:visible;z-index:12;}
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
</style>
</head>
<body>

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
      <div class="m9-popup-item" onclick="toggleM9Menu(this.closest('.m9-apple'));openPageFolder('<?= htmlspecialchars(addslashes($pf['label'])) ?>')">📂 <?= htmlspecialchars($pf['label']) ?></div>
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
    <span id="clock"></span>
    <?php if ($_dash_is_admin): ?>
    <a href="options.php" style="font-size:11px;padding:2px 7px;border-radius:4px;background:rgba(255,255,255,.15);border:1px solid rgba(255,255,255,.2);color:inherit;" title="Options">⚙️</a>
    <?php else: ?>
    <span title="Logged in as <?= htmlspecialchars($_dash_uname) ?> (<?= htmlspecialchars($_dash_role) ?>)"
      style="font-size:11px;padding:2px 7px;border-radius:4px;background:rgba(80,150,255,.15);border:1px solid rgba(80,150,255,.3);color:rgba(180,210,255,.9);cursor:default;">
      👤 <?= htmlspecialchars($_dash_uname) ?>
    </span>
    <?php endif; ?>
    <a href="?logout=1" style="font-size:11px;padding:2px 7px;border-radius:4px;background:rgba(255,60,60,.2);border:1px solid rgba(255,60,60,.3);color:inherit;" title="Logout">🚪</a>
    <button onclick="addPageFolder()" title="Add a file folder to the page" style="font-size:11px;padding:2px 7px;border-radius:4px;background:rgba(255,255,255,.12);border:1px solid rgba(255,255,255,.2);color:inherit;cursor:pointer;">📁 + Folder</button>
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
  </div>

  <div id="services">
    <?php
    // ===== STAT WIDGET SECTIONS =====
    $stat_pos = json_decode(@file_get_contents(__DIR__.'/dash_stat_pos.json') ?: '{}', true) ?: [];
    if ($monitor['cpu'] ?? true):
      $sp = $stat_pos['cpu'] ?? ['x'=>10,'y'=>10];
    ?>
    <div class="stat-section" id="stat-cpu" style="left:<?= (int)$sp['x'] ?>px;top:<?= (int)$sp['y'] ?>px;<?= isset($sp['w'])? 'width:'.(int)$sp['w'].'px;':'' ?>" data-stat="cpu">
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

    <?php /* ── Custom HTML Widgets ── */
      foreach ($html_widgets as $hw):
        $hwId = preg_replace('/[^a-zA-Z0-9_-]/', '', $hw['id'] ?? '');
        if (!$hwId) continue;
        $hwSp  = $stat_pos[$hwId] ?? ['x' => (int)($hw['x']??820), 'y' => (int)($hw['y']??80)];
        $hwName = htmlspecialchars($hw['name'] ?? 'Widget'); ?>
    <div class="stat-section hw-widget" id="stat-<?= $hwId ?>" data-stat="<?= $hwId ?>" style="left:<?= (int)$hwSp['x'] ?>px;top:<?= (int)$hwSp['y'] ?>px;">
      <div class="stat-section-hdr">🧩 <?= $hwName ?> <button class="stat-close-btn" onclick="hideStatWidget('stat-<?= $hwId ?>',event)" title="Hide">×</button></div>
      <div class="stat-section-body">
        <div class="hw-widget-content"><?= $hw['html'] ?></div>
      </div>
    </div>
    <?php endforeach; ?>

    <?php if (empty($links)): ?>
    <div class="section" data-id="default" style="left:20px;top:10px;">
      <div class="section-header"><div class="section-title">🖥 My Server</div><span class="section-count">1 item</span><button class="section-collapse-btn" onclick="toggleCollapse(event,this)" title="Collapse / Expand">▼</button><div class="section-actions"><button class="section-btn" onclick="addLink(this)">+ Add</button></div></div>
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
    <div class="section<?= $secCollapsed ? ' collapsed' : '' ?>" data-id="<?= htmlspecialchars($sec['id']??'') ?>" data-view="<?= htmlspecialchars($secView) ?>" style="left:<?= (int)$px ?>px;top:<?= (int)$py ?>px;" onclick="handleSectionClick(event,this)">
      <div class="section-header">
        <span class="section-folder-icon"><?= htmlspecialchars($sec['icon']??'📁') ?></span>
        <div class="section-title"><?= htmlspecialchars($sec['icon']??'') ?> <?= htmlspecialchars($sec['title']) ?></div>
        <span class="section-count"><?= $cardCount ?> item<?= $cardCount!==1?'s':'' ?></span>
        <button class="section-collapse-btn" onclick="toggleCollapse(event,this)" title="Collapse / Expand"><?= $secCollapsed ? '▶' : '▼' ?></button>
        <div class="section-actions">
          <span class="section-lock-indicator" title="Layout locked — click ✏️ Edit to rearrange">🔒</span>
          <button class="section-view-btn" onclick="toggleSectionView(event,this)" title="Toggle grid/list view"><?= $secView==='folder' ? '☰' : '⊞' ?></button>
          <button class="section-btn" onclick="addLink(this)">+ Add</button>
          <button class="section-btn section-del-btn" onclick="deleteSection(event,this)" title="Delete this column">🗑</button>
        </div>
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
    <?php endif; ?>
    <?php foreach ($page_folders as $pf): ?>
    <div class="page-folder" data-pf-id="<?= htmlspecialchars($pf['id']) ?>" style="left:<?= (int)($pf['pos_x']??400) ?>px;top:<?= (int)($pf['pos_y']??20) ?>px;" ondblclick="openPageFolder('<?= htmlspecialchars(addslashes($pf['label'])) ?>')">
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
        <div class="sm-flyout-item" onclick="closeStartMenu();openPageFolder('<?= htmlspecialchars(addslashes($pf['label'])) ?>')">
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
      <div class="w9x-item" onclick="closeWin9xMenu();openPageFolder('<?= htmlspecialchars(addslashes($pf['label'])) ?>')">
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
      <span>🗂 Documents</span>
      <button id="doc-panel-close" onclick="closeDocPanel()">✕</button>
    </div>
    <div id="doc-panel-body">
      <div id="doc-sidebar">
        <div id="doc-folder-list"></div>
        <div id="doc-new-folder-row" style="margin-top:6px;">
          <input id="doc-new-folder-input" type="text" placeholder="New folder…" onkeydown="if(event.key==='Enter')addDocFolder()">
          <button onclick="addDocFolder()">+</button>
        </div>
      </div>
      <div id="doc-main">
        <div id="doc-toolbar">
          <span id="doc-folder-name">Documents</span>
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
    <div class="modal-btns">
      <button class="modal-btn modal-btn-delete" id="modal-delete" onclick="deleteCard()" style="display:none">🗑 Delete</button>
      <button class="modal-btn modal-btn-cancel" onclick="closeModal()">Cancel</button>
      <button class="modal-btn modal-btn-save" onclick="saveCard()">💾 Save</button>
    </div>
  </div>
</div>

<script>
// ===== SERVER-SIDE DATA =====
const SERVER_BG = <?= json_encode($bgs) ?>;
const CUSTOM_THEME_SERVER = <?= $ctJson ?>;
const HP_USER = <?= json_encode($_dash_uname) ?>;
const PHP_STATE = <?= json_encode($_dash_state) ?>;
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
  win98:    [{v:'w-teal',l:'🟦 Teal'},{v:'w-circles',l:'🔴 Circles'},{v:'w-sandstone',l:'🟤 Sandstone'},{v:'w-forest',l:'🟢 Forest'},{v:'w-purple',l:'🟣 Purple'},{v:'w-navy',l:'🔵 Navy'},{v:'w-bricks',l:'🧱 Bricks'},{v:'w-clouds',l:'☁️ Clouds'},{v:'w-metal',l:'⚙️ Metal'}],
  win2k:    [{v:'default',l:'🔧 3D Pipes'}],
  winxp:    [{v:'default',l:'🌄 Bliss'},{v:'winxp2',l:'🐟 Aquarium'}],
  winphone: [{v:'default',l:'Metro'}],
  aqua:     [{v:'default',l:'💧 Silk Ribbons'}],
  ios26:        [{v:'default',l:'✨ Swirling Blobs'}],
  jellybean:    [{v:'default',l:'🤖 Jelly Bean'},{v:'jellybean2',l:'🌌 Nexus Live'}],
  startmenu:    [{v:'default',l:'🪟 Start Menu'}],
  win9x:        [{v:'w-teal',l:'🟦 Teal'},{v:'w-circles',l:'🔴 Circles'},{v:'w-sandstone',l:'🟤 Sandstone'},{v:'w-forest',l:'🟢 Forest'},{v:'w-purple',l:'🟣 Purple'},{v:'w-navy',l:'🔵 Navy'},{v:'w-bricks',l:'🧱 Bricks'},{v:'w-clouds',l:'☁️ Clouds'},{v:'w-metal',l:'⚙️ Metal'}],
  macos:        [{v:'default',l:'🌅 Sonoma Orbs'}],
  macos9:       [{v:'default',l:'🌈 Platinum'}],
  ubuntu:       [{v:'default',l:'🟠 GNOME'}],
  mac9:         [{v:'default',l:'🌈 Platinum Gray'}],
  macosx:       [{v:'default',l:'💧 Aqua Blue'}],
  palmos:       [{v:'default',l:'📟 Palm LCD'},{v:'palmtreo',l:'📱 Palm Treo'}],
  pocketpc:     [{v:'default',l:'📲 WM6 Bubbles'}],
  c64:          [{v:'default',l:'🕹 BASIC Screen'}],
  os2:          [{v:'default',l:'🗄 Warp 4'}],
  webos:        [{v:'default',l:'🌙 Glowing Orbs'}],
  spring:       [{v:'default',l:'🌸 Cherry Petals'}],
  summer:       [{v:'default',l:'🌊 Beach Waves'}],
  autumn:       [{v:'default',l:'🍂 Falling Leaves'}],
  winter:       [{v:'default',l:'❄️ Snowfall'}],
  thanksgiving: [{v:'default',l:'🦃 Harvest'}],
  july4:        [{v:'default',l:'🎆 Fireworks'}],
  christmas:    [{v:'default',l:'❄️ Snowfall'}],
  custom:       [{v:'default',l:'🎨 Custom Theme'}],
};

const themeClasses=['theme-aqua','theme-ios26','theme-winxp','theme-winxp2','theme-win2k','theme-winphone',
  'theme-jellybean','theme-jellybean2','theme-win9x','theme-osxtiger','theme-palmos','theme-palmtreo','theme-pocketpc',
  'theme-macos','theme-macos9','theme-mac9','theme-macosx','theme-ubuntu','theme-custom',
  'theme-c64','theme-os2','theme-webos',
  'theme-professional','theme-girly',
  'theme-spring','theme-summer','theme-autumn','theme-winter',
  'theme-thanksgiving','theme-july4','theme-christmas'];
const wallClasses=['wall-circles','wall-sandstone','wall-forest','wall-purple','wall-navy','wall-bricks','wall-clouds','wall-metal'];

let _currentBaseTheme='win98', _currentVariant='default';

function _saveState(patch) {
  // Write a key-value patch to server state (dash_state.json) and mirror to localStorage
  Object.keys(patch).forEach(k => {
    if (patch[k] === null) localStorage.removeItem(k);
    else localStorage.setItem(k, typeof patch[k] === 'string' ? patch[k] : JSON.stringify(patch[k]));
  });
  fetch('save_state.php', {method:'POST', keepalive:true, headers:{'Content-Type':'application/json'}, body:JSON.stringify(patch)}).catch(()=>{});
}
function onThemeChange(base) {
  _currentBaseTheme = base; _currentVariant = 'default';
  _saveState({'hp-theme': base});
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
    setTimeout(() => onVariantChange(savedV), 80);
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
  } else {
    // Show generic "Custom" option as fallback (opens options.php if nothing configured)
    variants=variants.concat([{v:'custom',l:'🎬 Custom…'}]);
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
  if(variant.startsWith('w-')){applyWallpaper(variant.replace('w-',''));stopBgMedia();return;}
  if(variant==='custom'){const bg=getCustomBg(_currentBaseTheme);if(bg)activateBg(bg);else window.open('options.php?tab=backgrounds#bg-'+_currentBaseTheme,'_blank');return;}
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
    if(bg.tile){
      img.style.backgroundRepeat='repeat';
      img.style.backgroundSize='auto';
      img.style.backgroundPosition='top left';
    } else {
      img.style.backgroundRepeat='no-repeat';
      img.style.backgroundSize='cover';
      img.style.backgroundPosition='center';
    }
    img.style.display='block';img.classList.add('active');
  } else {
    vid.src=url;vid.classList.add('active');vid.play().catch(()=>{});
  }
}
function stopBgMedia(){const vid=document.getElementById('bg-video'),img=document.getElementById('bg-image'),frm=document.getElementById('bg-iframe');vid.classList.remove('active');vid.pause();vid.src='';img.classList.remove('active');img.style.display='none';img.style.backgroundImage='';frm.classList.remove('active');frm.style.display='none';frm.src='';}

// ===== APPLY THEME =====
function applyTheme(theme) {
  stopBgMedia(); // always clear any active custom background when switching themes
  ['_stopPipes','_stopNexus','_stopNexus2','_stopAqua','_stopIos26','_stopAquarium','_stopPalmos','_stopPocketpc','_stopMacos','_stopMacosx','_stopUbuntu','_stopSnow','_stopLeaves','_stopPetals','_stopBliss','_stopSummer','_stopWebos'].forEach(fn=>{if(window[fn])window[fn]();});
  document.querySelectorAll('.screensaver-canvas').forEach(c=>c.style.display='none');
  themeClasses.forEach(c=>{document.body.classList.remove(c);document.getElementById('wallpaper').classList.remove(c);});
  wallClasses.forEach(c=>document.getElementById('wallpaper').classList.remove(c));
  const wp=document.getElementById('wallpaper');
  if(theme==='win98'||theme==='win9x'){applyWallpaper(localStorage.getItem('hp-wall')||'teal');
    if(theme==='win9x'){document.body.classList.add('theme-win9x');wp.classList.add('theme-win9x');}
  }
  else{document.body.classList.add('theme-'+theme);wp.classList.add('theme-'+theme);
    if(theme==='win2k')       {showC('canvas-pipes');     setTimeout(()=>_startPipes&&_startPipes(),100);}
    if(theme==='jellybean')   {showC('canvas-nexus');     setTimeout(()=>_startNexus&&_startNexus(),100);}
    if(theme==='jellybean2')  {showC('canvas-nexus2');    setTimeout(()=>_startNexus2&&_startNexus2(),100);}
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
    if(theme==='christmas')   {showC('canvas-snow');      setTimeout(()=>_startSnow&&_startSnow(),100);}
    if(theme==='summer')      {showC('canvas-summer');    setTimeout(()=>_startSummer&&_startSummer(),100);}
    if(theme==='webos')       {showC('canvas-webos');     setTimeout(()=>_startWebos&&_startWebos(),100);}
    if(theme==='custom')      applyCustomTheme();
  }
  _saveState({'hp-theme': theme});
  const tsel=document.getElementById('theme-sel');
  const baseMap={winxp2:'winxp',jellybean2:'jellybean',palmtreo:'palmos'};
  if(tsel){const base=baseMap[theme]||theme;if(tsel.value!==base)tsel.value=base;}
}
function showC(id){const el=document.getElementById(id);if(el)el.style.display='block';}

// ===== WALLPAPERS =====
function applyWallpaper(wall){const wp=document.getElementById('wallpaper');wallClasses.forEach(c=>wp.classList.remove(c));if(wall!=='teal')wp.classList.add('wall-'+wall);_saveState({'hp-wall':wall});}

// ===== CUSTOM THEME VARS =====
function applyCustomTheme(){const ct=JSON.parse(localStorage.getItem('dash-custom-theme')||'null')||CUSTOM_THEME_SERVER||{};if(!Object.keys(ct).length)return;const r=document.documentElement.style;['bg','card_bg','border_light','border_dark','card_text','hover_bg','hover_text','sec_from','sec_to','sec_text','radius','font'].forEach(k=>{if(ct[k])r.setProperty('--ct-'+k.replace('_','-'),ct[k]+(k==='radius'?'px':''));});if(ct.wallpaper&&ct.wallpaper!=='none')applyWallpaper(ct.wallpaper);}

// ===== SIZE =====
function applySize(val){const tS=document.getElementById('size-slider-top'),tL=document.getElementById('size-label-top');if(tS&&tS.value!=val)tS.value=val;if(tL)tL.textContent=val+'%';val=parseInt(val);const sv=document.getElementById('services');sv.style.transform='scale('+val/100+')';sv.style.transformOrigin='top left';sv.style.marginBottom=((val/100-1)*sv.scrollHeight)+'px';_saveState({'hp-size':String(val)});}

// ===== CLOCKS =====
function updateClock(){const now=new Date(),s=now.toLocaleString('en-US',{weekday:'short',month:'short',day:'numeric',hour:'numeric',minute:'2-digit'});document.getElementById('clock').textContent=s;const t=now.toLocaleTimeString('en-US',{hour:'numeric',minute:'2-digit'});['macos-clock-bar','m9-clock-bar','mac9-clock-bar','macosx-clock-bar','ubuntu-clock','osxtiger-clock'].forEach(id=>{const el=document.getElementById(id);if(el)el.textContent=t;});}
function updateTaskbarClock(){const el=document.getElementById('taskbar-clock');if(el){const now=new Date();el.textContent=now.toLocaleTimeString('en-US',{hour:'numeric',minute:'2-digit'});}}

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
  if(hm)hm.textContent=now.toLocaleTimeString('en-US',{hour:'2-digit',minute:'2-digit',hour12:false}).replace(/^24:/,'00:');
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

// ===== STAT WIDGET HIDE / SHOW =====
function hideStatWidget(id,e){if(e){e.stopPropagation();e.preventDefault();}const el=document.getElementById(id);if(!el)return;el.style.display='none';const h=JSON.parse(PHP_STATE['dash_hidden_stats']||localStorage.getItem('dash_hidden_stats')||'[]');if(!h.includes(id)){h.push(id);_saveState({'dash_hidden_stats':JSON.stringify(h)});}}
// Restore hidden stat widgets on page load — server is authoritative
(function(){const src=PHP_STATE['dash_hidden_stats']||localStorage.getItem('dash_hidden_stats')||'[]';JSON.parse(src).forEach(id=>{const el=document.getElementById(id);if(el)el.style.display='none';});})();

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
      if(el.style.width) entry.w=parseInt(el.style.width);
      pos[el.dataset.stat]=entry;
    });
    localStorage.setItem('hp-stat-pos',JSON.stringify(pos));
    fetch('save_stat_pos.php',{method:'POST',keepalive:true,headers:{'Content-Type':'application/json'},body:JSON.stringify(pos)});
  }
  // Stat widget positions come from PHP (server) — no localStorage override needed.

  // ── Resize handles: inject into every stat-section, drag to resize in edit mode ──
  function initResizeHandles(){
    const noResize=['stat-clock','stat-weather'];
    document.querySelectorAll('.stat-section').forEach(el=>{
      if(noResize.includes(el.id))return;
      if(!el.querySelector('.stat-resize-handle')){
        const h=document.createElement('div');h.className='stat-resize-handle';el.appendChild(h);
      }
    });
  }
  initResizeHandles();

  let _resizing=false;
  document.addEventListener('mousedown',e=>{
    if(!document.body.classList.contains('edit-mode'))return;
    const handle=e.target.closest('.stat-resize-handle');
    if(!handle)return;
    e.preventDefault();e.stopPropagation();
    const el=handle.closest('.stat-section');
    _resizing=true;
    const sx=e.clientX,sw=el.offsetWidth;
    const sc=parseFloat(document.body.style.zoom)||1;
    const onMove=e2=>{
      if(!_resizing)return;
      const w=Math.max(150,sw+(e2.clientX-sx)/sc);
      el.style.width=w+'px';
    };
    const onUp=()=>{
      if(!_resizing)return;_resizing=false;
      saveStatPos();
      document.removeEventListener('mousemove',onMove);
      document.removeEventListener('mouseup',onUp);
    };
    document.addEventListener('mousemove',onMove);
    document.addEventListener('mouseup',onUp);
  },true);

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
const PREBUILT_LINKS = {
  'Search': [
    {icon:'🔍',label:'Google',url:'https://google.com'},
    {icon:'🦆',label:'DuckDuckGo',url:'https://duckduckgo.com'},
    {icon:'🔎',label:'Bing',url:'https://bing.com'},
    {icon:'🌐',label:'Brave Search',url:'https://search.brave.com'},
    {icon:'📰',label:'Kagi',url:'https://kagi.com'},
    {icon:'🗺',label:'Google Maps',url:'https://maps.google.com'},
  ],
  'AI': [
    {icon:'🤖',label:'ChatGPT',url:'https://chatgpt.com'},
    {icon:'💎',label:'Gemini',url:'https://gemini.google.com'},
    {icon:'🧠',label:'Claude',url:'https://claude.ai'},
    {icon:'🌙',label:'Mistral',url:'https://chat.mistral.ai'},
    {icon:'⚡',label:'Grok',url:'https://grok.x.ai'},
    {icon:'🖼',label:'Midjourney',url:'https://midjourney.com'},
    {icon:'🎨',label:'DALL-E',url:'https://openai.com/dall-e-3'},
    {icon:'🦙',label:'Perplexity',url:'https://perplexity.ai'},
  ],
  'Social': [
    {icon:'🐦',label:'X / Twitter',url:'https://x.com'},
    {icon:'📘',label:'Facebook',url:'https://facebook.com'},
    {icon:'📷',label:'Instagram',url:'https://instagram.com'},
    {icon:'💼',label:'LinkedIn',url:'https://linkedin.com'},
    {icon:'👻',label:'Snapchat',url:'https://snapchat.com'},
    {icon:'🎵',label:'TikTok',url:'https://tiktok.com'},
    {icon:'📌',label:'Pinterest',url:'https://pinterest.com'},
    {icon:'🦋',label:'Bluesky',url:'https://bsky.app'},
    {icon:'🐘',label:'Mastodon',url:'https://mastodon.social'},
    {icon:'💬',label:'Discord',url:'https://discord.com'},
    {icon:'📡',label:'Reddit',url:'https://reddit.com'},
    {icon:'📺',label:'YouTube',url:'https://youtube.com'},
  ],
  'Productivity': [
    {icon:'📧',label:'Gmail',url:'https://mail.google.com'},
    {icon:'📅',label:'Google Calendar',url:'https://calendar.google.com'},
    {icon:'📝',label:'Google Docs',url:'https://docs.google.com'},
    {icon:'📊',label:'Google Sheets',url:'https://sheets.google.com'},
    {icon:'💾',label:'Google Drive',url:'https://drive.google.com'},
    {icon:'✅',label:'Notion',url:'https://notion.so'},
    {icon:'📋',label:'Trello',url:'https://trello.com'},
    {icon:'🗂',label:'Airtable',url:'https://airtable.com'},
    {icon:'📐',label:'Figma',url:'https://figma.com'},
    {icon:'🔔',label:'Slack',url:'https://slack.com'},
    {icon:'🟦',label:'Teams',url:'https://teams.microsoft.com'},
    {icon:'📮',label:'Outlook',url:'https://outlook.com'},
  ],
  'Dev': [
    {icon:'🐙',label:'GitHub',url:'https://github.com'},
    {icon:'🦊',label:'GitLab',url:'https://gitlab.com'},
    {icon:'🪣',label:'Bitbucket',url:'https://bitbucket.org'},
    {icon:'🌊',label:'DigitalOcean',url:'https://digitalocean.com'},
    {icon:'🔥',label:'Firebase',url:'https://firebase.google.com'},
    {icon:'▲',label:'Vercel',url:'https://vercel.com'},
    {icon:'🚀',label:'Netlify',url:'https://netlify.com'},
    {icon:'📦',label:'npm',url:'https://npmjs.com'},
    {icon:'🐳',label:'Docker Hub',url:'https://hub.docker.com'},
    {icon:'🛠',label:'Stack Overflow',url:'https://stackoverflow.com'},
    {icon:'☁️',label:'AWS Console',url:'https://console.aws.amazon.com'},
    {icon:'🌩',label:'Cloudflare',url:'https://cloudflare.com'},
  ],
  'Media': [
    {icon:'🎬',label:'Netflix',url:'https://netflix.com'},
    {icon:'🎥',label:'Disney+',url:'https://disneyplus.com'},
    {icon:'🎞',label:'Plex',url:'https://app.plex.tv'},
    {icon:'🟣',label:'Twitch',url:'https://twitch.tv'},
    {icon:'🎵',label:'Spotify',url:'https://open.spotify.com'},
    {icon:'🍎',label:'Apple Music',url:'https://music.apple.com'},
    {icon:'🎶',label:'YouTube Music',url:'https://music.youtube.com'},
    {icon:'📻',label:'SoundCloud',url:'https://soundcloud.com'},
  ],
  'Shopping': [
    {icon:'📦',label:'Amazon',url:'https://amazon.com'},
    {icon:'🛒',label:'eBay',url:'https://ebay.com'},
    {icon:'🛍',label:'Etsy',url:'https://etsy.com'},
    {icon:'🏷',label:'AliExpress',url:'https://aliexpress.com'},
    {icon:'💳',label:'PayPal',url:'https://paypal.com'},
  ],
  'News': [
    {icon:'🗞',label:'BBC News',url:'https://bbc.com/news'},
    {icon:'📰',label:'Reuters',url:'https://reuters.com'},
    {icon:'🌐',label:'AP News',url:'https://apnews.com'},
    {icon:'📡',label:'Hacker News',url:'https://news.ycombinator.com'},
    {icon:'🔴',label:'CNN',url:'https://cnn.com'},
  ],
};

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
      secEl.innerHTML=`<div class="section-header"><span class="section-folder-icon">${_selectedIcon}</span><div class="section-title">${_selectedIcon} ${section||'New'}</div><span class="section-count">0 items</span><button class="section-collapse-btn" onclick="toggleCollapse(event,this)" title="Collapse / Expand">▼</button><div class="section-actions"><span class="section-lock-indicator" title="Layout locked — click ✏️ Edit to rearrange">🔒</span><button class="section-view-btn" onclick="toggleSectionView(event,this)" title="Toggle grid/list view">⊞</button><button class="section-btn" onclick="addLink(this)">+ Add</button><button class="section-btn section-del-btn" onclick="deleteSection(event,this)" title="Delete this column">🗑</button></div></div><div class="section-body"></div>`;
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
    return {
      id:s.dataset.id||'sec-'+Date.now(),
      title,icon,
      pos_x:parseInt(s.style.left)||0,
      pos_y:parseInt(s.style.top)||0,
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
      if(j&&j.ok) _setSaveIndicator('✓ Saved '+t,'#6ee7b7');
      else _setSaveIndicator('⚠ Save failed','#fca5a5');
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

// saveAndGo: save synchronously then navigate
function saveAndGo(url){ saveLinksToServer(); window.location=url; }

// Intercept clicks to options.php
document.addEventListener('click',e=>{
  const a=e.target.closest('a[href]');
  if(a&&a.getAttribute('href')&&a.getAttribute('href').includes('options.php')) saveLinksToServer();
});

// Auto-save every 30 seconds
setInterval(saveLinksToServer, 30000);

// pagehide: local snapshot (synchronous, always succeeds) + keepalive fetch
window.addEventListener('pagehide',()=>{
  _saveLayoutLocal();
  const fd=new FormData();
  fd.append('action','save_links');
  fd.append('links_json', JSON.stringify(_buildLinksPayload()));
  // keepalive fetch survives page unload; sendBeacon does not guarantee delivery
  navigator.sendBeacon&&navigator.sendBeacon('save_links.php',
    new Blob(['action=save_links&links_json='+encodeURIComponent(JSON.stringify(_buildLinksPayload()))],
    {type:'application/x-www-form-urlencoded'}));
  fetch('save_links.php',{method:'POST',body:fd,keepalive:true}).catch(()=>{});
});

// ===== PAGE FOLDER WIDGETS =====
let _pageFolders = <?= json_encode($page_folders) ?>;

async function addPageFolder() {
  const label = prompt('Folder name:', 'My Files');
  if (!label) return;
  const id = 'pf-' + Date.now();
  const sv = document.getElementById('services');
  const svRect = sv.getBoundingClientRect();
  // Place near center of visible area
  const x = Math.max(10, (window.innerWidth/2 - svRect.left - 80));
  const y = Math.max(10, (window.scrollY + 100 - svRect.top));
  const pf = {id, label, pos_x: Math.round(x), pos_y: Math.round(y)};
  _pageFolders.push(pf);
  savePageFolders();
  // Auto-create a matching doc folder on the server so each widget has its own storage
  try {
    const fd = new FormData();
    fd.append('action', 'add_folder');
    fd.append('label', label);
    fd.append('icon', '📁');
    await fetch('download.php', {method:'POST', body:fd});
  } catch(e) {}
  // Render it
  const el = document.createElement('div');
  el.className = 'page-folder';
  el.dataset.pfId = id;
  el.style.left = pf.pos_x + 'px';
  el.style.top  = pf.pos_y + 'px';
  el.innerHTML = `<div class="pf-icon">📁</div><div class="pf-label">${label}</div><button class="pf-add-btn" onclick="event.stopPropagation();removePageFolder('${id}')" title="Remove">✕</button>`;
  el.addEventListener('dblclick', () => openPageFolder(label));
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

async function openPageFolder(label) {
  document.getElementById('doc-panel').classList.add('open');
  // Clear current selection so a failed match doesn't stick to the previous folder
  _docCurrentFolder = null;
  await loadDocFolders();
  // Try to auto-select the folder whose label matches the page folder widget name
  if (label && _docFolders.length) {
    const norm = s => s.toLowerCase().replace(/[^a-z0-9]/g,'');
    const match = _docFolders.find(f => norm(f.label) === norm(label));
    if (match) { _docCurrentFolder = _folderDirKey(match); renderDocSidebar(); renderDocFiles(_folderDirKey(match)); }
  }
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

async function openDocPanel() {
  document.getElementById('doc-panel').classList.add('open');
  await loadDocFolders();
}
function closeDocPanel() {
  document.getElementById('doc-panel').classList.remove('open');
}
document.addEventListener('click', e => { if(e.target.id==='doc-panel') closeDocPanel(); });

async function loadDocFolders() {
  try {
    const r = await fetch('download.php?action=list');
    const d = await r.json();
    if (!d.ok) return;
    _docFolders = d.folders || [];
    renderDocSidebar();
    if (_docFolders.length) {
      const cur = _docCurrentFolder || _folderDirKey(_docFolders[0]);
      _docCurrentFolder = cur;
      renderDocFiles(cur);
    }
  } catch(e) { console.error(e); }
}

function _folderDirKey(f) { return f.dir_key || f.path; }

function renderDocSidebar() {
  const el = document.getElementById('doc-folder-list');
  el.innerHTML = _docFolders.map(f => {
    const dk = _folderDirKey(f);
    return `<button class="doc-folder-btn${_docCurrentFolder===dk?' active':''}" onclick="selectDocFolder('${dk}')">
      <span>${f.icon||'📁'}</span><span style="flex:1;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">${f.label}</span>
      <span class="dfcount">${f.files.length}</span>
    </button>`;
  }).join('');
}

async function selectDocFolder(dirKey) {
  _docCurrentFolder = dirKey;
  try {
    const r = await fetch('download.php?action=list');
    const d = await r.json();
    if (d.ok) _docFolders = d.folders || [];
  } catch(e) {}
  renderDocSidebar();
  renderDocFiles(dirKey);
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
  const folder = _docFolders.find(f => _folderDirKey(f) === _docCurrentFolder);
  if (!folder || !folder.files.length) return;
  if (!confirm(`Delete all ${folder.files.length} files in "${folder.label}"?`)) return;
  for (const f of folder.files) {
    const fd = new FormData(); fd.append('action','delete'); fd.append('folder',f.folder); fd.append('file',f.name);
    await fetch('download.php', {method:'POST',body:fd}).catch(()=>{});
  }
  await loadDocFolders();
}

function renderDocFiles(dirKey) {
  const folder = _docFolders.find(f => _folderDirKey(f) === dirKey);
  if (!folder) return;
  document.getElementById('doc-folder-name').textContent = folder.icon + ' ' + folder.label;
  const el = document.getElementById('doc-files');
  if (!folder.files.length) {
    el.innerHTML = '<div id="doc-drop-zone" onclick="document.getElementById(\'doc-file-input\').click()">Drop files here or click to upload</div>';
    setupDocDrop();
    _autoDocView();
    return;
  }
  el.innerHTML = folder.files.map(f =>
    `<div class="doc-file-row">
      <span class="doc-file-icon">${f.icon}</span>
      <div class="doc-file-info">
        <div class="doc-file-name" title="${f.name}">${f.name}</div>
        <div class="doc-file-size">${f.size_h} · ${new Date(f.mtime*1000).toLocaleDateString()}</div>
      </div>
      <a class="doc-file-dl" href="${f.url}" download="${f.name}" title="Download">⬇</a>
      <button class="doc-file-del" onclick="deleteDocFile('${f.folder}','${f.name}')" title="Delete file">🗑</button>
    </div>`
  ).join('') + '<div id="doc-drop-zone" style="margin-top:8px;" onclick="document.getElementById(\'doc-file-input\').click()">+ Upload more files</div>';
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
    await loadDocFolders();
  } catch(e) { alert('Upload error: ' + e.message); }
}

async function deleteDocFile(folder, name) {
  if (!confirm('Delete ' + name + '?')) return;
  await fetch('download.php?action=delete&folder='+encodeURIComponent(folder)+'&file='+encodeURIComponent(name));
  await loadDocFolders();
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
  const valid=['win98','win9x','win2k','winxp','winxp2','winphone','aqua','ios26','jellybean','jellybean2','palmos','palmtreo','pocketpc','macos','macos9','mac9','macosx','osxtiger','ubuntu','c64','os2','webos','professional','girly','spring','summer','autumn','winter','thanksgiving','july4','christmas','custom'];
  // Server state is authoritative — fall back to localStorage only for first-time visitors
  let t=PHP_STATE['hp-theme']||localStorage.getItem('hp-theme')||'win98';
  let s=parseInt(PHP_STATE['hp-size']||localStorage.getItem('hp-size'))||100;
  if(!valid.includes(t))t='win98';if(s<60||s>200)s=100;
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
</script>

<!-- ===== SCREENSAVER ANIMATIONS ===== -->
<script>
// (Flying Toasters removed — use Custom background with iframe URL if desired)

// 3D Pipes (Win2K)
(function(){const cv=document.getElementById('canvas-pipes'),ctx=cv.getContext('2d');let pipes=[],animId=null;const CELL=40,COLORS=['#ff2020','#20ff20','#2060ff','#ff8000','#ff20ff','#20ffff','#ffff20'],DIRS=[[1,0],[0,1],[-1,0],[0,-1]];function resize(){cv.width=window.innerWidth;cv.height=window.innerHeight;}function newPipe(){const c=COLORS[Math.floor(Math.random()*COLORS.length)];return{x:Math.floor(Math.random()*Math.floor(cv.width/CELL))*CELL+CELL/2,y:Math.floor(Math.random()*Math.floor(cv.height/CELL))*CELL+CELL/2,dir:Math.floor(Math.random()*4),color:c,pipeW:8+Math.floor(Math.random()*3)*4,progress:0,alive:true,age:0,maxAge:80+Math.floor(Math.random()*120)};}function draw3D(x1,y1,x2,y2,c,w,d){ctx.strokeStyle=c;ctx.lineWidth=w;ctx.lineCap='square';ctx.beginPath();ctx.moveTo(x1,y1);ctx.lineTo(x2,y2);ctx.stroke();ctx.strokeStyle='rgba(255,255,255,.35)';ctx.lineWidth=w*.3;ctx.beginPath();d===0||d===2?ctx.moveTo(x1,y1-w*.25):ctx.moveTo(x1-w*.25,y1);d===0||d===2?ctx.lineTo(x2,y2-w*.25):ctx.lineTo(x2-w*.25,y2);ctx.stroke();}function drawJ(x,y,c,w){const r=w*.75,g=ctx.createRadialGradient(x-r*.3,y-r*.3,r*.1,x,y,r);g.addColorStop(0,'rgba(255,255,255,.7)');g.addColorStop(.4,c);g.addColorStop(1,'rgba(0,0,0,.6)');ctx.fillStyle=g;ctx.beginPath();ctx.arc(x,y,r,0,Math.PI*2);ctx.fill();}function animate(){if(cv.style.display==='none'){animId=null;return;}if(pipes.length<8&&Math.random()<.04)pipes.push(newPipe());pipes.forEach(p=>{if(!p.alive)return;p.age++;if(p.age>p.maxAge){p.alive=false;return;}const d=DIRS[p.dir],nx=p.x+d[0]*2,ny=p.y+d[1]*2;draw3D(p.x,p.y,nx,ny,p.color,p.pipeW,p.dir);p.x=nx;p.y=ny;p.progress+=2;if(p.progress>=CELL){p.progress=0;p.x=Math.round(p.x/CELL)*CELL;p.y=Math.round(p.y/CELL)*CELL;drawJ(p.x,p.y,p.color,p.pipeW);if(Math.random()<.4){const t=p.dir%2===0?[1,3]:[0,2];p.dir=t[Math.floor(Math.random()*2)];}const nd=DIRS[p.dir],fx=p.x+nd[0]*CELL,fy=p.y+nd[1]*CELL;if(fx<0||fx>cv.width||fy<0||fy>cv.height)p.dir=(p.dir+2)%4;}});pipes=pipes.filter(p=>p.alive);animId=requestAnimationFrame(animate);}window.addEventListener('resize',resize);resize();window._startPipes=()=>{ctx.fillStyle='#000';ctx.fillRect(0,0,cv.width,cv.height);pipes=[];if(!animId)animate();};window._stopPipes=()=>{if(animId){cancelAnimationFrame(animId);animId=null;}ctx.clearRect(0,0,cv.width,cv.height);};})();

// Nexus Circuit-Board (JellyBean) — glowing neon H/V lines + nodes
(function(){const cv=document.getElementById('canvas-nexus'),ctx=cv.getContext('2d');let segs=[],dots=[],animId=null,tick=0;const HUE=[165,195,210],SPEED=1.8,SEG=40;function resize(){cv.width=window.innerWidth;cv.height=window.innerHeight;build();}function rnd(a,b){return a+Math.random()*(b-a);}function build(){segs=[];dots=[];const cols=Math.ceil(cv.width/SEG)+2,rows=Math.ceil(cv.height/SEG)+2;for(let r=0;r<rows;r++){for(let c=0;c<cols;c++){if(Math.random()<.18){const horiz=Math.random()<.5,len=(2+Math.floor(Math.random()*4))*SEG,hue=HUE[Math.floor(Math.random()*HUE.length)];segs.push({x:c*SEG,y:r*SEG,horiz,len,hue,prog:0,speed:SPEED*.5+Math.random()*SPEED,alpha:0,life:0,maxLife:120+Math.random()*180});}}}for(let i=0;i<60;i++)dots.push({x:rnd(0,cv.width),y:rnd(0,cv.height),hue:HUE[Math.floor(Math.random()*HUE.length)],r:2,pulse:Math.random()*Math.PI*2});}function animate(){if(cv.style.display==='none'){animId=null;return;}tick++;ctx.fillStyle='rgba(0,8,20,.22)';ctx.fillRect(0,0,cv.width,cv.height);segs.forEach(s=>{s.life++;s.alpha=s.life<20?s.life/20:s.life>s.maxLife-20?(s.maxLife-s.life)/20:1;if(s.life>s.maxLife){s.life=0;s.x=Math.floor(Math.random()*(cv.width/SEG+2))*SEG;s.y=Math.floor(Math.random()*(cv.height/SEG+2))*SEG;s.horiz=Math.random()<.5;s.len=(2+Math.floor(Math.random()*4))*SEG;s.hue=HUE[Math.floor(Math.random()*HUE.length)];s.prog=0;return;}s.prog=Math.min(s.prog+s.speed,s.len);const x2=s.horiz?s.x+s.prog:s.x,y2=s.horiz?s.y:s.y+s.prog,a=s.alpha;ctx.shadowBlur=8;ctx.shadowColor=`hsla(${s.hue},100%,60%,${a})`;ctx.strokeStyle=`hsla(${s.hue},100%,60%,${a*.7})`;ctx.lineWidth=1;ctx.beginPath();ctx.moveTo(s.x,s.y);ctx.lineTo(x2,y2);ctx.stroke();ctx.strokeStyle=`hsla(${s.hue},100%,85%,${a*.5})`;ctx.lineWidth=.5;ctx.beginPath();ctx.moveTo(s.x,s.y);ctx.lineTo(x2,y2);ctx.stroke();if(s.prog>=s.len){const g=ctx.createRadialGradient(x2,y2,0,x2,y2,5);g.addColorStop(0,`hsla(${s.hue},100%,90%,${a})`);g.addColorStop(1,`hsla(${s.hue},100%,60%,0)`);ctx.fillStyle=g;ctx.beginPath();ctx.arc(x2,y2,5,0,Math.PI*2);ctx.fill();}});ctx.shadowBlur=0;dots.forEach(d=>{d.pulse+=.04;const pr=d.r*(1+.4*Math.sin(d.pulse)),g=ctx.createRadialGradient(d.x,d.y,0,d.x,d.y,pr*3);g.addColorStop(0,`hsla(${d.hue},100%,90%,.9)`);g.addColorStop(1,`hsla(${d.hue},100%,60%,0)`);ctx.fillStyle=g;ctx.beginPath();ctx.arc(d.x,d.y,pr*2,0,Math.PI*2);ctx.fill();});animId=requestAnimationFrame(animate);}window.addEventListener('resize',resize);resize();window._startNexus=()=>{ctx.fillStyle='#00081a';ctx.fillRect(0,0,cv.width,cv.height);build();if(!animId)animate();};window._stopNexus=()=>{if(animId){cancelAnimationFrame(animId);animId=null;}ctx.clearRect(0,0,cv.width,cv.height);};})();

// Nexus2 (JellyBean2)
(function(){const cv=document.getElementById('canvas-nexus2'),ctx=cv.getContext('2d');let nodes=[],animId=null;function resize(){cv.width=window.innerWidth;cv.height=window.innerHeight;spawnNodes();}function spawnNodes(){nodes=[];const cnt=Math.floor(cv.width*cv.height/8000);for(let i=0;i<cnt;i++)nodes.push({x:Math.random()*cv.width,y:Math.random()*cv.height,vx:(Math.random()-.5)*.5,vy:(Math.random()-.5)*.5,r:1+Math.random()*3,hue:200+Math.random()*40});}function animate(){if(cv.style.display==='none'){animId=null;return;}ctx.fillStyle='rgba(5,10,30,.12)';ctx.fillRect(0,0,cv.width,cv.height);nodes.forEach(n=>{n.x+=n.vx;n.y+=n.vy;if(n.x<0||n.x>cv.width)n.vx*=-1;if(n.y<0||n.y>cv.height)n.vy*=-1;});for(let i=0;i<nodes.length;i++)for(let j=i+1;j<nodes.length;j++){const dx=nodes[i].x-nodes[j].x,dy=nodes[i].y-nodes[j].y,d=Math.sqrt(dx*dx+dy*dy);if(d<200){ctx.strokeStyle=`rgba(51,170,255,${(1-d/200)*.4})`;ctx.lineWidth=.5;ctx.beginPath();ctx.moveTo(nodes[i].x,nodes[i].y);ctx.lineTo(nodes[j].x,nodes[j].y);ctx.stroke();}}nodes.forEach(n=>{const g=ctx.createRadialGradient(n.x,n.y,0,n.x,n.y,n.r*3);g.addColorStop(0,`hsla(${n.hue},100%,80%,.9)`);g.addColorStop(1,`hsla(${n.hue},100%,60%,0)`);ctx.fillStyle=g;ctx.beginPath();ctx.arc(n.x,n.y,n.r*2,0,Math.PI*2);ctx.fill();});animId=requestAnimationFrame(animate);}window.addEventListener('resize',resize);resize();window._startNexus2=()=>{ctx.fillStyle='#050a1e';ctx.fillRect(0,0,cv.width,cv.height);spawnNodes();if(!animId)animate();};window._stopNexus2=()=>{if(animId){cancelAnimationFrame(animId);animId=null;}ctx.clearRect(0,0,cv.width,cv.height);};})();

// OSX Aqua Ribbons
(function(){const cv=document.getElementById('canvas-aqua'),ctx=cv.getContext('2d');let t=0,animId=null;function resize(){cv.width=window.innerWidth;cv.height=window.innerHeight;}function animate(){if(cv.style.display==='none'){animId=null;return;}t+=.005;ctx.fillStyle='rgba(20,70,140,.08)';ctx.fillRect(0,0,cv.width,cv.height);for(let i=0;i<4;i++){ctx.beginPath();const amp=cv.height*.12,freq=.003+i*.001,spd=t*(.4+i*.15),off=i*cv.height*.25;for(let x=0;x<=cv.width;x+=4){const y=off+amp*Math.sin(x*freq+spd)+amp*.5*Math.sin(x*freq*1.5-spd*.8);x===0?ctx.moveTo(x,y):ctx.lineTo(x,y);}ctx.lineTo(cv.width,cv.height);ctx.lineTo(0,cv.height);ctx.closePath();const g=ctx.createLinearGradient(0,0,cv.width,0);const h=[210,200,195,205][i];g.addColorStop(0,`hsla(${h},80%,70%,.18)`);g.addColorStop(.5,`hsla(${h},90%,80%,.25)`);g.addColorStop(1,`hsla(${h},80%,70%,.18)`);ctx.fillStyle=g;ctx.fill();}animId=requestAnimationFrame(animate);}window.addEventListener('resize',resize);resize();window._startAqua=()=>{ctx.fillStyle='#1b6ca8';ctx.fillRect(0,0,cv.width,cv.height);if(!animId)animate();};window._stopAqua=()=>{if(animId){cancelAnimationFrame(animId);animId=null;}ctx.clearRect(0,0,cv.width,cv.height);};})();

// iOS 26 — soft lavender orbs, NO screen blend (avoids white blow-out)
(function(){const cv=document.getElementById('canvas-ios26'),ctx=cv.getContext('2d');let t=0,animId=null;function resize(){cv.width=window.innerWidth;cv.height=window.innerHeight;}function blob(cx,cy,r,innerCol,outerCol,ph){ctx.beginPath();for(let i=0;i<=18;i++){const a=(i/18)*Math.PI*2,w=r*(.82+.18*Math.sin(a*3+ph+t*.5));ctx.lineTo(cx+Math.cos(a)*w,cy+Math.sin(a)*w);}ctx.closePath();const g=ctx.createRadialGradient(cx-r*.25,cy-r*.25,r*.05,cx,cy,r);g.addColorStop(0,innerCol);g.addColorStop(1,outerCol);ctx.fillStyle=g;ctx.fill();}function animate(){if(cv.style.display==='none'){animId=null;return;}t+=.005;const W=cv.width,H=cv.height;// Fill dark bg each frame
ctx.fillStyle='#120c28';ctx.fillRect(0,0,W,H);// Draw blobs with source-over only — no screen composite
ctx.globalCompositeOperation='source-over';blob(W*.55+Math.sin(t*.35)*W*.12,H*.25+Math.cos(t*.28)*H*.1,H*.38,'rgba(140,80,200,.38)','rgba(80,40,160,0)',0);blob(W*.4+Math.cos(t*.3)*W*.1,H*.6+Math.sin(t*.25)*H*.08,H*.32,'rgba(60,90,200,.32)','rgba(40,60,180,0)',2.1);blob(W*.7+Math.sin(t*.38)*W*.09,H*.75+Math.cos(t*.32)*H*.07,H*.35,'rgba(60,140,180,.3)','rgba(30,90,150,0)',4.2);blob(W*.3+Math.cos(t*.25)*W*.08,H*.35+Math.sin(t*.3)*H*.06,H*.28,'rgba(160,80,180,.28)','rgba(100,40,140,0)',1);animId=requestAnimationFrame(animate);}window.addEventListener('resize',resize);resize();window._startIos26=()=>{ctx.fillStyle='#120c28';ctx.fillRect(0,0,cv.width,cv.height);if(!animId)animate();};window._stopIos26=()=>{if(animId){cancelAnimationFrame(animId);animId=null;}ctx.clearRect(0,0,cv.width,cv.height);};})();

// XP Aquarium
(function(){const cv=document.getElementById('canvas-aquarium'),ctx=cv.getContext('2d');let fish=[],animId=null,t=0;function resize(){cv.width=window.innerWidth;cv.height=window.innerHeight;spawnFish();}function spawnFish(){fish=[];for(let i=0;i<14;i++){const dir=Math.random()>.5?1:-1;fish.push({x:dir>0?-80:cv.width+80,y:50+Math.random()*(cv.height-100),sx:(.5+Math.random()*.8)*dir,wy:Math.random()*Math.PI*2,hue:180+Math.floor(Math.random()*6)*30,sz:.6+Math.random()*.8,tail:0});}}function drawFish(f){ctx.save();ctx.translate(f.x,f.y);if(f.sx<0)ctx.scale(-1,1);const s=f.sz*40;ctx.fillStyle=`hsl(${f.hue},80%,55%)`;ctx.beginPath();ctx.ellipse(0,0,s,s*.4,0,0,Math.PI*2);ctx.fill();ctx.beginPath();ctx.moveTo(-s,0);ctx.lineTo(-s-s*.5,s*.3+Math.sin(f.tail)*.1*s);ctx.lineTo(-s-s*.5,-(s*.3+Math.sin(f.tail)*.1*s));ctx.closePath();ctx.fillStyle=`hsl(${f.hue},60%,40%)`;ctx.fill();ctx.restore();}function animate(){if(cv.style.display==='none'){animId=null;return;}t+=.016;ctx.fillStyle='rgba(0,30,80,.12)';ctx.fillRect(0,0,cv.width,cv.height);fish.forEach(f=>{f.x+=f.sx;f.y+=Math.sin(f.wy+t)*.3;f.tail+=.18;if(f.x>cv.width+120)f.x=-80;if(f.x<-120)f.x=cv.width+80;drawFish(f);});animId=requestAnimationFrame(animate);}window.addEventListener('resize',resize);resize();window._startAquarium=()=>{ctx.fillStyle='#001a40';ctx.fillRect(0,0,cv.width,cv.height);if(!animId)animate();};window._stopAquarium=()=>{if(animId){cancelAnimationFrame(animId);animId=null;}ctx.clearRect(0,0,cv.width,cv.height);};})();

// Palm OS LCD drop matrix
(function(){const cv=document.getElementById('canvas-palmos'),ctx=cv.getContext('2d');let animId=null,t=0,drops=[],_th='palmos';function resize(){cv.width=window.innerWidth;cv.height=window.innerHeight;initDrops();}function initDrops(){drops=[];const cols=Math.floor(cv.width/8);for(let i=0;i<cols;i++)drops.push({x:i*8,y:Math.random()*cv.height,spd:1+Math.random()*2,len:4+Math.floor(Math.random()*8)});}function animate(){if(cv.style.display==='none'){animId=null;return;}t+=.04;const isTreo=_th==='palmtreo';ctx.fillStyle=isTreo?'rgba(50,30,10,.18)':'rgba(80,90,60,.15)';ctx.fillRect(0,0,cv.width,cv.height);drops.forEach(d=>{for(let i=0;i<d.len;i++){const yy=d.y-i*8,a=(d.len-i)/d.len*.6;ctx.fillStyle=i===0?(isTreo?'rgba(220,160,80,1)':'rgba(100,140,60,1)'):(isTreo?`rgba(180,120,40,${a})`:`rgba(60,90,40,${a})`);ctx.fillRect(d.x,((yy%cv.height)+cv.height)%cv.height,5,5);}d.y+=d.spd;if(d.y>cv.height+d.len*8){d.y=-10;d.spd=1+Math.random()*2;d.len=4+Math.floor(Math.random()*8);}});for(let y=0;y<cv.height;y+=2){ctx.fillStyle='rgba(0,0,0,.04)';ctx.fillRect(0,y,cv.width,1);}animId=requestAnimationFrame(animate);}window.addEventListener('resize',resize);resize();window._startPalmos=(th)=>{_th=th||'palmos';ctx.fillStyle=th==='palmtreo'?'#8a6040':'#7a8a60';ctx.fillRect(0,0,cv.width,cv.height);initDrops();if(!animId)animate();};window._stopPalmos=()=>{if(animId){cancelAnimationFrame(animId);animId=null;}ctx.clearRect(0,0,cv.width,cv.height);};})();

// Pocket PC 6 bubbles
(function(){const cv=document.getElementById('canvas-pocketpc'),ctx=cv.getContext('2d');let bubbles=[],animId=null,t=0;function resize(){cv.width=window.innerWidth;cv.height=window.innerHeight;spawnBubbles();}function spawnBubbles(){bubbles=[];for(let i=0;i<22;i++)bubbles.push({x:Math.random()*cv.width,y:Math.random()*cv.height,r:20+Math.random()*60,vx:(Math.random()-.5)*.3,vy:-.3-Math.random()*.5,hue:210+Math.random()*30,alpha:.05+Math.random()*.15});}function animate(){if(cv.style.display==='none'){animId=null;return;}t+=.008;ctx.fillStyle='rgba(10,25,70,.08)';ctx.fillRect(0,0,cv.width,cv.height);bubbles.forEach(b=>{b.x+=b.vx;b.y+=b.vy;if(b.y<-b.r){b.y=cv.height+b.r;b.x=Math.random()*cv.width;}if(b.x<-b.r)b.x=cv.width+b.r;if(b.x>cv.width+b.r)b.x=-b.r;const g=ctx.createRadialGradient(b.x-b.r*.3,b.y-b.r*.3,b.r*.05,b.x,b.y,b.r);g.addColorStop(0,`hsla(${b.hue},90%,90%,${b.alpha*2})`);g.addColorStop(.6,`hsla(${b.hue},80%,70%,${b.alpha})`);g.addColorStop(1,`hsla(${b.hue},80%,60%,0)`);ctx.fillStyle=g;ctx.beginPath();ctx.arc(b.x,b.y,b.r,0,Math.PI*2);ctx.fill();ctx.strokeStyle=`hsla(${b.hue},90%,90%,${b.alpha*.8})`;ctx.lineWidth=1;ctx.stroke();});const bg=ctx.createLinearGradient(0,Math.sin(t)*.5*cv.height*.8,cv.width,0);bg.addColorStop(0,'rgba(80,150,255,0)');bg.addColorStop(.5,'rgba(80,150,255,.07)');bg.addColorStop(1,'rgba(80,150,255,0)');ctx.fillStyle=bg;ctx.fillRect(0,0,cv.width,cv.height);animId=requestAnimationFrame(animate);}window.addEventListener('resize',resize);resize();window._startPocketpc=()=>{ctx.fillStyle='#0a1a46';ctx.fillRect(0,0,cv.width,cv.height);if(!animId)animate();};window._stopPocketpc=()=>{if(animId){cancelAnimationFrame(animId);animId=null;}ctx.clearRect(0,0,cv.width,cv.height);};})();

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

// Ubuntu — purple energy streaks
(function(){const cv=document.getElementById('canvas-ubuntu'),ctx=cv.getContext('2d');let t=0,animId=null,streaks=[];function resize(){cv.width=window.innerWidth;cv.height=window.innerHeight;spawnStreaks();}function spawnStreaks(){streaks=[];for(let i=0;i<15;i++)streaks.push({x:Math.random()*cv.width,y:Math.random()*cv.height,vx:(Math.random()-.5)*.4,vy:(Math.random()-.5)*.4,len:60+Math.random()*120,hue:280+Math.random()*60,alpha:.3+Math.random()*.4,w:1+Math.random()*2});}function animate(){if(cv.style.display==='none'){animId=null;return;}t+=.012;ctx.fillStyle='rgba(44,4,38,.12)';ctx.fillRect(0,0,cv.width,cv.height);streaks.forEach(s=>{s.x+=s.vx;s.y+=s.vy;if(s.x<-200)s.x=cv.width+200;if(s.x>cv.width+200)s.x=-200;if(s.y<-200)s.y=cv.height+200;if(s.y>cv.height+200)s.y=-200;const g=ctx.createLinearGradient(s.x,s.y,s.x+Math.cos(t)*s.len,s.y+Math.sin(t)*s.len);g.addColorStop(0,`hsla(${s.hue},90%,70%,${s.alpha})`);g.addColorStop(1,`hsla(${s.hue},90%,70%,0)`);ctx.strokeStyle=g;ctx.lineWidth=s.w;ctx.beginPath();ctx.moveTo(s.x,s.y);ctx.lineTo(s.x+Math.cos(t+s.x*.01)*s.len,s.y+Math.sin(t+s.y*.01)*s.len);ctx.stroke();});// Orange ring
const cx=cv.width*.5,cy=cv.height*.5,r=Math.min(cv.width,cv.height)*.35;ctx.strokeStyle=`rgba(233,84,32,${.08+.04*Math.sin(t*2)})`;ctx.lineWidth=40;ctx.beginPath();ctx.arc(cx,cy,r,0,Math.PI*2);ctx.stroke();animId=requestAnimationFrame(animate);}window.addEventListener('resize',resize);resize();window._startUbuntu=()=>{ctx.fillStyle='#2c001e';ctx.fillRect(0,0,cv.width,cv.height);if(!animId)animate();};window._stopUbuntu=()=>{if(animId){cancelAnimationFrame(animId);animId=null;}ctx.clearRect(0,0,cv.width,cv.height);};})();

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
function _profileLoad(name){
  if(!confirm('Load profile "'+name+'"?\nThis will apply the saved theme, wallpaper, and columns. The page will reload.'))return;
  fetch('save_layout.php',{
    method:'POST',headers:{'Content-Type':'application/json'},
    body:JSON.stringify({action:'load',name})
  }).then(r=>r.json()).then(j=>{
    if(!j.ok){alert('Error: '+(j.error||'?'));return;}
    // Remember last loaded profile on this machine
    localStorage.setItem('dash-last-profile',name);
    // Apply theme + wallpaper via localStorage before reload
    if(j.theme){
      localStorage.setItem('hp-theme',j.theme);
      // Save theme server-side too so it sticks on reload
      fetch('save_state.php',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({key:'theme',value:j.theme})});
    }
    if(j.wallpaper_variant&&j.theme){
      localStorage.setItem('variant-'+j.theme,j.wallpaper_variant);
    }
    window.location.reload();
  }).catch(e=>alert('Network error: '+e.message));
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
      _refreshProfilesList();
    }else alert('Error: '+(j.error||'?'));
  }).catch(()=>{btn.disabled=false;});
}
function _profileSave(name){
  if(!name)return;
  let links;
  try{links=_buildLinksPayload();}catch(err){alert('Could not read columns: '+err.message);return;}
  const payload={
    action:'save',name,links,
    theme:_currentBaseTheme,
    wallpaper_variant:_currentVariant
  };
  fetch('save_layout.php',{
    method:'POST',headers:{'Content-Type':'application/json'},
    body:JSON.stringify(payload)
  }).then(r=>r.json()).then(j=>{
    if(j.ok){_refreshProfilesList();}
    else alert('Error saving profile: '+(j.error||'unknown'));
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
// ===== PALM webOS — dark glowing orbs =====
(function(){const cv=document.getElementById('canvas-webos'),ctx=cv.getContext('2d');let t=0,animId=null;function resize(){cv.width=window.innerWidth;cv.height=window.innerHeight;}function orb(cx,cy,r,h,ph){ctx.beginPath();for(let i=0;i<=16;i++){const a=(i/16)*Math.PI*2,w=r*(.8+.2*Math.sin(a*2+ph+t*.4));ctx.lineTo(cx+Math.cos(a)*w,cy+Math.sin(a)*w);}ctx.closePath();const g=ctx.createRadialGradient(cx,cy,0,cx,cy,r);g.addColorStop(0,`hsla(${h},70%,40%,.35)`);g.addColorStop(.5,`hsla(${h},80%,30%,.2)`);g.addColorStop(1,`hsla(${h},80%,20%,0)`);ctx.fillStyle=g;ctx.fill();}function animate(){if(cv.style.display==='none'){animId=null;return;}t+=.004;const W=cv.width,H=cv.height;ctx.fillStyle='#0d0d1a';ctx.fillRect(0,0,W,H);ctx.globalCompositeOperation='source-over';orb(W*.3+Math.sin(t*.3)*W*.1,H*.4+Math.cos(t*.25)*H*.12,H*.45,240,0);orb(W*.7+Math.cos(t*.28)*W*.1,H*.6+Math.sin(t*.22)*H*.1,H*.38,270,2);orb(W*.5+Math.sin(t*.35)*W*.08,H*.25+Math.cos(t*.3)*H*.08,H*.32,220,4);orb(W*.8+Math.cos(t*.32)*W*.07,H*.8+Math.sin(t*.27)*H*.07,H*.28,250,1);animId=requestAnimationFrame(animate);}window.addEventListener('resize',resize);resize();window._startWebos=()=>{ctx.fillStyle='#0d0d1a';ctx.fillRect(0,0,cv.width,cv.height);if(!animId)animate();};window._stopWebos=()=>{if(animId){cancelAnimationFrame(animId);animId=null;}ctx.clearRect(0,0,cv.width,cv.height);};})();
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
      <div style="font-size:10px;opacity:.4;margin-top:6px;">Captures: columns · cards · current theme · wallpaper</div>
    </div>
    <!-- Profile list -->
    <div id="profiles-list" style="overflow-y:auto;flex:1;padding:10px 18px 16px;display:flex;flex-direction:column;gap:8px;"></div>
    <!-- Footer -->
    <div style="padding:10px 18px;border-top:1px solid rgba(255,255,255,.08);font-size:10px;opacity:.35;text-align:center;">
      Profiles are stored on the server · This machine remembers the last loaded profile locally
    </div>
  </div>
</div>
</body>
</html>

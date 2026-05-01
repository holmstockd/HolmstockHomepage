<?php
require_once 'auth.php';
// Only admin can access options
if (!isAdmin()) {
    header('Location: index.php'); exit;
}
$cfg = getDashConfig();

$msg = '';

// ===== AJAX endpoint for inline theme BG management =====
if (!empty($_GET['bgajax'])) {
    header('Content-Type: application/json');
    $bgs = json_decode(@file_get_contents(__DIR__.'/dash_custom_bg.json') ?: '{}', true) ?: [];
    $act = $_POST['action'] ?? '';
    if ($act === 'save_bg') {
        $theme = preg_replace('/[^a-z0-9_-]/', '', $_POST['theme'] ?? '');
        $type  = $_POST['bg_type'] ?? 'video_url';
        $url   = trim($_POST['url'] ?? '');
        $name  = htmlspecialchars(trim($_POST['bg_name'] ?? 'Custom'), ENT_QUOTES) ?: 'Custom';
        if ($url && $theme) {
            $tile     = !empty($_POST['tile']) && $_POST['tile'] === '1';
            $existing = $bgs[$theme] ?? [];
            if (!is_array($existing) || isset($existing['type'])) $existing = [];
            $entry = ['name' => $name, 'type' => $type, 'url' => $url];
            if ($tile) $entry['tile'] = true;
            $existing[] = $entry;
            $bgs[$theme] = $existing;
            file_put_contents(__DIR__.'/dash_custom_bg.json', json_encode($bgs));
            if (in_array($type, ['video_url', 'video_upload'])) {
                $v = json_decode(@file_get_contents(__DIR__.'/dash_videos.json') ?: '{}', true) ?: [];
                $v[$theme] = $url;
                file_put_contents(__DIR__.'/dash_videos.json', json_encode($v));
            }
            echo json_encode(['ok' => true, 'bgs' => array_values($bgs[$theme])]);
        } else {
            echo json_encode(['ok' => false, 'error' => 'Missing URL or theme']);
        }
    } elseif ($act === 'delete_named_bg') {
        $theme = preg_replace('/[^a-z0-9_-]/', '', $_POST['theme'] ?? '');
        $idx   = (int)($_POST['bg_index'] ?? -1);
        if ($theme && isset($bgs[$theme]) && isset($bgs[$theme][$idx])) {
            array_splice($bgs[$theme], $idx, 1);
            if (empty($bgs[$theme])) unset($bgs[$theme]);
            file_put_contents(__DIR__.'/dash_custom_bg.json', json_encode($bgs));
            echo json_encode(['ok' => true, 'bgs' => array_values($bgs[$theme] ?? [])]);
        } else {
            echo json_encode(['ok' => false, 'error' => 'Not found']);
        }
    } elseif ($act === 'upload_bg') {
        $theme = preg_replace('/[^a-z0-9_-]/', '', $_POST['theme'] ?? '');
        $type  = $_POST['upload_type'] ?? 'video';
        if ($theme && isset($_FILES['file']) && $_FILES['file']['error'] === 0) {
            $ext     = strtolower(pathinfo($_FILES['file']['name'], PATHINFO_EXTENSION));
            $allowed = $type === 'image' ? ['jpg','jpeg','png','gif','webp'] : ['mp4','webm','mov','m4v','ogg'];
            if (in_array($ext, $allowed)) {
                $dir = __DIR__ . '/' . ($type === 'image' ? 'uploads/' : 'videos/');
                if (!is_dir($dir)) mkdir($dir, 0755, true);
                $fname = $theme . '_' . time() . '.' . $ext;
                move_uploaded_file($_FILES['file']['tmp_name'], $dir . $fname);
                $subdir = $type === 'image' ? 'uploads' : 'videos';
                $url    = $subdir . '/' . $fname;  // relative to dashboard root (not dash/ prefix)
                $bgName = htmlspecialchars(trim($_POST['bg_name'] ?? ''), ENT_QUOTES) ?: basename($fname, '.' . $ext);
                $tile   = !empty($_POST['tile']) && $_POST['tile'] === '1';
                $existing = $bgs[$theme] ?? [];
                if (!is_array($existing) || (count($existing) > 0 && !isset($existing[0]))) $existing = [];
                $entry = ['name' => $bgName, 'type' => ($type === 'image' ? 'image_upload' : 'video_upload'), 'url' => $url];
                if ($tile) $entry['tile'] = true;
                $existing[] = $entry;
                $bgs[$theme] = $existing;
                file_put_contents(__DIR__.'/dash_custom_bg.json', json_encode($bgs));
                echo json_encode(['ok' => true, 'bgs' => array_values($bgs[$theme]), 'url' => $url]);
            } else {
                echo json_encode(['ok' => false, 'error' => 'File type not allowed']);
            }
        } else {
            echo json_encode(['ok' => false, 'error' => 'No file uploaded or missing theme']);
        }
    } else {
        echo json_encode(['ok' => false, 'error' => 'Unknown action']);
    }
    exit;
}

/**
 * Build the content for dash_config.php, always including DASH_SETUP_DONE=true
 * and preserving any DB config lines that were already in the file.
 */
function buildConfigContent(string $username, string $hash, string $title, int $cols): string {
    $lines   = ["<?php", "define('DASH_SETUP_DONE',true);",
                "define('DASH_USERNAME','".addslashes($username)."');",
                "define('DASH_PASSWORD_HASH','".addslashes($hash)."');",
                "define('DASH_TITLE','".addslashes($title)."');",
                "define('DASH_GRID_COLS',$cols);"];
    // Preserve any DASH_DB_* lines from the existing config
    $existing = @file_get_contents(__DIR__.'/dash_config.php') ?: '';
    foreach (explode("\n", $existing) as $line) {
        if (preg_match("/define\('DASH_DB_/", $line)) {
            $lines[] = rtrim($line);
        }
    }
    return implode("\n", $lines) . "\n";
}

// ─── Handle POST actions ──────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    switch ($_POST['action']) {

        case 'change_password':
            $new = $_POST['new_password'] ?? '';
            $confirm = $_POST['confirm_password'] ?? '';
            if (strlen($new) < 6) { $msg = 'error:Password must be at least 6 characters.'; break; }
            if ($new !== $confirm) { $msg = 'error:Passwords do not match.'; break; }
            $hash = password_hash($new, PASSWORD_BCRYPT);
            file_put_contents(__DIR__.'/dash_config.php',
                buildConfigContent($cfg['username'], $hash,
                    htmlspecialchars(trim($_POST['dash_title'] ?? $cfg['title']),ENT_QUOTES),
                    (int)($cfg['grid_cols'] ?? 3)));
            $msg = 'success:Password updated!';
            break;

        case 'save_settings':
            $title = htmlspecialchars(trim($_POST['dash_title'] ?? 'Server Dashboard'), ENT_QUOTES);
            $cols  = max(1, min(6, (int)($_POST['grid_cols'] ?? 3)));
            file_put_contents(__DIR__.'/dash_config.php',
                buildConfigContent($cfg['username'], $cfg['password_hash'], $title, $cols));
            $msg = 'success:Settings saved!';
            break;

        case 'save_search_engine':
            $engine = preg_replace('/[^a-z]/', '', strtolower($_POST['engine'] ?? 'google'));
            $st = json_decode(@file_get_contents(__DIR__.'/dash_state.json') ?: '{}', true) ?: [];
            $st['search_engine'] = $engine;
            file_put_contents(__DIR__.'/dash_state.json', json_encode($st, JSON_PRETTY_PRINT));
            $msg = 'success:Search engine saved!';
            break;

        case 'upload_logo':
            $uf = $_FILES['logo_file'] ?? null;
            if (!$uf || $uf['error'] !== UPLOAD_ERR_OK) { $msg = 'error:Upload failed.'; break; }
            $ext = strtolower(pathinfo($uf['name'], PATHINFO_EXTENSION));
            if (!in_array($ext, ['jpg','jpeg','png','gif','webp','svg'])) {
                $msg = 'error:Invalid type. Use JPG, PNG, GIF, WebP, or SVG.'; break;
            }
            @mkdir(__DIR__.'/uploads', 0755, true);
            foreach (['jpg','jpeg','png','gif','webp','svg'] as $ex) {
                $old = __DIR__.'/uploads/site_logo.'.$ex;
                if (file_exists($old)) unlink($old);
            }
            move_uploaded_file($uf['tmp_name'], __DIR__.'/uploads/site_logo.'.$ext);
            $msg = 'success:Logo uploaded! Reload the dashboard to see it.';
            break;

        case 'remove_logo':
            foreach (['jpg','jpeg','png','gif','webp','svg'] as $ex) {
                $old = __DIR__.'/uploads/site_logo.'.$ex;
                if (file_exists($old)) unlink($old);
            }
            $msg = 'success:Logo removed.';
            break;

        case 'save_drives':
            $drives = [];
            $keys   = $_POST['drive_key']   ?? [];
            $paths  = $_POST['drive_path']  ?? [];
            $labels = $_POST['drive_label'] ?? [];
            $icons  = $_POST['drive_icon']  ?? [];
            foreach ($keys as $i => $k) {
                $k = preg_replace('/[^a-z0-9_]/', '', strtolower($k));
                $p = trim($paths[$i] ?? '');
                if ($k && $p) {
                    $drives[] = ['key'=>$k,'path'=>$p,'label'=>trim($labels[$i]??$k),'icon'=>($icons[$i]??'💾')];
                }
            }
            file_put_contents(__DIR__.'/dash_drives.json', json_encode($drives, JSON_PRETTY_PRINT));
            $msg = 'success:Drive configuration saved!';
            break;

        case 'save_bg':
            $theme = preg_replace('/[^a-z0-9_-]/', '', $_POST['theme'] ?? '');
            $type  = $_POST['bg_type'] ?? 'video_url';
            $url   = trim($_POST['url'] ?? '');
            $name  = htmlspecialchars(trim($_POST['bg_name'] ?? 'Custom'), ENT_QUOTES);
            if (!$name) $name = 'Custom';
            $bgs   = json_decode(@file_get_contents(__DIR__.'/dash_custom_bg.json') ?: '{}', true) ?: [];
            if ($url) {
                // Always store as array of named entries
                $existing = $bgs[$theme] ?? [];
                if (!is_array($existing)) {
                    $existing = [];
                } elseif (isset($existing['type'])) {
                    // Legacy single object {type, url} → convert to array
                    $existing = [['name'=>'Custom','type'=>$existing['type'],'url'=>$existing['url']??'']];
                }
                // else: already a numerically-indexed array of entries
                $existing[] = ['name'=>$name,'type'=>$type,'url'=>$url];
                $bgs[$theme] = $existing;
            } elseif (!$url && empty($bgs[$theme])) {
                unset($bgs[$theme]);
            }
            file_put_contents(__DIR__.'/dash_custom_bg.json', json_encode($bgs));
            // Legacy videos.json compat
            $videos = json_decode(@file_get_contents(__DIR__.'/dash_videos.json') ?: '{}', true) ?: [];
            if ($url && in_array($type, ['video_url','video_upload'])) $videos[$theme] = $url;
            else unset($videos[$theme]);
            file_put_contents(__DIR__.'/dash_videos.json', json_encode($videos));
            $msg = 'success:Background "'.htmlspecialchars($name).'" added to '.$theme.'!';
            break;

        case 'delete_named_bg':
            $theme = preg_replace('/[^a-z0-9_-]/', '', $_POST['theme'] ?? '');
            $idx   = (int)($_POST['bg_index'] ?? -1);
            $bgs   = json_decode(@file_get_contents(__DIR__.'/dash_custom_bg.json') ?: '{}', true) ?: [];
            if (isset($bgs[$theme]) && is_array($bgs[$theme]) && isset($bgs[$theme][$idx])) {
                array_splice($bgs[$theme], $idx, 1);
                if (empty($bgs[$theme])) unset($bgs[$theme]);
                file_put_contents(__DIR__.'/dash_custom_bg.json', json_encode($bgs));
                $msg = 'success:Background removed.';
            }
            break;

        case 'upload_bg':
            $theme = preg_replace('/[^a-z0-9_-]/', '', $_POST['theme'] ?? '');
            $type  = $_POST['upload_type'] ?? 'video';
            if (isset($_FILES['file']) && $_FILES['file']['error'] === 0) {
                $ext = strtolower(pathinfo($_FILES['file']['name'], PATHINFO_EXTENSION));
                $allowed_video = ['mp4','webm','mov','m4v','ogg'];
                $allowed_img   = ['jpg','jpeg','png','gif','webp'];
                $allowed       = $type === 'image' ? $allowed_img : $allowed_video;
                if (in_array($ext, $allowed)) {
                    $dir = __DIR__.'/' . ($type === 'image' ? 'uploads/' : 'videos/');
                    if (!is_dir($dir)) mkdir($dir, 0755, true);
                    $fname = $theme.'_'.time().'.'.$ext;
                    move_uploaded_file($_FILES['file']['tmp_name'], $dir.$fname);
                    $subdir = $type === 'image' ? 'uploads' : 'videos';
                    $url  = $subdir.'/'.$fname;
                    $bgName = htmlspecialchars(trim($_POST['bg_name'] ?? ''), ENT_QUOTES) ?: basename($fname, '.'.$ext);
                    $bgs  = json_decode(@file_get_contents(__DIR__.'/dash_custom_bg.json') ?: '{}', true) ?: [];
                    $existing = $bgs[$theme] ?? [];
                    if (!is_array($existing) || (count($existing)>0 && !isset($existing[0]))) $existing = [];
                    $existing[] = ['name'=>$bgName,'type'=>($type==='image'?'image_upload':'video_upload'),'url'=>$url];
                    $bgs[$theme] = $existing;
                    file_put_contents(__DIR__.'/dash_custom_bg.json', json_encode($bgs));
                    if ($type !== 'image') {
                        $videos = json_decode(@file_get_contents(__DIR__.'/dash_videos.json') ?: '{}', true) ?: [];
                        $videos[$theme] = '/'.$url;
                        file_put_contents(__DIR__.'/dash_videos.json', json_encode($videos));
                    }
                    $msg = 'success:File uploaded! Path: /'.$url;
                } else {
                    $msg = 'error:File type not allowed.';
                }
            }
            break;

        case 'save_custom_theme':
            $data = json_decode($_POST['theme_json'] ?? '{}', true);
            if ($data) {
                file_put_contents(__DIR__.'/dash_custom_theme.json', json_encode($data));
                $msg = 'success:Custom theme saved!';
            }
            break;

        case 'add_site':
            $links = json_decode(@file_get_contents(__DIR__.'/dash_links.json') ?: '[]', true) ?: [];
            $section = trim($_POST['section'] ?? '');
            $label   = htmlspecialchars(trim($_POST['label'] ?? ''), ENT_QUOTES);
            $url     = trim($_POST['url'] ?? '');
            $icon    = htmlspecialchars(trim($_POST['icon'] ?? '🔗'), ENT_QUOTES);
            // Handle custom image icon upload
            $iconImg = '';
            if (isset($_FILES['icon_image']) && $_FILES['icon_image']['error'] === 0) {
                $ext = strtolower(pathinfo($_FILES['icon_image']['name'], PATHINFO_EXTENSION));
                if (in_array($ext, ['jpg','jpeg','png','gif','webp','svg'])) {
                    $dir = __DIR__.'/uploads/icons/';
                    if (!is_dir($dir)) mkdir($dir, 0755, true);
                    $fname = 'icon_'.time().'_'.mt_rand(100,999).'.'.$ext;
                    move_uploaded_file($_FILES['icon_image']['tmp_name'], $dir.$fname);
                    $iconImg = 'uploads/icons/'.$fname;
                    $icon = ''; // use image instead of emoji
                }
            }
            if ($label && $url) {
                $found = false;
                foreach ($links as &$sec) {
                    if ($sec['title'] === $section || $sec['id'] === $section) {
                        $entry = ['icon'=>$icon,'label'=>$label,'url'=>$url];
                        if ($iconImg) $entry['icon_img'] = $iconImg;
                        $sec['cards'][] = $entry;
                        $found = true; break;
                    }
                }
                unset($sec);
                if (!$found) {
                    $entry = ['icon'=>$icon,'label'=>$label,'url'=>$url];
                    if ($iconImg) $entry['icon_img'] = $iconImg;
                    $links[] = ['id'=>'sec-'.time(),'title'=>$section ?: $label,'icon'=>$icon,'cards'=>[$entry]];
                }
                file_put_contents(__DIR__.'/dash_links.json', json_encode($links, JSON_PRETTY_PRINT));
                $msg = 'success:Site/link added!';
            }
            break;

        case 'update_link_icon':
            // Edit icon/image for existing link
            $links = json_decode(@file_get_contents(__DIR__.'/dash_links.json') ?: '[]', true) ?: [];
            $secId = $_POST['sec_id'] ?? '';
            $urlKey = $_POST['url_key'] ?? '';
            $newIcon = trim($_POST['new_icon'] ?? '');
            $iconImg = '';
            if (isset($_FILES['icon_image']) && $_FILES['icon_image']['error'] === 0) {
                $ext = strtolower(pathinfo($_FILES['icon_image']['name'], PATHINFO_EXTENSION));
                if (in_array($ext, ['jpg','jpeg','png','gif','webp','svg'])) {
                    $dir = __DIR__.'/uploads/icons/';
                    if (!is_dir($dir)) mkdir($dir, 0755, true);
                    $fname = 'icon_'.time().'_'.mt_rand(100,999).'.'.$ext;
                    move_uploaded_file($_FILES['icon_image']['tmp_name'], $dir.$fname);
                    $iconImg = 'uploads/icons/'.$fname;
                }
            }
            foreach ($links as &$sec) {
                if (($sec['id']??'') === $secId || ($sec['title']??'') === $secId) {
                    foreach ($sec['cards'] as &$card) {
                        if ($card['url'] === $urlKey) {
                            if ($newIcon) { $card['icon'] = $newIcon; unset($card['icon_img']); }
                            if ($iconImg) { $card['icon_img'] = $iconImg; $card['icon'] = ''; }
                            break 2;
                        }
                    }
                    unset($card);
                }
            }
            unset($sec);
            file_put_contents(__DIR__.'/dash_links.json', json_encode($links, JSON_PRETTY_PRINT));
            $msg = 'success:Icon updated!';
            break;

        case 'move_link':
            // Move a link from one section to another
            $links = json_decode(@file_get_contents(__DIR__.'/dash_links.json') ?: '[]', true) ?: [];
            $fromSec = $_POST['from_sec'] ?? '';
            $urlKey  = $_POST['url_key'] ?? '';
            $toSec   = trim($_POST['to_sec'] ?? '');
            $moved   = null;
            foreach ($links as &$sec) {
                if (($sec['title']??'') === $fromSec || ($sec['id']??'') === $fromSec) {
                    foreach ($sec['cards'] as $i => $card) {
                        if ($card['url'] === $urlKey) { $moved = $card; array_splice($sec['cards'], $i, 1); break; }
                    }
                }
            } unset($sec);
            if ($moved) {
                $found = false;
                foreach ($links as &$sec) {
                    if (($sec['title']??'') === $toSec || ($sec['id']??'') === $toSec) { $sec['cards'][] = $moved; $found=true; break; }
                } unset($sec);
                if (!$found) $links[] = ['id'=>'sec-'.time(),'title'=>$toSec,'icon'=>$moved['icon']??'🔗','cards'=>[$moved]];
                file_put_contents(__DIR__.'/dash_links.json', json_encode($links, JSON_PRETTY_PRINT));
                $msg = 'success:Link moved!';
            }
            break;

        case 'delete_link':
            $links = json_decode(@file_get_contents(__DIR__.'/dash_links.json') ?: '[]', true) ?: [];
            $secId = $_POST['sec_id'] ?? '';
            $urlKey = $_POST['url_key'] ?? '';
            foreach ($links as &$sec) {
                if (($sec['title']??'') === $secId || ($sec['id']??'') === $secId) {
                    $sec['cards'] = array_filter($sec['cards'], fn($c) => $c['url'] !== $urlKey);
                    $sec['cards'] = array_values($sec['cards']);
                }
            } unset($sec);
            file_put_contents(__DIR__.'/dash_links.json', json_encode($links, JSON_PRETTY_PRINT));
            $msg = 'success:Link deleted!';
            break;

        case 'delete_section':
            $links  = json_decode(@file_get_contents(__DIR__.'/dash_links.json') ?: '[]', true) ?: [];
            $secId  = $_POST['sec_id'] ?? '';
            $links  = array_values(array_filter($links, fn($s) => ($s['title']??'') !== $secId && ($s['id']??'') !== $secId));
            file_put_contents(__DIR__.'/dash_links.json', json_encode($links, JSON_PRETTY_PRINT));
            $msg = 'success:Column deleted!';
            break;

        case 'toggle_hidden_theme':
            $ht = json_decode(@file_get_contents(__DIR__.'/dash_hidden_themes.json') ?: '[]', true) ?: [];
            $th = preg_replace('/[^a-z0-9_-]/','',$_POST['theme']??'');
            if (in_array($th,$ht)) $ht = array_values(array_filter($ht,fn($x)=>$x!==$th));
            else $ht[] = $th;
            file_put_contents(__DIR__.'/dash_hidden_themes.json', json_encode($ht));
            $msg = 'success:Theme visibility updated!';
            break;

        case 'add_user':
            $uname = preg_replace('/[^a-zA-Z0-9_-]/', '', trim($_POST['new_username'] ?? ''));
            $pass  = $_POST['new_password'] ?? '';
            $role  = in_array($_POST['new_role'] ?? 'user', ['user','readonly']) ? $_POST['new_role'] : 'user';
            if (strlen($uname) < 2) { $msg = 'error:Username must be at least 2 characters.'; break; }
            if (strlen($pass)  < 6) { $msg = 'error:Password must be at least 6 characters.'; break; }
            if (strtolower($uname) === strtolower($cfg['username'])) { $msg = 'error:That username is already the admin.'; break; }
            $users = json_decode(@file_get_contents(__DIR__.'/dash_users.json') ?: '[]', true) ?: [];
            foreach ($users as $u) {
                if (strtolower($u['username']) === strtolower($uname)) { $msg = 'error:Username already exists.'; break 2; }
            }
            $users[] = ['username'=>$uname, 'password_hash'=>password_hash($pass, PASSWORD_BCRYPT), 'role'=>$role];
            file_put_contents(__DIR__.'/dash_users.json', json_encode($users, JSON_PRETTY_PRINT));
            $msg = 'success:User "'.$uname.'" added!';
            break;

        case 'delete_user':
            $uname = trim($_POST['del_username'] ?? '');
            $users = json_decode(@file_get_contents(__DIR__.'/dash_users.json') ?: '[]', true) ?: [];
            $users = array_values(array_filter($users, fn($u) => $u['username'] !== $uname));
            file_put_contents(__DIR__.'/dash_users.json', json_encode($users, JSON_PRETTY_PRINT));
            $msg = 'success:User deleted.';
            break;

        case 'reset_user_password':
            $uname = trim($_POST['reset_username'] ?? '');
            $pass  = $_POST['reset_password'] ?? '';
            if (strlen($pass) < 6) { $msg = 'error:Password must be at least 6 characters.'; break; }
            $users = json_decode(@file_get_contents(__DIR__.'/dash_users.json') ?: '[]', true) ?: [];
            $found = false;
            foreach ($users as &$u) {
                if ($u['username'] === $uname) { $u['password_hash'] = password_hash($pass, PASSWORD_BCRYPT); $found = true; break; }
            } unset($u);
            if ($found) {
                file_put_contents(__DIR__.'/dash_users.json', json_encode($users, JSON_PRETTY_PRINT));
                $msg = 'success:Password updated for "'.$uname.'".';
            } else { $msg = 'error:User not found.'; }
            break;

        case 'add_column':
            $links = json_decode(@file_get_contents(__DIR__.'/dash_links.json') ?: '[]', true) ?: [];
            $title = htmlspecialchars(trim($_POST['col_title'] ?? ''), ENT_QUOTES);
            $icon  = htmlspecialchars(trim($_POST['col_icon']  ?? '📌'), ENT_QUOTES);
            if (!$title) { $msg = 'error:Column name is required.'; break; }
            foreach ($links as $s) {
                if (($s['title'] ?? '') === $title) { $msg = 'error:A column named "'.htmlspecialchars($title).'" already exists.'; break 2; }
            }
            $links[] = ['id'=>'sec-'.time(),'title'=>$title,'icon'=>$icon,'cards'=>[]];
            file_put_contents(__DIR__.'/dash_links.json', json_encode($links, JSON_PRETTY_PRINT));
            $msg = 'success:Column "'.htmlspecialchars($title).'" created! Go to the dashboard and enter Edit Mode to add links to it.';
            break;

        case 'save_widget_settings':
            $mon = json_decode(@file_get_contents(__DIR__.'/dash_monitor.json') ?: '{}', true) ?: [];
            foreach (['cpu','ram','storage','clock','weather'] as $k) {
                $mon[$k] = !empty($_POST['widget_'.$k]);
            }
            file_put_contents(__DIR__.'/dash_monitor.json', json_encode($mon, JSON_PRETTY_PRINT));
            $msg = 'success:Widget settings saved! Reload the dashboard to see changes.';
            break;

        case 'add_html_widget':
            $name = htmlspecialchars(trim($_POST['hw_name'] ?? ''), ENT_QUOTES);
            $html = trim($_POST['hw_html'] ?? '');
            if (!$name || !$html) { $msg = 'error:Widget name and HTML code are required.'; break; }
            $hwWidgets = json_decode(@file_get_contents(__DIR__.'/dash_html_widgets.json') ?: '[]', true) ?: [];
            $hwWidgets[] = ['id'=>'hw-'.time(),'name'=>$name,'html'=>$html,'x'=>820,'y'=>80];
            file_put_contents(__DIR__.'/dash_html_widgets.json', json_encode($hwWidgets, JSON_PRETTY_PRINT));
            $msg = 'success:Widget "'.htmlspecialchars($name).'" added! Reload the dashboard to see it.';
            break;

        case 'delete_html_widget':
            $hwId = trim($_POST['hw_id'] ?? '');
            $hwWidgets = json_decode(@file_get_contents(__DIR__.'/dash_html_widgets.json') ?: '[]', true) ?: [];
            $hwWidgets = array_values(array_filter($hwWidgets, fn($w) => $w['id'] !== $hwId));
            file_put_contents(__DIR__.'/dash_html_widgets.json', json_encode($hwWidgets, JSON_PRETTY_PRINT));
            $msg = 'success:Widget deleted.';
            break;

        case 'import_bookmarks':
            $json  = $_POST['bookmarks_json'] ?? '[]';
            $items = json_decode($json, true);
            if (!is_array($items)) { $msg = 'error:Invalid bookmark data.'; break; }
            $links = json_decode(@file_get_contents(__DIR__.'/dash_links.json') ?: '[]', true) ?: [];
            $imported = 0;
            foreach ($items as $item) {
                $colTitle = htmlspecialchars(trim($item['column'] ?? 'Imported'), ENT_QUOTES);
                $bLabel   = htmlspecialchars(trim($item['label'] ?? ''), ENT_QUOTES);
                $bUrl     = trim($item['url'] ?? '');
                if (!$bLabel || !$bUrl || !filter_var($bUrl, FILTER_VALIDATE_URL)) continue;
                $found = false;
                foreach ($links as &$sec) {
                    if (($sec['title'] ?? '') === $colTitle) {
                        $sec['cards'][] = ['icon'=>'🔗','label'=>$bLabel,'url'=>$bUrl];
                        $found = true; break;
                    }
                } unset($sec);
                if (!$found) {
                    $links[] = ['id'=>'sec-'.time().'-'.mt_rand(0,9999),'title'=>$colTitle,'icon'=>'🔖','cards'=>[['icon'=>'🔗','label'=>$bLabel,'url'=>$bUrl]]];
                }
                $imported++;
            }
            if ($imported) file_put_contents(__DIR__.'/dash_links.json', json_encode($links, JSON_PRETTY_PRINT));
            $msg = 'success:Imported '.$imported.' bookmark(s) successfully!';
            break;
    }
    // Re-read config after saves
    $cfg = getDashConfig();
}

// ─── Load data ────────────────────────────────────────────────────────────────
$drives       = json_decode(@file_get_contents(__DIR__.'/dash_drives.json') ?: '[]', true) ?: [];
$bgs          = json_decode(@file_get_contents(__DIR__.'/dash_custom_bg.json') ?: '{}', true) ?: [];
$videos       = json_decode(@file_get_contents(__DIR__.'/dash_videos.json') ?: '{}', true) ?: [];
$links        = json_decode(@file_get_contents(__DIR__.'/dash_links.json') ?: '[]', true) ?: [];
$custom_theme = json_decode(@file_get_contents(__DIR__.'/dash_custom_theme.json') ?: '{}', true) ?: [];
$monitor      = json_decode(@file_get_contents(__DIR__.'/dash_monitor.json') ?: '{}', true) ?: [];
$html_widgets = json_decode(@file_get_contents(__DIR__.'/dash_html_widgets.json') ?: '[]', true) ?: [];
$dash_state   = json_decode(@file_get_contents(__DIR__.'/dash_state.json') ?: '{}', true) ?: [];
// Current logo file (if any)
$_opt_logo = '';
foreach (['jpg','jpeg','png','gif','webp','svg'] as $_lx) {
    if (file_exists(__DIR__.'/uploads/site_logo.'.$_lx)) { $_opt_logo = 'uploads/site_logo.'.$_lx; break; }
}

// Variant-only themes: accessible via the variant dropdown on the dashboard,
// not as standalone theme choices. Excluded from Theme Visibility list.
$theme_variants_only = ['winxp2', 'jellybean2', 'palmtreo'];

$themes = [
    'win98'       => '💾 Win 98',
    'win9x'       => '🪟 WIN9X Retro',
    'win2k'       => '🖥 Win 2000',
    'winxp'       => '🪟 Win XP',
    'winxp2'      => '🐟 Win XP Aquarium (variant — use XP variant dropdown)',
    'winphone'    => '📱 Win Phone',
    'aqua'        => '🍎 OSX Aqua',
    'ios26'       => '✨ iOS 26',
    'jellybean'   => '🤖 Android 4 (Jelly Bean)',
    'jellybean2'  => '🤖 Android 4 Nexus (variant — use Jelly Bean variant dropdown)',
    'palmos'      => '📟 Palm OS',
    'palmtreo'    => '📱 Palm Treo (variant — use Palm OS variant dropdown)',
    'pocketpc'    => '📲 Pocket PC 6',
    'macos'       => '🍎 macOS',
    'macos9'      => '🌈 Mac OS 9',
    'mac9'        => '🌈 Mac9 Retro',
    'macosx'      => '🍎 MacOSX Retro',
    'ubuntu'      => '🟠 Ubuntu',
    'c64'         => '🕹 Commodore 64',
    'os2'         => '🗄 OS/2 Warp',
    'webos'       => '🌙 Palm webOS',
    'osxtiger'    => '🐯 OSX Tiger',
    'professional'=> '👔 Professional',
    'girly'       => '🌸 Girly',
    'spring'      => '🌷 Spring',
    'summer'      => '☀️ Summer',
    'autumn'      => '🍂 Autumn',
    'winter'      => '❄️ Winter',
    'thanksgiving'=> '🦃 Thanksgiving',
    'july4'       => '🎆 July 4th',
    'christmas'   => '✝️ Christmas',
    'custom'      => '🎨 Custom Theme',
];

// Load hidden themes list
$hidden_themes = json_decode(@file_get_contents(__DIR__.'/dash_hidden_themes.json') ?: '[]', true) ?: [];

// Load sub-users
$sub_users = json_decode(@file_get_contents(__DIR__.'/dash_users.json') ?: '[]', true) ?: [];

// Sections for add-site
$sections = [];
foreach ($links as $s) { $sections[] = $s['title'] ?? $s['id'] ?? ''; }

?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Dashboard Options</title>
<link rel="icon" type="image/svg+xml" href="favicon.svg">
<style>
*{box-sizing:border-box;margin:0;padding:0;}
body{font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',sans-serif;background:#0a0a1a;color:#fff;min-height:100vh;padding:24px;}
.header{display:flex;align-items:center;justify-content:space-between;margin-bottom:28px;padding-bottom:16px;border-bottom:1px solid rgba(255,255,255,0.1);}
.header h1{font-size:22px;font-weight:700;}
.back-btn{padding:6px 14px;background:rgba(255,255,255,0.1);border:1px solid rgba(255,255,255,0.2);border-radius:8px;color:#fff;text-decoration:none;font-size:13px;}
.section{background:rgba(255,255,255,0.05);border:1px solid rgba(255,255,255,0.1);border-radius:16px;padding:20px;margin-bottom:20px;}
.section h2{font-size:15px;font-weight:600;margin-bottom:16px;color:rgba(255,255,255,0.8);}
.msg{padding:10px 14px;border-radius:8px;font-size:13px;margin-bottom:16px;}
.msg.success{background:rgba(0,200,100,0.2);border:1px solid rgba(0,200,100,0.3);color:#00e676;}
.msg.error{background:rgba(255,60,60,0.2);border:1px solid rgba(255,60,60,0.3);color:#ff6060;}
label{display:block;font-size:12px;color:rgba(255,255,255,0.5);margin-bottom:4px;margin-top:12px;}
input[type=text],input[type=password],input[type=url],input[type=number],input[type=color],select,textarea{width:100%;padding:9px 12px;background:rgba(255,255,255,0.08);border:1px solid rgba(255,255,255,0.15);border-radius:8px;color:#fff;font-size:14px;outline:none;}
input[type=color]{padding:4px;height:38px;cursor:pointer;}
input:focus,select:focus,textarea:focus{border-color:rgba(74,158,255,0.6);}
select option{background:#1a1a2e;color:#fff;}
.btn{padding:9px 18px;border-radius:8px;border:none;cursor:pointer;font-size:13px;font-weight:600;display:inline-flex;align-items:center;gap:6px;}
.btn-primary{background:#4a9eff;color:#fff;}
.btn-danger{background:rgba(255,60,60,0.3);color:#ff8080;border:1px solid rgba(255,60,60,0.3);}
.btn-secondary{background:rgba(255,255,255,0.1);color:#fff;border:1px solid rgba(255,255,255,0.15);}
.btn-sm{padding:5px 12px;font-size:12px;}
.tabs{display:flex;gap:8px;margin-bottom:20px;flex-wrap:wrap;}
.tab{padding:8px 16px;border-radius:8px;cursor:pointer;font-size:13px;background:rgba(255,255,255,0.05);border:1px solid rgba(255,255,255,0.1);color:rgba(255,255,255,0.6);white-space:nowrap;}
.tab.active{background:rgba(74,158,255,0.2);border-color:rgba(74,158,255,0.4);color:#4a9eff;}
.tab-content{display:none;}
.tab-content.active{display:block;}
.drive-row{display:grid;grid-template-columns:80px 40px 1fr 120px 40px auto;gap:8px;align-items:center;padding:8px 0;border-bottom:1px solid rgba(255,255,255,0.05);}
.drive-row:last-child{border-bottom:none;}
.drive-row input{margin:0;}
.upload-label{cursor:pointer;padding:6px 10px;background:rgba(255,255,255,0.08);border:1px solid rgba(255,255,255,0.15);border-radius:6px;font-size:12px;white-space:nowrap;display:inline-block;}
.theme-bg-card{background:rgba(255,255,255,0.03);border-radius:10px;padding:14px;margin-bottom:12px;}
.theme-bg-card h4{font-size:14px;font-weight:600;margin-bottom:10px;}
.bg-current{font-size:11px;color:#4a9eff;margin-bottom:8px;word-break:break-all;}
.row2{display:flex;gap:8px;align-items:flex-end;margin-top:8px;}
.row2>*{flex:1;}
.row2>.btn{flex:0 0 auto;}
.site-row{display:flex;align-items:center;gap:8px;padding:8px 0;border-bottom:1px solid rgba(255,255,255,0.05);font-size:13px;}
.site-row:last-child{border-bottom:none;}
.site-name{flex:1;font-weight:600;}
.site-url{font-size:11px;color:#4a9eff;}
.site-badge{font-size:10px;padding:2px 6px;border-radius:4px;background:rgba(74,158,255,0.2);color:#4a9eff;white-space:nowrap;}
.grid-row{display:grid;grid-template-columns:1fr 1fr;gap:12px;}
.custom-theme-preview{border:1px solid rgba(255,255,255,0.15);border-radius:10px;padding:12px;margin-top:12px;font-size:13px;}
.custom-theme-preview .preview-card{padding:6px 10px;border-radius:4px;margin:4px 0;cursor:pointer;}
code{color:#4a9eff;font-size:11px;background:rgba(74,158,255,0.1);padding:2px 6px;border-radius:4px;}
.export-area{width:100%;height:100px;background:rgba(0,0,0,0.3);border:1px solid rgba(255,255,255,0.1);border-radius:8px;color:#fff;font-family:monospace;font-size:11px;padding:10px;resize:vertical;}
.links-list .link-sec{background:rgba(255,255,255,0.03);border-radius:8px;padding:12px;margin-bottom:10px;}
.links-list .link-sec h4{font-size:13px;font-weight:600;margin-bottom:8px;}
.links-list .link-card{display:flex;align-items:center;gap:8px;font-size:13px;padding:4px 0;}
.del-btn{background:rgba(255,60,60,0.2);border:1px solid rgba(255,60,60,0.3);color:#ff8080;border-radius:4px;padding:2px 8px;font-size:11px;cursor:pointer;}
</style>
</head>
<body>
<div class="header">
  <h1>⚙️ Dashboard Options</h1>
  <a href="index.php" class="back-btn">← Back to Dashboard</a>
</div>

<?php if ($msg):
  $type = str_starts_with($msg,'error:') ? 'error' : 'success';
  $text = substr($msg, strpos($msg,':')+1);
?>
<div class="msg <?= $type ?>"><?= htmlspecialchars($text) ?></div>
<?php endif; ?>

<div class="tabs">
  <div class="tab active"  onclick="showTab('general')">⚙️ General</div>
  <div class="tab" onclick="showTab('drives')">💾 Drives</div>
  <div class="tab" onclick="showTab('themes')">🎭 Themes</div>
  <div class="tab" onclick="showTab('customtheme')">🎨 Custom Theme</div>
  <div class="tab" onclick="showTab('links')">🔗 Links</div>
  <div class="tab" onclick="showTab('widgets')">🧩 Widgets</div>
  <div class="tab" onclick="showTab('users')">👥 Users</div>
  <div class="tab" onclick="showTab('password')">🔐 Password</div>
  <div class="tab" onclick="showTab('export')">📤 Export</div>
</div>

<!-- ===== GENERAL ===== -->
<div id="tab-general" class="tab-content active">
  <div class="section" style="margin-bottom:16px;">
    <h2>⚙️ General Settings</h2>
    <form method="POST">
      <input type="hidden" name="action" value="save_settings">
      <label>Dashboard Title</label>
      <input type="text" name="dash_title" value="<?= htmlspecialchars($cfg['title']) ?>" placeholder="Server Dashboard">
      <label>Grid Columns (1–6)</label>
      <input type="number" name="grid_cols" min="1" max="6" value="<?= (int)$cfg['grid_cols'] ?>">
      <div style="margin-top:16px;"><button type="submit" class="btn btn-primary">💾 Save Settings</button></div>
    </form>
  </div>

  <div class="section" style="margin-bottom:16px;">
    <h2>🔍 Search Bar Engine</h2>
    <p style="font-size:12px;color:rgba(255,255,255,.45);margin-bottom:14px;">Choose which search engine the top search bar sends queries to.</p>
    <form method="POST">
      <input type="hidden" name="action" value="save_search_engine">
      <select name="engine" style="background:#1a1a2e;color:#fff;border:1px solid rgba(255,255,255,.2);border-radius:6px;padding:8px 12px;font-size:13px;margin-bottom:14px;display:block;">
        <?php
        $engines = ['google'=>'🔍 Google','bing'=>'🔵 Bing','duckduckgo'=>'🦆 DuckDuckGo','brave'=>'🦁 Brave Search','ecosia'=>'🌱 Ecosia','kagi'=>'⚡ Kagi','yahoo'=>'💜 Yahoo','startpage'=>'🔒 Startpage'];
        $curEng  = $dash_state['search_engine'] ?? 'google';
        foreach ($engines as $ek => $el): ?>
        <option value="<?= $ek ?>" <?= $curEng===$ek?'selected':'' ?>><?= $el ?></option>
        <?php endforeach; ?>
      </select>
      <button type="submit" class="btn btn-primary">💾 Save Engine</button>
    </form>
  </div>

  <div class="section" style="margin-bottom:16px;">
    <h2>🖼 Site Logo</h2>
    <p style="font-size:12px;color:rgba(255,255,255,.45);margin-bottom:10px;">
      Upload a small image to replace the text title on the top bar.<br>
      <strong>Tips for creating a logo:</strong><br>
      📌 <strong>Canva</strong> (canva.com) — free drag-and-drop logo maker, export as PNG<br>
      📌 <strong>Paint.NET / GIMP</strong> — make a transparent PNG or simple text banner<br>
      📌 <strong>SVG Repo</strong> (svgrepo.com) — free icon SVGs, search by topic<br>
      📌 <strong>Crop your favicon</strong> — screenshot your server's existing icon at 2×<br>
      📌 <strong>Favicon.io</strong> — generate icon from text or emoji in seconds<br>
      Best size: <strong>200 × 40 px</strong> or less, transparent background PNG or SVG recommended.
    </p>
    <?php if ($_opt_logo): ?>
    <div style="background:rgba(0,0,0,.3);border-radius:8px;padding:12px 16px;margin-bottom:14px;display:flex;align-items:center;gap:14px;flex-wrap:wrap;">
      <img src="<?= htmlspecialchars($_opt_logo) ?>?<?= time() ?>" style="height:34px;border-radius:4px;background:rgba(255,255,255,.08);padding:4px;" alt="Current logo">
      <span style="font-size:12px;color:rgba(255,255,255,.5);">Current: <code><?= htmlspecialchars(basename($_opt_logo)) ?></code></span>
      <form method="POST" style="margin:0;">
        <input type="hidden" name="action" value="remove_logo">
        <button type="submit" class="btn btn-danger btn-sm" onclick="return confirm('Remove logo and go back to text title?')">🗑 Remove Logo</button>
      </form>
    </div>
    <?php endif; ?>
    <form method="POST" enctype="multipart/form-data">
      <input type="hidden" name="action" value="upload_logo">
      <div style="display:flex;gap:10px;align-items:flex-end;flex-wrap:wrap;">
        <div>
          <label style="font-size:12px;color:rgba(255,255,255,.5);margin-bottom:4px;display:block;">Image file (JPG, PNG, GIF, WebP, SVG)</label>
          <input type="file" name="logo_file" accept="image/*" required style="font-size:12px;color:#ccc;">
        </div>
        <button type="submit" class="btn btn-primary">⬆ Upload Logo</button>
      </div>
    </form>
  </div>

  <div class="section">
    <h2>📊 Stat Widget Visibility</h2>
    <p style="font-size:12px;color:rgba(255,255,255,.4);margin-bottom:12px;">Restore stat widgets you've hidden on the dashboard using the × button. These are stored per-browser.</p>
    <div id="stat-vis-list" style="display:flex;flex-wrap:wrap;gap:8px;min-height:32px;">
      <em style="font-size:12px;opacity:.4;">Loading…</em>
    </div>
  </div>
</div>

<!-- ===== DRIVES ===== -->
<div id="tab-drives" class="tab-content">
  <div class="section">
    <h2>💾 Drive Monitoring</h2>
    <p style="font-size:12px;color:rgba(255,255,255,0.4);margin-bottom:16px;">These drives will appear as widgets in the dashboard header. Click <strong>Auto-detect</strong> to scan your server's drives, or add paths manually.</p>
    <form method="POST" id="drives-form">
      <input type="hidden" name="action" value="save_drives">
      <div id="drives-list">
        <?php foreach ($drives as $i => $d): ?>
        <div class="drive-row" id="drow-<?= $i ?>">
          <input type="text" name="drive_key[]"   value="<?= htmlspecialchars($d['key']) ?>"   placeholder="key" title="Unique key (no spaces)">
          <input type="text" name="drive_icon[]"  value="<?= htmlspecialchars($d['icon']??'💾') ?>" placeholder="💾" style="width:38px;text-align:center;padding:4px;">
          <input type="text" name="drive_path[]"  value="<?= htmlspecialchars($d['path']) ?>"  placeholder="/media/server/drive">
          <input type="text" name="drive_label[]" value="<?= htmlspecialchars($d['label']) ?>" placeholder="Label">
          <button type="button" class="btn btn-danger btn-sm" onclick="this.closest('.drive-row').remove()">🗑</button>
        </div>
        <?php endforeach; ?>
      </div>
      <!-- Auto-detect results -->
      <div id="drive-detect-box" style="display:none;margin:12px 0;padding:12px;background:rgba(0,0,0,.25);border:1px solid rgba(255,255,255,.12);border-radius:8px;">
        <div style="font-size:11px;font-weight:bold;color:rgba(255,255,255,.5);text-transform:uppercase;letter-spacing:.05em;margin-bottom:8px;">Detected on this server (<span id="drive-detect-os"></span>)</div>
        <div id="drive-detect-list" style="display:flex;flex-direction:column;gap:6px;"></div>
        <div style="font-size:11px;color:rgba(255,255,255,.35);margin-top:8px;">Check the drives you want monitored, then click Add Selected.</div>
        <button type="button" class="btn btn-secondary btn-sm" style="margin-top:8px;" onclick="addDetectedDrives()">✅ Add Selected to List</button>
      </div>
      <!-- Manual path validator -->
      <div id="manual-path-box" style="margin-top:12px;padding:12px;background:rgba(0,0,0,.2);border:1px solid rgba(255,255,255,.1);border-radius:8px;">
        <div style="font-size:11px;font-weight:bold;color:rgba(255,255,255,.5);text-transform:uppercase;letter-spacing:.05em;margin-bottom:8px;">Add custom server path</div>
        <div style="display:flex;gap:6px;flex-wrap:wrap;align-items:center;">
          <input type="text" id="manual-path-input" placeholder="/mnt/data  or  /home/user/nas  or  D:\Data" style="flex:1;min-width:180px;padding:6px 9px;border-radius:6px;border:1px solid rgba(255,255,255,.2);background:rgba(255,255,255,.07);color:#fff;font-size:12px;font-family:monospace;">
          <input type="text" id="manual-path-label" placeholder="Label (e.g. NAS)" style="width:130px;padding:6px 9px;border-radius:6px;border:1px solid rgba(255,255,255,.2);background:rgba(255,255,255,.07);color:#fff;font-size:12px;">
          <button type="button" class="btn btn-secondary btn-sm" onclick="validateAndAddPath()" id="validate-path-btn">✅ Validate &amp; Add</button>
        </div>
        <div id="manual-path-result" style="margin-top:8px;font-size:12px;"></div>
        <div style="font-size:10px;color:rgba(255,255,255,.3);margin-top:6px;">The path must exist on this server. The server will check it and show disk usage before adding.</div>
      </div>
      <div style="margin-top:10px;display:flex;gap:8px;flex-wrap:wrap;">
        <button type="button" class="btn btn-secondary btn-sm" onclick="detectDrives()" id="detect-btn">🔍 Auto-detect Drives</button>
        <button type="submit" class="btn btn-primary">💾 Save Drives</button>
      </div>
    </form>
  </div>
  <div class="section">
    <h2>🧪 Quick Test</h2>
    <p style="font-size:12px;color:rgba(255,255,255,0.4);margin-bottom:12px;">Live stats from stats.php:</p>
    <pre id="stats-out" style="font-size:12px;color:#4a9eff;background:rgba(0,0,0,0.3);padding:12px;border-radius:8px;overflow:auto;">Loading…</pre>
    <button class="btn btn-secondary btn-sm" onclick="fetchStats()" style="margin-top:8px;">🔄 Refresh</button>
  </div>
</div>

<!-- ===== BACKGROUNDS (moved to Themes tab — kept for legacy POST handlers only, not shown) ===== -->
<div id="tab-backgrounds" class="tab-content" style="display:none!important;">
  <div class="section">
    <h2>🎬 Custom Backgrounds per Theme</h2>
    <p style="font-size:12px;color:rgba(255,255,255,0.4);margin-bottom:16px;">Add multiple named backgrounds per theme — video URLs, image URLs, animated web pages, or uploaded files. Each saved background shows up as a variant in the theme's variant dropdown on the dashboard.</p>
    <?php
    $presets = [
        'macos'    => [['name'=>'🌊 Big Sur Walls','type'=>'video_url','url'=>'https://i.imgur.com/KJQNVJq.mp4']],
        'ubuntu'   => [['name'=>'🔷 Yaru Wallpaper','type'=>'image_url','url'=>'https://assets.ubuntu.com/v1/9b8a55f5-focal-fossa.jpg']],
        'christmas'=> [['name'=>'❄️ Winter Forest','type'=>'video_url','url'=>'https://assets.mixkit.co/videos/preview/mixkit-snowy-forest-at-christmas-4147-large.mp4']],
        'july4'    => [['name'=>'🎆 Fireworks','type'=>'video_url','url'=>'https://assets.mixkit.co/videos/preview/mixkit-fireworks-in-the-city-at-new-year-2972-large.mp4']],
    ];
    foreach ($themes as $key => $label):
        // Normalize to array of named entries
        $entries = [];
        if (!empty($bgs[$key])) {
            $raw = $bgs[$key];
            if (is_array($raw) && isset($raw[0])) {
                $entries = $raw; // already array of named entries
            } elseif (is_array($raw) && isset($raw['type'])) {
                $entries = [['name'=>'Custom','type'=>$raw['type'],'url'=>$raw['url']]]; // legacy single object
            }
        }
    ?>
    <div class="theme-bg-card" id="bg-<?= $key ?>">
      <h4><?= $label ?> <span style="font-size:11px;font-weight:normal;opacity:.4;">#<?= $key ?></span></h4>

      <?php if (!empty($presets[$key])): ?>
      <div style="margin-bottom:10px;display:flex;flex-wrap:wrap;gap:6px;align-items:center;">
        <span style="font-size:11px;color:rgba(255,255,255,.35);">Quick presets:</span>
        <?php foreach($presets[$key] as $p): ?>
        <button type="button" class="btn btn-sm" style="font-size:11px;padding:3px 9px;background:rgba(255,255,255,.08);border:1px solid rgba(255,255,255,.15);border-radius:6px;"
          onclick="setPresetBg('<?= $key ?>','<?= addslashes($p['type']) ?>','<?= addslashes($p['url']) ?>','<?= addslashes($p['name']) ?>')"><?= $p['name'] ?></button>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>

      <?php if (!empty($entries)): ?>
      <div style="margin-bottom:12px;">
        <div style="font-size:11px;font-weight:bold;color:rgba(255,255,255,.5);text-transform:uppercase;letter-spacing:.05em;margin-bottom:6px;">Saved Backgrounds (<?= count($entries) ?>)</div>
        <?php foreach ($entries as $i => $entry): ?>
        <div style="display:flex;align-items:center;gap:8px;padding:6px 10px;background:rgba(255,255,255,.05);border-radius:7px;margin-bottom:4px;font-size:12px;">
          <span><?= $entry['type']==='video_url'||$entry['type']==='video_upload' ? '🎬' : ($entry['type']==='iframe_url' ? '🌐' : '🖼') ?></span>
          <span style="flex:1;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="<?= htmlspecialchars($entry['url']??'') ?>">
            <strong><?= htmlspecialchars($entry['name']??'Custom') ?></strong>
            <span style="opacity:.45;margin-left:6px;"><?= htmlspecialchars(substr($entry['url']??'',0,50)) ?><?= strlen($entry['url']??'')>50?'…':'' ?></span>
          </span>
          <a href="<?= htmlspecialchars($entry['url']??'#') ?>" target="_blank" style="font-size:11px;opacity:.5;text-decoration:none;" title="Preview">▶</a>
          <form method="POST" style="margin:0;">
            <input type="hidden" name="action" value="delete_named_bg">
            <input type="hidden" name="theme" value="<?= $key ?>">
            <input type="hidden" name="bg_index" value="<?= $i ?>">
            <button type="submit" class="btn btn-danger btn-sm" style="padding:2px 7px;font-size:11px;" title="Delete this background">🗑</button>
          </form>
        </div>
        <?php endforeach; ?>
      </div>
      <?php else: ?>
      <div style="font-size:12px;color:rgba(255,255,255,.25);margin-bottom:10px;padding:8px;background:rgba(255,255,255,.03);border-radius:6px;border:1px dashed rgba(255,255,255,.1);">No custom backgrounds yet. Add one below.</div>
      <?php endif; ?>

      <details style="margin-bottom:8px;">
        <summary style="cursor:pointer;font-size:12px;color:rgba(255,255,255,.6);padding:4px 0;user-select:none;">➕ Add Background by URL</summary>
        <form method="POST" style="margin-top:8px;" id="bg-form-<?= $key ?>">
          <input type="hidden" name="action" value="save_bg">
          <input type="hidden" name="theme"  value="<?= $key ?>">
          <div class="row2">
            <div>
              <label style="margin-top:0;">Name</label>
              <input type="text" name="bg_name" placeholder="e.g. Sunset Video" style="width:100%;padding:5px 8px;border-radius:5px;border:1px solid rgba(255,255,255,.15);background:rgba(255,255,255,.07);color:#fff;font-size:13px;">
            </div>
            <div>
              <label style="margin-top:0;">Type</label>
              <select name="bg_type" id="bg-type-<?= $key ?>" onchange="bgTypeChange(this)">
                <option value="video_url">🎬 Video URL (.mp4/.webm)</option>
                <option value="image_url">🖼 Image URL (.jpg/.png)</option>
                <option value="iframe_url">🌐 Web Page / Animated CSS (iframe)</option>
              </select>
            </div>
            <div>
              <label style="margin-top:0;">URL</label>
              <input type="url" class="url-input" id="bg-url-<?= $key ?>" name="url" placeholder="https://...">
            </div>
            <button type="submit" class="btn btn-primary btn-sm" style="margin-top:auto;">➕ Add</button>
          </div>
        </form>
      </details>

      <details>
        <summary style="cursor:pointer;font-size:12px;color:rgba(255,255,255,.6);padding:4px 0;user-select:none;">📁 Upload File (Video/Image)</summary>
        <form method="POST" enctype="multipart/form-data" style="margin-top:8px;">
          <input type="hidden" name="action" value="upload_bg">
          <input type="hidden" name="theme"  value="<?= $key ?>">
          <div class="row2" style="align-items:center;">
            <input type="text" name="bg_name" placeholder="Background name (optional)" style="padding:5px 8px;border-radius:5px;border:1px solid rgba(255,255,255,.15);background:rgba(255,255,255,.07);color:#fff;font-size:13px;">
            <select name="upload_type" style="max-width:140px;">
              <option value="video">🎬 Upload Video</option>
              <option value="image">🖼 Upload Image</option>
            </select>
            <label class="upload-label">📁 Choose File
              <input type="file" name="file" accept="video/*,image/jpeg,image/png,image/gif,image/webp" style="display:none" onchange="this.form.submit()">
            </label>
          </div>
        </form>
      </details>
    </div>
    <?php endforeach; ?>
  </div>

  <div class="section">
    <h2>📁 Uploaded Files</h2>
    <?php
    foreach (['videos' => '🎬', 'uploads' => '🖼'] as $subdir => $ico) {
        $dir = __DIR__ . "/$subdir/";
        if (!is_dir($dir)) { echo "<p style='color:rgba(255,255,255,0.3);font-size:13px;'>No $subdir yet.</p>"; continue; }
        $files = glob($dir . '*') ?: [];
        if (!$files) { echo "<p style='color:rgba(255,255,255,0.3);font-size:13px;'>No $subdir yet.</p>"; continue; }
        foreach ($files as $f) {
            $fname = basename($f);
            $size  = round(filesize($f) / 1048576, 1);
            echo "<div style='display:flex;align-items:center;gap:10px;padding:8px 0;border-bottom:1px solid rgba(255,255,255,0.05);font-size:13px;'>";
            echo "$ico $fname <span style='color:rgba(255,255,255,0.3);font-size:11px;'>{$size} MB</span>";
            echo "<a href='dash/$subdir/$fname' target='_blank' style='color:#4a9eff;font-size:11px;'>▶ Preview</a>";
            echo "</div>";
        }
    }
    ?>
  </div>
</div>

<!-- ===== THEMES VISIBILITY ===== -->
<div id="tab-themes" class="tab-content">
  <div class="section">
    <h2>🎭 Theme Visibility</h2>
    <p style="font-size:12px;color:rgba(255,255,255,.4);margin-bottom:16px;">Hidden themes are removed from the theme dropdown on the dashboard. You can still unhide them here anytime.</p>
    <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(240px,1fr));gap:10px;">
      <?php foreach ($themes as $key => $label):
        if (in_array($key, $theme_variants_only)) continue;
        $hidden = in_array($key, $hidden_themes);
        $hasBg  = !empty($bgs[$key]);
      ?>
      <div style="display:flex;align-items:center;gap:8px;background:rgba(255,255,255,<?= $hidden?'.03':'.06' ?>);border:1px solid rgba(255,255,255,<?= $hidden?'.05':'.12' ?>);border-radius:10px;padding:10px 12px;">
        <span style="font-size:18px;flex-shrink:0;"><?= mb_substr($label,0,2) ?></span>
        <span style="flex:1;font-size:13px;<?= $hidden?'opacity:.4;text-decoration:line-through;':'' ?>"><?= htmlspecialchars(preg_replace('/^\S+\s*/u','',$label)) ?></span>
        <?php if ($hasBg): ?><span title="Has custom backgrounds" style="font-size:11px;opacity:.5;">🎬</span><?php endif; ?>
        <button class="btn btn-sm btn-secondary" style="padding:4px 8px;font-size:11px;" onclick="toggleThemeEdit('<?= $key ?>')" id="edit-btn-<?= $key ?>">✏️ Edit</button>
        <form method="POST" style="margin:0;">
          <input type="hidden" name="action" value="toggle_hidden_theme">
          <input type="hidden" name="theme" value="<?= htmlspecialchars($key) ?>">
          <button type="submit" class="btn btn-sm <?= $hidden?'btn-primary':'btn-danger' ?>" style="padding:4px 10px;font-size:11px;">
            <?= $hidden ? '👁 Show' : '🙈 Hide' ?>
          </button>
        </form>
      </div>
      <?php endforeach; ?>
    </div>

    <!-- Inline theme background editor panel -->
    <div id="theme-inline-edit" style="display:none;margin-top:16px;background:rgba(255,255,255,.05);border:1px solid rgba(255,255,255,.12);border-radius:12px;padding:20px;">
      <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:16px;">
        <h3 id="tie-title" style="margin:0;font-size:15px;font-weight:600;">Edit Theme Backgrounds</h3>
        <button onclick="closeThemeEdit()" style="background:rgba(255,255,255,.1);border:1px solid rgba(255,255,255,.15);color:#fff;padding:5px 14px;border-radius:7px;cursor:pointer;font-size:13px;">✕ Close</button>
      </div>
      <div id="tie-body"></div>
    </div>
  </div>
</div>

<!-- ===== CUSTOM THEME ===== -->
<div id="tab-customtheme" class="tab-content">
  <div class="section">
    <h2>🎨 Custom Theme Creator</h2>
    <p style="font-size:12px;color:rgba(255,255,255,0.4);margin-bottom:16px;">Design your own theme. These CSS variables will be applied when you select "🎨 Custom Theme" from the theme menu. Changes are saved server-side and also synced to localStorage.</p>

    <div class="grid-row">
      <div>
        <label>Background Color</label>
        <input type="color" id="ct-bg"            value="<?= $custom_theme['bg']??'#0a2040' ?>">
        <label>Card Background</label>
        <input type="color" id="ct-card-bg"       value="<?= $custom_theme['card_bg']??'#1a3a6a' ?>">
        <label>Card Border Light</label>
        <input type="color" id="ct-border-light"  value="<?= $custom_theme['border_light']??'#4a8adf' ?>">
        <label>Card Border Dark</label>
        <input type="color" id="ct-border-dark"   value="<?= $custom_theme['border_dark']??'#0a1a40' ?>">
        <label>Card Text Color</label>
        <input type="color" id="ct-card-text"     value="<?= $custom_theme['card_text']??'#ffffff' ?>">
      </div>
      <div>
        <label>Hover Background</label>
        <input type="color" id="ct-hover-bg"      value="<?= $custom_theme['hover_bg']??'#2a5aaf' ?>">
        <label>Hover Text</label>
        <input type="color" id="ct-hover-text"    value="<?= $custom_theme['hover_text']??'#ffffff' ?>">
        <label>Section Title Bg (start)</label>
        <input type="color" id="ct-sec-from"      value="<?= $custom_theme['sec_from']??'#0a3080' ?>">
        <label>Section Title Bg (end)</label>
        <input type="color" id="ct-sec-to"        value="<?= $custom_theme['sec_to']??'#1060d0' ?>">
        <label>Section Title Text</label>
        <input type="color" id="ct-sec-text"      value="<?= $custom_theme['sec_text']??'#ffffff' ?>">
      </div>
    </div>

    <div style="margin-top:16px;" class="grid-row">
      <div>
        <label>Card Border Radius (px)</label>
        <input type="number" id="ct-radius" min="0" max="30" value="<?= $custom_theme['radius']??'4' ?>" style="width:80px;">
      </div>
      <div>
        <label>Font</label>
        <select id="ct-font">
          <?php $fonts=['Arial, sans-serif'=>'Arial','Tahoma, sans-serif'=>'Tahoma',"'Courier New', monospace"=>'Courier New',"'Lucida Grande', sans-serif"=>'Lucida Grande',"'Segoe UI', sans-serif"=>'Segoe UI','Georgia, serif'=>'Georgia'];
          $cur_font = $custom_theme['font'] ?? 'Arial, sans-serif';
          foreach($fonts as $val=>$lbl) echo "<option value=\"$val\"".($cur_font===$val?' selected':'').">$lbl</option>"; ?>
        </select>
      </div>
    </div>

    <div style="margin-top:16px;">
      <h4 style="font-size:13px;margin-bottom:10px;">Animated Wallpaper</h4>
      <select id="ct-wallpaper">
        <option value="none">None (solid color)</option>
        <option value="teal">Teal dots</option>
        <option value="circles">Red circles</option>
        <option value="purple">Purple flow</option>
        <option value="navy">Navy dots</option>
        <option value="forest">Forest</option>
        <option value="sandstone">Sandstone</option>
        <option value="bricks">Bricks</option>
        <option value="clouds">Clouds</option>
        <option value="metal">Metal</option>
      </select>
    </div>

    <div class="custom-theme-preview" id="ct-preview">
      <div style="font-size:12px;margin-bottom:8px;opacity:0.6;">Preview</div>
      <div class="preview-card" id="ct-prev-card">🖥 Sample Link</div>
      <div class="preview-card" id="ct-prev-card2">🔐 Another Link</div>
    </div>

    <div style="margin-top:16px;display:flex;gap:8px;flex-wrap:wrap;">
      <button class="btn btn-secondary" onclick="previewCustomTheme()">👁 Preview</button>
      <button class="btn btn-primary"   onclick="saveCustomTheme()">💾 Save Custom Theme</button>
      <button class="btn btn-secondary" onclick="applyTheme('custom')">✨ Apply Now</button>
    </div>
  </div>
</div>

<!-- ===== LINKS ===== -->
<div id="tab-links" class="tab-content">
  <!-- ADD NEW COLUMN -->
  <div class="section" style="margin-bottom:16px;">
    <h2>📁 Add New Column</h2>
    <p style="font-size:12px;color:rgba(255,255,255,.45);margin-bottom:14px;">Create an empty column on the dashboard. After saving, go to the dashboard and click <strong>✏️ Edit</strong> to add links, drag it into position, and rename it.</p>
    <form method="POST">
      <input type="hidden" name="action" value="add_column">
      <div class="grid-row">
        <div>
          <label>Icon (emoji)</label>
          <input type="text" name="col_icon" value="📌" style="width:60px;">
        </div>
        <div>
          <label>Column Name</label>
          <input type="text" name="col_title" placeholder="e.g. My Servers, Tools, Work" required>
        </div>
      </div>
      <div style="margin-top:14px;display:flex;align-items:center;gap:12px;flex-wrap:wrap;">
        <button type="submit" class="btn btn-primary">➕ Create Column</button>
        <span style="font-size:11px;opacity:.4;">→ Then open the dashboard in Edit Mode to add links</span>
      </div>
    </form>
    <div id="sites-list-inline" style="margin-top:10px;"></div>
    <div style="margin-top:12px;display:flex;align-items:center;gap:8px;flex-wrap:wrap;">
      <span style="font-size:11px;opacity:.4;">Auto-detect local sites from Apache/Nginx:</span>
      <button type="button" class="btn btn-secondary btn-sm" onclick="loadSitesInline()">🔍 Detect Sites</button>
    </div>
  </div>

  <!-- IMPORT CHROME BOOKMARKS -->
  <div class="section" style="margin-bottom:16px;">
    <h2>📥 Import Chrome Bookmarks</h2>
    <p style="font-size:12px;color:rgba(255,255,255,.45);margin-bottom:14px;">
      In Chrome go to <strong>Bookmarks Manager → ⋮ menu → Export bookmarks</strong> to save an HTML file, then upload it here.<br>
      Bookmark folders become separate columns. Choose which bookmarks to import and where to put them.
    </p>
    <label class="upload-label" style="display:inline-block;">
      📂 Choose Bookmark HTML File
      <input type="file" accept=".html,.htm" style="display:none;" onchange="parseBookmarkFile(this)">
    </label>
    <div id="bm-status" style="font-size:12px;color:rgba(255,255,255,.5);margin-top:8px;"></div>

    <div id="bm-panel" style="display:none;margin-top:14px;">
      <div style="display:flex;align-items:center;gap:10px;margin-bottom:10px;flex-wrap:wrap;">
        <strong style="font-size:13px;">Select bookmarks &amp; choose destination columns:</strong>
        <button class="btn btn-secondary btn-sm" onclick="bmCheckAll(true)">✅ All</button>
        <button class="btn btn-secondary btn-sm" onclick="bmCheckAll(false)">⬜ None</button>
      </div>
      <div id="bm-tree" style="max-height:380px;overflow-y:auto;background:rgba(0,0,0,.25);border-radius:10px;padding:10px 14px;"></div>
      <div style="margin-top:14px;display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
        <button class="btn btn-primary" onclick="submitBookmarks()">📥 Import Selected Bookmarks</button>
        <button class="btn btn-secondary" onclick="document.getElementById('bm-panel').style.display='none'">Cancel</button>
        <span id="bm-import-msg" style="font-size:12px;color:#00e676;"></span>
      </div>
    </div>
  </div>
  <div class="section">
    <h2>🔗 Manage Links</h2>
    <p style="font-size:12px;color:rgba(255,255,255,.4);margin-bottom:14px;">Edit icons, move links between columns, or delete them. Drag-and-drop column reordering is on the dashboard in Edit Mode.</p>
    <div class="links-list">
      <?php foreach ($links as $si => $sec): $secIdVal = htmlspecialchars(addslashes($sec['id']??$sec['title']??'')); ?>
      <div class="link-sec" data-sec="<?= htmlspecialchars($sec['title']??$sec['id']??'') ?>">
        <h4 style="display:flex;align-items:center;gap:8px;"><?= htmlspecialchars($sec['icon']??'') ?> <?= htmlspecialchars($sec['title']) ?> <span style="font-size:10px;color:rgba(255,255,255,.3);"><?= count($sec['cards']??[]) ?> links</span>
          <button class="btn btn-danger btn-sm" style="margin-left:auto;font-size:11px;padding:2px 8px;" onclick="deleteColumn('<?= $secIdVal ?>')">🗑 Delete Column</button>
        </h4>
        <?php foreach ($sec['cards'] ?? [] as $card): ?>
        <div class="link-card" data-url="<?= htmlspecialchars($card['url']) ?>">
          <div class="link-icon-wrap">
            <?php if (!empty($card['icon_img'])): ?>
            <img src="<?= htmlspecialchars($card['icon_img']) ?>" style="width:24px;height:24px;border-radius:50%;object-fit:cover;" alt="">
            <?php else: ?>
            <span style="font-size:18px;"><?= htmlspecialchars($card['icon']??'🔗') ?></span>
            <?php endif; ?>
          </div>
          <div style="flex:1;min-width:0;">
            <div style="font-weight:600;font-size:13px;"><?= htmlspecialchars($card['label']) ?></div>
            <a href="<?= htmlspecialchars($card['url']) ?>" target="_blank" style="color:#4a9eff;font-size:11px;word-break:break-all;"><?= htmlspecialchars($card['url']) ?></a>
          </div>
          <div style="display:flex;gap:4px;flex-shrink:0;">
            <button class="btn btn-secondary btn-sm" onclick="openIconEdit('<?= htmlspecialchars(addslashes($sec['title']??$sec['id']??'')) ?>','<?= htmlspecialchars(addslashes($card['url'])) ?>')">🖼 Icon</button>
            <button class="btn btn-secondary btn-sm" onclick="openMoveLink('<?= htmlspecialchars(addslashes($sec['title']??$sec['id']??'')) ?>','<?= htmlspecialchars(addslashes($card['url'])) ?>','<?= htmlspecialchars(addslashes($card['label'])) ?>')">↪ Move</button>
            <button class="btn btn-danger btn-sm" onclick="deleteLink('<?= htmlspecialchars(addslashes($sec['title']??$sec['id']??'')) ?>','<?= htmlspecialchars(addslashes($card['url'])) ?>')">🗑</button>
          </div>
        </div>
        <?php endforeach; ?>
      </div>
      <?php endforeach; ?>
      <?php if (empty($links)): ?>
      <p style="color:rgba(255,255,255,0.4);font-size:13px;">No links yet. Use the form above to add your first link.</p>
      <?php endif; ?>
    </div>
  </div>

  <!-- Icon Edit Modal -->
  <div id="icon-edit-modal" style="display:none;position:fixed;inset:0;z-index:9999;background:rgba(0,0,0,.8);align-items:center;justify-content:center;">
    <div style="background:#1a1a2e;border:1px solid rgba(255,255,255,.2);border-radius:14px;padding:22px;width:420px;max-width:96vw;color:#fff;">
      <h3 style="margin-bottom:14px;">🖼 Edit Icon</h3>
      <form method="POST" enctype="multipart/form-data" id="icon-edit-form">
        <input type="hidden" name="action" value="update_link_icon">
        <input type="hidden" name="sec_id" id="ie-sec">
        <input type="hidden" name="url_key" id="ie-url">
        <label style="font-size:12px;color:rgba(255,255,255,.5);">Emoji / text icon</label>
        <input type="text" name="new_icon" id="ie-icon" placeholder="🔗 or leave blank if uploading image" style="margin-bottom:10px;">
        <label style="font-size:12px;color:rgba(255,255,255,.5);">— OR upload JPG/PNG/SVG image icon —</label>
        <input type="file" name="icon_image" accept=".jpg,.jpeg,.png,.gif,.webp,.svg" style="margin:8px 0;color:#fff;">
        <div style="display:flex;gap:8px;margin-top:14px;">
          <button type="submit" class="btn btn-primary">💾 Save</button>
          <button type="button" class="btn btn-secondary" onclick="document.getElementById('icon-edit-modal').style.display='none'">Cancel</button>
        </div>
      </form>
    </div>
  </div>

  <!-- Move Link Modal -->
  <div id="move-link-modal" style="display:none;position:fixed;inset:0;z-index:9999;background:rgba(0,0,0,.8);align-items:center;justify-content:center;">
    <div style="background:#1a1a2e;border:1px solid rgba(255,255,255,.2);border-radius:14px;padding:22px;width:380px;max-width:96vw;color:#fff;">
      <h3 style="margin-bottom:14px;">↪ Move "<span id="ml-label"></span>"</h3>
      <form method="POST" id="move-link-form">
        <input type="hidden" name="action" value="move_link">
        <input type="hidden" name="from_sec" id="ml-from">
        <input type="hidden" name="url_key" id="ml-url">
        <label style="font-size:12px;color:rgba(255,255,255,.5);">Move to column / section</label>
        <select name="to_sec" style="margin-bottom:14px;">
          <?php foreach ($links as $sec): ?>
          <option value="<?= htmlspecialchars($sec['title']??$sec['id']??'') ?>"><?= htmlspecialchars($sec['title']) ?></option>
          <?php endforeach; ?>
        </select>
        <div style="display:flex;gap:8px;">
          <button type="submit" class="btn btn-primary">Move</button>
          <button type="button" class="btn btn-secondary" onclick="document.getElementById('move-link-modal').style.display='none'">Cancel</button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- ===== WIDGETS ===== -->
<div id="tab-widgets" class="tab-content">

  <!-- Widget visibility toggles -->
  <div class="section" style="margin-bottom:16px;">
    <h2>📊 Widget Visibility</h2>
    <p style="font-size:12px;color:rgba(255,255,255,.45);margin-bottom:14px;">Toggle which monitoring and utility widgets show on the dashboard. Changes take effect the next time the dashboard is loaded.</p>
    <form method="POST">
      <input type="hidden" name="action" value="save_widget_settings">
      <div style="display:flex;flex-wrap:wrap;gap:20px;margin-bottom:18px;">
        <?php
        $widgetDefs = ['cpu'=>'⚡ CPU Monitor','ram'=>'🧠 RAM Monitor','storage'=>'💾 Storage Drives','clock'=>'🕐 Clock Widget','weather'=>'🌤 Weather Widget'];
        foreach ($widgetDefs as $wk => $wl): ?>
        <label style="display:flex;align-items:center;gap:8px;cursor:pointer;font-size:14px;color:#fff;margin:0;user-select:none;">
          <input type="checkbox" name="widget_<?= $wk ?>" value="1" <?= ($monitor[$wk]??true)?'checked':'' ?>
                 style="width:auto;height:16px;accent-color:#4a9eff;">
          <?= $wl ?>
        </label>
        <?php endforeach; ?>
      </div>
      <button type="submit" class="btn btn-primary">💾 Save Widget Settings</button>
    </form>
  </div>

  <!-- Custom HTML Widgets -->
  <div class="section" style="margin-bottom:16px;">
    <h2>🧩 Add Custom HTML Widget</h2>
    <p style="font-size:12px;color:rgba(255,255,255,.45);margin-bottom:14px;">
      Paste any HTML embed code (from <a href="https://elfsight.com/" target="_blank" style="color:#4a9eff;">Elfsight</a>, Widgetbot, Google Maps, stock tickers, etc.) and give it a name.
      It will appear as a draggable widget on the dashboard.
    </p>
    <form method="POST">
      <input type="hidden" name="action" value="add_html_widget">
      <label>Widget Name</label>
      <input type="text" name="hw_name" placeholder="e.g. World Clock, Stock Ticker, Live Map" required>
      <label>HTML / Embed Code</label>
      <textarea name="hw_html" rows="6" placeholder="Paste the embed code here…&#10;&#10;Example:&#10;&lt;script src=&quot;https://...&quot;&gt;&lt;/script&gt;&#10;&lt;div class=&quot;elfsight-app-...&quot;&gt;&lt;/div&gt;" required style="font-family:monospace;font-size:12px;resize:vertical;"></textarea>
      <div style="margin-top:14px;">
        <button type="submit" class="btn btn-primary">➕ Add Widget to Dashboard</button>
      </div>
    </form>
  </div>

  <!-- Existing custom HTML widgets list -->
  <?php if (!empty($html_widgets)): ?>
  <div class="section">
    <h2>🧩 Your Custom Widgets</h2>
    <p style="font-size:12px;color:rgba(255,255,255,.45);margin-bottom:12px;">These widgets appear on the dashboard as draggable panels. Delete any you no longer want.</p>
    <?php foreach ($html_widgets as $hw): ?>
    <div style="display:flex;align-items:center;gap:12px;padding:10px 0;border-bottom:1px solid rgba(255,255,255,.06);">
      <div style="flex:0 0 24px;font-size:20px;">🧩</div>
      <div style="flex:1;min-width:0;">
        <div style="font-weight:600;font-size:13px;margin-bottom:2px;"><?= htmlspecialchars($hw['name']) ?></div>
        <div style="font-size:10px;opacity:.4;font-family:monospace;overflow:hidden;white-space:nowrap;text-overflow:ellipsis;"><?= htmlspecialchars(substr($hw['html'],0,90)) ?>…</div>
      </div>
      <form method="POST" style="margin:0;flex-shrink:0;" onsubmit="return confirm('Delete widget \'<?= htmlspecialchars(addslashes($hw['name'])) ?>\'?')">
        <input type="hidden" name="action" value="delete_html_widget">
        <input type="hidden" name="hw_id" value="<?= htmlspecialchars($hw['id']) ?>">
        <button type="submit" class="btn btn-danger btn-sm">🗑 Delete</button>
      </form>
    </div>
    <?php endforeach; ?>
  </div>
  <?php else: ?>
  <div class="section" style="text-align:center;padding:30px;">
    <div style="font-size:32px;margin-bottom:8px;">🧩</div>
    <div style="opacity:.4;font-size:13px;">No custom widgets yet. Add one above.</div>
  </div>
  <?php endif; ?>
</div>

<!-- ===== PASSWORD ===== -->
<div id="tab-password" class="tab-content">
  <div class="section">
    <h2>🔐 Change Password</h2>
    <form method="POST">
      <input type="hidden" name="action" value="change_password">
      <label>New Password</label>
      <input type="password" name="new_password" placeholder="At least 6 characters" minlength="6">
      <label>Confirm Password</label>
      <input type="password" name="confirm_password" placeholder="Repeat password">
      <div style="margin-top:16px;"><button type="submit" class="btn btn-primary">🔐 Update Password</button></div>
    </form>
    <p style="font-size:11px;color:rgba(255,255,255,0.3);margin-top:12px;">Username: <strong><?= htmlspecialchars($cfg['username']) ?></strong></p>
  </div>
</div>

<!-- ===== USERS ===== -->
<div id="tab-users" class="tab-content">
  <div class="section">
    <h2>👥 User Management</h2>
    <p style="font-size:12px;color:rgba(255,255,255,.55);margin-bottom:14px;">
      Users can log in and view the dashboard. Each user gets their own link list stored separately.<br>
      <strong>Admin</strong> (you) can access Options; other users cannot.
    </p>

    <!-- Current admin -->
    <div style="background:rgba(255,255,255,.05);border-radius:6px;padding:10px 14px;margin-bottom:18px;display:flex;align-items:center;gap:10px;">
      <span style="font-size:20px;">🛡</span>
      <div>
        <strong><?= htmlspecialchars($cfg['username']) ?></strong>
        <span style="font-size:11px;margin-left:6px;background:#3a6;color:#fff;padding:2px 6px;border-radius:10px;">Admin</span>
      </div>
      <span style="margin-left:auto;font-size:11px;color:rgba(255,255,255,.35);">Change password in the Password tab</span>
    </div>

    <!-- Sub-user list -->
    <?php if (empty($sub_users)): ?>
      <p style="font-size:12px;color:rgba(255,255,255,.35);margin-bottom:16px;">No users yet. Use the form below to add editors or read-only viewers.</p>
    <?php else: ?>
      <table style="width:100%;border-collapse:collapse;margin-bottom:18px;font-size:13px;">
        <thead>
          <tr style="color:rgba(255,255,255,.5);border-bottom:1px solid rgba(255,255,255,.1);">
            <th style="text-align:left;padding:5px 8px;">Username</th>
            <th style="text-align:left;padding:5px 8px;">Role</th>
            <th style="text-align:left;padding:5px 8px;">Link File</th>
            <th style="text-align:right;padding:5px 8px;">Actions</th>
          </tr>
        </thead>
        <tbody>
        <?php foreach ($sub_users as $su): ?>
          <tr style="border-bottom:1px solid rgba(255,255,255,.06);">
            <td style="padding:6px 8px;"><strong><?= htmlspecialchars($su['username']) ?></strong></td>
            <td style="padding:6px 8px;">
              <span style="font-size:11px;background:<?= ($su['role']??'user')==='readonly'?'#555':'#256' ?>;color:#fff;padding:2px 7px;border-radius:10px;">
                <?= ($su['role']??'user')==='readonly' ? '👁 Read-only' : '✏️ Editor' ?>
              </span>
            </td>
            <td style="padding:6px 8px;font-size:11px;color:rgba(255,255,255,.4);">
              dash_links_<?= htmlspecialchars($su['username']) ?>.json
            </td>
            <td style="padding:6px 8px;text-align:right;display:flex;gap:6px;justify-content:flex-end;">
              <button class="btn btn-secondary btn-sm"
                onclick="showResetPw('<?= htmlspecialchars(addslashes($su['username'])) ?>')">🔑 Reset PW</button>
              <form method="POST" style="display:inline;" onsubmit="return confirm('Delete user <?= htmlspecialchars($su['username']) ?>?')">
                <input type="hidden" name="action" value="delete_user">
                <input type="hidden" name="del_username" value="<?= htmlspecialchars($su['username']) ?>">
                <button type="submit" class="btn btn-danger btn-sm">🗑 Delete</button>
              </form>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>

    <!-- Reset password inline form (hidden until button clicked) -->
    <div id="reset-pw-box" style="display:none;background:rgba(255,255,255,.06);border-radius:6px;padding:14px;margin-bottom:18px;">
      <strong id="reset-pw-label">Reset password for: </strong>
      <form method="POST" style="margin-top:10px;">
        <input type="hidden" name="action" value="reset_user_password">
        <input type="hidden" name="reset_username" id="reset-username-val">
        <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
          <input type="password" name="reset_password" placeholder="New password (6+ chars)" minlength="6" style="flex:1;min-width:180px;">
          <button type="submit" class="btn btn-primary btn-sm">Save</button>
          <button type="button" class="btn btn-secondary btn-sm" onclick="document.getElementById('reset-pw-box').style.display='none'">Cancel</button>
        </div>
      </form>
    </div>

    <!-- Add new user form -->
    <h3 style="font-size:14px;margin-bottom:10px;">➕ Add New User</h3>
    <form method="POST">
      <input type="hidden" name="action" value="add_user">
      <div style="display:grid;grid-template-columns:1fr 1fr auto;gap:10px;align-items:end;flex-wrap:wrap;">
        <div>
          <label style="font-size:11px;margin-bottom:3px;display:block;">Username</label>
          <input type="text" name="new_username" placeholder="e.g. alice" required minlength="2"
            style="width:100%;box-sizing:border-box;" pattern="[a-zA-Z0-9_-]+">
        </div>
        <div>
          <label style="font-size:11px;margin-bottom:3px;display:block;">Password</label>
          <input type="password" name="new_password" placeholder="At least 6 chars" required minlength="6"
            style="width:100%;box-sizing:border-box;">
        </div>
        <div>
          <label style="font-size:11px;margin-bottom:3px;display:block;">Role</label>
          <select name="new_role" style="width:100%;">
            <option value="user">✏️ Editor</option>
            <option value="readonly">👁 Read-only</option>
          </select>
        </div>
      </div>
      <div style="margin-top:12px;"><button type="submit" class="btn btn-primary">👥 Add User</button></div>
    </form>

    <div style="margin-top:20px;padding:10px 14px;background:rgba(255,255,255,.04);border-radius:6px;font-size:11px;color:rgba(255,255,255,.4);line-height:1.6;">
      <strong style="color:rgba(255,255,255,.6);">Role explanations:</strong><br>
      <strong>Editor</strong> — can add, edit, and delete their own personal links on the dashboard.<br>
      <strong>Read-only</strong> — can see the main dashboard links but cannot add or change anything.
    </div>
  </div>
</div>

<!-- ===== EXPORT ===== -->
<div id="tab-export" class="tab-content">
  <div class="section">
    <h2>📤 Export / Import Settings</h2>
    <div style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:16px;">
      <button class="btn btn-primary" onclick="exportSettings()">📥 Export JSON</button>
      <button class="btn btn-secondary" onclick="document.getElementById('import-area').style.display='block'">📤 Import JSON</button>
    </div>
    <div id="import-area" style="display:none;">
      <label>Paste JSON:</label>
      <textarea class="export-area" id="import-json" placeholder='{"theme":"win98","wall":"teal",...}'></textarea>
      <button class="btn btn-primary" onclick="importSettings()" style="margin-top:8px;">Import</button>
    </div>
  </div>
</div>

<script>
function showTab(name){
  document.querySelectorAll('.tab').forEach(t=>t.classList.remove('active'));
  document.querySelectorAll('.tab-content').forEach(t=>t.classList.remove('active'));
  const content=document.getElementById('tab-'+name);
  if(!content)return;
  content.classList.add('active');
  document.querySelectorAll('.tab').forEach(t=>{
    if((t.getAttribute('onclick')||'').includes("'"+name+"'"))t.classList.add('active');
  });
  try{localStorage.setItem('opts-active-tab',name);}catch(e){}
}
document.addEventListener('DOMContentLoaded',()=>{
  let tab='general';
  try{const s=localStorage.getItem('opts-active-tab');if(s&&document.getElementById('tab-'+s))tab=s;}catch(e){}
  const hash=(location.hash||'').replace(/^#tab-/,'');
  if(hash&&document.getElementById('tab-'+hash))tab=hash;
  showTab(tab);
});
function showResetPw(username){
  document.getElementById('reset-pw-label').textContent='Reset password for: '+username;
  document.getElementById('reset-username-val').value=username;
  document.getElementById('reset-pw-box').style.display='block';
  document.getElementById('reset-pw-box').scrollIntoView({behavior:'smooth',block:'nearest'});
}

let _driveCount = <?= count($drives) ?>;
function addDriveRow(key='',icon='💾',path='',label=''){
  const row = document.createElement('div');
  row.className='drive-row';
  row.innerHTML=`
    <input type="text" name="drive_key[]"   value="${key}"  placeholder="key">
    <input type="text" name="drive_icon[]"  value="${icon}" style="width:38px;text-align:center;padding:4px;">
    <input type="text" name="drive_path[]"  value="${path}" placeholder="/media/server/drive">
    <input type="text" name="drive_label[]" value="${label}" placeholder="Label">
    <button type="button" class="btn btn-danger btn-sm" onclick="this.closest('.drive-row').remove()">🗑</button>
  `;
  document.getElementById('drives-list').appendChild(row);
}

let _detectedDrives=[];
async function validateAndAddPath(){
  const pathInp=document.getElementById('manual-path-input');
  const lblInp=document.getElementById('manual-path-label');
  const res=document.getElementById('manual-path-result');
  const btn=document.getElementById('validate-path-btn');
  const path=(pathInp?.value||'').trim();
  if(!path){pathInp?.focus();return;}
  btn.disabled=true;btn.textContent='Checking…';res.textContent='';
  try{
    const r=await fetch('scan_drives.php?action=validate&path='+encodeURIComponent(path));
    const d=await r.json();
    if(!d.ok){res.innerHTML='<span style="color:#ff8080;">❌ '+d.error+'</span>';return;}
    const dr=d.drive;
    const label=(lblInp?.value||'').trim()||dr.label;
    res.innerHTML='<span style="color:#4ade80;">✅ Found: <strong>'+label+'</strong> — '+dr.free_gb+'GB free of '+dr.total_gb+'GB ('+dr.used_pct+'% used)</span>';
    addDriveRow(dr.key,'💾',dr.path,label);
    pathInp.value='';lblInp.value='';
  }catch(e){res.innerHTML='<span style="color:#ff8080;">❌ '+e.message+'</span>';}
  finally{btn.disabled=false;btn.textContent='✅ Validate & Add';}
}
async function detectDrives(){
  const btn=document.getElementById('detect-btn');
  btn.textContent='🔍 Scanning…';btn.disabled=true;
  try{
    const r=await fetch('scan_drives.php');
    const d=await r.json();
    if(!d.ok) throw new Error(d.error||'Failed');
    _detectedDrives=d.drives||[];
    document.getElementById('drive-detect-os').textContent=d.os||'Unknown OS';
    const list=document.getElementById('drive-detect-list');
    list.innerHTML='';
    if(!_detectedDrives.length){
      list.innerHTML='<div style="font-size:12px;color:rgba(255,255,255,.4);">No drives found at common paths.</div>';
    } else {
      _detectedDrives.forEach((dr,i)=>{
        const id='dd-'+i;
        const freeStr=dr.free_gb+' GB free of '+dr.total_gb+' GB ('+dr.used_pct+'% used)';
        const bar=Math.max(2,Math.min(98,dr.used_pct));
        list.innerHTML+=`<label for="${id}" style="display:flex;align-items:center;gap:10px;padding:7px 8px;border-radius:6px;cursor:pointer;background:rgba(255,255,255,.04);user-select:none;">
          <input type="checkbox" id="${id}" data-idx="${i}" style="width:15px;height:15px;cursor:pointer;">
          <span style="flex:1;">
            <span style="font-size:12px;font-weight:bold;color:#fff;">${dr.label}</span>
            <span style="font-size:11px;color:rgba(255,255,255,.4);margin-left:8px;">${dr.path}</span><br>
            <span style="font-size:11px;color:rgba(255,255,255,.35);">${freeStr}</span>
            <div style="height:4px;background:rgba(255,255,255,.1);border-radius:2px;margin-top:4px;"><div style="width:${bar}%;height:4px;background:${bar>85?'#ef4444':bar>65?'#f59e0b':'#22c55e'};border-radius:2px;"></div></div>
          </span>
        </label>`;
      });
    }
    document.getElementById('drive-detect-box').style.display='';
  }catch(e){ alert('Scan error: '+e.message); }
  finally{ btn.textContent='🔍 Auto-detect'; btn.disabled=false; }
}

function addDetectedDrives(){
  const checkboxes=document.querySelectorAll('#drive-detect-list input[type=checkbox]:checked');
  let added=0;
  checkboxes.forEach(cb=>{
    const dr=_detectedDrives[parseInt(cb.dataset.idx)];
    if(!dr)return;
    addDriveRow(dr.key,'💾',dr.path,dr.label);
    cb.checked=false;
    added++;
  });
  if(!added){ alert('Check at least one drive first.'); return; }
  document.getElementById('drive-detect-box').style.display='none';
  alert(added+' drive'+(added!==1?'s':'')+' added. Click 💾 Save Drives to keep them.');
}

async function fetchStats(){
  try{
    const r=await fetch('stats.php');
    const d=await r.json();
    document.getElementById('stats-out').textContent=JSON.stringify(d,null,2);
  }catch(e){ document.getElementById('stats-out').textContent='Error: '+e.message; }
}
if(document.getElementById('stats-out')) fetchStats();

async function loadSitesInline(){
  const el=document.getElementById('sites-list-inline');
  if(!el)return;
  el.innerHTML='<p style="color:#4a9eff;font-size:13px;">Scanning…</p>';
  try{
    const r=await fetch('sites.php');
    const d=await r.json();
    if(!d.sites || !d.sites.length){
      el.innerHTML='<p style="color:rgba(255,255,255,0.4);font-size:13px;">No sites detected. Check Apache/Nginx config permissions.</p>';
      return;
    }
    let html='';
    d.sites.forEach(s=>{
      html+=`<div class="site-row">
        <span class="site-name">${s.name}</span>
        <span class="site-badge">${s.server} :${s.port}</span>
        <a href="${s.url}" target="_blank" class="site-url">${s.url}</a>
        <button class="btn btn-secondary btn-sm" onclick="autoAddSiteInline('${s.name.replace(/'/g,"\\'")}','${s.url.replace(/'/g,"\\'")}')">+ Fill form</button>
      </div>`;
    });
    el.innerHTML=html;
  }catch(e){ el.innerHTML='<p style="color:#ff6060;font-size:13px;">Error: '+e.message+'</p>'; }
}
// Keep old name as alias in case referenced from elsewhere
async function loadSites(){return loadSitesInline();}

// Auto-detect fills in the column name only (label/URL are now on dashboard)
function autoAddSiteInline(name, url){
  // Just fill the column name field since we removed label/URL from options
  const colInput=document.querySelector('#tab-links [name=col_title]');
  if(colInput && !colInput.value) colInput.value='Detected Sites';
  // Open a prompt so the user can go to dashboard to add the link
  document.getElementById('sites-list-inline').insertAdjacentHTML('beforeend',
    `<div style="font-size:12px;color:#4a9eff;margin-top:4px;">Tip: Copy this URL for use on the dashboard — <code style="color:#fff;">${url}</code></div>`);
}
function autoAddSite(name,url){autoAddSiteInline(name,url);}

// ===== BOOKMARK IMPORT =====
let _bmParsed = []; // array of {folder, label, url, checked}

function parseBookmarkFile(input) {
  const file = input.files[0]; if (!file) return;
  const status = document.getElementById('bm-status');
  status.textContent = 'Parsing ' + file.name + '…';
  const reader = new FileReader();
  reader.onload = function(e) {
    try {
      const html = e.target.result;
      _bmParsed = parseNetscapeBookmarks(html);
      if (!_bmParsed.length) { status.textContent = 'No bookmarks found in file.'; return; }
      status.textContent = 'Found ' + _bmParsed.length + ' bookmark(s). Select what to import:';
      renderBmTree();
      document.getElementById('bm-panel').style.display = 'block';
    } catch(err) {
      status.textContent = 'Error: ' + err.message;
    }
  };
  reader.readAsText(file);
}

function parseNetscapeBookmarks(html) {
  const parser = new DOMParser();
  const doc = parser.parseFromString(html, 'text/html');
  const items = [];
  function walkDL(dl, folderName) {
    const children = Array.from(dl.children);
    let i = 0;
    while (i < children.length) {
      const dt = children[i];
      if (dt.tagName === 'DT') {
        const h3 = dt.querySelector(':scope > H3');
        if (h3) {
          // This DT contains a folder — find its DL sibling
          const nextDL = (i + 1 < children.length && children[i+1].tagName === 'DL')
            ? children[i+1]
            : dt.querySelector('DL');
          const fName = h3.textContent.trim() || folderName;
          if (nextDL) walkDL(nextDL, fName);
        }
        const a = dt.querySelector(':scope > A');
        if (a && a.href) {
          items.push({ folder: folderName || 'Bookmarks', label: a.textContent.trim() || a.href, url: a.href, checked: true });
        }
      }
      i++;
    }
  }
  // Find top-level DL
  const topDL = doc.querySelector('DL');
  if (topDL) walkDL(topDL, 'Bookmarks');
  return items;
}

function renderBmTree() {
  // Group by folder
  const folders = {};
  _bmParsed.forEach((item, idx) => {
    if (!folders[item.folder]) folders[item.folder] = [];
    folders[item.folder].push({...item, idx});
  });

  // Get existing columns for the select
  const existingCols = <?= json_encode(array_values(array_map(fn($s)=>$s['title']??'',$links))) ?>;
  const colOptions = existingCols.map(c => `<option value="${escHtml(c)}">${escHtml(c)}</option>`).join('');

  let html = '';
  Object.keys(folders).forEach(folder => {
    const items = folders[folder];
    const folderId = 'bmf-' + folder.replace(/\W/g,'_');
    const newOpt = `<option value="__new__:${escHtml(folder)}" selected>📁 New column: "${escHtml(folder)}"</option>`;
    html += `
      <div style="margin-bottom:14px;">
        <div style="display:flex;align-items:center;gap:10px;margin-bottom:6px;flex-wrap:wrap;">
          <label style="display:flex;align-items:center;gap:5px;cursor:pointer;font-size:13px;font-weight:600;margin:0;color:#fff;">
            <input type="checkbox" checked onchange="bmFolderToggle('${folderId}',this.checked)" style="width:auto;"> 📁 ${escHtml(folder)}
          </label>
          <span style="font-size:11px;opacity:.5;">${items.length} links</span>
          <span style="font-size:11px;opacity:.5;">→ put into:</span>
          <select id="dest-${folderId}" style="font-size:12px;padding:3px 6px;background:#1a1a2e;border:1px solid rgba(255,255,255,.2);border-radius:5px;color:#fff;max-width:220px;">
            ${newOpt}
            ${colOptions}
          </select>
        </div>
        <div id="${folderId}" style="padding-left:18px;display:flex;flex-direction:column;gap:3px;">
          ${items.map(item => `
            <label style="display:flex;align-items:center;gap:6px;cursor:pointer;font-size:12px;margin:0;color:rgba(255,255,255,.8);">
              <input type="checkbox" ${item.checked?'checked':''} data-bm-idx="${item.idx}" style="width:auto;">
              <span style="overflow:hidden;white-space:nowrap;text-overflow:ellipsis;max-width:340px;" title="${escHtml(item.url)}">${escHtml(item.label)}</span>
              <a href="${escHtml(item.url)}" target="_blank" style="color:#4a9eff;font-size:10px;flex-shrink:0;">🔗</a>
            </label>`).join('')}
        </div>
      </div>`;
  });
  document.getElementById('bm-tree').innerHTML = html;
}

function escHtml(s) {
  return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;').replace(/'/g,'&#39;');
}

function bmFolderToggle(folderId, checked) {
  document.querySelectorAll('#'+folderId+' input[type=checkbox]').forEach(cb => cb.checked = checked);
}

function bmCheckAll(state) {
  document.querySelectorAll('#bm-tree input[type=checkbox]').forEach(cb => cb.checked = state);
}

async function submitBookmarks() {
  const msg = document.getElementById('bm-import-msg');
  msg.textContent = 'Importing…';

  // Build list of selected items with their destination columns
  const selected = [];
  document.querySelectorAll('#bm-tree input[data-bm-idx]').forEach(cb => {
    if (!cb.checked) return;
    const idx = parseInt(cb.dataset.bmIdx);
    const item = _bmParsed[idx];
    // Find folder destination select
    const folderId = 'bmf-' + item.folder.replace(/\W/g,'_');
    const destSel = document.getElementById('dest-' + folderId);
    let colName = destSel ? destSel.value : item.folder;
    if (colName.startsWith('__new__:')) colName = colName.slice(8); // strip prefix
    selected.push({ column: colName, label: item.label, url: item.url });
  });

  if (!selected.length) { msg.textContent = 'Nothing selected.'; return; }

  const fd = new FormData();
  fd.append('action','import_bookmarks');
  fd.append('bookmarks_json', JSON.stringify(selected));
  try {
    const r = await fetch('options.php', {method:'POST', body:fd});
    const text = await r.text();
    if (text.includes('Imported')) {
      msg.textContent = '✅ Done! Reload the dashboard to see your bookmarks.';
      document.getElementById('bm-panel').style.display = 'none';
    } else {
      msg.textContent = 'Server error. Check the page.';
    }
  } catch(e) { msg.textContent = 'Network error: ' + e.message; }
}

function clearBg(theme){
  if(!confirm('Clear custom background for '+theme+'?')) return;
  const f=document.createElement('form');f.method='POST';
  f.innerHTML=`<input name="action" value="save_bg"><input name="theme" value="${theme}"><input name="bg_type" value="video_url"><input name="url" value="">`;
  document.body.appendChild(f);f.submit();
}
function setPresetBg(theme,type,url,name){
  const card=document.getElementById('bg-'+theme);
  // Open the "Add Background by URL" details section
  if(card){ const det=card.querySelector('details'); if(det)det.open=true; }
  const typeEl=document.getElementById('bg-type-'+theme);
  const urlEl =document.getElementById('bg-url-'+theme);
  if(typeEl)typeEl.value=type;
  if(urlEl){urlEl.value=url;}
  // Set name field if present
  const form=document.getElementById('bg-form-'+theme);
  if(form){
    const nameEl=form.querySelector('input[name="bg_name"]');
    if(nameEl && name)nameEl.value=name;
    urlEl && urlEl.scrollIntoView({behavior:'smooth',block:'center'});
    urlEl && (urlEl.style.border='2px solid #4af');
    setTimeout(()=>{if(urlEl)urlEl.style.border='';},2500);
  }
}

// ─── Custom theme ──────────────────────────────────────────────────────────
function getCustomVars(){
  return {
    bg:           document.getElementById('ct-bg').value,
    card_bg:      document.getElementById('ct-card-bg').value,
    border_light: document.getElementById('ct-border-light').value,
    border_dark:  document.getElementById('ct-border-dark').value,
    card_text:    document.getElementById('ct-card-text').value,
    hover_bg:     document.getElementById('ct-hover-bg').value,
    hover_text:   document.getElementById('ct-hover-text').value,
    sec_from:     document.getElementById('ct-sec-from').value,
    sec_to:       document.getElementById('ct-sec-to').value,
    sec_text:     document.getElementById('ct-sec-text').value,
    radius:       document.getElementById('ct-radius').value,
    font:         document.getElementById('ct-font').value,
    wallpaper:    document.getElementById('ct-wallpaper').value,
  };
}

function previewCustomTheme(){
  const v=getCustomVars();
  const prev=document.getElementById('ct-preview');
  prev.style.background=v.card_bg;
  prev.style.borderColor=v.border_light;
  ['ct-prev-card','ct-prev-card2'].forEach(id=>{
    const el=document.getElementById(id);
    el.style.background=v.card_bg;
    el.style.color=v.card_text;
    el.style.borderRadius=v.radius+'px';
    el.style.border=`1px solid ${v.border_light}`;
    el.style.fontFamily=v.font;
  });
}

function saveCustomTheme(){
  const v=getCustomVars();
  // Save to server
  const f=document.createElement('form');f.method='POST';
  f.innerHTML=`<input name="action" value="save_custom_theme"><input name="theme_json" value='${JSON.stringify(v).replace(/'/g,"\\'")}'>`;
  document.body.appendChild(f);
  // Also save to localStorage for immediate use
  localStorage.setItem('dash-custom-theme', JSON.stringify(v));
  f.submit();
}

function applyTheme(t){ window.open('index.php','_self'); }

// ─── Export/Import ─────────────────────────────────────────────────────────
function exportSettings(){
  const data={
    theme:     localStorage.getItem('hp-theme')||'win98',
    wall:      localStorage.getItem('hp-wall')||'teal',
    size:      localStorage.getItem('hp-size')||'100',
    variant:   localStorage.getItem('hp-variant')||'default',
    customTheme: JSON.parse(localStorage.getItem('dash-custom-theme')||'{}'),
    exported:  new Date().toISOString()
  };
  const blob=new Blob([JSON.stringify(data,null,2)],{type:'application/json'});
  const a=document.createElement('a');a.href=URL.createObjectURL(blob);a.download='dashboard-settings.json';a.click();
}

function importSettings(){
  try{
    const data=JSON.parse(document.getElementById('import-json').value);
    if(data.theme)   localStorage.setItem('hp-theme', data.theme);
    if(data.wall)    localStorage.setItem('hp-wall',  data.wall);
    if(data.size)    localStorage.setItem('hp-size',  data.size);
    if(data.variant) localStorage.setItem('hp-variant', data.variant);
    if(data.customTheme) localStorage.setItem('dash-custom-theme', JSON.stringify(data.customTheme));
    alert('Settings imported! Reload the dashboard to apply.');
  }catch(e){ alert('Invalid JSON: '+e.message); }
}

// ─── Background type helper ─────────────────────────────────────────────────
function bgTypeChange(sel){
  const inp=sel.closest('.row2').querySelector('.url-input');
  if(!inp) return;
  if(sel.value==='image_url') inp.placeholder='https://example.com/bg.jpg';
  else if(sel.value==='iframe_url') inp.placeholder='https://example.com/animated-page.html';
  else inp.placeholder='https://example.com/video.mp4';
}

// ─── Link management ────────────────────────────────────────────────────────
function openIconEdit(sec, url) {
  document.getElementById('ie-sec').value = sec;
  document.getElementById('ie-url').value = url;
  document.getElementById('ie-icon').value = '';
  document.getElementById('icon-edit-modal').style.display = 'flex';
}
function openMoveLink(sec, url, label) {
  document.getElementById('ml-from').value = sec;
  document.getElementById('ml-url').value = url;
  document.getElementById('ml-label').textContent = label;
  document.getElementById('move-link-modal').style.display = 'flex';
}
function deleteLink(sec, url) {
  if (!confirm('Delete this link?')) return;
  const f = document.createElement('form');
  f.method = 'POST';
  f.innerHTML = `<input name="action" value="delete_link"><input name="sec_id" value="${sec}"><input name="url_key" value="${url}">`;
  document.body.appendChild(f); f.submit();
}
function deleteColumn(secId) {
  if (!confirm('Delete this entire column and all its links?\nThis cannot be undone.')) return;
  const f = document.createElement('form');
  f.method = 'POST';
  f.innerHTML = `<input name="action" value="delete_section"><input name="sec_id" value="${secId}">`;
  document.body.appendChild(f); f.submit();
}
// Close modals on background click
document.addEventListener('click', e => {
  if (e.target.id === 'icon-edit-modal') e.target.style.display='none';
  if (e.target.id === 'move-link-modal') e.target.style.display='none';
});

// Live preview on color changes
document.querySelectorAll('input[type=color],#ct-radius,#ct-font').forEach(el=>el.addEventListener('input',previewCustomTheme));
previewCustomTheme();

// ===== THEME BACKGROUND INLINE EDITOR =====
var THEME_BGS   = <?= json_encode($bgs) ?>;
var THEME_NAMES = <?= json_encode($themes) ?>;
var _editTheme  = null;

function toggleThemeEdit(key) {
  const panel = document.getElementById('theme-inline-edit');
  if (_editTheme === key && panel.style.display !== 'none') { closeThemeEdit(); return; }
  _editTheme = key;
  document.querySelectorAll('[id^="edit-btn-"]').forEach(b => b.style.outline = '');
  const btn = document.getElementById('edit-btn-' + key);
  if (btn) btn.style.outline = '2px solid #3b82f6';
  renderThemeEdit(key);
  panel.style.display = 'block';
  panel.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
}
function closeThemeEdit() {
  _editTheme = null;
  document.getElementById('theme-inline-edit').style.display = 'none';
  document.querySelectorAll('[id^="edit-btn-"]').forEach(b => b.style.outline = '');
}
function _htmlEsc(s) {
  return String(s || '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}
function renderThemeEdit(key) {
  const bgs  = THEME_BGS[key] || [];
  const name = (THEME_NAMES[key] || key).replace(/^\S+\s*/u, '');
  document.getElementById('tie-title').textContent = '✏️ ' + name + ' — Backgrounds';
  let html = '';
  // Existing backgrounds list
  if (bgs.length === 0) {
    html += '<p style="font-size:12px;opacity:.4;margin:0 0 14px;">No custom backgrounds yet. Add one below.</p>';
  } else {
    html += '<div style="margin-bottom:16px;overflow-x:auto;"><table style="width:100%;border-collapse:collapse;font-size:12px;">';
    html += '<tr style="opacity:.5;font-size:11px;text-transform:uppercase;letter-spacing:.05em;"><th style="text-align:left;padding:4px 8px;font-weight:600;">Name</th><th style="text-align:left;padding:4px 8px;">Type</th><th style="padding:4px 8px;"></th></tr>';
    bgs.forEach((bg, i) => {
      html += `<tr style="border-top:1px solid rgba(255,255,255,.06);">
        <td style="padding:7px 8px;max-width:250px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"><span title="${_htmlEsc(bg.url)}">${_htmlEsc(bg.name || bg.url)}</span></td>
        <td style="padding:7px 8px;opacity:.5;white-space:nowrap;">${_htmlEsc(bg.type || 'url')}</td>
        <td style="padding:7px 4px;white-space:nowrap;text-align:right;">
          <a href="${_htmlEsc(bg.url)}" target="_blank" style="color:#4a9eff;font-size:11px;margin-right:10px;text-decoration:none;">▶ Preview</a>
          <button onclick="deleteBg('${key}',${i})" style="background:rgba(255,60,60,.15);border:1px solid rgba(255,60,60,.3);color:#f88;padding:2px 9px;border-radius:5px;cursor:pointer;font-size:11px;">🗑 Remove</button>
        </td>
      </tr>`;
    });
    html += '</table></div>';
  }
  // Add new background form
  html += `<div style="border-top:1px solid rgba(255,255,255,.08);padding-top:16px;">
    <h4 style="margin:0 0 12px;font-size:13px;font-weight:600;">+ Add Background</h4>
    <div style="display:flex;gap:14px;flex-wrap:wrap;margin-bottom:12px;font-size:12px;">
      <label style="display:flex;align-items:center;gap:6px;cursor:pointer;"><input type="radio" name="tie-type" value="image_upload" checked onchange="tieTypeChange(this)"> 📁 Upload Image</label>
      <label style="display:flex;align-items:center;gap:6px;cursor:pointer;"><input type="radio" name="tie-type" value="video_upload" onchange="tieTypeChange(this)"> 📤 Upload Video</label>
    </div>
    <div id="tie-file-row" style="margin-bottom:10px;">
      <label style="font-size:12px;color:rgba(255,255,255,.5);margin-bottom:4px;display:block;">Choose file to upload</label>
      <input id="tie-file" type="file" accept="image/*" style="font-size:12px;color:#ccc;display:block;margin-bottom:6px;">
      <div id="tie-progress-wrap" style="display:none;background:rgba(255,255,255,.08);border-radius:6px;overflow:hidden;height:18px;margin-bottom:6px;">
        <div id="tie-progress-bar" style="height:100%;width:0%;background:#3b82f6;transition:width .1s;border-radius:6px;"></div>
      </div>
      <div id="tie-progress-pct" style="font-size:11px;color:rgba(255,255,255,.5);display:none;"></div>
    </div>
    <div id="tie-tile-row" style="margin-bottom:10px;display:none;">
      <label style="display:flex;align-items:center;gap:8px;cursor:pointer;font-size:12px;">
        <input type="checkbox" id="tie-tile" style="width:auto;">
        <span>🪟 Tile (repeat) — otherwise stretches to fill the screen</span>
      </label>
    </div>
    <div style="margin-bottom:12px;">
      <input id="tie-name" type="text" placeholder="Display name (optional, e.g. Summer Sky)" style="width:100%;box-sizing:border-box;padding:8px 12px;background:rgba(0,0,0,.3);border:1px solid rgba(255,255,255,.15);border-radius:7px;color:#fff;font-size:13px;">
    </div>
    <div style="display:flex;align-items:center;gap:14px;">
      <button id="tie-save-btn" onclick="saveBg('${key}')" style="background:#3b82f6;border:none;color:#fff;padding:9px 22px;border-radius:7px;cursor:pointer;font-size:13px;font-weight:600;">💾 Save Background</button>
      <span id="tie-msg" style="font-size:12px;opacity:.7;"></span>
    </div>
  </div>`;
  document.getElementById('tie-body').innerHTML = html;
}
function tieTypeChange(el) {
  const isImage = el.value === 'image_upload';
  document.getElementById('tie-tile-row').style.display = isImage ? 'flex' : 'none';
  document.getElementById('tie-file').setAttribute('accept', isImage ? 'image/*' : 'video/*');
}
function saveBg(theme) {
  const typeEl = document.querySelector('input[name="tie-type"]:checked');
  if (!typeEl) return;
  const type    = typeEl.value;
  const name    = (document.getElementById('tie-name').value || '').trim() || 'Custom';
  const tileEl  = document.getElementById('tie-tile');
  const tile    = tileEl && tileEl.checked ? '1' : '0';
  const msgEl   = document.getElementById('tie-msg');
  const saveBtn = document.getElementById('tie-save-btn');
  const file    = document.getElementById('tie-file').files[0];
  if (!file) { alert('Please choose a file to upload.'); return; }
  const fd = new FormData();
  fd.append('theme', theme);
  fd.append('tile', tile);
  fd.append('action', 'upload_bg');
  fd.append('file', file);
  fd.append('upload_type', type === 'image_upload' ? 'image' : 'video');
  fd.append('bg_name', name);
  const xhr  = new XMLHttpRequest();
  const wrap = document.getElementById('tie-progress-wrap');
  const bar  = document.getElementById('tie-progress-bar');
  const pct  = document.getElementById('tie-progress-pct');
  wrap.style.display = 'block'; pct.style.display = 'block';
  if (saveBtn) saveBtn.disabled = true;
  if (msgEl) msgEl.textContent = 'Uploading…';
  xhr.upload.onprogress = e => {
    if (e.lengthComputable) {
      const p = Math.round(e.loaded / e.total * 100);
      bar.style.width = p + '%'; pct.textContent = p + '%';
    }
  };
  xhr.onload = () => {
    wrap.style.display = 'none'; pct.style.display = 'none';
    if (saveBtn) saveBtn.disabled = false;
    try {
      const json = JSON.parse(xhr.responseText);
      if (json.ok) {
        THEME_BGS[theme] = json.bgs;
        renderThemeEdit(theme);
        const newIdx = json.bgs.length - 1;
        const _sv={}; _sv['variant-'+theme]='cbg-'+newIdx;
        fetch('save_state.php', {method:'POST', headers:{'Content-Type':'application/json'}, body:JSON.stringify(_sv)}).catch(()=>{});
        const m = document.getElementById('tie-msg');
        if (m) { m.style.color='#4ade80'; m.textContent = '✓ Upload complete! Reloading…'; }
        setTimeout(() => { window.location.href = 'index.php'; }, 1200);
      } else {
        if (msgEl) { msgEl.style.color='#f87171'; msgEl.textContent = '⚠ ' + (json.error||'Error'); }
      }
    } catch(e) { if (msgEl) { msgEl.style.color='#f87171'; msgEl.textContent = '⚠ Invalid response'; } }
  };
  xhr.onerror = () => { if(saveBtn) saveBtn.disabled=false; if(msgEl){msgEl.style.color='#f87171';msgEl.textContent='⚠ Network error';} };
  xhr.open('POST', 'options.php?bgajax=1');
  xhr.send(fd);
}
async function deleteBg(theme, idx) {
  if (!confirm('Remove this background?')) return;
  const fd = new FormData();
  fd.append('action', 'delete_named_bg');
  fd.append('theme', theme);
  fd.append('bg_index', idx);
  try {
    const res  = await fetch('options.php?bgajax=1', { method: 'POST', body: fd });
    const json = await res.json();
    if (json.ok) { THEME_BGS[theme] = json.bgs; renderThemeEdit(theme); }
    else alert('Error: ' + (json.error || 'Unknown'));
  } catch (e) { alert('Network error'); }
}

// ===== STAT WIDGET VISIBILITY (General tab) =====
(function () {
  const h  = JSON.parse(localStorage.getItem('dash_hidden_stats') || '[]');
  const el = document.getElementById('stat-vis-list');
  if (!el) return;
  if (h.length === 0) {
    el.innerHTML = '<p style="font-size:12px;opacity:.4;margin:0;">All stat widgets are visible — nothing hidden.</p>';
    return;
  }
  el.innerHTML = h.map(id => {
    const label = id.replace('stat-drv-', '💾 Drive: ').replace('stat-cpu', '⚡ CPU').replace('stat-ram', '🧠 RAM');
    return `<div style="display:inline-flex;align-items:center;gap:8px;background:rgba(255,255,255,.07);border:1px solid rgba(255,255,255,.1);border-radius:8px;padding:7px 12px;">
      <span style="font-size:12px;">${label}</span>
      <button onclick="restoreStatWidget('${id}')" style="background:#3b82f6;border:none;color:#fff;padding:3px 10px;border-radius:5px;cursor:pointer;font-size:11px;">👁 Restore</button>
    </div>`;
  }).join('');
})();
function restoreStatWidget(id) {
  const h = JSON.parse(localStorage.getItem('dash_hidden_stats') || '[]');
  localStorage.setItem('dash_hidden_stats', JSON.stringify(h.filter(x => x !== id)));
  location.reload();
}
</script>
</body>
</html>

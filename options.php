<?php
require_once 'auth.php';
require_once 'db.php';
require_once 'presets.php';
// Admin and regular users can access options; read-only and guests cannot.
if (!isLoggedIn() || getCurrentRole() === 'readonly') {
    header('Location: index.php'); exit;
}
$_dash_is_admin = isAdmin();
$cfg  = getDashConfig();
$_odb = getDashDb();
$_ou  = getCurrentUsername();

$msg = '';

// ===== Theme ZIP export =====
if (!empty($_GET['export_theme_zip'])) {
    $theme   = preg_replace('/[^a-z0-9_-]/', '', $_GET['theme'] ?? '');
    if (!$theme) { echo 'No theme specified.'; exit; }
    $bgs     = dashGetCustomBgs($_odb, $_ou);
    $entries = $bgs[$theme] ?? [];
    if (is_array($entries) && isset($entries['type'])) $entries = [$entries];
    if (!class_exists('ZipArchive')) { echo 'ZipArchive not available on this server.'; exit; }
    $zip     = new ZipArchive();
    $tmpFile = tempnam(sys_get_temp_dir(), 'dashtzip_');
    if ($zip->open($tmpFile, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) { echo 'Cannot create ZIP.'; exit; }
    $manifest = ['theme' => $theme, 'version' => '1.1', 'exported_by' => $_ou, 'exported_at' => date('c'), 'entries' => []];
    foreach ((array)$entries as $entry) {
        $type  = $entry['type'] ?? 'video_url';
        $url   = $entry['url'] ?? '';
        $me    = $entry;
        if (in_array($type, ['image_upload','video_upload']) && $url) {
            $filepath = __DIR__ . '/' . ltrim($url, '/');
            if (file_exists($filepath)) {
                $basename     = 'files/' . basename($filepath);
                $zip->addFile($filepath, $basename);
                $me['zip_file'] = $basename;
                unset($me['url']);
            }
        }
        $manifest['entries'][] = $me;
    }
    $zip->addFromString('manifest.json', json_encode($manifest, JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
    $zip->close();
    $outName = 'theme_' . $theme . '_' . date('Ymd') . '.zip';
    header('Content-Type: application/zip');
    header('Content-Disposition: attachment; filename="' . $outName . '"');
    header('Content-Length: ' . filesize($tmpFile));
    header('Cache-Control: no-store');
    readfile($tmpFile);
    @unlink($tmpFile);
    exit;
}

// ===== AJAX endpoint for inline theme BG management =====
if (!empty($_GET['bgajax'])) {
    header('Content-Type: application/json');
    $bgs = dashGetCustomBgs($_odb, $_ou);
    $act = $_POST['action'] ?? '';
    if ($act === 'save_bg') {
        $theme = preg_replace('/[^a-z0-9_-]/', '', $_POST['theme'] ?? '');
        $type  = $_POST['bg_type'] ?? 'video_url';
        $url   = trim($_POST['url'] ?? '');
        $name  = htmlspecialchars(trim($_POST['bg_name'] ?? 'Custom'), ENT_QUOTES) ?: 'Custom';
        if ($url && $theme) {
            $allowed_fits = ['fill','stretch','center','tile'];
            $fit  = in_array($_POST['bg_fit'] ?? '', $allowed_fits) ? $_POST['bg_fit'] : 'fill';
            $existing = $bgs[$theme] ?? [];
            if (!is_array($existing) || isset($existing['type'])) $existing = [];
            $entry = ['name' => $name, 'type' => $type, 'url' => $url];
            // Only store fit for image types (videos are always object-fit:cover)
            if (str_starts_with($type, 'image')) $entry['fit'] = $fit;
            $existing[] = $entry;
            $bgs[$theme] = $existing;
            dashSetCustomBgs($_odb, $_ou, $bgs);
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
            dashSetCustomBgs($_odb, $_ou, $bgs);
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
                $subdir  = $type === 'image' ? 'uploads' : 'videos';
                $url     = $subdir . '/' . $fname;
                $bgName  = htmlspecialchars(trim($_POST['bg_name'] ?? ''), ENT_QUOTES) ?: basename($fname, '.' . $ext);
                $allowed_fits = ['fill','stretch','center','tile'];
                $fit  = in_array($_POST['bg_fit'] ?? '', $allowed_fits) ? $_POST['bg_fit'] : 'fill';
                $existing = $bgs[$theme] ?? [];
                if (!is_array($existing) || (count($existing) > 0 && !isset($existing[0]))) $existing = [];
                $bgType = ($type === 'image' ? 'image_upload' : 'video_upload');
                $entry  = ['name' => $bgName, 'type' => $bgType, 'url' => $url];
                if ($type === 'image') $entry['fit'] = $fit;
                $existing[] = $entry;
                $bgs[$theme] = $existing;
                dashSetCustomBgs($_odb, $_ou, $bgs);
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

// ===== AJAX endpoint for link CRUD (delete link/column, icon update, move) =====
// Dedicated gate keeps it completely separate from the main form handler so there
// is no risk of HTML output polluting the JSON response.
if (!empty($_GET['lajax'])) {
    header('Content-Type: application/json');
    $act    = $_POST['action'] ?? '';
    $links  = dashGetLinks($_odb, $_ou);
    $ok     = false;
    $err    = '';

    switch ($act) {

        case 'delete_link':
            $secId  = $_POST['sec_id']  ?? '';
            $urlKey = $_POST['url_key'] ?? '';
            foreach ($links as &$sec) {
                if (($sec['id']??'') === $secId || ($sec['title']??'') === $secId) {
                    $sec['cards'] = array_values(
                        array_filter($sec['cards'] ?? [], fn($c) => $c['url'] !== $urlKey)
                    );
                    $ok = true;
                }
            } unset($sec);
            if ($ok) dashSetLinks($_odb, $_ou, $links);
            else $err = 'Section not found';
            break;

        case 'delete_section':
            $secId = $_POST['sec_id'] ?? '';
            $before = count($links);
            $links  = array_values(array_filter(
                $links,
                fn($s) => ($s['id']??'') !== $secId && ($s['title']??'') !== $secId
            ));
            $ok = count($links) < $before;
            if ($ok) dashSetLinks($_odb, $_ou, $links);
            else $err = 'Section not found';
            break;

        case 'update_link_icon':
            $secId   = $_POST['sec_id']   ?? '';
            $urlKey  = $_POST['url_key']  ?? '';
            $newIcon = trim($_POST['new_icon'] ?? '');
            $iconImg = '';
            if (isset($_FILES['icon_image']) && $_FILES['icon_image']['error'] === 0) {
                $ext = strtolower(pathinfo($_FILES['icon_image']['name'], PATHINFO_EXTENSION));
                if (in_array($ext, ['jpg','jpeg','png','gif','webp','svg'])) {
                    $dir = __DIR__ . '/uploads/icons/';
                    if (!is_dir($dir)) mkdir($dir, 0755, true);
                    $fname = 'icon_' . time() . '_' . mt_rand(100,999) . '.' . $ext;
                    if (move_uploaded_file($_FILES['icon_image']['tmp_name'], $dir . $fname)) {
                        $iconImg = 'uploads/icons/' . $fname;
                    }
                }
            }
            foreach ($links as &$sec) {
                if (($sec['id']??'') === $secId || ($sec['title']??'') === $secId) {
                    foreach ($sec['cards'] as &$card) {
                        if ($card['url'] === $urlKey) {
                            if ($newIcon) { $card['icon'] = $newIcon; unset($card['icon_img']); }
                            if ($iconImg) { $card['icon_img'] = $iconImg; $card['icon'] = ''; }
                            $ok = true;
                            break 2;
                        }
                    } unset($card);
                }
            } unset($sec);
            if ($ok) dashSetLinks($_odb, $_ou, $links);
            else $err = 'Link not found';
            echo json_encode(['ok' => $ok, 'icon' => $newIcon, 'icon_img' => $iconImg,
                              'error' => $err ?: null]);
            exit;

        case 'move_link':
            $fromSec = $_POST['from_sec'] ?? '';
            $urlKey  = $_POST['url_key']  ?? '';
            $toSec   = trim($_POST['to_sec'] ?? '');
            $moved   = null;
            foreach ($links as &$sec) {
                if (($sec['title']??'') === $fromSec || ($sec['id']??'') === $fromSec) {
                    foreach ($sec['cards'] as $i => $card) {
                        if ($card['url'] === $urlKey) {
                            $moved = $card;
                            array_splice($sec['cards'], $i, 1);
                            break;
                        }
                    }
                }
            } unset($sec);
            if ($moved) {
                $found = false;
                foreach ($links as &$sec) {
                    if (($sec['title']??'') === $toSec || ($sec['id']??'') === $toSec) {
                        $sec['cards'][] = $moved; $found = true; break;
                    }
                } unset($sec);
                if (!$found) $links[] = [
                    'id'    => 'sec-' . time() . '-' . mt_rand(1000,9999),
                    'title' => $toSec,
                    'icon'  => $moved['icon'] ?? '🔗',
                    'cards' => [$moved],
                ];
                dashSetLinks($_odb, $_ou, $links);
                $ok = true;
            } else {
                $err = 'Link not found in source section';
            }
            break;

        default:
            $err = 'Unknown action: ' . htmlspecialchars($act);
    }

    echo json_encode(['ok' => $ok, 'error' => $err ?: null]);
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
            $cols  = max(1, min(6, (int)($_POST['grid_cols'] ?? $cfg['grid_cols'] ?? 3)));
            file_put_contents(__DIR__.'/dash_config.php',
                buildConfigContent($cfg['username'], $cfg['password_hash'], $title, $cols));
            $msg = 'success:Settings saved!';
            break;

        case 'save_search_engine':
            $engine = preg_replace('/[^a-z]/', '', strtolower($_POST['engine'] ?? 'google'));
            dashSetSetting($_odb, $_ou, 'search_engine', $engine);
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
            dashSetSetting($_odb, $_ou, 'drives', json_encode($drives));
            // Auto-enable storage monitoring and un-hide drive widgets when drives are saved
            if (!empty($drives)) {
                $_mon_dr = dashGetSetting($_odb, $_ou, 'monitor', '{}');
                $monDr = json_decode($_mon_dr ?: '{}', true) ?: [];
                $monDr['storage'] = true;
                dashSetSetting($_odb, $_ou, 'monitor', json_encode($monDr));
                // Remove any stat-drv-* entries from hidden stats
                $hiddenDrRaw = dashGetSetting($_odb, $_ou, 'dash_hidden_stats', '[]');
                $hiddenDr = json_decode($hiddenDrRaw ?: '[]', true) ?: [];
                $hiddenDr = array_values(array_filter($hiddenDr, fn($h) => strpos($h, 'stat-drv-') !== 0));
                dashSetSetting($_odb, $_ou, 'dash_hidden_stats', json_encode($hiddenDr));
            }
            $msg = 'success:Drive configuration saved!';
            break;

        case 'save_bg':
            $theme = preg_replace('/[^a-z0-9_-]/', '', $_POST['theme'] ?? '');
            $type  = $_POST['bg_type'] ?? 'video_url';
            $url   = trim($_POST['url'] ?? '');
            $name  = htmlspecialchars(trim($_POST['bg_name'] ?? 'Custom'), ENT_QUOTES) ?: 'Custom';
            if ($url) {
                $bgScheme = strtolower(parse_url($url, PHP_URL_SCHEME) ?? '');
                if (!in_array($bgScheme, ['http','https','/',''])) {
                    $msg = 'error:Invalid URL scheme. Use http or https.'; break;
                }
            }
            $bgs   = dashGetCustomBgs($_odb, $_ou);
            if ($url) {
                $_allowed_fits = ['fill','stretch','center','tile'];
                $_fit = in_array($_POST['bg_fit'] ?? '', $_allowed_fits) ? $_POST['bg_fit'] : 'fill';
                $existing = $bgs[$theme] ?? [];
                if (!is_array($existing)) $existing = [];
                elseif (isset($existing['type'])) $existing = [['name'=>'Custom','type'=>$existing['type'],'url'=>$existing['url']??'']];
                $_entry = ['name'=>$name,'type'=>$type,'url'=>$url];
                if (str_starts_with($type, 'image')) $_entry['fit'] = $_fit;
                $existing[] = $_entry;
                $bgs[$theme] = $existing;
            } elseif (!$url && empty($bgs[$theme])) {
                unset($bgs[$theme]);
            }
            dashSetCustomBgs($_odb, $_ou, $bgs);
            $msg = 'success:Background "'.htmlspecialchars($name).'" added to '.$theme.'!';
            break;

        case 'delete_named_bg':
            $theme = preg_replace('/[^a-z0-9_-]/', '', $_POST['theme'] ?? '');
            $idx   = (int)($_POST['bg_index'] ?? -1);
            $bgs   = dashGetCustomBgs($_odb, $_ou);
            if (isset($bgs[$theme]) && is_array($bgs[$theme]) && isset($bgs[$theme][$idx])) {
                array_splice($bgs[$theme], $idx, 1);
                if (empty($bgs[$theme])) unset($bgs[$theme]);
                dashSetCustomBgs($_odb, $_ou, $bgs);
                $msg = 'success:Background removed.';
            }
            break;

        case 'upload_bg':
            $theme = preg_replace('/[^a-z0-9_-]/', '', $_POST['theme'] ?? '');
            $type  = $_POST['upload_type'] ?? 'video';
            if (isset($_FILES['file']) && $_FILES['file']['error'] === 0) {
                $ext           = strtolower(pathinfo($_FILES['file']['name'], PATHINFO_EXTENSION));
                $allowed_video = ['mp4','webm','mov','m4v','ogg'];
                $allowed_img   = ['jpg','jpeg','png','gif','webp'];
                $allowed       = $type === 'image' ? $allowed_img : $allowed_video;
                if (in_array($ext, $allowed)) {
                    $dir = __DIR__.'/' . ($type === 'image' ? 'uploads/' : 'videos/');
                    if (!is_dir($dir)) mkdir($dir, 0755, true);
                    $fname  = $theme.'_'.time().'.'.$ext;
                    move_uploaded_file($_FILES['file']['tmp_name'], $dir.$fname);
                    $subdir = $type === 'image' ? 'uploads' : 'videos';
                    $url    = $subdir.'/'.$fname;
                    $bgName = htmlspecialchars(trim($_POST['bg_name'] ?? ''), ENT_QUOTES) ?: basename($fname, '.'.$ext);
                    $bgs    = dashGetCustomBgs($_odb, $_ou);
                    $existing = $bgs[$theme] ?? [];
                    if (!is_array($existing) || (count($existing)>0 && !isset($existing[0]))) $existing = [];
                    $_allowed_fits2 = ['fill','stretch','center','tile'];
                    $_fit2 = in_array($_POST['bg_fit'] ?? '', $_allowed_fits2) ? $_POST['bg_fit'] : 'fill';
                    $_bgtype2 = ($type==='image' ? 'image_upload' : 'video_upload');
                    $_entry2 = ['name'=>$bgName,'type'=>$_bgtype2,'url'=>$url];
                    if ($type === 'image') $_entry2['fit'] = $_fit2;
                    $existing[] = $_entry2;
                    $bgs[$theme] = $existing;
                    dashSetCustomBgs($_odb, $_ou, $bgs);
                    $msg = 'success:File uploaded! Path: /'.$url;
                } else {
                    $msg = 'error:File type not allowed.';
                }
            }
            break;

        case 'save_custom_theme':
            $data = json_decode($_POST['theme_json'] ?? '{}', true);
            if ($data) {
                dashSetSetting($_odb, $_ou, 'custom_theme', json_encode($data));
                $msg = 'success:Custom theme saved!';
            }
            break;

        case 'add_site':
            $links = dashGetLinks($_odb, $_ou);
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
                    $links[] = ['id'=>'sec-'.time().'-'.mt_rand(1000,9999),'title'=>$section ?: $label,'icon'=>$icon,'cards'=>[$entry]];
                }
                dashSetLinks($_odb, $_ou, $links);
                $msg = 'success:Site/link added!';
            }
            break;

        case 'update_link_icon':
            // Edit icon/image for existing link
            $links = dashGetLinks($_odb, $_ou);
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
            dashSetLinks($_odb, $_ou, $links);
            if (!empty($_POST['ajax'])) {
                header('Content-Type: application/json');
                echo json_encode(['ok'=>true,'icon'=>$newIcon,'icon_img'=>$iconImg]);
                exit;
            }
            $msg = 'success:Icon updated!';
            break;

        case 'move_link':
            // Move a link from one section to another
            $links = dashGetLinks($_odb, $_ou);
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
                if (!$found) $links[] = ['id'=>'sec-'.time().'-'.mt_rand(1000,9999),'title'=>$toSec,'icon'=>$moved['icon']??'🔗','cards'=>[$moved]];
                dashSetLinks($_odb, $_ou, $links);
                if (!empty($_POST['ajax'])) {
                    header('Content-Type: application/json');
                    echo json_encode(['ok'=>true]);
                    exit;
                }
                $msg = 'success:Link moved!';
            }
            break;

        case 'delete_link':
            $links  = dashGetLinks($_odb, $_ou);
            $secId  = $_POST['sec_id'] ?? '';
            $urlKey = $_POST['url_key'] ?? '';
            foreach ($links as &$sec) {
                if (($sec['title']??'') === $secId || ($sec['id']??'') === $secId) {
                    $sec['cards'] = array_values(array_filter($sec['cards'], fn($c) => $c['url'] !== $urlKey));
                }
            } unset($sec);
            dashSetLinks($_odb, $_ou, $links);
            if (!empty($_POST['ajax'])) {
                header('Content-Type: application/json');
                echo json_encode(['ok'=>true]);
                exit;
            }
            $msg = 'success:Link deleted!';
            break;

        case 'delete_section':
            $links = dashGetLinks($_odb, $_ou);
            $secId = $_POST['sec_id'] ?? '';
            $links = array_values(array_filter($links, fn($s) => ($s['title']??'') !== $secId && ($s['id']??'') !== $secId));
            dashSetLinks($_odb, $_ou, $links);
            if (!empty($_POST['ajax'])) {
                header('Content-Type: application/json');
                echo json_encode(['ok'=>true]);
                exit;
            }
            $msg = 'success:Column deleted!';
            break;

        case 'toggle_hidden_theme':
            $_ht_raw2 = dashGetSetting($_odb, $_ou, 'hidden_themes', '[]');
            $ht = json_decode($_ht_raw2, true) ?: [];
            $th = preg_replace('/[^a-z0-9_-]/','',$_POST['theme']??'');
            if (in_array($th,$ht)) $ht = array_values(array_filter($ht,fn($x)=>$x!==$th));
            else $ht[] = $th;
            dashSetSetting($_odb, $_ou, 'hidden_themes', json_encode($ht));
            $msg = 'success:Theme visibility updated!';
            break;

        case 'add_user':
            $uname = preg_replace('/[^a-zA-Z0-9_-]/', '', trim($_POST['new_username'] ?? ''));
            $pass  = $_POST['new_password'] ?? '';
            $role  = in_array($_POST['new_role'] ?? 'user', ['user','readonly']) ? $_POST['new_role'] : 'user';
            if (strlen($uname) < 2) { $msg = 'error:Username must be at least 2 characters.'; break; }
            if (strlen($pass)  < 6) { $msg = 'error:Password must be at least 6 characters.'; break; }
            if (strtolower($uname) === strtolower($cfg['username'])) { $msg = 'error:That username is already the admin.'; break; }
            // Check uniqueness in MySQL first, then JSON fallback
            $existUsers = dashGetUsers($_odb);
            foreach ($existUsers as $eu) {
                if (strtolower($eu['username']) === strtolower($uname)) { $msg = 'error:Username already exists.'; break 2; }
            }
            $newUserHash = password_hash($pass, PASSWORD_BCRYPT);
            dashSaveUser($_odb, $uname, $newUserHash, $role);
            // Keep JSON in sync for auth.php JSON fallback — deduplicate before writing
            $jUsers = json_decode(@file_get_contents(__DIR__.'/dash_users.json') ?: '[]', true) ?: [];
            $jUsers = array_values(array_filter($jUsers, fn($u) => ($u['username']??'') !== $uname));
            $jUsers[] = ['username'=>$uname,'password_hash'=>$newUserHash,'role'=>$role];
            @file_put_contents(__DIR__.'/dash_users.json', json_encode($jUsers, JSON_PRETTY_PRINT));
            $msg = 'success:User "'.$uname.'" added!';
            break;

        case 'delete_user':
            $uname = trim($_POST['del_username'] ?? '');
            dashDeleteUser($_odb, $uname);
            // Sync JSON fallback
            $jUsers = json_decode(@file_get_contents(__DIR__.'/dash_users.json') ?: '[]', true) ?: [];
            $jUsers = array_values(array_filter($jUsers, fn($u) => $u['username'] !== $uname));
            @file_put_contents(__DIR__.'/dash_users.json', json_encode($jUsers, JSON_PRETTY_PRINT));
            $msg = 'success:User deleted.';
            break;

        case 'reset_user_password':
            $uname = trim($_POST['reset_username'] ?? '');
            $pass  = $_POST['reset_password'] ?? '';
            if (strlen($pass) < 6) { $msg = 'error:Password must be at least 6 characters.'; break; }
            $newHash = password_hash($pass, PASSWORD_BCRYPT);
            // Preserve existing role; fetch it first
            $existingU = dashGetUser($_odb, $uname);
            $keepRole  = $existingU['role'] ?? 'user';
            dashSaveUser($_odb, $uname, $newHash, $keepRole);
            // Sync JSON fallback
            $jUsers = json_decode(@file_get_contents(__DIR__.'/dash_users.json') ?: '[]', true) ?: [];
            $found  = false;
            foreach ($jUsers as &$u) {
                if ($u['username'] === $uname) { $u['password_hash'] = $newHash; $found = true; break; }
            } unset($u);
            if ($found) @file_put_contents(__DIR__.'/dash_users.json', json_encode($jUsers, JSON_PRETTY_PRINT));
            $msg = 'success:Password updated for "'.$uname.'".';
            break;

        case 'add_column':
            $links = dashGetLinks($_odb, $_ou);
            $title = trim($_POST['col_title'] ?? '');
            $icon  = trim($_POST['col_icon']  ?? '📌');
            if (!$title) { $msg = 'error:Column name is required.'; break; }
            foreach ($links as $s) {
                if (($s['title'] ?? '') === $title) { $msg = 'error:A column named "'.htmlspecialchars($title, ENT_QUOTES).'" already exists.'; break 2; }
            }
            $links[] = ['id'=>'sec-'.time().'-'.mt_rand(1000,9999),'title'=>$title,'icon'=>$icon,'cards'=>[]];
            dashSetLinks($_odb, $_ou, $links);
            $msg = 'success:Column "'.htmlspecialchars($title, ENT_QUOTES).'" created! Go to the dashboard and enter Edit Mode to add links to it.';
            break;

        case 'restore_default_links':
            $existing = dashGetLinks($_odb, $_ou);
            $existingTitles = array_map(fn($s) => $s['title'] ?? '', $existing);
            $ts = time();
            $defaults = [
                ['id'=>'sec-search-'.$ts,    'title'=>'Search',      'icon'=>'🔍','cards'=>[
                    ['icon'=>'🔍','label'=>'Google',     'url'=>'https://google.com'],
                    ['icon'=>'🦆','label'=>'DuckDuckGo', 'url'=>'https://duckduckgo.com'],
                    ['icon'=>'🔵','label'=>'Bing',        'url'=>'https://bing.com'],
                    ['icon'=>'🦁','label'=>'Brave Search','url'=>'https://search.brave.com'],
                ]],
                ['id'=>'sec-email-'.($ts+1),  'title'=>'Email',       'icon'=>'📧','cards'=>[
                    ['icon'=>'📬','label'=>'Gmail',      'url'=>'https://mail.google.com'],
                    ['icon'=>'📮','label'=>'Outlook',    'url'=>'https://outlook.live.com'],
                    ['icon'=>'📧','label'=>'ProtonMail', 'url'=>'https://proton.me/mail'],
                ]],
                ['id'=>'sec-dev-'.($ts+2),    'title'=>'Development', 'icon'=>'💻','cards'=>[
                    ['icon'=>'🐙','label'=>'GitHub',         'url'=>'https://github.com'],
                    ['icon'=>'📚','label'=>'Stack Overflow', 'url'=>'https://stackoverflow.com'],
                    ['icon'=>'🔷','label'=>'MDN Web Docs',   'url'=>'https://developer.mozilla.org'],
                    ['icon'=>'🐋','label'=>'Docker Hub',     'url'=>'https://hub.docker.com'],
                ]],
                ['id'=>'sec-media-'.($ts+3),  'title'=>'Media',       'icon'=>'🎬','cards'=>[
                    ['icon'=>'▶️','label'=>'YouTube', 'url'=>'https://youtube.com'],
                    ['icon'=>'🎵','label'=>'Spotify', 'url'=>'https://spotify.com'],
                    ['icon'=>'📺','label'=>'Twitch',  'url'=>'https://twitch.tv'],
                ]],
                ['id'=>'sec-social-'.($ts+4), 'title'=>'Social',      'icon'=>'💬','cards'=>[
                    ['icon'=>'💬','label'=>'Reddit',   'url'=>'https://reddit.com'],
                    ['icon'=>'🐦','label'=>'X / Twitter','url'=>'https://x.com'],
                    ['icon'=>'📘','label'=>'Facebook', 'url'=>'https://facebook.com'],
                ]],
            ];
            $added = 0;
            foreach ($defaults as $def) {
                if (!in_array($def['title'], $existingTitles)) {
                    $existing[] = $def;
                    $added++;
                }
            }
            dashSetLinks($_odb, $_ou, $existing);
            $msg = $added > 0
                ? 'success:'.$added.' default column(s) restored. Reload the dashboard to see them.'
                : 'success:All default columns already exist — nothing to add.';
            break;

        case 'add_preset_column':
            // v1.4.3 — add a single starter column from the shared preset library.
            // Used by the "📦 Add Preset Column" buttons in tab-links so users
            // can re-add a category they deleted (or get any they skipped in
            // the first-run wizard) without manually re-entering links.
            $cat = trim($_POST['preset_cat'] ?? '');
            $presets = dashGetPresets();
            if (!isset($presets[$cat])) { $msg = 'error:Unknown preset category.'; break; }
            $info = $presets[$cat];
            $links = dashGetLinks($_odb, $_ou);
            // De-dupe titles — append "(2)", "(3)" etc if a column with the
            // same name already exists. Never silently drop the request.
            $existingTitles = array_map(fn($s) => $s['title'] ?? '', $links);
            $title = $cat;
            if (in_array($title, $existingTitles, true)) {
                $n = 2;
                while (in_array($title.' ('.$n.')', $existingTitles, true)) { $n++; }
                $title = $title.' ('.$n.')';
            }
            $cards = [];
            foreach ($info['items'] as $it) {
                $cards[] = [
                    'icon'  => $it['icon']  ?? '🔗',
                    'label' => $it['label'] ?? '',
                    'url'   => $it['url']   ?? '',
                ];
            }
            $links[] = [
                'id'    => 'sec-preset-'.time().'-'.mt_rand(100,999),
                'title' => $title,
                'icon'  => $info['icon'] ?? '📁',
                'cards' => $cards,
            ];
            dashSetLinks($_odb, $_ou, $links);
            $msg = 'success:Added "'.htmlspecialchars($title, ENT_QUOTES).'" with '.count($cards).' link(s). Reload your dashboard to see it.';
            break;

        case 'restore_links_backup':
            // Restore columns from the last auto-saved backup
            $backupRaw = $db
                ? dashGetSetting($_odb, $_ou, 'dash_links_backup', '')
                : @file_get_contents(__DIR__ . '/dash_links_backup.json');
            if (!$backupRaw) { $msg = 'error:No backup found yet. A backup is created automatically every time your links are saved from the dashboard.'; break; }
            $backup = json_decode($backupRaw, true);
            if (!is_array($backup) || empty($backup['links'])) { $msg = 'error:Backup data is empty or invalid.'; break; }
            dashSetLinks($_odb, $_ou, $backup['links']);
            $savedAt = $backup['saved_at'] ?? 'unknown time';
            $cnt = count($backup['links']);
            $msg = 'success:Restored '.$cnt.' column(s) from backup (saved '.$savedAt.'). Reload your dashboard to see them.';
            break;

        case 'save_hidden_widgets':
            // Save which floating widgets (HTML/RSS/camera/calendar) are hidden
            $hwIds = [];
            $raw   = $_POST['hidden_ids'] ?? '';
            if ($raw) { $hwIds = array_values(array_filter(array_map('trim', explode(',', $raw)))); }
            dashSetSetting($_odb, $_ou, 'hidden_widgets', json_encode($hwIds));
            $msg = 'success:Widget visibility saved.';
            break;

        case 'save_widget_settings':
            $_mon_raw2 = dashGetSetting($_odb, $_ou, 'monitor', '{}');
            $mon = json_decode($_mon_raw2, true) ?: [];
            foreach (['cpu','ram','storage','clock','weather'] as $k) {
                $mon[$k] = !empty($_POST['widget_'.$k]);
            }
            dashSetSetting($_odb, $_ou, 'monitor', json_encode($mon));
            // When a widget is re-enabled, remove it from the hidden-stats list
            // so it actually appears instead of being hidden on load.
            $hiddenRaw = dashGetSetting($_odb, $_ou, 'dash_hidden_stats', '[]');
            $hidden = json_decode($hiddenRaw ?: '[]', true) ?: [];
            $unHide = [];
            if ($mon['cpu'])     $unHide[] = 'stat-cpu';
            if ($mon['ram'])     $unHide[] = 'stat-ram';
            if ($mon['clock'])   $unHide[] = 'stat-clock';
            if ($mon['weather']) $unHide[] = 'stat-weather';
            if ($unHide) {
                $hidden = array_values(array_filter($hidden, fn($h) => !in_array($h, $unHide)));
                dashSetSetting($_odb, $_ou, 'dash_hidden_stats', json_encode($hidden));
            }
            $msg = 'success:Widget settings saved! Reload the dashboard to see changes.';
            break;

        case 'add_html_widget':
            $name = htmlspecialchars(trim($_POST['hw_name'] ?? ''), ENT_QUOTES);
            $html = trim($_POST['hw_html'] ?? '');
            if (!$name || !$html) { $msg = 'error:Widget name and HTML code are required.'; break; }
            $hwWidgets   = dashGetWidgets($_odb, $_ou, 'html');
            $hwWidgets[] = ['id'=>'hw-'.time(),'name'=>$name,'html'=>$html,'x'=>820,'y'=>80];
            dashSetWidgets($_odb, $_ou, 'html', $hwWidgets);
            $msg = 'success:Widget "'.htmlspecialchars($name).'" added! Reload the dashboard to see it.';
            break;

        case 'delete_html_widget':
            $hwId      = trim($_POST['hw_id'] ?? '');
            $hwWidgets = dashGetWidgets($_odb, $_ou, 'html');
            $hwWidgets = array_values(array_filter($hwWidgets, fn($w) => $w['id'] !== $hwId));
            dashSetWidgets($_odb, $_ou, 'html', $hwWidgets);
            $msg = 'success:Widget deleted.';
            break;

        case 'add_rss_widget':
            $rwName = htmlspecialchars(trim($_POST['rw_name'] ?? ''), ENT_QUOTES);
            $rwUrl  = trim($_POST['rw_url'] ?? '');
            $rwMax  = max(3, min(30, intval($_POST['rw_max'] ?? 8)));
            if (!$rwName || !$rwUrl) { $msg = 'error:Widget name and feed URL are required.'; break; }
            if (!filter_var($rwUrl, FILTER_VALIDATE_URL)) { $msg = 'error:Please enter a valid URL.'; break; }
            $rwList   = dashGetWidgets($_odb, $_ou, 'rss');
            $rwList[] = ['id'=>'rw-'.time(),'name'=>$rwName,'url'=>$rwUrl,'max'=>$rwMax,'x'=>840,'y'=>60];
            dashSetWidgets($_odb, $_ou, 'rss', $rwList);
            $msg = 'success:RSS widget "'.htmlspecialchars($rwName).'" added! Reload the dashboard to see it.';
            break;

        case 'delete_rss_widget':
            $rwId   = trim($_POST['rw_id'] ?? '');
            $rwList = dashGetWidgets($_odb, $_ou, 'rss');
            $rwList = array_values(array_filter($rwList, fn($w) => $w['id'] !== $rwId));
            dashSetWidgets($_odb, $_ou, 'rss', $rwList);
            $msg = 'success:RSS widget deleted.';
            break;

        case 'add_weather_city':
            $wxcName = htmlspecialchars(trim($_POST['wxc_name'] ?? ''), ENT_QUOTES);
            $wxcZip  = trim($_POST['wxc_zip'] ?? '');
            $wxcUnit = in_array(trim($_POST['wxc_unit']??'F'),['F','C'])?trim($_POST['wxc_unit']):'F';
            if (!$wxcName || !$wxcZip) { $msg = 'error:City name and ZIP/city are required.'; break; }
            $wxcList   = dashGetWidgets($_odb, $_ou, 'weather_city');
            $wxcList[] = ['id'=>'wxc-'.time(),'name'=>$wxcName,'zip'=>$wxcZip,'unit'=>$wxcUnit,'x'=>860,'y'=>10];
            dashSetWidgets($_odb, $_ou, 'weather_city', $wxcList);
            $msg = 'success:Weather widget "'.htmlspecialchars($wxcName).'" added! Reload the dashboard to see it.';
            break;

        case 'delete_weather_city':
            $wxcId   = trim($_POST['wxc_id'] ?? '');
            $wxcList = dashGetWidgets($_odb, $_ou, 'weather_city');
            $wxcList = array_values(array_filter($wxcList, fn($w) => $w['id'] !== $wxcId));
            dashSetWidgets($_odb, $_ou, 'weather_city', $wxcList);
            $msg = 'success:Weather city widget deleted.';
            break;

        case 'add_timezone_widget':
            $tzName = htmlspecialchars(trim($_POST['tz_name'] ?? ''), ENT_QUOTES);
            $tzZone = trim($_POST['tz_zone'] ?? 'UTC');
            if (!$tzName) { $msg = 'error:A label is required.'; break; }
            $tzList   = dashGetWidgets($_odb, $_ou, 'timezone');
            $tzList[] = ['id'=>'tz-'.time(),'name'=>$tzName,'tz'=>$tzZone,'x'=>620,'y'=>10];
            dashSetWidgets($_odb, $_ou, 'timezone', $tzList);
            $msg = 'success:Timezone clock "'.htmlspecialchars($tzName).'" added! Reload to see it.';
            break;

        case 'delete_timezone_widget':
            $tzId   = trim($_POST['tz_id'] ?? '');
            $tzList = dashGetWidgets($_odb, $_ou, 'timezone');
            $tzList = array_values(array_filter($tzList, fn($w) => $w['id'] !== $tzId));
            dashSetWidgets($_odb, $_ou, 'timezone', $tzList);
            $msg = 'success:Timezone widget deleted.';
            break;

        case 'add_camera_widget':
            $cwName = htmlspecialchars(trim($_POST['cw_name'] ?? ''), ENT_QUOTES);
            $cwUrl  = trim($_POST['cw_url'] ?? '');
            $cwType = in_array(trim($_POST['cw_type']??'iframe'),['iframe','video','mjpeg'])?trim($_POST['cw_type']):'iframe';
            $cwRec  = trim($_POST['cw_record_url'] ?? '');
            if (!$cwName || !$cwUrl) { $msg = 'error:Camera name and stream URL are required.'; break; }
            $cwList   = dashGetWidgets($_odb, $_ou, 'camera');
            $cwList[] = ['id'=>'cam-'.time(),'name'=>$cwName,'url'=>$cwUrl,'type'=>$cwType,'record_url'=>$cwRec,'x'=>900,'y'=>60];
            dashSetWidgets($_odb, $_ou, 'camera', $cwList);
            $msg = 'success:Camera widget "'.htmlspecialchars($cwName).'" added! Reload the dashboard to see it.';
            break;

        case 'delete_camera_widget':
            $cwId   = trim($_POST['cw_id'] ?? '');
            $cwList = dashGetWidgets($_odb, $_ou, 'camera');
            $cwList = array_values(array_filter($cwList, fn($w) => $w['id'] !== $cwId));
            dashSetWidgets($_odb, $_ou, 'camera', $cwList);
            $msg = 'success:Camera widget deleted.';
            break;

        case 'add_calendar_widget':
            $calName = htmlspecialchars(trim($_POST['cal_name'] ?? ''), ENT_QUOTES);
            $calIds  = trim($_POST['cal_ids'] ?? '');
            $calTz   = trim($_POST['cal_tz'] ?? 'UTC');
            if (!$calName || !$calIds) { $msg = 'error:Calendar name and at least one calendar ID are required.'; break; }
            $calList   = dashGetWidgets($_odb, $_ou, 'calendar');
            $calList[] = ['id'=>'cal-'.time(),'name'=>$calName,'cal_ids'=>$calIds,'tz'=>$calTz,'x'=>860,'y'=>60];
            dashSetWidgets($_odb, $_ou, 'calendar', $calList);
            $msg = 'success:Calendar widget "'.htmlspecialchars($calName).'" added! Reload the dashboard to see it.';
            break;

        case 'delete_calendar_widget':
            $calId   = trim($_POST['cal_id'] ?? '');
            $calList = dashGetWidgets($_odb, $_ou, 'calendar');
            $calList = array_values(array_filter($calList, fn($w) => $w['id'] !== $calId));
            dashSetWidgets($_odb, $_ou, 'calendar', $calList);
            $msg = 'success:Calendar widget deleted.';
            break;

        case 'add_countdown_widget':
            $cdName = htmlspecialchars(trim($_POST['cd_name'] ?? ''), ENT_QUOTES);
            $cdDate = trim($_POST['cd_date'] ?? '');
            if (!$cdName || !$cdDate) { $msg = 'error:Name and target date are required.'; break; }
            $cdList   = dashGetWidgets($_odb, $_ou, 'countdown');
            $cdList[] = ['id'=>'cd-'.time(),'name'=>$cdName,'target_date'=>$cdDate,'x'=>920,'y'=>80];
            dashSetWidgets($_odb, $_ou, 'countdown', $cdList);
            $msg = 'success:Countdown "'.htmlspecialchars($cdName).'" added! Reload the dashboard to see it.';
            break;

        case 'delete_countdown_widget':
            $cdId   = trim($_POST['cd_id'] ?? '');
            $cdList = dashGetWidgets($_odb, $_ou, 'countdown');
            $cdList = array_values(array_filter($cdList, fn($w) => $w['id'] !== $cdId));
            dashSetWidgets($_odb, $_ou, 'countdown', $cdList);
            $msg = 'success:Countdown widget deleted.';
            break;

        case 'restore_column':
            $colId  = trim($_POST['col_id'] ?? '');
            if (!$colId) { $msg = 'error:No column ID.'; break; }
            $hidden = json_decode(dashGetSetting($_odb, $_ou, 'dash_hidden_cols', '[]') ?: '[]', true) ?: [];
            $hidden = array_values(array_filter($hidden, fn($c) => (is_string($c) ? $c : ($c['id']??'')) !== $colId));
            dashSetSetting($_odb, $_ou, 'dash_hidden_cols', $hidden ? json_encode($hidden) : null);
            $msg = 'success:Column restored. Reload the dashboard to see it.';
            break;

        case 'save_theme_sounds':
            $snd = ($_POST['theme_sound'] ?? '') === '1' ? '1' : '0';
            dashSetSetting($_odb, $_ou, 'theme_sound', $snd);
            $msg = 'success:Sound preference saved.';
            break;

        case 'add_doc_folder':
            $dLabel = trim($_POST['label'] ?? '');
            $dIcon  = trim($_POST['icon']  ?? '📁');
            if (!$dLabel) { $msg = 'error:Folder name required.'; break; }
            $dBase    = __DIR__ . '/uploads/docs/' . $_ou;
            $dKey     = 'fd_' . time() . '_' . substr(bin2hex(random_bytes(3)), 0, 6);
            $dPath    = $dBase . '/' . $dKey;
            $existing = dashDocFolders($_odb, $_ou);
            $dOrder   = count($existing);
            @mkdir($dPath, 0755, true);
            file_put_contents($dPath . '/_meta.json', json_encode(
                ['label'=>$dLabel,'icon'=>$dIcon,'order'=>$dOrder], JSON_PRETTY_PRINT
            ));
            dashDocCreateFolder($_odb, $_ou, $dKey, $dLabel, $dIcon, $dOrder);
            $msg = 'success:Folder "'.htmlspecialchars($dLabel).'" created.';
            break;

        case 'delete_doc_folder':
            $dKey  = preg_replace('/[^a-zA-Z0-9_-]/', '', $_POST['dk'] ?? '');
            $dBase = __DIR__ . '/uploads/docs/' . $_ou;
            $dPath = $dBase . '/' . $dKey;
            if ($dKey && is_dir($dPath)) {
                // Recursive delete — handles subdirs and dotfiles, same as download.php removeDir()
                $rdi = new RecursiveDirectoryIterator($dPath, FilesystemIterator::SKIP_DOTS);
                $rii = new RecursiveIteratorIterator($rdi, RecursiveIteratorIterator::CHILD_FIRST);
                foreach ($rii as $fi) {
                    $fi->isDir() ? @rmdir($fi->getRealPath()) : @unlink($fi->getRealPath());
                }
                @rmdir($dPath);
            }
            dashDocDeleteFolder($_odb, $_ou, $dKey);
            $msg = $dKey ? 'success:Folder deleted.' : 'error:No folder key.';
            break;

        case 'rename_doc_folder':
            $dKey      = preg_replace('/[^a-zA-Z0-9_-]/', '', $_POST['dk'] ?? '');
            $dNewLabel = htmlspecialchars(trim($_POST['label'] ?? ''), ENT_QUOTES);
            $dBase     = __DIR__ . '/uploads/docs/' . $_ou;
            $dPath     = $dBase . '/' . $dKey;
            $metaF     = $dPath . '/_meta.json';
            if ($dKey && $dNewLabel) {
                dashDocRenameFolder($_odb, $_ou, $dKey, $dNewLabel);
                if (file_exists($metaF)) {
                    $m = json_decode(@file_get_contents($metaF) ?: '{}', true) ?: [];
                    $m['label'] = $dNewLabel;
                    file_put_contents($metaF, json_encode($m));
                }
                $msg = 'success:Folder renamed.';
            } else { $msg = 'error:Missing folder key or label.'; }
            break;

        case 'import_bookmarks':
            $json  = $_POST['bookmarks_json'] ?? '[]';
            $items = json_decode($json, true);
            if (!is_array($items)) { $msg = 'error:Invalid bookmark data.'; break; }
            $links    = dashGetLinks($_odb, $_ou);
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
            if ($imported) dashSetLinks($_odb, $_ou, $links);
            $msg = 'success:Imported '.$imported.' bookmark(s) successfully!';
            break;

        // ── MySQL Configuration ──────────────────────────────────────────────
        case 'save_mysql_config':
            $dbHost = trim($_POST['db_host'] ?? 'localhost');
            $dbName = trim($_POST['db_name'] ?? '');
            $dbUser = trim($_POST['db_user'] ?? '');
            $dbPass = $_POST['db_pass'] ?? '';
            if (!$dbName || !$dbUser) { $msg = 'error:Database name and user are required.'; break; }
            $existing = @file_get_contents(__DIR__.'/dash_config.php') ?: '';
            // Remove old DB lines
            $lines = array_filter(explode("\n", $existing), fn($l) =>
                !preg_match("/define\('DASH_DB_/", $l)
            );
            $lines[] = "define('DASH_DB_TYPE','mysql');";
            $lines[] = "define('DASH_DB_HOST','".addslashes($dbHost)."');";
            $lines[] = "define('DASH_DB_NAME','".addslashes($dbName)."');";
            $lines[] = "define('DASH_DB_USER','".addslashes($dbUser)."');";
            $lines[] = "define('DASH_DB_PASS','".addslashes($dbPass)."');";
            file_put_contents(__DIR__.'/dash_config.php', implode("\n", $lines)."\n");
            $msg = 'success:MySQL configuration saved! Reload this page to connect.';
            break;

        // ── Export user data ─────────────────────────────────────────────────
        case 'export_user_data':
            $eUser = trim($_POST['export_username'] ?? $_ou);
            if (!$eUser) $eUser = $_ou;
            header('Content-Type: application/json');
            header('Content-Disposition: attachment; filename="dash_export_'.preg_replace('/[^a-z0-9_-]/','_',$eUser).'_'.date('Ymd_His').'.json"');
            echo json_encode(dashExportUser($_odb, $eUser), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
            exit;

        // ── Import user data ─────────────────────────────────────────────────
        case 'import_user_data':
            $iUser = trim($_POST['import_username'] ?? $_ou);
            if (!$iUser) $iUser = $_ou;
            $iJson = '';
            if (isset($_FILES['import_file']) && $_FILES['import_file']['error'] === 0) {
                $iJson = @file_get_contents($_FILES['import_file']['tmp_name']) ?: '';
            } elseif (!empty($_POST['import_json'])) {
                $iJson = $_POST['import_json'];
            }
            $iData = json_decode($iJson, true);
            if (!is_array($iData)) { $msg = 'error:Invalid JSON data.'; break; }
            dashImportUser($_odb, $iUser, $iData);
            $msg = 'success:Data imported for user "'.htmlspecialchars($iUser).'". Reload dashboard to see changes.';
            break;

        // ── Admin: delete all user data ──────────────────────────────────────
        case 'admin_wipe_user':
            $wUser = trim($_POST['wipe_username'] ?? '');
            if (!$wUser || $wUser === $cfg['username']) { $msg = 'error:Cannot wipe the main admin account.'; break; }
            dashDeleteUser($_odb, $wUser);
            // Remove filesystem docs
            $wBase = __DIR__ . '/uploads/docs/' . preg_replace('/[^a-zA-Z0-9_-]/','', $wUser);
            if (is_dir($wBase)) {
                $iter = new RecursiveIteratorIterator(
                    new RecursiveDirectoryIterator($wBase, FilesystemIterator::SKIP_DOTS),
                    RecursiveIteratorIterator::CHILD_FIRST);
                foreach ($iter as $f) $f->isDir() ? @rmdir($f) : @unlink($f);
                @rmdir($wBase);
            }
            $msg = 'success:All data for user "'.htmlspecialchars($wUser).'" has been deleted.';
            break;

        // ── Machine Profiles ─────────────────────────────────────────────────
        case 'rename_machine':
            $muuid = preg_replace('/[^0-9a-f\-]/', '', $_POST['machine_uuid'] ?? '');
            $mname = htmlspecialchars(trim($_POST['machine_name'] ?? ''), ENT_QUOTES);
            if (strlen($muuid) !== 36 || !$mname) { $msg = 'error:Invalid machine UUID or name.'; break; }
            dashSaveMachine($_odb, $_ou, $muuid, ['machine_name' => $mname]);
            $msg = 'success:Machine renamed to "'.htmlspecialchars($mname).'".';
            break;

        case 'delete_machine':
            $muuid = preg_replace('/[^0-9a-f\-]/', '', $_POST['machine_uuid'] ?? '');
            if (strlen($muuid) !== 36) { $msg = 'error:Invalid machine UUID.'; break; }
            if ($_odb) {
                $_odb->prepare('DELETE FROM dash_machines WHERE username=? AND machine_uuid=?')
                     ->execute([$_ou, $muuid]);
            }
            $msg = 'success:Machine profile deleted.';
            break;

        // ── Update URL setting ───────────────────────────────────────────────
        case 'admin_push_to_user':
            if (!$_odb) { $msg = 'error:MySQL required for admin push.'; break; }
            $pTo    = trim($_POST['push_to'] ?? '');
            $pType  = trim($_POST['push_type'] ?? '');
            $pResId = trim($_POST['push_res_id'] ?? '');
            if (!$pTo || !$pType || !$pResId) { $msg = 'error:Missing parameters.'; break; }
            $allUs = dashGetUsers($_odb);
            if ($pTo === '__all__') {
                $targets = array_column($allUs, 'username');
            } else {
                $valid = array_column($allUs, 'username');
                if (!in_array($pTo, $valid)) { $msg = 'error:Unknown user.'; break; }
                $targets = [$pTo];
            }
            $pushed = 0;
            foreach ($targets as $tgt) {
                if ($tgt === $_ou) continue;
                if ($pType === 'links_col') {
                    $fromLinks = dashGetLinks($_odb, $_ou);
                    $col = null;
                    foreach ($fromLinks as $fc) { if (($fc['id']??'') === $pResId) { $col = $fc; break; } }
                    if (!$col) continue;
                    $col['id'] = 'col_'.substr(md5(uniqid('',true)),0,8);
                    $toLinks = dashGetLinks($_odb, $tgt);
                    $toLinks[] = $col;
                    dashSetLinks($_odb, $tgt, $toLinks);
                } else {
                    $fromWs = dashGetWidgets($_odb, $_ou, $pType);
                    $w = null;
                    foreach ($fromWs as $fw) { if (($fw['id']??'') === $pResId) { $w = $fw; break; } }
                    if (!$w) continue;
                    $w['id'] = 'w_'.substr(md5(uniqid('',true)),0,8);
                    $toWs = dashGetWidgets($_odb, $tgt, $pType);
                    $toWs[] = $w;
                    dashSetWidgets($_odb, $tgt, $pType, $toWs);
                }
                $pushed++;
            }
            $tLabel = ($pTo === '__all__') ? "all {$pushed} user(s)" : $pTo;
            $msg = "success:Pushed to {$tLabel}!";
            break;

        case 'save_update_url':
            $uurl = trim($_POST['update_url'] ?? '');
            dashSetSetting($_odb, $_ou, 'update_url', $uurl ?: null);
            $msg = 'success:Update URL saved.';
            break;
        // ── File-based sharing (no MySQL needed) ────────────────────────────
        case 'file_share_item': {
            $fsType  = preg_replace('/[^a-z_]/', '', $_POST['fs_type'] ?? '');
            $fsColId = trim($_POST['fs_col_id'] ?? '');
            $fsTo    = preg_replace('/[^a-z0-9_\-\.@]/i', '', $_POST['fs_to'] ?? '');
            if (!$fsType || !$fsTo || $fsTo === $_ou) { $msg = 'error:Invalid share parameters.'; break; }
            if ($fsType !== 'links_col') { $msg = 'error:Only link columns can be shared in file mode.'; break; }
            $allLk = dashGetLinks($_odb, $_ou);
            $col   = null;
            foreach ($allLk as $c) { if (($c['id'] ?? '') === $fsColId) { $col = $c; break; } }
            if (!$col) { $msg = 'error:Column not found.'; break; }
            $shareEntry = ['id' => 'sh_'.time().'_'.rand(100,999), 'from' => $_ou,
                           'type' => 'links_col', 'name' => ($col['icon']??'📁').' '.($col['title']??'Column'),
                           'data' => $col, 'ts' => time()];
            $allU = dashGetUsers($_odb);
            $allU[] = ['username' => $cfg['username']];
            $targets = $fsTo === '__all__'
                ? array_column(array_filter($allU, fn($u) => ($u['username']??'') !== $_ou), 'username')
                : [$fsTo];
            foreach ($targets as $rec) {
                $rf = __DIR__ . '/dash_shares_' . preg_replace('/[^a-z0-9_\-]/i', '', $rec) . '.json';
                $ex = json_decode(@file_get_contents($rf) ?: '[]', true) ?: [];
                $ex[] = $shareEntry;
                file_put_contents($rf, json_encode($ex, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), LOCK_EX);
            }
            $msg = 'success:Column shared with ' . (count($targets) > 1 ? count($targets).' users' : htmlspecialchars($targets[0] ?? '?')) . '!';
            break;
        }
        case 'file_accept_share': {
            $fsId = trim($_POST['fs_id'] ?? '');
            if (!$fsId) { $msg = 'error:Invalid share ID.'; break; }
            $myF = __DIR__ . '/dash_shares_' . preg_replace('/[^a-z0-9_\-]/i', '', $_ou) . '.json';
            $myS = json_decode(@file_get_contents($myF) ?: '[]', true) ?: [];
            $item = null; $rest = [];
            foreach ($myS as $s) { if (($s['id']??'') === $fsId) $item = $s; else $rest[] = $s; }
            if (!$item) { $msg = 'error:Share not found.'; break; }
            if (($item['type'] ?? '') === 'links_col' && isset($item['data'])) {
                $col = $item['data'];
                $col['id'] = 'col_' . uniqid();
                $col['title'] = ($col['title'] ?? 'Shared Column') . ' (from ' . htmlspecialchars($item['from'] ?? '?') . ')';
                $existing = dashGetLinks($_odb, $_ou);
                $existing[] = $col;
                dashSaveLinks($_odb, $_ou, $existing);
            }
            file_put_contents($myF, json_encode(array_values($rest), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), LOCK_EX);
            $msg = 'success:Column added to your dashboard — reload the dashboard to see it.';
            break;
        }
        case 'file_dismiss_share': {
            $fsId = trim($_POST['fs_id'] ?? '');
            if (!$fsId) { $msg = 'error:Invalid ID.'; break; }
            $myF = __DIR__ . '/dash_shares_' . preg_replace('/[^a-z0-9_\-]/i', '', $_ou) . '.json';
            $myS = json_decode(@file_get_contents($myF) ?: '[]', true) ?: [];
            $myS = array_values(array_filter($myS, fn($s) => ($s['id'] ?? '') !== $fsId));
            file_put_contents($myF, json_encode($myS, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), LOCK_EX);
            $msg = 'success:Share dismissed.';
            break;
        }
        case 'link_device_profile': {
            $ldUuid    = preg_replace('/[^0-9a-f\-]/', '', $_POST['ld_uuid'] ?? '');
            $ldProfile = trim($_POST['ld_profile'] ?? '');
            if (strlen($ldUuid) !== 36) { $msg = 'error:Invalid device UUID.'; break; }
            $dpFile = __DIR__ . '/dash_device_profiles_' . preg_replace('/[^a-z0-9_\-]/i', '', $_ou) . '.json';
            $dpMap  = json_decode(@file_get_contents($dpFile) ?: '{}', true) ?: [];
            if ($ldProfile === '') { unset($dpMap[$ldUuid]); }
            else { $dpMap[$ldUuid] = $ldProfile; }
            file_put_contents($dpFile, json_encode($dpMap, JSON_PRETTY_PRINT), LOCK_EX);
            $msg = $ldProfile ? 'success:Device linked to profile "'.htmlspecialchars($ldProfile).'".' : 'success:Device profile link removed.';
            break;
        }

        case 'create_share':
            if (!$_odb) { $msg = 'error:MySQL required for sharing.'; break; }
            $sType    = trim($_POST['share_type'] ?? '');
            $sResId   = trim($_POST['share_resource_id'] ?? '');
            $sResName = htmlspecialchars(trim($_POST['share_resource_name'] ?? ''));
            $sTo      = trim($_POST['share_to_user'] ?? '');
            $allU     = array_column(dashGetUsers($_odb), 'username');
            $allU[]   = $cfg['username'];
            if (!$sType || !$sResId || !$sTo) { $msg = 'error:Missing share parameters.'; break; }
            if (!in_array($sTo, $allU))        { $msg = 'error:Unknown target user.'; break; }
            if ($sTo === $_ou)                 { $msg = 'error:Cannot share with yourself.'; break; }
            dashCreateShare($_odb, $_ou, $sTo, $sType, $sResId, $sResName);
            $msg = 'success:Shared "'.$sResName.'" with '.htmlspecialchars($sTo).'!';
            break;

        case 'accept_share':
            if (!$_odb) { $msg = 'error:MySQL required.'; break; }
            $shId = (int)($_POST['share_id'] ?? 0);
            if (!$shId) { $msg = 'error:Invalid share.'; break; }
            dashUpdateShare($_odb, $shId, 'accepted', $_ou);
            $msg = 'success:Share accepted! Reload your dashboard to see it.';
            break;

        case 'decline_share':
            if (!$_odb) { $msg = 'error:MySQL required.'; break; }
            $shId = (int)($_POST['share_id'] ?? 0);
            if (!$shId) { $msg = 'error:Invalid share.'; break; }
            dashUpdateShare($_odb, $shId, 'declined', $_ou);
            $msg = 'success:Share declined.';
            break;

        case 'revoke_share':
            if (!$_odb) { $msg = 'error:MySQL required.'; break; }
            $shId = (int)($_POST['share_id'] ?? 0);
            if (!$shId) { $msg = 'error:Invalid share.'; break; }
            dashRevokeShare($_odb, $shId, $_ou);
            $msg = 'success:Share revoked.';
            break;

        // ── Import theme ZIP (uploaded file) ─────────────────────────────────
        case 'import_theme_zip':
            $uf = $_FILES['theme_zip'] ?? null;
            if (!$uf || $uf['error'] !== UPLOAD_ERR_OK) { $msg = 'error:Upload failed or no file chosen.'; break; }
            if (!class_exists('ZipArchive')) { $msg = 'error:ZipArchive not available on this server.'; break; }
            $zip = new ZipArchive();
            if ($zip->open($uf['tmp_name']) !== true) { $msg = 'error:Cannot open ZIP file.'; break; }
            $manifestRaw = $zip->getFromName('manifest.json');
            if ($manifestRaw === false) { $zip->close(); $msg = 'error:No manifest.json found in ZIP.'; break; }
            $manifest = json_decode($manifestRaw, true);
            if (!is_array($manifest) || !isset($manifest['entries'])) { $zip->close(); $msg = 'error:Malformed manifest.json.'; break; }
            $tKey = preg_replace('/[^a-z0-9_-]/', '', $manifest['theme'] ?? '');
            if (!$tKey) { $zip->close(); $msg = 'error:Invalid theme name in manifest.'; break; }
            $curBgs   = dashGetCustomBgs($_odb, $_ou);
            $existing = $curBgs[$tKey] ?? [];
            if (is_array($existing) && isset($existing['type'])) $existing = [$existing];
            // Build sets of existing URLs and filenames for duplicate detection
            $existUrls  = array_column((array)$existing, 'url');
            $existFiles = array_map('basename', array_filter($existUrls));
            $added = 0; $skipped = 0;
            $imgDir = __DIR__ . '/uploads/';
            $vidDir = __DIR__ . '/videos/';
            @mkdir($imgDir, 0755, true);
            @mkdir($vidDir, 0755, true);
            foreach ((array)$manifest['entries'] as $entry) {
                $type    = $entry['type'] ?? 'video_url';
                $zipFile = $entry['zip_file'] ?? '';
                if ($zipFile && in_array($type, ['image_upload','video_upload'])) {
                    $fname = basename($zipFile);
                    if (in_array($fname, $existFiles)) { $skipped++; continue; }
                    $dir   = ($type === 'image_upload') ? $imgDir : $vidDir;
                    $dest  = $dir . $fname;
                    $data  = $zip->getFromName($zipFile);
                    if ($data === false) { $skipped++; continue; }
                    file_put_contents($dest, $data);
                    $relUrl = ($type === 'image_upload' ? 'uploads/' : 'videos/') . $fname;
                    $newEntry = $entry; $newEntry['url'] = $relUrl; unset($newEntry['zip_file']);
                    $existing[] = $newEntry; $added++;
                } else {
                    $url = $entry['url'] ?? '';
                    if ($url && in_array($url, $existUrls)) { $skipped++; continue; }
                    $existing[] = $entry; $added++;
                }
            }
            $zip->close();
            $curBgs[$tKey] = array_values($existing);
            dashSetCustomBgs($_odb, $_ou, $curBgs);
            $msg = 'success:Imported ' . $added . ' background(s) into theme "' . htmlspecialchars($tKey) . '"' . ($skipped ? ' (' . $skipped . ' duplicate(s) skipped)' : '') . '. Go to Themes tab to activate.';
            break;

        // ── Import a shared column or widget JSON ─────────────────────────────
        case 'import_shared_json':
            $rawJson = trim($_POST['shared_json'] ?? '');
            if (!$rawJson) { $msg = 'error:No JSON provided.'; break; }
            $shData = json_decode($rawJson, true);
            if (!is_array($shData)) { $msg = 'error:Invalid or malformed JSON — make sure you copied the full JSON.'; break; }
            $itype = $shData['type'] ?? '';
            if ($itype === 'column_import') {
                if (!isset($shData['column']) || !is_array($shData['column'])) { $msg = 'error:Missing column data in JSON.'; break; }
                $col = $shData['column'];
                $col['id'] = 'col_' . substr(md5(uniqid('', true)), 0, 8);
                $curLinks = dashGetLinks($_odb, $_ou);
                $curLinks[] = $col;
                dashSetLinks($_odb, $_ou, $curLinks);
                $msg = 'success:Column "' . htmlspecialchars($col['title'] ?? 'Imported') . '" added to your dashboard! Reload the main page to see it.';
            } elseif (preg_match('/^(html|rss|camera|calendar|countdown)_widget_import$/', $itype, $wm)) {
                $wtype = $wm[1];
                if (!isset($shData['widget']) || !is_array($shData['widget'])) { $msg = 'error:Missing widget data in JSON.'; break; }
                $w = $shData['widget'];
                $w['id'] = $wtype[0] . 'w-' . time();
                $w['x'] = 820; $w['y'] = 80;
                $wlist = dashGetWidgets($_odb, $_ou, $wtype);
                $wlist[] = $w;
                dashSetWidgets($_odb, $_ou, $wtype, $wlist);
                $msg = 'success:Widget "' . htmlspecialchars($w['name'] ?? 'Imported') . '" added! Reload the main dashboard to see it.';
            } elseif ($itype === 'settings_import') {
                if (isset($shData['settings']) && is_array($shData['settings']))
                    dashSetSettings($_odb, $_ou, $shData['settings']);
                $msg = 'success:Settings imported! Reload the dashboard to apply.';
            } elseif ($itype === 'theme_pack_import') {
                // ── Theme Pack import ──────────────────────────────────────────
                // Imports:  1) custom_theme colors (the 🎨 Custom Theme color set)
                //           2) custom_bgs for each theme key — even brand-new keys
                //              that don't exist in the built-in theme list are stored;
                //              they will appear as variants under that theme whenever
                //              it is selected on the dashboard.
                $imported = [];

                // 1. Custom theme colors / wallpaper
                if (!empty($shData['custom_theme']) && is_array($shData['custom_theme'])) {
                    $ct = $shData['custom_theme'];
                    // Whitelist safe keys — reject anything that could carry HTML
                    $allowed_ct = ['bg','card_bg','border_light','border_dark','card_text',
                                   'hover_bg','hover_text','sec_from','sec_to','sec_text',
                                   'radius','font','wallpaper'];
                    $clean_ct = [];
                    foreach ($allowed_ct as $k) {
                        if (isset($ct[$k])) $clean_ct[$k] = htmlspecialchars((string)$ct[$k], ENT_QUOTES);
                    }
                    if ($clean_ct) {
                        dashSetSetting($_odb, $_ou, 'custom_theme', json_encode($clean_ct));
                        $imported[] = 'custom theme colors';
                    }
                }

                // 2. Custom backgrounds (merge — never wipe existing bgs)
                if (!empty($shData['custom_bgs']) && is_array($shData['custom_bgs'])) {
                    $curBgs  = dashGetCustomBgs($_odb, $_ou);
                    $bgCount = 0;
                    $allowed_types = ['image_url','video_url','iframe_url','image_upload','video_upload'];
                    foreach ($shData['custom_bgs'] as $tk => $entries) {
                        // Sanitise theme key — allow brand-new keys (not just built-ins)
                        $tk = preg_replace('/[^a-z0-9_-]/', '', strtolower((string)$tk));
                        if (!$tk || !is_array($entries)) continue;
                        if (!isset($curBgs[$tk])) $curBgs[$tk] = [];
                        foreach ($entries as $entry) {
                            if (!is_array($entry) || empty($entry['url'])) continue;
                            $et = $entry['type'] ?? '';
                            if (!in_array($et, $allowed_types)) continue;
                            $clean = [
                                'name' => htmlspecialchars(substr((string)($entry['name'] ?? 'Imported'), 0, 80), ENT_QUOTES),
                                'type' => $et,
                                'url'  => $entry['url'], // URLs are used verbatim; renderer sanitises on display
                            ];
                            if (!empty($entry['tile'])) $clean['tile'] = true;
                            $curBgs[$tk][] = $clean;
                            $bgCount++;
                        }
                    }
                    if ($bgCount) {
                        dashSetCustomBgs($_odb, $_ou, $curBgs);
                        $imported[] = $bgCount . ' background(s) across ' . count($shData['custom_bgs']) . ' theme(s)';
                    }
                }

                if ($imported) {
                    $msg = 'success:Theme pack imported: ' . implode(', ', $imported) . '. Reload the dashboard, then activate "🎨 Custom Theme" to use the imported colors.';
                } else {
                    $msg = 'error:Theme pack contained no importable data — it may be empty or already using only local uploaded files.';
                }
            } else {
                $msg = 'error:Unknown import type "' . htmlspecialchars($itype) . '". Make sure you are pasting JSON exported from this dashboard.';
            }
            break;
    }
    // Re-read config after saves
    $cfg = getDashConfig();
}

// ─── Load data (MySQL-first, JSON fallback) ───────────────────────────────────
$_opt_settings = dashGetSettings($_odb, $_ou);
$_dr_opt       = $_opt_settings['drives']  ?? null;
$drives        = $_dr_opt !== null ? (json_decode($_dr_opt, true) ?: [])
               : (json_decode(@file_get_contents(__DIR__.'/dash_drives.json') ?: '[]', true) ?: []);
$bgs           = dashGetCustomBgs($_odb, $_ou);
$videos        = [];  // legacy — no longer primary
$links         = dashGetLinks($_odb, $_ou);
// Backup info for Links tab UI
$_links_backup_raw = $_odb
    ? dashGetSetting($_odb, $_ou, 'dash_links_backup', '')
    : @file_get_contents(__DIR__.'/dash_links_backup.json');
$_links_backup = $_links_backup_raw ? json_decode($_links_backup_raw, true) : null;
$_links_backup_at  = $_links_backup['saved_at']  ?? null;
$_links_backup_cnt = isset($_links_backup['links']) ? count($_links_backup['links']) : 0;
$_ct_opt       = $_opt_settings['custom_theme'] ?? null;
$custom_theme  = $_ct_opt !== null ? (json_decode($_ct_opt, true) ?: [])
               : (json_decode(@file_get_contents(__DIR__.'/dash_custom_theme.json') ?: '{}', true) ?: []);
$_mon_opt      = $_opt_settings['monitor'] ?? null;
$monitor       = $_mon_opt !== null ? (json_decode($_mon_opt, true) ?: [])
               : (json_decode(@file_get_contents(__DIR__.'/dash_monitor.json') ?: '{}', true) ?: []);
$html_widgets  = dashGetWidgets($_odb, $_ou, 'html');
$dash_state    = $_opt_settings; // all settings as flat map
// Current logo file (if any)
$_opt_logo = '';
foreach (['jpg','jpeg','png','gif','webp','svg'] as $_lx) {
    if (file_exists(__DIR__.'/uploads/site_logo.'.$_lx)) { $_opt_logo = 'uploads/site_logo.'.$_lx; break; }
}

$_all_machines  = dashGetAllMachines($_odb, $_ou);
$_update_url    = dashGetSetting($_odb, $_ou, 'update_url', '');
$_my_shares_out = dashGetSharesFrom($_odb, $_ou);
$_my_shares_in  = dashGetSharesTo($_odb, $_ou);
// File-based shares for current user (no MySQL required)
$_fs_myfile  = __DIR__ . '/dash_shares_' . preg_replace('/[^a-z0-9_\-]/i', '', $_ou) . '.json';
$_fs_inbox   = json_decode(@file_get_contents($_fs_myfile) ?: '[]', true) ?: [];
// Device-profile map
$_dp_file_opt = __DIR__ . '/dash_device_profiles_' . preg_replace('/[^a-z0-9_\-]/i', '', $_ou) . '.json';
$_dp_map_opt  = json_decode(@file_get_contents($_dp_file_opt) ?: '{}', true) ?: [];
// Profile list for device-link dropdown
$_dp_profiles = array_column(dashGetProfiles($_odb, $_ou), 'profile_name');
$_opt_rss_w     = dashGetWidgets($_odb, $_ou, 'rss');
$_opt_cam_w2    = dashGetWidgets($_odb, $_ou, 'camera');
$_opt_cal_w2    = dashGetWidgets($_odb, $_ou, 'calendar');
$_opt_wxc_w     = dashGetWidgets($_odb, $_ou, 'weather_city');
$_opt_tz_w      = dashGetWidgets($_odb, $_ou, 'timezone');
$_opt_cd_w      = dashGetWidgets($_odb, $_ou, 'countdown');
$_opt_sn_raw    = dashGetSetting($_odb, $_ou, 'sticky_notes', '[]');
$_opt_stickies  = json_decode($_opt_sn_raw ?: '[]', true) ?: [];
$_all_users_share = array_filter(
    array_merge([['username'=>$cfg['username']]], dashGetUsers($_odb)),
    fn($u) => ($u['username'] ?? '') !== $_ou
);

// Variant-only themes: accessible via the variant dropdown on the dashboard,
// not as standalone theme choices. Excluded from Theme Visibility list.
$theme_variants_only = ['winxp2', 'jellybean2', 'palmtreo'];

$themes = [
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
    'palmv'       => '🔳 Palm V / Vx',
    'palmpilot'   => '📟 Palm Pilot',
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
    'cute'       => '🌸 Cute',
    'spring'      => '🌷 Spring',
    'summer'      => '☀️ Summer',
    'autumn'      => '🍂 Autumn',
    'winter'      => '❄️ Winter',
    'thanksgiving'=> '🦃 Thanksgiving',
    'july4'       => '🎆 July 4th',
    'christmas'   => '✝️ Christmas',
    'amiga'       => '🖥 Amiga Workbench',
    'nextstep'    => '⬛ NeXTSTEP',
    'beos'        => '🟡 BeOS',
    'norton'      => '💙 DOS / Norton Commander',
    'atarist'     => '🕹 Atari ST / TOS',
    'irix'        => '🌊 IRIX / SGI',
    'miku'        => '🎵 Hatsune Miku',
    'custom'      => '🎨 Custom Theme',
];

// Load hidden themes list (MySQL-first)
$_ht_opt       = $_opt_settings['hidden_themes'] ?? null;
$hidden_themes = $_ht_opt !== null ? (json_decode($_ht_opt, true) ?: [])
               : (json_decode(@file_get_contents(__DIR__.'/dash_hidden_themes.json') ?: '[]', true) ?: []);

// Load hidden columns list from MySQL (authoritative across all browsers)
$_hidden_cols_raw = $_opt_settings['dash_hidden_cols'] ?? '[]';

// Load sub-users (MySQL-first)
$sub_users = dashGetUsers($_odb);

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
  <?php if ($_dash_is_admin): ?>
  <div class="tab" onclick="showTab('drives')">💾 Drives</div>
  <?php endif; ?>
  <div class="tab" onclick="showTab('themes')">🎭 Themes</div>
  <div class="tab" onclick="showTab('customtheme')">🎨 Custom Theme</div>
  <div class="tab" onclick="showTab('links')">🔗 Links</div>
  <div class="tab" onclick="showTab('widgets')">🧩 Widgets</div>
  <?php if ($_dash_is_admin): ?>
  <div class="tab" onclick="showTab('machines')">🖥 This Device</div>
  <div class="tab" onclick="showTab('users')">👥 Users</div>
  <?php endif; ?>
  <div class="tab" onclick="showTab('password')">🔐 Password</div>
  <div class="tab" onclick="showTab('export')">📤 Export</div>
  <?php if ($_dash_is_admin): ?>
  <div class="tab" onclick="showTab('update')">🔄 Update</div>
  <?php endif; ?>
  <div class="tab" onclick="showTab('changelog')">📋 Changelog</div>
  <?php if ($_dash_is_admin): ?>
  <div class="tab" onclick="showTab('mysql')" id="tab-btn-mysql">🗄 MySQL<?php if($_odb): ?> <span style="background:#0a5;color:#fff;border-radius:10px;padding:1px 5px;font-size:9px;margin-left:3px;">●</span><?php endif; ?></div>
  <?php endif; ?>
  <div class="tab" onclick="showTab('sharing')" id="tab-btn-sharing">🔗 Sharing<?php
    $__pnd = count($_fs_inbox);
    if ($_odb) { foreach ($_my_shares_in as $_si) if ($_si['status']==='pending') $__pnd++; }
    if ($__pnd > 0): ?> <span style="background:#e04;color:#fff;border-radius:10px;padding:1px 6px;font-size:10px;margin-left:4px;"><?= $__pnd ?></span><?php endif; ?></div>
</div>

<!-- ===== GENERAL ===== -->
<div id="tab-general" class="tab-content active">
  <?php if ($_dash_is_admin): ?>
  <div class="section" style="margin-bottom:16px;">
    <h2>⚙️ General Settings</h2>
    <form method="POST">
      <input type="hidden" name="action" value="save_settings">
      <label>Dashboard Title</label>
      <input type="text" name="dash_title" value="<?= htmlspecialchars($cfg['title']) ?>" placeholder="Server Dashboard">
      <div style="margin-top:16px;"><button type="submit" class="btn btn-primary">💾 Save Settings</button></div>
    </form>
  </div>
  <?php endif; ?>

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

  <?php if ($_dash_is_admin): ?>
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
  <?php endif; ?>
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
            <div class="fit-row" style="display:none;align-items:center;gap:8px;padding:4px 0;flex-wrap:wrap;">
              <label style="font-size:12px;color:rgba(255,255,255,.6);white-space:nowrap;margin:0;">Image fit:</label>
              <select name="bg_fit" style="background:#1a1a2e;color:#fff;border:1px solid rgba(255,255,255,.2);border-radius:6px;padding:4px 9px;font-size:12px;">
                <option value="fill">🖼 Fill (cover, no distortion)</option>
                <option value="stretch">↔ Stretch (distort to exact size)</option>
                <option value="center">⊙ Center (natural size, centered)</option>
                <option value="tile">🪟 Tile (repeat like wallpaper)</option>
              </select>
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
            <select name="upload_type" onchange="uploadTypeChange(this)" style="max-width:140px;">
              <option value="video">🎬 Upload Video</option>
              <option value="image">🖼 Upload Image</option>
            </select>
            <div class="fit-row" style="display:none;align-items:center;gap:8px;padding:2px 0;flex-wrap:wrap;">
              <label style="font-size:12px;color:rgba(255,255,255,.6);white-space:nowrap;margin:0;">Image fit:</label>
              <select name="bg_fit" style="background:#1a1a2e;color:#fff;border:1px solid rgba(255,255,255,.2);border-radius:6px;padding:4px 9px;font-size:12px;">
                <option value="fill">🖼 Fill (cover, no distortion)</option>
                <option value="stretch">↔ Stretch (distort to exact size)</option>
                <option value="center">⊙ Center (natural size, centered)</option>
                <option value="tile">🪟 Tile (repeat like wallpaper)</option>
              </select>
            </div>
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
    <h2>🗂 Document Folders</h2>
    <p style="font-size:12px;color:rgba(255,255,255,.4);margin-bottom:14px;">Manage folders shown in the Documents panel on your dashboard.</p>
    <?php
    $docBase = __DIR__ . '/uploads/docs/' . ($user ?? 'admin');
    $docFolders = [];
    if (is_dir($docBase)) {
        foreach (scandir($docBase) as $entry) {
            if ($entry === '.' || $entry === '..') continue;
            $dp = $docBase . '/' . $entry;
            if (!is_dir($dp) || !file_exists($dp . '/_meta.json')) continue;
            $m = @json_decode(@file_get_contents($dp . '/_meta.json'), true) ?: [];
            $fileCount = max(0, count(glob($dp . '/*')) - 1);
            $docFolders[] = ['dk' => $entry, 'label' => $m['label'] ?? $entry, 'icon' => $m['icon'] ?? '📁', 'pinned_type' => $m['pinned_type'] ?? 'all', 'count' => $fileCount];
        }
    }
    $typeLabels = ['all'=>'📂 All','image'=>'🖼️ Images','video'=>'🎬 Videos','audio'=>'🎵 Audio','doc'=>'📄 Documents','archive'=>'🗜️ Archives','other'=>'📎 Other'];
    ?>
    <?php
    $typeIcons = ['all'=>'📁','image'=>'🖼️','video'=>'🎬','audio'=>'🎵','doc'=>'📄','archive'=>'🗜️','other'=>'📎'];
    ?>
    <div style="display:flex;flex-direction:column;gap:8px;margin-bottom:16px;">
    <?php foreach ($docFolders as $df):
      $fIcon = $df['pinned_type'] !== 'all' ? ($typeIcons[$df['pinned_type']] ?? '📁') : $df['icon'];
    ?>
      <div class="doc-folder-card" style="background:rgba(255,255,255,.05);border:1px solid rgba(255,255,255,.1);border-radius:8px;overflow:hidden;">
        <div style="display:flex;align-items:center;gap:10px;padding:8px 12px;font-size:13px;">
          <span style="font-size:20px;"><?= htmlspecialchars($fIcon) ?></span>
          <span style="flex:1;font-weight:600;"><?= htmlspecialchars($df['label']) ?></span>
          <span style="font-size:11px;background:rgba(255,255,255,.12);padding:2px 8px;border-radius:10px;white-space:nowrap;"><?= $typeLabels[$df['pinned_type']] ?? $df['pinned_type'] ?></span>
          <span style="font-size:11px;opacity:.4;"><?= $df['count'] ?> file<?= $df['count'] !== 1 ? 's' : '' ?></span>
          <button type="button" class="btn btn-sm btn-secondary" style="font-size:11px;padding:3px 8px;" onclick="this.closest('.doc-folder-card').querySelector('.rename-row').classList.toggle('hidden-row')">✏️ Rename</button>
          <form method="POST" style="margin:0;" onsubmit="return confirm('Delete folder \'<?= htmlspecialchars(addslashes($df['label'])) ?>\' and ALL its files?');">
            <input type="hidden" name="action" value="delete_doc_folder">
            <input type="hidden" name="dk" value="<?= htmlspecialchars($df['dk']) ?>">
            <button type="submit" class="btn btn-danger btn-sm" style="font-size:11px;padding:3px 8px;">🗑</button>
          </form>
        </div>
        <form method="POST" class="rename-row hidden-row" style="display:flex;gap:8px;padding:8px 12px;border-top:1px solid rgba(255,255,255,.08);background:rgba(0,0,0,.2);">
          <input type="hidden" name="action" value="rename_doc_folder">
          <input type="hidden" name="dk" value="<?= htmlspecialchars($df['dk']) ?>">
          <input type="text" name="label" placeholder="New name…" value="<?= htmlspecialchars($df['label']) ?>" required style="flex:1;padding:5px 8px;background:rgba(255,255,255,.07);border:1px solid rgba(255,255,255,.15);border-radius:5px;color:#fff;font-size:12px;">
          <button type="submit" class="btn btn-primary btn-sm" style="font-size:11px;padding:4px 12px;">Save</button>
        </form>
      </div>
    <?php endforeach; ?>
    <?php if (!$docFolders): ?>
      <p style="font-size:12px;opacity:.4;">No folders yet. Create one below.</p>
    <?php endif; ?>
    </div>
    <style>
    .hidden-row{display:none!important;}
    .doc-folder-card .rename-row{display:flex;}
    </style>
    <script>
    document.querySelectorAll('.doc-folder-card').forEach(c=>{
      c.querySelector('.rename-row')?.classList.add('hidden-row');
    });
    </script>
    <!-- New folder form -->
    <div style="background:rgba(255,255,255,.04);border:1px solid rgba(255,255,255,.1);border-radius:8px;padding:14px 16px;">
      <div style="font-size:13px;font-weight:600;margin-bottom:12px;">+ New Folder</div>
      <form method="POST" id="new-doc-folder-form">
        <input type="hidden" name="action" value="add_doc_folder">
        <div style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:10px;">
          <input type="text" name="label" id="ndf-label" placeholder="Folder name…" required style="flex:1;min-width:140px;padding:7px 10px;background:rgba(255,255,255,.07);border:1px solid rgba(255,255,255,.15);border-radius:6px;color:#fff;font-size:13px;">
        </div>
        <div style="display:flex;gap:10px;margin-bottom:12px;">
          <label style="display:flex;align-items:center;gap:8px;cursor:pointer;padding:8px 14px;border:2px solid rgba(255,255,255,.15);border-radius:8px;flex:1;transition:.15s;" id="ndf-opt-all">
            <input type="radio" name="pin_choice" value="all" checked onchange="ndfToggle(this)" style="display:none">
            <span style="font-size:22px;">📁</span>
            <div><div style="font-size:13px;font-weight:600;">See All</div><div style="font-size:11px;opacity:.5;">No filter — shows every file</div></div>
          </label>
          <label style="display:flex;align-items:center;gap:8px;cursor:pointer;padding:8px 14px;border:2px solid rgba(255,255,255,.15);border-radius:8px;flex:1;transition:.15s;" id="ndf-opt-pin">
            <input type="radio" name="pin_choice" value="pin" onchange="ndfToggle(this)" style="display:none">
            <span style="font-size:22px;">📌</span>
            <div><div style="font-size:13px;font-weight:600;">Pin Category</div><div style="font-size:11px;opacity:.5;">Auto-filter on open</div></div>
          </label>
        </div>
        <div id="ndf-type-row" style="display:none;margin-bottom:12px;">
          <div style="font-size:12px;opacity:.5;margin-bottom:6px;">Choose category to pin:</div>
          <div style="display:flex;flex-wrap:wrap;gap:8px;" id="ndf-type-btns">
            <?php foreach ($typeLabels as $tv => $tl): if($tv==='all') continue; ?>
            <label style="display:flex;align-items:center;gap:6px;cursor:pointer;padding:7px 12px;border:2px solid rgba(255,255,255,.12);border-radius:8px;font-size:12px;transition:.15s;">
              <input type="radio" name="pinned_type" value="<?= $tv ?>" style="display:none" onchange="ndfTypeSelect(this)">
              <span><?= $tl ?></span>
            </label>
            <?php endforeach; ?>
            <input type="hidden" name="pinned_type" id="ndf-type-val" value="all">
          </div>
        </div>
        <input type="hidden" name="icon" id="ndf-icon-val" value="📁">
        <button type="submit" class="btn btn-primary" style="padding:8px 20px;">+ Create Folder</button>
      </form>
    </div>
    <script>
    function ndfToggle(radio) {
      const isPin = radio.value === 'pin';
      document.getElementById('ndf-type-row').style.display = isPin ? '' : 'none';
      if (!isPin) { document.getElementById('ndf-type-val').value = 'all'; document.getElementById('ndf-icon-val').value = '📁'; }
      document.getElementById('ndf-opt-all').style.borderColor = isPin ? 'rgba(255,255,255,.15)' : '#4a9eff';
      document.getElementById('ndf-opt-pin').style.borderColor = isPin ? '#4a9eff' : 'rgba(255,255,255,.15)';
    }
    function ndfTypeSelect(radio) {
      document.querySelectorAll('#ndf-type-btns label').forEach(l => l.style.borderColor = 'rgba(255,255,255,.12)');
      radio.closest('label').style.borderColor = '#4a9eff';
      document.getElementById('ndf-type-val').value = radio.value;
      const icons = {image:'🖼️',video:'🎬',audio:'🎵',doc:'📄',archive:'🗜️',other:'📎'};
      document.getElementById('ndf-icon-val').value = icons[radio.value] || '📁';
    }
    // Highlight "See All" by default
    document.getElementById('ndf-opt-all').style.borderColor = '#4a9eff';
    </script>
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
        <?php if ($hasBg): ?>
        <a href="options.php?export_theme_zip=1&theme=<?= urlencode($key) ?>" class="btn btn-sm btn-secondary" style="padding:4px 8px;font-size:11px;text-decoration:none;" title="Export this theme's backgrounds as ZIP">📦</a>
        <?php endif; ?>
        <?php if ($_dash_is_admin): ?>
        <form method="POST" style="margin:0;">
          <input type="hidden" name="action" value="toggle_hidden_theme">
          <input type="hidden" name="theme" value="<?= htmlspecialchars($key) ?>">
          <button type="submit" class="btn btn-sm <?= $hidden?'btn-primary':'btn-danger' ?>" style="padding:4px 10px;font-size:11px;">
            <?= $hidden ? '👁 Show' : '🙈 Hide' ?>
          </button>
        </form>
        <?php endif; ?>
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

  <!-- AUTO-BACKUP RESTORE -->
  <div class="section" style="margin-bottom:16px;border:1px solid rgba(255,165,0,.35);background:rgba(255,140,0,.07);">
    <h2>💾 Column Backup &amp; Recovery</h2>
    <p style="font-size:12px;color:rgba(255,255,255,.55);margin-bottom:12px;">
      Every time your dashboard saves links successfully, a backup snapshot is created automatically.
      If your columns ever disappear, click <strong>Restore Backup</strong> to bring them back instantly.
    </p>
    <?php if ($_links_backup_at): ?>
      <p style="font-size:12px;color:#6ee7b7;margin-bottom:12px;">
        ✅ Last backup: <strong><?= htmlspecialchars($_links_backup_at) ?></strong>
        — <?= (int)$_links_backup_cnt ?> column(s) stored
      </p>
    <?php else: ?>
      <p style="font-size:12px;color:rgba(255,255,255,.4);margin-bottom:12px;">
        ⚠ No backup yet — open your dashboard and make any edit to create the first backup automatically.
      </p>
    <?php endif; ?>
    <form method="POST" style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;"
          onsubmit="return <?= $_links_backup_at ? 'confirm(\'Restore ' . (int)$_links_backup_cnt . ' column(s) from the backup dated ' . addslashes($_links_backup_at) . '? Your current columns will be overwritten.\')' : 'alert(\'No backup found yet — make a save from the dashboard first.\') || false' ?>;">
      <input type="hidden" name="action" value="restore_links_backup">
      <button type="submit" class="btn btn-primary" <?= $_links_backup_at ? '' : 'disabled' ?>>
        🔄 Restore Column Backup
      </button>
      <span style="font-size:11px;color:rgba(255,255,255,.35);">Overwrites current columns with the backup snapshot</span>
    </form>
  </div>

  <!-- RESTORE DEFAULT LINKS -->
  <div class="section" style="margin-bottom:16px;">
    <h2>🔄 Restore Default Columns</h2>
    <p style="font-size:12px;color:rgba(255,255,255,.45);margin-bottom:14px;">
      Adds the original starter columns (Search, Email, Development, Media, Social) back to your dashboard. Any columns you already have with those names are left untouched — only missing ones are added.
    </p>
    <form method="POST" style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;">
      <input type="hidden" name="action" value="restore_default_links">
      <button type="submit" class="btn btn-secondary">🔄 Restore Default Columns</button>
      <span style="font-size:11px;color:rgba(255,255,255,.35);">Safe to run — won't overwrite existing columns</span>
    </form>
  </div>

  <!-- ADD PRESET COLUMN (v1.4.3) -->
  <div class="section" style="margin-bottom:16px;">
    <h2>📦 Add Preset Column</h2>
    <p style="font-size:12px;color:rgba(255,255,255,.45);margin-bottom:14px;">
      Drop a fully-stocked starter column onto your dashboard. Each one ships
      with hand-picked links for that category. Click any preset to add it —
      if you already have one with the same name, the new one will be suffixed
      with <code>(2)</code> instead of overwriting.
    </p>
    <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(190px,1fr));gap:8px;">
      <?php foreach (dashGetPresets() as $_pk => $_pi):
        $_titles = implode(', ', array_map(fn($it) => $it['label'] ?? '', $_pi['items']));
      ?>
      <form method="POST" style="margin:0;">
        <input type="hidden" name="action" value="add_preset_column">
        <input type="hidden" name="preset_cat" value="<?= htmlspecialchars($_pk, ENT_QUOTES) ?>">
        <button type="submit"
                title="<?= htmlspecialchars($_titles, ENT_QUOTES) ?>"
                style="width:100%;display:flex;align-items:center;gap:10px;padding:10px 12px;background:rgba(255,255,255,.04);border:1px solid rgba(255,255,255,.1);border-radius:8px;color:#fff;font-size:13px;cursor:pointer;text-align:left;transition:background .15s,border .15s;"
                onmouseover="this.style.background='rgba(80,150,255,.18)';this.style.borderColor='rgba(80,150,255,.45)'"
                onmouseout="this.style.background='rgba(255,255,255,.04)';this.style.borderColor='rgba(255,255,255,.1)'">
          <span style="font-size:22px;line-height:1;flex:0 0 auto;"><?= $_pi['icon'] ?></span>
          <span style="flex:1;min-width:0;">
            <strong style="display:block;font-size:13px;color:#fff;margin-bottom:2px;"><?= htmlspecialchars($_pk) ?></strong>
            <span style="display:block;font-size:11px;color:rgba(255,255,255,.4);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;"><?= count($_pi['items']) ?> links · <?= htmlspecialchars($_pi['desc']) ?></span>
          </span>
          <span style="font-size:14px;opacity:.5;flex:0 0 auto;">＋</span>
        </button>
      </form>
      <?php endforeach; ?>
    </div>
  </div>

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
    <div class="links-list" id="manage-links-list">
      <?php foreach ($links as $si => $sec):
        $secIdSafe = htmlspecialchars($sec['id']??$sec['title']??'', ENT_QUOTES);
        $secTitleSafe = htmlspecialchars($sec['title']??'', ENT_QUOTES);
        $secIconSafe  = htmlspecialchars($sec['icon']??'', ENT_QUOTES);
      ?>
      <div class="link-sec" id="link-sec-<?= $secIdSafe ?>" data-sec="<?= $secIdSafe ?>">
        <h4 style="display:flex;align-items:center;gap:8px;"><?= htmlspecialchars($sec['icon']??'') ?> <?= htmlspecialchars($sec['title']) ?> <span class="link-sec-count" style="font-size:10px;color:rgba(255,255,255,.3);"><?= count($sec['cards']??[]) ?> links</span>
          <button class="btn btn-danger btn-sm" style="margin-left:auto;font-size:11px;padding:2px 8px;"
                  data-sec-id="<?= $secIdSafe ?>"
                  onclick="deleteColumn(this)">🗑 Delete Column</button>
        </h4>
        <?php foreach ($sec['cards'] ?? [] as $card): ?>
        <div class="link-card" data-url="<?= htmlspecialchars($card['url'], ENT_QUOTES) ?>">
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
            <button class="btn btn-secondary btn-sm"
                    data-sec-id="<?= $secIdSafe ?>"
                    data-url="<?= htmlspecialchars($card['url'], ENT_QUOTES) ?>"
                    onclick="openIconEdit(this)">🖼 Icon</button>
            <button class="btn btn-secondary btn-sm"
                    data-sec-id="<?= $secIdSafe ?>"
                    data-url="<?= htmlspecialchars($card['url'], ENT_QUOTES) ?>"
                    data-label="<?= htmlspecialchars($card['label'], ENT_QUOTES) ?>"
                    onclick="openMoveLink(this)">↪ Move</button>
            <button class="btn btn-danger btn-sm"
                    data-sec-id="<?= $secIdSafe ?>"
                    data-url="<?= htmlspecialchars($card['url'], ENT_QUOTES) ?>"
                    onclick="deleteLink(this)">🗑</button>
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
      <form enctype="multipart/form-data" id="icon-edit-form">
        <input type="hidden" name="action" value="update_link_icon">
        <input type="hidden" name="ajax" value="1">
        <input type="hidden" name="sec_id" id="ie-sec">
        <input type="hidden" name="url_key" id="ie-url">
        <label style="font-size:12px;color:rgba(255,255,255,.5);">Emoji / text icon</label>
        <input type="text" name="new_icon" id="ie-icon" placeholder="🔗 or leave blank if uploading image" style="margin-bottom:10px;">
        <label style="font-size:12px;color:rgba(255,255,255,.5);">— OR upload JPG/PNG/SVG image icon —</label>
        <input type="file" name="icon_image" id="ie-file" accept=".jpg,.jpeg,.png,.gif,.webp,.svg" style="margin:8px 0;color:#fff;">
        <div id="ie-status" style="font-size:12px;color:#6ee7b7;min-height:18px;margin-top:4px;"></div>
        <div style="display:flex;gap:8px;margin-top:10px;">
          <button type="submit" id="ie-save-btn" class="btn btn-primary">💾 Save</button>
          <button type="button" class="btn btn-secondary" onclick="document.getElementById('icon-edit-modal').style.display='none'">Cancel</button>
        </div>
      </form>
    </div>
  </div>

  <!-- Move Link Modal -->
  <div id="move-link-modal" style="display:none;position:fixed;inset:0;z-index:9999;background:rgba(0,0,0,.8);align-items:center;justify-content:center;">
    <div style="background:#1a1a2e;border:1px solid rgba(255,255,255,.2);border-radius:14px;padding:22px;width:380px;max-width:96vw;color:#fff;">
      <h3 style="margin-bottom:14px;">↪ Move "<span id="ml-label"></span>"</h3>
      <form id="move-link-form">
        <input type="hidden" name="action" value="move_link">
        <input type="hidden" name="ajax" value="1">
        <input type="hidden" name="from_sec" id="ml-from">
        <input type="hidden" name="url_key" id="ml-url">
        <label style="font-size:12px;color:rgba(255,255,255,.5);">Move to column / section</label>
        <select name="to_sec" id="ml-to-sec" style="margin-bottom:14px;">
          <?php foreach ($links as $sec): ?>
          <option value="<?= htmlspecialchars($sec['id']??$sec['title']??'', ENT_QUOTES) ?>"><?= htmlspecialchars($sec['title']) ?></option>
          <?php endforeach; ?>
        </select>
        <div id="ml-status" style="font-size:12px;color:#6ee7b7;min-height:18px;margin-bottom:6px;"></div>
        <div style="display:flex;gap:8px;">
          <button type="submit" id="ml-save-btn" class="btn btn-primary">Move</button>
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

  <!-- ===== All floating widgets: show / hide ===== -->
  <?php
  $opt_html_w = dashGetWidgets($_odb, $_ou, 'html');
  $opt_rss_w  = dashGetWidgets($_odb, $_ou, 'rss');
  $opt_cam_w  = dashGetWidgets($_odb, $_ou, 'camera');
  $opt_cal_w  = dashGetWidgets($_odb, $_ou, 'calendar');
  $_hidden_wids_opt = json_decode(dashGetSetting($_odb, $_ou, 'hidden_widgets', '[]') ?: '[]', true) ?: [];
  $all_float_widgets = [];
  foreach ($opt_html_w as $w) $all_float_widgets[] = ['id'=>'stat-'.preg_replace('/[^a-zA-Z0-9_-]/','',($w['id']??'')),'name'=>($w['name']??'Widget'),'type'=>'🧩'];
  foreach ($opt_rss_w  as $w) $all_float_widgets[] = ['id'=>'stat-'.preg_replace('/[^a-zA-Z0-9_-]/','',($w['id']??'')),'name'=>($w['name']??'RSS Feed'),'type'=>'📰'];
  foreach ($opt_cam_w  as $w) $all_float_widgets[] = ['id'=>'stat-'.preg_replace('/[^a-zA-Z0-9_-]/','',($w['id']??'')),'name'=>($w['name']??'Camera'),'type'=>'📷'];
  foreach ($opt_cal_w  as $w) $all_float_widgets[] = ['id'=>'stat-'.preg_replace('/[^a-zA-Z0-9_-]/','',($w['id']??'')),'name'=>($w['name']??'Calendar'),'type'=>'📅'];
  $all_float_widgets = array_filter($all_float_widgets, fn($w) => $w['id'] !== 'stat-');
  if (!empty($all_float_widgets)): ?>
  <div class="section" style="margin-bottom:16px;">
    <h2>👁 Your Floating Widgets — Show / Hide</h2>
    <p style="font-size:12px;color:rgba(255,255,255,.45);margin-bottom:14px;">
      Toggle which floating widgets are visible on the dashboard. Hidden widgets are removed from view
      but not deleted — they reappear when you toggle them back on.
    </p>
    <form method="POST">
      <input type="hidden" name="action" value="save_hidden_widgets">
      <input type="hidden" name="hidden_ids" id="hw-hidden-ids" value="<?= htmlspecialchars(implode(',', $_hidden_wids_opt)) ?>">
      <div style="display:flex;flex-direction:column;gap:10px;margin-bottom:16px;">
        <?php foreach ($all_float_widgets as $fw):
          $fwId      = $fw['id'];
          $fwName    = htmlspecialchars($fw['name']);
          $fwType    = $fw['type'];
          $isHidden  = in_array($fwId, $_hidden_wids_opt); ?>
        <label style="display:flex;align-items:center;gap:10px;cursor:pointer;background:rgba(255,255,255,.04);border:1px solid rgba(255,255,255,.08);border-radius:8px;padding:8px 12px;">
          <input type="checkbox" class="hw-toggle" data-id="<?= htmlspecialchars($fwId) ?>"
                 <?= $isHidden ? '' : 'checked' ?>
                 style="width:auto;height:16px;accent-color:#4a9eff;"
                 onchange="updateHiddenWidgets()">
          <span style="font-size:18px;"><?= $fwType ?></span>
          <span style="font-size:13px;font-weight:600;"><?= $fwName ?></span>
          <span style="font-size:11px;opacity:.4;margin-left:auto;"><?= htmlspecialchars($fwId) ?></span>
        </label>
        <?php endforeach; ?>
      </div>
      <button type="submit" class="btn btn-primary">💾 Save Widget Visibility</button>
    </form>
    <script>
    function updateHiddenWidgets(){
      const hidden=[];
      document.querySelectorAll('.hw-toggle').forEach(cb=>{
        if(!cb.checked) hidden.push(cb.dataset.id);
      });
      document.getElementById('hw-hidden-ids').value=hidden.join(',');
    }
    </script>
  </div>
  <?php endif; ?>

  <!-- ===== CRT Overlay toggle ===== -->
  <div class="section" style="margin-bottom:16px;">
    <h2>📺 CRT Screen Effect</h2>
    <p style="font-size:12px;color:rgba(255,255,255,.45);margin-bottom:14px;">
      Adds a retro scanline and phosphor-vignette overlay across the whole dashboard. Toggle on/off at any time using the button on your dashboard.
    </p>
    <form method="POST">
      <input type="hidden" name="action" value="save_theme_sounds">
      <div style="display:flex;gap:20px;margin-bottom:16px;flex-wrap:wrap;">
        <label style="display:flex;align-items:center;gap:8px;cursor:pointer;font-size:14px;color:#fff;">
          <input type="checkbox" name="theme_sound" value="1" <?= ($monitor['theme_sound']??true)?'checked':'' ?>
                 style="width:auto;height:16px;accent-color:#4a9eff;">
          🎵 Play startup sound when switching themes
        </label>
      </div>
      <p style="font-size:11px;color:rgba(255,255,255,.35);margin-bottom:14px;">
        Each retro theme has its own synthesized startup chime. Toggle on the dashboard with the 📺 button in the toolbar.
      </p>
      <button type="submit" class="btn btn-primary">💾 Save Sound Setting</button>
    </form>
  </div>

  <!-- ===== Restore hidden columns ===== -->
  <?php
  $_hc_raw = dashGetSetting($_odb, $_ou, 'dash_hidden_cols', '[]');
  $_hidden_cols_list = json_decode($_hc_raw ?: '[]', true) ?: [];
  if (!empty($_hidden_cols_list)): ?>
  <div class="section" style="margin-bottom:16px;">
    <h2>🔁 Restore Hidden Columns</h2>
    <p style="font-size:12px;color:rgba(255,255,255,.45);margin-bottom:14px;">
      These link columns were hidden with the × button. Click Restore to make them visible again on the dashboard.
    </p>
    <?php foreach ($_hidden_cols_list as $hc):
      $hcId    = is_string($hc) ? $hc : ($hc['id'] ?? '');
      $hcTitle = is_string($hc) ? $hc : ($hc['title'] ?? $hcId); ?>
    <form method="POST" style="display:inline-flex;align-items:center;gap:10px;margin-bottom:8px;background:rgba(255,255,255,.05);border:1px solid rgba(255,255,255,.08);border-radius:8px;padding:8px 12px;">
      <input type="hidden" name="action" value="restore_column">
      <input type="hidden" name="col_id" value="<?= htmlspecialchars($hcId) ?>">
      <span style="font-size:13px;flex:1;"><?= htmlspecialchars($hcTitle) ?></span>
      <button type="submit" class="btn btn-primary btn-sm">↩ Restore</button>
    </form>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>

  <!-- ===== Countdown Timer Widgets ===== -->
  <div class="section" style="margin-bottom:16px;">
    <h2>⏳ Add Countdown Timer</h2>
    <p style="font-size:12px;color:rgba(255,255,255,.45);margin-bottom:14px;">
      Add a live countdown to any date — vacations, deadlines, birthdays, launches. Appears as a floating draggable widget.
    </p>
    <form method="POST">
      <input type="hidden" name="action" value="add_countdown_widget">
      <label>Timer Name</label>
      <input type="text" name="cd_name" placeholder="e.g. Vacation 🏖" required>
      <label>Target Date / Time</label>
      <input type="datetime-local" name="cd_date" required style="margin-bottom:14px;">
      <button type="submit" class="btn btn-primary">➕ Add Countdown</button>
    </form>
  </div>
  <?php
  $opt_cd_widgets = dashGetWidgets($_odb, $_ou, 'countdown');
  if (!empty($opt_cd_widgets)): ?>
  <div class="section" style="margin-bottom:16px;">
    <h2>⏳ Your Countdown Timers</h2>
    <?php foreach ($opt_cd_widgets as $cd): ?>
    <div style="display:flex;align-items:center;gap:12px;padding:10px 0;border-bottom:1px solid rgba(255,255,255,.06);">
      <span style="font-size:20px;">⏳</span>
      <div style="flex:1;min-width:0;">
        <div style="font-weight:600;font-size:13px;"><?= htmlspecialchars($cd['name']) ?></div>
        <div style="font-size:11px;opacity:.4;"><?= htmlspecialchars($cd['target_date']) ?></div>
      </div>
      <form method="POST" style="margin:0;" onsubmit="return confirm('Delete countdown?')">
        <input type="hidden" name="action" value="delete_countdown_widget">
        <input type="hidden" name="cd_id" value="<?= htmlspecialchars($cd['id']) ?>">
        <button type="submit" class="btn btn-danger btn-sm">🗑 Delete</button>
      </form>
    </div>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>

  <!-- ===== Sticky Notes ===== -->
  <div class="section" style="margin-bottom:16px;">
    <h2>📌 Sticky Notes</h2>
    <p style="font-size:12px;color:rgba(255,255,255,.45);margin-bottom:14px;">
      Add draggable, resizable sticky notes to your dashboard. Notes auto-save as you type and persist across sessions.
    </p>
    <div style="display:flex;gap:10px;flex-wrap:wrap;margin-bottom:14px;">
      <button onclick="window.opener?window.opener._addStickyNote('#f6e87e'):alert('Open from dashboard')" class="btn btn-primary" style="background:#c8b800;border-color:#a09000;">🟡 Yellow Note</button>
      <button onclick="window.opener?window.opener._addStickyNote('#87ceeb'):alert('Open from dashboard')" class="btn btn-primary" style="background:#3a7fa0;border-color:#2a5f80;">🔵 Blue Note</button>
      <button onclick="window.opener?window.opener._addStickyNote('#90ee90'):alert('Open from dashboard')" class="btn btn-primary" style="background:#3a8040;border-color:#2a6030;">🟢 Green Note</button>
      <button onclick="window.opener?window.opener._addStickyNote('#ffb6c1'):alert('Open from dashboard')" class="btn btn-primary" style="background:#a04060;border-color:#803050;">🩷 Pink Note</button>
    </div>
    <p style="font-size:11px;color:rgba(255,255,255,.35);">
      Tip: You can also add sticky notes directly from your dashboard using the ✏️ Edit toolbar. Notes are saved instantly as you type.
    </p>
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
      <div style="margin-top:12px;margin-bottom:10px;">
        <button type="button" class="btn" onclick="toggleHwPreview()" style="background:rgba(255,255,255,.1);color:#fff;border:1px solid rgba(255,255,255,.15);font-size:12px;padding:6px 14px;">👁 Preview</button>
      </div>
      <div id="hw-preview-box" style="display:none;border:1px solid rgba(255,255,255,.12);border-radius:8px;padding:12px;margin-bottom:14px;background:rgba(255,255,255,.04);min-height:60px;max-height:300px;overflow:auto;" aria-label="Widget preview"></div>
      <div style="margin-top:14px;">
        <button type="submit" class="btn btn-primary">➕ Add Widget to Dashboard</button>
      </div>
    </form>
  </div>

  <!-- RSS Feed Widgets -->
  <div class="section" style="margin-bottom:16px;">
    <h2>📰 Add RSS Feed Widget</h2>
    <p style="font-size:12px;color:rgba(255,255,255,.45);margin-bottom:14px;">
      Enter any RSS or Atom feed URL. A scrollable news-feed widget will appear on the dashboard.
    </p>
    <form method="POST">
      <input type="hidden" name="action" value="add_rss_widget">
      <label>Widget Name</label>
      <input type="text" name="rw_name" placeholder="e.g. Hacker News, BBC News" required>
      <label>Feed URL</label>
      <input type="url" name="rw_url" placeholder="https://feeds.bbci.co.uk/news/rss.xml" required>
      <div style="display:flex;gap:16px;align-items:flex-end;flex-wrap:wrap;margin-top:8px;">
        <div style="flex:0 0 120px;">
          <label>Max Items</label>
          <input type="number" name="rw_max" value="8" min="3" max="30" style="width:100%;">
        </div>
        <div style="flex:1;">
          <button type="submit" class="btn btn-primary">➕ Add RSS Widget</button>
        </div>
      </div>
    </form>
  </div>

  <!-- Existing RSS widgets list -->
  <?php
  $rss_widgets = dashGetWidgets($_odb, $_ou, 'rss');
  if (!empty($rss_widgets)): ?>
  <div class="section" style="margin-bottom:16px;">
    <h2>📰 Your RSS Widgets</h2>
    <?php foreach ($rss_widgets as $rw): ?>
    <div style="display:flex;align-items:center;gap:12px;padding:10px 0;border-bottom:1px solid rgba(255,255,255,.06);">
      <div style="flex:0 0 24px;font-size:20px;">📰</div>
      <div style="flex:1;min-width:0;">
        <div style="font-weight:600;font-size:13px;margin-bottom:2px;"><?= htmlspecialchars($rw['name']) ?></div>
        <div style="font-size:10px;opacity:.4;overflow:hidden;white-space:nowrap;text-overflow:ellipsis;"><?= htmlspecialchars($rw['url']) ?></div>
      </div>
      <form method="POST" style="margin:0;flex-shrink:0;" onsubmit="return confirm('Delete RSS widget?')">
        <input type="hidden" name="action" value="delete_rss_widget">
        <input type="hidden" name="rw_id" value="<?= htmlspecialchars($rw['id']) ?>">
        <button type="submit" class="btn btn-danger btn-sm">🗑 Delete</button>
      </form>
    </div>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>

  <!-- Existing custom HTML widgets list -->
  <?php if (!empty($html_widgets)): ?>
  <div class="section" style="margin-bottom:16px;">
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
  <div class="section" style="text-align:center;padding:30px;margin-bottom:16px;">
    <div style="font-size:32px;margin-bottom:8px;">🧩</div>
    <div style="opacity:.4;font-size:13px;">No custom widgets yet. Add one above.</div>
  </div>
  <?php endif; ?>

  <!-- ===== CAMERA WIDGET ===== -->
  <div class="section" style="margin-bottom:16px;">
    <h2>📷 Add Camera Widget</h2>
    <p style="font-size:12px;color:rgba(255,255,255,.45);margin-bottom:14px;">
      Embed an IP camera stream (MJPEG, HLS, or an NVR iframe like Scrypted/Frigate/BlueIris) as a draggable widget.
      Optionally add a Scrypted record trigger URL via <code>camera_proxy.php</code>.
    </p>
    <form method="POST">
      <input type="hidden" name="action" value="add_camera_widget">
      <label>Camera Name</label>
      <input type="text" name="cw_name" placeholder="e.g. Front Door, Garage" required>
      <label>Stream URL</label>
      <input type="url" name="cw_url" placeholder="http://192.168.1.x:8080/stream or NVR iframe URL" required>
      <label>Stream Type</label>
      <select name="cw_type" style="background:rgba(255,255,255,.08);border:1px solid rgba(255,255,255,.15);color:#fff;padding:8px 12px;border-radius:6px;width:100%;margin-bottom:14px;">
        <option value="iframe">iframe (Scrypted, Frigate, BlueIris web UI)</option>
        <option value="mjpeg">MJPEG / image feed (direct stream URL)</option>
      </select>
      <label>Record Trigger URL <span style="opacity:.4;font-size:11px;">(optional — Scrypted REST)</span></label>
      <input type="url" name="cw_record_url" placeholder="http://scrypted-host/camera-proxy.php?cam=frontdoor">
      <div style="margin-top:14px;">
        <button type="submit" class="btn btn-primary">➕ Add Camera Widget</button>
      </div>
    </form>
  </div>
  <?php
  $cam_widgets_opt = dashGetWidgets($_odb, $_ou, 'camera');
  if (!empty($cam_widgets_opt)): ?>
  <div class="section" style="margin-bottom:16px;">
    <h2>📷 Your Camera Widgets</h2>
    <?php foreach ($cam_widgets_opt as $cw): ?>
    <div style="display:flex;align-items:center;gap:12px;padding:10px 0;border-bottom:1px solid rgba(255,255,255,.06);">
      <div style="flex:0 0 24px;font-size:20px;">📷</div>
      <div style="flex:1;min-width:0;">
        <div style="font-weight:600;font-size:13px;margin-bottom:2px;"><?= htmlspecialchars($cw['name']) ?></div>
        <div style="font-size:10px;opacity:.4;overflow:hidden;white-space:nowrap;text-overflow:ellipsis;"><?= htmlspecialchars($cw['url']) ?></div>
      </div>
      <form method="POST" style="margin:0;flex-shrink:0;" onsubmit="return confirm('Delete camera widget?')">
        <input type="hidden" name="action" value="delete_camera_widget">
        <input type="hidden" name="cw_id" value="<?= htmlspecialchars($cw['id']) ?>">
        <button type="submit" class="btn btn-danger btn-sm">🗑 Delete</button>
      </form>
    </div>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>

  <!-- ===== GOOGLE CALENDAR WIDGET ===== -->
  <div class="section" style="margin-bottom:16px;">
    <h2>📅 Add Google Calendar Widget</h2>

    <div style="background:rgba(60,120,255,.1);border:1px solid rgba(100,160,255,.25);border-radius:8px;padding:14px;margin-bottom:18px;">
      <strong style="font-size:13px;display:block;margin-bottom:10px;">How to get your Calendar ID — 5 steps:</strong>
      <ol style="margin:0;padding-left:20px;color:rgba(255,255,255,.78);font-size:12px;line-height:2;">
        <li>Open <a href="https://calendar.google.com" target="_blank" style="color:#7ab4ff;">calendar.google.com</a> on desktop and click <strong>⚙️ → Settings</strong></li>
        <li>In the left sidebar under <em>"Settings for my calendars"</em>, click the calendar you want</li>
        <li>Scroll to <strong>"Access permissions for events"</strong> → tick <strong>"Make available to public"</strong> → OK</li>
        <li>Still on that page, scroll down to <strong>"Integrate calendar"</strong></li>
        <li>Copy the <strong>Calendar ID</strong> (looks like <code style="font-size:10px;opacity:.7;">yourname@gmail.com</code> or <code style="font-size:10px;opacity:.7;">abc123@group.calendar.google.com</code>) and paste it below</li>
      </ol>
      <div style="margin-top:12px;padding-top:12px;border-top:1px solid rgba(255,255,255,.1);font-size:12px;color:rgba(255,255,255,.6);">
        <strong style="color:rgba(255,255,255,.85);">Shortcut:</strong> Already have a Google Calendar share or embed link? Paste it here and we'll pull the ID out automatically:
      </div>
      <div style="display:flex;gap:8px;margin-top:8px;align-items:center;">
        <input type="text" id="cal-link-extractor" placeholder="Paste any Google Calendar share or embed link…"
               style="flex:1;background:rgba(255,255,255,.07);border:1px solid rgba(255,255,255,.15);border-radius:6px;padding:8px 10px;color:#fff;font-size:11px;outline:none;font-family:monospace;">
        <button type="button" onclick="extractCalId()" style="padding:8px 16px;background:rgba(80,160,255,.3);border:1px solid rgba(100,180,255,.4);color:#adf;border-radius:6px;cursor:pointer;font-size:12px;white-space:nowrap;flex-shrink:0;">Extract ID →</button>
      </div>
      <div id="cal-extract-result" style="margin-top:7px;font-size:11px;display:none;"></div>
    </div>

    <form method="POST">
      <input type="hidden" name="action" value="add_calendar_widget">
      <label>Widget Name</label>
      <input type="text" name="cal_name" placeholder="e.g. Work &amp; Personal" required>
      <label>Calendar ID(s) <span style="opacity:.4;font-size:11px;">(comma-separated — add one per calendar you made public)</span></label>
      <input type="text" id="cal-ids-field" name="cal_ids" placeholder="yourname@gmail.com, abc123@group.calendar.google.com" required>
      <label>Your Timezone</label>
      <input type="text" name="cal_tz" value="America/New_York" placeholder="America/New_York">
      <p style="font-size:11px;color:rgba(255,255,255,.35);margin-top:4px;">
        Common zones: America/Chicago · America/Denver · America/Los_Angeles · America/Toronto · Europe/London · Europe/Paris · Asia/Tokyo
      </p>
      <div style="margin-top:14px;">
        <button type="submit" class="btn btn-primary">➕ Add Calendar Widget</button>
      </div>
    </form>
  </div>
  <script>
  function extractCalId() {
    const raw = document.getElementById('cal-link-extractor').value.trim();
    const result = document.getElementById('cal-extract-result');
    const field  = document.getElementById('cal-ids-field');
    result.style.display = '';
    if (!raw) { result.innerHTML = '<span style="color:#ff9966;">Paste a link first.</span>'; return; }
    let ids = [];
    try {
      const url = new URL(raw.startsWith('http') ? raw : 'https://' + raw);
      // Embed format: ?src=CAL_ID (may repeat for multiple)
      const allSrc = [...url.searchParams.entries()].filter(([k]) => k === 'src').map(([,v]) => v);
      if (allSrc.length) ids = allSrc;
      // Share format sometimes uses cid= (base64-ish)
      if (!ids.length) {
        const cid = url.searchParams.get('cid');
        if (cid) {
          try { ids = [atob(cid.replace(/-/g,'+').replace(/_/g,'/')).replace(/\0.*$/, '')]; } catch(e) {}
        }
      }
    } catch(e) {}
    // Fallback: regex for calendar-email patterns in raw text
    if (!ids.length) {
      const m = raw.match(/[a-zA-Z0-9._%+\-]+@(?:gmail\.com|[a-zA-Z0-9\-]+\.calendar\.google\.com)/g);
      if (m) ids = [...new Set(m)];
    }
    if (!ids.length) {
      result.innerHTML = '<span style="color:#ff9966;">⚠ Could not detect an ID in that link. Copy the Calendar ID directly from Settings → Integrate calendar instead.</span>';
      return;
    }
    const joined = ids.join(', ');
    const existing = field.value.trim();
    field.value = existing ? existing + ', ' + joined : joined;
    result.innerHTML = '<span style="color:#88ffaa;">✔ Extracted and added: <strong>' + ids.map(id => id.replace(/</g,'&lt;')).join(', ') + '</strong></span>';
    document.getElementById('cal-link-extractor').value = '';
  }
  </script>
  <?php
  $cal_widgets_opt = dashGetWidgets($_odb, $_ou, 'calendar');
  if (!empty($cal_widgets_opt)): ?>
  <div class="section" style="margin-bottom:16px;">
    <h2>📅 Your Calendar Widgets</h2>
    <?php foreach ($cal_widgets_opt as $calw): ?>
    <div style="display:flex;align-items:center;gap:12px;padding:10px 0;border-bottom:1px solid rgba(255,255,255,.06);">
      <div style="flex:0 0 24px;font-size:20px;">📅</div>
      <div style="flex:1;min-width:0;">
        <div style="font-weight:600;font-size:13px;margin-bottom:2px;"><?= htmlspecialchars($calw['name']) ?></div>
        <div style="font-size:10px;opacity:.4;overflow:hidden;white-space:nowrap;text-overflow:ellipsis;"><?= htmlspecialchars($calw['cal_ids']) ?></div>
      </div>
      <form method="POST" style="margin:0;flex-shrink:0;" onsubmit="return confirm('Delete calendar widget?')">
        <input type="hidden" name="action" value="delete_calendar_widget">
        <input type="hidden" name="cal_id" value="<?= htmlspecialchars($calw['id']) ?>">
        <button type="submit" class="btn btn-danger btn-sm">🗑 Delete</button>
      </form>
    </div>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>

  <!-- ===== EXTRA WEATHER CITY WIDGETS ===== -->
  <div class="section" style="margin-bottom:16px;">
    <h2>🌤 Add City Weather Widget</h2>
    <p style="font-size:12px;color:rgba(255,255,255,.45);margin-bottom:14px;">
      Add a draggable weather panel for any city or ZIP code. Each widget auto-refreshes every 30 minutes. Uses <a href="https://wttr.in" target="_blank" style="color:#7ab4ff;">wttr.in</a> — no API key needed.
    </p>
    <form method="POST">
      <input type="hidden" name="action" value="add_weather_city">
      <label>Widget Label</label>
      <input type="text" name="wxc_name" placeholder="e.g. New York, Tokyo, Home" required>
      <label>ZIP Code or City Name</label>
      <input type="text" name="wxc_zip" placeholder="e.g. 10001, London, Tokyo" required>
      <label>Default Unit</label>
      <select name="wxc_unit" style="background:rgba(255,255,255,.08);border:1px solid rgba(255,255,255,.15);color:#fff;padding:8px 12px;border-radius:6px;width:100%;margin-bottom:14px;">
        <option value="F">°F — Fahrenheit</option>
        <option value="C">°C — Celsius</option>
      </select>
      <div style="margin-top:14px;">
        <button type="submit" class="btn btn-primary">➕ Add Weather Widget</button>
      </div>
    </form>
  </div>
  <?php if (!empty($_opt_wxc_w)): ?>
  <div class="section" style="margin-bottom:16px;">
    <h2>🌤 Your City Weather Widgets</h2>
    <?php foreach ($_opt_wxc_w as $wxc): ?>
    <div style="display:flex;align-items:center;gap:12px;padding:10px 0;border-bottom:1px solid rgba(255,255,255,.06);">
      <div style="flex:0 0 24px;font-size:20px;">🌤</div>
      <div style="flex:1;min-width:0;">
        <div style="font-weight:600;font-size:13px;margin-bottom:2px;"><?= htmlspecialchars($wxc['name']) ?></div>
        <div style="font-size:10px;opacity:.4;"><?= htmlspecialchars($wxc['zip']) ?> · °<?= htmlspecialchars($wxc['unit']??'F') ?></div>
      </div>
      <form method="POST" style="margin:0;flex-shrink:0;" onsubmit="return confirm('Delete this weather widget?')">
        <input type="hidden" name="action" value="delete_weather_city">
        <input type="hidden" name="wxc_id" value="<?= htmlspecialchars($wxc['id']) ?>">
        <button type="submit" class="btn btn-danger btn-sm">🗑 Delete</button>
      </form>
    </div>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>

  <!-- ===== TIMEZONE WIDGETS ===== -->
  <div class="section" style="margin-bottom:16px;">
    <h2>🕐 Add World Clock Widget</h2>
    <p style="font-size:12px;color:rgba(255,255,255,.45);margin-bottom:14px;">
      Add a digital clock for any timezone — great for monitoring remote servers or team members in other countries.
    </p>
    <form method="POST">
      <input type="hidden" name="action" value="add_timezone_widget">
      <label>Clock Label</label>
      <input type="text" name="tz_name" placeholder="e.g. Tokyo, London Office, UTC" required>
      <label>IANA Timezone</label>
      <input type="text" name="tz_zone" value="America/New_York" placeholder="America/New_York">
      <p style="font-size:11px;color:rgba(255,255,255,.35);margin-top:4px;line-height:1.6;">
        Common zones: <code>UTC</code> · <code>America/New_York</code> · <code>America/Chicago</code> · <code>America/Denver</code> · <code>America/Los_Angeles</code><br>
        <code>Europe/London</code> · <code>Europe/Paris</code> · <code>Europe/Berlin</code> · <code>Asia/Tokyo</code> · <code>Asia/Shanghai</code> · <code>Australia/Sydney</code>
      </p>
      <div style="margin-top:14px;">
        <button type="submit" class="btn btn-primary">➕ Add Clock Widget</button>
      </div>
    </form>
  </div>
  <?php if (!empty($_opt_tz_w)): ?>
  <div class="section" style="margin-bottom:16px;">
    <h2>🕐 Your World Clock Widgets</h2>
    <?php foreach ($_opt_tz_w as $tzw): ?>
    <div style="display:flex;align-items:center;gap:12px;padding:10px 0;border-bottom:1px solid rgba(255,255,255,.06);">
      <div style="flex:0 0 24px;font-size:20px;">🕐</div>
      <div style="flex:1;min-width:0;">
        <div style="font-weight:600;font-size:13px;margin-bottom:2px;"><?= htmlspecialchars($tzw['name']) ?></div>
        <div style="font-size:10px;opacity:.4;font-family:monospace;"><?= htmlspecialchars($tzw['tz']) ?></div>
      </div>
      <form method="POST" style="margin:0;flex-shrink:0;" onsubmit="return confirm('Delete this clock widget?')">
        <input type="hidden" name="action" value="delete_timezone_widget">
        <input type="hidden" name="tz_id" value="<?= htmlspecialchars($tzw['id']) ?>">
        <button type="submit" class="btn btn-danger btn-sm">🗑 Delete</button>
      </form>
    </div>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>

  <!-- ===== HIDDEN COLUMNS RESTORE ===== -->
  <div class="section" style="margin-bottom:16px;">
    <h2>👁 Hidden Columns</h2>
    <p style="font-size:12px;color:rgba(255,255,255,.45);margin-bottom:14px;">
      Columns hidden with the <strong>×</strong> button (visible in Edit mode) are listed here.
      Click Restore to bring them back on the dashboard.
    </p>
    <div id="hidden-cols-list" style="min-height:40px;">
      <div style="opacity:.4;font-size:13px;text-align:center;padding:16px 0;" id="hidden-cols-empty">No hidden columns. Hide a column in Edit mode using the × button on its header.</div>
    </div>
    <div style="margin-top:12px;">
      <button class="btn" onclick="restoreAllColumns()" style="background:rgba(60,180,60,.2);border:1px solid rgba(80,200,80,.3);color:#aaffaa;font-size:12px;">♻ Restore All Columns</button>
    </div>
  </div>
  <script>
  (function(){
    const serverRaw=<?= json_encode($_hidden_cols_raw ?: '[]') ?>;
    const raw=serverRaw&&serverRaw!=='[]'?serverRaw:(localStorage.getItem('dash_hidden_cols')||'[]');
    let items=[];try{items=JSON.parse(raw);}catch(e){}
    const list=document.getElementById('hidden-cols-list');
    const empty=document.getElementById('hidden-cols-empty');
    if(!items.length){empty.style.display='';return;}
    empty.style.display='none';
    items.forEach(item=>{
      const id=typeof item==='string'?item:item.id;
      const title=typeof item==='object'&&item.title?item.title:id;
      const row=document.createElement('div');
      row.style.cssText='display:flex;align-items:center;gap:12px;padding:8px 0;border-bottom:1px solid rgba(255,255,255,.06);';
      row.innerHTML=`<div style="flex:1;font-size:13px;">${title}</div>
        <button onclick="doRestoreCol('${id.replace(/'/g,"\\'")}',this.closest('div[data-col]'))" style="font-size:12px;padding:4px 12px;background:rgba(60,160,60,.3);border:1px solid rgba(80,200,80,.3);color:#aaffaa;border-radius:5px;cursor:pointer;">♻ Restore</button>`;
      row.dataset.col=id;
      list.appendChild(row);
    });
  })();
  function doRestoreCol(id, rowEl){
    let items=[];try{items=JSON.parse(localStorage.getItem('dash_hidden_cols')||'[]');}catch(e){}
    items=items.filter(x=>(typeof x==='string'?x:x.id)!==id);
    localStorage.setItem('dash_hidden_cols',items.length?JSON.stringify(items):'[]');
    fetch('save_state.php',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({dash_hidden_cols:items.length?JSON.stringify(items):null})}).catch(()=>{});
    if(rowEl)rowEl.remove();
    const list=document.getElementById('hidden-cols-list');
    if(!list.querySelector('[data-col]'))document.getElementById('hidden-cols-empty').style.display='';
  }
  function restoreAllColumns(){
    localStorage.setItem('dash_hidden_cols','[]');
    fetch('save_state.php',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({dash_hidden_cols:null})}).catch(()=>{});
    document.querySelectorAll('[data-col]').forEach(el=>el.remove());
    document.getElementById('hidden-cols-empty').style.display='';
  }
  </script>

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
                <?= ($su['role']??'user')==='readonly' ? '👁 Read-only' : '👤 User' ?>
              </span>
            </td>
            <td style="padding:6px 8px;font-size:11px;color:rgba(255,255,255,.4);">
              <?= $_odb ? '🗄 MySQL' : 'dash_links_'.htmlspecialchars($su['username']).'.json' ?>
            </td>
            <td style="padding:6px 8px;text-align:right;display:flex;gap:6px;justify-content:flex-end;flex-wrap:wrap;">
              <button class="btn btn-secondary btn-sm"
                onclick="showResetPw('<?= htmlspecialchars(addslashes($su['username'])) ?>')">🔑 Reset PW</button>
              <button class="btn btn-secondary btn-sm"
                onclick="exportUserData('<?= htmlspecialchars(addslashes($su['username'])) ?>')">📥 Export</button>
              <form method="POST" style="display:inline;" onsubmit="return confirm('Delete ALL data for <?= htmlspecialchars($su['username']) ?>?\n\nThis removes their links, settings, files, and login.')">
                <input type="hidden" name="action" value="admin_wipe_user">
                <input type="hidden" name="wipe_username" value="<?= htmlspecialchars($su['username']) ?>">
                <button type="submit" class="btn btn-danger btn-sm">🗑 Wipe</button>
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
            <option value="user">👤 User</option>
            <option value="readonly">👁 Read-only</option>
          </select>
        </div>
      </div>
      <div style="margin-top:12px;"><button type="submit" class="btn btn-primary">👥 Add User</button></div>
    </form>

    <div style="margin-top:20px;padding:10px 14px;background:rgba(255,255,255,.04);border-radius:6px;font-size:11px;color:rgba(255,255,255,.4);line-height:1.6;">
      <strong style="color:rgba(255,255,255,.6);">Role explanations:</strong><br>
      <strong>User</strong> — can add and edit their own personal links on the dashboard. Cannot delete columns or links.<br>
      <strong>Read-only</strong> — can see the main dashboard links but cannot add or change anything.
    </div>
  </div>
</div>

<!-- ===== MySQL ADMIN ===== -->
<div id="tab-mysql" class="tab-content">
<?php
$_dbConnected = ($_odb !== null);
$_dbCfgExists = defined('DASH_DB_TYPE') && constant('DASH_DB_TYPE') === 'mysql';
$_dbHost = defined('DASH_DB_HOST') ? constant('DASH_DB_HOST') : '';
$_dbName = defined('DASH_DB_NAME') ? constant('DASH_DB_NAME') : '';
$_dbUser = defined('DASH_DB_USER') ? constant('DASH_DB_USER') : '';
?>
  <!-- Connection status -->
  <div class="section">
    <h2>🗄 MySQL Database</h2>
    <?php if ($_dbConnected): ?>
    <div style="background:rgba(0,200,100,.12);border:1px solid rgba(0,200,100,.3);border-radius:8px;padding:12px 16px;margin-bottom:16px;font-size:13px;">
      ✅ <strong>Connected</strong> — <?= htmlspecialchars($_dbUser) ?>@<?= htmlspecialchars($_dbHost) ?>/<?= htmlspecialchars($_dbName) ?>
    </div>
    <?php elseif ($_dbCfgExists): ?>
    <div style="background:rgba(255,60,60,.12);border:1px solid rgba(255,60,60,.3);border-radius:8px;padding:12px 16px;margin-bottom:16px;font-size:13px;color:#ff8080;">
      ❌ <strong>Connection failed</strong> — credentials saved but database unreachable. Check host, credentials, and that the DB exists.
    </div>
    <?php else: ?>
    <div style="background:rgba(255,200,0,.1);border:1px solid rgba(255,200,0,.3);border-radius:8px;padding:12px 16px;margin-bottom:16px;font-size:13px;color:#ffd060;">
      ⚠️ <strong>Not configured</strong> — all data is stored in JSON files. Configure MySQL below for multi-user isolation and persistent storage.
    </div>
    <?php endif; ?>

    <!-- Configure credentials -->
    <h3 style="font-size:14px;margin-bottom:12px;">🔧 Database Credentials</h3>
    <form method="POST">
      <input type="hidden" name="action" value="save_mysql_config">
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;">
        <div>
          <label>Host</label>
          <input type="text" name="db_host" value="<?= htmlspecialchars($_dbHost ?: 'localhost') ?>" placeholder="localhost">
        </div>
        <div>
          <label>Database Name</label>
          <input type="text" name="db_name" value="<?= htmlspecialchars($_dbName) ?>" placeholder="dashboard" required>
        </div>
        <div>
          <label>Username</label>
          <input type="text" name="db_user" value="<?= htmlspecialchars($_dbUser) ?>" placeholder="dbuser" required>
        </div>
        <div>
          <label>Password</label>
          <input type="password" name="db_pass" placeholder="Leave blank to keep current">
        </div>
      </div>
      <div style="margin-top:14px;display:flex;gap:8px;flex-wrap:wrap;align-items:center;">
        <button type="submit" class="btn btn-primary">💾 Save Credentials</button>
        <?php if ($_dbConnected): ?>
        <a href="migrate.php" class="btn btn-secondary" target="_blank">🔄 Run Migration Tool</a>
        <?php endif; ?>
      </div>
    </form>
  </div>

  <?php if ($_dbConnected): ?>
  <!-- Per-user stats -->
  <div class="section">
    <h2>👥 All Users &amp; Storage</h2>
    <?php
    $allUsers = dashGetUsers($_odb);
    $adminUser = ['username' => $cfg['username'], 'role' => 'admin'];
    array_unshift($allUsers, $adminUser);
    ?>
    <table style="width:100%;border-collapse:collapse;font-size:13px;margin-bottom:10px;">
      <thead>
        <tr style="color:rgba(255,255,255,.5);border-bottom:1px solid rgba(255,255,255,.1);">
          <th style="text-align:left;padding:5px 8px;">User</th>
          <th style="text-align:left;padding:5px 8px;">Role</th>
          <th style="text-align:left;padding:5px 8px;">Links</th>
          <th style="text-align:left;padding:5px 8px;">Widgets</th>
          <th style="text-align:left;padding:5px 8px;">Doc Folders</th>
          <th style="text-align:right;padding:5px 8px;">Actions</th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($allUsers as $au):
        $stats = dashUserStats($_odb, $au['username']);
        $isMainAdmin = ($au['username'] === $cfg['username']);
      ?>
        <tr style="border-bottom:1px solid rgba(255,255,255,.06);">
          <td style="padding:6px 8px;"><strong><?= htmlspecialchars($au['username']) ?></strong></td>
          <td style="padding:6px 8px;">
            <span style="font-size:11px;background:<?= $isMainAdmin?'#3a6':( ($au['role']??'user')==='readonly'?'#555':'#256') ?>;color:#fff;padding:2px 6px;border-radius:10px;">
              <?= $isMainAdmin ? '🛡 Admin' : (($au['role']??'user')==='readonly'?'👁 Read-only':'👤 User') ?>
            </span>
          </td>
          <td style="padding:6px 8px;font-size:12px;color:rgba(255,255,255,.6);"><?= (int)($stats['link_sections']??0) ?> sections</td>
          <td style="padding:6px 8px;font-size:12px;color:rgba(255,255,255,.6);"><?= (int)($stats['widget_rows']??0) ?> rows</td>
          <td style="padding:6px 8px;font-size:12px;color:rgba(255,255,255,.6);"><?= (int)($stats['doc_folders']??0) ?> folders / <?= (int)($stats['doc_files']??0) ?> files</td>
          <td style="padding:6px 8px;text-align:right;">
            <div style="display:flex;gap:5px;justify-content:flex-end;flex-wrap:wrap;">
              <button class="btn btn-secondary btn-sm" onclick="exportUserData('<?= htmlspecialchars(addslashes($au['username'])) ?>')">📥 Export</button>
              <button class="btn btn-secondary btn-sm" onclick="showImportBox('<?= htmlspecialchars(addslashes($au['username'])) ?>')">📤 Import</button>
              <?php if (!$isMainAdmin): ?>
              <form method="POST" style="display:inline;" onsubmit="return confirm('Wipe ALL MySQL data for <?= htmlspecialchars($au['username']) ?>?\n\nLinks, settings, widgets, and doc metadata will be deleted.')">
                <input type="hidden" name="action" value="admin_wipe_user">
                <input type="hidden" name="wipe_username" value="<?= htmlspecialchars($au['username']) ?>">
                <button type="submit" class="btn btn-danger btn-sm">🗑</button>
              </form>
              <?php endif; ?>
            </div>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>

    <!-- Import box (hidden until triggered) -->
    <div id="import-user-box" style="display:none;background:rgba(255,255,255,.06);border-radius:8px;padding:14px;margin-top:12px;">
      <strong id="import-user-label" style="font-size:13px;"></strong>
      <form method="POST" enctype="multipart/form-data" style="margin-top:10px;">
        <input type="hidden" name="action" value="import_user_data">
        <input type="hidden" name="import_username" id="import-username-val">
        <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
          <input type="file" name="import_file" accept=".json" style="flex:1;min-width:180px;">
          <button type="submit" class="btn btn-primary btn-sm">📤 Import</button>
          <button type="button" class="btn btn-secondary btn-sm" onclick="document.getElementById('import-user-box').style.display='none'">Cancel</button>
        </div>
        <p style="font-size:11px;color:rgba(255,255,255,.4);margin-top:6px;">Upload a JSON file previously exported from this dashboard. Existing data will be overwritten.</p>
      </form>
    </div>
  </div>

  <!-- Self export/import -->
  <div class="section">
    <h2>📦 Export / Import Your Data</h2>
    <p style="font-size:12px;color:rgba(255,255,255,.5);margin-bottom:14px;">Download all your data as a single JSON file for backup or migrating to a new server.</p>
    <div style="display:flex;gap:8px;flex-wrap:wrap;">
      <button class="btn btn-primary" onclick="exportUserData('<?= htmlspecialchars(addslashes($cfg['username'])) ?>')">📥 Export My Data</button>
    </div>
  </div>
  <?php endif; ?>
</div>

<!-- ===== MACHINES / DEVICE REGISTRATION ===== -->
<div id="tab-machines" class="tab-content">
  <div class="section">
    <h2>🖥 Device Registration</h2>
    <div style="background:rgba(80,180,255,.08);border:1px solid rgba(80,180,255,.2);border-radius:8px;padding:12px 16px;margin-bottom:16px;font-size:13px;">
      <strong>How device registration works:</strong><br>
      Every browser that visits this dashboard automatically receives a unique ID cookie (<code>dash_machine_uuid</code>) that lasts 10 years. No action needed — your device is registered the moment you first visit.<br><br>
      This lets each device remember its own theme, wallpaper variant, and zoom level independently. Change the theme on your phone and your desktop stays unchanged.
    </div>
    <p style="font-size:12px;opacity:.55;margin-bottom:16px;">All registered devices are listed below. You can give each one a friendly name, or delete profiles you no longer need.</p>
    <?php if (empty($_all_machines)): ?>
      <div style="opacity:.5;font-size:13px;padding:20px 0;">No devices recorded yet. Simply visit the main dashboard and this device will register automatically.</div>
    <?php else: ?>
      <table style="width:100%;border-collapse:collapse;font-size:13px;">
        <thead>
          <tr style="border-bottom:1px solid rgba(255,255,255,.1);opacity:.6;">
            <th style="text-align:left;padding:6px 8px;">Name</th>
            <th style="text-align:left;padding:6px 8px;">UUID</th>
            <th style="text-align:left;padding:6px 8px;">Last Theme</th>
            <th style="text-align:left;padding:6px 8px;">Auto-load Profile</th>
            <th style="text-align:left;padding:6px 8px;">Last Seen</th>
            <th style="text-align:left;padding:6px 8px;">Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($_all_machines as $m): $mUuid = htmlspecialchars($m['machine_uuid']); $mLinked = $_dp_map_opt[$m['machine_uuid']] ?? ''; ?>
          <tr style="border-bottom:1px solid rgba(255,255,255,.05);">
            <td style="padding:8px;">
              <form method="POST" style="display:flex;gap:6px;align-items:center;">
                <input type="hidden" name="action" value="rename_machine">
                <input type="hidden" name="machine_uuid" value="<?= $mUuid ?>">
                <input type="text" name="machine_name" value="<?= htmlspecialchars($m['machine_name']??'') ?>"
                       style="background:rgba(255,255,255,.08);border:1px solid rgba(255,255,255,.15);border-radius:5px;color:#fff;padding:4px 8px;font-size:12px;width:130px;">
                <button type="submit" class="btn btn-primary btn-sm">✏️</button>
              </form>
            </td>
            <td style="padding:8px;font-family:monospace;font-size:11px;opacity:.5;"><?= $mUuid ?></td>
            <td style="padding:8px;opacity:.7;"><?= htmlspecialchars($m['last_theme'] ?: '—') ?><?= $m['last_variant'] ? ' / '.htmlspecialchars($m['last_variant']) : '' ?></td>
            <td style="padding:8px;">
              <form method="POST" style="display:flex;gap:6px;align-items:center;">
                <input type="hidden" name="action" value="link_device_profile">
                <input type="hidden" name="ld_uuid" value="<?= $mUuid ?>">
                <select name="ld_profile" style="background:rgba(255,255,255,.08);border:1px solid rgba(255,255,255,.15);border-radius:5px;color:#fff;padding:3px 6px;font-size:12px;max-width:140px;">
                  <option value="">— None —</option>
                  <?php foreach ($_dp_profiles as $_pp): ?>
                  <option value="<?= htmlspecialchars($_pp) ?>"<?= $mLinked === $_pp ? ' selected' : '' ?>><?= htmlspecialchars($_pp) ?></option>
                  <?php endforeach; ?>
                </select>
                <button type="submit" class="btn btn-primary btn-sm" title="Save device-profile link">💾</button>
              </form>
            </td>
            <td style="padding:8px;opacity:.6;white-space:nowrap;"><?= htmlspecialchars($m['last_seen'] ?? '—') ?></td>
            <td style="padding:8px;">
              <form method="POST" onsubmit="return confirm('Delete this machine profile?');" style="display:inline;">
                <input type="hidden" name="action" value="delete_machine">
                <input type="hidden" name="machine_uuid" value="<?= $mUuid ?>">
                <button type="submit" class="btn btn-sm" style="background:rgba(255,60,60,.2);border:1px solid rgba(255,60,60,.3);color:#ff8080;">🗑</button>
              </form>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>
    <div style="margin-top:20px;padding:12px;background:rgba(255,255,255,.03);border:1px solid rgba(255,255,255,.08);border-radius:8px;font-size:12px;opacity:.55;">
      <strong>How it works:</strong> A 10-year HttpOnly cookie (<code>dash_machine_uuid</code>) is set on every device that visits. When you change the theme or variant on a device, it is saved to this device's profile and restored automatically next time — even if another device uses a different theme simultaneously.
    </div>
  </div>
</div>

<!-- ===== EXPORT ===== -->
<div id="tab-export" class="tab-content">

  <!-- Theme ZIP Export -->
  <div class="section" style="margin-bottom:16px;">
    <h2>📦 Export Theme as ZIP</h2>
    <p style="font-size:12px;opacity:.55;margin-bottom:12px;">Download a single ZIP containing your custom backgrounds (including uploaded images and videos) for any theme. Import it on another device — duplicate files are automatically skipped.</p>
    <div style="display:flex;gap:10px;align-items:flex-end;flex-wrap:wrap;">
      <div>
        <label style="font-size:12px;opacity:.7;display:block;margin-bottom:4px;">Theme to export</label>
        <select id="zip-export-theme" style="background:#1a1a2e;color:#fff;border:1px solid rgba(255,255,255,.2);border-radius:6px;padding:6px 10px;font-size:13px;">
          <?php foreach ($themes as $_te => $_tlabel): $cnt = count((array)($bgs[$_te] ?? [])); ?>
          <option value="<?= htmlspecialchars($_te) ?>"><?= htmlspecialchars($_tlabel) ?><?= $cnt ? ' ('.$cnt.' bg'.($cnt!==1?'s':'').')' : '' ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <button class="btn btn-primary" onclick="startThemeZipExport()">📦 Download ZIP</button>
    </div>
    <p style="font-size:11px;color:rgba(255,255,255,.35);margin-top:8px;">Themes with no custom backgrounds will export a manifest-only ZIP. The ZIP always preserves any uploaded images or videos for that theme.</p>
  </div>

  <!-- Theme ZIP Import -->
  <div class="section" style="margin-bottom:16px;">
    <h2>📥 Import Theme ZIP</h2>
    <p style="font-size:12px;opacity:.55;margin-bottom:10px;">Upload a <code>.zip</code> exported from this dashboard. Duplicate images and videos are skipped; everything else is merged into the matching theme.</p>
    <form method="POST" enctype="multipart/form-data" style="display:flex;gap:10px;align-items:flex-end;flex-wrap:wrap;">
      <input type="hidden" name="action" value="import_theme_zip">
      <input type="file" name="theme_zip" accept=".zip" required style="font-size:12px;color:#ccc;">
      <button type="submit" class="btn btn-primary">📥 Import Theme ZIP</button>
    </form>
  </div>

  <!-- My theme / display settings -->
  <div class="section" style="margin-bottom:16px;">
    <h2>📤 My Display Settings</h2>
    <p style="font-size:12px;opacity:.55;margin-bottom:12px;">Export your current theme, wallpaper, zoom, and layout preferences as a JSON snippet. Import it on another device or share it with another user.</p>
    <div style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:16px;">
      <button class="btn btn-primary" onclick="exportSettings()">📥 Export My Settings</button>
      <button class="btn btn-secondary" onclick="document.getElementById('import-area').style.display='block'">📤 Import Settings</button>
    </div>
    <div id="import-area" style="display:none;">
      <label>Paste JSON:</label>
      <textarea class="export-area" id="import-json" placeholder='{"theme":"win98","wall":"teal",...}'></textarea>
      <button class="btn btn-primary" onclick="importSettings()" style="margin-top:8px;">Import</button>
    </div>
  </div>

  <!-- Share a column or widget -->
  <?php
  $_exp_hw  = dashGetWidgets($_odb, $_ou, 'html');
  $_exp_rw  = dashGetWidgets($_odb, $_ou, 'rss');
  $_exp_cam = dashGetWidgets($_odb, $_ou, 'camera');
  $_exp_cal = dashGetWidgets($_odb, $_ou, 'calendar');
  ?>
  <div class="section" style="margin-bottom:16px;">
    <h2>🔗 Export Column / Widget for Sharing</h2>
    <p style="font-size:12px;opacity:.55;margin-bottom:12px;">Export one of your link columns or widgets as a JSON snippet. Give the JSON to another user — they paste it in the Import box below to add it to their dashboard.</p>
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:12px;">
      <div>
        <label style="font-size:12px;opacity:.7;display:block;margin-bottom:4px;">Type</label>
        <select id="share-export-type" onchange="shareExportTypeChange()" style="width:100%;background:#1a1a2e;color:#fff;border:1px solid rgba(255,255,255,.2);border-radius:6px;padding:6px 10px;font-size:13px;">
          <option value="column">📁 Link Column</option>
          <option value="html_widget">📄 HTML Widget</option>
          <option value="rss_widget">📡 RSS Feed Widget</option>
          <option value="camera_widget">📷 Camera Widget</option>
          <option value="calendar_widget">📅 Calendar Widget</option>
          <option value="settings">🎨 Theme Settings (localStorage only)</option>
          <option value="theme_pack">📦 Theme Pack (colors + all backgrounds)</option>
        </select>
      </div>
      <div id="share-export-col-wrap">
        <label style="font-size:12px;opacity:.7;display:block;margin-bottom:4px;">Column</label>
        <select id="share-export-col" style="width:100%;background:#1a1a2e;color:#fff;border:1px solid rgba(255,255,255,.2);border-radius:6px;padding:6px 10px;font-size:13px;">
          <?php foreach ($links as $col): ?>
          <option value="<?= htmlspecialchars($col['id']??'') ?>"><?= htmlspecialchars(($col['icon']??'📁').' '.($col['title']??'Column')) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div id="share-export-widget-wrap" style="display:none;">
        <label style="font-size:12px;opacity:.7;display:block;margin-bottom:4px;">Widget</label>
        <select id="share-export-widget" style="width:100%;background:#1a1a2e;color:#fff;border:1px solid rgba(255,255,255,.2);border-radius:6px;padding:6px 10px;font-size:13px;"></select>
      </div>
    </div>
    <button class="btn btn-primary" onclick="doShareExport()">📤 Export as JSON</button>
    <div id="share-export-result" style="margin-top:12px;display:none;">
      <label style="font-size:12px;opacity:.7;">Copy this JSON and give it to the other user to paste in the Import box:</label>
      <textarea class="export-area" id="share-export-json" style="height:140px;margin-top:6px;" readonly></textarea>
      <button class="btn btn-secondary btn-sm" style="margin-top:6px;" onclick="navigator.clipboard.writeText(document.getElementById('share-export-json').value).then(()=>this.textContent='✅ Copied!',()=>document.getElementById('share-export-json').select())">📋 Copy to Clipboard</button>
    </div>
  </div>

  <!-- Import shared JSON -->
  <div class="section" style="margin-bottom:16px;">
    <h2>📥 Import Shared Column or Widget</h2>
    <p style="font-size:12px;opacity:.55;margin-bottom:12px;">Paste a JSON snippet exported from this dashboard to add the column or widget directly to your layout.</p>
    <form method="POST">
      <input type="hidden" name="action" value="import_shared_json">
      <textarea name="shared_json" rows="6"
        placeholder='Paste the JSON here — supports: column_import, widget_import, settings_import, theme_pack_import'
        style="width:100%;box-sizing:border-box;background:rgba(0,0,0,.3);border:1px solid rgba(255,255,255,.15);border-radius:6px;color:#fff;font-family:monospace;font-size:11px;padding:10px;resize:vertical;margin-bottom:10px;"></textarea>
      <button type="submit" class="btn btn-primary">📥 Import</button>
    </form>
  </div>

  <?php if ($_dash_is_admin && $_odb): ?>
  <!-- Admin: export all users -->
  <div class="section" style="margin-bottom:16px;">
    <h2>👥 Export All Users' Settings (Admin)</h2>
    <p style="font-size:12px;opacity:.55;margin-bottom:12px;">Download a full JSON export for any user — their theme, links, widgets, and preferences.</p>
    <div style="display:flex;gap:8px;align-items:flex-end;flex-wrap:wrap;">
      <div>
        <label style="font-size:12px;opacity:.7;display:block;margin-bottom:4px;">User</label>
        <select id="admin-export-user" style="background:#1a1a2e;color:#fff;border:1px solid rgba(255,255,255,.2);border-radius:6px;padding:6px 10px;font-size:13px;">
          <?php
          $__allU = dashGetUsers($_odb);
          array_unshift($__allU, ['username'=>$cfg['username'],'role'=>'admin']);
          foreach ($__allU as $__u): ?>
          <option value="<?= htmlspecialchars($__u['username']) ?>"><?= htmlspecialchars($__u['username']) ?> (<?= htmlspecialchars($__u['role']??'user') ?>)</option>
          <?php endforeach; ?>
        </select>
      </div>
      <button class="btn btn-primary" onclick="exportUserData(document.getElementById('admin-export-user').value)">📥 Export User Data</button>
    </div>
  </div>
  <?php endif; ?>

</div>
<script>
// Widget data for export
const _shareWidgets = {
  html_widget:     <?= json_encode($_exp_hw) ?>,
  rss_widget:      <?= json_encode($_exp_rw) ?>,
  camera_widget:   <?= json_encode($_exp_cam) ?>,
  calendar_widget: <?= json_encode($_exp_cal) ?>,
};

function shareExportTypeChange(){
  const t = document.getElementById('share-export-type').value;
  const isCol = t === 'column';
  const isWidget = t.endsWith('_widget');
  document.getElementById('share-export-col-wrap').style.display    = isCol    ? '' : 'none';
  document.getElementById('share-export-widget-wrap').style.display = isWidget ? '' : 'none';
  document.getElementById('share-export-result').style.display = 'none';
  if (isWidget) {
    const wSel = document.getElementById('share-export-widget');
    wSel.innerHTML = '';
    const list = _shareWidgets[t] || [];
    if (!list.length) {
      wSel.innerHTML = '<option value="">— No widgets of this type —</option>';
    } else {
      list.forEach(w => {
        const o = document.createElement('option');
        o.value = w.id || '';
        o.textContent = w.name || w.id || 'Widget';
        wSel.appendChild(o);
      });
    }
  }
}

function doShareExport(){
  const type = document.getElementById('share-export-type').value;
  let data = {};
  if (type === 'column') {
    const colId = document.getElementById('share-export-col').value;
    const allLinks = <?= json_encode($links) ?>;
    const col = allLinks.find(c => (c.id || '') == colId);
    if (!col) { alert('Column not found. Try reloading the page.'); return; }
    data = {type: 'column_import', version: '1.0', column: col};
  } else if (type.endsWith('_widget')) {
    const wId = document.getElementById('share-export-widget').value;
    if (!wId) { alert('No widget selected or no widgets of this type exist.'); return; }
    const list = _shareWidgets[type] || [];
    const w = list.find(x => (x.id || '') == wId);
    if (!w) { alert('Widget not found.'); return; }
    data = {type: type + '_import', version: '1.0', widget: w};
  } else if (type === 'theme_pack') {
    // Theme pack = server-side custom theme colors + ALL custom backgrounds.
    // URL-based backgrounds (image_url / video_url / iframe_url) work on any server.
    // Uploaded files (image_upload / video_upload) are local — the import will
    // include them for same-server use; cross-server they will appear broken.
    const ct  = <?= json_encode($custom_theme) ?>;
    const cbgs = <?= json_encode($bgs) ?>;
    data = {
      type:         'theme_pack_import',
      version:      '1.0',
      custom_theme: ct,
      custom_bgs:   cbgs,
      exported_by:  <?= json_encode($_ou) ?>,
      exported_at:  new Date().toISOString(),
      note: 'Import this in the "Import Shared Column or Widget" box on any RetroOS dashboard.'
    };
  } else {
    const st = <?= json_encode($dash_state) ?>;
    data = {type: 'settings_import', version: '1.0', settings: st};
  }
  const json = JSON.stringify(data, null, 2);
  const el = document.getElementById('share-export-json');
  el.value = json;
  document.getElementById('share-export-result').style.display = 'block';
  el.select();
}
</script>

<!-- ===== UPDATE ===== -->
<div id="tab-update" class="tab-content">
  <div class="section" style="margin-bottom:16px;">
    <h2>🔄 Dashboard Updates</h2>

    <!-- Update URL -->
    <div style="margin-bottom:20px;">
      <h3 style="font-size:14px;margin-bottom:8px;opacity:.8;">Update Source URL</h3>
      <form method="POST" style="display:flex;gap:8px;align-items:flex-end;flex-wrap:wrap;">
        <input type="hidden" name="action" value="save_update_url">
        <div style="flex:1;min-width:260px;">
          <label style="font-size:12px;opacity:.6;display:block;margin-bottom:4px;">version.json URL</label>
          <input type="url" name="update_url" value="<?= htmlspecialchars($_update_url) ?>"
                 placeholder="https://dash.danielholmstock.com/updateusers/version.json"
                 style="width:100%;">
        </div>
        <button type="submit" class="btn btn-primary btn-sm">💾 Save URL</button>
      </form>
      <div style="font-size:11px;opacity:.45;margin-top:6px;">Leave blank to use the default: <code>https://dash.danielholmstock.com/updateusers/version.json</code></div>
    </div>

    <!-- Check for Updates -->
    <div style="margin-bottom:20px;padding:16px;background:rgba(255,255,255,.03);border:1px solid rgba(255,255,255,.08);border-radius:10px;">
      <div style="display:flex;align-items:center;gap:12px;flex-wrap:wrap;">
        <button class="btn btn-primary" onclick="checkForUpdate()">🔍 Check for Updates</button>
        <span style="font-size:12px;opacity:.5;">Running v<strong>1.5</strong></span>
      </div>
      <div id="update-result" style="margin-top:12px;display:none;">
        <div id="update-status" style="font-size:14px;font-weight:600;margin-bottom:8px;"></div>
        <div id="update-changelog" style="font-size:13px;opacity:.75;white-space:pre-wrap;line-height:1.6;"></div>
        <div id="update-download-row" style="margin-top:12px;display:none;">
          <button class="btn btn-primary" onclick="applyUpdateFromUrl()">⬇️ Download &amp; Apply Update</button>
          <span id="update-zip-size" style="font-size:12px;opacity:.5;margin-left:10px;"></span>
        </div>
      </div>
      <div id="update-spinner" style="display:none;font-size:12px;opacity:.6;margin-top:10px;">⏳ Checking…</div>
    </div>

    <!-- Apply Update: Upload ZIP -->
    <div style="margin-bottom:20px;padding:16px;background:rgba(255,255,255,.03);border:1px solid rgba(255,255,255,.08);border-radius:10px;">
      <h3 style="font-size:14px;margin-bottom:12px;opacity:.8;">📦 Apply Update from ZIP</h3>
      <p style="font-size:12px;opacity:.55;margin-bottom:10px;">Upload a dashboard ZIP file directly. SHA-256 will be verified if you provide a hash. Protected files (dash_config.php, uploads/) are never overwritten.</p>
      <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:flex-end;">
        <div>
          <label style="font-size:12px;opacity:.6;display:block;margin-bottom:4px;">ZIP file</label>
          <input type="file" id="update-zip-file" accept=".zip" style="font-size:12px;">
        </div>
        <div style="flex:1;min-width:200px;">
          <label style="font-size:12px;opacity:.6;display:block;margin-bottom:4px;">SHA-256 (optional)</label>
          <input type="text" id="update-sha256" placeholder="leave blank to skip hash check" style="width:100%;font-size:12px;font-family:monospace;">
        </div>
        <button class="btn btn-primary" onclick="applyUpdateFromUpload()">🚀 Apply ZIP</button>
      </div>
      <div id="apply-result" style="margin-top:12px;font-size:13px;display:none;"></div>
      <div id="apply-spinner" style="display:none;font-size:12px;opacity:.6;margin-top:10px;">⏳ Applying update — do not navigate away…</div>
    </div>
  </div>

  <!-- Export / Import Full Backup -->
  <div class="section">
    <h2>💾 Backup &amp; Restore</h2>
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;flex-wrap:wrap;" class="grid-row">

      <div style="background:rgba(255,255,255,.03);border:1px solid rgba(255,255,255,.08);border-radius:10px;padding:16px;">
        <h3 style="font-size:14px;margin-bottom:8px;">📤 Export Full Backup</h3>
        <p style="font-size:12px;opacity:.55;margin-bottom:12px;">Downloads a ZIP containing all settings, widgets, links, profiles, and uploaded files.</p>
        <a href="export_data.php" class="btn btn-primary" style="text-decoration:none;display:inline-block;">⬇️ Download Backup ZIP</a>
      </div>

      <div style="background:rgba(255,255,255,.03);border:1px solid rgba(255,255,255,.08);border-radius:10px;padding:16px;">
        <h3 style="font-size:14px;margin-bottom:8px;">📥 Import Backup</h3>
        <p style="font-size:12px;opacity:.55;margin-bottom:12px;">Restore from a backup ZIP. Existing data will be overwritten.</p>
        <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
          <input type="file" id="import-zip-file" accept=".zip" style="font-size:12px;flex:1;">
          <button class="btn btn-primary" onclick="importBackup()">📥 Restore</button>
        </div>
        <div id="import-result" style="margin-top:10px;font-size:13px;display:none;"></div>
        <div id="import-spinner" style="display:none;font-size:12px;opacity:.6;margin-top:8px;">⏳ Restoring…</div>
      </div>
    </div>
  </div>
</div>

<?php
$_stypes = ['links_col'=>'📁 Link Column','rss'=>'📰 RSS Feed','camera'=>'📷 Camera',
            'calendar'=>'📅 Calendar','countdown'=>'⏳ Countdown','sticky'=>'📌 Sticky Note'];
$_share_resources = [
    'links_col' => array_map(fn($c) => ['id'=>($c['id']??''),'name'=>($c['icon']??'📁').' '.($c['title']??'Column')], $links),
    'rss'       => array_map(fn($w) => ['id'=>($w['id']??''),'name'=>'📰 '.($w['name']??'RSS Feed')], $_opt_rss_w),
    'camera'    => array_map(fn($w) => ['id'=>($w['id']??''),'name'=>'📷 '.($w['name']??'Camera')], $_opt_cam_w2),
    'calendar'  => array_map(fn($w) => ['id'=>($w['id']??''),'name'=>'📅 '.($w['name']??'Calendar')], $_opt_cal_w2),
    'countdown' => array_map(fn($w) => ['id'=>($w['id']??''),'name'=>'⏳ '.($w['name']??'Countdown')], $_opt_cd_w),
    'sticky'    => array_map(fn($sn) => ['id'=>($sn['id']??''),'name'=>'📌 '.mb_substr($sn['text']??'Note',0,30)], $_opt_stickies),
];
?>
<!-- ===== CHANGELOG ===== -->
<div id="tab-changelog" class="tab-content">
  <div class="section">
    <h2>📋 Changelog</h2>
    <div style="max-width:700px;">

      <div style="border-left:3px solid rgba(80,255,160,.6);padding:10px 16px;margin-bottom:20px;background:rgba(255,255,255,.03);border-radius:0 6px 6px 0;">
        <div style="font-size:13px;font-weight:700;color:#fff;margin-bottom:6px;">v1.4.3 — current</div>
        <ul style="font-size:12px;color:rgba(255,255,255,.75);margin:0;padding-left:18px;line-height:1.9;">
          <li>🐛 <strong>Fixed welcome wizard:</strong> picked starter columns now actually appear (a <code>const</code> vs <code>window</code> bug was silently dropping every selection and inserting a placeholder "My Dashboard" column instead).</li>
          <li>📦 New <strong>Add Preset Column</strong> section in <em>Options → Links</em>: drop any starter category (Search, AI, Dev, Travel, Finance, Self-Hosted…) onto your dashboard with one click, even after you've used the wizard.</li>
          <li>🆕 Added <strong>5 new starter categories</strong> — Travel, Finance, Gaming, Self-Hosted, Email — and expanded Shopping, News, AI, Media, and Productivity with more popular sites.</li>
          <li>🧠 Refactored presets into a single shared file (<code>presets.php</code>) — the wizard, the Quick-Pick panel inside Add-Link, and the new Options buttons all stay in sync automatically.</li>
        </ul>
      </div>

      <div style="border-left:3px solid rgba(80,160,255,.5);padding:10px 16px;margin-bottom:20px;background:rgba(255,255,255,.03);border-radius:0 6px 6px 0;">
        <div style="font-size:13px;font-weight:700;color:rgba(255,255,255,.85);margin-bottom:6px;">v1.4.2</div>
        <ul style="font-size:12px;color:rgba(255,255,255,.7);margin:0;padding-left:18px;line-height:1.9;">
          <li>👥 <strong>Per-user data isolation in JSON / flat-file mode:</strong> each sub-user now gets their own <code>dash_links_alice.json</code>, settings, page-folders, etc. Admin keeps the un-suffixed legacy files.</li>
          <li>🪄 First-run welcome wizard now appears for new sub-users in JSON mode (was previously only firing in MySQL mode).</li>
        </ul>
      </div>

      <div style="border-left:3px solid rgba(80,160,255,.5);padding:10px 16px;margin-bottom:20px;background:rgba(255,255,255,.03);border-radius:0 6px 6px 0;">
        <div style="font-size:13px;font-weight:700;color:rgba(255,255,255,.9);margin-bottom:6px;">v1.3 — 2026</div>
        <ul style="font-size:12px;color:rgba(255,255,255,.7);margin:0;padding-left:18px;line-height:1.9;">
          <li>🎨 Renamed <strong>Girly</strong> theme to <strong>Cute</strong></li>
          <li>🔊 Expanded OS sound system — all 35+ themes now have unique synthesized startup chimes</li>
          <li>🔊 Added <strong>Sound toggle button</strong> (🔊) in the header toolbar</li>
          <li>🎨 Added <strong>13 new HQ canvas animations</strong>:
            <ul style="margin-top:4px;">
              <li>🌧️ Spring Rain — rain drops, ripples, rainbow arc</li>
              <li>🌿 Spring Meadow — hills, wildflowers, butterflies, sun rays</li>
              <li>🌌 Aurora Borealis — undulating aurora curtains, snow ground, tree silhouettes</li>
              <li>❄️ Blizzard — wind-driven snow storm, drifting ground</li>
              <li>🎄 Christmas Night — decorated tree, colored lights, snow, falling ornaments</li>
              <li>🌙 Tropical Fireflies — palm silhouettes, glowing fireflies, moonlit ocean</li>
              <li>🌅 Ocean Sunset — dramatic sky, rolling waves, seabirds</li>
              <li>🍁 Maple Forest — parallax depth layers, 5-lobe maple leaves, ground scatter</li>
              <li>🌕 Harvest Moon — full moon, pumpkin field, bats, mist wisps</li>
              <li>🎤 Concert Stage — Miku spotlights, crowd glow sticks, note particles</li>
              <li>🌐 Cyber Rain — teal/pink matrix character rain</li>
              <li>💕 Hearts & Sparkles — floating hearts, sparkle stars, bokeh</li>
            </ul>
          </li>
          <li>📋 Added this <strong>Changelog</strong> tab</li>
        </ul>
      </div>

      <div style="border-left:3px solid rgba(100,200,120,.4);padding:10px 16px;margin-bottom:20px;background:rgba(255,255,255,.02);border-radius:0 6px 6px 0;">
        <div style="font-size:13px;font-weight:700;color:rgba(255,255,255,.8);margin-bottom:6px;">v1.2</div>
        <ul style="font-size:12px;color:rgba(255,255,255,.65);margin:0;padding-left:18px;line-height:1.9;">
          <li>📸 Camera widget — MJPEG/WebRTC stream embed</li>
          <li>📅 Google Calendar embed widget</li>
          <li>↔️ Widget resize handles with font scaling</li>
          <li>💾 Profile auto-save with continuous write-back</li>
          <li>🔗 Links independent of profiles</li>
          <li>🔢 Zoom level saved per profile</li>
          <li>✖️ Hide/show columns with × button</li>
          <li>🖥️ Embed compatibility — CSP relaxed, raw HTML, script re-execution</li>
        </ul>
      </div>

      <div style="border-left:3px solid rgba(200,150,80,.4);padding:10px 16px;margin-bottom:20px;background:rgba(255,255,255,.02);border-radius:0 6px 6px 0;">
        <div style="font-size:13px;font-weight:700;color:rgba(255,255,255,.75);margin-bottom:6px;">v1.1</div>
        <ul style="font-size:12px;color:rgba(255,255,255,.6);margin:0;padding-left:18px;line-height:1.9;">
          <li>🎨 35+ themes including seasonal, Miku, professional, retro</li>
          <li>🖼️ Animated canvas backgrounds (petals, snow, leaves, fireworks, etc.)</li>
          <li>🎵 Web Audio API theme sounds (no files required)</li>
          <li>📺 CRT scanline overlay toggle</li>
          <li>📌 Sticky notes widget</li>
          <li>🗂️ RSS feed widget</li>
          <li>💻 System stats widgets (CPU, RAM, disk, etc.)</li>
          <li>🔒 Password protection &amp; user management</li>
          <li>🔗 Multi-user profile sharing</li>
          <li>📤 Profile import/export as ZIP</li>
        </ul>
      </div>

      <div style="border-left:3px solid rgba(180,180,180,.3);padding:10px 16px;background:rgba(255,255,255,.02);border-radius:0 6px 6px 0;">
        <div style="font-size:13px;font-weight:700;color:rgba(255,255,255,.65);margin-bottom:6px;">v1.0 — Initial Release</div>
        <ul style="font-size:12px;color:rgba(255,255,255,.5);margin:0;padding-left:18px;line-height:1.9;">
          <li>PHP 8.3 + MySQL self-hosted dashboard</li>
          <li>Bookmark link grid with drag-and-drop reordering</li>
          <li>Dark/light base with theme switching</li>
          <li>Basic profile system</li>
          <li>Service machine monitor</li>
        </ul>
      </div>

    </div>
  </div>
</div>

<!-- ===== SHARING ===== -->
<div id="tab-sharing" class="tab-content">

<?php
// Build the current user's shareable columns list
$_fs_my_cols = array_map(fn($c) => ['id' => $c['id'] ?? '', 'name' => ($c['icon']??'📁').' '.($c['title']??'Column')], $links);
// Build recipient list (all users except current)
$_fs_all_users = array_filter(
    array_merge([['username' => $cfg['username']]], dashGetUsers($_odb)),
    fn($u) => ($u['username'] ?? '') !== $_ou
);
$_fs_has_others = !empty($_fs_all_users);
?>

  <!-- ── Share a Column ─────────────────────────────────────────────────── -->
  <div class="section" style="margin-bottom:16px;">
    <h2>📤 Share a Column</h2>
    <p style="font-size:12px;color:rgba(255,255,255,.45);margin-bottom:16px;">
      Choose one of your link columns and share it with another user. They get an independent copy — changes you make afterwards won't affect theirs.
    </p>
    <?php if (!$_fs_has_others): ?>
      <p style="font-size:12px;color:rgba(255,255,255,.35);">No other users yet — add sub-users in the 👥 Users tab first.</p>
    <?php elseif (empty($_fs_my_cols)): ?>
      <p style="font-size:12px;color:rgba(255,255,255,.35);">You don't have any link columns to share yet.</p>
    <?php else: ?>
    <form method="POST" style="display:grid;grid-template-columns:1fr 1fr auto;gap:12px;align-items:flex-end;flex-wrap:wrap;" class="grid-row">
      <input type="hidden" name="action" value="file_share_item">
      <input type="hidden" name="fs_type" value="links_col">
      <div>
        <label style="font-size:12px;opacity:.6;display:block;margin-bottom:5px;">Column to share</label>
        <select name="fs_col_id" style="width:100%;">
          <?php foreach ($_fs_my_cols as $_fc): ?>
          <option value="<?= htmlspecialchars($_fc['id']) ?>"><?= htmlspecialchars($_fc['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div>
        <label style="font-size:12px;opacity:.6;display:block;margin-bottom:5px;">Share with</label>
        <select name="fs_to" style="width:100%;">
          <option value="__all__">— All Users —</option>
          <?php foreach ($_fs_all_users as $_fu): ?>
          <option value="<?= htmlspecialchars($_fu['username']) ?>"><?= htmlspecialchars($_fu['username']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div>
        <button type="submit" class="btn btn-primary">🔗 Share</button>
      </div>
    </form>
    <?php endif; ?>
  </div>

  <!-- ── Shared with Me ─────────────────────────────────────────────────── -->
  <div class="section" style="margin-bottom:16px;">
    <h2>📥 Shared with Me</h2>
    <p style="font-size:12px;color:rgba(255,255,255,.45);margin-bottom:14px;">
      Columns other users have shared with you. Accept to add them to your dashboard; dismiss to ignore.
    </p>
    <?php if (empty($_fs_inbox)): ?>
      <p style="font-size:12px;color:rgba(255,255,255,.3);">Nothing shared with you yet.</p>
    <?php else: ?>
    <table style="width:100%;border-collapse:collapse;font-size:13px;">
      <thead>
        <tr style="color:rgba(255,255,255,.45);border-bottom:1px solid rgba(255,255,255,.1);">
          <th style="text-align:left;padding:5px 8px;">From</th>
          <th style="text-align:left;padding:5px 8px;">Column</th>
          <th style="text-align:left;padding:5px 8px;">Sent</th>
          <th style="text-align:right;padding:5px 8px;">Action</th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($_fs_inbox as $_fsh): ?>
        <tr style="border-bottom:1px solid rgba(255,255,255,.06);">
          <td style="padding:6px 8px;"><strong><?= htmlspecialchars($_fsh['from'] ?? '?') ?></strong></td>
          <td style="padding:6px 8px;"><?= htmlspecialchars($_fsh['name'] ?? '—') ?></td>
          <td style="padding:6px 8px;opacity:.55;font-size:11px;"><?= $_fsh['ts'] ? date('M j, Y', (int)$_fsh['ts']) : '—' ?></td>
          <td style="padding:6px 8px;text-align:right;display:flex;gap:6px;justify-content:flex-end;">
            <form method="POST" style="display:inline;">
              <input type="hidden" name="action" value="file_accept_share">
              <input type="hidden" name="fs_id" value="<?= htmlspecialchars($_fsh['id'] ?? '') ?>">
              <button type="submit" class="btn btn-primary btn-sm">✓ Accept</button>
            </form>
            <form method="POST" style="display:inline;" onsubmit="return confirm('Dismiss this share?')">
              <input type="hidden" name="action" value="file_dismiss_share">
              <input type="hidden" name="fs_id" value="<?= htmlspecialchars($_fsh['id'] ?? '') ?>">
              <button type="submit" class="btn btn-secondary btn-sm">✗ Dismiss</button>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <?php endif; ?>
  </div>

  <?php if ($_odb): ?>

  <!-- MySQL sharing (only shown when DB is connected) -->
  <?php if (!empty($_all_users_share)): ?>
  <!-- ── Push Content to Users (admin: all users; user: share with others) ── -->
  <div class="section" style="margin-bottom:16px;">
    <h2>📋 <?= $_dash_is_admin ? 'Push Content to Users' : 'Share My Content' ?></h2>
    <p style="font-size:12px;color:rgba(255,255,255,.45);margin-bottom:16px;">
      <?= $_dash_is_admin
        ? 'Copy any of your columns or widgets directly to another user (or all users). They get an independent copy instantly — no invite needed.'
        : 'Share any of your columns or widgets with another user. They will receive an independent copy on their dashboard.' ?>
    </p>
    <div style="display:grid;grid-template-columns:1fr 1fr 1fr auto;gap:12px;align-items:flex-end;" class="grid-row">
      <div>
        <label style="font-size:12px;opacity:.6;display:block;margin-bottom:5px;">Type</label>
        <select id="ap-type" onchange="apPopulateRes()" style="width:100%;">
          <?php foreach ($_stypes as $tk => $tv): ?>
          <option value="<?= $tk ?>"><?= $tv ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div>
        <label style="font-size:12px;opacity:.6;display:block;margin-bottom:5px;">Which item (yours)</label>
        <select id="ap-res" style="width:100%;"></select>
      </div>
      <div>
        <label style="font-size:12px;opacity:.6;display:block;margin-bottom:5px;">Push to</label>
        <select id="ap-user" style="width:100%;">
          <option value="__all__">— All Users —</option>
          <?php foreach ($_all_users_share as $uu): ?>
          <option value="<?= htmlspecialchars($uu['username']) ?>"><?= htmlspecialchars($uu['username']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div>
        <button class="btn btn-primary" onclick="doPush()">📤 Push Copy</button>
      </div>
    </div>
    <div id="ap-msg" style="margin-top:10px;font-size:12px;display:none;"></div>
  </div>
  <script>
  const _apResources = <?= json_encode($_share_resources, JSON_UNESCAPED_UNICODE) ?>;
  function apPopulateRes(){
    const type=document.getElementById('ap-type').value;
    const sel=document.getElementById('ap-res');
    const items=_apResources[type]||[];
    sel.innerHTML=items.length?items.map(i=>`<option value="${i.id}">${i.name||i.id}</option>`).join(''):'<option value="">— no items —</option>';
  }
  apPopulateRes();
  function doPush(){
    const type=document.getElementById('ap-type').value;
    const res=document.getElementById('ap-res');
    const user=document.getElementById('ap-user').value;
    const msg=document.getElementById('ap-msg');
    if(!res.value){msg.style.display='block';msg.style.color='#f88';msg.textContent='No item selected.';return;}
    const fd=new FormData();
    fd.append('action','admin_push_to_user');
    fd.append('push_type',type);
    fd.append('push_res_id',res.value);
    fd.append('push_to',user);
    msg.style.display='block';msg.style.color='rgba(255,255,255,.5)';msg.textContent='Pushing…';
    fetch('options.php',{method:'POST',body:fd})
      .then(r=>r.text()).then(html=>{
        const ok=html.includes('class="msg success"');
        const m=html.match(/class="msg\s+(?:success|error)"[^>]*>\s*([^<]+)/);
        msg.style.color=ok?'#6d6':'#f88';
        msg.textContent=m?m[1].trim():(ok?'Pushed!':'Unknown error.');
      }).catch(()=>{msg.style.color='#f88';msg.textContent='Request failed.';});
  }
  </script>
  <?php endif; ?>

  <!-- ── Shared with Me ─────────────────────────────────────────────────── -->
  <div class="section" style="margin-bottom:16px;">
    <h2>📥 Shared with Me</h2>
    <p style="font-size:12px;color:rgba(255,255,255,.45);margin-bottom:14px;">
      Items other users have shared with you. Accept to add them to your dashboard; decline to dismiss.
    </p>
    <?php
    $sharesIn = $_my_shares_in ?? [];
    if (empty($sharesIn)): ?>
      <p style="font-size:12px;color:rgba(255,255,255,.3);">Nothing shared with you yet.</p>
    <?php else: ?>
    <table style="width:100%;border-collapse:collapse;font-size:13px;">
      <thead>
        <tr style="color:rgba(255,255,255,.45);border-bottom:1px solid rgba(255,255,255,.1);">
          <th style="text-align:left;padding:5px 8px;">From</th>
          <th style="text-align:left;padding:5px 8px;">Type</th>
          <th style="text-align:left;padding:5px 8px;">Name</th>
          <th style="text-align:left;padding:5px 8px;">Status</th>
          <th style="text-align:right;padding:5px 8px;">Action</th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($sharesIn as $sh): ?>
        <tr style="border-bottom:1px solid rgba(255,255,255,.06);">
          <td style="padding:6px 8px;"><strong><?= htmlspecialchars($sh['from_user']) ?></strong></td>
          <td style="padding:6px 8px;"><?= $_stypes[$sh['resource_type']] ?? $sh['resource_type'] ?></td>
          <td style="padding:6px 8px;"><?= htmlspecialchars($sh['resource_name']) ?></td>
          <td style="padding:6px 8px;">
            <?php if ($sh['status']==='pending'): ?>
              <span style="color:#fba;font-weight:600;">⏳ Pending</span>
            <?php elseif ($sh['status']==='accepted'): ?>
              <span style="color:#6d6;font-weight:600;">✓ Accepted</span>
            <?php else: ?>
              <span style="color:rgba(255,255,255,.35);">✗ Declined</span>
            <?php endif; ?>
          </td>
          <td style="padding:6px 8px;text-align:right;display:flex;gap:6px;justify-content:flex-end;flex-wrap:wrap;">
            <?php if ($sh['status']==='pending'): ?>
              <form method="POST" style="display:inline;">
                <input type="hidden" name="action" value="accept_share">
                <input type="hidden" name="share_id" value="<?= (int)$sh['id'] ?>">
                <button type="submit" class="btn btn-primary btn-sm">✓ Accept</button>
              </form>
              <form method="POST" style="display:inline;">
                <input type="hidden" name="action" value="decline_share">
                <input type="hidden" name="share_id" value="<?= (int)$sh['id'] ?>">
                <button type="submit" class="btn btn-secondary btn-sm">✗ Decline</button>
              </form>
            <?php elseif ($sh['status']==='accepted'): ?>
              <form method="POST" style="display:inline;" onsubmit="return confirm('Remove this shared item from your dashboard?')">
                <input type="hidden" name="action" value="decline_share">
                <input type="hidden" name="share_id" value="<?= (int)$sh['id'] ?>">
                <button type="submit" class="btn btn-secondary btn-sm">🗑 Remove</button>
              </form>
            <?php else: ?>
              <form method="POST" style="display:inline;">
                <input type="hidden" name="action" value="accept_share">
                <input type="hidden" name="share_id" value="<?= (int)$sh['id'] ?>">
                <button type="submit" class="btn btn-secondary btn-sm">↩ Re-accept</button>
              </form>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <?php endif; ?>
  </div>

  <!-- ── What I'm Sharing ───────────────────────────────────────────────── -->
  <div class="section" style="margin-bottom:16px;">
    <h2>📤 What I'm Sharing</h2>
    <p style="font-size:12px;color:rgba(255,255,255,.45);margin-bottom:14px;">
      Items you've shared with others. Revoke any share at any time — it disappears from their dashboard immediately.
    </p>
    <?php
    $sharesOut = $_my_shares_out ?? [];
    if (empty($sharesOut)): ?>
      <p style="font-size:12px;color:rgba(255,255,255,.3);">You haven't shared anything yet. Use the form below.</p>
    <?php else: ?>
    <table style="width:100%;border-collapse:collapse;font-size:13px;margin-bottom:8px;">
      <thead>
        <tr style="color:rgba(255,255,255,.45);border-bottom:1px solid rgba(255,255,255,.1);">
          <th style="text-align:left;padding:5px 8px;">With</th>
          <th style="text-align:left;padding:5px 8px;">Type</th>
          <th style="text-align:left;padding:5px 8px;">Name</th>
          <th style="text-align:left;padding:5px 8px;">Status</th>
          <th style="text-align:right;padding:5px 8px;">Action</th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($sharesOut as $sh): ?>
        <tr style="border-bottom:1px solid rgba(255,255,255,.06);">
          <td style="padding:6px 8px;"><strong><?= htmlspecialchars($sh['to_user']) ?></strong></td>
          <td style="padding:6px 8px;"><?= $_stypes[$sh['resource_type']] ?? $sh['resource_type'] ?></td>
          <td style="padding:6px 8px;"><?= htmlspecialchars($sh['resource_name']) ?></td>
          <td style="padding:6px 8px;">
            <?php if ($sh['status']==='pending'): ?>
              <span style="color:#fba;">⏳ Awaiting acceptance</span>
            <?php elseif ($sh['status']==='accepted'): ?>
              <span style="color:#6d6;">✓ Active</span>
            <?php else: ?>
              <span style="color:rgba(255,255,255,.35);">✗ Declined by user</span>
            <?php endif; ?>
          </td>
          <td style="padding:6px 8px;text-align:right;">
            <form method="POST" style="display:inline;" onsubmit="return confirm('Revoke this share?')">
              <input type="hidden" name="action" value="revoke_share">
              <input type="hidden" name="share_id" value="<?= (int)$sh['id'] ?>">
              <button type="submit" class="btn btn-danger btn-sm">🗑 Revoke</button>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <?php endif; ?>
  </div>

  <!-- ── Share an Item ──────────────────────────────────────────────────── -->
  <div class="section">
    <h2>➕ Share an Item</h2>
    <p style="font-size:12px;color:rgba(255,255,255,.45);margin-bottom:16px;">
      Pick the type, choose which item, and select the user to share with. They'll see a pending invite in their Sharing tab.
    </p>
    <?php if (empty($_all_users_share)): ?>
      <p style="font-size:12px;color:rgba(255,255,255,.35);">No other users to share with. Add users in the Users tab.</p>
    <?php else: ?>
    <div style="display:grid;grid-template-columns:1fr 1fr 1fr auto;gap:12px;align-items:flex-end;flex-wrap:wrap;" class="grid-row">
      <div>
        <label style="font-size:12px;opacity:.6;display:block;margin-bottom:5px;">Resource type</label>
        <select id="sh-type" onchange="shPopulateRes()" style="width:100%;">
          <?php foreach ($_stypes as $tk => $tv): ?>
          <option value="<?= $tk ?>"><?= $tv ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div>
        <label style="font-size:12px;opacity:.6;display:block;margin-bottom:5px;">Which item</label>
        <select id="sh-res" style="width:100%;"></select>
      </div>
      <div>
        <label style="font-size:12px;opacity:.6;display:block;margin-bottom:5px;">Share with</label>
        <select id="sh-user" style="width:100%;">
          <?php foreach ($_all_users_share as $uu): ?>
          <option value="<?= htmlspecialchars($uu['username']) ?>"><?= htmlspecialchars($uu['username']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div>
        <button class="btn btn-primary" onclick="doShare()">🔗 Share</button>
      </div>
    </div>
    <div id="sh-msg" style="margin-top:10px;font-size:12px;display:none;"></div>
    <?php endif; ?>
  </div>

  <script>
  const _shResources = <?= json_encode($_share_resources, JSON_UNESCAPED_UNICODE) ?>;
  function shPopulateRes() {
    const type = document.getElementById('sh-type').value;
    const sel  = document.getElementById('sh-res');
    const items = _shResources[type] || [];
    sel.innerHTML = items.length
      ? items.map(i=>`<option value="${i.id}">${i.name||i.id}</option>`).join('')
      : '<option value="">— no items of this type —</option>';
  }
  shPopulateRes();
  function doShare() {
    const type = document.getElementById('sh-type').value;
    const res  = document.getElementById('sh-res');
    const user = document.getElementById('sh-user').value;
    const msg  = document.getElementById('sh-msg');
    if (!res.value) { msg.style.display='block'; msg.style.color='#f88'; msg.textContent='No item selected for this type.'; return; }
    const resName = res.options[res.selectedIndex]?.text || res.value;
    const fd = new FormData();
    fd.append('action','create_share');
    fd.append('share_type', type);
    fd.append('share_resource_id', res.value);
    fd.append('share_resource_name', resName);
    fd.append('share_to_user', user);
    fetch('options.php', {method:'POST', body:fd})
      .then(r=>r.text()).then(html=>{
        const isOk  = html.includes('class="msg success"');
        const textM = html.match(/class="msg\s+(?:success|error)"[^>]*>\s*([^<]+)/);
        const text  = textM ? textM[1].trim() : (isOk ? 'Shared!' : 'Unknown error.');
        msg.style.color   = isOk ? '#6d6' : '#f88';
        msg.textContent   = text;
        msg.style.display = 'block';
        if (isOk) setTimeout(()=>location.reload(),1200);
      }).catch(()=>{ msg.style.display='block'; msg.style.color='#f88'; msg.textContent='Request failed.'; });
  }
  </script>
  <?php endif; ?>
</div>

<script>
// ── Update checker ──────────────────────────────────────────────────────────
let _pendingZipUrl = '', _pendingZipSha = '';

async function checkForUpdate() {
  document.getElementById('update-spinner').style.display='block';
  document.getElementById('update-result').style.display='none';
  try {
    const r = await fetch('check_update.php');
    const d = await r.json();
    document.getElementById('update-spinner').style.display='none';
    const res = document.getElementById('update-result');
    res.style.display='block';
    const st = document.getElementById('update-status');
    if (!d.ok) {
      st.style.color='#f87171';
      st.textContent='❌ ' + (d.error || 'Check failed.');
      document.getElementById('update-changelog').textContent='';
      document.getElementById('update-download-row').style.display='none';
      return;
    }
    if (d.newer) {
      st.style.color='#4ade80';
      st.textContent='🎉 Update available — v' + d.remote + ' (you have v' + d.current + ')';
    } else {
      st.style.color='#a3e635';
      st.textContent='✅ You are up to date (v' + d.current + ')';
    }
    document.getElementById('update-changelog').textContent = d.changelog || '';
    if (d.newer && d.zip_url) {
      _pendingZipUrl = d.zip_url;
      _pendingZipSha = d.sha256 || '';
      document.getElementById('update-zip-size').textContent = d.zip_size ? '(' + d.zip_size + ')' : '';
      document.getElementById('update-download-row').style.display='block';
    } else {
      document.getElementById('update-download-row').style.display='none';
    }
  } catch(e) {
    document.getElementById('update-spinner').style.display='none';
    document.getElementById('update-result').style.display='block';
    document.getElementById('update-status').textContent='❌ Network error: '+e.message;
    document.getElementById('update-status').style.color='#f87171';
  }
}

async function applyUpdateFromUrl() {
  if (!_pendingZipUrl) return;
  if (!confirm('Download and apply v' + document.getElementById('update-status').textContent.match(/v[\d.]+/)?.[0] + '? The page will reload after.')) return;
  document.getElementById('apply-spinner').style.display='block';
  document.getElementById('apply-result').style.display='none';
  const fd = new FormData();
  fd.append('mode','url');
  fd.append('zip_url', _pendingZipUrl);
  if (_pendingZipSha) fd.append('sha256', _pendingZipSha);
  try {
    const r = await fetch('apply_update.php', {method:'POST', body:fd});
    const d = await r.json();
    document.getElementById('apply-spinner').style.display='none';
    const el = document.getElementById('apply-result');
    el.style.display='block';
    if (d.ok) {
      el.style.color='#4ade80';
      el.textContent='✅ ' + (d.message || 'Update applied! Reload to use the new version.');
      setTimeout(()=>location.reload(),3000);
    } else {
      el.style.color='#f87171';
      el.textContent='❌ ' + (d.error || 'Update failed.');
    }
  } catch(e) {
    document.getElementById('apply-spinner').style.display='none';
    const el=document.getElementById('apply-result');
    el.style.display='block';el.style.color='#f87171';
    el.textContent='❌ Network error: '+e.message;
  }
}

async function applyUpdateFromUpload() {
  const f = document.getElementById('update-zip-file').files[0];
  if (!f) { alert('Please choose a ZIP file first.'); return; }
  if (!confirm('Apply this ZIP update? Protected files (dash_config.php, uploads/) will not be touched.')) return;
  document.getElementById('apply-spinner').style.display='block';
  document.getElementById('apply-result').style.display='none';
  const fd = new FormData();
  fd.append('mode','upload');
  fd.append('update_zip', f);
  const sha = document.getElementById('update-sha256').value.trim();
  if (sha) fd.append('sha256', sha);
  try {
    const r = await fetch('apply_update.php', {method:'POST', body:fd});
    const d = await r.json();
    document.getElementById('apply-spinner').style.display='none';
    const el = document.getElementById('apply-result');
    el.style.display='block';
    if (d.ok) {
      el.style.color='#4ade80';
      el.innerHTML='✅ ' + (d.message||'Update applied!') + (d.migrations?.length ? '<br><small>Migrations: '+d.migrations.join(', ')+'</small>' : '');
      setTimeout(()=>location.reload(),3500);
    } else {
      el.style.color='#f87171';
      el.textContent='❌ ' + (d.error||'Update failed.');
    }
  } catch(e) {
    document.getElementById('apply-spinner').style.display='none';
    const el=document.getElementById('apply-result');
    el.style.display='block';el.style.color='#f87171';
    el.textContent='❌ Network error: '+e.message;
  }
}

async function importBackup() {
  const f = document.getElementById('import-zip-file').files[0];
  if (!f) { alert('Please choose a backup ZIP file first.'); return; }
  if (!confirm('Restore from this backup? Existing data for your account will be overwritten.')) return;
  document.getElementById('import-spinner').style.display='block';
  document.getElementById('import-result').style.display='none';
  const fd = new FormData();
  fd.append('import_zip', f);
  try {
    const r = await fetch('import_data.php', {method:'POST', body:fd});
    const d = await r.json();
    document.getElementById('import-spinner').style.display='none';
    const el = document.getElementById('import-result');
    el.style.display='block';
    if (d.ok) {
      el.style.color='#4ade80';
      el.textContent='✅ ' + (d.message||'Restored successfully.');
    } else {
      el.style.color='#f87171';
      el.textContent='❌ ' + (d.error||'Restore failed.');
    }
  } catch(e) {
    document.getElementById('import-spinner').style.display='none';
    const el=document.getElementById('import-result');
    el.style.display='block';el.style.color='#f87171';
    el.textContent='❌ Network error: '+e.message;
  }
}
</script>

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
function exportUserData(username){
  const f=document.createElement('form');
  f.method='POST';f.style.display='none';
  const a=document.createElement('input');a.type='hidden';a.name='action';a.value='export_user_data';
  const u=document.createElement('input');u.type='hidden';u.name='export_username';u.value=username;
  f.appendChild(a);f.appendChild(u);document.body.appendChild(f);f.submit();f.remove();
}
function showImportBox(username){
  document.getElementById('import-user-label').textContent='Import data for: '+username;
  document.getElementById('import-username-val').value=username;
  const box=document.getElementById('import-user-box');
  box.style.display='block';
  box.scrollIntoView({behavior:'smooth',block:'nearest'});
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

// ─── Theme ZIP export ───────────────────────────────────────────────────────
function startThemeZipExport(){
  const sel = document.getElementById('zip-export-theme');
  if (!sel) return;
  const theme = sel.value;
  if (!theme) { alert('Please select a theme first.'); return; }
  window.location.href = 'options.php?export_theme_zip=1&theme=' + encodeURIComponent(theme);
}

// ─── Export/Import ─────────────────────────────────────────────────────────
function exportSettings(){
  const data={
    theme:     localStorage.getItem('hp-theme')||'win9x',
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
  const wrap=sel.closest('.row2');
  const inp=wrap.querySelector('.url-input');
  const fitRow=wrap.querySelector('.fit-row');
  const isImg=sel.value==='image_url';
  if(inp){
    if(isImg) inp.placeholder='https://example.com/bg.jpg';
    else if(sel.value==='iframe_url') inp.placeholder='https://example.com/animated-page.html';
    else inp.placeholder='https://example.com/video.mp4';
  }
  if(fitRow) fitRow.style.display=isImg?'flex':'none';
}

// ─── Upload type helper (show/hide fit select for images) ────────────────────
function uploadTypeChange(sel){
  const row=sel.closest('.row2').querySelector('.fit-row');
  if(row) row.style.display=sel.value==='image'?'flex':'none';
}

// ─── Link management (AJAX — no page reload, no scroll jump) ────────────────

// POST FormData to the dedicated link AJAX endpoint, return parsed JSON.
// Uses ?lajax=1 — a completely separate gate before any HTML output,
// identical in pattern to the working ?bgajax=1 endpoint.
async function _linkPost(fd) {
  const r = await fetch('options.php?lajax=1', { method: 'POST', body: fd });
  if (!r.ok) throw new Error('HTTP ' + r.status);
  const text = await r.text();
  try { return JSON.parse(text); }
  catch(e) { console.error('lajax bad JSON:', text.slice(0,200)); throw e; }
}

// ── Delete single link ────────────────────────────────────────────────────────
function deleteLink(btn) {
  if (!confirm('Delete this link?')) return;
  const secId = btn.dataset.secId;
  const url   = btn.dataset.url;
  const card  = btn.closest('.link-card');
  const secEl = btn.closest('.link-sec');
  btn.disabled = true;
  const fd = new FormData();
  fd.append('action',  'delete_link');
  fd.append('ajax',    '1');
  fd.append('sec_id',  secId);
  fd.append('url_key', url);
  _linkPost(fd).then(j => {
    if (j && j.ok) {
      card.remove();
      const badge = secEl && secEl.querySelector('.link-sec-count');
      if (badge) badge.textContent = (secEl.querySelectorAll('.link-card').length) + ' links';
    } else {
      btn.disabled = false;
      alert('Delete failed: ' + (j && j.error ? j.error : 'server error'));
    }
  }).catch(() => { btn.disabled = false; alert('Network error'); });
}

// ── Delete entire column ──────────────────────────────────────────────────────
function deleteColumn(btn) {
  if (!confirm('Delete this entire column and all its links?\nThis cannot be undone.')) return;
  const secId = btn.dataset.secId;
  const secEl = btn.closest('.link-sec');
  btn.disabled = true;
  const fd = new FormData();
  fd.append('action', 'delete_section');
  fd.append('ajax',   '1');
  fd.append('sec_id', secId);
  _linkPost(fd).then(j => {
    if (j && j.ok) {
      if (secEl) secEl.remove();
    } else {
      btn.disabled = false;
      alert('Delete failed: ' + (j && j.error ? j.error : 'server error'));
    }
  }).catch(() => { btn.disabled = false; alert('Network error'); });
}

// ── Icon edit modal ───────────────────────────────────────────────────────────
function openIconEdit(btn) {
  document.getElementById('ie-sec').value  = btn.dataset.secId;
  document.getElementById('ie-url').value  = btn.dataset.url;
  document.getElementById('ie-icon').value = '';
  document.getElementById('ie-file').value = '';
  document.getElementById('ie-status').textContent = '';
  document.getElementById('ie-save-btn').disabled  = false;
  // Remember which card to update in the DOM on success
  document.getElementById('icon-edit-form')._card = btn.closest('.link-card');
  document.getElementById('icon-edit-modal').style.display = 'flex';
}
document.getElementById('icon-edit-form').addEventListener('submit', async function(e) {
  e.preventDefault();
  const status  = document.getElementById('ie-status');
  const saveBtn = document.getElementById('ie-save-btn');
  const icon    = document.getElementById('ie-icon').value.trim();
  const fileInp = document.getElementById('ie-file');
  if (!icon && (!fileInp.files || !fileInp.files.length)) {
    status.style.color = '#f87171';
    status.textContent = 'Enter an emoji or pick an image file.';
    return;
  }
  saveBtn.disabled = true;
  status.style.color = '#6ee7b7';
  status.textContent = 'Saving…';
  try {
    const j = await _linkPost(new FormData(this));
    if (j && j.ok) {
      // Update icon in DOM instantly — no reload needed
      const card = this._card;
      if (card) {
        const wrap = card.querySelector('.link-icon-wrap');
        if (wrap) {
          if (j.icon_img) {
            wrap.innerHTML = `<img src="${j.icon_img}" style="width:24px;height:24px;border-radius:50%;object-fit:cover;" alt="">`;
          } else if (j.icon) {
            wrap.innerHTML = `<span style="font-size:18px;">${j.icon}</span>`;
          }
        }
      }
      status.textContent = '✅ Saved!';
      setTimeout(() => { document.getElementById('icon-edit-modal').style.display = 'none'; }, 700);
    } else {
      status.style.color = '#f87171';
      status.textContent = 'Error: ' + (j && j.error ? j.error : 'server error');
      saveBtn.disabled = false;
    }
  } catch(err) {
    status.style.color = '#f87171';
    status.textContent = 'Network error';
    saveBtn.disabled = false;
  }
});

// ── Move-link modal ───────────────────────────────────────────────────────────
function openMoveLink(btn) {
  document.getElementById('ml-from').value        = btn.dataset.secId;
  document.getElementById('ml-url').value         = btn.dataset.url;
  document.getElementById('ml-label').textContent = btn.dataset.label || '';
  document.getElementById('ml-status').textContent = '';
  document.getElementById('ml-save-btn').disabled  = false;
  document.getElementById('move-link-form')._btn   = btn;
  document.getElementById('move-link-modal').style.display = 'flex';
}
document.getElementById('move-link-form').addEventListener('submit', async function(e) {
  e.preventDefault();
  const status  = document.getElementById('ml-status');
  const saveBtn = document.getElementById('ml-save-btn');
  saveBtn.disabled = true;
  status.style.color = '#6ee7b7';
  status.textContent = 'Moving…';
  try {
    const j = await _linkPost(new FormData(this));
    if (j && j.ok) {
      // Move card in the DOM to the target section — no reload
      const srcBtn  = this._btn;
      const card    = srcBtn ? srcBtn.closest('.link-card') : null;
      const toSecId = document.getElementById('ml-to-sec').value;
      const toSecEl = document.querySelector('#link-sec-' + CSS.escape(toSecId));
      if (card && toSecEl) {
        const srcSec = card.closest('.link-sec');
        toSecEl.appendChild(card);
        // Keep data-sec-id in sync on all buttons of the moved card
        card.querySelectorAll('[data-sec-id]').forEach(b => b.dataset.secId = toSecId);
        [srcSec, toSecEl].forEach(sec => {
          if (!sec) return;
          const badge = sec.querySelector('.link-sec-count');
          if (badge) badge.textContent = sec.querySelectorAll('.link-card').length + ' links';
        });
      }
      status.textContent = '✅ Moved!';
      setTimeout(() => { document.getElementById('move-link-modal').style.display = 'none'; }, 700);
    } else {
      status.style.color = '#f87171';
      status.textContent = 'Error: ' + (j && j.error ? j.error : 'server error');
      saveBtn.disabled = false;
    }
  } catch(err) {
    status.style.color = '#f87171';
    status.textContent = 'Network error';
    saveBtn.disabled = false;
  }
});

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
    <div id="tie-fit-row" style="margin-bottom:10px;display:none;align-items:center;gap:8px;flex-wrap:wrap;">
      <label style="font-size:12px;color:rgba(255,255,255,.6);white-space:nowrap;margin:0;">Image fit:</label>
      <select id="tie-fit" style="background:#1a1a2e;color:#fff;border:1px solid rgba(255,255,255,.2);border-radius:6px;padding:5px 10px;font-size:12px;">
        <option value="fill">🖼 Fill (cover, no distortion)</option>
        <option value="stretch">↔ Stretch (distort to exact size)</option>
        <option value="center">⊙ Center (natural size, centered)</option>
        <option value="tile">🪟 Tile (repeat like wallpaper)</option>
      </select>
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
  document.getElementById('tie-fit-row').style.display = isImage ? 'flex' : 'none';
  document.getElementById('tie-file').setAttribute('accept', isImage ? 'image/*' : 'video/*');
}
function saveBg(theme) {
  const typeEl = document.querySelector('input[name="tie-type"]:checked');
  if (!typeEl) return;
  const type    = typeEl.value;
  const name    = (document.getElementById('tie-name').value || '').trim() || 'Custom';
  const fitEl   = document.getElementById('tie-fit');
  const bgFit   = fitEl ? fitEl.value : 'fill';
  const msgEl   = document.getElementById('tie-msg');
  const saveBtn = document.getElementById('tie-save-btn');
  const file    = document.getElementById('tie-file').files[0];
  if (!file) { alert('Please choose a file to upload.'); return; }
  const fd = new FormData();
  fd.append('theme', theme);
  fd.append('bg_fit', bgFit);
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
        if (m) { m.style.color='#4ade80'; m.textContent = '✓ Upload complete! Add another or close.'; }
        if (saveBtn) saveBtn.textContent = '✓ Saved';
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

function toggleHwPreview(){
  const box=document.getElementById('hw-preview-box');
  const ta=document.querySelector('[name="hw_html"]');
  if(!box||!ta)return;
  if(box.style.display==='none'||!box.style.display){
    box.style.display='block';
    box.innerHTML=ta.value||'<em style="opacity:.4;font-size:12px;">Nothing to preview yet — paste your HTML embed code above.</em>';
    // Re-run scripts inside preview (for embed widgets)
    box.querySelectorAll('script').forEach(s=>{
      const ns=document.createElement('script');
      [...s.attributes].forEach(a=>ns.setAttribute(a.name,a.value));
      ns.textContent=s.textContent;
      s.parentNode.replaceChild(ns,s);
    });
  } else {
    box.style.display='none';
    box.innerHTML='';
  }
}
</script>
</body>
</html>

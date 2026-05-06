<?php
session_start();
require_once __DIR__.'/presets.php';

function alreadyConfigured() {
    $f = __DIR__.'/dash_config.php';
    if (!file_exists($f)) return false;
    $c = @file_get_contents($f) ?: '';
    return strpos($c,'DASH_SETUP_DONE') !== false
        && strpos($c,"DASH_SETUP_DONE', false") === false;
}
if (alreadyConfigured() && !isset($_GET['reconfigure'])) {
    header('Location: login.php'); exit;
}

// Fresh install (no config yet): clear any stale auth cookies from a previous install
// so the admin is not auto-logged in as an old user.
if (!alreadyConfigured()) {
    setcookie('dash_auth', '', time() - 3600, '/');
    setcookie('dash_auth', '', time() - 3600, '/', '', false, true);
}

// When the site is already configured, ALL AJAX actions require a logged-in session.
// This prevents unauthenticated access to scan_server, get_drives, upload_icon, test_db
// via the ?reconfigure bypass.
if (alreadyConfigured() && isset($_GET['action'])) {
    if (session_status() === PHP_SESSION_NONE) session_start();
    $_setupAuthed = !empty($_SESSION['logged_in']);
    if (!$_setupAuthed && isset($_COOKIE['dash_auth'])) {
        $_cfgRaw = @file_get_contents(__DIR__ . '/dash_config.php') ?: '';
        preg_match("/define\('DASH_USERNAME',\s*'([^']+)'\)/", $_cfgRaw, $_um);
        $_cfgUser = $_um[1] ?? 'admin';
        $_expected = hash('sha256', $_cfgUser . ($_SERVER['HTTP_USER_AGENT'] ?? '') . 'dash_secret_salt_2024');
        if (isset($_COOKIE['dash_auth']) && hash_equals($_expected, $_COOKIE['dash_auth'])) {
            $_setupAuthed = true;
        }
    }
    if (!$_setupAuthed) {
        header('Content-Type: application/json');
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'Authentication required. Please log in first.']);
        exit;
    }
}

/* ─── Utility helpers ─────────────────────────────────────────────────── */
function probeUrl($url, $to=5) {
    $scheme = strtolower(parse_url($url, PHP_URL_SCHEME) ?? '');
    if (!in_array($scheme, ['http','https'])) return '';
    $ctx = stream_context_create([
        'http'  =>['timeout'=>$to,'ignore_errors'=>true,'follow_location'=>true,'max_redirects'=>3,
                   'header'=>"User-Agent: Mozilla/5.0\r\n"],
        'https' =>['timeout'=>$to,'ignore_errors'=>true,'follow_location'=>true,'max_redirects'=>3,
                   'header'=>"User-Agent: Mozilla/5.0\r\n"],
    ]);
    return @file_get_contents($url, false, $ctx) ?: '';
}
function extractTitle($html) {
    preg_match('/<title[^>]*>([^<]{1,200})<\/title>/i', $html, $m);
    return html_entity_decode(trim($m[1]??''), ENT_QUOTES|ENT_HTML5);
}
function isBadTitle($t) {
    if (!$t) return true;
    foreach (['401','403','404','500','502','503','it works','test page','welcome to nginx',
              'index of /','default page','bad request','misdirected'] as $b)
        if (stripos($t,$b)!==false) return true;
    return false;
}
function recognizeService($url,$title) {
    $s = strtolower($url.' '.$title);
    $m = ['portainer'=>'🐳','nextcloud'=>'☁️','wordpress'=>'📝','wp-admin'=>'📝',
          'gitea'=>'🦊','forgejo'=>'🦊','gitlab'=>'🦊','jellyfin'=>'🎵','plex'=>'🎬','emby'=>'🎵',
          'home assistant'=>'🏠','homeassistant'=>'🏠','haos'=>'🏠',
          'grafana'=>'📊','prometheus'=>'🔥','pihole'=>'⬛','pi-hole'=>'⬛','adguard'=>'🛡',
          'vaultwarden'=>'🔐','bitwarden'=>'🔐','freshrss'=>'📰','miniflux'=>'📰',
          'phpmyadmin'=>'🗄','adminer'=>'🗄','pgadmin'=>'🗄','syncthing'=>'🔁',
          'sonarr'=>'📺','radarr'=>'🎬','lidarr'=>'🎵','readarr'=>'📚','bazarr'=>'💬',
          'prowlarr'=>'🔍','jackett'=>'🔍','qbittorrent'=>'⬇️','deluge'=>'⬇️','transmission'=>'⬇️',
          'navidrome'=>'🎵','photoprism'=>'📸','immich'=>'📸','paperless'=>'📄','mealie'=>'🍽',
          'grocy'=>'🛒','netdata'=>'📡','uptime kuma'=>'📶','uptimekuma'=>'📶',
          'code-server'=>'💻','vscode'=>'💻','traefik'=>'🔀','nginx proxy manager'=>'🟢',
          'wireguard'=>'🔒','authelia'=>'🔑','authentik'=>'🔑','keycloak'=>'🔑','speedtest'=>'⚡',
          'calibre'=>'📚','kavita'=>'📚','komga'=>'📚','filebrowser'=>'📁','duplicati'=>'💾',
          'seafile'=>'🌊','minio'=>'🪣','searxng'=>'🔍','searx'=>'🔍','roundcube'=>'📧',
          'webmail'=>'📧','unifi'=>'📡','homer'=>'🖥','organizr'=>'🖥','homarr'=>'🖥',
          'nginx'=>'🟢','apache'=>'🔴','mysql'=>'🐬','mariadb'=>'🐬',
          'postgresql'=>'🐘','redis'=>'⚡','mongodb'=>'🍃','bookstack'=>'📚','wiki'=>'📚',
          'mattermost'=>'💬','rocketchat'=>'💬','changedetection'=>'🔔',
          'github'=>'🐙','google'=>'🌐','youtube'=>'▶️','gmail'=>'📧',
          'dropbox'=>'📦','slack'=>'💬','zoom'=>'📹','teams'=>'💼',
          'netflix'=>'🎬','spotify'=>'🎵','amazon'=>'📦','twitter'=>'🐦','instagram'=>'📸',
          'facebook'=>'👥','linkedin'=>'💼','reddit'=>'🤖','twitch'=>'🎮',
          'whatsapp'=>'💬','telegram'=>'✈️','discord'=>'💬'];
    foreach ($m as $k=>$v) if (strpos($s,$k)!==false) return $v;
    return '🔗';
}

/* ─── Server detection (admin path) ───────────────────────────────────── */
function detectApacheSites() {
    $sites = [];
    $dirs  = ['/etc/apache2/sites-enabled','/etc/httpd/conf.d'];
    foreach ($dirs as $dir) {
        foreach (glob("$dir/*.conf") ?: [] as $f) {
            $c = @file_get_contents($f) ?: '';
            preg_match_all('/ServerName\s+(\S+)/i', $c, $nm);
            preg_match('/Listen\s+(\d+)/i', $c, $lm);
            $ssl = stripos($c,'SSLEngine on') !== false;
            foreach ($nm[1] as $host) {
                if (str_contains($host,'{{')) continue;
                $scheme = $ssl ? 'https' : 'http';
                $port = $lm[1] ?? ($ssl?443:80);
                $portStr = ($ssl&&$port==443)||(!$ssl&&$port==80) ? '' : ":$port";
                $sites[] = ['url'=>"$scheme://$host$portStr",'label'=>$host,'source'=>'apache'];
            }
        }
    }
    return $sites;
}
function detectNginxSites() {
    $sites = [];
    $dirs  = ['/etc/nginx/sites-enabled','/etc/nginx/conf.d'];
    foreach ($dirs as $dir) {
        foreach (glob("$dir/*.conf") ?: glob("$dir/*") ?: [] as $f) {
            if (!is_file($f)) continue;
            $c = @file_get_contents($f) ?: '';
            preg_match_all('/server_name\s+([^;]+);/i', $c, $nm);
            $ssl = stripos($c,'ssl_certificate') !== false;
            preg_match('/listen\s+(\d+)/i', $c, $lm);
            $port = $lm[1] ?? ($ssl?443:80);
            foreach (explode(' ', trim($nm[1][0] ?? '')) as $host) {
                $host = trim($host);
                if (!$host || $host==='_') continue;
                $scheme = $ssl?'https':'http';
                $portStr = ($ssl&&$port==443)||(!$ssl&&$port==80) ? '' : ":$port";
                $sites[] = ['url'=>"$scheme://$host$portStr",'label'=>$host,'source'=>'nginx'];
            }
        }
    }
    return $sites;
}
function detectDockerContainers() {
    $out = @shell_exec('docker ps --format "{{.Names}}\t{{.Ports}}\t{{.Image}}" 2>/dev/null');
    if (!$out) return [];
    $sites = [];
    foreach (explode("\n", trim($out)) as $line) {
        if (!$line) continue;
        [$name,$ports,$image] = array_pad(explode("\t",$line),3,'');
        preg_match_all('/0\.0\.0\.0:(\d+)->(\d+)/i', $ports, $pm);
        foreach ($pm[1] as $i=>$hostPort) {
            $url   = "http://localhost:$hostPort";
            $icon  = recognizeService($url, $name.' '.$image);
            $sites[] = ['url'=>$url,'label'=>$name,'icon'=>$icon,'source'=>'docker'];
        }
    }
    return $sites;
}
function detectAllDrives() {
    $drives = [];
    $raw = @shell_exec('df -h --output=source,size,used,avail,pcent,target 2>/dev/null');
    if (!$raw) $raw = @shell_exec('df -h 2>/dev/null');
    if (!$raw) return $drives;
    $lines = array_slice(explode("\n",trim($raw)),1);
    $skip  = ['tmpfs','devtmpfs','udev','overlay','shm','proc','sysfs','devpts','squashfs','none','cgroupfs','cgroup2'];
    foreach ($lines as $line) {
        if (!trim($line)) continue;
        $p = preg_split('/\s+/',trim($line));
        if (count($p) < 6) continue;
        $fs=$p[0];$size=$p[1];$used=$p[2];$avail=$p[3];$pct=(int)$p[4];$mount=$p[5];
        $fsType = strtolower($fs);
        $skip_this = false;
        foreach ($skip as $s) if (strpos($fsType,$s)!==false||strpos($mount,'/sys')===0||strpos($mount,'/proc')===0||strpos($mount,'/dev/loop')!==false) { $skip_this=true; break; }
        if ($skip_this||$size==='0'||$size==='0B') continue;
        $isNetwork = (str_starts_with($fs,'//')||str_starts_with($fs,'\\\\')
            ||strpos($fs,':/')!==false||strpos(strtolower($fs),'nfs')!==false
            ||strpos(strtolower($fs),'smb')!==false||strpos(strtolower($fs),'cifs')!==false);
        $label = basename($mount) ?: ($mount==='/'?'Root':'Drive');
        if ($label=='/') $label='Root';
        $icon  = $isNetwork ? '🌐' : ($mount==='/'?'🖥':'💾');
        $drives[] = ['path'=>$mount,'label'=>ucfirst($label),'icon'=>$icon,'size'=>$size,
                     'used'=>$used,'avail'=>$avail,'used_pct'=>$pct,'network'=>$isNetwork];
    }
    return $drives;
}

/* ─── AJAX: Scan server for sites ─────────────────────────────────────── */
if (isset($_GET['action']) && $_GET['action']==='scan_server') {
    header('Content-Type: application/json');
    $all = array_merge(detectApacheSites(), detectNginxSites(), detectDockerContainers());
    $results = [];
    $seen = [];
    foreach ($all as $s) {
        $url = $s['url'];
        if (isset($seen[$url])) continue;
        $seen[$url] = true;
        $html  = probeUrl($url, 3);
        $title = $html ? extractTitle($html) : '';
        $icon  = $s['icon'] ?? recognizeService($url, $title.' '.$s['label']);
        $label = ($title && !isBadTitle($title)) ? $title : $s['label'];
        if (stripos($label,'setup wizard')!==false||stripos($label,'server dashboard')!==false) continue;
        $results[] = ['label'=>$label,'url'=>$url,'icon'=>$icon,'source'=>$s['source']];
    }
    echo json_encode(['ok'=>true,'sites'=>$results,'count'=>count($results)]);
    exit;
}

/* ─── AJAX: Get all mounted drives ────────────────────────────────────── */
if (isset($_GET['action']) && $_GET['action']==='get_drives') {
    header('Content-Type: application/json');
    echo json_encode(['ok'=>true,'drives'=>detectAllDrives()]);
    exit;
}

/* ─── AJAX: Upload icon ────────────────────────────────────────────────── */
if (isset($_GET['action']) && $_GET['action']==='upload_icon') {
    header('Content-Type: application/json');
    $file = $_FILES['icon'] ?? null;
    if (!$file || $file['error']!==0) { echo json_encode(['error'=>'Upload failed']); exit; }
    $ext = strtolower(pathinfo($file['name'],PATHINFO_EXTENSION));
    if (!in_array($ext,['png','jpg','jpeg','ico','gif','webp','svg'])) {
        echo json_encode(['error'=>'Use PNG, JPG, ICO, SVG or GIF']); exit;
    }
    $dir = __DIR__.'/icons/';
    if (!is_dir($dir)) mkdir($dir,0755,true);
    $name = 'icon_'.bin2hex(random_bytes(8)).'.'.$ext;
    if (move_uploaded_file($file['tmp_name'],$dir.$name)) {
        echo json_encode(['ok'=>true,'url'=>'icons/'.$name]);
    } else {
        echo json_encode(['error'=>'Could not save file']);
    }
    exit;
}

/* ─── AJAX: Test DB connection ─────────────────────────────────────────── */
if (isset($_GET['action']) && $_GET['action']==='test_db') {
    header('Content-Type: application/json');
    $h = $_GET['host'] ?? '127.0.0.1';
    $p = (int)($_GET['port'] ?? 3306);
    $u = $_GET['user'] ?? 'root';
    $pw= $_GET['pass'] ?? '';
    $n = $_GET['name'] ?? 'dashboard';
    try {
        $dsn = "mysql:host={$h};port={$p};dbname={$n};charset=utf8mb4";
        $pdo = new PDO($dsn, $u, $pw, [PDO::ATTR_TIMEOUT=>5, PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
        echo json_encode(['ok'=>true]);
    } catch (Exception $e) {
        echo json_encode(['ok'=>false,'error'=>$e->getMessage()]);
    }
    exit;
}

/* ─── Upgrade detection helper ─────────────────────────────────────────── */
function detectUpgrade() {
    // An upgrade is when user data files exist but DASH_SETUP_DONE is false
    return file_exists(__DIR__.'/dash_links.json')
        && filesize(__DIR__.'/dash_links.json') > 10;
}
$isUpgrade = detectUpgrade();

/* ─── POST: Complete setup ─────────────────────────────────────────────── */
if ($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['action']??'')==='complete') {
    $uname  = preg_replace('/[^a-zA-Z0-9_-]/','',trim($_POST['username']??'admin'));
    $pass   = $_POST['password'] ?? '';
    $title  = htmlspecialchars(trim($_POST['title']??'Server Dashboard'),ENT_QUOTES);
    $cols   = 3;
    $theme  = preg_replace('/[^a-z0-9_]/','',trim($_POST['theme']??'win9x'));
    if (strlen($pass)<4) $pass='admin';
    $hash = password_hash($pass, PASSWORD_BCRYPT);

    $dbType = preg_replace('/[^a-z]/', '', strtolower($_POST['db_type'] ?? 'none'));
    if ($dbType === 'mysql') {
        $h=addslashes($_POST['db_host']??'127.0.0.1');$p=(int)($_POST['db_port']??3306);
        $u=addslashes($_POST['db_user']??'root');$pw=addslashes($_POST['db_pass']??'');
        $n=addslashes($_POST['db_name']??'dashboard');
        $dbConf="define('DASH_DB_TYPE','mysql');\ndefine('DASH_DB_HOST','$h');\ndefine('DASH_DB_PORT',$p);\ndefine('DASH_DB_USER','$u');\ndefine('DASH_DB_PASS','$pw');\ndefine('DASH_DB_NAME','$n');\n";
    } else {
        // JSON flat-file mode — no MySQL config written, getDashDb() returns null gracefully
        $dbConf = "define('DASH_DB_TYPE','none');\n";
    }
    file_put_contents(__DIR__.'/dash_config.php',
        "<?php\ndefine('DASH_SETUP_DONE',true);\ndefine('DASH_USERNAME','".addslashes($uname)."');\ndefine('DASH_PASSWORD_HASH','".addslashes($hash)."');\ndefine('DASH_TITLE','".addslashes($title)."');\ndefine('DASH_GRID_COLS',$cols);\n$dbConf");

    // ── Preserve existing data files on upgrade; only write if new install ──
    // User's explicit choice overrides file-based auto-detection.
    $installType   = $_POST['install_type'] ?? 'auto';
    if ($installType === 'fresh') {
        $isUpgradeSave = false;           // User confirmed fresh — wipe old data
    } elseif ($installType === 'upgrade') {
        $isUpgradeSave = true;            // User confirmed upgrade — preserve all data
    } else {
        $isUpgradeSave = detectUpgrade(); // Auto-detect fallback
    }

    // ── Extra safety: if MySQL is configured and already has admin data, NEVER wipe ──
    // Catches the case where a user re-runs setup choosing "fresh" on a live MySQL install.
    if ($dbType === 'mysql' && !$isUpgradeSave) {
        try {
            $ckDsn = "mysql:host=".($_POST['db_host']??'127.0.0.1').";port="
                    .(int)($_POST['db_port']??3306).";dbname="
                    .($_POST['db_name']??'dashboard').";charset=utf8mb4";
            $ckPdo = new PDO($ckDsn, $_POST['db_user']??'root', $_POST['db_pass']??'',
                            [PDO::ATTR_TIMEOUT=>3, PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
            // Create table if missing (safe no-op on existing installs)
            $ckPdo->exec("CREATE TABLE IF NOT EXISTS dash_settings (
                id INT AUTO_INCREMENT PRIMARY KEY,
                username VARCHAR(64) NOT NULL,
                `key` VARCHAR(64) NOT NULL,
                value MEDIUMTEXT,
                UNIQUE KEY uk_user_key (username, `key`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
            $ckStmt = $ckPdo->prepare(
                "SELECT COUNT(*) FROM dash_settings WHERE username=? LIMIT 1");
            $ckStmt->execute([$uname]);
            if ((int)$ckStmt->fetchColumn() > 0) {
                $isUpgradeSave = true; // MySQL already has user data — protect it
            }
        } catch (Exception $e) { /* Can't connect or verify — proceed as-is */ }
    }

    // Links — skip if upgrading (existing links stay intact)
    if (!$isUpgradeSave) {
        $links_raw = json_decode($_POST['links_json']??'[]',true)?:[];
        $sections  = [];
        foreach ($links_raw as $col) {
            $cards=[];
            foreach ($col['cards']??[] as $c) {
                if (!($c['url']??'')) continue;
                $cards[]=['icon'=>$c['icon']??'🔗','label'=>$c['label']??$c['url'],'url'=>$c['url']];
            }
            $ct  = $col['title']??'Column';
            $cid = 'sec-'.preg_replace('/[^a-z0-9]/','',strtolower($ct)).'-'.substr(md5($ct),0,4);
            $sections[]=['id'=>$cid,'title'=>$ct,'icon'=>$col['icon']??'🔗','cards'=>$cards];
        }
        if (empty($sections)) {
            $sections[]=['id'=>'sec-server','title'=>'My Server','icon'=>'🖥',
                         'cards'=>[['icon'=>'⚙️','label'=>'Options','url'=>'options.php']]];
        }
        file_put_contents(__DIR__.'/dash_links.json', json_encode($sections,JSON_PRETTY_PRINT));
    }

    // Monitor preferences — only write on fresh install
    if (!$isUpgradeSave) {
        $mon = ['cpu'=>(bool)($_POST['mon_cpu']??false),'ram'=>(bool)($_POST['mon_ram']??false),
                'storage'=>(bool)($_POST['mon_storage']??false)];
        file_put_contents(__DIR__.'/dash_monitor.json', json_encode($mon,JSON_PRETTY_PRINT));
    }

    // Drives — only write on fresh install
    if (!$isUpgradeSave) {
        $drives_raw = json_decode($_POST['drives_json']??'[]',true)?:[];
        $drives_out=[];
        foreach ($drives_raw as $d) {
            $k=preg_replace('/[^a-z0-9_]/','',strtolower($d['path']??''));
            if (!$k) $k='drv'.count($drives_out);
            $drives_out[]=['key'=>$k,'path'=>$d['path'],'label'=>$d['label']??$k,'icon'=>$d['icon']??'💾'];
        }
        file_put_contents(__DIR__.'/dash_drives.json', json_encode($drives_out,JSON_PRETTY_PRINT));
    }

    // Hidden themes — only write on fresh install
    if (!$isUpgradeSave) {
        $ht = json_decode($_POST['hidden_themes_json']??'[]',true)?:[];
        file_put_contents(__DIR__.'/dash_hidden_themes.json',
            json_encode(array_values(array_filter(array_map(fn($k)=>preg_replace('/[^a-z0-9_]/','', $k),$ht))),JSON_PRETTY_PRINT));
    }

    // Empty defaults (always safe — only creates if missing)
    if (!file_exists(__DIR__.'/dash_users.json'))        file_put_contents(__DIR__.'/dash_users.json','[]');
    if (!file_exists(__DIR__.'/dash_custom_bg.json'))    file_put_contents(__DIR__.'/dash_custom_bg.json','{}');

    // ── Seed initial widgets directly into MySQL ──────────────────────────
    if (!$isUpgradeSave) {
        $wjson = $_POST['widgets_json'] ?? '';
        $wdata = $wjson ? (json_decode($wjson, true) ?: []) : [];
        $wantsSticky = !empty($wdata['sticky']);
        unset($wdata['sticky']); // sticky flag only, not a widget row array
        try {
            $wh  = $_POST['db_host'] ?? '127.0.0.1';
            $wp  = (int)($_POST['db_port'] ?? 3306);
            $wu  = $_POST['db_user'] ?? 'root';
            $wpw = $_POST['db_pass'] ?? '';
            $wn  = $_POST['db_name'] ?? 'dashboard';
            $wdsn = "mysql:host={$wh};port={$wp};dbname={$wn};charset=utf8mb4";
            $wpdo = new PDO($wdsn, $wu, $wpw, [PDO::ATTR_TIMEOUT=>5, PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
            // Ensure widgets table exists (db.php creates it on first index.php load too)
            $wpdo->exec("CREATE TABLE IF NOT EXISTS dash_widgets (
                id          INT AUTO_INCREMENT PRIMARY KEY,
                username    VARCHAR(64) NOT NULL,
                widget_type VARCHAR(32) NOT NULL,
                data        MEDIUMTEXT  NOT NULL DEFAULT '[]',
                updated_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uk_user_type (username, widget_type)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
            $wstmt = $wpdo->prepare('INSERT IGNORE INTO dash_widgets (username,widget_type,data) VALUES (?,?,?)');
            foreach (['rss','camera','calendar','countdown'] as $wt) {
                if (!empty($wdata[$wt])) {
                    $wstmt->execute([$uname, $wt, json_encode($wdata[$wt], JSON_UNESCAPED_UNICODE)]);
                }
            }
            // Sticky notes setting
            $wpdo->exec("CREATE TABLE IF NOT EXISTS dash_settings (
                id         INT AUTO_INCREMENT PRIMARY KEY,
                username   VARCHAR(64) NOT NULL,
                `key`      VARCHAR(64) NOT NULL,
                value      MEDIUMTEXT,
                UNIQUE KEY uk_user_key (username, `key`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
            $wpdo->prepare('INSERT INTO dash_settings (username,`key`,value) VALUES (?,?,?) ON DUPLICATE KEY UPDATE value=VALUES(value)')
                 ->execute([$uname, 'dash_sticky_enabled', $wantsSticky ? '1' : '0']);
        } catch (Exception $e) { /* Silently skip — user can configure widgets from Options */ }
    }

    $_SESSION['logged_in']=true;$_SESSION['setup_done']=true;$_SESSION['setup_theme']=$theme;
    echo json_encode(['ok'=>true]);
    exit;
}
$hostname = @gethostname()?:'localhost';
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Setup Wizard — Server Dashboard</title>
<style>
*{box-sizing:border-box;margin:0;padding:0;}
body{font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',sans-serif;background:#020804;color:#fff;min-height:100vh;}
.wrap{position:relative;z-index:1;max-width:860px;margin:0 auto;padding:36px 20px 80px;}
#retro-bg{position:fixed;inset:0;z-index:0;pointer-events:none;opacity:.28;}
.wiz-hdr{text-align:center;margin-bottom:28px;}
.wiz-logo{font-size:46px;margin-bottom:10px;}
.wiz-title{font-size:26px;font-weight:800;}
.wiz-sub{color:rgba(255,255,255,.45);font-size:13px;margin-top:5px;}
/* Steps */
.steps{display:flex;margin-bottom:28px;background:rgba(6,14,24,.88);border:1px solid rgba(255,255,255,.08);border-radius:12px;padding:4px;overflow-x:auto;gap:2px;backdrop-filter:blur(4px);}
.step{flex:1;text-align:center;padding:7px 4px;border-radius:8px;font-size:10px;font-weight:700;color:rgba(255,255,255,.35);white-space:nowrap;transition:all .2s;}
.step.active{background:rgba(74,158,255,.2);color:#4a9eff;}
.step.done{color:rgba(80,220,80,.8);}
.step .num{display:block;font-size:14px;margin-bottom:1px;}
/* Panel */
.panel{display:none;background:rgba(6,14,24,.92);border:1px solid rgba(255,255,255,.1);border-radius:16px;padding:26px;backdrop-filter:blur(4px);}
.panel.active{display:block;}
h2{font-size:17px;font-weight:700;margin-bottom:5px;}
.sub{font-size:12px;color:rgba(255,255,255,.4);margin-bottom:18px;line-height:1.6;}
/* Buttons */
.btn{display:inline-flex;align-items:center;gap:6px;padding:9px 18px;border:none;border-radius:8px;font-size:13px;font-weight:600;cursor:pointer;transition:all .15s;vertical-align:middle;}
.btn:disabled{opacity:.45;cursor:not-allowed;}
.btn-primary{background:linear-gradient(135deg,#4a9eff,#7c4dff);color:#fff;}
.btn-secondary{background:rgba(255,255,255,.09);border:1px solid rgba(255,255,255,.18);color:#fff;}
.btn-success{background:rgba(40,200,80,.2);border:1px solid rgba(40,200,80,.4);color:#5ef08a;}
.btn-danger{background:rgba(255,60,60,.15);border:1px solid rgba(255,60,60,.3);color:#ff7070;}
.btn-sm{padding:6px 13px;font-size:12px;}
.btn-xs{padding:3px 9px;font-size:11px;}
.btn:hover:not(:disabled){opacity:.85;transform:translateY(-1px);}
.nav{display:flex;justify-content:space-between;margin-top:26px;gap:12px;}
/* Forms */
label{display:block;font-size:11px;font-weight:600;color:rgba(255,255,255,.5);margin-bottom:4px;text-transform:uppercase;letter-spacing:.04em;}
input[type=text],input[type=password],input[type=number],input[type=url]{
  width:100%;padding:9px 12px;background:rgba(255,255,255,.07);border:1px solid rgba(255,255,255,.14);
  border-radius:8px;color:#fff;font-size:13px;outline:none;font-family:inherit;}
input:focus{border-color:rgba(74,158,255,.6);background:rgba(74,158,255,.06);}
select{padding:8px 12px;background:rgba(255,255,255,.09);border:1px solid rgba(255,255,255,.18);border-radius:8px;color:#fff;font-size:13px;outline:none;}
.fg{display:grid;grid-template-columns:1fr 1fr;gap:14px;margin-bottom:14px;}
.hint{font-size:11px;color:rgba(255,255,255,.32);margin-top:5px;line-height:1.5;}
/* Choice cards (admin/personal) */
.choice-grid{display:grid;grid-template-columns:1fr 1fr;gap:14px;margin-bottom:20px;}
.choice-card{padding:24px 18px;border:2px solid rgba(255,255,255,.1);border-radius:14px;cursor:pointer;text-align:center;background:rgba(255,255,255,.04);transition:all .2s;}
.choice-card:hover{border-color:rgba(74,158,255,.5);background:rgba(74,158,255,.07);}
.choice-card.selected{border-color:#4a9eff;background:rgba(74,158,255,.12);}
.choice-card .ci{font-size:34px;display:block;margin-bottom:10px;}
.choice-card strong{font-size:14px;display:block;margin-bottom:6px;}
.choice-card p{font-size:11px;color:rgba(255,255,255,.45);line-height:1.5;}
.choice-card .badge{display:inline-block;margin-top:8px;font-size:10px;padding:3px 10px;border-radius:20px;background:rgba(255,200,50,.15);border:1px solid rgba(255,200,50,.3);color:#ffd060;}
/* Scan results */
.scan-box{background:rgba(0,0,0,.3);border:1px solid rgba(255,255,255,.1);border-radius:12px;padding:18px;margin-top:14px;}
.site-item{display:flex;align-items:center;gap:10px;padding:9px 10px;background:rgba(255,255,255,.05);border:1px solid rgba(255,255,255,.08);border-radius:8px;margin-bottom:6px;cursor:pointer;transition:border-color .15s;}
.site-item:hover{border-color:rgba(74,158,255,.4);}
.site-item.checked{border-color:#4a9eff;background:rgba(74,158,255,.09);}
.site-icon{font-size:20px;width:28px;text-align:center;flex-shrink:0;}
.site-info{flex:1;min-width:0;}
.site-name{font-size:13px;font-weight:600;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;}
.site-url{font-size:10px;color:#4a9eff;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;}
.site-src{font-size:10px;padding:2px 7px;border-radius:10px;background:rgba(255,255,255,.08);color:rgba(255,255,255,.5);flex-shrink:0;}
.cb-box{width:18px;height:18px;border:2px solid rgba(255,255,255,.3);border-radius:4px;display:flex;align-items:center;justify-content:center;flex-shrink:0;font-size:11px;}
.site-item.checked .cb-box{background:#4a9eff;border-color:#4a9eff;}
/* Column builder */
.prebuilt-row{display:flex;gap:8px;flex-wrap:wrap;margin-bottom:16px;}
.col-card{background:rgba(255,255,255,.05);border:1px solid rgba(255,255,255,.1);border-radius:12px;margin-bottom:10px;overflow:hidden;}
.col-head{display:flex;align-items:center;gap:8px;padding:10px 14px;background:rgba(255,255,255,.04);border-bottom:1px solid rgba(255,255,255,.08);}
.col-head .cico{font-size:20px;cursor:pointer;line-height:1;padding:2px 4px;border-radius:4px;}
.col-head .cico:hover{background:rgba(255,255,255,.1);}
.col-head .ctitle{flex:1;}
.col-head .ctitle input{background:transparent;border:none;border-bottom:1px dashed rgba(255,255,255,.3);border-radius:0;color:#fff;font-size:13px;font-weight:600;width:100%;padding:2px 4px;text-transform:none;letter-spacing:0;}
.col-head .ctitle input:focus{outline:none;border-bottom-color:#4a9eff;}
.col-body{padding:8px 12px;}
.link-row{display:flex;align-items:center;gap:7px;padding:6px 8px;background:rgba(255,255,255,.04);border-radius:6px;margin-bottom:5px;}
.link-ico{font-size:15px;cursor:pointer;min-width:20px;text-align:center;}
.link-ico img{width:18px;height:18px;object-fit:contain;border-radius:3px;}
.link-label{font-size:12px;font-weight:500;flex:1;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;}
.link-url{font-size:10px;color:#4a9eff;max-width:140px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;}
/* Inline add forms */
.ifrm{background:rgba(0,0,0,.25);border:1px solid rgba(255,255,255,.1);border-radius:10px;padding:12px;margin-top:8px;}
.ifrm h4{font-size:12px;font-weight:700;margin-bottom:10px;color:rgba(255,255,255,.7);}
.ifrm .r3{display:grid;grid-template-columns:1fr 1fr 48px;gap:8px;align-items:flex-end;}
/* Drive monitoring */
.mon-checks{display:flex;gap:12px;flex-wrap:wrap;margin-bottom:18px;}
.mon-check{display:flex;align-items:center;gap:8px;padding:12px 16px;background:rgba(255,255,255,.05);border:2px solid rgba(255,255,255,.1);border-radius:10px;cursor:pointer;transition:all .2s;user-select:none;}
.mon-check:hover{border-color:rgba(74,158,255,.4);}
.mon-check.on{border-color:#4a9eff;background:rgba(74,158,255,.1);}
.mon-check .micon{font-size:22px;}
.mon-check .mlabel{font-size:13px;font-weight:600;}
/* Drive list */
.drive-row{display:flex;align-items:center;gap:10px;padding:9px 12px;background:rgba(255,255,255,.04);border:1px solid rgba(255,255,255,.08);border-radius:9px;margin-bottom:6px;cursor:pointer;transition:border-color .15s;}
.drive-row:hover{border-color:rgba(74,158,255,.4);}
.drive-row.on{border-color:#4a9eff;background:rgba(74,158,255,.08);}
.drv-icon{font-size:22px;flex-shrink:0;}
.drv-info{flex:1;min-width:0;}
.drv-label{font-size:13px;font-weight:600;}
.drv-path{font-size:10px;color:rgba(255,255,255,.4);margin-top:1px;}
.drv-stats{font-size:11px;color:#4a9eff;margin-top:2px;}
.drv-bar{height:4px;background:rgba(255,255,255,.1);border-radius:2px;margin-top:4px;}
.drv-fill{height:4px;border-radius:2px;}
/* DB section */
.db-header{display:flex;align-items:center;gap:12px;padding:14px 18px;background:rgba(74,158,255,.08);border:1px solid rgba(74,158,255,.2);border-radius:12px;margin-bottom:20px;}
.db-header .db-icon{font-size:28px;line-height:1;}
.db-header strong{font-size:14px;display:block;margin-bottom:2px;}
.db-header span{font-size:12px;color:rgba(255,255,255,.45);}
/* Theme grid */
.tgrid{display:grid;grid-template-columns:repeat(auto-fill,minmax(100px,1fr));gap:7px;margin-bottom:14px;}
.tc{padding:9px 5px;border:2px solid rgba(255,255,255,.1);border-radius:10px;text-align:center;cursor:pointer;transition:all .2s;background:rgba(255,255,255,.04);}
.tc:hover{border-color:rgba(74,158,255,.5);}
.tc.on{border-color:#4a9eff;background:rgba(74,158,255,.12);}
.tc.off{opacity:.3;border-color:rgba(255,255,255,.05);}
.ti{font-size:20px;display:block;margin-bottom:3px;}
.tn{font-size:9px;font-weight:700;}
/* Icon picker modal */
.modal-bg{position:fixed;inset:0;background:rgba(0,0,0,.75);z-index:1000;display:flex;align-items:center;justify-content:center;padding:20px;}
.modal{background:#111827;border:1px solid rgba(255,255,255,.12);border-radius:16px;padding:22px;max-width:540px;width:100%;max-height:80vh;overflow-y:auto;}
.modal h3{font-size:15px;font-weight:700;margin-bottom:14px;}
.icat{font-size:11px;font-weight:700;color:rgba(255,255,255,.4);text-transform:uppercase;letter-spacing:.06em;margin:12px 0 6px;}
.igrid{display:flex;flex-wrap:wrap;gap:5px;}
.ico-btn{width:38px;height:38px;font-size:20px;background:rgba(255,255,255,.07);border:1px solid rgba(255,255,255,.1);border-radius:8px;cursor:pointer;display:flex;align-items:center;justify-content:center;transition:all .15s;line-height:1;}
.ico-btn:hover{background:rgba(74,158,255,.2);border-color:#4a9eff;transform:scale(1.1);}
.upload-zone{border:2px dashed rgba(255,255,255,.2);border-radius:10px;padding:18px;text-align:center;margin-top:12px;cursor:pointer;transition:border-color .2s;}
.upload-zone:hover{border-color:rgba(74,158,255,.5);}
.upload-zone input{display:none;}
/* Misc */
.empty{text-align:center;padding:22px;color:rgba(255,255,255,.3);font-size:12px;}
.tag{display:inline-block;font-size:10px;padding:2px 8px;border-radius:12px;margin:2px;}
.tag-a{background:rgba(255,140,50,.15);border:1px solid rgba(255,140,50,.3);color:#ffb060;}
.tag-n{background:rgba(50,200,80,.15);border:1px solid rgba(50,200,80,.3);color:#60ef80;}
.tag-d{background:rgba(0,180,255,.15);border:1px solid rgba(0,180,255,.3);color:#60d0ff;}
.spinner{width:15px;height:15px;border:2px solid rgba(255,255,255,.25);border-top-color:#fff;border-radius:50%;animation:spin .7s linear infinite;display:inline-block;}
@keyframes spin{to{transform:rotate(360deg)}}
.vr-ok{background:rgba(40,200,80,.1);border:1px solid rgba(40,200,80,.3);border-radius:8px;padding:10px 14px;font-size:12px;margin-top:8px;}
.vr-err{background:rgba(255,60,60,.1);border:1px solid rgba(255,60,60,.3);border-radius:8px;padding:10px 14px;font-size:12px;color:#ff8080;margin-top:8px;}
.summary-chip{display:inline-block;padding:5px 13px;background:rgba(74,158,255,.15);border:1px solid rgba(74,158,255,.3);border-radius:20px;font-size:13px;margin:4px;}
/* Widget options step */
.wgt-opts{display:flex;flex-direction:column;gap:10px;margin-bottom:20px;}
.wgt-opt{background:rgba(255,255,255,.04);border:1px solid rgba(255,255,255,.08);border-radius:12px;padding:14px 16px;transition:border-color .2s;}
.wgt-opt.active{border-color:rgba(74,158,255,.35);background:rgba(74,158,255,.05);}
.wgt-opt-head{display:flex;align-items:center;gap:12px;}
.wgt-opt-icon{font-size:22px;line-height:1;flex-shrink:0;width:28px;text-align:center;}
.wgt-opt-info{flex:1;min-width:0;}
.wgt-opt-info strong{font-size:13px;display:block;margin-bottom:2px;}
.wgt-opt-info span{font-size:11px;color:rgba(255,255,255,.4);line-height:1.45;}
.wgt-toggle{display:flex;gap:4px;flex-shrink:0;}
.wgt-btn{padding:5px 14px;border:1px solid rgba(255,255,255,.15);border-radius:6px;font-size:12px;font-weight:600;cursor:pointer;background:rgba(255,255,255,.06);color:rgba(255,255,255,.45);transition:all .15s;}
.wgt-btn:hover{opacity:.8;}
.wgt-btn.yes-on{background:rgba(74,158,255,.2);border-color:#4a9eff;color:#7ec8ff;}
.wgt-btn.no-on{background:rgba(255,255,255,.06);border-color:rgba(255,255,255,.2);color:rgba(255,255,255,.5);}
.wgt-detail{margin-top:12px;padding-top:12px;border-top:1px solid rgba(255,255,255,.06);}
</style>
</head>
<body>
<canvas id="retro-bg"></canvas>

<!-- ── ICON PICKER MODAL ─────────────────────────────────────────── -->
<div id="icon-modal" class="modal-bg" style="display:none;" onclick="if(event.target===this)closeIconPicker()">
  <div class="modal">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:14px;">
      <h3>🎨 Pick an Icon</h3>
      <button class="btn btn-xs btn-danger" onclick="closeIconPicker()">✕ Close</button>
    </div>
    <div class="icat">Recently Used & Popular</div>
    <div class="igrid" id="icon-popular"></div>
    <?php
    $ilib = [
      'Web & Navigation'  =>['🌐','🔗','🏠','🌍','🌎','📡','🧭','🔮','🏗','🗺'],
      'Social Media'      =>['💬','👥','❤️','📘','🐦','📸','🎥','🤳','📲','💌','🤝','🗣'],
      'Media & Streaming' =>['🎬','🎵','🎧','📺','🎮','🎨','🖼','🎙','📻','🎞','🎤','🎯'],
      'Search & AI'       =>['🔍','🤖','🧠','✨','⚡','💡','🦆','🌱','🐋','🔮','🧬','📡'],
      'Files & Storage'   =>['📁','📂','📄','📝','📊','📈','💾','🗂','📋','📃','💿','🗃'],
      'Security & Auth'   =>['🔒','🔓','🛡','🔐','🗝','🔑','⚔','🚨','🔏','🛂','🪪','👁'],
      'Cloud & Network'   =>['☁️','🌩','⬆️','⬇️','🔄','📤','📥','🌐','📶','🛰','🔁','♻️'],
      'Tools & Dev'       =>['⚙️','🔧','🔨','🛠','🔌','🔩','⛏','💻','🖥','⌨','🖱','📟'],
      'Finance & Shop'    =>['💰','💳','🏦','💵','💸','🛒','🏪','💹','📉','📈','🪙','🎁'],
      'Communication'     =>['📧','📨','📩','📬','☎️','📞','📠','💬','🗨','📣','📢','🔔'],
      'Servers & Infra'   =>['🐳','🐧','🖧','🗄','🏭','🔀','🟢','🔴','🟡','🏁','⚡','🔥'],
      'Stars & Misc'      =>['⭐','💫','✨','🔥','💎','🏆','🎯','🎲','🌟','🚀','💥','🎪'],
    ];
    foreach ($ilib as $cat => $icons):
    ?>
    <div class="icat"><?= htmlspecialchars($cat) ?></div>
    <div class="igrid">
      <?php foreach ($icons as $ic): ?>
      <button class="ico-btn" onclick="pickIcon('<?= $ic ?>')" title="<?= $ic ?>"><?= $ic ?></button>
      <?php endforeach; ?>
    </div>
    <?php endforeach; ?>
    <div class="upload-zone" onclick="document.getElementById('icon-upload-inp').click()">
      <div style="font-size:24px;margin-bottom:6px;">📁</div>
      <div style="font-size:12px;font-weight:600;margin-bottom:3px;">Upload your own icon</div>
      <div style="font-size:11px;color:rgba(255,255,255,.4);">PNG, JPG, ICO, SVG, GIF — max 500 KB</div>
      <input type="file" id="icon-upload-inp" accept=".png,.jpg,.jpeg,.ico,.gif,.webp,.svg" onchange="uploadIcon(this)">
    </div>
    <div id="icon-upload-status" style="margin-top:6px;font-size:12px;"></div>
  </div>
</div>

<div class="wrap">
  <div class="wiz-hdr">
    <div class="wiz-logo">🖥</div>
    <div class="wiz-title">Server Dashboard Setup</div>
    <div class="wiz-sub">Quick first-run wizard · takes about 2 minutes</div>
  </div>

  <!-- ══ STEP 0: INSTALL TYPE CHOICE ══════════════════════════════ -->
  <div id="panel-0" style="display:block;">
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:18px;margin:8px 0 4px;">
      <button onclick="chooseInstall('fresh')" style="background:rgba(40,200,100,.12);border:2px solid rgba(40,200,100,.35);border-radius:14px;padding:32px 20px;cursor:pointer;color:#fff;text-align:center;transition:all .2s;" onmouseover="this.style.borderColor='rgba(40,200,100,.7)';this.style.background='rgba(40,200,100,.2)'" onmouseout="this.style.borderColor='rgba(40,200,100,.35)';this.style.background='rgba(40,200,100,.12)'">
        <div style="font-size:38px;margin-bottom:10px;">🆕</div>
        <div style="font-size:16px;font-weight:700;margin-bottom:6px;">Fresh Install</div>
        <div style="font-size:12px;color:rgba(255,255,255,.55);line-height:1.5;">Clean slate — I wiped the directory<br>or this is a brand new server</div>
      </button>
      <button onclick="chooseInstall('upgrade')" style="background:rgba(74,158,255,.12);border:2px solid rgba(74,158,255,.35);border-radius:14px;padding:32px 20px;cursor:pointer;color:#fff;text-align:center;transition:all .2s;" onmouseover="this.style.borderColor='rgba(74,158,255,.7)';this.style.background='rgba(74,158,255,.2)'" onmouseout="this.style.borderColor='rgba(74,158,255,.35)';this.style.background='rgba(74,158,255,.12)'">
        <div style="font-size:38px;margin-bottom:10px;">🔄</div>
        <div style="font-size:16px;font-weight:700;margin-bottom:6px;">Upgrade / Reinstall</div>
        <div style="font-size:12px;color:rgba(255,255,255,.55);line-height:1.5;">I'm upgrading over an existing install —<br>keep my links, widgets &amp; users</div>
      </button>
    </div>
    <p style="text-align:center;font-size:11px;color:rgba(255,255,255,.3);margin-top:14px;">Not sure? Choose <strong style="color:rgba(255,255,255,.5);">Fresh Install</strong> if you just extracted the ZIP into a clean directory.</p>
  </div>

  <div id="wizard-body" style="display:none;">
  <div class="steps">
    <div class="step" id="s1"><span class="num">①</span>Account</div>
    <div class="step" id="s2"><span class="num">②</span>Links</div>
    <div class="step" id="s3"><span class="num">③</span>Widgets</div>
    <div class="step" id="s4"><span class="num">④</span>Monitor</div>
    <div class="step" id="s5"><span class="num">⑤</span>Database</div>
    <div class="step" id="s6"><span class="num">⑥</span>Theme</div>
    <div class="step" id="s7"><span class="num">⑦</span>Done!</div>
  </div>

  <!-- ══ STEP 1: ACCOUNT ══════════════════════════════════════════ -->
  <div class="panel" id="panel-1">
    <h2>👤 Account Setup</h2>
    <!-- Upgrade banner — shown by JS when user chose "Upgrade" on step 0 -->
    <div id="upgrade-banner" style="display:none;background:rgba(74,158,255,.15);border:1px solid rgba(74,158,255,.4);border-radius:8px;padding:12px 16px;margin-bottom:18px;font-size:13px;line-height:1.6;">
      <strong style="color:#4a9eff;">🔄 Upgrading existing install</strong><br>
      Your links, widgets, drives, themes, and users will stay intact — only <code>dash_config.php</code> is being rewritten.<br>
      <strong>Just set a new password below and click Finish Upgrade</strong> — all your data will be kept exactly as-is.
    </div>
    <p class="sub" id="fresh-sub">Set your dashboard title and create the admin account. You can add more users later via ⚙️ Options.</p>
    <div class="fg">
      <div style="grid-column:1/-1"><label>Dashboard Title</label><input type="text" id="f-title" value="Server Dashboard" placeholder="My Home Server"></div>
      <div><label>Admin Username</label><input type="text" id="f-user" value="admin"></div>
      <div><label>Admin Password <span style="color:#f66;">*</span></label>
        <div style="display:flex;gap:6px;align-items:center;">
          <input type="password" id="f-pass" placeholder="min 4 characters" autocomplete="new-password" style="flex:1;">
          <button type="button" onclick="(function(){var i=document.getElementById('f-pass');var b=document.getElementById('pw-eye');i.type=i.type==='password'?'text':'password';b.textContent=i.type==='password'?'👁':'🙈';})()" id="pw-eye" style="background:rgba(255,255,255,.08);border:1px solid rgba(255,255,255,.15);border-radius:6px;color:#fff;cursor:pointer;padding:6px 10px;font-size:14px;flex-shrink:0;">👁</button>
        </div>
      </div>
    </div>
    <p class="hint">ⓘ You will stay logged in for <strong>6 months</strong> on this device. Additional users (Editor / Read-only) can be added from Options after setup.</p>
    <div class="nav"><span></span>
      <button id="upgrade-finish-btn" class="btn btn-primary" style="display:none;" onclick="doFinish()">✅ Finish Upgrade →</button>
      <button id="fresh-next-btn"     class="btn btn-primary"                        onclick="goStep(2)">Next: Links →</button>
    </div>
  </div>

  <!-- ══ STEP 2: LINKS ════════════════════════════════════════════ -->
  <div class="panel" id="panel-2">
    <h2>🔗 Dashboard Links</h2>
    <p class="sub">Build your link columns — create named groups and add URLs manually. You can always add, edit, or rearrange links later directly on the dashboard.</p>

    <!-- Column builder -->
    <div id="personal-path">
      <div style="margin-bottom:10px;display:flex;gap:8px;flex-wrap:wrap;align-items:center;">
        <button class="btn btn-primary btn-sm" onclick="showNewColForm()">📁 New Column</button>
        <span style="font-size:11px;color:rgba(255,255,255,.35);">— or add a pre-built category:</span>
      </div>
      <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(150px,1fr));gap:6px;margin-bottom:12px;">
        <?php foreach (dashGetPresets() as $_sp_key => $_sp_v): ?>
        <button class="btn btn-secondary btn-sm" id="pb-btn-<?= htmlspecialchars($_sp_key) ?>" onclick='addPrebuilt(<?= htmlspecialchars(json_encode($_sp_key), ENT_QUOTES) ?>)' style="text-align:left;gap:5px;display:flex;align-items:center;transition:background .15s,border .15s;">
          <span class="pb-check" style="display:none;color:#6d6;font-size:13px;">✔</span>
          <?= $_sp_v['icon'] ?> <?= htmlspecialchars($_sp_key) ?>
        </button>
        <?php endforeach; ?>
      </div>
      <div style="margin-bottom:12px;display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
        <label style="font-size:12px;color:rgba(255,255,255,.5);margin:0;">📥 Import browser bookmarks:</label>
        <input type="file" id="bookmark-file" accept=".html,.htm" style="font-size:11px;color:#ccc;" onchange="importBookmarks(this)">
        <span style="font-size:11px;color:rgba(255,255,255,.3);">Export from Chrome/Firefox/Edge: Bookmarks Manager → Export</span>
      </div>

      <!-- New column form -->
      <div id="new-col-form" class="ifrm" style="display:none;">
        <h4>📁 New Column</h4>
        <div style="display:flex;gap:8px;align-items:flex-end;flex-wrap:wrap;">
          <div><label>Icon</label>
            <button class="btn btn-secondary btn-sm" id="ncol-icon-btn" onclick="openIconPicker('ncol-icon')" style="font-size:18px;min-width:44px;">📁</button>
            <input type="hidden" id="ncol-icon" value="📁">
          </div>
          <div style="flex:2;min-width:160px;"><label>Column Name</label><input type="text" id="ncol-name" placeholder="e.g. Social Media, Work, Tools…" onkeydown="if(event.key==='Enter')commitNewCol()"></div>
          <button class="btn btn-primary btn-sm" onclick="commitNewCol()" style="margin-top:18px;">+ Create</button>
          <button class="btn btn-xs btn-danger" onclick="document.getElementById('new-col-form').style.display='none'" style="margin-top:18px;">Cancel</button>
        </div>
      </div>

      <div id="col-list" style="margin-top:12px;"></div>
      <div id="col-empty" class="empty">No columns yet. Click <strong>New Column</strong> or add a pre-built one above.</div>
    </div>

    <div class="nav">
      <button class="btn btn-secondary" onclick="goStep(1)">← Back</button>
      <button class="btn btn-primary" onclick="goStep(3)">Next: Widgets →</button>
    </div>
  </div>

  <!-- ══ STEP 3: WIDGETS ══════════════════════════════════════════ -->
  <div class="panel" id="panel-3">
    <h2>🧩 Floating Widgets</h2>
    <p class="sub">Choose which widgets to add. Selected ones will be placed in a clean, non-overlapping layout automatically — you can drag them anywhere after setup.</p>
    <div class="wgt-opts">

      <!-- RSS Feed -->
      <div class="wgt-opt active" id="wopt-rss">
        <div class="wgt-opt-head">
          <div class="wgt-opt-icon">📰</div>
          <div class="wgt-opt-info">
            <strong>RSS Feed</strong>
            <span>A live news/blog ticker from any RSS or Atom URL. Draggable and resizable floating panel.</span>
          </div>
          <div class="wgt-toggle">
            <button class="wgt-btn" id="wb-rss-yes" onclick="toggleWidget('rss',true)">Yes</button>
            <button class="wgt-btn yes-on" id="wb-rss-no" onclick="toggleWidget('rss',false)">No</button>
          </div>
        </div>
        <div class="wgt-detail" id="wd-rss">
          <label>Feed URL <span style="font-weight:400;text-transform:none;letter-spacing:0;">(optional — you can add it later)</span></label>
          <input type="url" id="wi-rss-url" placeholder="https://feeds.bbci.co.uk/news/rss.xml" oninput="widgetChoices.rss_url=this.value">
        </div>
      </div>

      <!-- Google Calendar -->
      <div class="wgt-opt" id="wopt-cal">
        <div class="wgt-opt-head">
          <div class="wgt-opt-icon">📅</div>
          <div class="wgt-opt-info">
            <strong>Google Calendar</strong>
            <span>Embeds your Google Calendar as a floating widget. Needs your calendar's public embed ID.</span>
          </div>
          <div class="wgt-toggle">
            <button class="wgt-btn" id="wb-cal-yes" onclick="toggleWidget('cal',true)">Yes</button>
            <button class="wgt-btn no-on" id="wb-cal-no" onclick="toggleWidget('cal',false)">No</button>
          </div>
        </div>
        <div class="wgt-detail" id="wd-cal" style="display:none;">
          <label>Calendar ID(s)</label>
          <input type="text" id="wi-cal-ids" placeholder="yourname@gmail.com" oninput="widgetChoices.cal_ids=this.value">
          <p class="hint" style="margin-top:5px;">Google Calendar → Settings → your calendar → "Calendar ID". Multiple IDs: comma-separated.</p>
        </div>
      </div>

      <!-- Camera / Stream -->
      <div class="wgt-opt" id="wopt-cam">
        <div class="wgt-opt-head">
          <div class="wgt-opt-icon">📷</div>
          <div class="wgt-opt-info">
            <strong>Camera / Stream</strong>
            <span>Embed an IP camera, MJPEG stream, or any iframe-compatible URL as a floating widget.</span>
          </div>
          <div class="wgt-toggle">
            <button class="wgt-btn" id="wb-cam-yes" onclick="toggleWidget('cam',true)">Yes</button>
            <button class="wgt-btn no-on" id="wb-cam-no" onclick="toggleWidget('cam',false)">No</button>
          </div>
        </div>
        <div class="wgt-detail" id="wd-cam" style="display:none;">
          <label>Stream URL</label>
          <input type="url" id="wi-cam-url" placeholder="http://192.168.1.x:8080/stream" oninput="widgetChoices.cam_url=this.value">
          <p class="hint" style="margin-top:5px;">MJPEG, HLS, or any URL your browser can embed. More cameras can be added later from Options.</p>
        </div>
      </div>

      <!-- Countdown Timer -->
      <div class="wgt-opt" id="wopt-cd">
        <div class="wgt-opt-head">
          <div class="wgt-opt-icon">⏳</div>
          <div class="wgt-opt-info">
            <strong>Countdown Timer</strong>
            <span>A floating panel counting down to any event. Set the name and target date from Options after setup.</span>
          </div>
          <div class="wgt-toggle">
            <button class="wgt-btn" id="wb-cd-yes" onclick="toggleWidget('cd',true)">Yes</button>
            <button class="wgt-btn no-on" id="wb-cd-no" onclick="toggleWidget('cd',false)">No</button>
          </div>
        </div>
      </div>

      <!-- Sticky Notes -->
      <div class="wgt-opt active" id="wopt-sticky">
        <div class="wgt-opt-head">
          <div class="wgt-opt-icon">📌</div>
          <div class="wgt-opt-info">
            <strong>Sticky Notes</strong>
            <span>Draggable, colour-coded notes that auto-save as you type. Also accessible any time via the ✏️ Edit toolbar.</span>
          </div>
          <div class="wgt-toggle">
            <button class="wgt-btn yes-on" id="wb-sticky-yes" onclick="toggleWidget('sticky',true)">Yes</button>
            <button class="wgt-btn" id="wb-sticky-no" onclick="toggleWidget('sticky',false)">No</button>
          </div>
        </div>
      </div>

    </div>
    <div style="background:rgba(74,158,255,.06);border:1px solid rgba(74,158,255,.18);border-radius:10px;padding:12px 15px;font-size:12px;color:rgba(255,255,255,.55);">
      <strong style="color:rgba(120,190,255,.9);">Layout note:</strong> Widgets are placed to the right of your link columns with no overlaps — safe for 1280 px+ and any browser in full-screen. Drag them anywhere after setup.
    </div>
    <div class="nav">
      <button class="btn btn-secondary" onclick="goStep(2)">← Back</button>
      <button class="btn btn-primary" onclick="goStep(4)">Next: Monitor →</button>
    </div>
  </div>

  <!-- ══ STEP 4: MONITORING ═══════════════════════════════════════ -->
  <div class="panel" id="panel-4">
    <h2>📊 System Monitoring Widgets</h2>
    <p class="sub">Your dashboard has built-in header widgets for live stats. Choose which ones to show — all pull data directly from this machine.</p>
    <div class="mon-checks">
      <div class="mon-check on" id="mc-cpu" onclick="toggleMon('cpu')"><span class="micon">🖥</span><div><div class="mlabel">CPU Usage</div><div class="hint" style="margin:0;">Live processor load %</div></div></div>
      <div class="mon-check on" id="mc-ram" onclick="toggleMon('ram')"><span class="micon">🧠</span><div><div class="mlabel">RAM / Memory</div><div class="hint" style="margin:0;">Used vs total memory</div></div></div>
      <div class="mon-check on" id="mc-storage" onclick="toggleMon('storage')"><span class="micon">💾</span><div><div class="mlabel">Storage</div><div class="hint" style="margin:0;">Disk usage for all drives</div></div></div>
    </div>
    <div style="background:rgba(74,158,255,.08);border:1px solid rgba(74,158,255,.2);border-radius:10px;padding:14px 16px;margin-top:4px;">
      <div style="font-size:13px;font-weight:600;margin-bottom:6px;">ℹ️ About drive monitoring</div>
      <p class="hint" style="margin:0;">The <strong>Storage widget</strong> automatically shows all drives and mounted shares found on this server — no manual configuration needed. You can add or remove individual drives later from <strong>⚙️ Options → Monitoring</strong>.</p>
    </div>

    <div class="nav">
      <button class="btn btn-secondary" onclick="goStep(3)">← Back</button>
      <button class="btn btn-primary" onclick="goStep(5)">Next: Database →</button>
    </div>
  </div>

  <!-- ══ STEP 5: DATABASE ═════════════════════════════════════════ -->
  <div class="panel" id="panel-5">
    <h2>🗄️ Database Connection</h2>
    <p class="sub">Enter your MySQL / MariaDB credentials. The dashboard will create its tables automatically on first run.</p>
    <div class="db-header">
      <div class="db-icon">🐬</div>
      <div>
        <strong>MySQL / MariaDB</strong>
        <span>Tested and ready — credentials saved to <code style="background:rgba(255,255,255,.08);padding:1px 6px;border-radius:4px;font-size:11px;">dash_config.php</code></span>
      </div>
    </div>
    <div class="fg">
      <div><label>Host</label><input type="text" id="db-host" value="127.0.0.1"></div>
      <div><label>Port</label><input type="number" id="db-port" value="3306"></div>
      <div><label>Username</label><input type="text" id="db-user" placeholder="root"></div>
      <div><label>Password</label><input type="password" id="db-pw"></div>
      <div><label>Database Name</label><input type="text" id="db-nm" placeholder="dashboard"></div>
      <div style="display:flex;align-items:flex-end;"><button class="btn btn-secondary btn-sm" onclick="testDb()" style="width:100%">🔌 Test Connection</button></div>
    </div>
    <div id="db-test-res" style="margin-top:6px;font-size:13px;"></div>
    <div class="nav">
      <button class="btn btn-secondary" onclick="goStep(4)">← Back</button>
      <button class="btn btn-primary" onclick="goStep(6)">Next: Theme →</button>
    </div>
  </div>

  <!-- ══ STEP 6: THEME ════════════════════════════════════════════ -->
  <div class="panel" id="panel-6">
    <h2>🎨 Themes</h2>
    <p class="sub">Check the themes you want available, then pick your default. You can change this any time on the dashboard.</p>
    <div style="display:flex;gap:7px;flex-wrap:wrap;margin-bottom:14px;">
      <button class="btn btn-secondary btn-sm" onclick="themeAll(true)">✅ All</button>
      <button class="btn btn-secondary btn-sm" onclick="themeAll(false)">☐ None</button>
      <button class="btn btn-secondary btn-sm" onclick="themeGroup('retro')">🕹 Retro</button>
      <button class="btn btn-secondary btn-sm" onclick="themeGroup('modern')">🍎 Modern</button>
      <button class="btn btn-secondary btn-sm" onclick="themeGroup('seasonal')">🌸 Seasonal</button>
    </div>
    <div class="tgrid" id="tgrid">
      <?php
      $tgroups=['retro'=>['win9x'=>'🖥 WIN 9X Retro','win2k'=>'🖥 Win 2000','winxp'=>'🪟 Win XP',
        'winphone'=>'📱 Win Phone','startmenu'=>'🪟 Start Menu','c64'=>'🕹 C64',
        'os2'=>'🗄 OS/2','solaris'=>'☀️ Solaris'],
        'modern'=>['macos'=>'🍎 macOS','macos9'=>'🌈 Mac OS 9','aqua'=>'💧 Aqua','ios26'=>'✨ iOS 26',
        'ubuntu'=>'🟠 Ubuntu','jellybean'=>'🤖 Jelly Bean','palmos'=>'📟 Palm OS',
        'pocketpc'=>'📲 Pocket PC','webos'=>'🌙 Palm webOS','professional'=>'👔 Professional','girly'=>'🌸 Girly'],
        'seasonal'=>['spring'=>'🌷 Spring','summer'=>'🏖 Summer','autumn'=>'🍂 Autumn','winter'=>'❄️ Winter',
        'halloween'=>'🎃 Halloween','valentine'=>'💝 Valentine','newyear'=>'🎉 New Year','easter'=>'🐣 Easter',
        'thanksgiving'=>'🦃 Thanksgiving','july4'=>'🎆 July 4th','christmas'=>'✝️ Christmas'],
        'other'=>['custom'=>'🎨 Custom']];
      foreach($tgroups as $grp=>$themes):
        foreach($themes as $k=>$label):
          [$ico,$nm]=explode(' ',$label,2); ?>
      <div class="tc on" data-k="<?=$k?>" data-g="<?=$grp?>" onclick="toggleTheme(this,'<?=$k?>')">
        <span class="ti"><?=$ico?></span><span class="tn"><?=htmlspecialchars($nm)?></span>
        <div style="font-size:10px;margin-top:2px;">☑️</div>
      </div>
      <?php endforeach;endforeach;?>
    </div>
    <div style="display:flex;align-items:center;gap:12px;flex-wrap:wrap;margin-top:14px;">
      <label style="text-transform:none;font-size:13px;letter-spacing:0;color:#fff;">Default theme:</label>
      <div id="theme-dd" style="position:relative;min-width:200px;">
        <div id="theme-dd-btn" onclick="toggleThemeDd()" style="display:flex;align-items:center;justify-content:space-between;background:#0d1829;border:1px solid rgba(255,255,255,.18);border-radius:8px;padding:8px 12px;cursor:pointer;font-size:13px;color:#fff;user-select:none;">
          <span id="theme-dd-label">💾 Win 98</span>
          <span style="font-size:10px;opacity:.6;margin-left:10px;">▼</span>
        </div>
        <div id="theme-dd-list" style="display:none;position:absolute;bottom:calc(100% + 4px);left:0;right:0;background:#0d1829;border:1px solid rgba(255,255,255,.18);border-radius:8px;overflow-y:auto;max-height:220px;z-index:999;box-shadow:0 -8px 24px rgba(0,0,0,.6);"></div>
      </div>
      <select id="theme-sel" style="display:none;"></select>
    </div>
    <div class="nav">
      <button class="btn btn-secondary" onclick="goStep(5)">← Back</button>
      <button class="btn btn-primary" onclick="completeSetup(this)">🚀 Finish Setup</button>
    </div>
  </div>

  <!-- ══ STEP 7: DONE ═════════════════════════════════════════════ -->
  <div class="panel" id="panel-7">
    <h2 style="text-align:center;font-size:22px;">🎉 Setup Complete!</h2>
    <p class="sub" style="text-align:center;margin-bottom:20px;">Your dashboard is ready. Redirecting in a moment…</p>
    <div id="summary" style="text-align:center;margin:16px 0;"></div>
    <div style="text-align:center;margin-top:20px;">
      <a href="index.php" class="btn btn-primary" style="text-decoration:none;font-size:15px;">Go to Dashboard →</a>
    </div>
  </div>
  </div><!-- /wizard-body -->
</div><!-- /wrap -->

<script>
/* ══ STATE ══════════════════════════════════════════════════════ */
let userMode       = 'personal';   // 'admin' | 'personal'
let selectedLinks  = [];           // [{id,title,icon,cards:[{label,url,icon}]}]
let scannedSites   = [];           // from server scan
let detectedDrives = [];           // from get_drives
let selectedDrives = new Set();    // paths of checked drives
let monCpu=true, monRam=true, monStorage=true;
let enabledThemes  = new Set();
let selTheme       = 'win9x';
let selDb          = 'none';
let _iconTarget    = null;
let widgetChoices  = {rss_url:'', cal_ids:'', cam_url:'', countdown:false, sticky:true};         // {type:'col'|'link', colId, linkIdx}

/* ══ INSTALL TYPE CHOICE (step 0) ══════════════════════════════ */
let _installType = null;

function chooseInstall(type) {
  _installType = type;
  // Hide choice screen, reveal wizard
  document.getElementById('panel-0').style.display = 'none';
  document.getElementById('wizard-body').style.display = 'block';

  // If upgrade: show upgrade banner and fast-finish button in step 1
  const upgradeBanner = document.getElementById('upgrade-banner');
  const upgradeBtn    = document.getElementById('upgrade-finish-btn');
  const freshBtn      = document.getElementById('fresh-next-btn');
  if (type === 'upgrade') {
    if (upgradeBanner) upgradeBanner.style.display = 'block';
    if (upgradeBtn)    upgradeBtn.style.display    = 'inline-flex';
    if (freshBtn)      freshBtn.style.display      = 'none';
  } else {
    if (upgradeBanner) upgradeBanner.style.display = 'none';
    if (upgradeBtn)    upgradeBtn.style.display    = 'none';
    if (freshBtn)      freshBtn.style.display      = 'inline-flex';
  }
  goStep(1);
}

/* ══ NAVIGATION ═════════════════════════════════════════════════ */
function goStep(n) {
  document.querySelectorAll('.panel').forEach(p=>p.classList.remove('active'));
  document.querySelectorAll('.step').forEach(s=>{s.classList.remove('active');});
  document.getElementById('panel-'+n).classList.add('active');
  document.getElementById('s'+n).classList.add('active');
  for(let i=1;i<n;i++) document.getElementById('s'+i).classList.add('done');
  window.scrollTo({top:0,behavior:'smooth'});
  if (n===6 && enabledThemes.size===0) initThemes();
}

/* ══ STEP 2 – COLUMN BUILDER ═══════════════════════════════════ */

/* ══ STEP 2 – PERSONAL / COLUMN BUILDER ═══════════════════════ */
<?php
// Build PREBUILT JS object from the same presets.php used everywhere else.
$_sp_prebuilt = [];
foreach (dashGetPresets() as $_sp_k => $_sp_v) {
    $_sp_prebuilt[$_sp_k] = ['title'=>$_sp_k,'icon'=>$_sp_v['icon'],'cards'=>$_sp_v['items']];
}
?>
const PREBUILT = <?= json_encode($_sp_prebuilt, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES) ?>;

const ICON_SUGGEST = {
  'portainer':'🐳','nextcloud':'☁️','wordpress':'📝','gitea':'🦊','gitlab':'🦊',
  'jellyfin':'🎵','plex':'🎬','emby':'🎵','homeassistant':'🏠','home assistant':'🏠',
  'grafana':'📊','prometheus':'🔥','pihole':'⬛','adguard':'🛡','bitwarden':'🔐',
  'qbittorrent':'⬇️','deluge':'⬇️','sonarr':'📺','radarr':'🎬','lidarr':'🎵',
  'readarr':'📚','navidrome':'🎵','photoprism':'📸','immich':'📸','paperless':'📄',
  'netdata':'📡','uptime':'📶','vscode':'💻','traefik':'🔀','wireguard':'🔒',
  'speedtest':'⚡','calibre':'📚','filebrowser':'📁','minio':'🪣','searx':'🔍',
  'roundcube':'📧','webmail':'📧','unifi':'📡','nginx':'🟢','apache':'🔴',
  'mysql':'🐬','mariadb':'🐬','postgresql':'🐘','redis':'⚡','mongodb':'🍃',
  'github':'🐙','google':'🌐','youtube':'▶️','gmail':'📧','dropbox':'📦',
  'slack':'💬','zoom':'📹','teams':'💼','netflix':'🎬','spotify':'🎵',
  'amazon':'📦','twitter':'🐦','instagram':'📸','facebook':'👥','linkedin':'💼',
  'reddit':'🤖','twitch':'🎮','discord':'💬','whatsapp':'💬','telegram':'✈️',
  'tiktok':'🎵','pinterest':'📌','snapchat':'👻','chatgpt':'🤖','openai':'🤖',
  'gemini':'✨','claude':'🧠','grok':'⚡','copilot':'🪟','perplexity':'🔮',
  'deepseek':'🐋','bing':'🔵','duckduckgo':'🦆','brave':'🦁','ecosia':'🌱',
};
function guessIcon(text) {
  const s=text.toLowerCase();
  for (const [k,v] of Object.entries(ICON_SUGGEST)) if (s.includes(k)) return v;
  return '🔗';
}

function addPrebuilt(key) {
  const btn = document.getElementById('pb-btn-' + key);
  const alreadyAdded = !!selectedLinks.find(c => c.id === 'pb-' + key);
  if (alreadyAdded) {
    // Toggle OFF — remove column
    selectedLinks = selectedLinks.filter(c => c.id !== 'pb-' + key);
    renderCols();
    if (btn) {
      btn.style.background = '';
      btn.style.border = '';
      btn.style.color = '';
      const chk = btn.querySelector('.pb-check');
      if (chk) chk.style.display = 'none';
    }
    return;
  }
  if (!PREBUILT[key]) return;
  selectedLinks.push({id:'pb-'+key,...JSON.parse(JSON.stringify(PREBUILT[key]))});
  renderCols();
  if (btn) {
    btn.style.background = 'rgba(80,200,120,.25)';
    btn.style.border = '1px solid rgba(80,200,120,.55)';
    btn.style.color = '#a0ffb8';
    const chk = btn.querySelector('.pb-check');
    if (chk) chk.style.display = 'inline';
  }
}
function importBookmarks(input) {
  const file = input.files[0]; if (!file) return;
  const reader = new FileReader();
  reader.onload = function(e) {
    const parser = new DOMParser();
    const doc = parser.parseFromString(e.target.result, 'text/html');
    const folders = doc.querySelectorAll('DT > H3');
    let added = 0;
    folders.forEach(h3 => {
      const folderName = h3.textContent.trim();
      if (!folderName) return;
      const dl = h3.nextElementSibling;
      if (!dl || dl.tagName !== 'DL') return;
      const links = [];
      dl.querySelectorAll('A').forEach(a => {
        const url = a.href; const label = a.textContent.trim();
        if (url && label && url.startsWith('http')) {
          links.push({label, url, icon: guessIcon(label)});
        }
      });
      if (!links.length) return;
      const id = 'bm-'+Date.now()+'-'+(++added);
      selectedLinks.push({id, title:folderName, icon:'📁', cards:links});
    });
    // If no folders found, import all links as one column
    if (!added) {
      const links = [];
      doc.querySelectorAll('A').forEach(a => {
        const url = a.href; const label = a.textContent.trim();
        if (url && label && url.startsWith('http')) links.push({label, url, icon:guessIcon(label)});
      });
      if (links.length) {
        selectedLinks.push({id:'bm-all-'+Date.now(), title:'Imported Bookmarks', icon:'📥', cards:links});
        added++;
      }
    }
    renderCols();
    if (added) alert('Imported ' + added + ' bookmark folder'+(added!==1?'s':'')+'. Review and remove any you don\'t need!');
    else alert('No bookmark folders found. Make sure you exported in HTML format from your browser.');
    input.value = '';
  };
  reader.readAsText(file);
}
function showNewColForm() {
  document.getElementById('new-col-form').style.display='block';
  document.getElementById('ncol-name').focus();
}
function commitNewCol() {
  const name=document.getElementById('ncol-name').value.trim();
  if (!name) { alert('Enter a column name.'); return; }
  const icon=document.getElementById('ncol-icon').value||'📁';
  selectedLinks.push({id:'col-'+Date.now(),title:name,icon,cards:[]});
  renderCols();
  document.getElementById('new-col-form').style.display='none';
  document.getElementById('ncol-name').value='';
  document.getElementById('ncol-icon').value='📁';
  document.getElementById('ncol-icon-btn').textContent='📁';
}
function removeCol(id) { selectedLinks=selectedLinks.filter(c=>c.id!==id); renderCols(); }
function renderCols() {
  const list=document.getElementById('col-list');
  const empty=document.getElementById('col-empty');
  if (!selectedLinks.length) { list.innerHTML=''; empty.style.display='block'; return; }
  empty.style.display='none';
  list.innerHTML=selectedLinks.map(col=>`
    <div class="col-card" id="cc-${col.id}">
      <div class="col-head">
        <button class="btn btn-xs btn-secondary cico" title="Change icon" onclick="openIconPicker('__col__${col.id}')" style="font-size:18px;min-width:40px;">${renderIcon(col.icon)}</button>
        <div class="ctitle"><input type="text" value="${esc(col.title)}" onchange="updateColTitle('${col.id}',this.value)" label placeholder="Column Name"></div>
        <button class="btn btn-xs btn-danger" onclick="removeCol('${col.id}')">✕</button>
      </div>
      <div class="col-body">
        ${col.cards.map((c,i)=>`
          <div class="link-row">
            <span class="link-ico" onclick="openIconPicker('__link__${col.id}__${i}')" title="Change icon">${renderIcon(c.icon)}</span>
            <div style="flex:1;min-width:0;">
              <div class="link-label">${esc(c.label)}</div>
              <div class="link-url">${esc(c.url)}</div>
            </div>
            <button class="btn btn-xs btn-danger" onclick="removeLink('${col.id}',${i})">✕</button>
          </div>`).join('')}
        <div style="padding:4px 0 2px;">
          <button class="btn btn-xs btn-secondary" onclick="showLinkForm('${col.id}')">+ Add Link</button>
        </div>
        <div id="lf-${col.id}" class="ifrm" style="display:none;margin-top:6px;">
          <h4>Add Link</h4>
          <div class="r3">
            <div><label>Label</label><input type="text" id="ll-lbl-${col.id}" placeholder="YouTube"></div>
            <div><label>URL</label><input type="url" id="ll-url-${col.id}" placeholder="https://…" oninput="autoIcon('${col.id}')"></div>
            <div><label>Icon</label>
              <button class="btn btn-secondary btn-sm" id="ll-ico-btn-${col.id}" onclick="openIconPicker('__linkform__${col.id}')" style="font-size:16px;min-width:40px;">🔗</button>
              <input type="hidden" id="ll-ico-${col.id}" value="🔗">
            </div>
          </div>
          <div style="margin-top:8px;display:flex;gap:7px;">
            <button class="btn btn-primary btn-xs" onclick="addLink('${col.id}')">+ Add</button>
            <button class="btn btn-xs btn-danger" onclick="document.getElementById('lf-${col.id}').style.display='none'">Cancel</button>
          </div>
        </div>
      </div>
    </div>`).join('');
}
function renderIcon(ico) {
  if (!ico) return '🔗';
  if (ico.startsWith('icons/') || ico.startsWith('http')) return `<img src="${esc(ico)}" style="width:20px;height:20px;object-fit:contain;border-radius:3px;">`;
  return ico;
}
function updateColTitle(id,v) { const c=selectedLinks.find(x=>x.id===id); if(c) c.title=v; }
function showLinkForm(id) { document.getElementById('lf-'+id).style.display='block'; document.getElementById('ll-lbl-'+id).focus(); }
function autoIcon(colId) {
  const url=(document.getElementById('ll-url-'+colId)||{}).value||'';
  const ic=guessIcon(url);
  const btn=document.getElementById('ll-ico-btn-'+colId);
  const inp=document.getElementById('ll-ico-'+colId);
  if(btn&&inp){btn.textContent=ic;inp.value=ic;}
}
function addLink(colId) {
  const lbl=(document.getElementById('ll-lbl-'+colId)||{}).value?.trim();
  const url=(document.getElementById('ll-url-'+colId)||{}).value?.trim();
  const ico=(document.getElementById('ll-ico-'+colId)||{}).value?.trim()||'🔗';
  if(!url){alert('URL is required.');return;}
  const col=selectedLinks.find(c=>c.id===colId);
  if(col){col.cards.push({label:lbl||url,url,icon:ico});renderCols();}
}
function removeLink(colId,i) { const col=selectedLinks.find(c=>c.id===colId); if(col){col.cards.splice(i,1);renderCols();} }

/* ══ ICON PICKER ═══════════════════════════════════════════════ */
function openIconPicker(target) {
  _iconTarget=target;
  document.getElementById('icon-modal').style.display='flex';
}
function closeIconPicker() { document.getElementById('icon-modal').style.display='none'; }
function pickIcon(ico) {
  applyIcon(ico);
  closeIconPicker();
}
function applyIcon(ico) {
  if (!_iconTarget) return;
  const t=_iconTarget;
  if (t==='ncol-icon') {
    document.getElementById('ncol-icon').value=ico;
    document.getElementById('ncol-icon-btn').innerHTML=renderIcon(ico);
    return;
  }
  if (t.startsWith('__col__')) {
    const colId=t.replace('__col__','');
    const col=selectedLinks.find(c=>c.id===colId);
    if(col){col.icon=ico;renderCols();}
    return;
  }
  if (t.startsWith('__linkform__')) {
    const colId=t.replace('__linkform__','');
    const btn=document.getElementById('ll-ico-btn-'+colId);
    const inp=document.getElementById('ll-ico-'+colId);
    if(btn)btn.innerHTML=renderIcon(ico);
    if(inp)inp.value=ico;
    return;
  }
  if (t.startsWith('__link__')) {
    const parts=t.replace('__link__','').split('__');
    const colId=parts[0],idx=parseInt(parts[1]);
    const col=selectedLinks.find(c=>c.id===colId);
    if(col&&col.cards[idx]){col.cards[idx].icon=ico;renderCols();}
    return;
  }
}
async function uploadIcon(inp) {
  const file=inp.files[0]; if(!file) return;
  if(file.size>512*1024){document.getElementById('icon-upload-status').innerHTML='<span style="color:#f66">File too large (max 500 KB)</span>';return;}
  const fd=new FormData(); fd.append('icon',file);
  document.getElementById('icon-upload-status').innerHTML='<span class="spinner"></span> Uploading…';
  try {
    const r=await fetch('setup.php?action=upload_icon',{method:'POST',body:fd});
    const d=await r.json();
    if(d.ok){
      document.getElementById('icon-upload-status').innerHTML='<span style="color:#5ef">✅ Uploaded!</span>';
      pickIcon(d.url);
    } else {
      document.getElementById('icon-upload-status').innerHTML=`<span style="color:#f66">❌ ${d.error}</span>`;
    }
  } catch(e){document.getElementById('icon-upload-status').innerHTML='<span style="color:#f66">Upload failed</span>';}
  inp.value='';
}

/* ══ STEP 3 – MONITORING ═══════════════════════════════════════ */
function toggleMon(k) {
  if(k==='cpu') monCpu=!monCpu;
  else if(k==='ram') monRam=!monRam;
  else if(k==='storage') monStorage=!monStorage;
  document.getElementById('mc-'+k).classList.toggle('on',k==='cpu'?monCpu:k==='ram'?monRam:monStorage);
}
async function loadDrives() {
  const btn=document.getElementById('refresh-drives-btn');
  btn.disabled=true; btn.innerHTML='<span class="spinner"></span>';
  try {
    const r=await fetch('setup.php?action=get_drives'); const d=await r.json();
    detectedDrives=d.drives||[];
    selectedDrives=new Set(detectedDrives.map(x=>x.path));
    renderDrives();
  } catch(e){ document.getElementById('drive-empty').style.display='block'; document.getElementById('drive-empty').textContent='Failed to detect drives: '+e.message; }
  btn.disabled=false; btn.innerHTML='🔄 Scan Drives';
}
function renderDrives() {
  const list=document.getElementById('drive-list');
  const empty=document.getElementById('drive-empty');
  if(!detectedDrives.length){list.innerHTML='';empty.style.display='block';return;}
  empty.style.display='none';
  list.innerHTML=detectedDrives.map(d=>{
    const on=selectedDrives.has(d.path);
    const pct=d.used_pct||0;
    const col=pct>=90?'#ff4444':pct>=70?'#ffaa00':'#4a9eff';
    const net=d.network?' 🌐':'';
    return `<div class="drive-row ${on?'on':''}" onclick="toggleDrive('${esc(d.path)}')">
      <div class="drv-icon">${d.icon}</div>
      <div class="drv-info">
        <div class="drv-label">${esc(d.label)}${net}</div>
        <div class="drv-path">${esc(d.path)}</div>
        <div class="drv-stats">${d.size} total · ${d.avail} free · ${pct}% used</div>
        <div class="drv-bar"><div class="drv-fill" style="width:${pct}%;background:${col};"></div></div>
      </div>
      <div class="cb-box" style="${on?'background:#4a9eff;border-color:#4a9eff;':''}">${on?'✓':''}</div>
    </div>`;
  }).join('');
}
function toggleDrive(path) {
  if(selectedDrives.has(path)) selectedDrives.delete(path);
  else selectedDrives.add(path);
  renderDrives();
}
function driveCheckAll(on) {
  selectedDrives=on?new Set(detectedDrives.map(d=>d.path)):new Set();
  renderDrives();
}

/* ══ STEP 3 – WIDGET CHOICES ═══════════════════════════════════ */
function toggleWidget(key, on) {
  const yBtn = document.getElementById('wb-'+key+'-yes');
  const nBtn = document.getElementById('wb-'+key+'-no');
  const det  = document.getElementById('wd-'+key);
  const opt  = document.getElementById('wopt-'+key);
  yBtn.className = 'wgt-btn' + (on ? ' yes-on' : '');
  nBtn.className = 'wgt-btn' + (!on ? ' no-on' : '');
  if (opt) opt.classList.toggle('active', on);
  if (det) det.style.display = on ? 'block' : 'none';
  if (key === 'cd')     widgetChoices.countdown = on;
  if (key === 'sticky') widgetChoices.sticky    = on;
  if (key === 'rss'  && !on) widgetChoices.rss_url = '';
  if (key === 'cal'  && !on) widgetChoices.cal_ids = '';
  if (key === 'cam'  && !on) widgetChoices.cam_url = '';
}

function buildWidgetLayout(choices, gridCols) {
  const startX = Math.max(880, gridCols * 295 + 20);
  const col2X  = startX + 370;
  const GAP    = 18;
  let c0 = 80, c1 = 80;
  const out = {rss:[], camera:[], calendar:[], countdown:[]};
  const ts  = Date.now();

  function place(arr, h, obj) {
    if (c0 <= c1) { arr.push({...obj, x:startX, y:c0}); c0 += h + GAP; }
    else          { arr.push({...obj, x:col2X,  y:c1}); c1 += h + GAP; }
  }

  if (choices.rss_url)   place(out.rss,      280, {id:'rw-'+ts,  name:'RSS Feed',  url:choices.rss_url, max:8});
  if (choices.cal_ids)   place(out.calendar,  400, {id:'cal-'+ts, name:'Calendar',  cal_ids:choices.cal_ids, tz:'UTC'});
  if (choices.cam_url)   place(out.camera,    240, {id:'cam-'+ts, name:'Camera',    url:choices.cam_url, type:'iframe', record_url:''});
  if (choices.countdown) place(out.countdown, 130, {id:'cd-'+ts,  name:'Countdown', target_date: new Date(Date.now()+365*24*3600*1000).toISOString().slice(0,10)+'T00:00'});
  return out;
}

/* ══ STEP 5 – DB ═══════════════════════════════════════════════ */
async function testDb() {
  const r=await fetch('setup.php?action=test_db&host='+encodeURIComponent(document.getElementById('db-host').value)+'&port='+document.getElementById('db-port').value+'&user='+encodeURIComponent(document.getElementById('db-user').value)+'&pass='+encodeURIComponent(document.getElementById('db-pw').value)+'&name='+encodeURIComponent(document.getElementById('db-nm').value));
  const d=await r.json();
  document.getElementById('db-test-res').innerHTML=d.ok?'<span style="color:#5ef">✅ Connected!</span>':'<span style="color:#f66">❌ '+(d.error||'Failed')+'</span>';
}

/* ══ STEP 5 – THEMES ═══════════════════════════════════════════ */
const ALL_THEMES={win9x:'🖥 WIN 9X Retro',win2k:'🖥 Win 2000',winxp:'🪟 Win XP',winphone:'📱 Win Phone',
  startmenu:'🪟 Start Menu',c64:'🕹 C64',os2:'🗄 OS/2',solaris:'☀️ Solaris',
  macos:'🍎 macOS',macos9:'🌈 Mac OS 9',aqua:'💧 Aqua',ios26:'✨ iOS 26',ubuntu:'🟠 Ubuntu',
  jellybean:'🤖 Jelly Bean',palmos:'📟 Palm OS',pocketpc:'📲 Pocket PC',webos:'🌙 Palm webOS',
  professional:'👔 Professional',girly:'🌸 Girly',
  spring:'🌷 Spring',summer:'🏖 Summer',autumn:'🍂 Autumn',winter:'❄️ Winter',
  halloween:'🎃 Halloween',valentine:'💝 Valentine',newyear:'🎉 New Year',easter:'🐣 Easter',
  thanksgiving:'🦃 Thanksgiving',july4:'🎆 July 4th',christmas:'✝️ Christmas',custom:'🎨 Custom'};
function initThemes() {
  document.querySelectorAll('.tc').forEach(c=>{enabledThemes.add(c.dataset.k);});
  updateThemeSel();
}
function toggleTheme(card,k) {
  if(enabledThemes.has(k)){if(enabledThemes.size<=1)return;enabledThemes.delete(k);card.classList.replace('on','off');card.querySelector('div').textContent='☐';}
  else{enabledThemes.add(k);card.classList.replace('off','on');card.querySelector('div').textContent='☑️';}
  updateThemeSel();
}
function themeAll(on) {
  document.querySelectorAll('.tc').forEach(c=>{
    const k=c.dataset.k;
    if(on){enabledThemes.add(k);c.classList.replace('off','on');c.querySelector('div').textContent='☑️';}
    else{if(k==='win9x')return;enabledThemes.delete(k);c.classList.replace('on','off');c.querySelector('div').textContent='☐';}
  });
  updateThemeSel();
}
function themeGroup(g) {
  document.querySelectorAll('.tc').forEach(c=>{
    const k=c.dataset.k,inG=c.dataset.g===g||k==='win9x';
    if(inG){enabledThemes.add(k);c.classList.replace('off','on');c.querySelector('div').textContent='☑️';}
    else{enabledThemes.delete(k);c.classList.replace('on','off');c.querySelector('div').textContent='☐';}
  });
  updateThemeSel();
}
function updateThemeSel() {
  const sel=document.getElementById('theme-sel');
  const list=document.getElementById('theme-dd-list');
  const lbl=document.getElementById('theme-dd-label');
  const prev=selTheme||sel.value;
  // rebuild hidden select (for form compat)
  sel.innerHTML='';
  for(const[k,l]of Object.entries(ALL_THEMES)){
    if(enabledThemes.has(k)){const o=document.createElement('option');o.value=k;o.textContent=l;sel.appendChild(o);}
  }
  if([...sel.options].some(o=>o.value===prev)){sel.value=prev;}
  selTheme=sel.value;
  // rebuild custom dropdown list
  list.innerHTML='';
  for(const[k,l]of Object.entries(ALL_THEMES)){
    if(!enabledThemes.has(k))continue;
    const item=document.createElement('div');
    item.textContent=l;
    item.style.cssText='padding:8px 12px;cursor:pointer;font-size:13px;color:#fff;background:#0d1829;transition:background .1s;';
    if(k===selTheme)item.style.background='rgba(74,158,255,.25)';
    item.onmouseover=()=>item.style.background='rgba(255,255,255,.1)';
    item.onmouseout=()=>item.style.background=k===selTheme?'rgba(74,158,255,.25)':'#0d1829';
    item.onclick=()=>{selTheme=k;sel.value=k;lbl.textContent=l;closThemeDd();updateThemeSel();};
    list.appendChild(item);
  }
  lbl.textContent=ALL_THEMES[selTheme]||selTheme;
}
function toggleThemeDd(){
  const l=document.getElementById('theme-dd-list');
  l.style.display=l.style.display==='none'?'block':'none';
}
function closThemeDd(){document.getElementById('theme-dd-list').style.display='none';}
document.addEventListener('click',e=>{
  const dd=document.getElementById('theme-dd');
  if(dd&&!dd.contains(e.target))closThemeDd();
});

/* ══ UPGRADE: fast-finish from step 1 ═════════════════════════ */
async function doFinish() {
  const pass=document.getElementById('f-pass').value;
  if(pass.length<4){alert('Password must be at least 4 characters.');return;}
  const fd=new FormData();
  fd.append('action','complete');
  fd.append('install_type', _installType||'upgrade');
  fd.append('title',  document.getElementById('f-title').value||'Server Dashboard');
  fd.append('username',document.getElementById('f-user').value||'admin');
  fd.append('password',pass);
  fd.append('grid_cols','3');
  fd.append('theme',  selTheme||'win9x');
  fd.append('db_type', selDb||'none');
  if((selDb||'none')==='mysql'){
    fd.append('db_host',document.getElementById('db-host')?.value||'127.0.0.1');
    fd.append('db_port',document.getElementById('db-port')?.value||'3306');
    fd.append('db_user',document.getElementById('db-user')?.value||'root');
    fd.append('db_pass',document.getElementById('db-pw')?.value||'');
    fd.append('db_name',document.getElementById('db-nm')?.value||'dashboard');
  }
  fd.append('links_json', JSON.stringify(selectedLinks));
  fd.append('drives_json','[]');
  fd.append('hidden_themes_json','[]');
  try {
    const r=await fetch('setup.php',{method:'POST',body:fd});
    const d=await r.json();
    if(d.ok){ location.href='index.php'; }
    else { alert('Save failed: '+(d.error||'unknown')); }
  } catch(e){ alert('Error: '+e.message); }
}

/* ══ COMPLETE ══════════════════════════════════════════════════ */
async function completeSetup(btn) {
  const pass=document.getElementById('f-pass').value;
  if(pass.length<4){alert('Password must be at least 4 characters.');return;}
  btn.disabled=true;btn.textContent='Saving…';
  const links = selectedLinks;
  const drives = detectedDrives.filter(d=>selectedDrives.has(d.path));
  const fd=new FormData();
  fd.append('action','complete');
  fd.append('install_type', _installType||'fresh');
  fd.append('title',  document.getElementById('f-title').value||'Server Dashboard');
  fd.append('username',document.getElementById('f-user').value||'admin');
  fd.append('password',pass);
  fd.append('grid_cols','3');
  fd.append('theme',  selTheme);
  fd.append('db_type',selDb);
  if(selDb==='mysql'){
    fd.append('db_host',document.getElementById('db-host').value);
    fd.append('db_port',document.getElementById('db-port').value);
    fd.append('db_user',document.getElementById('db-user').value);
    fd.append('db_pass',document.getElementById('db-pw').value);
    fd.append('db_name',document.getElementById('db-nm').value);
  }
  const gridCols = 3;
  const widgetLayout = buildWidgetLayout(widgetChoices, gridCols);
  widgetLayout.sticky = widgetChoices.sticky;
  fd.append('widgets_json', JSON.stringify(widgetLayout));
  fd.append('links_json', JSON.stringify(links));
  fd.append('drives_json',JSON.stringify(drives));
  fd.append('mon_cpu',    monCpu?'1':'');
  fd.append('mon_ram',    monRam?'1':'');
  fd.append('mon_storage',monStorage?'1':'');
  const allK=Object.keys(ALL_THEMES);
  fd.append('hidden_themes_json',JSON.stringify(allK.filter(k=>!enabledThemes.has(k))));
  try {
    const r=await fetch('setup.php',{method:'POST',body:fd});
    const d=await r.json();
    if(d.ok){
      localStorage.setItem('hp-theme',selTheme);
      const wCount=[widgetChoices.rss_url,widgetChoices.cal_ids,widgetChoices.cam_url,widgetChoices.countdown,widgetChoices.sticky].filter(Boolean).length;
      document.getElementById('summary').innerHTML=
        `<span class="summary-chip">🔗 ${links.length} column${links.length!==1?'s':''}</span>
         <span class="summary-chip">🧩 ${wCount} widget${wCount!==1?'s':''}</span>
         <span class="summary-chip">🎨 ${selTheme}</span>
         <span class="summary-chip">💾 ${drives.length} drive${drives.length!==1?'s':''}</span>`;
      goStep(7);
      setTimeout(()=>location.href='index.php',3500);
    } else { alert('Save failed: '+(d.error||'unknown')); }
  } catch(e){ alert('Error: '+e.message); }
  btn.disabled=false;btn.textContent='🚀 Finish Setup';
}

/* ══ UTILS ═════════════════════════════════════════════════════ */
function esc(s){return(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');}

</script>
<script>
(function(){
  const cv=document.getElementById('retro-bg');
  if(!cv)return;
  const ctx=cv.getContext('2d');
  const FS=13;
  // Typewriter-style retro chars: mix of terminal commands, block chars, symbols
  const CHARS='ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789!@#$%^&*(){}[]|<>/\\;:.,_-+=~`░▒▓▀▄■□●○→←';
  const PHRASES=[
    'setup --init','config write','mysql -u root','chmod 755 .',
    'php artisan','./install.sh','db:migrate','systemctl start',
    'apt-get install','composer install','npm install','curl -L',
    '> /dev/null','2>&1','&&','||','$HOME','$PATH',
    'SELECT * FROM','CREATE TABLE','INSERT INTO','DROP TABLE IF',
    'bcrypt_hash()','session_start()','header(Location:)','require_once',
  ];
  let cols,drops,bright,phrases,phraseTimer=0;

  function resize(){
    cv.width=window.innerWidth;
    cv.height=window.innerHeight;
    cols=Math.floor(cv.width/FS);
    drops=new Float32Array(cols).map(()=>Math.random()*-80);
    bright=new Uint8Array(cols);
    phrases=[];
  }

  function spawnPhrase(){
    if(phrases.length>8)return;
    const txt=PHRASES[Math.floor(Math.random()*PHRASES.length)];
    const col=Math.floor(Math.random()*(cols-txt.length-2))+1;
    const row=Math.floor(Math.random()*(cv.height/FS-4))+2;
    phrases.push({txt,col,row,pos:0,alpha:1,age:0,speed:0.12+Math.random()*0.08});
  }

  function draw(){
    // Phosphor fade trail
    ctx.fillStyle='rgba(0,0,0,0.06)';
    ctx.fillRect(0,0,cv.width,cv.height);
    ctx.font=FS+'px "Courier New",Courier,monospace';

    // Matrix rain columns
    for(let i=0;i<drops.length;i++){
      const y=drops[i];
      if(y>0){
        // Head character — bright white-green
        const headY=Math.floor(y)*FS;
        if(headY>0&&headY<cv.height){
          ctx.fillStyle='#ccffcc';
          ctx.fillText(CHARS[Math.floor(Math.random()*CHARS.length)],i*FS,headY);
        }
        // Body glyphs — mid green
        for(let j=1;j<4;j++){
          const by=(Math.floor(y)-j)*FS;
          if(by>0&&Math.random()>0.7){
            const g=Math.max(80,200-j*40);
            ctx.fillStyle=`rgb(0,${g},20)`;
            ctx.fillText(CHARS[Math.floor(Math.random()*CHARS.length)],i*FS,by);
          }
        }
      }
      drops[i]+=0.4+Math.random()*0.3;
      if(drops[i]*FS>cv.height&&Math.random()>0.965) drops[i]=Math.random()*-30;
    }

    // Typewriter phrases
    phraseTimer++;
    if(phraseTimer>90){phraseTimer=0;spawnPhrase();}
    phrases=phrases.filter(p=>{
      p.age++;
      p.pos=Math.min(p.txt.length,p.pos+p.speed);
      if(p.age>340)p.alpha-=0.012;
      if(p.alpha<=0)return false;
      const visible=p.txt.slice(0,Math.floor(p.pos));
      ctx.globalAlpha=p.alpha;
      ctx.fillStyle='#00ff41';
      ctx.fillText(visible,p.col*FS,p.row*FS);
      // blinking cursor
      if(Math.floor(p.pos)<p.txt.length&&Math.floor(Date.now()/400)%2===0){
        ctx.fillStyle='#00ff41';
        ctx.fillRect(p.col*FS+visible.length*FS*0.6,p.row*FS-FS+2,2,FS-2);
      }
      ctx.globalAlpha=1;
      return true;
    });

    requestAnimationFrame(draw);
  }

  window.addEventListener('resize',resize,{passive:true});
  resize();
  draw();
})();
</script>
</body>
</html>

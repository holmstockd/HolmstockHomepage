<?php
session_start();

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

/* ─── Utility helpers ─────────────────────────────────────────────────── */
function probeUrl($url, $to=5) {
    $ctx = stream_context_create([
        'http'  =>['timeout'=>$to,'ignore_errors'=>true,'follow_location'=>true,'max_redirects'=>3,
                   'header'=>"User-Agent: Mozilla/5.0\r\n"],
        'ssl'   =>['verify_peer'=>false,'verify_peer_name'=>false],
        'https' =>['timeout'=>$to,'ignore_errors'=>true,'verify_peer'=>false,'verify_peer_name'=>false],
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
    $cols   = max(1,min(6,(int)($_POST['grid_cols']??3)));
    $theme  = preg_replace('/[^a-z0-9_]/','',trim($_POST['theme']??'win98'));
    $dbType = in_array($_POST['db_type']??'sqlite',['sqlite','mysql'])
              ? ($_POST['db_type']??'sqlite') : 'sqlite';
    if (strlen($pass)<4) $pass='admin';
    $hash = password_hash($pass, PASSWORD_BCRYPT);

    $dbConf='';
    if ($dbType==='mysql') {
        $h=addslashes($_POST['db_host']??'127.0.0.1');$p=(int)($_POST['db_port']??3306);
        $u=addslashes($_POST['db_user']??'root');$pw=addslashes($_POST['db_pass']??'');
        $n=addslashes($_POST['db_name']??'dashboard');
        $dbConf="define('DASH_DB_TYPE','mysql');\ndefine('DASH_DB_HOST','$h');\ndefine('DASH_DB_PORT',$p);\ndefine('DASH_DB_USER','$u');\ndefine('DASH_DB_PASS','$pw');\ndefine('DASH_DB_NAME','$n');\n";
    } else {
        $dbConf="define('DASH_DB_TYPE','sqlite');\n";
    }
    file_put_contents(__DIR__.'/dash_config.php',
        "<?php\ndefine('DASH_SETUP_DONE',true);\ndefine('DASH_USERNAME','".addslashes($uname)."');\ndefine('DASH_PASSWORD_HASH','".addslashes($hash)."');\ndefine('DASH_TITLE','".addslashes($title)."');\ndefine('DASH_GRID_COLS',$cols);\n$dbConf");

    // ── Preserve existing data files on upgrade; only write if new install ──
    $isUpgradeSave = detectUpgrade();

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
body{font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',sans-serif;background:#080c18;color:#fff;min-height:100vh;}
canvas#bg{position:fixed;inset:0;z-index:0;}
.wrap{position:relative;z-index:1;max-width:920px;margin:0 auto;padding:28px 18px 70px;}
.wiz-hdr{text-align:center;margin-bottom:28px;}
.wiz-logo{font-size:46px;margin-bottom:10px;}
.wiz-title{font-size:26px;font-weight:800;}
.wiz-sub{color:rgba(255,255,255,.45);font-size:13px;margin-top:5px;}
/* Steps */
.steps{display:flex;margin-bottom:28px;background:rgba(255,255,255,.04);border-radius:12px;padding:4px;overflow-x:auto;gap:2px;}
.step{flex:1;text-align:center;padding:7px 4px;border-radius:8px;font-size:10px;font-weight:700;color:rgba(255,255,255,.35);white-space:nowrap;transition:all .2s;}
.step.active{background:rgba(74,158,255,.2);color:#4a9eff;}
.step.done{color:rgba(80,220,80,.8);}
.step .num{display:block;font-size:14px;margin-bottom:1px;}
/* Panel */
.panel{display:none;background:rgba(255,255,255,.04);border:1px solid rgba(255,255,255,.08);border-radius:16px;padding:26px;}
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
/* DB cards */
.db-grid{display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:18px;}
.db-card{padding:16px;border:2px solid rgba(255,255,255,.1);border-radius:12px;text-align:center;cursor:pointer;transition:all .2s;background:rgba(255,255,255,.04);}
.db-card:hover{border-color:rgba(74,158,255,.4);}
.db-card.selected{border-color:#4a9eff;background:rgba(74,158,255,.1);}
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
</style>
</head>
<body>
<canvas id="bg"></canvas>

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

  <div class="steps">
    <div class="step active" id="s1"><span class="num">①</span>Account</div>
    <div class="step" id="s2"><span class="num">②</span>Links</div>
    <div class="step" id="s3"><span class="num">③</span>Monitor</div>
    <div class="step" id="s4"><span class="num">④</span>Database</div>
    <div class="step" id="s5"><span class="num">⑤</span>Theme</div>
    <div class="step" id="s6"><span class="num">⑥</span>Done!</div>
  </div>

  <!-- ══ STEP 1: ACCOUNT ══════════════════════════════════════════ -->
  <div class="panel active" id="panel-1">
    <h2>👤 Account Setup</h2>
    <?php if ($isUpgrade): ?>
    <div style="background:rgba(74,158,255,.15);border:1px solid rgba(74,158,255,.4);border-radius:8px;padding:12px 16px;margin-bottom:18px;font-size:13px;line-height:1.6;">
      <strong style="color:#4a9eff;">🔄 Upgrade detected</strong><br>
      Your links, widgets, drives, themes, and users are all still intact — only the <code>dash_config.php</code> file was reset by the upgrade.<br>
      <strong>Just set a new password below and click Finish</strong> — all your data will be kept exactly as-is.
    </div>
    <?php else: ?>
    <p class="sub">Set your dashboard title and create the admin account. You can add more users later via ⚙️ Options.</p>
    <?php endif; ?>
    <div class="fg">
      <div><label>Dashboard Title</label><input type="text" id="f-title" value="Server Dashboard" placeholder="My Home Server"></div>
      <div><label>Default Columns</label>
        <select id="f-cols"><option value="2">2</option><option value="3" selected>3</option><option value="4">4</option><option value="5">5</option></select>
      </div>
      <div><label>Admin Username</label><input type="text" id="f-user" value="admin"></div>
      <div><label>Admin Password <span style="color:#f66;">*</span></label><input type="password" id="f-pass" placeholder="min 4 characters" autocomplete="new-password"></div>
    </div>
    <p class="hint">ⓘ You will stay logged in for <strong>6 months</strong> on this device. Additional users (Editor / Read-only) can be added from Options after setup.</p>
    <?php if ($isUpgrade): ?>
    <div class="nav"><span></span><button class="btn btn-primary" onclick="doFinish()">✅ Finish Upgrade →</button></div>
    <?php else: ?>
    <div class="nav"><span></span><button class="btn btn-primary" onclick="goStep(2)">Next: Links →</button></div>
    <?php endif; ?>
  </div>

  <!-- ══ STEP 2: LINKS ════════════════════════════════════════════ -->
  <div class="panel" id="panel-2">
    <h2>🔗 Dashboard Links</h2>
    <p class="sub">Build your link columns — create named groups and add URLs manually. You can always add, edit, or rearrange links later directly on the dashboard.</p>

    <!-- Column builder -->
    <div id="personal-path">
      <div style="margin-bottom:8px;display:flex;gap:8px;flex-wrap:wrap;">
        <button class="btn btn-primary btn-sm" onclick="showNewColForm()">📁 New Column</button>
        <button class="btn btn-secondary btn-sm" onclick="addPrebuilt('search')">🔍 + Search Sites</button>
        <button class="btn btn-secondary btn-sm" onclick="addPrebuilt('ai')">🤖 + AI Sites</button>
        <button class="btn btn-secondary btn-sm" onclick="addPrebuilt('social')">📱 + Social Media</button>
        <button class="btn btn-secondary btn-sm" onclick="addPrebuilt('email')">📧 + Email</button>
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
      <button class="btn btn-primary" onclick="goStep(3)">Next: Monitor →</button>
    </div>
  </div>

  <!-- ══ STEP 3: MONITORING ═══════════════════════════════════════ -->
  <div class="panel" id="panel-3">
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
      <button class="btn btn-secondary" onclick="goStep(2)">← Back</button>
      <button class="btn btn-primary" onclick="goStep(4)">Next: Database →</button>
    </div>
  </div>

  <!-- ══ STEP 4: DATABASE ═════════════════════════════════════════ -->
  <div class="panel" id="panel-4">
    <h2>🗄️ Storage Backend</h2>
    <p class="sub">Choose where to store dashboard data. SQLite is zero-setup and recommended for most installs.</p>
    <div class="db-grid">
      <div class="db-card selected" id="db-sq" onclick="pickDb('sqlite')">
        <span style="font-size:30px;">📦</span>
        <div style="font-weight:700;margin-top:8px;">SQLite</div>
        <div style="font-size:11px;color:rgba(255,255,255,.4);margin-top:4px;">Zero config · file-based · recommended</div>
      </div>
      <div class="db-card" id="db-my" onclick="pickDb('mysql')">
        <span style="font-size:30px;">🐬</span>
        <div style="font-weight:700;margin-top:8px;">MySQL / MariaDB</div>
        <div style="font-size:11px;color:rgba(255,255,255,.4);margin-top:4px;">Existing database server</div>
      </div>
    </div>
    <div id="db-sq-info" style="font-size:13px;color:rgba(255,255,255,.45);">✅ A <strong>dash.db</strong> file will be created in the dashboard folder automatically.</div>
    <div id="db-my-fields" style="display:none;">
      <div class="fg">
        <div><label>Host</label><input type="text" id="db-host" value="127.0.0.1"></div>
        <div><label>Port</label><input type="number" id="db-port" value="3306"></div>
        <div><label>Username</label><input type="text" id="db-user" placeholder="root"></div>
        <div><label>Password</label><input type="password" id="db-pw"></div>
        <div><label>Database Name</label><input type="text" id="db-nm" placeholder="dashboard"></div>
        <div style="display:flex;align-items:flex-end;"><button class="btn btn-secondary btn-sm" onclick="testDb()" style="width:100%">🔌 Test Connection</button></div>
      </div>
      <div id="db-test-res" style="margin-top:6px;font-size:13px;"></div>
    </div>
    <div class="nav">
      <button class="btn btn-secondary" onclick="goStep(3)">← Back</button>
      <button class="btn btn-primary" onclick="goStep(5)">Next: Theme →</button>
    </div>
  </div>

  <!-- ══ STEP 5: THEME ════════════════════════════════════════════ -->
  <div class="panel" id="panel-5">
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
      $tgroups=['retro'=>['win98'=>'💾 Win 98','win2k'=>'🖥 Win 2000','winxp'=>'🪟 Win XP',
        'winphone'=>'📱 Win Phone','startmenu'=>'🪟 Start Menu','c64'=>'🕹 C64',
        'os2'=>'🗄 OS/2','solaris'=>'☀️ Solaris'],
        'modern'=>['macos'=>'🍎 macOS','macos9'=>'🌈 Mac OS 9','aqua'=>'💧 Aqua','ios26'=>'✨ iOS 26',
        'ubuntu'=>'🟠 Ubuntu','jellybean'=>'🤖 Jelly Bean','palmos'=>'📟 Palm OS',
        'pocketpc'=>'📲 Pocket PC','webos'=>'🌙 Palm webOS','professional'=>'👔 Professional','girly'=>'🌸 Girly'],
        'seasonal'=>['spring'=>'🌷 Spring','summer'=>'🏖 Summer','autumn'=>'🍂 Autumn','winter'=>'❄️ Winter',
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
      <select id="theme-sel" onchange="selTheme=this.value" style="min-width:180px;"></select>
    </div>
    <div class="nav">
      <button class="btn btn-secondary" onclick="goStep(4)">← Back</button>
      <button class="btn btn-primary" onclick="completeSetup(this)">🚀 Finish Setup</button>
    </div>
  </div>

  <!-- ══ STEP 6: DONE ═════════════════════════════════════════════ -->
  <div class="panel" id="panel-6">
    <h2 style="text-align:center;font-size:22px;">🎉 Setup Complete!</h2>
    <p class="sub" style="text-align:center;margin-bottom:20px;">Your dashboard is ready. Redirecting in a moment…</p>
    <div id="summary" style="text-align:center;margin:16px 0;"></div>
    <div style="text-align:center;margin-top:20px;">
      <a href="index.php" class="btn btn-primary" style="text-decoration:none;font-size:15px;">Go to Dashboard →</a>
    </div>
  </div>
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
let selTheme       = 'win98';
let selDb          = 'sqlite';
let _iconTarget    = null;         // {type:'col'|'link', colId, linkIdx}

/* ══ NAVIGATION ═════════════════════════════════════════════════ */
function goStep(n) {
  document.querySelectorAll('.panel').forEach(p=>p.classList.remove('active'));
  document.querySelectorAll('.step').forEach(s=>{s.classList.remove('active');});
  document.getElementById('panel-'+n).classList.add('active');
  document.getElementById('s'+n).classList.add('active');
  for(let i=1;i<n;i++) document.getElementById('s'+i).classList.add('done');
  window.scrollTo({top:0,behavior:'smooth'});
  if (n===5 && enabledThemes.size===0) initThemes();
}

/* ══ STEP 2 – COLUMN BUILDER ═══════════════════════════════════ */

/* ══ STEP 2 – PERSONAL / COLUMN BUILDER ═══════════════════════ */
const PREBUILT = {
  search:{title:'Search Sites',icon:'🔍',cards:[
    {label:'Google',url:'https://www.google.com',icon:'🌐'},
    {label:'DuckDuckGo',url:'https://duckduckgo.com',icon:'🦆'},
    {label:'Bing',url:'https://www.bing.com',icon:'🔵'},
    {label:'Brave Search',url:'https://search.brave.com',icon:'🦁'},
    {label:'Yahoo',url:'https://search.yahoo.com',icon:'💜'},
    {label:'Ecosia',url:'https://www.ecosia.org',icon:'🌱'},
    {label:'Startpage',url:'https://www.startpage.com',icon:'🔒'},
    {label:'Kagi',url:'https://kagi.com',icon:'⚡'},
  ]},
  ai:{title:'AI Sites',icon:'🤖',cards:[
    {label:'ChatGPT',url:'https://chat.openai.com',icon:'🤖'},
    {label:'Gemini',url:'https://gemini.google.com',icon:'✨'},
    {label:'Claude',url:'https://claude.ai',icon:'🧠'},
    {label:'Grok',url:'https://grok.x.ai',icon:'⚡'},
    {label:'Copilot',url:'https://copilot.microsoft.com',icon:'🪟'},
    {label:'Perplexity',url:'https://www.perplexity.ai',icon:'🔮'},
    {label:'Meta AI',url:'https://www.meta.ai',icon:'👾'},
    {label:'DeepSeek',url:'https://chat.deepseek.com',icon:'🐋'},
    {label:'Mistral',url:'https://chat.mistral.ai',icon:'💫'},
    {label:'Poe',url:'https://poe.com',icon:'🌀'},
  ]},
  social:{title:'Social Media',icon:'📱',cards:[
    {label:'Facebook',url:'https://www.facebook.com',icon:'👥'},
    {label:'Twitter / X',url:'https://x.com',icon:'🐦'},
    {label:'Instagram',url:'https://www.instagram.com',icon:'📸'},
    {label:'YouTube',url:'https://www.youtube.com',icon:'▶️'},
    {label:'Reddit',url:'https://www.reddit.com',icon:'🤖'},
    {label:'LinkedIn',url:'https://www.linkedin.com',icon:'💼'},
    {label:'TikTok',url:'https://www.tiktok.com',icon:'🎵'},
    {label:'Discord',url:'https://discord.com',icon:'💬'},
    {label:'Twitch',url:'https://www.twitch.tv',icon:'🎮'},
    {label:'Pinterest',url:'https://www.pinterest.com',icon:'📌'},
  ]},
  email:{title:'Email & Webmail',icon:'📧',cards:[
    {label:'Gmail',url:'https://mail.google.com',icon:'📧'},
    {label:'Outlook / Hotmail',url:'https://outlook.live.com',icon:'📮'},
    {label:'Proton Mail',url:'https://mail.proton.me',icon:'🔒'},
    {label:'Yahoo Mail',url:'https://mail.yahoo.com',icon:'💜'},
    {label:'iCloud Mail',url:'https://www.icloud.com/mail',icon:'🍎'},
    {label:'Zoho Mail',url:'https://mail.zoho.com',icon:'🔴'},
    {label:'Fastmail',url:'https://www.fastmail.com',icon:'⚡'},
    {label:'Tuta (Tutanota)',url:'https://app.tuta.com',icon:'🟢'},
  ]},
};

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
  if (PREBUILT[key] && !selectedLinks.find(c=>c.id==='pb-'+key)) {
    selectedLinks.push({id:'pb-'+key,...JSON.parse(JSON.stringify(PREBUILT[key]))});
    renderCols();
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
  else if(k==='storage') { monStorage=!monStorage; document.getElementById('drives-section').style.display=monStorage?'block':'none'; }
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

/* ══ STEP 4 – DB ═══════════════════════════════════════════════ */
function pickDb(t) {
  selDb=t;
  document.getElementById('db-sq').classList.toggle('selected',t==='sqlite');
  document.getElementById('db-my').classList.toggle('selected',t==='mysql');
  document.getElementById('db-sq-info').style.display=t==='sqlite'?'block':'none';
  document.getElementById('db-my-fields').style.display=t==='mysql'?'block':'none';
}
async function testDb() {
  const r=await fetch('setup.php?action=test_db&host='+encodeURIComponent(document.getElementById('db-host').value)+'&port='+document.getElementById('db-port').value+'&user='+encodeURIComponent(document.getElementById('db-user').value)+'&pass='+encodeURIComponent(document.getElementById('db-pw').value)+'&name='+encodeURIComponent(document.getElementById('db-nm').value));
  const d=await r.json();
  document.getElementById('db-test-res').innerHTML=d.ok?'<span style="color:#5ef">✅ Connected!</span>':'<span style="color:#f66">❌ '+(d.error||'Failed')+'</span>';
}

/* ══ STEP 5 – THEMES ═══════════════════════════════════════════ */
const ALL_THEMES={win98:'💾 Win 98',win2k:'🖥 Win 2000',winxp:'🪟 Win XP',winphone:'📱 Win Phone',
  startmenu:'🪟 Start Menu',c64:'🕹 C64',os2:'🗄 OS/2',solaris:'☀️ Solaris',
  macos:'🍎 macOS',macos9:'🌈 Mac OS 9',aqua:'💧 Aqua',ios26:'✨ iOS 26',ubuntu:'🟠 Ubuntu',
  jellybean:'🤖 Jelly Bean',palmos:'📟 Palm OS',pocketpc:'📲 Pocket PC',webos:'🌙 Palm webOS',
  professional:'👔 Professional',girly:'🌸 Girly',
  spring:'🌷 Spring',summer:'🏖 Summer',autumn:'🍂 Autumn',winter:'❄️ Winter',
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
    else{if(k==='win98')return;enabledThemes.delete(k);c.classList.replace('on','off');c.querySelector('div').textContent='☐';}
  });
  updateThemeSel();
}
function themeGroup(g) {
  document.querySelectorAll('.tc').forEach(c=>{
    const k=c.dataset.k,inG=c.dataset.g===g||k==='win98';
    if(inG){enabledThemes.add(k);c.classList.replace('off','on');c.querySelector('div').textContent='☑️';}
    else{enabledThemes.delete(k);c.classList.replace('on','off');c.querySelector('div').textContent='☐';}
  });
  updateThemeSel();
}
function updateThemeSel() {
  const sel=document.getElementById('theme-sel'),prev=sel.value;
  sel.innerHTML='';
  for(const[k,l]of Object.entries(ALL_THEMES)){
    if(enabledThemes.has(k)){const o=document.createElement('option');o.value=k;o.textContent=l;sel.appendChild(o);}
  }
  if([...sel.options].some(o=>o.value===prev))sel.value=prev;
  selTheme=sel.value;
}

/* ══ UPGRADE: fast-finish from step 1 ═════════════════════════ */
async function doFinish() {
  const pass=document.getElementById('f-pass').value;
  if(pass.length<4){alert('Password must be at least 4 characters.');return;}
  const fd=new FormData();
  fd.append('action','complete');
  fd.append('title',  document.getElementById('f-title').value||'Server Dashboard');
  fd.append('username',document.getElementById('f-user').value||'admin');
  fd.append('password',pass);
  fd.append('grid_cols',document.getElementById('f-cols').value||'3');
  fd.append('theme',  'win98');
  fd.append('db_type','sqlite');
  fd.append('links_json', '[]');
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
  fd.append('title',  document.getElementById('f-title').value||'Server Dashboard');
  fd.append('username',document.getElementById('f-user').value||'admin');
  fd.append('password',pass);
  fd.append('grid_cols',document.getElementById('f-cols').value||'3');
  fd.append('theme',  selTheme);
  fd.append('db_type',selDb);
  if(selDb==='mysql'){
    fd.append('db_host',document.getElementById('db-host').value);
    fd.append('db_port',document.getElementById('db-port').value);
    fd.append('db_user',document.getElementById('db-user').value);
    fd.append('db_pass',document.getElementById('db-pw').value);
    fd.append('db_name',document.getElementById('db-nm').value);
  }
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
      document.getElementById('summary').innerHTML=
        `<span class="summary-chip">🔗 ${links.length} column${links.length!==1?'s':''}</span>
         <span class="summary-chip">💾 ${drives.length} drive${drives.length!==1?'s':''}</span>
         <span class="summary-chip">🎨 ${selTheme}</span>
         <span class="summary-chip">🗄️ ${selDb}</span>`;
      goStep(6);
      setTimeout(()=>location.href='index.php',3500);
    } else { alert('Save failed: '+(d.error||'unknown')); }
  } catch(e){ alert('Error: '+e.message); }
  btn.disabled=false;btn.textContent='🚀 Finish Setup';
}

/* ══ UTILS ═════════════════════════════════════════════════════ */
function esc(s){return(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');}

/* ══ BACKGROUND CANVAS ═════════════════════════════════════════ */
(()=>{
  const cv=document.getElementById('bg'),ctx=cv.getContext('2d');
  let pts=[],t=0;
  const resize=()=>{cv.width=innerWidth;cv.height=innerHeight;pts=Array.from({length:65},()=>({x:Math.random()*cv.width,y:Math.random()*cv.height,vx:(Math.random()-.5)*.4,vy:(Math.random()-.5)*.4,r:1+Math.random()*2,a:Math.random()}));};
  const draw=()=>{
    ctx.fillStyle='rgba(8,12,24,.15)';ctx.fillRect(0,0,cv.width,cv.height);t+=.007;
    pts.forEach((p,i)=>{
      p.x+=p.vx;p.y+=p.vy;
      if(p.x<0||p.x>cv.width)p.vx*=-1;if(p.y<0||p.y>cv.height)p.vy*=-1;
      ctx.beginPath();ctx.fillStyle=`rgba(74,158,255,${.25+.25*Math.sin(t+p.a)})`;ctx.arc(p.x,p.y,p.r,0,Math.PI*2);ctx.fill();
      for(let j=i+1;j<Math.min(i+5,pts.length);j++){const q=pts[j],dx=p.x-q.x,dy=p.y-q.y,dist=Math.sqrt(dx*dx+dy*dy);if(dist<100){ctx.strokeStyle=`rgba(74,158,255,${.12*(1-dist/100)})`;ctx.lineWidth=.5;ctx.beginPath();ctx.moveTo(p.x,p.y);ctx.lineTo(q.x,q.y);ctx.stroke();}}
    });
    requestAnimationFrame(draw);
  };
  window.addEventListener('resize',resize);resize();draw();
})();
</script>
</body>
</html>

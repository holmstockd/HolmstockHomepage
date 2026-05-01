<?php
if (session_status() === PHP_SESSION_NONE) session_start();

/* ── First-run: redirect to setup wizard ────────────────────────────── */
$_cfgFile = __DIR__ . '/dash_config.php';
function isSetupDone() {
    global $_cfgFile;
    if (!file_exists($_cfgFile)) return false;
    $c = @file_get_contents($_cfgFile) ?: '';
    return strpos($c, 'DASH_SETUP_DONE') !== false
        && strpos($c, "DASH_SETUP_DONE', false") === false;
}
if (!isSetupDone()) {
    header('Location: setup.php'); exit;
}

/* ── If setup just completed and session is already logged in → index ── */
if (!empty($_SESSION['logged_in'])) {
    header('Location: index.php'); exit;
}

/* ── Read config safely ─────────────────────────────────────────────── */
$cfg_raw = @file_get_contents($_cfgFile);
preg_match("/define\('DASH_USERNAME',\s*'([^']+)'\)/", $cfg_raw, $um);
preg_match("/define\('DASH_PASSWORD_HASH',\s*'([^']+)'\)/", $cfg_raw, $hm);
preg_match("/define\('DASH_TITLE',\s*'([^']+)'\)/", $cfg_raw, $tm);
$username = $um[1] ?? 'admin';
$pwhash   = $hm[1] ?? '';
$title    = $tm[1] ?? 'Server Dashboard';

/* ── Sub-user loader ─────────────────────────────────────────────── */
function getSubUsersLogin() {
    $f = __DIR__ . '/dash_users.json';
    return json_decode(@file_get_contents($f) ?: '[]', true) ?: [];
}

/* ── Cookie check ─────────────────────────────────────────────────── */
define('LOGIN_COOKIE', 'dash_auth');
define('LOGIN_DAYS', 180); // 6 months
if (isset($_COOKIE[LOGIN_COOKIE])) {
    $token = $_COOKIE[LOGIN_COOKIE];
    // Admin cookie
    $expected = hash('sha256', $username . ($_SERVER['HTTP_USER_AGENT'] ?? '') . 'dash_secret_salt_2024');
    if (hash_equals($expected, $token)) {
        $_SESSION['logged_in'] = true;
        header('Location: index.php'); exit;
    }
    // Sub-user cookie
    foreach (getSubUsersLogin() as $su) {
        $exp = hash('sha256', $su['username'] . ($_SERVER['HTTP_USER_AGENT'] ?? '') . 'dash_secret_salt_2024');
        if (hash_equals($exp, $token)) {
            $_SESSION['logged_in'] = true;
            $_SESSION['sub_user']  = $su['username'];
            $_SESSION['sub_role']  = $su['role'] ?? 'user';
            header('Location: index.php'); exit;
        }
    }
}

/* ── POST: handle login ─────────────────────────────────────────────── */
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $user = trim($_POST['username'] ?? '');
    $pass = $_POST['password'] ?? '';
    // Admin check
    $ok = ($user === $username) && $pwhash && password_verify($pass, $pwhash);
    if ($ok) {
        $token = hash('sha256', $username . ($_SERVER['HTTP_USER_AGENT'] ?? '') . 'dash_secret_salt_2024');
        setcookie(LOGIN_COOKIE, $token, time() + (LOGIN_DAYS * 86400), '/', '', false, true);
        $_SESSION['logged_in'] = true;
        header('Location: index.php'); exit;
    }
    // Sub-user check
    foreach (getSubUsersLogin() as $su) {
        if ($su['username'] === $user && password_verify($pass, $su['password_hash'])) {
            $token = hash('sha256', $su['username'] . ($_SERVER['HTTP_USER_AGENT'] ?? '') . 'dash_secret_salt_2024');
            setcookie(LOGIN_COOKIE, $token, time() + (LOGIN_DAYS * 86400), '/', '', false, true);
            $_SESSION['logged_in'] = true;
            $_SESSION['sub_user']  = $su['username'];
            $_SESSION['sub_role']  = $su['role'] ?? 'user';
            header('Location: index.php'); exit;
        }
    }
    $error = 'Invalid username or password.';
    sleep(1);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Login — <?= htmlspecialchars($title) ?></title>
<style>
*{box-sizing:border-box;margin:0;padding:0;}
body{font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',sans-serif;background:#0a0a1a;min-height:100vh;display:flex;align-items:center;justify-content:center;position:relative;overflow:hidden;}
canvas#bg{position:fixed;inset:0;z-index:0;}
.login-box{position:relative;z-index:1;background:rgba(255,255,255,0.08);backdrop-filter:blur(40px);-webkit-backdrop-filter:blur(40px);border:1px solid rgba(255,255,255,0.18);border-radius:24px;padding:40px 36px;width:360px;color:#fff;box-shadow:0 20px 60px rgba(0,0,0,0.5);}
.login-logo{font-size:40px;text-align:center;margin-bottom:8px;}
.login-title{font-size:22px;font-weight:700;text-align:center;margin-bottom:4px;letter-spacing:-0.5px;}
.login-sub{font-size:13px;color:rgba(255,255,255,0.5);text-align:center;margin-bottom:28px;}
label{display:block;font-size:12px;color:rgba(255,255,255,0.6);margin-bottom:6px;margin-top:16px;font-weight:500;letter-spacing:0.3px;}
input[type=text],input[type=password]{width:100%;padding:12px 14px;background:rgba(255,255,255,0.1);border:1px solid rgba(255,255,255,0.2);border-radius:10px;color:#fff;font-size:15px;outline:none;transition:border-color 0.2s;}
input:focus{border-color:rgba(74,158,255,0.7);background:rgba(255,255,255,0.15);}
input::placeholder{color:rgba(255,255,255,0.3);}
.login-btn{width:100%;margin-top:24px;padding:13px;background:#4a9eff;border:none;border-radius:10px;color:#fff;font-size:15px;font-weight:600;cursor:pointer;transition:background 0.2s,transform 0.1s;}
.login-btn:hover{background:#2a7eff;}
.login-btn:active{transform:scale(0.98);}
.error{background:rgba(255,60,60,0.2);border:1px solid rgba(255,60,60,0.4);border-radius:8px;padding:10px 14px;font-size:13px;color:#ff8080;margin-top:16px;}
</style>
</head>
<body>
<canvas id="bg"></canvas>
<div class="login-box">
  <div class="login-logo">🖥</div>
  <div class="login-title"><?= htmlspecialchars($title) ?></div>
  <div class="login-sub">Server Dashboard — Sign In</div>
  <form method="POST">
    <label>Username</label>
    <input type="text" name="username" placeholder="username" autocomplete="username"
           value="<?= htmlspecialchars($_POST['username'] ?? '') ?>" required>
    <label>Password</label>
    <input type="password" name="password" placeholder="••••••••" autocomplete="current-password" required>
    <button type="submit" class="login-btn">Sign In →</button>
    <?php if ($error): ?>
    <div class="error">⚠️ <?= htmlspecialchars($error) ?></div>
    <?php endif; ?>
  </form>
</div>
<script>
const canvas=document.getElementById('bg');const ctx=canvas.getContext('2d');let t=0;
function resize(){canvas.width=window.innerWidth;canvas.height=window.innerHeight;}
function drawBlob(cx,cy,r,c1,c2,phase){ctx.beginPath();for(let i=0;i<=14;i++){const a=(i/14)*Math.PI*2;const w=r*(0.85+0.15*Math.sin(a*3+phase+t*0.7));const x=cx+Math.cos(a)*w,y=cy+Math.sin(a)*w;i===0?ctx.moveTo(x,y):ctx.lineTo(x,y);}ctx.closePath();const g=ctx.createRadialGradient(cx-r*0.3,cy-r*0.3,r*0.1,cx,cy,r*1.1);g.addColorStop(0,c1);g.addColorStop(1,c2);ctx.fillStyle=g;ctx.fill();}
function animate(){t+=0.008;const W=canvas.width,H=canvas.height;ctx.fillStyle='#0a0a1a';ctx.fillRect(0,0,W,H);ctx.globalAlpha=0.7;ctx.globalCompositeOperation='screen';drawBlob(W*0.6+Math.sin(t*0.4)*W*0.1,H*0.2+Math.cos(t*0.3)*H*0.08,H*0.55,'rgba(255,100,20,0.9)','rgba(200,30,10,0.4)',0);drawBlob(W*0.5+Math.cos(t*0.35)*W*0.08,H*0.55+Math.sin(t*0.28)*H*0.06,H*0.42,'rgba(120,20,220,0.85)','rgba(60,0,150,0.3)',2);drawBlob(W*0.65+Math.sin(t*0.45)*W*0.07,H*0.8+Math.cos(t*0.38)*H*0.06,H*0.48,'rgba(0,160,255,0.9)','rgba(0,80,200,0.4)',4);ctx.globalCompositeOperation='source-over';ctx.globalAlpha=1;requestAnimationFrame(animate);}
window.addEventListener('resize',resize);resize();animate();
</script>
</body>
</html>

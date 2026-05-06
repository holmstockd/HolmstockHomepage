<?php
/**
 * db.php — MySQL database layer for Dashboard v1.4
 *
 * Add to dash_config.php to enable MySQL:
 *   define('DASH_DB_TYPE', 'mysql');
 *   define('DASH_DB_HOST', 'localhost');
 *   define('DASH_DB_NAME', 'your_database');
 *   define('DASH_DB_USER', 'your_user');
 *   define('DASH_DB_PASS', 'your_password');
 *
 * Falls back to JSON files automatically when MySQL is not configured.
 * SQLite is no longer used.
 */

function getDashDb(): ?PDO {
    static $pdo    = null;
    static $tried  = false;
    if ($tried) return $pdo;
    $tried = true;

    $cfg = __DIR__ . '/dash_config.php';
    if (file_exists($cfg) && !defined('DASH_DB_TYPE')) {
        @include_once $cfg;
    }

    if (!defined('DASH_DB_TYPE') || DASH_DB_TYPE !== 'mysql') return null;
    if (!defined('DASH_DB_HOST') || !defined('DASH_DB_NAME') || !defined('DASH_DB_USER')) return null;

    try {
        $port = (defined('DASH_DB_PORT') && DASH_DB_PORT) ? (int)DASH_DB_PORT : 3306;
        $dsn = 'mysql:host=' . DASH_DB_HOST . ';port=' . $port . ';dbname=' . DASH_DB_NAME . ';charset=utf8mb4';
        $pdo = new PDO($dsn, DASH_DB_USER, defined('DASH_DB_PASS') ? DASH_DB_PASS : '', [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
        _dashCreateTables($pdo);
        return $pdo;
    } catch (Throwable $e) {
        error_log('[dash db.php] MySQL connection failed: ' . $e->getMessage());
        $pdo = null;
        return null;
    }
}

function _dashCreateTables(PDO $pdo): void {
    // Key-value settings per user (theme, size, wallpaper, drives, monitor, custom_theme, etc.)
    $pdo->exec("CREATE TABLE IF NOT EXISTS dash_settings (
        id          INT AUTO_INCREMENT PRIMARY KEY,
        username    VARCHAR(64)  NOT NULL,
        setting_key VARCHAR(128) NOT NULL,
        setting_val MEDIUMTEXT,
        updated_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uk_user_key (username, setting_key)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // Links / bookmarks (whole link-sections array as JSON blob)
    $pdo->exec("CREATE TABLE IF NOT EXISTS dash_links (
        id         INT AUTO_INCREMENT PRIMARY KEY,
        username   VARCHAR(64) NOT NULL UNIQUE,
        data       MEDIUMTEXT  NOT NULL DEFAULT '[]',
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // Floating page-folder widget positions
    $pdo->exec("CREATE TABLE IF NOT EXISTS dash_page_folders (
        id         INT AUTO_INCREMENT PRIMARY KEY,
        username   VARCHAR(64) NOT NULL UNIQUE,
        data       MEDIUMTEXT  NOT NULL DEFAULT '[]',
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // Named layout profiles (theme + wallpaper + size + stat positions)
    $pdo->exec("CREATE TABLE IF NOT EXISTS dash_profiles (
        id                INT AUTO_INCREMENT PRIMARY KEY,
        username          VARCHAR(64)  NOT NULL,
        profile_name      VARCHAR(128) NOT NULL,
        theme             VARCHAR(64)  DEFAULT '',
        wallpaper_variant VARCHAR(128) DEFAULT '',
        size              INT          DEFAULT 100,
        stat_pos_json     MEDIUMTEXT   DEFAULT '{}',
        saved             VARCHAR(20)  DEFAULT '',
        updated_at        TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uk_user_profile (username, profile_name)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // HTML / RSS / camera / calendar widgets (per type, stored as JSON array)
    $pdo->exec("CREATE TABLE IF NOT EXISTS dash_widgets (
        id          INT AUTO_INCREMENT PRIMARY KEY,
        username    VARCHAR(64) NOT NULL,
        widget_type VARCHAR(32) NOT NULL,
        data        MEDIUMTEXT  NOT NULL DEFAULT '[]',
        updated_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uk_user_type (username, widget_type)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // Custom background images / videos per theme
    $pdo->exec("CREATE TABLE IF NOT EXISTS dash_custom_bgs (
        id         INT AUTO_INCREMENT PRIMARY KEY,
        username   VARCHAR(64) NOT NULL UNIQUE,
        data       MEDIUMTEXT  NOT NULL DEFAULT '{}',
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // Document folders (metadata only; files live on disk)
    $pdo->exec("CREATE TABLE IF NOT EXISTS dash_doc_folders (
        id         INT AUTO_INCREMENT PRIMARY KEY,
        username   VARCHAR(64)  NOT NULL,
        dir_key    VARCHAR(64)  NOT NULL,
        label      VARCHAR(255) NOT NULL,
        icon       VARCHAR(16)  DEFAULT '📁',
        sort_order INT          DEFAULT 0,
        pin_type   VARCHAR(16)  DEFAULT 'all',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uk_user_dir (username, dir_key)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // Document file metadata (actual files remain on disk)
    $pdo->exec("CREATE TABLE IF NOT EXISTS dash_doc_files (
        id         INT AUTO_INCREMENT PRIMARY KEY,
        username   VARCHAR(64)  NOT NULL,
        dir_key    VARCHAR(64)  NOT NULL,
        filename   VARCHAR(255) NOT NULL,
        size       BIGINT       DEFAULT 0,
        ext        VARCHAR(16)  DEFAULT '',
        mtime      INT          DEFAULT 0,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uk_user_dir_file (username, dir_key, filename)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // Sub-users (replaces dash_users.json)
    $pdo->exec("CREATE TABLE IF NOT EXISTS dash_users (
        id            INT AUTO_INCREMENT PRIMARY KEY,
        username      VARCHAR(64)  NOT NULL UNIQUE,
        password_hash VARCHAR(255) NOT NULL,
        role          VARCHAR(16)  DEFAULT 'user',
        created_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // Machine profiles — per-browser-device recall via server-side cookie UUID
    $pdo->exec("CREATE TABLE IF NOT EXISTS dash_machines (
        id           INT AUTO_INCREMENT PRIMARY KEY,
        username     VARCHAR(64)  NOT NULL,
        machine_uuid VARCHAR(36)  NOT NULL,
        machine_name VARCHAR(128) DEFAULT 'My Machine',
        last_theme   VARCHAR(64)  DEFAULT '',
        last_variant VARCHAR(128) DEFAULT '',
        last_profile VARCHAR(128) DEFAULT '',
        last_size    INT          DEFAULT 100,
        last_seen    TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        created_at   TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uk_user_uuid (username, machine_uuid)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $pdo->exec("CREATE TABLE IF NOT EXISTS dash_shares (
        id            INT AUTO_INCREMENT PRIMARY KEY,
        from_user     VARCHAR(64)  NOT NULL,
        to_user       VARCHAR(64)  NOT NULL,
        resource_type VARCHAR(32)  NOT NULL,
        resource_id   VARCHAR(128) NOT NULL,
        resource_name VARCHAR(255) DEFAULT '',
        status        VARCHAR(16)  DEFAULT 'pending',
        created_at    TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
        updated_at    TIMESTAMP    DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uk_share (from_user, to_user, resource_type, resource_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

/* ═══════════════════════════════════════════════════════════════════════
   SETTINGS  (generic key-value store per user)
═══════════════════════════════════════════════════════════════════════ */

function dashGetSettings(?PDO $db, string $username): array {
    if ($db) {
        $s = $db->prepare('SELECT setting_key, setting_val FROM dash_settings WHERE username = ?');
        $s->execute([$username]);
        $out = [];
        foreach ($s->fetchAll() as $r) $out[$r['setting_key']] = $r['setting_val'];
        return $out;
    }
    return _dashJsonRead(_dashUserJson('dash_state', $username), []);
}

function dashGetSetting(?PDO $db, string $username, string $key, string $default = ''): string {
    if ($db) {
        $s = $db->prepare('SELECT setting_val FROM dash_settings WHERE username = ? AND setting_key = ?');
        $s->execute([$username, $key]);
        $r = $s->fetch();
        return $r ? ($r['setting_val'] ?? $default) : $default;
    }
    $state = _dashJsonRead(_dashUserJson('dash_state', $username), []);
    return $state[$key] ?? $default;
}

function dashSetSetting(?PDO $db, string $username, string $key, ?string $value): void {
    if ($db) {
        if ($value === null) {
            $db->prepare('DELETE FROM dash_settings WHERE username = ? AND setting_key = ?')
               ->execute([$username, $key]);
        } else {
            $db->prepare('INSERT INTO dash_settings (username, setting_key, setting_val) VALUES (?,?,?)
                          ON DUPLICATE KEY UPDATE setting_val = VALUES(setting_val), updated_at = NOW()')
               ->execute([$username, $key, $value]);
        }
        return;
    }
    $file  = _dashUserJson('dash_state', $username);
    $state = _dashJsonRead($file, []);
    if ($value === null) unset($state[$key]); else $state[$key] = $value;
    _dashJsonWrite($file, $state);
}

function dashSetSettings(?PDO $db, string $username, array $kv): void {
    foreach ($kv as $k => $v) dashSetSetting($db, $username, $k, $v);
}

/* ═══════════════════════════════════════════════════════════════════════
   LINKS
═══════════════════════════════════════════════════════════════════════ */

function dashGetLinks(?PDO $db, string $username): array {
    if ($db) {
        $s = $db->prepare('SELECT data FROM dash_links WHERE username = ?');
        $s->execute([$username]);
        $r = $s->fetch();
        if ($r) {
            return json_decode($r['data'], true) ?: [];
        }
        // No MySQL row yet for this user.
        // Auto-migrate from dash_links.json ONLY for the primary admin account so
        // that a first-time MySQL setup recovers cleanly.  Sub-users must always
        // start with empty links — they see content only through explicit shares.
        $isAdmin = defined('DASH_USERNAME') && $username === DASH_USERNAME;
        if ($isAdmin) {
            $jsonLinks = _dashJsonRead(__DIR__ . '/dash_links.json', null);
            if (is_array($jsonLinks) && !empty($jsonLinks)) {
                try {
                    $db->prepare('INSERT IGNORE INTO dash_links (username, data) VALUES (?,?)')
                       ->execute([$username, json_encode($jsonLinks, JSON_UNESCAPED_UNICODE)]);
                } catch (Throwable $e) {
                    error_log('[dash db.php] dashGetLinks auto-migrate failed: ' . $e->getMessage());
                }
                return $jsonLinks;
            }
        }
        return [];
    }
    return _dashJsonRead(_dashUserJson('dash_links', $username), []);
}

function dashSetLinks(?PDO $db, string $username, array $links): void {
    if ($db) {
        $db->prepare('INSERT INTO dash_links (username, data) VALUES (?,?)
                      ON DUPLICATE KEY UPDATE data = VALUES(data), updated_at = NOW()')
           ->execute([$username, json_encode($links, JSON_UNESCAPED_UNICODE)]);
        return;
    }
    _dashJsonWrite(_dashUserJson('dash_links', $username), $links);
}

/**
 * Returns true if a dash_links record already exists for this user (even if the
 * stored array is empty). Used to distinguish "never initialised" (show setup
 * wizard) from "intentionally empty" (skip wizard).
 *   - MySQL mode: row in dash_links table.
 *   - JSON  mode: per-user dash_links_<user>.json file exists (admin uses
 *                 the un-suffixed dash_links.json).
 */
function dashLinksRowExists(?PDO $db, string $username): bool {
    if ($db) {
        $s = $db->prepare('SELECT COUNT(*) FROM dash_links WHERE username = ?');
        $s->execute([$username]);
        return (int) $s->fetchColumn() > 0;
    }
    return file_exists(_dashUserJson('dash_links', $username));
}

/* ═══════════════════════════════════════════════════════════════════════
   PAGE FOLDERS (floating file-folder widgets on the dashboard)
═══════════════════════════════════════════════════════════════════════ */

function dashGetPageFolders(?PDO $db, string $username): array {
    if ($db) {
        $s = $db->prepare('SELECT data FROM dash_page_folders WHERE username = ?');
        $s->execute([$username]);
        $r = $s->fetch();
        $folders = $r ? (json_decode($r['data'], true) ?: []) : [];
    } else {
        $folders = _dashJsonRead(_dashUserJson('dash_page_folders', $username), []);
    }

    // Auto-migrate: any page-folder widget that has no dir_key gets its own
    // dedicated doc folder so it can never share content with another widget.
    $changed = false;
    foreach ($folders as &$pf) {
        if (!empty($pf['dir_key'])) continue;
        // Always create a brand-new dedicated doc folder — never label-match,
        // because two widgets with the same name must still be independent.
        $dk = 'fd_' . time() . '_' . substr(bin2hex(random_bytes(3)), 0, 6);
        $label = $pf['label'] ?? 'Folder';
        dashDocCreateFolder($db, $username, $dk, $label, '📁', 0);
        $pf['dir_key'] = $dk;
        $changed = true;
        // Tiny sleep so simultaneous widgets get distinct time-based keys
        usleep(1000);
    }
    unset($pf);

    if ($changed) {
        dashSetPageFolders($db, $username, $folders);
    }

    return $folders;
}

function dashSetPageFolders(?PDO $db, string $username, array $folders): void {
    if ($db) {
        $db->prepare('INSERT INTO dash_page_folders (username, data) VALUES (?,?)
                      ON DUPLICATE KEY UPDATE data = VALUES(data), updated_at = NOW()')
           ->execute([$username, json_encode($folders, JSON_UNESCAPED_UNICODE)]);
        return;
    }
    _dashJsonWrite(_dashUserJson('dash_page_folders', $username), $folders);
}

/* ═══════════════════════════════════════════════════════════════════════
   LAYOUT PROFILES
═══════════════════════════════════════════════════════════════════════ */

function dashGetProfiles(?PDO $db, string $username): array {
    if ($db) {
        $s = $db->prepare('SELECT profile_name, saved, theme, wallpaper_variant, size
                           FROM dash_profiles WHERE username = ? ORDER BY profile_name');
        $s->execute([$username]);
        return $s->fetchAll();
    }
    $all = _dashJsonRead(_dashUserJson('dash_layouts', $username), []);
    $out = [];
    foreach ($all as $name => $d) {
        $out[] = ['profile_name'=>$name,'saved'=>$d['saved']??'','theme'=>$d['theme']??'',
                  'wallpaper_variant'=>$d['wallpaper_variant']??'','size'=>(int)($d['size']??100)];
    }
    return $out;
}

function dashGetProfile(?PDO $db, string $username, string $name): ?array {
    if ($db) {
        $s = $db->prepare('SELECT * FROM dash_profiles WHERE username = ? AND profile_name = ?');
        $s->execute([$username, $name]);
        return $s->fetch() ?: null;
    }
    $all = _dashJsonRead(_dashUserJson('dash_layouts', $username), []);
    return isset($all[$name]) ? array_merge($all[$name], ['profile_name' => $name]) : null;
}

function dashSaveProfile(?PDO $db, string $username, string $name, string $theme,
                         string $variant, int $size, string $statPosJson): void {
    $saved = date('Y-m-d H:i');
    if ($db) {
        $db->prepare('INSERT INTO dash_profiles
                        (username, profile_name, theme, wallpaper_variant, size, stat_pos_json, saved)
                      VALUES (?,?,?,?,?,?,?)
                      ON DUPLICATE KEY UPDATE
                        theme=VALUES(theme), wallpaper_variant=VALUES(wallpaper_variant),
                        size=VALUES(size), stat_pos_json=VALUES(stat_pos_json),
                        saved=VALUES(saved), updated_at=NOW()')
           ->execute([$username, $name, $theme, $variant, $size, $statPosJson, $saved]);
        return;
    }
    $file = _dashUserJson('dash_layouts', $username);
    $all  = _dashJsonRead($file, []);
    $all[$name] = ['saved'=>$saved,'theme'=>$theme,'wallpaper_variant'=>$variant,
                   'size'=>$size,'stat_pos_json'=>$statPosJson];
    _dashJsonWrite($file, $all);
}

function dashPatchProfile(?PDO $db, string $username, string $name, array $fields): void {
    if ($db) {
        $sets = []; $vals = [];
        if (isset($fields['theme']))             { $sets[]='theme=?';             $vals[]=$fields['theme']; }
        if (isset($fields['wallpaper_variant'])) { $sets[]='wallpaper_variant=?'; $vals[]=$fields['wallpaper_variant']; }
        if (isset($fields['size']))              { $sets[]='size=?';              $vals[]=(int)$fields['size']; }
        if (isset($fields['stat_pos_json']))     { $sets[]='stat_pos_json=?';     $vals[]=$fields['stat_pos_json']; }
        if (!$sets) return;
        $sets[] = 'saved=?';   $vals[] = date('Y-m-d H:i');
        $sets[] = 'updated_at=NOW()';
        $vals[] = $username; $vals[] = $name;
        $db->prepare('UPDATE dash_profiles SET ' . implode(',',$sets) .
                     ' WHERE username=? AND profile_name=?')->execute($vals);
        return;
    }
    $file = _dashUserJson('dash_layouts', $username);
    $all  = _dashJsonRead($file, []);
    if (!isset($all[$name])) return;
    foreach (['theme','wallpaper_variant','size','stat_pos_json'] as $f) {
        if (isset($fields[$f])) $all[$name][$f] = $fields[$f];
    }
    $all[$name]['saved'] = date('Y-m-d H:i');
    _dashJsonWrite($file, $all);
}

function dashDeleteProfile(?PDO $db, string $username, string $name): void {
    if ($db) {
        $db->prepare('DELETE FROM dash_profiles WHERE username=? AND profile_name=?')
           ->execute([$username, $name]);
        return;
    }
    $file = _dashUserJson('dash_layouts', $username);
    $all  = _dashJsonRead($file, []);
    unset($all[$name]);
    _dashJsonWrite($file, $all);
}

/* ═══════════════════════════════════════════════════════════════════════
   WIDGETS  (html / rss / camera / calendar)
═══════════════════════════════════════════════════════════════════════ */

function dashGetWidgets(?PDO $db, string $username, string $type): array {
    if ($db) {
        $s = $db->prepare('SELECT data FROM dash_widgets WHERE username=? AND widget_type=?');
        $s->execute([$username, $type]);
        $r = $s->fetch();
        return $r ? (json_decode($r['data'], true) ?: []) : [];
    }
    return _dashJsonRead(_dashUserJson("dash_{$type}_widgets", $username), []);
}

function dashSetWidgets(?PDO $db, string $username, string $type, array $data): void {
    if ($db) {
        $db->prepare('INSERT INTO dash_widgets (username, widget_type, data) VALUES (?,?,?)
                      ON DUPLICATE KEY UPDATE data=VALUES(data), updated_at=NOW()')
           ->execute([$username, $type, json_encode($data, JSON_UNESCAPED_UNICODE)]);
        return;
    }
    _dashJsonWrite(_dashUserJson("dash_{$type}_widgets", $username), $data);
}

/* ═══════════════════════════════════════════════════════════════════════
   CUSTOM BACKGROUNDS
═══════════════════════════════════════════════════════════════════════ */

function dashGetCustomBgs(?PDO $db, string $username): array {
    if ($db) {
        $s = $db->prepare('SELECT data FROM dash_custom_bgs WHERE username=?');
        $s->execute([$username]);
        $r = $s->fetch();
        return $r ? (json_decode($r['data'], true) ?: []) : [];
    }
    return _dashJsonRead(_dashUserJson('dash_custom_bg', $username), []);
}

function dashSetCustomBgs(?PDO $db, string $username, array $bgs): void {
    if ($db) {
        $db->prepare('INSERT INTO dash_custom_bgs (username, data) VALUES (?,?)
                      ON DUPLICATE KEY UPDATE data=VALUES(data), updated_at=NOW()')
           ->execute([$username, json_encode($bgs, JSON_UNESCAPED_UNICODE)]);
        return;
    }
    _dashJsonWrite(_dashUserJson('dash_custom_bg', $username), $bgs);
}

/* ═══════════════════════════════════════════════════════════════════════
   DOC FOLDERS
═══════════════════════════════════════════════════════════════════════ */

function dashDocFolders(?PDO $db, string $username): array {
    if ($db) {
        $s = $db->prepare('SELECT * FROM dash_doc_folders WHERE username=? ORDER BY sort_order, label');
        $s->execute([$username]);
        return $s->fetchAll();
    }
    // JSON fallback: scan filesystem
    $base = __DIR__ . '/uploads/docs/' . $username;
    $pins = _dashJsonRead($base . '/folder_pins.json', []);
    $out  = [];
    if (!is_dir($base)) return [];
    foreach (scandir($base) as $e) {
        if ($e[0] === '.') continue;
        $dp = $base . '/' . $e;
        if (!is_dir($dp)) continue;
        $mf = $dp . '/_meta.json';
        if (!file_exists($mf)) continue;
        $m = _dashJsonRead($mf, []);
        $out[] = ['dir_key'=>$e,'label'=>$m['label']??$e,'icon'=>$m['icon']??'📁',
                  'sort_order'=>(int)($m['order']??0),'pin_type'=>$pins[$e]??'all'];
    }
    usort($out, fn($a,$b)=>$a['sort_order']<=>$b['sort_order']?:strcmp($a['label'],$b['label']));
    return $out;
}

function dashDocFolder(?PDO $db, string $username, string $dirKey): ?array {
    if ($db) {
        $s = $db->prepare('SELECT * FROM dash_doc_folders WHERE username=? AND dir_key=?');
        $s->execute([$username, $dirKey]);
        return $s->fetch() ?: null;
    }
    $base = __DIR__ . '/uploads/docs/' . $username;
    $mf   = $base . '/' . $dirKey . '/_meta.json';
    if (!file_exists($mf)) return null;
    $m    = _dashJsonRead($mf, []);
    $pins = _dashJsonRead($base . '/folder_pins.json', []);
    return ['dir_key'=>$dirKey,'label'=>$m['label']??$dirKey,'icon'=>$m['icon']??'📁',
            'sort_order'=>(int)($m['order']??0),'pin_type'=>$pins[$dirKey]??'all'];
}

function dashDocCreateFolder(?PDO $db, string $username, string $dirKey,
                              string $label, string $icon, int $order): void {
    if ($db) {
        $db->prepare('INSERT IGNORE INTO dash_doc_folders
                        (username, dir_key, label, icon, sort_order, pin_type)
                      VALUES (?,?,?,?,?,\'all\')')
           ->execute([$username, $dirKey, $label, $icon, $order]);
        return;
    }
    $base = __DIR__ . '/uploads/docs/' . $username;
    $path = $base . '/' . $dirKey;
    @mkdir($path, 0755, true);
    _dashJsonWrite($path . '/_meta.json', ['label'=>$label,'icon'=>$icon,'order'=>$order]);
}

function dashDocRenameFolder(?PDO $db, string $username, string $dirKey, string $label): void {
    if ($db) {
        $db->prepare('UPDATE dash_doc_folders SET label=? WHERE username=? AND dir_key=?')
           ->execute([$label, $username, $dirKey]);
        return;
    }
    $mf = __DIR__ . '/uploads/docs/' . $username . '/' . $dirKey . '/_meta.json';
    if (file_exists($mf)) {
        $m = _dashJsonRead($mf, []);
        $m['label'] = $label;
        _dashJsonWrite($mf, $m);
    }
}

function dashDocDeleteFolder(?PDO $db, string $username, string $dirKey): void {
    if ($db) {
        $db->prepare('DELETE FROM dash_doc_folders WHERE username=? AND dir_key=?')
           ->execute([$username, $dirKey]);
        $db->prepare('DELETE FROM dash_doc_files WHERE username=? AND dir_key=?')
           ->execute([$username, $dirKey]);
        return;
    }
    // filesystem only (caller removes physical dir)
}

function dashDocSetPin(?PDO $db, string $username, string $dirKey, string $pinType): void {
    if ($db) {
        $db->prepare('UPDATE dash_doc_folders SET pin_type=? WHERE username=? AND dir_key=?')
           ->execute([$pinType, $username, $dirKey]);
        return;
    }
    $base = __DIR__ . '/uploads/docs/' . $username;
    $pf   = $base . '/folder_pins.json';
    $pins = _dashJsonRead($pf, []);
    $pins[$dirKey] = $pinType;
    _dashJsonWrite($pf, $pins);
}

/* ═══════════════════════════════════════════════════════════════════════
   DOC FILES (metadata)
═══════════════════════════════════════════════════════════════════════ */

function dashDocFiles(?PDO $db, string $username, string $dirKey): array {
    if ($db) {
        $s = $db->prepare('SELECT * FROM dash_doc_files WHERE username=? AND dir_key=? ORDER BY filename');
        $s->execute([$username, $dirKey]);
        return $s->fetchAll();
    }
    // Scan filesystem directly
    $path = __DIR__ . '/uploads/docs/' . $username . '/' . $dirKey;
    if (!is_dir($path)) return [];
    $out = [];
    foreach (glob($path . '/*') ?: [] as $fp) {
        if (!is_file($fp)) continue;
        $name = basename($fp);
        if ($name === '_meta.json') continue;
        $ext  = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        $sz   = filesize($fp);
        $out[] = ['filename'=>$name,'size'=>$sz,'ext'=>$ext,'mtime'=>filemtime($fp),
                  'dir_key'=>$dirKey,'username'=>$username];
    }
    usort($out, fn($a,$b)=>strcasecmp($a['filename'],$b['filename']));
    return $out;
}

function dashDocUpsertFile(?PDO $db, string $username, string $dirKey,
                            string $filename, int $size, string $ext, int $mtime): void {
    if (!$db) return;
    $db->prepare('INSERT INTO dash_doc_files (username,dir_key,filename,size,ext,mtime)
                  VALUES (?,?,?,?,?,?)
                  ON DUPLICATE KEY UPDATE size=VALUES(size), mtime=VALUES(mtime)')
       ->execute([$username, $dirKey, $filename, $size, $ext, $mtime]);
}

function dashDocDeleteFile(?PDO $db, string $username, string $dirKey, string $filename): void {
    if (!$db) return;
    $db->prepare('DELETE FROM dash_doc_files WHERE username=? AND dir_key=? AND filename=?')
       ->execute([$username, $dirKey, $filename]);
}

/* ═══════════════════════════════════════════════════════════════════════
   SUB-USERS  (replaces dash_users.json)
═══════════════════════════════════════════════════════════════════════ */

function dashGetUsers(?PDO $db): array {
    if ($db) {
        $rows = $db->query('SELECT username, role, created_at FROM dash_users ORDER BY username')
                   ->fetchAll(PDO::FETCH_ASSOC);
        // Deduplicate by username — keeps first occurrence (oldest row wins)
        $seen = []; $out = [];
        foreach ($rows as $r) {
            if (!isset($seen[$r['username']])) {
                $seen[$r['username']] = true;
                $out[] = $r;
            }
        }
        return $out;
    }
    $rows = _dashJsonRead(__DIR__ . '/dash_users.json', []);
    // Deduplicate JSON list too
    $seen = []; $out = [];
    foreach ($rows as $r) {
        if (!isset($seen[$r['username'] ?? ''])) {
            $seen[$r['username'] ?? ''] = true;
            $out[] = $r;
        }
    }
    return $out;
}

function dashGetUser(?PDO $db, string $username): ?array {
    if ($db) {
        $s = $db->prepare('SELECT * FROM dash_users WHERE username=?');
        $s->execute([$username]);
        return $s->fetch() ?: null;
    }
    foreach (_dashJsonRead(__DIR__ . '/dash_users.json', []) as $u) {
        if ($u['username'] === $username) return $u;
    }
    return null;
}

function dashVerifyUser(?PDO $db, string $username, string $password): ?array {
    $u = dashGetUser($db, $username);
    if ($u && password_verify($password, $u['password_hash'])) return $u;
    return null;
}

function dashSaveUser(?PDO $db, string $username, string $hash, ?string $role = 'user'): void {
    $role = $role ?? 'user';
    if ($db) {
        // If user already exists and role is passed as null sentinel, keep existing role
        $db->prepare('INSERT INTO dash_users (username, password_hash, role) VALUES (?,?,?)
                      ON DUPLICATE KEY UPDATE password_hash=VALUES(password_hash),
                      role=VALUES(role), updated_at=NOW()')
           ->execute([$username, $hash, $role]);
        return;
    }
    $file  = __DIR__ . '/dash_users.json';
    $users = _dashJsonRead($file, []);
    $found = false;
    foreach ($users as &$u) {
        if ($u['username'] === $username) { $u['password_hash']=$hash; $u['role']=$role; $found=true; break; }
    }
    unset($u);
    if (!$found) $users[] = ['username'=>$username,'password_hash'=>$hash,'role'=>$role];
    _dashJsonWrite($file, $users);
}

/* ═══════════════════════════════════════════════════════════════════════
   MACHINE PROFILES  (per-device recall via server-side UUID cookie)
═══════════════════════════════════════════════════════════════════════ */

function dashGetMachine(?PDO $db, string $username, string $uuid): array {
    if (!$db || !$uuid) return [];
    $s = $db->prepare('SELECT * FROM dash_machines WHERE username=? AND machine_uuid=?');
    $s->execute([$username, $uuid]);
    $row = $s->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        $name = 'Machine ' . strtoupper(substr($uuid, 0, 8));
        try {
            $db->prepare('INSERT IGNORE INTO dash_machines (username, machine_uuid, machine_name) VALUES (?,?,?)')
               ->execute([$username, $uuid, $name]);
        } catch (Throwable $e) {}
        $s->execute([$username, $uuid]);
        $row = $s->fetch(PDO::FETCH_ASSOC) ?: [];
    }
    // Touch last_seen without changing other columns
    try {
        $db->prepare('UPDATE dash_machines SET last_seen=NOW() WHERE username=? AND machine_uuid=?')
           ->execute([$username, $uuid]);
    } catch (Throwable $e) {}
    return $row ?: [];
}

function dashSaveMachine(?PDO $db, string $username, string $uuid, array $data): void {
    if (!$db || !$uuid) return;
    $allowed = ['machine_name','last_theme','last_variant','last_profile','last_size'];
    $sets = []; $vals = [];
    foreach ($allowed as $k) {
        if (array_key_exists($k, $data)) { $sets[] = "$k=?"; $vals[] = $data[$k]; }
    }
    if (!$sets) return;
    $vals[] = $username; $vals[] = $uuid;
    try {
        $db->prepare('UPDATE dash_machines SET ' . implode(',', $sets) . ' WHERE username=? AND machine_uuid=?')
           ->execute($vals);
    } catch (Throwable $e) {}
}

function dashGetAllMachines(?PDO $db, string $username): array {
    if (!$db) return [];
    $s = $db->prepare('SELECT * FROM dash_machines WHERE username=? ORDER BY last_seen DESC');
    $s->execute([$username]);
    return $s->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function dashDeleteUser(?PDO $db, string $username): void {
    if ($db) {
        foreach (['dash_settings','dash_links','dash_page_folders','dash_profiles',
                  'dash_widgets','dash_custom_bgs','dash_doc_folders','dash_doc_files',
                  'dash_machines'] as $t) {
            $db->prepare("DELETE FROM $t WHERE username=?")->execute([$username]);
        }
        // Clean up shares — remove any row where this user is sender or recipient
        $db->prepare('DELETE FROM dash_shares WHERE from_user=? OR to_user=?')->execute([$username, $username]);
        $db->prepare('DELETE FROM dash_users WHERE username=?')->execute([$username]);
        return;
    }
    $file  = __DIR__ . '/dash_users.json';
    $users = array_filter(_dashJsonRead($file, []), fn($u)=>$u['username']!==$username);
    _dashJsonWrite($file, array_values($users));
}

/* ═══════════════════════════════════════════════════════════════════════
   EXPORT / IMPORT  (full user data as JSON)
═══════════════════════════════════════════════════════════════════════ */

function dashExportUser(?PDO $db, string $username): array {
    return [
        'version'      => '1.5',
        'username'     => $username,
        'exported_at'  => date('Y-m-d H:i:s'),
        'settings'     => dashGetSettings($db, $username),
        'links'        => dashGetLinks($db, $username),
        'page_folders' => dashGetPageFolders($db, $username),
        'profiles'     => dashGetProfiles($db, $username),
        'widgets'      => [
            'html'     => dashGetWidgets($db, $username, 'html'),
            'rss'      => dashGetWidgets($db, $username, 'rss'),
            'camera'   => dashGetWidgets($db, $username, 'camera'),
            'calendar'  => dashGetWidgets($db, $username, 'calendar'),
            'countdown' => dashGetWidgets($db, $username, 'countdown'),
        ],
        'custom_bgs'   => dashGetCustomBgs($db, $username),
        'doc_folders'  => dashDocFolders($db, $username),
        'doc_files'    => $db ? (function() use ($db, $username) {
            $s = $db->prepare('SELECT * FROM dash_doc_files WHERE username=? ORDER BY dir_key, filename');
            $s->execute([$username]);
            return $s->fetchAll();
        })() : [],
    ];
}

function dashImportUser(?PDO $db, string $username, array $data): void {
    if (isset($data['settings']) && is_array($data['settings']))
        dashSetSettings($db, $username, $data['settings']);
    if (isset($data['links']) && is_array($data['links']))
        dashSetLinks($db, $username, $data['links']);
    if (isset($data['page_folders']) && is_array($data['page_folders']))
        dashSetPageFolders($db, $username, $data['page_folders']);
    if (isset($data['custom_bgs']) && is_array($data['custom_bgs']))
        dashSetCustomBgs($db, $username, $data['custom_bgs']);
    foreach (['html','rss','camera','calendar','countdown'] as $t) {
        if (isset($data['widgets'][$t]) && is_array($data['widgets'][$t]))
            dashSetWidgets($db, $username, $t, $data['widgets'][$t]);
    }
    if ($db && isset($data['profiles']) && is_array($data['profiles'])) {
        foreach ($data['profiles'] as $p) {
            dashSaveProfile($db, $username,
                $p['profile_name']??$p['name']??'', $p['theme']??'',
                $p['wallpaper_variant']??'', (int)($p['size']??100), $p['stat_pos_json']??'{}');
        }
    }
    if ($db && isset($data['doc_folders']) && is_array($data['doc_folders'])) {
        foreach ($data['doc_folders'] as $f) {
            dashDocCreateFolder($db, $username, $f['dir_key'], $f['label'],
                                $f['icon']??'📁', (int)($f['sort_order']??0));
            if (!empty($f['pin_type']) && $f['pin_type'] !== 'all')
                dashDocSetPin($db, $username, $f['dir_key'], $f['pin_type']);
        }
    }
}

/* ═══════════════════════════════════════════════════════════════════════
   ADMIN: per-user storage stats
═══════════════════════════════════════════════════════════════════════ */

function dashUserStats(?PDO $db, string $username): array {
    $diskBytes = 0;
    $diskFiles = 0;
    $base = __DIR__ . '/uploads/docs/' . $username;
    if (is_dir($base)) {
        foreach (new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS)
        ) as $f) {
            if ($f->isFile()) { $diskBytes += $f->getSize(); $diskFiles++; }
        }
    }
    $dbLinks = $dbFolders = $dbFiles = $dbProfiles = 0;
    if ($db) {
        $s=$db->prepare('SELECT COUNT(*) FROM dash_links WHERE username=?'); $s->execute([$username]);
        $dbLinks = (int)$s->fetchColumn();
        $s=$db->prepare('SELECT COUNT(*) FROM dash_doc_folders WHERE username=?'); $s->execute([$username]);
        $dbFolders = (int)$s->fetchColumn();
        $s=$db->prepare('SELECT COUNT(*) FROM dash_doc_files WHERE username=?'); $s->execute([$username]);
        $dbFiles = (int)$s->fetchColumn();
        $s=$db->prepare('SELECT COUNT(*) FROM dash_profiles WHERE username=?'); $s->execute([$username]);
        $dbProfiles = (int)$s->fetchColumn();
    }
    return ['disk_bytes'=>$diskBytes,'disk_files'=>$diskFiles,
            'db_links'=>$dbLinks,'db_folders'=>$dbFolders,
            'db_files'=>$dbFiles,'db_profiles'=>$dbProfiles];
}

/* ═══════════════════════════════════════════════════════════════════════
   SHARING
═══════════════════════════════════════════════════════════════════════ */

function dashCreateShare(?PDO $db, string $fromUser, string $toUser, string $type, string $resourceId, string $resourceName = ''): bool {
    if (!$db) return false;
    try {
        $db->prepare('INSERT INTO dash_shares (from_user,to_user,resource_type,resource_id,resource_name,status)
                      VALUES (?,?,?,?,?,\'pending\')
                      ON DUPLICATE KEY UPDATE resource_name=VALUES(resource_name),status=\'pending\',updated_at=NOW()')
           ->execute([$fromUser, $toUser, $type, $resourceId, $resourceName]);
        return true;
    } catch (Exception $e) { return false; }
}

function dashGetSharesFrom(?PDO $db, string $username): array {
    if (!$db) return [];
    $s = $db->prepare('SELECT * FROM dash_shares WHERE from_user=? ORDER BY created_at DESC');
    $s->execute([$username]);
    return $s->fetchAll();
}

function dashGetSharesTo(?PDO $db, string $username): array {
    if (!$db) return [];
    $s = $db->prepare('SELECT * FROM dash_shares WHERE to_user=? ORDER BY FIELD(status,\'pending\',\'accepted\',\'declined\'), created_at DESC');
    $s->execute([$username]);
    return $s->fetchAll();
}

function dashUpdateShare(?PDO $db, int $shareId, string $status, string $toUser): bool {
    if (!$db) return false;
    $status = in_array($status, ['accepted','declined','pending']) ? $status : 'pending';
    $db->prepare('UPDATE dash_shares SET status=?,updated_at=NOW() WHERE id=? AND to_user=?')
       ->execute([$status, $shareId, $toUser]);
    return true;
}

function dashRevokeShare(?PDO $db, int $shareId, string $fromUser): bool {
    if (!$db) return false;
    $db->prepare('DELETE FROM dash_shares WHERE id=? AND from_user=?')->execute([$shareId, $fromUser]);
    return true;
}

function dashGetAcceptedSharesByType(?PDO $db, string $toUser, string $type): array {
    if (!$db) return [];
    $s = $db->prepare('SELECT from_user, resource_id FROM dash_shares WHERE (to_user=? OR to_user=\'__all__\') AND resource_type=? AND status=\'accepted\'');
    $s->execute([$toUser, $type]);
    return $s->fetchAll();
}

/* ═══════════════════════════════════════════════════════════════════════
   JSON FALLBACK HELPERS
═══════════════════════════════════════════════════════════════════════ */

function _dashJsonRead(string $file, $default = []) {
    $raw = @file_get_contents($file);
    if ($raw === false) return $default;
    $d = json_decode($raw, true);
    return ($d !== null) ? $d : $default;
}

function _dashJsonWrite(string $file, $data): void {
    $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    // Atomic write: temp-file + rename prevents two concurrent writes from interleaving
    // and corrupting the JSON (the original root cause of columns disappearing on save races).
    $tmp = $file . '.tmp.' . getmypid();
    if (@file_put_contents($tmp, $json, LOCK_EX) !== false) {
        @rename($tmp, $file);
    } else {
        // Fallback direct write with exclusive lock
        file_put_contents($file, $json, LOCK_EX);
    }
}

/**
 * v1.4.2 — return per-user JSON path so JSON-fallback mode supports multi-user.
 *
 * Before this helper, sub-users in JSON mode all read/wrote the same flat file
 * (e.g. dash_links.json), so every sub-user saw the admin's dashboard. Now:
 *   - admin → keeps the legacy un-suffixed filename (no data migration needed)
 *   - sub-user "alice" → dash_links_alice.json, dash_state_alice.json, etc.
 *
 * Use for any file that holds *per-user* state. Do NOT use for global config
 * files like dash_users.json (the user list itself).
 */
function _dashUserJson(string $base, string $username): string {
    $admin = defined('DASH_USERNAME') ? DASH_USERNAME : 'admin';
    if ($username === '' || $username === $admin) {
        return __DIR__ . '/' . $base . '.json';
    }
    $safe = preg_replace('/[^a-zA-Z0-9_-]/', '', $username);
    if ($safe === '') $safe = 'user';
    return __DIR__ . '/' . $base . '_' . $safe . '.json';
}

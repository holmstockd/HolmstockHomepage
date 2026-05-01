<?php
/**
 * Dashboard SQLite database connection.
 * Returns a ready-to-use SQLite3 instance, or null if SQLite3 is unavailable.
 * Tables are created on first connection; the file lives in the same directory.
 */
function getDashDb(): ?SQLite3 {
    if (!class_exists('SQLite3')) return null;
    static $db = null;
    if ($db) return $db;
    try {
        $db = new SQLite3(__DIR__ . '/dash.sqlite');
        $db->enableExceptions(true);
        // WAL mode: better concurrent read/write performance
        $db->exec('PRAGMA journal_mode=WAL; PRAGMA synchronous=NORMAL;');

        // Named layout profiles — stores links + theme + wallpaper
        $db->exec('CREATE TABLE IF NOT EXISTS layouts (
            name             TEXT PRIMARY KEY COLLATE NOCASE,
            saved            TEXT NOT NULL DEFAULT \'\',
            links_json       TEXT NOT NULL DEFAULT \'[]\',
            theme            TEXT NOT NULL DEFAULT \'\',
            wallpaper_variant TEXT NOT NULL DEFAULT \'\'
        )');

        // Migrate older DBs that lack the new columns (safe, idempotent)
        $cols = [];
        $res  = $db->query("PRAGMA table_info(layouts)");
        while ($r = $res->fetchArray(SQLITE3_ASSOC)) {
            $cols[] = $r['name'];
        }
        if (!in_array('theme', $cols)) {
            $db->exec("ALTER TABLE layouts ADD COLUMN theme TEXT NOT NULL DEFAULT ''");
        }
        if (!in_array('wallpaper_variant', $cols)) {
            $db->exec("ALTER TABLE layouts ADD COLUMN wallpaper_variant TEXT NOT NULL DEFAULT ''");
        }

        return $db;
    } catch (Exception $e) {
        error_log('[dash db.php] SQLite3 error: ' . $e->getMessage());
        $db = null;
        return null;
    }
}

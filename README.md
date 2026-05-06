# Server Homepage Dashboard — v1.6 (Build Plan v1.3)

A self-hosted, fully customizable PHP/HTML browser homepage — no Node.js, no build step, no Composer.  
Drop it on any PHP server and open it in your browser.

---

## What's New in v1.6 (Build Plan v1.3)

| # | Feature | Status |
|---|---|---|
| T001 | **Folder widget isolation** | Each page-folder is bound to a unique `dir_key`; opening one never leaks another folder's files |
| T002 | **Widget position + size persistence** | Width and height saved alongside x/y in `stat_pos_json`; all widget types restore size on reload |
| T003 | **Profile auto-save** | Theme/wallpaper/size/positions continuously written back to the active profile (debounced 1.2 s) |
| T004 | **Links independent of profiles** | Profile save/load never touches `dash_links.json` or the links table |
| T005 | **Size slider per profile** | Zoom level stored in profile record and restored on profile load |
| T006 | **Hide/show columns** | × on section headers hides them; Options → Widgets lists all hidden columns with restore buttons |
| T007 | **Camera widget** | iframe/MJPEG/video stream widget with optional record-trigger button |
| T008 | **Google Calendar embed** | Multi-calendar embed with configurable timezone and "+ New Event" deep-link |
| T009 | **Widget resize + font scaling** | Width slider scales inner content font-size proportionally; camera/calendar excluded (iframe layout) |
| T010 | **Embed compatibility** | No X-Frame-Options or restrictive CSP; HTML widget `<script>` tags re-executed via `_execWidgetScripts` |
| — | **6–9 animated wallpapers per theme** | 10 new global CSS animations: aurora, nebula, matrix, lava, grid, waves, diamonds, stripes, starfield, plasma |
| — | **Curated era-accurate wallpaper variants** | Win 9x/98/2K/XP get teal/brick/sandstone/metal options only; Palm OS gets stripes/grid/diamonds; retro themes get matrix/navy/circles/bricks/stripes — no aurora/nebula/plasma anachronisms |
| — | **Android 4 Nexus Live as default** | Jelly Bean now defaults to the Nexus particle-network canvas; Circuit Board is the variant |
| — | **setup.php UX** | Custom dark dropdown, password show/hide toggle, `db_type` defaults to `none` |

---

## What's New in v1.5

| # | Feature | Summary |
|---|---|---|
| 1 | **Machine UUID profiles** | Server-side 10-year cookie gives each device a unique UUID. Last-used theme, variant, and size are recalled automatically per device — two browsers, two different themes |
| 2 | **Animated OS-accurate folder windows** | Folder windows open with a scale+fade animation (CSS keyframe). The titlebar shows the folder name. Windows are freely draggable by the titlebar — just grab and move |
| 3 | **Per-theme window chrome** | All 25+ themes now have OS-accurate window chrome: Amiga (orange titlebar, close gadget), NeXTSTEP (dark gray, minimal), BeOS (golden gradient, round buttons), Norton (blue/yellow monospace), Atari ST (blue PM-style), IRIX (teal SGI), C64 (medium blue), OS/2 (Presentation Manager), Palm OS, WebOS, macOS (traffic lights), macOS 9 (pinstripe), Ubuntu (purple/orange), Jelly Bean, Win98/2K/XP |
| 4 | **ZIP drop upgrader** | Settings → Update: upload a ZIP or provide a download URL. SHA-256 is verified before extraction. Protected files (dash_config.php, uploads/) are never overwritten. SQL migrations in `migrations/` run automatically |
| 5 | **Update URL checker** | Configurable `version.json` URL. One click to check for updates — shows version, changelog, and a "Download & Apply" button. Auto-reloads on success |
| 6 | **Full export/import backup** | `export_data.php` downloads a ZIP with all settings, widgets, links, profiles, custom backgrounds, and uploads. `import_data.php` restores from that ZIP |
| 7 | **Settings → Machines tab** | Lists all registered devices with UUID, name, last-used theme, and last-seen timestamp. Rename or delete any machine profile |
| 8 | **Settings → Update tab** | Central update hub: check for updates, upload ZIP, download from URL, configure update source, export backup, import backup — all in one place |

---

## What's New in v1.4

| # | Feature | Summary |
|---|---|---|
| 1 | **6 New Retro OS Themes** | Amiga Workbench, NeXTSTEP, BeOS, DOS/Norton Commander, Atari ST/TOS, IRIX/SGI — full CSS vars, wallpaper styles, and titlebars |
| 2 | **Sticky Notes** | Draggable, resizable 📌 sticky notes directly on your dashboard — auto-save as you type, 4 colors, persist in MySQL |
| 3 | **Countdown Timer widgets** | Live ⏳ countdown to any date/time — add via Options → Widgets, appears as a floating panel |
| 4 | **CRT Overlay** | 📺 button in the toolbar toggles a retro phosphor scanline + vignette overlay over the whole dashboard |
| 5 | **Theme startup sounds** | Each retro theme plays its own synthesized chime when selected (Web Audio API, no files) — toggle in Options |
| 6 | **RSS widget width persistence** | RSS widget now saves and restores width (and height) alongside position, just like all other widgets |
| 7 | **Requirements checker** | `/requirements.php` — full server readiness check: PHP 8+, extensions, MySQL, writable dirs, web server detection |
| 8 | **Hidden column restore** | Options → Widgets shows all hidden columns with one-click restore buttons |

---

## What's New in v1.3

| # | Feature | Summary |
|---|---|---|
| 1 | **Folder widget isolation** | Each page-folder widget (📁 icon on dashboard) opens its own isolated file panel — no cross-contamination between folders |
| 2 | **Widget size persistence** | Width **and** height saved for every floating widget (stat, HTML, RSS, camera, calendar); restored on page load |
| 3 | **Profile auto-save** | Active profile is continuously written back whenever theme, wallpaper, size, or widget positions change — no manual save needed |
| 4 | **Links independent of profiles** | Profile switch/load never overwrites your link columns; links are always separate |
| 5 | **Size slider per profile** | Each saved profile stores its own zoom level; restored when the profile is loaded |
| 6 | **Hide / restore columns** | × button on each column header (visible in Edit mode) hides it; restore from **Options → Widgets → Hidden Columns** |
| 7 | **Camera widget** | Embed any IP camera MJPEG stream or NVR iframe (Scrypted, Frigate, BlueIris) as a draggable floating panel with optional ⏺ record trigger |
| 8 | **Google Calendar widget** | Embed up to 5 Google Calendars in one draggable widget with + New Event deep-link |
| 9 | **Widget resize with font scaling** | SE-corner drag now resizes both width and height; font size scales proportionally |
| 10 | **Embed compatibility** | No X-Frame-Options or restrictive CSP headers; `<script>` tags in HTML widget content are re-executed correctly |

### v1.3 Post-release fixes

| Fix | What changed |
|---|---|
| **Document folder panel — create & delete restored** | Added 🗑 delete button to each folder (hover to reveal); fixed sidebar hiding entirely when opening a widget-scoped folder so the New Folder row can no longer be reached in a broken state |
| **Google Calendar setup — completely reworked UX** | Added a numbered 5-step guide directly in the form (make public → find Calendar ID → paste); added a **"Paste share link → Extract ID"** helper that automatically pulls the `src=` calendar ID out of any Google Calendar share or embed URL and appends it to the ID field — no manual digging needed |
| **Folder selection race condition fixed** | `selectDocFolder()` now pins `_docCurrentFolder` before the async server reload so files always display for the clicked folder, not for whatever folder the reload resolves to |
| **Delete folder actually deletes** | `delete_folder` in `download.php` was matching on `id` but the JS was sending `folder` (the dir_key); fixed to use the same dir resolution as the `list` action (`$f['dir']` → `$f['path']` fallback); also now physically removes the directory and all its files from disk |
| **Old folders auto-migrated to unique `dir` keys** | `loadFolders()` now assigns a guaranteed-unique `dir` field to any legacy folder that was saved without one, eliminating the root cause of multiple folders resolving to the same physical directory |
| **`loadDocFolders` stays on current folder after reload** | After upload, delete, or folder creation, the panel no longer jumps back to the first folder if the current folder still exists |
| **Deleted folder directories no longer reappear** | Root cause: `folderPath()` was auto-creating the directory on every `list` call, so deleting a folder's directory was immediately undone by the next refresh. Fixed by adding a `$create` flag — only `add_folder` and `upload` actions create directories; `list`, `get`, and `delete` never do |
| **"Delete All" button actually deletes files** | `deleteAllDocFiles` was sending `action` in the POST body, but PHP reads it from `$_GET` — so every "Delete All" silently did a `list` instead. Fixed to put `action` in the URL query string |
| **Deleted folders no longer reappear as "Documents"** | `loadFolders()` was auto-recreating a default "Documents" folder whenever JSON was empty, so deleting all folders caused them to snap back immediately. Now returns an empty list and shows a "Create a folder to get started" message instead |
| **`download.php` fully rewritten — no JSON config** | Root cause of all folder bugs: a central JSON mapping file that could get out of sync with the filesystem. Completely removed. Each folder is now a plain subdirectory of the user's upload directory; the folder's label and icon are stored in `_meta.json` inside that subdirectory. The directory name itself is the unique ID (e.g. `fd_1746123456_a3f2b1`). Two folders sharing a directory is now physically impossible. No config to corrupt, no migrations, no mapping table. |
| **`upgrade.php` — MySQL detection now tests a real connection** | Previously the page checked only whether `dash_config.php` contained `DASH_DB_TYPE = 'mysql'`. If you dropped or recreated the database, it still showed "✅ MySQL Already Connected". Now upgrade.php actually opens a PDO connection and runs `SELECT 1`. If it fails, a yellow warning card shows the exact PDO error and presents a pre-filled re-entry form so you can reconnect without editing any files. |
| **Folder widget panel — never shows stale content** | Root cause: `openPageFolder()` called `loadDocFolders()` asynchronously before wiping the display. If the fetch was slow, the panel showed whatever files it last rendered. Rewritten to clear content synchronously before any async work. If a widget has no folder ID (broken widget), it now shows a red error with a link to `diag.php` instead of silently showing old files. |
| **`addPageFolder()` — fails visibly on server error** | Previously, if `download.php` returned a PHP error or non-JSON response, the fetch silently threw, `dirKey` stayed empty, and the widget was created with no ID — guaranteed to show stale content on open. Now alerts the user and aborts the widget creation entirely if no valid folder ID is returned from the server. |
| **Upload and delete refresh in locked-folder mode** | After uploading or deleting a file in a widget-locked folder panel, the code was calling `loadDocFolders()` (fetches all folders, rebuilds sidebar). In locked mode the sidebar is hidden and only one folder matters. Fixed to call `renderDocFiles(dirKey)` directly — faster, and prevents the sidebar from flashing in. |
| **Diagnostic page added (`diag.php`)** | Auth-gated debug page showing PHP version, MySQL connection status, and a table of every doc folder (filesystem dir, file count, MySQL record, widget dir_key). Includes a "Wipe All Doc Folder Data" button for a clean slate and a link from the dashboard version badge. |
| **`download.php` — `add_folder` never fired via POST** | Root cause: `$action = $_GET['action'] ?? 'list'` only read query-string params. JS `addPageFolder()` sends `action=add_folder` in the POST body via FormData, so `$action` always resolved to `list`, returned `{ok:true,folders:[...]}` without a `dir` key, and the UI showed "Folder creation failed — server returned no folder ID." Fixed to `$_GET['action'] ?? $_POST['action'] ?? 'list'`. |
| **4 off-theme seasonal themes removed** | Halloween, Valentine's Day, New Year, and Easter themes removed from the theme selector and all CSS/JS/variant tables. Remaining seasonal themes (Spring, Summer, Autumn, Winter, Thanksgiving, July 4th, Christmas) are unaffected. |
| **Android 4 / Jelly Bean — canvas swap** | Nexus Live particle network is now the **default** canvas; Circuit Board moves to the second variant slot. VARIANTS table updated to match. |
| **Palm V and Palm Pilot theme-switch bug** | `theme-palmv` and `theme-palmpilot` were missing from the `themeClasses` strip-list, so switching away from those themes left the class on `<body>`. Both keys also added to the JS `valid` array. |
| **Wallpaper variants blocked by screensaver canvas** | Screensaver canvases (z-index 0) painted over `#wallpaper` when a `w-*` static variant was selected. Added `stopAllCanvases()` called from `onVariantChange()` whenever a wallpaper-class variant is chosen. |

---

## Features at a Glance

| Feature | Details |
|---|---|
| **32+ Themes** | Win 98, Win XP, Win 2000, Win Phone, macOS, Mac OS 9, OSX Tiger, Aqua, iOS 26, Ubuntu, C64, OS/2, Amiga, NeXTSTEP, BeOS, DOS/Norton, Atari ST, IRIX/SGI, Palm OS, Palm V, Palm Pilot, Pocket PC, WebOS, Jelly Bean, Spring, Summer, Autumn, Winter, Thanksgiving, July 4th, Christmas, and more |
| **Animated Canvas Wallpapers** | Pipes, Aquarium, Nexus, Snow, Leaves, Petals, Bliss, fireworks, silk ribbons, blobs — all pure JS canvas |
| **Custom Backgrounds** | Upload your own image or video per theme (stored in `uploads/`) |
| **Search Bar** | Configurable engine: Google, Bing, DuckDuckGo, Brave, Ecosia, Kagi, Yahoo, Startpage |
| **Site Logo** | Upload a PNG/SVG/JPG logo to replace the title text on the top bar |
| **Free-Drag Layout** | All columns and widgets are freely draggable; positions saved server-side |
| **Named Layouts** | Save multiple layouts (Desktop, iPad, Laptop) and switch between them in Edit Mode |
| **Bookmark Import** | Import Chrome/Firefox/Edge bookmarks HTML export — each folder becomes a column |
| **Preset Columns** | One-click columns: Search Sites, AI Tools, Social Media, Email & Webmail |
| **System Widgets** | Live CPU, RAM, and storage drive widgets (draggable, hideable, resizable) |
| **Clock Widget** | Digital clock with seconds and date |
| **Weather Widget** | Configurable location weather display |
| **Document Folders** | Each folder icon opens its own isolated file panel (v1.3) |
| **Custom HTML Widgets** | Paste any embed code (Elfsight, Google Maps, stock tickers, Widgetbot) |
| **Camera Widgets** | MJPEG / NVR iframe stream panels with optional Scrypted record trigger (v1.3) |
| **Google Calendar Widget** | Embed one or more calendars as a draggable panel with + New Event button (v1.3) |
| **Hide / Show Columns** | × button hides any link column; restore list in Options → Widgets (v1.3) |
| **Per-OS Menus** | macOS menu bar, Mac OS 9 menu bar, Windows 9x Start Menu, Ubuntu app grid, OSX Tiger menu |
| **User Accounts** | Admin + read-only user roles, bcrypt passwords |
| **SQLite or MySQL** | Choose your storage backend during setup |
| **Setup Wizard** | 6-step guided setup: account → links → monitoring → database → theme → done |
| **ZIP Distribution** | `zips/dash.zip` (clean install) and `zips/github.zip` (full snapshot) |

---

## Quick Install

1. **Copy** the `php-dashboard/` folder to your web server root, e.g.:
   ```
   /var/www/html/dash/
   ```
2. **Make it writable** by the web server:
   ```bash
   chown -R www-data:www-data /var/www/html/dash
   chmod -R 755 /var/www/html/dash
   ```
3. **Visit** `http://yourserver/dash/setup.php` in your browser.
4. **Follow the 6-step wizard** (see below).
5. **Log in** at `index.php`.

**Requirements:** PHP 8.0+ with `json`, `sqlite3` (or `pdo_mysql`), `fileinfo` extensions.  
Works on Apache, Nginx + PHP-FPM, LiteSpeed, or a Raspberry Pi running Apache.

---

## Setup Wizard — Step by Step

### Step 1 — Account
Set your admin username and password. Additional users can be added later in **Options → Users**.

### Step 2 — Dashboard Links
Build your link columns using the toolbar buttons:

| Button | What it adds |
|---|---|
| **📁 New Column** | Blank named column |
| **🔍 + Search Sites** | Google, DuckDuckGo, Brave, Kagi, Bing, Ecosia, Startpage, Yahoo |
| **🤖 + AI Sites** | ChatGPT, Claude, Gemini, Grok, Copilot, Perplexity, DeepSeek, Mistral, Poe |
| **📱 + Social Media** | Facebook, X/Twitter, Instagram, YouTube, Reddit, Discord, TikTok, Twitch, Pinterest |
| **📧 + Email** | Gmail, Outlook, Proton Mail, Yahoo Mail, iCloud Mail, Zoho, Fastmail, Tuta |

**Import browser bookmarks:**  
Export your bookmarks from Chrome/Firefox/Edge as an HTML file  
(Bookmarks Manager → ⋮ menu → Export bookmarks).  
Select the file with the "Import browser bookmarks" file picker — each bookmark folder becomes a column automatically.

You can add, edit, reorder, and delete links at any time directly on the live dashboard.

### Step 3 — Monitoring
Toggle which live system stats appear as floating widgets on the dashboard:
- ⚡ CPU Usage (% load)
- 🧠 RAM / Memory (used vs total)
- 💾 Storage (auto-detects all drives and mount points)

### Step 4 — Database
- **SQLite** (default, zero-config) — creates `dash.db` in the dashboard folder
- **MySQL / MariaDB** — enter your host, port, username, password, and database name; use "Test Connection" to verify before continuing

### Step 5 — Theme
Select which themes to enable and pick your default. All 30+ themes are available. You can change this any time using the theme dropdown on the dashboard.

---

## Daily Use

### The Top Bar
From left to right:
- **Logo / Title** — your site logo image or dashboard title
- **Storage widgets** — live disk usage for configured drives
- **Search bar** — searches using your configured engine (default: Google)
- **Clock** — live time
- **⚙️ Settings** — opens Options panel (admin only)
- **🚪 Logout**
- **📁 + Folder** — adds a document folder to the page
- **✏️ Edit** — enters Edit Mode
- **Theme dropdown** — switch themes instantly
- **Variant dropdown** — switch theme variant or background

### Clicking Links
All links open in a new tab. Links display an emoji icon and the site name.

### Hiding Widgets
Every stat widget (CPU, RAM, storage, clock, weather) has an **×** button in the top-right corner. Click it to hide the widget. Restore hidden widgets in **Options → General → Stat Widget Visibility**.

---

## Edit Mode

Click **✏️ Edit** in the top bar. When Edit Mode is active:

- **Drag columns** — click and drag any column header to move it freely; position auto-saves to the server
- **Drag widgets** — drag the CPU/RAM/storage/clock/weather widgets by their title bar
- **Resize columns** — drag the **⋮** handle on the right edge of any column (except clock and weather)
- **Add a link** — click the **+** button on any column header
- **Edit a link** — click the pencil icon on any link card
- **Delete a link** — click the trash icon on any link card
- **Delete a column** — click the **×** button on the column header
- **🗂 Spread Out** — auto-arranges all columns into an even grid (great for fixing stacked columns)
- **✅ Done** — exits Edit Mode and auto-saves all positions to the server

### Layout Profiles — Multi-Device / Multi-Setup Workflow

When in Edit Mode, a **📋 Profiles** button appears. Click it to open the Profiles manager.

#### What a profile stores
Each profile is a complete snapshot of:
- All columns, cards, and their positions
- The active **theme** (e.g. Dracula, Win98, Catppuccin…)
- The active **wallpaper / variant** (e.g. animated canvas, custom background)

#### Saving a profile
1. Arrange your columns, pick your theme and wallpaper.
2. Enter Edit Mode, click **📋 Profiles**.
3. Type a name (e.g. `Work`, `Gaming`, `Laptop`) and click **💾 Save New**.
   - To update an existing profile with your current layout, click its **💾 Overwrite** button.

#### Loading a profile
Click the green **📥 Load** button on any saved profile. The page reloads with that profile's columns, theme, and wallpaper applied.

> **Nothing is saved automatically.** If you change your wallpaper or rearrange columns and don't save the profile, those changes are gone on next load. Explicit save is always required.

#### Per-machine "last loaded" indicator
Each browser/device remembers which profile was last loaded on it (stored locally). You'll see a **★ this machine** badge on that profile in the list — it's just a local hint, not shared across devices.

#### Deleting a profile
Click the red 🗑 button on any profile row. Deletion is permanent.

**Example setup:**
| Profile | Theme | Use case |
|---|---|---|
| `Work` | Catppuccin | Office PC — work tools, monitoring widgets |
| `Gaming` | Dracula | Home rig — game launchers, Discord, Twitch |
| `Laptop` | Nord | Travel — compact single-column layout |

Profiles are stored server-side (SQLite, or JSON file as fallback), so they're available from any browser connected to the server.

---

## Search Engine

Go to **Options → General → Search Bar Engine** to choose which search engine the top bar sends queries to:

| Option | URL |
|---|---|
| 🔍 Google | google.com |
| 🔵 Bing | bing.com |
| 🦆 DuckDuckGo | duckduckgo.com |
| 🦁 Brave Search | search.brave.com |
| 🌱 Ecosia | ecosia.org |
| ⚡ Kagi | kagi.com |
| 💜 Yahoo | search.yahoo.com |
| 🔒 Startpage | startpage.com |

The setting is saved server-side and applies on any device/browser.

---

## Site Logo

Go to **Options → General → Site Logo** to replace the text title on the top bar with an image.

**Ideas for creating a logo:**

| Tool | How |
|---|---|
| [Canva](https://canva.com) | Free drag-and-drop logo maker — export as transparent PNG |
| [Favicon.io](https://favicon.io) | Generate logo from text or emoji in seconds |
| [SVG Repo](https://svgrepo.com) | Thousands of free SVG icons — search and download |
| Paint.NET / GIMP | Create a text banner, export as transparent PNG |
| Crop your favicon | Screenshot the existing browser tab icon at 2× zoom |

**Recommended size:** 200 × 40 px or smaller, transparent PNG or SVG.  
To go back to text: click **🗑 Remove Logo** in Options.

---

## Themes & Wallpapers

### Switching Themes
Use the **theme dropdown** in the top bar. Changes take effect instantly.

### Switching Wallpapers / Variants
Use the **variant dropdown** next to the theme dropdown. Variants include built-in animated wallpapers and any custom backgrounds you've uploaded.

### Adding Custom Backgrounds
Go to **Options → Themes**, click the theme you want to edit. The inline editor appears with:
- Your existing backgrounds (click to preview, 🗑 to delete, ✓ to set active)
- **+ Add Background** section: choose **Upload Image** or **Upload Video**

Supported image formats: JPG, PNG, GIF, WebP.  
Supported video formats: MP4, WebM (these loop as live wallpapers).

---

## Custom HTML Widgets

Go to **Options → Widgets → Add Custom HTML Widget**.  
Paste any embed code and give it a name — it appears as a draggable widget on the dashboard.

Works great with:
- [Elfsight](https://elfsight.com) widgets (weather, social feeds, countdown timers)
- Google Maps embeds
- Stock tickers and currency converters
- Widgetbot Discord chat embeds
- YouTube live stream embeds

---

## Options Reference

| Tab | What you can configure |
|---|---|
| **General** | Dashboard title · Grid columns · Search engine · Site logo · Restore hidden widgets |
| **Drives** | Add/remove monitored disk mount paths for the storage widget |
| **Themes** | Enable/disable themes · Manage per-theme custom backgrounds (upload/delete) |
| **Custom Theme** | Build a fully custom theme: colors, font, radius, shadow, wallpaper |
| **Links** | Bulk-view and manage all link columns and cards |
| **Widgets** | Toggle CPU, RAM, Storage, Clock, Weather · Add custom HTML widgets |
| **Users** | Create/delete admin or read-only user accounts |
| **Password** | Change the admin password |
| **Export** | Download ZIP of your configuration, links, and uploaded assets |

---

## File Layout

```
php-dashboard/
├── index.php               Main dashboard
├── options.php             Admin settings panel
├── setup.php               First-run wizard
├── auth.php                Session/login handling
├── stats.php               System stats JSON API (CPU, RAM, disk)
├── save_links.php          Saves column positions and content
├── save_stat_pos.php       Saves widget positions
├── save_state.php          Saves theme/wallpaper/search engine/size
├── save_layout.php         Named layout save/load/delete/list
├── dash_config.php         Generated config (title, password hash, DB type)
├── dash_links.json         Column and link data
├── dash_state.json         Theme, wallpaper, search engine, size
├── dash_stat_pos.json      Widget drag positions
├── dash_layouts.json       Named saved layouts
├── dash_custom_bg.json     Per-theme background config
├── dash_monitor.json       Widget visibility toggles
├── dash_drives.json        Drive monitoring paths
├── dash_html_widgets.json  Custom HTML widget definitions
├── uploads/                Uploaded images, videos, and site logo
└── zips/
    ├── dash.zip            Clean install archive (no data files)
    └── github.zip          Full snapshot (includes all state files)
```

---

## Updating

1. Back up `dash_config.php`, `dash_links.json`, `dash_state.json`, `uploads/`, and any `dash_*.json` files.
2. Replace all `.php` files with the new version.
3. Your data files are preserved.

If the setup wizard re-appears after an update, your `dash_config.php` may have been overwritten — restore from backup or re-run the wizard (your `dash_links.json` and other data files are unchanged).

---

## Troubleshooting

| Problem | Fix |
|---|---|
| Blank page | Check PHP error log; confirm PHP 8+ and sqlite3/json extensions are loaded |
| Setup wizard loops | Delete `dash_config.php` and visit `setup.php` again |
| Widgets not dragging | Enter Edit Mode first (✏️ Edit button in the top bar) |
| Stats show `--` | Confirm `stats.php` runs without errors; needs `/proc/meminfo` access (Linux) |
| Custom background not showing | Confirm `uploads/` is writable: `chmod 755 uploads/` |
| Logo not showing after upload | Hard-refresh the browser (Ctrl+Shift+R / Cmd+Shift+R) |
| Layouts not saving | Confirm the dashboard folder is writable by the web server user |
| Session expires too fast | Check `session.gc_maxlifetime` in `php.ini` |

---

## Security Notes

- Passwords are stored using PHP's `password_hash(PASSWORD_BCRYPT)`.
- The remember-me cookie uses a SHA-256 token tied to username and salt.
- For production: serve over **HTTPS** and consider restricting access to the dashboard directory by IP using `.htaccess` or Nginx `allow/deny`.
- `stats.php` exposes live server stats — restrict by IP if the dashboard is publicly accessible.

---

## License

MIT — free for personal and commercial use. Attribution appreciated but not required.

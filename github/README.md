# Server Homepage Dashboard

A self-hosted, fully customizable PHP/HTML browser homepage — no Node.js, no build step, no Composer.  
Drop it on any PHP server and open it in your browser.

---

## Features at a Glance

| Feature | Details |
|---|---|
| **30+ Themes** | Win 98, Win XP, Win 2000, Win Phone, macOS, Mac OS 9, OSX Tiger, Aqua, iOS 26, Ubuntu, C64, OS/2, Palm OS, Pocket PC, WebOS, Jelly Bean, seasonal themes, and more |
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
| **Document Folders** | Drag-and-drop folder icons on the dashboard that act as page sections |
| **Custom HTML Widgets** | Paste any embed code (Elfsight, Google Maps, stock tickers, Widgetbot) |
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

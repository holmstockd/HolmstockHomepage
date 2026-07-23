# Server Homepage Dashboard

A self-hosted browser homepage for your server. Drop it on any PHP host — no
Node, no build step, no Composer. Link columns, live disk and CPU stats,
floating widgets, and 80 animated themes.

![PHP](https://img.shields.io/badge/PHP-8.0%2B-777bb4)
![License](https://img.shields.io/badge/license-MIT-green)
![No build step](https://img.shields.io/badge/build-none%20required-brightgreen)

> **Heads up:** this is designed for a machine you control — a home server, a
> VPS, a LAN box. Read [SECURITY.md](SECURITY.md) before exposing it to the
> open internet.

---

## Quick Install

**Requirements:** PHP 8.0+, Apache or Nginx. MySQL/MariaDB optional (falls back to JSON files).

```bash
# 1. Copy the dashboard onto your web root
sudo cp -r php-dashboard /var/www/html/dash

# 2. Let the web server own it (Debian/Ubuntu uses www-data)
sudo chown -R www-data:www-data /var/www/html/dash

# 3. Make sure PHP can write its config + data files
sudo chmod -R 755 /var/www/html/dash
```

Then open `http://your-server/dash/` in a browser. The setup wizard runs automatically:

1. **Account** — create the admin username and password
2. **Links** — add your first columns and links
3. **Drives** — add disks to monitor (validated with `df -h`)
4. **Database** — MySQL/MariaDB, or leave as `none` to use JSON files
5. **Theme** — pick a starting theme
6. **Done** — you're redirected to the dashboard

> **Note:** the wizard writes `dash_config.php`. If PHP can't write to the directory, setup will fail —
> check ownership and permissions above.

### Docker / other web roots

Point your vhost `DocumentRoot` at the `php-dashboard` directory. No rewrite rules are required;
the bundled `.htaccess` only sets cache headers and blocks direct access to `dash_*.json`.

---

## Settings

Settings are grouped into five areas:

| Group | What's in it |
|---|---|
| **Appearance** | General options, theme visibility, and the custom theme builder |
| **Content** | Link columns, floating widgets, and document folders/uploads |
| **Server** | Drive monitoring, MySQL connection, and updates (admin only) |
| **Sharing** | Share columns and widgets with other users; export and import |
| **Account** | Password, user management, device registration, changelog |

To add a background to a theme, go to **Appearance → Themes**, click **Edit** on
any theme, then upload an image or video. Each theme can hold several, selectable
from the second dropdown on the dashboard.

To add a widget, go to **Content → Widgets** and pick a type from the row of
buttons at the top.

---

## Updating

Two supported paths:

**In-app (recommended)** — Settings → Update → *Upload ZIP*, choose the release zip, apply.
User data is preserved automatically: `dash_config.php`, every `dash_*.json`, and the
`uploads/` and `zips/` directories are never overwritten.

**Manual** — unzip over the install directory:
```bash
sudo unzip -o dash-update-1.7.zip -d /var/www/html/
sudo chown -R www-data:www-data /var/www/html/dash
```

Always take a backup first: Settings → Update → *Export backup*.

---

## Themes

80 themes, grouped in the dropdown:

| Group | Themes |
|---|---|
| **Microsoft** | Windows 3.1, 9x Retro, 2000, XP, Vista, 7, 10, 11, Windows Phone, Pocket PC 6 |
| **Apple** | Mac OS 9, Mac OS 9 Retro, Mac OS X Aqua, Mac OS X Retro, Tiger, macOS, iOS 26, iPad |
| **Palm & Mobile** | Palm OS, Palm V/Vx, Palm Pilot, Palm Treo, webOS, Android 4 |
| **BlackBerry** | BlackBerry 10, BlackBerry Bold |
| **Linux & Unix** | Ubuntu, Linux Mint, IRIX/SGI, Solaris |
| **Retro Computing** | Commodore 64, Amiga Workbench, Atari ST, NeXTSTEP, BeOS, OS/2 Warp, Norton Commander |
| **Seasons** | Spring, Summer, Autumn, Winter |
| **Holidays** | Memorial Day, July 4th, Thanksgiving, Christmas |
| **Technology Eras** | 80s, 90s, 2000s, 2010s, 2020s Technology, Pager |
| **Processors** | Intel, AMD, IBM POWER, PowerPC, VIA, ARM |
| **Months** | January through December — a distinct scene for each |
| **Music & TV** | 80s TV, 80s Music, 90s TV, 90s Music, 2000s TV, 2000s Music, 2010s TV, 2010s Music |
| **Era & Style** | 80s TV, 90s Music, Hatsune Miku, Professional, Cute |

Every theme has an animated canvas background plus additional wallpaper variants in the
second dropdown. Themes you don't want can be hidden in Settings → Themes.

---

## Changelog

Full history is in the dashboard itself under **Settings → Changelog**, and in
[GitHub Releases](../../releases).

Recent highlights:

- **1.5.6** — Security: per-install cookie signing secret; sub-user privilege fix
- **1.5.5** — Settings reorganised into five groups; every tab documented
- **1.5.4** — 12 month themes, 8 music/TV decade themes, Memorial Day
- **1.5.3** — Technology era and processor themes
- **1.5.2** — Live OS desktop wallpapers
- **1.5.1** — Windows 3.1 / 11, BlackBerry, Palm Treo themes

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

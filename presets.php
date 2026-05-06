<?php
/**
 * presets.php — Shared preset column library (added in v1.4.3).
 *
 * Single source of truth for the starter columns shown in:
 *   1. The first-run welcome wizard (index.php)
 *   2. The "Add Link" modal's Quick-Pick library (index.php)
 *   3. The "Add Preset Column" section in Options → Links (options.php)
 *
 * Each entry is keyed by category name and has:
 *   - icon  : emoji shown in pickers and on the resulting column
 *   - desc  : short hint text shown next to the icon in pickers
 *   - items : array of {icon, label, url} card definitions
 *
 * To add or change presets later, just edit this file — every consumer
 * picks up the new list automatically on next page load.
 */

function dashGetPresets(): array {
    return [
        'Search' => [
            'icon' => '🔍',
            'desc' => 'Google, DuckDuckGo, Maps…',
            'items' => [
                ['icon'=>'🔍','label'=>'Google',       'url'=>'https://google.com'],
                ['icon'=>'🦆','label'=>'DuckDuckGo',   'url'=>'https://duckduckgo.com'],
                ['icon'=>'🔎','label'=>'Bing',         'url'=>'https://bing.com'],
                ['icon'=>'🌐','label'=>'Brave Search', 'url'=>'https://search.brave.com'],
                ['icon'=>'📰','label'=>'Kagi',         'url'=>'https://kagi.com'],
                ['icon'=>'🗺','label'=>'Google Maps',  'url'=>'https://maps.google.com'],
            ],
        ],
        'AI' => [
            'icon' => '🤖',
            'desc' => 'ChatGPT, Claude, Gemini…',
            'items' => [
                ['icon'=>'🤖','label'=>'ChatGPT',      'url'=>'https://chatgpt.com'],
                ['icon'=>'💎','label'=>'Gemini',       'url'=>'https://gemini.google.com'],
                ['icon'=>'🧠','label'=>'Claude',       'url'=>'https://claude.ai'],
                ['icon'=>'🌙','label'=>'Mistral',      'url'=>'https://chat.mistral.ai'],
                ['icon'=>'⚡','label'=>'Grok',         'url'=>'https://grok.x.ai'],
                ['icon'=>'🦙','label'=>'Perplexity',   'url'=>'https://perplexity.ai'],
                ['icon'=>'🖼','label'=>'Midjourney',   'url'=>'https://midjourney.com'],
                ['icon'=>'🎨','label'=>'DALL-E',       'url'=>'https://openai.com/dall-e-3'],
                ['icon'=>'🤗','label'=>'Hugging Face', 'url'=>'https://huggingface.co'],
                ['icon'=>'📝','label'=>'NotebookLM',   'url'=>'https://notebooklm.google.com'],
            ],
        ],
        'Dev' => [
            'icon' => '🛠',
            'desc' => 'GitHub, GitLab, Docker Hub…',
            'items' => [
                ['icon'=>'🐙','label'=>'GitHub',         'url'=>'https://github.com'],
                ['icon'=>'🦊','label'=>'GitLab',         'url'=>'https://gitlab.com'],
                ['icon'=>'🪣','label'=>'Bitbucket',      'url'=>'https://bitbucket.org'],
                ['icon'=>'🌊','label'=>'DigitalOcean',   'url'=>'https://digitalocean.com'],
                ['icon'=>'🔥','label'=>'Firebase',       'url'=>'https://firebase.google.com'],
                ['icon'=>'▲', 'label'=>'Vercel',         'url'=>'https://vercel.com'],
                ['icon'=>'🚀','label'=>'Netlify',        'url'=>'https://netlify.com'],
                ['icon'=>'📦','label'=>'npm',            'url'=>'https://npmjs.com'],
                ['icon'=>'🐳','label'=>'Docker Hub',     'url'=>'https://hub.docker.com'],
                ['icon'=>'🛠','label'=>'Stack Overflow', 'url'=>'https://stackoverflow.com'],
                ['icon'=>'☁️','label'=>'AWS Console',    'url'=>'https://console.aws.amazon.com'],
                ['icon'=>'🌩','label'=>'Cloudflare',     'url'=>'https://cloudflare.com'],
            ],
        ],
        'Social' => [
            'icon' => '💬',
            'desc' => 'X, Reddit, Discord…',
            'items' => [
                ['icon'=>'🐦','label'=>'X / Twitter','url'=>'https://x.com'],
                ['icon'=>'📘','label'=>'Facebook',   'url'=>'https://facebook.com'],
                ['icon'=>'📷','label'=>'Instagram',  'url'=>'https://instagram.com'],
                ['icon'=>'💼','label'=>'LinkedIn',   'url'=>'https://linkedin.com'],
                ['icon'=>'👻','label'=>'Snapchat',   'url'=>'https://snapchat.com'],
                ['icon'=>'🎵','label'=>'TikTok',     'url'=>'https://tiktok.com'],
                ['icon'=>'📌','label'=>'Pinterest',  'url'=>'https://pinterest.com'],
                ['icon'=>'🦋','label'=>'Bluesky',    'url'=>'https://bsky.app'],
                ['icon'=>'🐘','label'=>'Mastodon',   'url'=>'https://mastodon.social'],
                ['icon'=>'💬','label'=>'Discord',    'url'=>'https://discord.com'],
                ['icon'=>'📡','label'=>'Reddit',     'url'=>'https://reddit.com'],
                ['icon'=>'📺','label'=>'YouTube',    'url'=>'https://youtube.com'],
            ],
        ],
        'Media' => [
            'icon' => '🎬',
            'desc' => 'Netflix, Spotify, Plex…',
            'items' => [
                ['icon'=>'🎬','label'=>'Netflix',       'url'=>'https://netflix.com'],
                ['icon'=>'📽','label'=>'Prime Video',   'url'=>'https://primevideo.com'],
                ['icon'=>'🎥','label'=>'Disney+',       'url'=>'https://disneyplus.com'],
                ['icon'=>'🟪','label'=>'HBO Max',       'url'=>'https://max.com'],
                ['icon'=>'🟢','label'=>'Hulu',          'url'=>'https://hulu.com'],
                ['icon'=>'🎞','label'=>'Plex',          'url'=>'https://app.plex.tv'],
                ['icon'=>'🟣','label'=>'Twitch',        'url'=>'https://twitch.tv'],
                ['icon'=>'🎵','label'=>'Spotify',       'url'=>'https://open.spotify.com'],
                ['icon'=>'🍎','label'=>'Apple Music',   'url'=>'https://music.apple.com'],
                ['icon'=>'🎶','label'=>'YouTube Music', 'url'=>'https://music.youtube.com'],
                ['icon'=>'📻','label'=>'SoundCloud',    'url'=>'https://soundcloud.com'],
            ],
        ],
        'Productivity' => [
            'icon' => '📋',
            'desc' => 'Notion, Drive, Calendar…',
            'items' => [
                ['icon'=>'📅','label'=>'Google Calendar','url'=>'https://calendar.google.com'],
                ['icon'=>'📝','label'=>'Google Docs',    'url'=>'https://docs.google.com'],
                ['icon'=>'📊','label'=>'Google Sheets',  'url'=>'https://sheets.google.com'],
                ['icon'=>'💾','label'=>'Google Drive',   'url'=>'https://drive.google.com'],
                ['icon'=>'✅','label'=>'Notion',         'url'=>'https://notion.so'],
                ['icon'=>'📋','label'=>'Trello',         'url'=>'https://trello.com'],
                ['icon'=>'🗂','label'=>'Airtable',       'url'=>'https://airtable.com'],
                ['icon'=>'📐','label'=>'Figma',          'url'=>'https://figma.com'],
                ['icon'=>'🔔','label'=>'Slack',          'url'=>'https://slack.com'],
                ['icon'=>'🟦','label'=>'Teams',          'url'=>'https://teams.microsoft.com'],
                ['icon'=>'🟧','label'=>'Asana',          'url'=>'https://asana.com'],
                ['icon'=>'🟪','label'=>'ClickUp',        'url'=>'https://clickup.com'],
            ],
        ],
        'Email' => [
            'icon' => '📧',
            'desc' => 'Gmail, Outlook, Proton…',
            'items' => [
                ['icon'=>'📬','label'=>'Gmail',           'url'=>'https://mail.google.com'],
                ['icon'=>'📮','label'=>'Outlook',         'url'=>'https://outlook.live.com'],
                ['icon'=>'🛡','label'=>'Proton Mail',     'url'=>'https://mail.proton.me'],
                ['icon'=>'💌','label'=>'iCloud Mail',     'url'=>'https://www.icloud.com/mail'],
                ['icon'=>'🟪','label'=>'Yahoo Mail',      'url'=>'https://mail.yahoo.com'],
                ['icon'=>'🦆','label'=>'DuckDuckGo Email','url'=>'https://duckduckgo.com/email'],
                ['icon'=>'🟫','label'=>'Fastmail',        'url'=>'https://app.fastmail.com'],
                ['icon'=>'🌶','label'=>'Hey',             'url'=>'https://app.hey.com'],
            ],
        ],
        'Shopping' => [
            'icon' => '🛍',
            'desc' => 'Amazon, eBay, Walmart…',
            'items' => [
                ['icon'=>'📦','label'=>'Amazon',        'url'=>'https://amazon.com'],
                ['icon'=>'📚','label'=>'Amazon Orders', 'url'=>'https://amazon.com/your-orders'],
                ['icon'=>'🛒','label'=>'eBay',          'url'=>'https://ebay.com'],
                ['icon'=>'🛍','label'=>'Etsy',          'url'=>'https://etsy.com'],
                ['icon'=>'🏷','label'=>'AliExpress',    'url'=>'https://aliexpress.com'],
                ['icon'=>'💳','label'=>'PayPal',        'url'=>'https://paypal.com'],
                ['icon'=>'🏪','label'=>'Walmart',       'url'=>'https://walmart.com'],
                ['icon'=>'🎯','label'=>'Target',        'url'=>'https://target.com'],
                ['icon'=>'🔵','label'=>'Best Buy',      'url'=>'https://bestbuy.com'],
                ['icon'=>'🟥','label'=>'Costco',        'url'=>'https://costco.com'],
                ['icon'=>'🏠','label'=>'Home Depot',    'url'=>'https://homedepot.com'],
                ['icon'=>'🛏','label'=>'IKEA',          'url'=>'https://ikea.com'],
            ],
        ],
        'News' => [
            'icon' => '📰',
            'desc' => 'BBC, Reuters, NYT, HN…',
            'items' => [
                ['icon'=>'🗞','label'=>'BBC News',     'url'=>'https://bbc.com/news'],
                ['icon'=>'📰','label'=>'Reuters',      'url'=>'https://reuters.com'],
                ['icon'=>'🌐','label'=>'AP News',      'url'=>'https://apnews.com'],
                ['icon'=>'📡','label'=>'Hacker News',  'url'=>'https://news.ycombinator.com'],
                ['icon'=>'🔴','label'=>'CNN',          'url'=>'https://cnn.com'],
                ['icon'=>'🗽','label'=>'NY Times',     'url'=>'https://nytimes.com'],
                ['icon'=>'📻','label'=>'NPR',          'url'=>'https://npr.org'],
                ['icon'=>'💼','label'=>'WSJ',          'url'=>'https://wsj.com'],
                ['icon'=>'🟦','label'=>'The Guardian', 'url'=>'https://theguardian.com'],
                ['icon'=>'📊','label'=>'Bloomberg',    'url'=>'https://bloomberg.com'],
                ['icon'=>'🟧','label'=>'Ars Technica', 'url'=>'https://arstechnica.com'],
                ['icon'=>'⬛','label'=>'The Verge',    'url'=>'https://theverge.com'],
            ],
        ],
        'Travel' => [
            'icon' => '✈️',
            'desc' => 'Flights, hotels, rideshare…',
            'items' => [
                ['icon'=>'✈️','label'=>'Google Flights','url'=>'https://google.com/flights'],
                ['icon'=>'🏨','label'=>'Booking.com',   'url'=>'https://booking.com'],
                ['icon'=>'🏠','label'=>'Airbnb',        'url'=>'https://airbnb.com'],
                ['icon'=>'🟪','label'=>'Expedia',       'url'=>'https://expedia.com'],
                ['icon'=>'🟧','label'=>'Kayak',         'url'=>'https://kayak.com'],
                ['icon'=>'🦉','label'=>'Tripadvisor',   'url'=>'https://tripadvisor.com'],
                ['icon'=>'🌍','label'=>'Google Maps',   'url'=>'https://maps.google.com'],
                ['icon'=>'🚗','label'=>'Uber',          'url'=>'https://uber.com'],
                ['icon'=>'🚖','label'=>'Lyft',          'url'=>'https://lyft.com'],
                ['icon'=>'🚆','label'=>'Rome2rio',      'url'=>'https://rome2rio.com'],
                ['icon'=>'🅿️','label'=>'SpotHero',     'url'=>'https://spothero.com'],
            ],
        ],
        'Finance' => [
            'icon' => '💰',
            'desc' => 'Banking, stocks, crypto…',
            'items' => [
                ['icon'=>'📈','label'=>'Yahoo Finance',  'url'=>'https://finance.yahoo.com'],
                ['icon'=>'📊','label'=>'Google Finance', 'url'=>'https://google.com/finance'],
                ['icon'=>'🟢','label'=>'Robinhood',      'url'=>'https://robinhood.com'],
                ['icon'=>'🟦','label'=>'Fidelity',       'url'=>'https://fidelity.com'],
                ['icon'=>'🟧','label'=>'Schwab',         'url'=>'https://schwab.com'],
                ['icon'=>'🪙','label'=>'Coinbase',       'url'=>'https://coinbase.com'],
                ['icon'=>'🟨','label'=>'Binance',        'url'=>'https://binance.com'],
                ['icon'=>'💎','label'=>'CoinGecko',      'url'=>'https://coingecko.com'],
                ['icon'=>'💵','label'=>'PayPal',         'url'=>'https://paypal.com'],
                ['icon'=>'🏦','label'=>'Mint',           'url'=>'https://mint.intuit.com'],
                ['icon'=>'📑','label'=>'TurboTax',       'url'=>'https://turbotax.intuit.com'],
            ],
        ],
        'Gaming' => [
            'icon' => '🎮',
            'desc' => 'Steam, Epic, consoles…',
            'items' => [
                ['icon'=>'🎮','label'=>'Steam',          'url'=>'https://store.steampowered.com'],
                ['icon'=>'🟪','label'=>'Epic Games',     'url'=>'https://store.epicgames.com'],
                ['icon'=>'🟢','label'=>'Xbox',           'url'=>'https://xbox.com'],
                ['icon'=>'🟦','label'=>'PlayStation',    'url'=>'https://playstation.com'],
                ['icon'=>'🔴','label'=>'Nintendo',       'url'=>'https://nintendo.com'],
                ['icon'=>'🟣','label'=>'Twitch',         'url'=>'https://twitch.tv'],
                ['icon'=>'⚪','label'=>'GOG',            'url'=>'https://gog.com'],
                ['icon'=>'📊','label'=>'HowLongToBeat',  'url'=>'https://howlongtobeat.com'],
                ['icon'=>'🎯','label'=>'IGN',            'url'=>'https://ign.com'],
                ['icon'=>'🎲','label'=>'itch.io',        'url'=>'https://itch.io'],
            ],
        ],
        'Self-Hosted' => [
            'icon' => '🏠',
            'desc' => 'Plex, *arr, Home Assistant…',
            'items' => [
                ['icon'=>'🎞','label'=>'Plex',           'url'=>'https://app.plex.tv'],
                ['icon'=>'🏡','label'=>'Home Assistant', 'url'=>'http://homeassistant.local:8123'],
                ['icon'=>'📺','label'=>'Sonarr',         'url'=>'http://localhost:8989'],
                ['icon'=>'🎬','label'=>'Radarr',         'url'=>'http://localhost:7878'],
                ['icon'=>'🎵','label'=>'Lidarr',         'url'=>'http://localhost:8686'],
                ['icon'=>'🚦','label'=>'Pi-hole',        'url'=>'http://pi.hole/admin'],
                ['icon'=>'🐳','label'=>'Portainer',      'url'=>'http://localhost:9000'],
                ['icon'=>'☁️','label'=>'Nextcloud',      'url'=>'http://localhost'],
                ['icon'=>'📡','label'=>'Jellyfin',       'url'=>'http://localhost:8096'],
                ['icon'=>'🌊','label'=>'qBittorrent',    'url'=>'http://localhost:8080'],
                ['icon'=>'📦','label'=>'Unraid',         'url'=>'http://tower.local'],
                ['icon'=>'🛡','label'=>'AdGuard Home',   'url'=>'http://localhost:3000'],
            ],
        ],
    ];
}

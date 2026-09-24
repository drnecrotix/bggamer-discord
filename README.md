# BG-GAMER Discord Lobby

Living Gaming Community Hub landing page for `https://bg-gamer.com/discord/`.

The current build keeps the live public Discord integrations, adds a ready backend proxy layer for private Discord API data, and redesigns the page into a darker lobby/broadcast-style layout instead of a generic SaaS landing page.

## Project files

- `index.html`
  Main page structure, sections, modal, and semantic layout.

- `style.css`
  Design system tokens, layout, responsive rules, motion, and component styling.

- `script.js`
  Public Discord fetch logic, live DOM updates, chart rendering, server map interactions, counters, and modal behavior.

- `backend/discord-proxy.mjs`
  Ready Node backend/proxy for:
  - server news feed
  - scheduled events
  - message-based text-channel stats

- `assets/bg-gamer-logo.png`
  Live logo used in navbar, hero branding, footer, and favicon.

- `assets/atlas-guard-shot.svg`
  Atlas Guard visual used in the commands area.

## Current page structure

The page is now built in this order:

1. `Hero / Live Lobby`
2. `Live Community Strip`
3. `Explore the Server`
4. `Live Community Activity`
5. `Server News`
6. `Bot and Commands`
7. `Community Identity`
8. `Final CTA`

## Public Discord data already in use

These sources work without a bot token:

- Invite URL:
  `https://discord.gg/PFkjeKBuxH`

- Invite API:
  `https://discord.com/api/v9/invites/PFkjeKBuxH?with_counts=true`

- Widget API:
  `https://discord.com/api/guilds/114667416247599110/widget.json`

From those public sources, the page currently pulls:

- total members
- online members
- boosts
- boost tier
- public entry channel
- visible widget channels
- active voice room
- active voice count
- currently visible game activity
- online member avatars from the widget
- Discord liveliness bins for the activity chart

## Private Discord data now supported through the backend proxy

The proxy file at `backend/discord-proxy.mjs` is for the Discord data that a static frontend cannot safely or directly read:

- channel news feed
- scheduled server events
- message-based most active text channel

### Supported proxy routes

- `GET /api/discord/health`
- `GET /api/discord/server-news`
- `GET /api/discord/events`
- `GET /api/discord/message-stats`

## Frontend configuration

Edit the top of `script.js`.

```js
const DISCORD_CONFIG = {
  inviteUrl: "https://discord.gg/PFkjeKBuxH",
  inviteCode: "PFkjeKBuxH",
  guildId: "114667416247599110",
  newsChannelId: "506928759509745664",
  rssFeedUrl: "https://bg-gamer.com/category/news/feed/",
  rssFeedLabel: "BG-GAMER.com",
  inviteApiEndpoint: "",
  widgetEndpoint: "",
  eventsEndpoint: "",
  newsEndpoint: "",
  insightsEndpoint: "",
  refreshIntervalMs: 30000
};
```

### What these fields do

- `inviteUrl`
  Used by all Discord CTA buttons.

- `inviteCode`
  Builds the public invite API URL when `inviteApiEndpoint` is empty.

- `guildId`
  Builds the public widget URL when `widgetEndpoint` is empty.

- `newsChannelId`
  Kept as the Discord news-channel reference for optional backend overrides.

- `rssFeedUrl`
  Default source for the `Server News` panel. The current build reads the latest posts from the BG-GAMER website RSS feed.

- `rssFeedLabel`
  Human-readable source label shown in the UI copy for the website feed.

- `inviteApiEndpoint`
  Optional custom override for the public invite profile.

- `widgetEndpoint`
  Optional custom override for the public widget.

- `eventsEndpoint`
  Backend route for scheduled Discord events.

- `newsEndpoint`
  Optional backend override for the server news feed. If left empty, the frontend uses `rssFeedUrl`.

- `insightsEndpoint`
  Backend route for message-based text channel stats.

- `refreshIntervalMs`
  Controls how often the page refreshes live data.

## Recommended production endpoint setup

Once the backend proxy is deployed, set these values in `script.js`:

```js
eventsEndpoint: "/api/discord/events",
insightsEndpoint: "/api/discord/message-stats",
```

`newsEndpoint` is now optional because `Server News` defaults to the BG-GAMER RSS feed.

## Production deployment checklist

If the live page suddenly looks unstyled or partially broken, the most common reason is a mixed deploy where:

- `index.html` is new
- `script.js` is new
- `style.css` is still an older version on the server or behind cache

This project now uses versioned asset URLs in `index.html`:

- `style.css?v=20260714-1`
- `script.js?v=20260714-1`
- `assets/bg-gamer-logo.png?v=20260714-1`

Before publishing, make sure all of these files are uploaded together:

- `index.html`
- `style.css`
- `script.js`
- `assets/bg-gamer-logo.png`
- any new SVG or image assets used by the current layout

If your hosting or CDN caches static files aggressively:

- purge the cache after upload, or
- bump the asset version in `index.html` again

## Backend proxy setup

The backend file uses native Node APIs. No extra framework is required.

### Required environment variable

- `DISCORD_BOT_TOKEN`

### Optional environment variables

- `PORT`
- `DISCORD_GUILD_ID`
- `DISCORD_NEWS_CHANNEL_ID`
- `ALLOWED_ORIGIN`
- `NEWS_LIMIT`
- `EVENTS_LIMIT`
- `MESSAGE_WINDOW`
- `TEXT_CHANNEL_LIMIT`
- `MESSAGE_STATS_CHANNEL_IDS`

### Local run example

```powershell
$env:DISCORD_BOT_TOKEN="YOUR_TOKEN"
node backend/discord-proxy.mjs
```

Default local proxy URL:

- `http://127.0.0.1:8787`

## Most active text channel

The field `Most active text channel` is now intentionally message-based only.

It does **not** guess from visible widget channels.

The proxy returns data in this shape:

```json
{
  "mostActiveTextChannel": "#general",
  "mostActiveTextChannelMessages": 428,
  "topTextChannels": [
    { "id": "123", "name": "#general", "messages": 428 }
  ]
}
```

If `insightsEndpoint` is not connected, the UI shows an honest fallback instead of pretending it knows the answer.

## Server news feed

The `Server News` rail now reads directly from:

- `https://bg-gamer.com/category/news/feed/`

This means the panel can show real site posts without waiting for a Discord proxy.

If you still want Discord-channel messages there instead, set:

```js
newsEndpoint: "/api/discord/server-news?channelId=506928759509745664"
```

When `newsEndpoint` is filled, it overrides the RSS feed.

## Scheduled events

The page still supports scheduled events through `eventsEndpoint`.

When no backend route is connected:

- the UI keeps working
- the page does not break
- the activity section clearly says that the events proxy is needed

## Interactive command system

The command content is controlled in `script.js` by:

- `INTERACTIVE_DETAILS`

These IDs currently exist:

- `verify`
- `roles`
- `ticket`
- `events`
- `serverinfo`
- `server_home`
- `server_voice`

To add another command:

1. Add a trigger element with `data-command-id="your_id"` in `index.html`
2. Add a matching object inside `INTERACTIVE_DETAILS` in `script.js`

## Visual editing guide

### Typography

The project uses:

- `Barlow Condensed` for hero and display headings
- `Manrope` for body copy
- `IBM Plex Mono` for indicators, commands, and live labels

### Color system

The design tokens live at the top of `style.css` in `:root`.

Main tokens:

- `--bg`
- `--bg-elevated`
- `--surface`
- `--surface-strong`
- `--border`
- `--text`
- `--text-soft`
- `--discord-blue`
- `--accent`
- `--live`
- `--warning`

### Replace images

Logo:

- `assets/bg-gamer-logo.png`

Bot visual:

- `assets/atlas-guard-shot.svg`

Replace the files directly or update the image paths in `index.html`.

## Browser verification completed

The redesign was visually checked in a real browser at:

- `1440px`
- `1024px`
- `768px`
- `390px`

Checks completed:

- no JavaScript syntax errors
- no console errors in final frontend state
- no horizontal overflow at `390px`
- responsive layout remains intact
- live public Discord stats still render
- private-data areas fail gracefully until the proxy is connected

## Quick edit checklist

When changing this page later:

1. Update content in `index.html`
2. Keep all color/spacing changes inside the CSS tokens in `style.css`
3. Keep public Discord logic in `script.js`
4. Keep private Discord logic in `backend/discord-proxy.mjs`
5. Re-check the page in a browser at desktop and mobile widths

# BG-GAMER Discord Portal

The public landing page and admin panel live in the Laravel application under `portal/`. See [portal/README.md](portal/README.md) for installation, Discord configuration, FTP deployment, and updates.

For a shared FTP web root, upload the repository root including the hidden `.htaccess`, `index.php`, and `portal/`. Delete old files on the server that an FTP upload does not remove: `index.html`, `script.js`, `backend/preview-server.cjs`, `backend/discord-proxy.mjs`, `assets/atlas-guard-shot.svg`, `assets/partyqueue-shot.svg`, and `bans/assets/bans-ui-v2.css` through `bans-ui-v5.css` plus `bans/assets/bans.css`. Clear any host or CDN page cache after deployment.

The existing `mod/`, `appeal/`, `api/`, and ban webhook endpoints remain available. Their shared `style.css`, `assets/site-nav.js`, `assets/bg-gamer-logo.png`, and active `bans/assets/bans-ui-v6.css` and `bans/assets/bans.js` are still needed.

For FTP installation, download the `bggamer-ftp-install` artifact from the latest successful **Build FTP installer** GitHub Actions run. Extract `bggamer-ftp-install.zip` into the `/discord/` FTP directory, then delete any old `index.html` and clear the host/CDN cache. The archive includes `portal/vendor/` and excludes `.env`, the setup key, and cached Laravel configuration. It is an overlay: keep existing moderation, appeal, and webhook files on the server. Create the private `portal/storage/app/install.key` setup code before opening `/discord/install.php`.

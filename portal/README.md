# BG-GAMER Laravel Portal

A separate Laravel 12 staff portal alongside the existing static lobby and legacy PHP moderation pages. This is an incremental replacement: it does not import bans or appeals yet and must not be pointed at a production database expecting those records to appear automatically.

## Features

- Discord OAuth2 login with session state validation and guild member role verification through the bot.
- Admin, Moderator and Support access based on explicit Discord role IDs, verified at every staff request (60-second cache). No default admin account.
- Dashboard with live guild counts, boost tier, scheduled events and an action journal. Moderator and Admin can edit the portal homepage; Admin can publish announcements. Public ban list reads the existing `discord_bans` table using only public fields.
- Admin-only announcement publisher with validation, rate limiting and disabled Discord mentions.
- Read-only dashboard degrades visibly when Discord API is unavailable.

## Install

Requirements: PHP 8.2+, Composer 2, MySQL 8+ or PostgreSQL, HTTPS, and a Discord application bot already in the guild. Deploy `portal/` outside the public web root and set the subdomain document root to `portal/public`. Never publish `.env`, `vendor`, or the legacy root directory as the portal document root.

```bash
cd portal
composer install --no-dev --optimize-autoloader
cp .env.example .env
php artisan key:generate
# Set APP_URL, database credentials and DISCORD_* values in .env
php artisan migrate --force
php artisan config:cache
```

Set `APP_ENV=production`, `APP_DEBUG=false`, `APP_URL` to the HTTPS portal URL, and `SESSION_SECURE_COOKIE=true`. Configure MySQL or PostgreSQL in `.env`. The default skeleton uses database sessions, cache and queue; migrations create their tables. The bot token needs guild/member read access and Send Messages permission in the selected announcement channel. OAuth2 scopes use `identify`; add the exact redirect URL in the Discord Developer Portal. `DISCORD_ADMIN_ROLE_IDS`, `DISCORD_MODERATOR_ROLE_IDS` and `DISCORD_SUPPORT_ROLE_IDS` accept comma-separated snowflake IDs. Leave no role IDs blank in production. A member must hold a configured role and be in the configured guild. Restart/reload PHP workers after changing environment values.

To cut over, test on a separate subdomain first. The old lobby at `/` and its `/mod`, `/bans`, `/appeal` pages continue working independently on their existing host. The portal homepage editor changes only the Laravel portal homepage, not the legacy static `index.html`. Existing BotGhost/Kremmuna webhooks remain on the legacy PHP endpoints. No credentials or token values belong in git.

## Remaining migration work

The public ban list needs the same MySQL database as the existing legacy ban center, or an explicit safe replication of its `discord_bans` table. The portal does not create or modify that legacy table. Ban and appeal write workflows, BotGhost/Kremmuna ingestion, and the full existing static lobby migration need a separate reviewed cutover. Do not disable the old endpoints until parity and data migration have been tested. Bot integrations in this first stage are Discord REST reads and announcement writes, not a gateway bot process.

## Secrets

The GitHub project contains environment variable names and public Discord identifiers, not usable bot credentials. Keep real values in server environment variables or an untracked `.env` outside the public document root. Never copy credentials from a public commit into production without rotating them. Tracked runtime logs/cache were removed from source control; historical Git commits remain public, so rotate any credentials that were ever committed.

## Server landing page

Admin can set a Discord Server ID and invite code at `/admin/server`. Saving requires the bot to verify the guild before updating the setting. The public `/` page and admin dashboard read this selected guild. The bot token remains in server environment configuration. Public counts come from the Discord guild endpoint and are cached for 60 seconds. The avatar row and visible voice-member count use Discord's public Server Widget; enable **Server Settings → Widget** in Discord if you want these displayed. The widget may expose only a subset of online members, so voice is labelled as a visible count rather than an exact whole-server count. No synthetic statistics are shown when either API is unavailable.

## FTP installation and Owner recovery

Upload the complete `portal/` directory **including Composer `vendor/`** and point the subdomain document root to `portal/public`. Use PHP 8.2+, PDO MySQL/PostgreSQL, Zip, mbstring and HTTPS. Create a fresh, random setup code of at least 32 characters in the private file `portal/storage/app/install.key` (outside the web root), then open `https://YOUR-PORTAL/install.php`. Enter the setup code, DB credentials, Owner email and a 14+ character password. The installer creates `.env`, runs migrations, creates the hashed Owner credential, writes `installed.lock`, removes the setup key and verifies that `public/install.php` was deleted. Installation checks PHP write permission on the public directory before changing `.env` or the database. If filesystem permissions change during setup and deletion fails, the success screen explicitly instructs manual FTP removal; `.env` and the lock block re-entry. The installer returns 404 after `.env` exists, including after a failed migration; inspect logs and repair setup manually before retrying. Never commit `.env` or `install.key`.

Owner can always use `/owner/login` with email and password if Discord is unavailable. After password login, `/owner/settings` can link a Discord account through OAuth. The linked Discord identity can then sign in as Owner without needing a guild staff role. Unlink requires the Owner password. Protect both methods with a strong password and normal server backups. Discord linking uses the same OAuth application callback URL as staff login.

## GitHub CMS updates

Owner-only `/owner/updates` checks the latest GitHub Release for the fixed upstream `drnecrotix/bggamer-discord`. To offer a one-click update, publish an asset named `bggamer-portal.zip` with a GitHub-provided `sha256:` digest. The ZIP must contain paths under `portal/`, including `portal/app/Services/Discord.php` and `portal/routes/web.php`. The updater accepts only code/view files under `app/`, `config/`, `resources/`, `routes/` and `public/`, caps size, rejects symlinks/path traversal, verifies SHA-256, copies overwritten files to private `storage/app/update-backups/` and restores them on a write error. It never touches `.env`, `vendor/`, `storage/`, database migrations or Composer dependencies. Updates requiring schema or dependency changes must use a staged FTP deployment and manual migration after backup. The current repository has no qualifying release asset yet; the page will show that state until one is published.

The admin dashboard checks GitHub automatically with a 15-minute cache and shows a **NEW** badge for Owner when a newer verified release is available. `/owner/updates` also has a manual Check now action. The first code-only release is `v1.0.0`; the release workflow runs when `portal/RELEASE_VERSION` reaches `main`, tests the portal, then publishes the ZIP. The bundled installer version is `0.1.0`; applying the release records `v1.0.0` in the database.

# BG-GAMER Laravel Portal

A separate Laravel 12 staff portal alongside the existing static lobby and legacy PHP moderation pages. This is an incremental replacement: it does not import bans or appeals yet and must not be pointed at a production database expecting those records to appear automatically.

## Features

- Discord OAuth2 login with session state validation and guild member role verification through the bot.
- Admin and Moderator access based on explicit Discord role IDs. No default admin account.
- Dashboard with live guild counts, boost tier, scheduled events and an action journal.
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

Set `APP_ENV=production`, `APP_DEBUG=false`, `APP_URL` to the HTTPS portal URL, and `SESSION_SECURE_COOKIE=true`. Configure MySQL or PostgreSQL in `.env`. The default skeleton uses database sessions, cache and queue; migrations create their tables. The bot token needs guild/member read access and Send Messages permission in the selected announcement channel. OAuth2 scopes use `identify`; add the exact redirect URL in the Discord Developer Portal. `DISCORD_ADMIN_ROLE_IDS` and `DISCORD_MODERATOR_ROLE_IDS` accept comma-separated snowflake IDs. Leave no role IDs blank in production. A member must hold a configured role and be in the configured guild. Restart/reload PHP workers after changing environment values.

To cut over, test on a separate subdomain first. The old lobby at `/` and its `/mod`, `/bans`, `/appeal` pages continue working independently. Existing BotGhost/Kremmuna webhooks remain on the legacy PHP endpoints. No credentials or token values belong in git.

## Remaining migration work

Ban and appeal schemas, BotGhost/Kremmuna ingestion, staff moderation workflows, permissions reconciliation and legacy data migration need a separate reviewed cutover. Do not disable the old endpoints until parity and data migration have been tested. Bot integrations in this first stage are Discord REST reads and announcement writes, not a gateway bot process.

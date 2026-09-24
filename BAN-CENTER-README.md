# BG-GAMER Ban Center

Този пакет добавя отделни публични страници за:

- `/discord/bans/` - публичен списък на активните Discord наказания
- `/discord/appeal/` - protected appeal intake форма

Страниците са изградени така, че да следват визуалния език на съществуващата `/discord/` landing страница, без да я променят.

## Създадени файлове

- `bans/index.php`
- `bans/assets/bans.css`
- `bans/assets/bans.js`
- `bans/api/bans.php`
- `bans/api/sync-bans.php`
- `bans/api/kremmuna-ingest.php`
- `appeal/index.php`
- `appeal/success.php`
- `appeal/assets/appeal.css`
- `appeal/assets/appeal.js`
- `appeal/api/submit-appeal.php`
- `api/register-ban.php`
- `api/check-bot-permissions.php`
- `mod/index.php`
- `mod/login.php`
- `mod/callback.php`
- `mod/logout.php`
- `config/discord-config.php`
- `config/bootstrap.php`
- `config/ban-center.php`
- `database/migrations/create_discord_bans.sql`
- `database/migrations/create_discord_ban_appeals.sql`
- `storage/appeal-uploads/.htaccess`

## Среда и променливи

Конфигурацията се чете от environment variables. Не поставяйте реални tokens, secrets или DB пароли в публични файлове.

### Задължителни

- `BG_GAMER_BASE_PATH=/discord`
- `BG_GAMER_BASE_URL=https://bg-gamer.com/discord`
- `BG_GAMER_RULES_URL=https://bg-gamer.com/discord/`
- `BG_GAMER_DISCORD_BOT_TOKEN=...`
- `BG_GAMER_DISCORD_CLIENT_ID=...`
- `BG_GAMER_DISCORD_CLIENT_SECRET=...`
- `BG_GAMER_DISCORD_GUILD_ID=...`
- `BG_GAMER_DB_HOST=127.0.0.1`
- `BG_GAMER_DB_PORT=3306`
- `BG_GAMER_DB_NAME=bg_gamer`
- `BG_GAMER_DB_USER=...`
- `BG_GAMER_DB_PASS=...`
- `BG_GAMER_SYNC_TOKEN=...`
- `BG_GAMER_KREMMUNA_SECRET=...`
- `BG_GAMER_KREMMUNA_WEBHOOK_SECRET=...`
- `BG_GAMER_APPEAL_WEBHOOK_URL=...`
- `BG_GAMER_TURNSTILE_SITE_KEY=...`
- `BG_GAMER_TURNSTILE_SECRET=...`

### Допълнителни

- `BG_GAMER_TURNSTILE_BYPASS=0`
- `BG_GAMER_ADMIN_REVIEW_URL=https://bg-gamer.com/admin/appeals`
- `BG_GAMER_SYNC_INTERVAL_SECONDS=600`
- `BG_GAMER_KREMMUNA_GUILD_ID=YOUR_DISCORD_GUILD_ID`
- `BG_GAMER_KREMMUNA_SOURCE_LABEL=kremmuna-webhook`
- `BG_GAMER_KREMMUNA_SIGNATURE_HEADER=X-BG-Signature`
- `BG_GAMER_DISCORD_REDIRECT_URI=https://bg-gamer.com/discord/mod/callback.php`
- `BG_GAMER_DISCORD_OAUTH_SCOPES=identify`
- `BG_GAMER_MOD_ALLOWED_USER_IDS=123...,456...`
- `BG_GAMER_MOD_ALLOWED_ROLE_IDS=123...,456...`
- `BG_GAMER_APPEAL_MAX_UPLOAD_BYTES=5242880`
- `BG_GAMER_PUBLIC_REASON_MAX_LENGTH=220`
- `BG_GAMER_BANS_PAGE_SIZE_MAX=6`
- `BG_GAMER_BANS_ROBOTS=`  
  Оставено е празно по подразбиране. Ако решите да включите `noindex,follow`, задайте:
  `BG_GAMER_BANS_ROBOTS=noindex,follow`

## Препоръка за robots

Препоръката е ban list страницата да бъде с `noindex,follow`.

Причини:

- това не е маркетинг страница, а operational публичен регистър
- съдържа moderation контекст, който не носи SEO стойност
- намалява риска от индексиране на Discord usernames в резултати на търсачки

Не съм го активирал твърдо в кода. Оставено е като config toggle чрез `BG_GAMER_BANS_ROBOTS`, за да можете да го включите съзнателно при deployment.

## База данни

Изпълнете последователно:

1. `database/migrations/create_discord_bans.sql`
2. `database/migrations/create_discord_ban_appeals.sql`

### Важно

- `discord_bans.public_reference` е публичният идентификатор за бан записите
- `discord_ban_appeals.public_reference` е appeal номерът от типа `BG-APPEAL-2026-000125`
- `discord_bans.active_discord_user_id` е generated column за unique active ban constraint

## Sync endpoint

Protected endpoint:

- `GET /discord/bans/api/sync-bans.php?token=YOUR_SYNC_TOKEN`

Алтернативно:

- `X-BG-SYNC-TOKEN: YOUR_SYNC_TOKEN`

Какво прави:

- тегли активните банове от Discord
- сравнява ги с `discord_bans`
- добавя липсващи активни записи
- маркира вече премахнатите като `unbanned`
- пази историята
- записва safe sync state в `storage/cache/discord-ban-sync-state.json`

### Препоръчан cron

На всеки 10 минути:

```bash
*/10 * * * * curl -fsS "https://bg-gamer.com/discord/bans/api/sync-bans.php?token=YOUR_SYNC_TOKEN" >/dev/null
```

## Kremmuna realtime ingest

Protected endpoint:

- `POST /discord/bans/api/kremmuna-ingest.php`

Recommended auth:

- `Authorization: Bearer YOUR_KREMMUNA_SECRET`

Fallback headers:

- `X-Kremmuna-Secret: YOUR_KREMMUNA_SECRET`
- `X-BG-KREMMUNA-SECRET: YOUR_KREMMUNA_SECRET`

Purpose:

- inserts new active bans in real time
- updates existing ban data when moderator, reason, or expiry changes
- marks a record as `unbanned`
- updates `appeal_status`
- writes the latest realtime sync snapshot to `storage/cache/discord-ban-sync-state.json`

Recommended setup:

- trigger Kremmuna custom webhook or HTTP request actions on ban events
- trigger a second webhook on unban events
- keep `sync-bans.php` on cron as the fallback reconciliation layer

Supported event types:

- `ban`
- `ban_create`
- `member_ban`
- `member_banned`
- `unban`
- `ban_remove`
- `member_unban`
- `member_unbanned`
- `appeal_status`

Accepted payload aliases include:

- `event`, `event_type`, `type`, `action`
- `guild_id`, `guildId`, `server_id`, `serverId`
- `user.id`, `member.id`, `target.id`, `user_id`
- `public_reference`, `ban_reference`, `reference`
- `reason`, `public_reason`, `private_reason`
- `timestamp`, `created_at`, `banned_at`, `unbanned_at`, `expires_at`

### Example: ban event

```json
{
  "event": "ban",
  "guild_id": "YOUR_GUILD_ID",
  "user": {
    "id": "123456789012345678",
    "username": "PlayerName",
    "global_name": "PlayerName"
  },
  "moderator": {
    "id": "987654321098765432",
    "username": "Atlas Guard"
  },
  "reason": "Repeated hate speech",
  "public_reason": "Repeated abusive language",
  "public_reference": "BG-BAN-7Q3W9K2L",
  "banned_at": "2026-07-14T12:30:00Z",
  "expires_at": null
}
```

### Example: unban event

```json
{
  "event": "unban",
  "guild_id": "YOUR_GUILD_ID",
  "user_id": "123456789012345678",
  "unbanned_at": "2026-07-18T18:20:00Z"
}
```

### Example: appeal status update

```json
{
  "event": "appeal_status",
  "guild_id": "YOUR_GUILD_ID",
  "user_id": "123456789012345678",
  "public_reference": "BG-BAN-7Q3W9K2L",
  "appeal_status": "approved"
}
```

## Immediate integration

Use the protected Discord API sync when you want the current active bans without waiting for Kremmuna events.

- `GET /discord/bans/api/sync-bans.php?token=YOUR_SYNC_TOKEN`
- source: Discord API v10 `/guilds/{guild.id}/bans`
- token usage: server-side only through `BG_GAMER_DISCORD_BOT_TOKEN`
- public safety: the public bans endpoint does not expose `private_reason` or the full Discord ID

## Extended integration

Preferred Kremmuna webhook endpoint:

- `POST /discord/api/register-ban.php`

Accepted fields:

- `user_id`
- `username`
- `moderator_id`
- `moderator_name`
- `reason`
- `duration`
- `banned_at`
- `signature`

Security model:

- uses `BG_GAMER_KREMMUNA_WEBHOOK_SECRET`
- does not use the Discord bot token as webhook secret
- validates an HMAC SHA-256 signature before inserting or updating records

Canonical signature string:

```text
user_id={urlencoded user_id}&username={urlencoded username}&moderator_id={urlencoded moderator_id}&moderator_name={urlencoded moderator_name}&reason={urlencoded reason}&duration={urlencoded duration}&banned_at={urlencoded banned_at}
```

Expected signature:

```text
hash_hmac('sha256', canonical_string, BG_GAMER_KREMMUNA_WEBHOOK_SECRET)
```

Signature transport:

- body field: `signature`
- header override: `X-BG-Signature`
- configurable header name: `BG_GAMER_KREMMUNA_SIGNATURE_HEADER`

Duration handling:

- empty, `permanent`, `perm`, `indefinite`, `forever`, `none` -> permanent active ban
- integer value -> treated as seconds
- compact tokens like `30m`, `12h`, `7d`, `2w`, or mixed values like `1d 12h` -> temporary ban with computed `expires_at`

Example Kremmuna webhook body:

```json
{
  "user_id": "123456789012345678",
  "username": "PlayerName",
  "moderator_id": "987654321098765432",
  "moderator_name": "Atlas Guard",
  "reason": "Repeated abusive language",
  "duration": "7d",
  "banned_at": "2026-07-14T12:30:00Z",
  "signature": "PUT_GENERATED_HMAC_HERE"
}
```

## Bot permission check

Protected diagnostic endpoint:

- `GET /discord/api/check-bot-permissions.php?token=YOUR_SYNC_TOKEN`

What it verifies:

- resolves the current bot identity with Discord API v10
- loads the bot member record in the configured guild
- calculates the effective guild permissions from assigned roles
- returns whether `Ban Members` is granted, including `Administrator` fallback

## Private config loader

The PHP config loader can read secrets from a private file outside the public `/discord/` directory:

- `../bg-gamer-discord.private.php`
- or a custom path from `BG_GAMER_PRIVATE_CONFIG_FILE`

Recommended use:

- keep Discord token, DB password, sync token, and Kremmuna secrets only in that private file
- do not commit the private file to version control

## Discord moderator panel

The `/discord/mod/` page now supports two access modes:

- Discord OAuth moderator login
- signed quick-action links for `panel`, `unban`, and `reject`

### Moderator login

The persistent moderator dashboard uses:

- `BG_GAMER_DISCORD_CLIENT_ID`
- `BG_GAMER_DISCORD_CLIENT_SECRET`
- `BG_GAMER_DISCORD_REDIRECT_URI`

Access is granted when one of the following is true:

- the logged-in Discord user ID is listed in `BG_GAMER_MOD_ALLOWED_USER_IDS`
- one of the member role IDs is listed in `BG_GAMER_MOD_ALLOWED_ROLE_IDS`
- the member has a guild role with `Administrator` or `Ban Members`

### Runtime routing overrides

The moderator panel can change the live appeal notification channel without editing PHP files.

Runtime overrides are stored server-side in:

- `storage/config/runtime-overrides.json`

Current usage:

- `appeals.discord_channel_id`

The panel also includes:

- bot test message delivery to a chosen channel ID
- webhook test delivery to the configured appeal webhook URL

## Публичен API

Endpoint:

- `GET /discord/bans/api/bans.php`

Поддържани query параметри:

- `page`
- `per_page`
- `q`
- `status`
- `date_from`
- `date_to`
- `reference`

### Примери

```text
/discord/bans/api/bans.php?page=2&status=temporary
/discord/bans/api/bans.php?q=toxicity
/discord/bans/api/bans.php?reference=BG-BAN-AB12CD34EF
```

## Appeal submit flow

Submit endpoint:

- `POST /discord/appeal/api/submit-appeal.php`

Проверки:

- CSRF token
- honeypot field
- Turnstile validation
- file MIME validation
- file size limit
- per-IP request rate limit
- server-side lookup на `ban_reference`
- server-side validation на `discord_user_id`

След успешен submit:

- записва ред в `discord_ban_appeals`
- генерира `BG-APPEAL-YYYY-NNNNNN`
- обновява `discord_bans.appeal_status = pending`
- изпраща webhook към private moderation channel
- redirect към `/discord/appeal/success.php?ref=...`

## Discord bot права

Необходимият bot token трябва да може да чете:

- guild bans
- audit logs

Без audit log достъп:

- синхронизацията ще продължи да работи
- но част от старите `banned_at` полета ще останат празни
- UI ще показва `Дата: Не е налична`

## Сигурност

Включени защити:

- config/database/storage access deny чрез `.htaccess`
- upload directory hardening и забрана за PHP execution
- masked Discord IDs в публичния UI/API
- sanitized public reasons чрез `sanitizePublicReason()`
- без private reason, mod ID, internal notes или tokens в публичния JSON
- CSP, X-Content-Type-Options, Referrer-Policy, COOP, Permissions-Policy

## Front-end поведение

`/discord/bans/` включва:

- live fetch към bans API
- loading skeleton
- empty state
- no-search-results state
- API error state с retry
- pagination без reload
- URL sync, например `/discord/bans/?page=2`
- keyboard navigation със стрелки
- swipe navigation на mobile
- reduced motion fallback

`/discord/appeal/` включва:

- server-rendered case summary при `?ban=PUBLIC_REFERENCE`
- textarea counters
- file name preview
- submit pending state

## Какво да проверите след upload

1. Отворете `/discord/bans/` и проверете дали списъкът се зарежда.
2. Пробвайте `?page=2`.
3. Пробвайте search, status filter и date range.
4. Пуснете sync endpoint ръчно с token.
5. Отворете `/discord/appeal/?ban=PUBLIC_REFERENCE`.
6. Подайте test appeal с Turnstile и файл.
7. Проверете moderation webhook съобщението.
8. Потвърдете, че реалният bot token не присъства в HTML, JS или JSON отговорите.

## Локално ограничение в тази среда

В текущата Codex среда `php` binary не е наличен, така че PHP syntax check не можа да бъде изпълнен локално тук.  
JavaScript файловете могат да бъдат проверени отделно с `node --check` след промени.

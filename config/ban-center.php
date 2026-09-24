<?php

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

function bg_default_public_reason(): string
{
    return 'Нарушаване на правилата на BG-GAMER';
}

function bg_build_discord_avatar_url(array $user, int $size = 128): ?string
{
    $userId = (string) ($user['id'] ?? '');
    $avatar = (string) ($user['avatar'] ?? '');

    if ($userId === '' || $avatar === '') {
        return null;
    }

    return sprintf(
        'https://cdn.discordapp.com/avatars/%s/%s.png?size=%d',
        rawurlencode($userId),
        rawurlencode($avatar),
        $size
    );
}

function bg_discord_snowflake_datetime(?string $snowflake): ?DateTimeImmutable
{
    if ($snowflake === null || preg_match('/^\d+$/', $snowflake) !== 1) {
        return null;
    }

    $milliseconds = ((int) $snowflake >> 22) + 1420070400000;
    $seconds = intdiv($milliseconds, 1000);

    return (new DateTimeImmutable('@' . $seconds))
        ->setTimezone(new DateTimeZone('Europe/Sofia'));
}

function bg_month_name(int $month): string
{
    return match ($month) {
        1 => 'януари',
        2 => 'февруари',
        3 => 'март',
        4 => 'април',
        5 => 'май',
        6 => 'юни',
        7 => 'юли',
        8 => 'август',
        9 => 'септември',
        10 => 'октомври',
        11 => 'ноември',
        12 => 'декември',
        default => '',
    };
}

function bg_format_public_datetime(?string $value): string
{
    if ($value === null || trim($value) === '') {
        return 'Дата: Не е налична';
    }

    try {
        $date = new DateTimeImmutable($value, new DateTimeZone('Europe/Sofia'));
        $date = $date->setTimezone(new DateTimeZone('Europe/Sofia'));
    } catch (Throwable) {
        return 'Дата: Не е налична';
    }

    return sprintf(
        'Дата: %d %s %s г., %s ч.',
        (int) $date->format('j'),
        bg_month_name((int) $date->format('n')),
        $date->format('Y'),
        $date->format('H:i')
    );
}

function bg_validate_date_filter(?string $value): ?string
{
    $candidate = trim((string) $value);

    if ($candidate === '') {
        return null;
    }

    $date = DateTimeImmutable::createFromFormat('Y-m-d', $candidate);

    if ($date === false || $date->format('Y-m-d') !== $candidate) {
        return null;
    }

    return $candidate;
}

function bg_avatar_initials(string $primary, ?string $secondary = null): string
{
    $source = trim($secondary ?? '') !== '' ? trim((string) $secondary) : trim($primary);

    if ($source === '') {
        return 'BG';
    }

    $parts = preg_split('/\s+/u', $source) ?: [];
    $letters = '';

    foreach ($parts as $part) {
        if ($part === '') {
            continue;
        }

        $letters .= mb_substr($part, 0, 1, 'UTF-8');

        if (mb_strlen($letters, 'UTF-8') >= 2) {
            break;
        }
    }

    if ($letters === '') {
        $letters = mb_substr($source, 0, 2, 'UTF-8');
    }

    return mb_strtoupper($letters, 'UTF-8');
}

function bg_normalize_ban_for_public(array $row): array
{
    $username = (string) ($row['username'] ?? 'Unknown user');
    $displayName = trim((string) ($row['global_name'] ?? ''));
    $publicReference = (string) ($row['public_reference'] ?? '');

    return [
        'public_reference' => $publicReference,
        'username' => $username,
        'display_name' => $displayName,
        'avatar_url' => (string) ($row['avatar_url'] ?? ''),
        'avatar_initials' => bg_avatar_initials($username, $displayName),
        'masked_discord_id' => bg_mask_discord_id((string) ($row['discord_user_id'] ?? '')),
        'public_reason' => sanitizePublicReason((string) ($row['public_reason'] ?? '')),
        'banned_at' => $row['banned_at'] ?? null,
        'banned_at_display' => bg_format_public_datetime($row['banned_at'] ?? null),
        'expires_at' => $row['expires_at'] ?? null,
        'status' => (string) ($row['status'] ?? 'active'),
        'status_label' => bg_status_label((string) ($row['status'] ?? 'active')),
        'appeal_status' => (string) ($row['appeal_status'] ?? 'not_requested'),
        'appeal_status_label' => bg_appeal_status_label((string) ($row['appeal_status'] ?? 'not_requested')),
        'appeal_url' => bg_public_url('appeal/?ban=' . rawurlencode($publicReference)),
    ];
}

function bg_sync_state_path(): string
{
    return bg_storage_path('cache/discord-ban-sync-state.json');
}

function bg_get_sync_state(): array
{
    $path = bg_sync_state_path();

    if (!is_file($path)) {
        return [];
    }

    $decoded = json_decode((string) file_get_contents($path), true);
    return is_array($decoded) ? $decoded : [];
}

function bg_set_sync_state(array $payload): void
{
    file_put_contents(
        bg_sync_state_path(),
        json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT),
        LOCK_EX
    );
}

function bg_fetch_current_discord_bans(string $guildId): array
{
    $allBans = [];
    $before = null;

    do {
        $query = [
            'limit' => 1000,
        ];

        if ($before !== null) {
            $query['before'] = $before;
        }

        $response = bg_discord_request(sprintf('/guilds/%s/bans', rawurlencode($guildId)), $query);

        if (!is_array($response)) {
            break;
        }

        $batch = array_values(array_filter($response, 'is_array'));
        $allBans = array_merge($allBans, $batch);

        if (count($batch) < 1000) {
            break;
        }

        $last = end($batch);
        $before = is_array($last) ? (string) (($last['user']['id'] ?? '') ?: '') : null;
    } while ($before !== null && $before !== '');

    return $allBans;
}

function bg_fetch_recent_ban_audit_map(string $guildId, int $limit = 100): array
{
    $response = bg_discord_request(sprintf('/guilds/%s/audit-logs', rawurlencode($guildId)), [
        'action_type' => 22,
        'limit' => $limit,
    ]);

    $entries = $response['audit_log_entries'] ?? [];
    $users = [];

    foreach (($response['users'] ?? []) as $user) {
        if (is_array($user) && isset($user['id'])) {
            $users[(string) $user['id']] = $user;
        }
    }

    $map = [];

    foreach ($entries as $entry) {
        if (!is_array($entry)) {
            continue;
        }

        $targetId = (string) ($entry['target_id'] ?? '');

        if ($targetId === '') {
            continue;
        }

        $moderatorId = (string) ($entry['user_id'] ?? '');
        $moderator = $users[$moderatorId] ?? [];
        $timestamp = bg_discord_snowflake_datetime((string) ($entry['id'] ?? ''));

        $map[$targetId] = [
            'banned_at' => $timestamp?->format('Y-m-d H:i:s'),
            'moderator_discord_id' => $moderatorId !== '' ? $moderatorId : null,
            'moderator_name' => (string) (($moderator['global_name'] ?? $moderator['username'] ?? '') ?: ''),
            'private_reason' => trim((string) ($entry['reason'] ?? '')) ?: null,
        ];
    }

    return $map;
}

function bg_excerpt_text(string $value, int $length = 260): string
{
    $normalized = trim(preg_replace('/\s+/u', ' ', $value) ?? '');

    if ($normalized === '') {
        return '';
    }

    if (mb_strlen($normalized, 'UTF-8') <= $length) {
        return $normalized;
    }

    return rtrim(mb_substr($normalized, 0, $length - 1, 'UTF-8')) . '…';
}

function bg_send_discord_webhook(array $payload): void
{
    $webhookUrl = bg_effective_webhook_url();

    if ($webhookUrl === '') {
        return;
    }

    $response = bg_http_request(
        $webhookUrl,
        'POST',
        [
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
        ],
        json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
    );

    if ($response['status'] >= 400) {
        throw new RuntimeException('Webhook delivery failed with HTTP ' . $response['status']);
    }
}

function bg_send_discord_channel_message(array $payload, ?string $channelId = null): void
{
    $targetChannelId = trim($channelId ?? bg_effective_appeal_channel_id());

    if ($targetChannelId === '') {
        throw new RuntimeException('Appeal Discord channel id is not configured.');
    }

    if (preg_match('/^\d{15,21}$/', $targetChannelId) !== 1) {
        throw new RuntimeException('Appeal Discord channel id is invalid.');
    }

    bg_discord_api_request(
        'POST',
        sprintf('/channels/%s/messages', rawurlencode($targetChannelId)),
        [],
        $payload
    );
}

<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config/ban-center.php';

bg_require_method('POST');

try {
    $payload = bg_botghost_read_payload();
    bg_botghost_require_secret($payload);

    $event = bg_botghost_normalize_event($payload);
    $allowedGuildId = trim((string) bg_config('botghost.guild_id', ''));

    if ($allowedGuildId !== '' && $event['guild_id'] !== null && $event['guild_id'] !== $allowedGuildId) {
        throw new RuntimeException('guild_mismatch', 403);
    }

    $pdo = bg_pdo();
    $pdo->beginTransaction();

    $result = match ($event['kind']) {
        'ban' => bg_botghost_upsert_ban($pdo, $event),
        'unban' => bg_botghost_mark_unbanned($pdo, $event),
        'appeal_status' => bg_botghost_update_appeal_status($pdo, $event),
        default => throw new RuntimeException('unsupported_event', 422),
    };

    $activeCount = (int) $pdo
        ->query("SELECT COUNT(*) FROM discord_bans WHERE status IN ('active', 'temporary')")
        ->fetchColumn();

    $pdo->commit();

    $syncState = [
        'synced_at' => gmdate('c'),
        'active_count' => $activeCount,
        'added' => $result['action'] === 'created' ? 1 : 0,
        'updated' => $result['action'] === 'updated' || $result['action'] === 'appeal_status_updated' ? 1 : 0,
        'unbanned' => $result['action'] === 'unbanned' ? 1 : 0,
        'source' => (string) bg_config('botghost.source_label', 'kremmuna-webhook'),
        'last_event_type' => $event['event_type'],
        'last_public_reference' => $result['public_reference'] ?? null,
    ];

    bg_set_sync_state($syncState);

    bg_log_event('botghost-ingest', [
        'event_type' => $event['event_type'],
        'kind' => $event['kind'],
        'action' => $result['action'],
        'public_reference' => $result['public_reference'] ?? null,
        'masked_discord_id' => isset($event['discord_user_id']) ? bg_mask_discord_id($event['discord_user_id']) : null,
    ]);

    bg_json([
        'ok' => true,
        'event' => [
            'type' => $event['event_type'],
            'kind' => $event['kind'],
        ],
        'result' => $result,
        'sync' => $syncState,
    ]);
} catch (Throwable $throwable) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }

    $statusCode = (int) $throwable->getCode();

    if ($statusCode < 400 || $statusCode > 599) {
        $statusCode = 500;
    }

    bg_log_event('botghost-ingest-errors', [
        'message' => $throwable->getMessage(),
        'status_code' => $statusCode,
        'file' => basename($throwable->getFile()),
        'line' => $throwable->getLine(),
    ]);

    if ($statusCode >= 500) {
        $error = bg_public_exception_payload($throwable, 'botghost_ingest_failed', 503);
        bg_json($error['payload'], $error['status']);
    }

    bg_json([
        'ok' => false,
        'error' => $throwable->getMessage(),
    ], $statusCode);
}

function bg_botghost_read_payload(): array
{
    $rawBody = trim((string) file_get_contents('php://input'));

    if ($rawBody !== '') {
        $decoded = json_decode($rawBody, true);

        if (is_array($decoded)) {
            return $decoded;
        }
    }

    if ($_POST !== []) {
        return $_POST;
    }

    throw new RuntimeException('empty_payload', 422);
}

function bg_botghost_header(string $name): ?string
{
    $serverKey = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
    $value = $_SERVER[$serverKey] ?? null;

    if (!is_string($value)) {
        return null;
    }

    $value = trim($value);
    return $value === '' ? null : $value;
}

function bg_botghost_require_secret(array $payload): void
{
    $expected = trim((string) bg_config('botghost.secret', ''));

    if ($expected === '') {
        throw new RuntimeException('botghost_not_configured', 503);
    }

    $authorization = bg_botghost_header('Authorization');
    $bearerToken = null;

    if ($authorization !== null && preg_match('/^Bearer\s+(.+)$/i', $authorization, $matches) === 1) {
        $bearerToken = trim($matches[1]);
    }

    $provided = $bearerToken
        ?? bg_botghost_header('X-Kremmuna-Secret')
        ?? bg_botghost_header('X-BG-KREMMUNA-SECRET')
        ?? bg_botghost_header('X-BotGhost-Secret')
        ?? bg_botghost_header('X-BG-BOTGHOST-SECRET')
        ?? (isset($_GET['token']) ? trim((string) $_GET['token']) : null)
        ?? bg_botghost_string($payload, ['token', 'secret', 'webhook_secret']);

    if ($provided === null || !hash_equals($expected, $provided)) {
        throw new RuntimeException('unauthorized', 401);
    }
}

function bg_botghost_string(array $payload, array $paths, ?string $default = null): ?string
{
    $value = bg_botghost_value($payload, $paths, $default);

    if (!is_string($value) && !is_numeric($value)) {
        return $default;
    }

    $normalized = trim((string) $value);
    return $normalized === '' ? $default : $normalized;
}

function bg_botghost_value(array $payload, array $paths, mixed $default = null): mixed
{
    foreach ($paths as $path) {
        $segments = is_array($path) ? $path : explode('.', (string) $path);
        $cursor = $payload;
        $found = true;

        foreach ($segments as $segment) {
            if (!is_array($cursor) || !array_key_exists($segment, $cursor)) {
                $found = false;
                break;
            }

            $cursor = $cursor[$segment];
        }

        if ($found) {
            return $cursor;
        }
    }

    return $default;
}

function bg_botghost_to_sql_datetime(mixed $value): ?string
{
    if ($value === null || $value === '') {
        return null;
    }

    if (is_numeric($value)) {
        $timestamp = (int) $value;

        if ($timestamp > 9999999999) {
            $timestamp = (int) floor($timestamp / 1000);
        }

        return (new DateTimeImmutable('@' . $timestamp))
            ->setTimezone(new DateTimeZone('Europe/Sofia'))
            ->format('Y-m-d H:i:s');
    }

    try {
        return (new DateTimeImmutable((string) $value))
            ->setTimezone(new DateTimeZone('Europe/Sofia'))
            ->format('Y-m-d H:i:s');
    } catch (Throwable) {
        return null;
    }
}

function bg_botghost_normalize_appeal_status(?string $status): ?string
{
    if ($status === null || trim($status) === '') {
        return null;
    }

    $normalized = strtolower(str_replace([' ', '-'], '_', trim($status)));

    return match ($normalized) {
        'not_requested', 'none' => 'not_requested',
        'pending', 'queued' => 'pending',
        'under_review', 'in_review', 'reviewing' => 'under_review',
        'information_requested', 'needs_info', 'more_info' => 'information_requested',
        'approved', 'accepted' => 'approved',
        'rejected', 'denied' => 'rejected',
        'closed', 'resolved' => 'closed',
        default => null,
    };
}

function bg_botghost_event_kind(string $eventType, ?string $appealStatus): string
{
    if ($appealStatus !== null || str_contains($eventType, 'appeal')) {
        return 'appeal_status';
    }

    foreach (['unban', 'ban_remove', 'ban_removed', 'ban_delete', 'ban_deleted', 'member_ban_remove', 'lifted'] as $token) {
        if (str_contains($eventType, $token)) {
            return 'unban';
        }
    }

    return 'ban';
}

function bg_botghost_normalize_event(array $payload): array
{
    $eventTypeRaw = bg_botghost_string($payload, [
        'event',
        'event_type',
        'type',
        'action',
        'trigger',
        'meta.event',
    ], 'ban.created');

    $eventType = strtolower(str_replace([' ', '-'], ['_', '_'], (string) $eventTypeRaw));
    $discordUserId = bg_botghost_string($payload, [
        'discord_user_id',
        'user_id',
        'userId',
        'target_id',
        'target.id',
        'member.id',
        'user.id',
    ]);

    $username = bg_botghost_string($payload, [
        'username',
        'member.username',
        'target.username',
        'user.username',
    ], 'Unknown user');

    $globalName = bg_botghost_string($payload, [
        'global_name',
        'display_name',
        'member.global_name',
        'member.display_name',
        'target.global_name',
        'user.global_name',
        'user.display_name',
    ]);

    $avatarHash = bg_botghost_string($payload, [
        'avatar',
        'avatar_hash',
        'member.avatar',
        'target.avatar',
        'user.avatar',
    ]);

    $avatarUrl = bg_botghost_string($payload, [
        'avatar_url',
        'member.avatar_url',
        'target.avatar_url',
        'user.avatar_url',
    ]);

    if ($avatarUrl === null && $discordUserId !== null && $avatarHash !== null) {
        $avatarUrl = sprintf(
            'https://cdn.discordapp.com/avatars/%s/%s.png?size=128',
            rawurlencode($discordUserId),
            rawurlencode($avatarHash)
        );
    }

    $privateReason = bg_botghost_string($payload, [
        'private_reason',
        'moderator_reason',
        'reason',
        'ban_reason',
        'details.reason',
    ]);

    $publicReason = bg_botghost_string($payload, [
        'public_reason',
        'reason_public',
        'display_reason',
    ], $privateReason);

    $appealStatus = bg_botghost_normalize_appeal_status(bg_botghost_string($payload, [
        'appeal_status',
        'appeal.status',
        'review_status',
    ]));

    $status = strtolower((string) bg_botghost_string($payload, [
        'status',
        'ban_status',
    ], bg_botghost_string($payload, ['expires_at', 'expiresAt']) !== null ? 'temporary' : 'active'));

    if (!in_array($status, ['active', 'temporary', 'expired', 'unbanned'], true)) {
        $status = 'active';
    }

    $kind = bg_botghost_event_kind($eventType, $appealStatus);

    return [
        'event_type' => $eventType,
        'kind' => $kind,
        'guild_id' => bg_botghost_string($payload, ['guild_id', 'guildId', 'server_id', 'serverId']),
        'public_reference' => bg_botghost_string($payload, ['public_reference', 'ban_reference', 'reference']),
        'discord_user_id' => $discordUserId,
        'username' => $username,
        'global_name' => $globalName,
        'avatar_url' => $avatarUrl,
        'public_reason' => sanitizePublicReason($publicReason),
        'private_reason' => $privateReason,
        'moderator_discord_id' => bg_botghost_string($payload, [
            'moderator_discord_id',
            'moderator_id',
            'moderator.id',
        ]),
        'moderator_name' => bg_botghost_string($payload, [
            'moderator_name',
            'moderator.username',
            'moderator.global_name',
        ]),
        'banned_at' => bg_botghost_to_sql_datetime(bg_botghost_value($payload, [
            'banned_at',
            'created_at',
            'occurred_at',
            'event_time',
            'timestamp',
        ])),
        'unbanned_at' => bg_botghost_to_sql_datetime(bg_botghost_value($payload, [
            'unbanned_at',
            'removed_at',
            'resolved_at',
            'timestamp',
        ])),
        'expires_at' => bg_botghost_to_sql_datetime(bg_botghost_value($payload, [
            'expires_at',
            'expiry_at',
            'timeout_until',
        ])),
        'status' => $kind === 'unban' ? 'unbanned' : $status,
        'appeal_status' => $appealStatus,
    ];
}

function bg_botghost_upsert_ban(PDO $pdo, array $event): array
{
    if (($event['discord_user_id'] ?? null) === null) {
        throw new RuntimeException('missing_user_id', 422);
    }

    $statement = $pdo->prepare(
        "SELECT id, public_reference
         FROM discord_bans
         WHERE discord_user_id = :discord_user_id
           AND status IN ('active', 'temporary')
         ORDER BY created_at DESC
         LIMIT 1"
    );
    $statement->execute([
        'discord_user_id' => $event['discord_user_id'],
    ]);
    $existing = $statement->fetch();

    if (is_array($existing)) {
        $pdo->prepare(
            'UPDATE discord_bans
             SET username = :username,
                 global_name = :global_name,
                 avatar_url = :avatar_url,
                 public_reason = :public_reason,
                 private_reason = :private_reason,
                 moderator_discord_id = :moderator_discord_id,
                 moderator_name = :moderator_name,
                 banned_at = COALESCE(banned_at, :banned_at),
                 expires_at = :expires_at,
                 status = :status,
                 appeal_status = COALESCE(:appeal_status, appeal_status),
                 updated_at = CURRENT_TIMESTAMP
             WHERE id = :id'
        )->execute([
            'id' => (int) $existing['id'],
            'username' => $event['username'],
            'global_name' => $event['global_name'],
            'avatar_url' => $event['avatar_url'],
            'public_reason' => $event['public_reason'],
            'private_reason' => $event['private_reason'],
            'moderator_discord_id' => $event['moderator_discord_id'],
            'moderator_name' => $event['moderator_name'],
            'banned_at' => $event['banned_at'],
            'expires_at' => $event['expires_at'],
            'status' => $event['status'] === 'temporary' ? 'temporary' : 'active',
            'appeal_status' => $event['appeal_status'],
        ]);

        return [
            'action' => 'updated',
            'public_reference' => (string) $existing['public_reference'],
        ];
    }

    $publicReference = $event['public_reference'] ?? bg_generate_public_reference();

    $pdo->prepare(
        'INSERT INTO discord_bans (
            public_reference,
            discord_user_id,
            username,
            global_name,
            avatar_url,
            public_reason,
            private_reason,
            moderator_discord_id,
            moderator_name,
            banned_at,
            expires_at,
            status,
            appeal_status
        ) VALUES (
            :public_reference,
            :discord_user_id,
            :username,
            :global_name,
            :avatar_url,
            :public_reason,
            :private_reason,
            :moderator_discord_id,
            :moderator_name,
            :banned_at,
            :expires_at,
            :status,
            :appeal_status
        )'
    )->execute([
        'public_reference' => $publicReference,
        'discord_user_id' => $event['discord_user_id'],
        'username' => $event['username'],
        'global_name' => $event['global_name'],
        'avatar_url' => $event['avatar_url'],
        'public_reason' => $event['public_reason'],
        'private_reason' => $event['private_reason'],
        'moderator_discord_id' => $event['moderator_discord_id'],
        'moderator_name' => $event['moderator_name'],
        'banned_at' => $event['banned_at'],
        'expires_at' => $event['expires_at'],
        'status' => $event['status'] === 'temporary' ? 'temporary' : 'active',
        'appeal_status' => $event['appeal_status'] ?? 'not_requested',
    ]);

    return [
        'action' => 'created',
        'public_reference' => $publicReference,
    ];
}

function bg_botghost_mark_unbanned(PDO $pdo, array $event): array
{
    if (($event['public_reference'] ?? null) !== null) {
        $statement = $pdo->prepare(
            "SELECT id, public_reference
             FROM discord_bans
             WHERE public_reference = :public_reference
               AND status IN ('active', 'temporary')
             ORDER BY created_at DESC
             LIMIT 1"
        );
        $statement->execute([
            'public_reference' => $event['public_reference'],
        ]);
    } elseif (($event['discord_user_id'] ?? null) !== null) {
        $statement = $pdo->prepare(
            "SELECT id, public_reference
             FROM discord_bans
             WHERE discord_user_id = :discord_user_id
               AND status IN ('active', 'temporary')
             ORDER BY created_at DESC
             LIMIT 1"
        );
        $statement->execute([
            'discord_user_id' => $event['discord_user_id'],
        ]);
    } else {
        throw new RuntimeException('missing_lookup_key', 422);
    }

    $record = $statement->fetch();

    if (!is_array($record)) {
        return [
            'action' => 'ignored',
            'reason' => 'active_ban_not_found',
        ];
    }

    $pdo->prepare(
        "UPDATE discord_bans
         SET status = 'unbanned',
             unbanned_at = :unbanned_at,
             updated_at = CURRENT_TIMESTAMP
         WHERE id = :id"
    )->execute([
        'id' => (int) $record['id'],
        'unbanned_at' => $event['unbanned_at'] ?? (new DateTimeImmutable('now', new DateTimeZone('Europe/Sofia')))->format('Y-m-d H:i:s'),
    ]);

    return [
        'action' => 'unbanned',
        'public_reference' => (string) $record['public_reference'],
    ];
}

function bg_botghost_update_appeal_status(PDO $pdo, array $event): array
{
    if (($event['appeal_status'] ?? null) === null) {
        throw new RuntimeException('missing_appeal_status', 422);
    }

    if (($event['public_reference'] ?? null) !== null) {
        $statement = $pdo->prepare(
            "SELECT id, public_reference
             FROM discord_bans
             WHERE public_reference = :public_reference
             ORDER BY created_at DESC
             LIMIT 1"
        );
        $statement->execute([
            'public_reference' => $event['public_reference'],
        ]);
    } elseif (($event['discord_user_id'] ?? null) !== null) {
        $statement = $pdo->prepare(
            "SELECT id, public_reference
             FROM discord_bans
             WHERE discord_user_id = :discord_user_id
             ORDER BY created_at DESC
             LIMIT 1"
        );
        $statement->execute([
            'discord_user_id' => $event['discord_user_id'],
        ]);
    } else {
        throw new RuntimeException('missing_lookup_key', 422);
    }

    $record = $statement->fetch();

    if (!is_array($record)) {
        return [
            'action' => 'ignored',
            'reason' => 'ban_record_not_found',
        ];
    }

    $pdo->prepare(
        'UPDATE discord_bans
         SET appeal_status = :appeal_status,
             updated_at = CURRENT_TIMESTAMP
         WHERE id = :id'
    )->execute([
        'id' => (int) $record['id'],
        'appeal_status' => $event['appeal_status'],
    ]);

    return [
        'action' => 'appeal_status_updated',
        'public_reference' => (string) $record['public_reference'],
        'appeal_status' => $event['appeal_status'],
    ];
}

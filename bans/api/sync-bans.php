<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config/ban-center.php';

bg_require_method(['GET', 'POST']);
bg_require_sync_token();

$lockHandle = null;

try {
    $guildId = (string) bg_config('discord.guild_id', '');

    if ($guildId === '') {
        throw new RuntimeException('Discord guild is not configured.');
    }

    $lockHandle = bg_acquire_lock('discord-ban-sync');
    bg_rate_limit_guard('discord-ban-sync', (int) bg_config('limits.sync_interval_seconds', 600));

    $discordBans = bg_fetch_current_discord_bans($guildId);
    $auditMap = [];

    try {
        $auditMap = bg_fetch_recent_ban_audit_map($guildId);
    } catch (Throwable $throwable) {
        bg_log_event('ban-sync-audit-warnings', [
            'message' => $throwable->getMessage(),
            'file' => basename($throwable->getFile()),
            'line' => $throwable->getLine(),
        ]);
    }

    $pdo = bg_pdo();
    $pdo->beginTransaction();

    $activeStatement = $pdo->query(
        "SELECT id, discord_user_id, public_reference, banned_at
         FROM discord_bans
         WHERE status IN ('active', 'temporary')"
    );

    $existingActive = [];

    foreach ($activeStatement->fetchAll() as $row) {
        $existingActive[(string) $row['discord_user_id']] = $row;
    }

    $insertStatement = $pdo->prepare(
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
            :status,
            :appeal_status
        )'
    );

    $updateStatement = $pdo->prepare(
        'UPDATE discord_bans
         SET username = :username,
             global_name = :global_name,
             avatar_url = :avatar_url,
             public_reason = :public_reason,
             private_reason = :private_reason,
             moderator_discord_id = :moderator_discord_id,
             moderator_name = :moderator_name,
             banned_at = COALESCE(banned_at, :banned_at),
             status = :status,
             updated_at = CURRENT_TIMESTAMP
         WHERE id = :id'
    );

    $markUnbannedStatement = $pdo->prepare(
        "UPDATE discord_bans
         SET status = 'unbanned',
             unbanned_at = CURRENT_TIMESTAMP,
             updated_at = CURRENT_TIMESTAMP
         WHERE id = :id"
    );

    $seenDiscordIds = [];
    $addedCount = 0;
    $updatedCount = 0;

    foreach ($discordBans as $discordBan) {
        if (!is_array($discordBan) || !isset($discordBan['user']) || !is_array($discordBan['user'])) {
            continue;
        }

        $user = $discordBan['user'];
        $discordUserId = (string) ($user['id'] ?? '');

        if ($discordUserId === '') {
            continue;
        }

        $seenDiscordIds[$discordUserId] = true;
        $auditEntry = $auditMap[$discordUserId] ?? [];
        $privateReason = trim((string) ($discordBan['reason'] ?? '')) ?: (string) ($auditEntry['private_reason'] ?? '');

        $payload = [
            'username' => (string) ($user['username'] ?? 'Unknown user'),
            'global_name' => trim((string) ($user['global_name'] ?? '')) ?: null,
            'avatar_url' => bg_build_discord_avatar_url($user),
            'public_reason' => sanitizePublicReason($privateReason),
            'private_reason' => $privateReason !== '' ? $privateReason : null,
            'moderator_discord_id' => $auditEntry['moderator_discord_id'] ?? null,
            'moderator_name' => trim((string) ($auditEntry['moderator_name'] ?? '')) ?: null,
            'banned_at' => $auditEntry['banned_at'] ?? null,
            'status' => 'active',
            'appeal_status' => 'not_requested',
        ];

        if (isset($existingActive[$discordUserId])) {
            $updateStatement->execute([
                'id' => (int) $existingActive[$discordUserId]['id'],
                'username' => $payload['username'],
                'global_name' => $payload['global_name'],
                'avatar_url' => $payload['avatar_url'],
                'public_reason' => $payload['public_reason'],
                'private_reason' => $payload['private_reason'],
                'moderator_discord_id' => $payload['moderator_discord_id'],
                'moderator_name' => $payload['moderator_name'],
                'banned_at' => $payload['banned_at'],
                'status' => $payload['status'],
            ]);
            $updatedCount++;
            continue;
        }

        $insertStatement->execute([
            'public_reference' => bg_generate_public_reference(),
            'discord_user_id' => $discordUserId,
            'username' => $payload['username'],
            'global_name' => $payload['global_name'],
            'avatar_url' => $payload['avatar_url'],
            'public_reason' => $payload['public_reason'],
            'private_reason' => $payload['private_reason'],
            'moderator_discord_id' => $payload['moderator_discord_id'],
            'moderator_name' => $payload['moderator_name'],
            'banned_at' => $payload['banned_at'],
            'status' => $payload['status'],
            'appeal_status' => $payload['appeal_status'],
        ]);
        $addedCount++;
    }

    $unbannedCount = 0;

    foreach ($existingActive as $discordUserId => $row) {
        if (isset($seenDiscordIds[$discordUserId])) {
            continue;
        }

        $markUnbannedStatement->execute([
            'id' => (int) $row['id'],
        ]);
        $unbannedCount++;
    }

    $pdo->commit();

    $syncState = [
        'synced_at' => gmdate('c'),
        'active_count' => count($seenDiscordIds),
        'added' => $addedCount,
        'updated' => $updatedCount,
        'unbanned' => $unbannedCount,
        'source' => 'discord-guild-ban-list',
    ];

    bg_set_sync_state($syncState);
    bg_log_event('ban-sync', $syncState);

    bg_json([
        'ok' => true,
        'sync' => $syncState,
    ]);
} catch (Throwable $throwable) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }

    bg_log_event('ban-sync-errors', [
        'message' => $throwable->getMessage(),
        'file' => basename($throwable->getFile()),
        'line' => $throwable->getLine(),
    ]);

    $error = bg_public_exception_payload($throwable, 'sync_failed', 503);
    bg_json($error['payload'], $error['status']);
} finally {
    if ($lockHandle !== null) {
        bg_release_lock($lockHandle);
    }
}

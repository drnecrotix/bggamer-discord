<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/config/ban-center.php';

bg_require_method(['GET', 'POST']);
bg_require_sync_token();

try {
    $guildId = trim((string) bg_config('discord.guild_id', ''));

    if ($guildId === '') {
        throw new RuntimeException('guild_not_configured', 503);
    }

    $botUser = bg_discord_request('/users/@me');
    $botUserId = (string) ($botUser['id'] ?? '');

    if ($botUserId === '') {
        throw new RuntimeException('bot_identity_unavailable', 502);
    }

    $member = bg_discord_request(sprintf('/guilds/%s/members/%s', rawurlencode($guildId), rawurlencode($botUserId)));
    $rolesResponse = bg_discord_request(sprintf('/guilds/%s/roles', rawurlencode($guildId)));

    $memberRoleIds = array_map('strval', (array) ($member['roles'] ?? []));
    $grantedRoles = [];
    $permissionValue = 0;

    foreach ($rolesResponse as $role) {
        if (!is_array($role)) {
            continue;
        }

        $roleId = (string) ($role['id'] ?? '');

        if ($roleId === '') {
            continue;
        }

        $isGranted = $roleId === $guildId || in_array($roleId, $memberRoleIds, true);

        if (!$isGranted) {
            continue;
        }

        $permissionValue |= (int) ($role['permissions'] ?? 0);
        $grantedRoles[] = [
            'id' => $roleId,
            'name' => (string) ($role['name'] ?? 'unknown'),
        ];
    }

    $hasAdministrator = ($permissionValue & 0x8) === 0x8;
    $hasBanMembers = $hasAdministrator || (($permissionValue & 0x4) === 0x4);

    bg_json([
        'ok' => true,
        'bot' => [
            'id' => $botUserId,
            'username' => (string) ($botUser['username'] ?? 'unknown'),
            'global_name' => (string) ($botUser['global_name'] ?? ''),
        ],
        'guild_id' => $guildId,
        'permissions' => [
            'raw' => (string) $permissionValue,
            'administrator' => $hasAdministrator,
            'ban_members' => $hasBanMembers,
        ],
        'roles' => $grantedRoles,
    ]);
} catch (Throwable $throwable) {
    $statusCode = (int) $throwable->getCode();

    if ($statusCode < 400 || $statusCode > 599) {
        $statusCode = 500;
    }

    bg_log_event('bot-permission-check-errors', [
        'message' => $throwable->getMessage(),
        'status_code' => $statusCode,
        'file' => basename($throwable->getFile()),
        'line' => $throwable->getLine(),
    ]);

    bg_json([
        'ok' => false,
        'error' => $statusCode >= 500 ? 'permission_check_failed' : $throwable->getMessage(),
    ], $statusCode);
}

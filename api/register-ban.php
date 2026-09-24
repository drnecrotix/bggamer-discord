<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/config/ban-center.php';

bg_require_method('POST');

try {
    $payload = bg_register_ban_read_payload();
    bg_register_ban_verify_signature($payload);

    $event = bg_register_ban_normalize_event($payload);
    $allowedGuildId = trim((string) bg_config('botghost.guild_id', ''));

    if ($allowedGuildId !== '' && $event['guild_id'] !== null && $event['guild_id'] !== $allowedGuildId) {
        throw new RuntimeException('guild_mismatch', 403);
    }

    $pdo = bg_pdo();
    $pdo->beginTransaction();

    $result = bg_register_ban_upsert($pdo, $event);
    $activeCount = (int) $pdo
        ->query("SELECT COUNT(*) FROM discord_bans WHERE status IN ('active', 'temporary')")
        ->fetchColumn();

    $pdo->commit();

    $syncState = [
        'synced_at' => gmdate('c'),
        'active_count' => $activeCount,
        'added' => $result['action'] === 'created' ? 1 : 0,
        'updated' => $result['action'] === 'updated' ? 1 : 0,
        'unbanned' => 0,
        'source' => 'kremmuna-register-ban',
        'last_public_reference' => $result['public_reference'] ?? null,
    ];

    bg_set_sync_state($syncState);

    bg_log_event('register-ban', [
        'action' => $result['action'],
        'public_reference' => $result['public_reference'] ?? null,
        'masked_discord_id' => bg_mask_discord_id($event['discord_user_id']),
        'status' => $event['status'],
    ]);

    bg_json([
        'ok' => true,
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

    bg_log_event('register-ban-errors', [
        'message' => $throwable->getMessage(),
        'status_code' => $statusCode,
        'file' => basename($throwable->getFile()),
        'line' => $throwable->getLine(),
    ]);

    if ($statusCode >= 500) {
        $error = bg_public_exception_payload($throwable, 'register_ban_failed', 503);
        bg_json($error['payload'], $error['status']);
    }

    bg_json([
        'ok' => false,
        'error' => $throwable->getMessage(),
    ], $statusCode);
}

function bg_register_ban_read_payload(): array
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

function bg_register_ban_header(string $name): ?string
{
    $serverKey = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
    $value = $_SERVER[$serverKey] ?? null;

    if (!is_string($value)) {
        return null;
    }

    $value = trim($value);
    return $value === '' ? null : $value;
}

function bg_register_ban_string(array $payload, string $key, ?string $default = null): ?string
{
    $value = $payload[$key] ?? $default;

    if (!is_string($value) && !is_numeric($value)) {
        return $default;
    }

    $normalized = trim((string) $value);
    return $normalized === '' ? $default : $normalized;
}

function bg_register_ban_canonical_payload(array $payload): string
{
    $fields = [
        'user_id',
        'username',
        'moderator_id',
        'moderator_name',
        'reason',
        'duration',
        'banned_at',
    ];

    $pairs = [];

    foreach ($fields as $field) {
        $pairs[] = $field . '=' . rawurlencode((string) bg_register_ban_string($payload, $field, ''));
    }

    return implode('&', $pairs);
}

function bg_register_ban_normalize_signature(string $signature): string
{
    $normalized = strtolower(trim($signature));

    if (str_starts_with($normalized, 'sha256=')) {
        $normalized = substr($normalized, 7);
    }

    return $normalized;
}

function bg_register_ban_verify_signature(array $payload): void
{
    $secret = trim((string) bg_config('botghost.webhook_secret', ''));

    if ($secret === '') {
        throw new RuntimeException('webhook_not_configured', 503);
    }

    $signatureHeader = trim((string) bg_config('botghost.signature_header', 'X-BG-Signature'));
    $providedSignature = bg_register_ban_header($signatureHeader)
        ?? bg_register_ban_header('X-BG-Signature')
        ?? bg_register_ban_header('X-Signature')
        ?? bg_register_ban_string($payload, 'signature');

    if ($providedSignature === null) {
        throw new RuntimeException('missing_signature', 401);
    }

    $expectedSignature = hash_hmac('sha256', bg_register_ban_canonical_payload($payload), $secret);
    $providedSignature = bg_register_ban_normalize_signature($providedSignature);

    if (!hash_equals($expectedSignature, $providedSignature)) {
        throw new RuntimeException('invalid_signature', 401);
    }
}

function bg_register_ban_to_sql_datetime(string $value): string
{
    try {
        return (new DateTimeImmutable($value))
            ->setTimezone(new DateTimeZone('Europe/Sofia'))
            ->format('Y-m-d H:i:s');
    } catch (Throwable) {
        throw new RuntimeException('invalid_datetime', 422);
    }
}

function bg_register_ban_parse_duration(?string $duration, string $bannedAtSql): array
{
    if ($duration === null) {
        return [
            'status' => 'active',
            'expires_at' => null,
        ];
    }

    $normalized = strtolower(trim($duration));

    if ($normalized === '' || in_array($normalized, ['permanent', 'perm', 'indefinite', 'forever', 'none'], true)) {
        return [
            'status' => 'active',
            'expires_at' => null,
        ];
    }

    $totalSeconds = 0;

    if (preg_match('/^\d+$/', $normalized) === 1) {
        $totalSeconds = (int) $normalized;
    } else {
        if (preg_match_all('/(\d+)\s*([smhdw])/i', $normalized, $matches, PREG_SET_ORDER) === 0) {
            throw new RuntimeException('invalid_duration', 422);
        }

        $unmatched = preg_replace('/(\d+)\s*([smhdw])/i', '', $normalized);

        if ($unmatched === null || trim($unmatched) !== '') {
            throw new RuntimeException('invalid_duration', 422);
        }

        $unitMap = [
            's' => 1,
            'm' => 60,
            'h' => 3600,
            'd' => 86400,
            'w' => 604800,
        ];

        foreach ($matches as $match) {
            $amount = (int) $match[1];
            $unit = strtolower($match[2]);
            $totalSeconds += $amount * $unitMap[$unit];
        }
    }

    if ($totalSeconds <= 0) {
        return [
            'status' => 'active',
            'expires_at' => null,
        ];
    }

    $expiresAt = (new DateTimeImmutable($bannedAtSql, new DateTimeZone('Europe/Sofia')))
        ->modify('+' . $totalSeconds . ' seconds')
        ->format('Y-m-d H:i:s');

    return [
        'status' => 'temporary',
        'expires_at' => $expiresAt,
    ];
}

function bg_register_ban_normalize_event(array $payload): array
{
    $discordUserId = bg_register_ban_string($payload, 'user_id');
    $username = bg_register_ban_string($payload, 'username');
    $reason = bg_register_ban_string($payload, 'reason', bg_default_public_reason());
    $bannedAt = bg_register_ban_string($payload, 'banned_at');

    if ($discordUserId === null || preg_match('/^\d+$/', $discordUserId) !== 1) {
        throw new RuntimeException('invalid_user_id', 422);
    }

    if ($username === null) {
        throw new RuntimeException('missing_username', 422);
    }

    if ($bannedAt === null) {
        throw new RuntimeException('missing_banned_at', 422);
    }

    $bannedAtSql = bg_register_ban_to_sql_datetime($bannedAt);
    $duration = bg_register_ban_string($payload, 'duration');
    $timing = bg_register_ban_parse_duration($duration, $bannedAtSql);
    $moderatorId = bg_register_ban_string($payload, 'moderator_id');

    if ($moderatorId !== null && preg_match('/^\d+$/', $moderatorId) !== 1) {
        throw new RuntimeException('invalid_moderator_id', 422);
    }

    return [
        'guild_id' => bg_register_ban_string($payload, 'guild_id'),
        'discord_user_id' => $discordUserId,
        'username' => $username,
        'avatar_url' => null,
        'public_reason' => sanitizePublicReason($reason),
        'private_reason' => $reason,
        'moderator_discord_id' => $moderatorId,
        'moderator_name' => bg_register_ban_string($payload, 'moderator_name'),
        'banned_at' => $bannedAtSql,
        'expires_at' => $timing['expires_at'],
        'status' => $timing['status'],
    ];
}

function bg_register_ban_upsert(PDO $pdo, array $event): array
{
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
                 avatar_url = COALESCE(:avatar_url, avatar_url),
                 public_reason = :public_reason,
                 private_reason = :private_reason,
                 moderator_discord_id = :moderator_discord_id,
                 moderator_name = :moderator_name,
                 banned_at = COALESCE(banned_at, :banned_at),
                 expires_at = :expires_at,
                 status = :status,
                 unbanned_at = NULL,
                 updated_at = CURRENT_TIMESTAMP
             WHERE id = :id'
        )->execute([
            'id' => (int) $existing['id'],
            'username' => $event['username'],
            'avatar_url' => $event['avatar_url'],
            'public_reason' => $event['public_reason'],
            'private_reason' => $event['private_reason'],
            'moderator_discord_id' => $event['moderator_discord_id'],
            'moderator_name' => $event['moderator_name'],
            'banned_at' => $event['banned_at'],
            'expires_at' => $event['expires_at'],
            'status' => $event['status'],
        ]);

        return [
            'action' => 'updated',
            'public_reference' => (string) $existing['public_reference'],
        ];
    }

    $publicReference = bg_generate_public_reference();

    $pdo->prepare(
        'INSERT INTO discord_bans (
            public_reference,
            discord_user_id,
            username,
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
        'avatar_url' => $event['avatar_url'],
        'public_reason' => $event['public_reason'],
        'private_reason' => $event['private_reason'],
        'moderator_discord_id' => $event['moderator_discord_id'],
        'moderator_name' => $event['moderator_name'],
        'banned_at' => $event['banned_at'],
        'expires_at' => $event['expires_at'],
        'status' => $event['status'],
        'appeal_status' => 'not_requested',
    ]);

    return [
        'action' => 'created',
        'public_reference' => $publicReference,
    ];
}

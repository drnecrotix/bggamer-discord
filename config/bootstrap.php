<?php

declare(strict_types=1);

if (!defined('BG_GAMER_ROOT')) {
    define('BG_GAMER_ROOT', dirname(__DIR__));
}

if (PHP_SAPI !== 'cli' && session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params([
        'httponly' => true,
        'samesite' => 'Lax',
        'secure' => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
    ]);
    session_name('bg_gamer_ban_center');
    session_start();
}

$GLOBALS['bg_gamer_config'] = require __DIR__ . '/discord-config.php';

function bg_config(?string $key = null, mixed $default = null): mixed
{
    $config = $GLOBALS['bg_gamer_config'] ?? [];

    if ($key === null || $key === '') {
        return $config;
    }

    $segments = explode('.', $key);
    $value = $config;

    foreach ($segments as $segment) {
        if (!is_array($value) || !array_key_exists($segment, $value)) {
            return $default;
        }

        $value = $value[$segment];
    }

    return $value;
}

function bg_root_path(string $path = ''): string
{
    $normalized = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $path);
    return rtrim(BG_GAMER_ROOT . DIRECTORY_SEPARATOR . ltrim($normalized, DIRECTORY_SEPARATOR), DIRECTORY_SEPARATOR);
}

function bg_storage_path(string $path = ''): string
{
    $fullPath = bg_root_path('storage' . ($path !== '' ? DIRECTORY_SEPARATOR . $path : ''));
    $directory = is_dir($fullPath) ? $fullPath : dirname($fullPath);

    if (!is_dir($directory)) {
        mkdir($directory, 0775, true);
    }

    return $fullPath;
}

function bg_runtime_settings_path(): string
{
    return bg_storage_path('config/runtime-overrides.json');
}

function bg_runtime_settings(): array
{
    $settings = $GLOBALS['bg_runtime_settings_cache'] ?? null;

    if (is_array($settings)) {
        return $settings;
    }

    $path = bg_runtime_settings_path();

    if (!is_file($path)) {
        $GLOBALS['bg_runtime_settings_cache'] = [];
        return [];
    }

    $decoded = json_decode((string) file_get_contents($path), true);
    $GLOBALS['bg_runtime_settings_cache'] = is_array($decoded) ? $decoded : [];

    return $GLOBALS['bg_runtime_settings_cache'];
}

function bg_array_path_get(array $source, string $path, mixed $default = null): mixed
{
    $segments = explode('.', $path);
    $value = $source;

    foreach ($segments as $segment) {
        if (!is_array($value) || !array_key_exists($segment, $value)) {
            return $default;
        }

        $value = $value[$segment];
    }

    return $value;
}

function bg_array_path_set(array &$target, string $path, mixed $value): void
{
    $segments = explode('.', $path);
    $cursor = &$target;

    foreach ($segments as $index => $segment) {
        if ($index === count($segments) - 1) {
            $cursor[$segment] = $value;
            return;
        }

        if (!isset($cursor[$segment]) || !is_array($cursor[$segment])) {
            $cursor[$segment] = [];
        }

        $cursor = &$cursor[$segment];
    }
}

function bg_runtime_setting(string $path, mixed $default = null): mixed
{
    return bg_array_path_get(bg_runtime_settings(), $path, $default);
}

function bg_effective_appeal_channel_id(): string
{
    $override = trim((string) bg_runtime_setting('appeals.discord_channel_id', ''));

    if ($override !== '') {
        return $override;
    }

    return trim((string) bg_config('appeals.discord_channel_id', ''));
}

function bg_effective_webhook_url(): string
{
    $override = trim((string) bg_runtime_setting('security.webhook_url', ''));

    if ($override !== '') {
        return $override;
    }

    return trim((string) bg_config('security.webhook_url', ''));
}

function bg_effective_mod_log_channel_id(): string
{
    $override = trim((string) bg_runtime_setting('moderation.log_channel_id', ''));

    if ($override !== '') {
        return $override;
    }

    return trim((string) bg_config('moderation.log_channel_id', ''));
}

function bg_set_runtime_setting(string $path, mixed $value): void
{
    $settings = bg_runtime_settings();
    bg_array_path_set($settings, $path, $value);

    $pathToFile = bg_runtime_settings_path();
    $directory = dirname($pathToFile);

    if (!is_dir($directory)) {
        mkdir($directory, 0775, true);
    }

    file_put_contents(
        $pathToFile,
        json_encode($settings, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT),
        LOCK_EX
    );
    $GLOBALS['bg_runtime_settings_cache'] = $settings;
}

function bg_public_base_path(): string
{
    $basePath = (string) bg_config('app.base_path', '/discord');
    return $basePath === '' ? '' : rtrim($basePath, '/');
}

function bg_public_url(string $path = ''): string
{
    $baseUrl = (string) bg_config('app.base_url', '');
    $normalizedPath = ltrim($path, '/');

    if ($baseUrl !== '') {
        return rtrim($baseUrl, '/') . ($normalizedPath !== '' ? '/' . $normalizedPath : '');
    }

    $basePath = bg_public_base_path();
    return $basePath . ($normalizedPath !== '' ? '/' . $normalizedPath : '/');
}

function bg_discord_channel_url(string $channelId, ?string $guildId = null): string
{
    $targetGuildId = trim($guildId ?? (string) bg_config('discord.guild_id', ''));
    $targetChannelId = trim($channelId);

    if ($targetGuildId === '' || $targetChannelId === '') {
        return bg_public_url();
    }

    return sprintf(
        'https://discord.com/channels/%s/%s',
        rawurlencode($targetGuildId),
        rawurlencode($targetChannelId)
    );
}

function bg_discord_invite_url(): string
{
    $inviteCode = trim((string) bg_config('discord.invite_code', ''));

    if ($inviteCode === '') {
        return 'https://discord.gg/PFkjeKBuxH';
    }

    return 'https://discord.gg/' . rawurlencode($inviteCode);
}

function bg_escape(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function bg_send_security_headers(bool $json = false): void
{
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('X-Frame-Options: SAMEORIGIN');
    header('Cross-Origin-Opener-Policy: same-origin');
    header("Permissions-Policy: camera=(), geolocation=(), microphone=()");

    if ($json) {
        header("Content-Security-Policy: default-src 'none'; base-uri 'none'; form-action 'none'; frame-ancestors 'none'");
        header('Content-Type: application/json; charset=UTF-8');
        header('Cache-Control: no-store, max-age=0');
        return;
    }

    header('Content-Type: text/html; charset=UTF-8');
}

function bg_send_page_security_headers(bool $allowTurnstile = false): void
{
    bg_send_security_headers(false);

    $scriptSources = [
        "'self'",
        'https://cdn.jsdelivr.net',
    ];

    $connectSources = [
        "'self'",
        'https://bg-gamer.com',
        'https://discord.com',
        'https://cdn.discordapp.com',
        'https://media.discordapp.net',
        'https://images-ext-1.discordapp.net',
    ];

    $frameSources = [
        "'self'",
    ];

    if ($allowTurnstile) {
        $scriptSources[] = 'https://challenges.cloudflare.com';
        $connectSources[] = 'https://challenges.cloudflare.com';
        $frameSources[] = 'https://challenges.cloudflare.com';
    }

    $policy = sprintf(
        "default-src 'self'; base-uri 'self'; form-action 'self'; object-src 'none'; frame-ancestors 'self'; img-src 'self' https://cdn.discordapp.com https://media.discordapp.net https://images-ext-1.discordapp.net data:; style-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net https://fonts.googleapis.com; font-src 'self' https://fonts.gstatic.com data:; script-src %s; connect-src %s; frame-src %s; upgrade-insecure-requests",
        implode(' ', $scriptSources),
        implode(' ', $connectSources),
        implode(' ', $frameSources)
    );

    header('Content-Security-Policy: ' . $policy);
}

function bg_json(array $payload, int $statusCode = 200): never
{
    http_response_code($statusCode);
    bg_send_security_headers(true);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function bg_debug_errors_enabled(): bool
{
    return (bool) bg_config('debug.expose_errors', false);
}

function bg_public_exception_payload(
    Throwable $throwable,
    string $defaultError = 'service_unavailable',
    int $defaultStatus = 503
): array {
    $message = strtolower(trim($throwable->getMessage()));
    $error = $defaultError;
    $status = $defaultStatus;

    if ($message !== '') {
        if (
            str_contains($message, 'base table or view not found')
            || str_contains($message, 'doesn\'t exist')
            || str_contains($message, 'sqlstate[42s02]')
        ) {
            $error = 'database_schema_missing';
        } elseif (
            str_contains($message, 'access denied')
            || str_contains($message, 'connection refused')
            || str_contains($message, 'unknown database')
            || str_contains($message, 'could not find driver')
            || str_contains($message, 'no such host')
            || str_contains($message, 'getaddrinfo')
            || str_contains($message, 'sqlstate[hy000] [1045]')
            || str_contains($message, 'sqlstate[hy000] [2002]')
        ) {
            $error = 'database_unavailable';
        } elseif (
            str_contains($message, 'not configured')
            || str_contains($message, 'missing_')
            || str_contains($message, 'guild_mismatch')
            || str_contains($message, 'unauthorized')
        ) {
            $error = 'config_incomplete';
        } elseif (
            str_contains($message, 'permission denied')
            || str_contains($message, 'failed to open stream')
            || str_contains($message, 'unable to create lock')
            || str_contains($message, 'неуспешно създаване на lock файл')
        ) {
            $error = 'storage_unavailable';
        }
    }

    $payload = [
        'ok' => false,
        'error' => $error,
    ];

    if (bg_debug_errors_enabled()) {
        $payload['debug'] = [
            'message' => $throwable->getMessage(),
            'file' => basename($throwable->getFile()),
            'line' => $throwable->getLine(),
        ];
    }

    return [
        'status' => $status,
        'payload' => $payload,
    ];
}

function bg_require_method(array|string $allowedMethods): void
{
    $allowed = array_map('strtoupper', (array) $allowedMethods);
    $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');

    if (!in_array($method, $allowed, true)) {
        bg_json([
            'ok' => false,
            'error' => 'method_not_allowed',
        ], 405);
    }
}

function bg_pdo(): PDO
{
    static $pdo = null;

    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $database = bg_config('database');
    $dsn = sprintf(
        'mysql:host=%s;port=%d;dbname=%s;charset=%s',
        $database['host'],
        $database['port'],
        $database['name'],
        $database['charset']
    );

    $pdo = new PDO(
        $dsn,
        (string) $database['user'],
        (string) $database['pass'],
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]
    );

    return $pdo;
}

function bg_mask_discord_id(?string $discordId): string
{
    $value = preg_replace('/\D+/', '', (string) $discordId);

    if ($value === null || $value === '') {
        return '••••••••0000';
    }

    $lastFour = substr($value, -4);
    return '••••••••' . $lastFour;
}

function sanitizePublicReason(?string $reason): string
{
    $fallback = 'Нарушаване на правилата на BG-GAMER';
    $value = trim((string) $reason);

    if ($value === '') {
        return $fallback;
    }

    $normalized = preg_replace('/\s+/u', ' ', $value);

    if ($normalized === null || $normalized === '') {
        return $fallback;
    }

    $sensitivePatterns = [
        '/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/iu',
        '/(?:\+?\d[\d\s().-]{7,}\d)/u',
        '/\b(?:\d{1,3}\.){3}\d{1,3}\b/u',
        '/\b(?:[0-9a-f]{1,4}:){2,7}[0-9a-f]{1,4}\b/iu',
        '/https?:\/\/\S+/iu',
        '/www\.\S+/iu',
        '/discord(?:app)?\.com\/users\/\S+/iu',
        '/(?:ул\.|улица|street|st\.|address|адрес)\s+\S+/iu',
        '/(?:internal|mod note|moderator note|вътрешна бележка|evidence|доказателство)/iu',
    ];

    foreach ($sensitivePatterns as $pattern) {
        if (preg_match($pattern, $normalized) === 1) {
            return $fallback;
        }
    }

    $maxLength = (int) bg_config('limits.public_reason_max_length', 220);
    $sanitized = mb_substr($normalized, 0, $maxLength, 'UTF-8');
    return $sanitized === '' ? $fallback : $sanitized;
}

function bg_generate_public_reference(string $prefix = 'BG-BAN', int $randomBytes = 5): string
{
    return sprintf('%s-%s', $prefix, strtoupper(bin2hex(random_bytes($randomBytes))));
}

function bg_create_appeal_reference(int $appealId, ?DateTimeImmutable $submittedAt = null): string
{
    $submittedAt ??= new DateTimeImmutable('now');
    return sprintf('BG-APPEAL-%s-%06d', $submittedAt->format('Y'), $appealId);
}

function bg_status_label(string $status): string
{
    return match ($status) {
        'active' => 'Активен бан',
        'temporary' => 'Временен бан',
        'expired' => 'Изтекъл',
        'unbanned' => 'Премахнат',
        default => 'Неизвестен статус',
    };
}

function bg_appeal_status_label(string $status): string
{
    return match ($status) {
        'not_requested' => 'Няма подадено обжалване',
        'pending' => 'Чака преглед',
        'under_review' => 'В процес на преглед',
        'information_requested' => 'Нужна е информация',
        'approved' => 'Одобрено',
        'rejected' => 'Отхвърлено',
        'closed' => 'Затворено',
        default => 'Няма информация',
    };
}

function bg_log_event(string $channel, array $context = []): void
{
    $payload = [
        'timestamp' => gmdate('c'),
        'channel' => $channel,
        'context' => $context,
    ];

    $line = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    file_put_contents(bg_storage_path('logs/' . $channel . '.log'), $line, FILE_APPEND | LOCK_EX);
}

function bg_csrf_token(string $form = 'default'): string
{
    $namespace = (string) bg_config('security.csrf_namespace', 'bg_gamer_ban_center');
    $sessionKey = $namespace . '_csrf_tokens';

    if (!isset($_SESSION[$sessionKey]) || !is_array($_SESSION[$sessionKey])) {
        $_SESSION[$sessionKey] = [];
    }

    if (!isset($_SESSION[$sessionKey][$form])) {
        $_SESSION[$sessionKey][$form] = bin2hex(random_bytes(32));
    }

    return (string) $_SESSION[$sessionKey][$form];
}

function bg_verify_csrf(string $submittedToken, string $form = 'default'): bool
{
    $token = bg_csrf_token($form);
    return hash_equals($token, $submittedToken);
}

function bg_honeypot_hit(string $fieldName = 'website'): bool
{
    return trim((string) ($_POST[$fieldName] ?? '')) !== '';
}

function bg_http_request(
    string $url,
    string $method = 'GET',
    array $headers = [],
    ?string $body = null,
    int $timeout = 20
): array {
    $normalizedHeaders = [];

    foreach ($headers as $name => $value) {
        if (is_int($name)) {
            $normalizedHeaders[] = (string) $value;
            continue;
        }

        $normalizedHeaders[] = $name . ': ' . $value;
    }

    if (function_exists('curl_init')) {
        $handle = curl_init($url);
        curl_setopt_array($handle, [
            CURLOPT_CUSTOMREQUEST => strtoupper($method),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $normalizedHeaders,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_HEADER => true,
        ]);

        if ($body !== null) {
            curl_setopt($handle, CURLOPT_POSTFIELDS, $body);
        }

        $response = curl_exec($handle);
        $headerLength = (int) curl_getinfo($handle, CURLINFO_HEADER_SIZE);
        $statusCode = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        $error = curl_error($handle);
        curl_close($handle);

        if ($response === false) {
            throw new RuntimeException($error !== '' ? $error : 'HTTP request failed');
        }

        return [
            'status' => $statusCode,
            'headers' => substr($response, 0, $headerLength),
            'body' => substr($response, $headerLength),
        ];
    }

    $context = stream_context_create([
        'http' => [
            'method' => strtoupper($method),
            'header' => implode("\r\n", $normalizedHeaders),
            'content' => $body ?? '',
            'timeout' => $timeout,
            'ignore_errors' => true,
        ],
    ]);

    $responseBody = @file_get_contents($url, false, $context);

    if ($responseBody === false) {
        throw new RuntimeException('HTTP request failed');
    }

    $metaData = $http_response_header ?? [];
    $statusLine = $metaData[0] ?? 'HTTP/1.1 500';
    preg_match('/\s(\d{3})\s/', $statusLine, $matches);

    return [
        'status' => isset($matches[1]) ? (int) $matches[1] : 500,
        'headers' => implode("\n", $metaData),
        'body' => $responseBody,
    ];
}

function bg_discord_api_request(
    string $method,
    string $path,
    array $query = [],
    array|string|null $body = null,
    bool $allowEmptyResponse = false,
    array $extraHeaders = []
): array
{
    $token = (string) bg_config('discord.bot_token', '');
    $apiBase = rtrim((string) bg_config('discord.api_base', 'https://discord.com/api/v10'), '/');

    if ($token === '') {
        throw new RuntimeException('Discord bot token is not configured.');
    }

    $url = $apiBase . '/' . ltrim($path, '/');

    if ($query !== []) {
        $url .= '?' . http_build_query($query);
    }

    $payload = $body;
    $headers = [
        'Authorization' => 'Bot ' . $token,
        'Accept' => 'application/json',
        'User-Agent' => 'BG-GAMER-Ban-Center/1.0',
    ];

    if (is_array($payload)) {
        $payload = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        if ($payload === false) {
            throw new RuntimeException('Discord API payload encoding failed.');
        }

        $headers['Content-Type'] = 'application/json';
    }

    foreach ($extraHeaders as $name => $value) {
        if (!is_string($name) || $name === '') {
            continue;
        }

        $headers[$name] = (string) $value;
    }

    $response = bg_http_request($url, strtoupper($method), $headers, $payload);

    $responseBody = trim($response['body']);

    if ($response['status'] >= 400) {
        throw new RuntimeException('Discord API returned HTTP ' . $response['status']);
    }

    if ($responseBody === '') {
        if ($allowEmptyResponse) {
            return [];
        }

        throw new RuntimeException('Discord API returned an empty response body');
    }

    $decoded = json_decode($responseBody, true);

    if (!is_array($decoded)) {
        throw new RuntimeException('Discord API returned invalid JSON');
    }

    return $decoded;
}

function bg_discord_request(string $path, array $query = []): array
{
    return bg_discord_api_request('GET', $path, $query);
}

function bg_discord_unban_member(string $guildId, string $userId): void
{
    bg_discord_api_request(
        'DELETE',
        sprintf(
            '/guilds/%s/bans/%s',
            rawurlencode($guildId),
            rawurlencode($userId)
        ),
        [],
        null,
        true
    );
}

function bg_discord_ban_member(
    string $guildId,
    string $userId,
    string $reason = '',
    int $deleteMessageSeconds = 0
): void {
    $query = [];
    $normalizedDeleteWindow = max(0, min($deleteMessageSeconds, 604800));

    if ($normalizedDeleteWindow > 0) {
        $query['delete_message_seconds'] = $normalizedDeleteWindow;
    }

    $headers = [];
    $normalizedReason = trim($reason);

    if ($normalizedReason !== '') {
        $headers['X-Audit-Log-Reason'] = rawurlencode(mb_substr($normalizedReason, 0, 480, 'UTF-8'));
    }

    bg_discord_api_request(
        'PUT',
        sprintf(
            '/guilds/%s/bans/%s',
            rawurlencode($guildId),
            rawurlencode($userId)
        ),
        $query,
        null,
        true,
        $headers
    );
}

function bg_discord_kick_member(string $guildId, string $userId, string $reason = ''): void
{
    $headers = [];
    $normalizedReason = trim($reason);

    if ($normalizedReason !== '') {
        $headers['X-Audit-Log-Reason'] = rawurlencode(mb_substr($normalizedReason, 0, 480, 'UTF-8'));
    }

    bg_discord_api_request(
        'DELETE',
        sprintf(
            '/guilds/%s/members/%s',
            rawurlencode($guildId),
            rawurlencode($userId)
        ),
        [],
        null,
        true,
        $headers
    );
}

function bg_discord_timeout_member(
    string $guildId,
    string $userId,
    DateTimeImmutable $until,
    string $reason = ''
): array {
    $headers = [];
    $normalizedReason = trim($reason);

    if ($normalizedReason !== '') {
        $headers['X-Audit-Log-Reason'] = rawurlencode(mb_substr($normalizedReason, 0, 480, 'UTF-8'));
    }

    return bg_discord_api_request(
        'PATCH',
        sprintf(
            '/guilds/%s/members/%s',
            rawurlencode($guildId),
            rawurlencode($userId)
        ),
        [],
        [
            'communication_disabled_until' => $until
                ->setTimezone(new DateTimeZone('UTC'))
                ->format('Y-m-d\TH:i:s.000\Z'),
        ],
        false,
        $headers
    );
}

function bg_discord_remove_member_timeout(string $guildId, string $userId, string $reason = ''): array
{
    $headers = [];
    $normalizedReason = trim($reason);

    if ($normalizedReason !== '') {
        $headers['X-Audit-Log-Reason'] = rawurlencode(mb_substr($normalizedReason, 0, 480, 'UTF-8'));
    }

    return bg_discord_api_request(
        'PATCH',
        sprintf(
            '/guilds/%s/members/%s',
            rawurlencode($guildId),
            rawurlencode($userId)
        ),
        [],
        [
            'communication_disabled_until' => null,
        ],
        false,
        $headers
    );
}

function bg_discord_fetch_user_profile(string $userId): ?array
{
    try {
        return bg_discord_api_request('GET', sprintf('/users/%s', rawurlencode($userId)));
    } catch (Throwable) {
        return null;
    }
}

function bg_discord_try_fetch_guild_member_record(string $guildId, string $userId): ?array
{
    try {
        return bg_discord_request(sprintf('/guilds/%s/members/%s', rawurlencode($guildId), rawurlencode($userId)));
    } catch (Throwable) {
        return null;
    }
}

function bg_discord_create_dm_channel(string $userId): array
{
    return bg_discord_api_request(
        'POST',
        '/users/@me/channels',
        [],
        [
            'recipient_id' => $userId,
        ]
    );
}

function bg_send_discord_direct_message(string $userId, array $payload): void
{
    $dmChannel = bg_discord_create_dm_channel($userId);
    $channelId = trim((string) ($dmChannel['id'] ?? ''));

    if ($channelId === '') {
        throw new RuntimeException('Discord DM channel could not be opened.');
    }

    bg_send_discord_channel_message($payload, $channelId);
}

function bg_moderation_action_label(string $action): string
{
    return match ($action) {
        'ban' => 'Ban',
        'unban' => 'Unban',
        'kick' => 'Kick',
        'timeout' => 'Timeout',
        'remove_timeout' => 'Remove timeout',
        'warning' => 'Warning',
        default => 'Moderation action',
    };
}

function bg_moderation_notice_reason(?string $publicReason, ?string $fallbackReason): string
{
    $candidate = trim((string) $publicReason);

    if ($candidate !== '') {
        return $candidate;
    }

    $candidate = trim((string) $fallbackReason);

    return $candidate !== '' ? $candidate : 'No reason provided.';
}

function bg_try_send_discord_action_dm(string $action, string $userId, array $context = []): array
{
    if (!in_array($action, ['ban', 'warning', 'timeout'], true)) {
        return [
            'ok' => false,
            'skipped' => true,
        ];
    }

    $reason = bg_moderation_notice_reason(
        $context['public_reason'] ?? null,
        $context['reason'] ?? null
    );
    $guildName = trim((string) ($context['guild_name'] ?? ''));

    if ($guildName === '') {
        $guildName = 'BG-GAMER';
    }
    $title = match ($action) {
        'ban' => 'You have been banned from ' . $guildName,
        'warning' => 'You have received a warning in ' . $guildName,
        'timeout' => 'You have been timed out in ' . $guildName,
        default => 'Moderation notice from ' . $guildName,
    };
    $description = match ($action) {
        'ban' => 'A moderator has removed your access to the server.',
        'warning' => 'A moderator has issued a formal warning on your account.',
        'timeout' => 'A moderator has temporarily restricted your communication access.',
        default => 'A moderator action was applied to your account.',
    };
    $fields = [
        [
            'name' => 'Reason',
            'value' => mb_substr($reason, 0, 1024, 'UTF-8'),
            'inline' => false,
        ],
    ];

    if ($action === 'ban' && trim((string) ($context['duration'] ?? '')) !== '') {
        $fields[] = [
            'name' => 'Duration',
            'value' => mb_substr(trim((string) $context['duration']), 0, 1024, 'UTF-8'),
            'inline' => true,
        ];
    }

    if ($action === 'timeout' && !empty($context['timeout_minutes'])) {
        $fields[] = [
            'name' => 'Timeout',
            'value' => (string) ((int) $context['timeout_minutes']) . ' minutes',
            'inline' => true,
        ];
    }

    if (trim((string) ($context['rules_url'] ?? '')) !== '') {
        $fields[] = [
            'name' => 'Server rules',
            'value' => trim((string) $context['rules_url']),
            'inline' => false,
        ];
    }

    try {
        bg_send_discord_direct_message($userId, [
            'content' => $title,
            'allowed_mentions' => [
                'parse' => [],
            ],
            'embeds' => [[
                'title' => $title,
                'description' => $description,
                'color' => match ($action) {
                    'ban' => 15158332,
                    'warning' => 16098851,
                    'timeout' => 10181046,
                    default => 9278719,
                },
                'fields' => $fields,
                'timestamp' => gmdate('c'),
            ]],
        ]);

        return [
            'ok' => true,
            'skipped' => false,
        ];
    } catch (Throwable $throwable) {
        bg_log_event('discord-direct-action-dm', [
            'action' => $action,
            'target_user_id' => $userId,
            'message' => $throwable->getMessage(),
        ]);

        return [
            'ok' => false,
            'skipped' => false,
            'error' => $throwable->getMessage(),
        ];
    }
}

function bg_try_send_discord_direct_action_log(string $action, array $context = []): array
{
    $channelId = bg_effective_mod_log_channel_id();

    if (preg_match('/^\d{15,21}$/', $channelId) !== 1) {
        return [
            'ok' => false,
            'error' => 'log_channel_missing',
        ];
    }

    $targetUserId = trim((string) ($context['target_user_id'] ?? ''));
    $targetLabel = trim((string) ($context['target_label'] ?? ''));
    $moderatorLabel = trim((string) ($context['moderator_name'] ?? '')) ?: 'Unknown moderator';
    $reason = trim((string) ($context['reason'] ?? ''));
    $publicReason = trim((string) ($context['public_reason'] ?? ''));
    $fields = [
        [
            'name' => 'Action',
            'value' => bg_moderation_action_label($action),
            'inline' => true,
        ],
        [
            'name' => 'Moderator',
            'value' => mb_substr($moderatorLabel, 0, 1024, 'UTF-8'),
            'inline' => true,
        ],
        [
            'name' => 'Target',
            'value' => mb_substr($targetLabel !== '' ? $targetLabel : bg_mask_discord_id($targetUserId), 0, 1024, 'UTF-8'),
            'inline' => true,
        ],
    ];

    if ($targetUserId !== '') {
        $fields[] = [
            'name' => 'Discord user ID',
            'value' => $targetUserId,
            'inline' => true,
        ];
    }

    if ($publicReason !== '') {
        $fields[] = [
            'name' => 'Public reason',
            'value' => mb_substr($publicReason, 0, 1024, 'UTF-8'),
            'inline' => false,
        ];
    }

    if ($reason !== '') {
        $fields[] = [
            'name' => 'Moderator reason',
            'value' => mb_substr($reason, 0, 1024, 'UTF-8'),
            'inline' => false,
        ];
    }

    if ($action === 'ban' && trim((string) ($context['duration'] ?? '')) !== '') {
        $fields[] = [
            'name' => 'Duration',
            'value' => mb_substr(trim((string) $context['duration']), 0, 1024, 'UTF-8'),
            'inline' => true,
        ];
    }

    if ($action === 'ban' && trim((string) ($context['ban_reference'] ?? '')) !== '') {
        $fields[] = [
            'name' => 'Internal reference',
            'value' => trim((string) $context['ban_reference']),
            'inline' => true,
        ];
    }

    if ($action === 'timeout' && !empty($context['timeout_minutes'])) {
        $fields[] = [
            'name' => 'Timeout',
            'value' => (string) ((int) $context['timeout_minutes']) . ' minutes',
            'inline' => true,
        ];
    }

    if (trim((string) ($context['dm_status'] ?? '')) !== '') {
        $fields[] = [
            'name' => 'DM status',
            'value' => trim((string) $context['dm_status']),
            'inline' => true,
        ];
    }

    try {
        bg_send_discord_channel_message([
            'content' => 'Direct moderation action completed: ' . bg_moderation_action_label($action),
            'allowed_mentions' => [
                'parse' => [],
            ],
            'embeds' => [[
                'title' => 'Moderator panel action log',
                'description' => 'A direct Discord action was completed from the BG-GAMER moderation panel.',
                'color' => match ($action) {
                    'ban' => 15158332,
                    'kick' => 15548997,
                    'warning' => 16098851,
                    'timeout' => 10181046,
                    'remove_timeout', 'unban' => 5763719,
                    default => 9278719,
                },
                'fields' => $fields,
                'timestamp' => gmdate('c'),
            ]],
        ], $channelId);

        return [
            'ok' => true,
        ];
    } catch (Throwable $throwable) {
        bg_log_event('discord-direct-action-log', [
            'action' => $action,
            'target_user_id' => $targetUserId,
            'message' => $throwable->getMessage(),
        ]);

        return [
            'ok' => false,
            'error' => $throwable->getMessage(),
        ];
    }
}

function bg_discord_fetch_guild_overview(string $guildId): array
{
    return bg_discord_api_request(
        'GET',
        sprintf('/guilds/%s', rawurlencode($guildId)),
        ['with_counts' => 'true']
    );
}

function bg_discord_fetch_guild_channels(string $guildId): array
{
    $response = bg_discord_request(sprintf('/guilds/%s/channels', rawurlencode($guildId)));
    return array_values(array_filter($response, 'is_array'));
}

function bg_discord_fetch_public_widget(string $guildId): array
{
    $response = bg_http_request(
        sprintf(
            'https://discord.com/api/guilds/%s/widget.json',
            rawurlencode($guildId)
        ),
        'GET',
        [
            'Accept' => 'application/json',
        ]
    );

    if ($response['status'] >= 400) {
        return [];
    }

    $decoded = json_decode((string) $response['body'], true);
    return is_array($decoded) ? $decoded : [];
}

function bg_parse_id_csv(string $value): array
{
    $items = preg_split('/[\s,]+/', trim($value)) ?: [];
    $normalized = [];

    foreach ($items as $item) {
        $candidate = trim($item);

        if ($candidate !== '' && preg_match('/^\d{15,21}$/', $candidate) === 1) {
            $normalized[$candidate] = $candidate;
        }
    }

    return array_values($normalized);
}

function bg_mod_role_catalog(): array
{
    $configuredRoles = bg_config('moderation.role_ids', []);

    return [
        'admin' => [
            'id' => trim((string) ($configuredRoles['admin'] ?? '')),
            'label' => 'Admin',
            'description' => 'Full control over moderator permissions, direct Discord actions, and panel settings.',
            'editable' => false,
        ],
        'moderator' => [
            'id' => trim((string) ($configuredRoles['moderator'] ?? '')),
            'label' => 'Moderator',
            'description' => 'Core moderation role for bans, timeouts, kicks, and appeal reviews.',
            'editable' => true,
        ],
        'support' => [
            'id' => trim((string) ($configuredRoles['support'] ?? '')),
            'label' => 'Support',
            'description' => 'Support-facing access for handling appeals and reading live server context.',
            'editable' => true,
        ],
        'social_manager' => [
            'id' => trim((string) ($configuredRoles['social_manager'] ?? '')),
            'label' => 'Social Manager',
            'description' => 'Community-facing role with visibility into activity and safe communication tools.',
            'editable' => true,
        ],
    ];
}

function bg_mod_capability_catalog(): array
{
    return [
        'dashboard_view' => [
            'label' => 'Dashboard access',
            'description' => 'Open the moderator panel and view the core overview.',
            'dangerous' => false,
        ],
        'server_stats_view' => [
            'label' => 'Server statistics',
            'description' => 'View server counters, charts, and live snapshot data.',
            'dangerous' => false,
        ],
        'login_roster_view' => [
            'label' => 'Login roster',
            'description' => 'View which staff accounts have logged into the panel and whether they are still in the guild.',
            'dangerous' => false,
        ],
        'audit_log_view' => [
            'label' => 'Audit log',
            'description' => 'View the moderator chronology and direct site actions.',
            'dangerous' => false,
        ],
        'bans_view' => [
            'label' => 'Blocked list',
            'description' => 'Open the internal banned-user list with moderation references.',
            'dangerous' => false,
        ],
        'private_reason_view' => [
            'label' => 'Private reasons',
            'description' => 'View internal ban reasons and non-public moderation context.',
            'dangerous' => true,
        ],
        'appeal_review' => [
            'label' => 'Appeal review',
            'description' => 'Open appeal records and inspect their details.',
            'dangerous' => false,
        ],
        'appeal_reject' => [
            'label' => 'Reject appeals',
            'description' => 'Reject a ban appeal from the panel.',
            'dangerous' => true,
        ],
        'appeal_unban' => [
            'label' => 'Approve + unban appeals',
            'description' => 'Approve an appeal and remove the Discord ban directly from the site.',
            'dangerous' => true,
        ],
        'direct_ban' => [
            'label' => 'Direct ban',
            'description' => 'Ban a Discord user directly from the moderator panel.',
            'dangerous' => true,
        ],
        'direct_unban' => [
            'label' => 'Direct unban',
            'description' => 'Unban a Discord user from the internal blocked list or direct controls.',
            'dangerous' => true,
        ],
        'member_kick' => [
            'label' => 'Kick members',
            'description' => 'Remove a member from the guild directly from the site.',
            'dangerous' => true,
        ],
        'member_warn' => [
            'label' => 'Warn members',
            'description' => 'Send a formal moderation warning and DM notice from the site.',
            'dangerous' => true,
        ],
        'member_timeout' => [
            'label' => 'Timeout members',
            'description' => 'Apply a Discord communication timeout from the site.',
            'dangerous' => true,
        ],
        'member_timeout_remove' => [
            'label' => 'Remove timeout',
            'description' => 'Remove a Discord communication timeout from the site.',
            'dangerous' => true,
        ],
        'settings_channel' => [
            'label' => 'Change appeal channel',
            'description' => 'Update the Discord channel ID used for appeal messages.',
            'dangerous' => true,
        ],
        'settings_tests' => [
            'label' => 'Send test payloads',
            'description' => 'Send test bot messages and webhook payloads from the panel.',
            'dangerous' => true,
        ],
        'settings_permissions' => [
            'label' => 'Edit permission policy',
            'description' => 'Change which non-admin roles can use sensitive panel actions.',
            'dangerous' => true,
        ],
    ];
}

function bg_mod_default_role_capabilities(): array
{
    $allCapabilities = array_keys(bg_mod_capability_catalog());

    return [
        'admin' => $allCapabilities,
        'moderator' => [
            'dashboard_view',
            'server_stats_view',
            'login_roster_view',
            'audit_log_view',
            'bans_view',
            'private_reason_view',
            'appeal_review',
            'appeal_reject',
            'appeal_unban',
            'direct_ban',
            'direct_unban',
            'member_kick',
            'member_warn',
            'member_timeout',
            'member_timeout_remove',
            'settings_tests',
        ],
        'support' => [
            'dashboard_view',
            'server_stats_view',
            'login_roster_view',
            'bans_view',
            'appeal_review',
            'appeal_reject',
        ],
        'social_manager' => [
            'dashboard_view',
            'server_stats_view',
            'login_roster_view',
            'audit_log_view',
            'settings_tests',
        ],
    ];
}

function bg_mod_fixed_role_capabilities(): array
{
    return [
        'dashboard_view',
    ];
}

function bg_mod_role_capabilities(): array
{
    $defaults = bg_mod_default_role_capabilities();
    $catalog = bg_mod_capability_catalog();
    $fixed = bg_mod_fixed_role_capabilities();
    $overrides = bg_runtime_setting('moderation.role_capabilities', []);

    if (!is_array($overrides)) {
        return $defaults;
    }

    foreach ($defaults as $roleKey => $capabilities) {
        if ($roleKey === 'admin') {
            continue;
        }

        $override = $overrides[$roleKey] ?? null;

        if (!is_array($override)) {
            continue;
        }

        $normalized = [];

        foreach ($override as $capability) {
            $candidate = trim((string) $capability);

            if ($candidate !== '' && array_key_exists($candidate, $catalog)) {
                $normalized[$candidate] = $candidate;
            }
        }

        foreach ($fixed as $capability) {
            $normalized[$capability] = $capability;
        }

        $defaults[$roleKey] = array_values($normalized);
    }

    return $defaults;
}

function bg_mod_role_keys_for_member(array $memberRoleIds, string $authorizedReason = ''): array
{
    $catalog = bg_mod_role_catalog();
    $roleKeys = [];

    foreach ($catalog as $roleKey => $role) {
        $roleId = trim((string) ($role['id'] ?? ''));

        if ($roleId !== '' && in_array($roleId, $memberRoleIds, true)) {
            $roleKeys[$roleKey] = $roleKey;
        }
    }

    if ($roleKeys !== []) {
        return array_values($roleKeys);
    }

    if ($authorizedReason === 'allowed_user') {
        return ['admin'];
    }

    if ($authorizedReason !== '') {
        return ['moderator'];
    }

    return [];
}

function bg_mod_user_role_keys(?array $user): array
{
    if (!is_array($user)) {
        return [];
    }

    $stored = $user['role_keys'] ?? null;

    if (is_array($stored)) {
        return array_values(array_unique(array_filter(array_map(
            static fn (mixed $value): string => trim((string) $value),
            $stored
        ))));
    }

    $memberRoleIds = array_values(array_filter((array) ($user['guild_member_roles'] ?? []), 'is_string'));
    $authorizedReason = trim((string) ($user['authorized_reason'] ?? ''));

    return bg_mod_role_keys_for_member($memberRoleIds, $authorizedReason);
}

function bg_mod_user_capabilities(?array $user): array
{
    $roleCapabilities = bg_mod_role_capabilities();
    $roleKeys = bg_mod_user_role_keys($user);
    $capabilities = [];

    foreach ($roleKeys as $roleKey) {
        foreach (($roleCapabilities[$roleKey] ?? []) as $capability) {
            $capabilities[$capability] = $capability;
        }
    }

    return array_values($capabilities);
}

function bg_mod_has_capability(?array $user, string $capability): bool
{
    return in_array($capability, bg_mod_user_capabilities($user), true);
}

function bg_mod_signed_capabilities(): array
{
    return [
        'appeal_review',
        'appeal_reject',
        'appeal_unban',
    ];
}

function bg_mod_capability_granted(?array $user, string $capability, bool $hasSignedAccess = false): bool
{
    if (bg_mod_has_capability($user, $capability)) {
        return true;
    }

    return $hasSignedAccess && in_array($capability, bg_mod_signed_capabilities(), true);
}

function bg_mod_require_capability(?array $user, string $capability, bool $hasSignedAccess = false): void
{
    if (!bg_mod_capability_granted($user, $capability, $hasSignedAccess)) {
        throw new RuntimeException('You do not have permission to use this moderator action.');
    }
}

function bg_mod_auth_session_key(): string
{
    return 'bg_mod_auth';
}

function bg_mod_current_user(): ?array
{
    $payload = $_SESSION[bg_mod_auth_session_key()] ?? null;
    return is_array($payload) ? $payload : null;
}

function bg_mod_store_user(array $payload): void
{
    $_SESSION[bg_mod_auth_session_key()] = $payload;
}

function bg_mod_clear_user(): void
{
    unset($_SESSION[bg_mod_auth_session_key()]);
}

function bg_ensure_mod_audit_table(): void
{
    static $ensured = false;

    if ($ensured) {
        return;
    }

    bg_pdo()->exec(
        "CREATE TABLE IF NOT EXISTS `discord_mod_audit_log` (
            `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `actor_discord_id` VARCHAR(32) DEFAULT NULL,
            `actor_username` VARCHAR(80) NOT NULL,
            `actor_display_name` VARCHAR(120) DEFAULT NULL,
            `actor_role_keys` VARCHAR(190) DEFAULT NULL,
            `action_type` VARCHAR(64) NOT NULL,
            `action_result` ENUM('success', 'error', 'info') NOT NULL DEFAULT 'success',
            `target_type` VARCHAR(64) DEFAULT NULL,
            `target_discord_id` VARCHAR(32) DEFAULT NULL,
            `target_reference` VARCHAR(64) DEFAULT NULL,
            `target_label` VARCHAR(190) DEFAULT NULL,
            `summary` VARCHAR(255) NOT NULL,
            `details_json` LONGTEXT DEFAULT NULL,
            `ip_address` VARCHAR(45) DEFAULT NULL,
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `idx_discord_mod_audit_actor` (`actor_discord_id`),
            KEY `idx_discord_mod_audit_action` (`action_type`),
            KEY `idx_discord_mod_audit_result` (`action_result`),
            KEY `idx_discord_mod_audit_created_at` (`created_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    $ensured = true;
}

function bg_mod_actor_snapshot(?array $user): array
{
    if (!is_array($user)) {
        return [
            'id' => null,
            'username' => 'Signed moderator link',
            'display_name' => 'Signed moderator link',
            'role_keys' => [],
        ];
    }

    return [
        'id' => trim((string) ($user['id'] ?? '')) ?: null,
        'username' => trim((string) ($user['username'] ?? '')) ?: 'Unknown moderator',
        'display_name' => trim((string) ($user['global_name'] ?? '')) ?: trim((string) ($user['username'] ?? '')) ?: 'Unknown moderator',
        'role_keys' => bg_mod_user_role_keys($user),
    ];
}

function bg_record_mod_audit(
    string $actionType,
    string $summary,
    array $context = [],
    ?array $actorUser = null
): void {
    try {
        bg_ensure_mod_audit_table();
    } catch (Throwable) {
        return;
    }

    $actor = bg_mod_actor_snapshot($actorUser);
    $details = $context['details'] ?? [];
    $encodedDetails = $details === []
        ? null
        : json_encode($details, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    if ($encodedDetails === false) {
        $encodedDetails = null;
    }

    $statement = bg_pdo()->prepare(
        "INSERT INTO discord_mod_audit_log (
            actor_discord_id,
            actor_username,
            actor_display_name,
            actor_role_keys,
            action_type,
            action_result,
            target_type,
            target_discord_id,
            target_reference,
            target_label,
            summary,
            details_json,
            ip_address
        ) VALUES (
            :actor_discord_id,
            :actor_username,
            :actor_display_name,
            :actor_role_keys,
            :action_type,
            :action_result,
            :target_type,
            :target_discord_id,
            :target_reference,
            :target_label,
            :summary,
            :details_json,
            :ip_address
        )"
    );

    $statement->execute([
        'actor_discord_id' => $actor['id'],
        'actor_username' => $actor['username'],
        'actor_display_name' => $actor['display_name'],
        'actor_role_keys' => $actor['role_keys'] !== [] ? implode(',', $actor['role_keys']) : null,
        'action_type' => trim($actionType) !== '' ? trim($actionType) : 'unknown_action',
        'action_result' => in_array(($context['result'] ?? 'success'), ['success', 'error', 'info'], true)
            ? $context['result']
            : 'success',
        'target_type' => trim((string) ($context['target_type'] ?? '')) ?: null,
        'target_discord_id' => trim((string) ($context['target_discord_id'] ?? '')) ?: null,
        'target_reference' => trim((string) ($context['target_reference'] ?? '')) ?: null,
        'target_label' => trim((string) ($context['target_label'] ?? '')) ?: null,
        'summary' => mb_substr(trim($summary) !== '' ? trim($summary) : 'Moderator action', 0, 255, 'UTF-8'),
        'details_json' => $encodedDetails,
        'ip_address' => bg_client_ip(),
    ]);
}

function bg_fetch_mod_audit_entries(int $limit = 40): array
{
    bg_ensure_mod_audit_table();
    $limit = max(1, min($limit, 100));
    $statement = bg_pdo()->query(
        "SELECT
            id,
            actor_discord_id,
            actor_username,
            actor_display_name,
            actor_role_keys,
            action_type,
            action_result,
            target_type,
            target_discord_id,
            target_reference,
            target_label,
            summary,
            details_json,
            ip_address,
            created_at
         FROM discord_mod_audit_log
         ORDER BY created_at DESC, id DESC
         LIMIT {$limit}"
    );

    $rows = $statement->fetchAll();

    if (!is_array($rows)) {
        return [];
    }

    foreach ($rows as &$row) {
        $decoded = json_decode((string) ($row['details_json'] ?? ''), true);
        $row['details'] = is_array($decoded) ? $decoded : [];
        $row['role_keys'] = array_values(array_filter(array_map(
            static fn (string $value): string => trim($value),
            explode(',', (string) ($row['actor_role_keys'] ?? ''))
        )));
    }

    unset($row);

    return $rows;
}

function bg_fetch_mod_audit_trend(int $days = 7): array
{
    bg_ensure_mod_audit_table();
    $days = max(3, min($days, 30));
    $start = (new DateTimeImmutable('today', new DateTimeZone('Europe/Sofia')))
        ->modify('-' . ($days - 1) . ' days')
        ->format('Y-m-d 00:00:00');

    $statement = bg_pdo()->prepare(
        "SELECT DATE(created_at) AS audit_day, COUNT(*) AS total
         FROM discord_mod_audit_log
         WHERE created_at >= :start_at
         GROUP BY DATE(created_at)
         ORDER BY audit_day ASC"
    );
    $statement->execute(['start_at' => $start]);
    $rows = $statement->fetchAll();
    $totalsByDay = [];

    foreach ($rows as $row) {
        if (!is_array($row) || empty($row['audit_day'])) {
            continue;
        }

        $totalsByDay[(string) $row['audit_day']] = (int) ($row['total'] ?? 0);
    }

    $series = [];
    $cursor = new DateTimeImmutable('today', new DateTimeZone('Europe/Sofia'));

    for ($index = $days - 1; $index >= 0; $index--) {
        $date = $cursor->modify('-' . $index . ' days');
        $key = $date->format('Y-m-d');
        $series[] = [
            'date' => $key,
            'label' => $date->format('d.m'),
            'count' => $totalsByDay[$key] ?? 0,
        ];
    }

    return $series;
}

function bg_fetch_mod_login_roster(int $limit = 24): array
{
    bg_ensure_mod_audit_table();
    $limit = max(1, min($limit, 60));
    $statement = bg_pdo()->prepare(
        "SELECT
            actor_discord_id,
            actor_username,
            actor_display_name,
            actor_role_keys,
            created_at
         FROM discord_mod_audit_log
         WHERE action_type = 'login_success'
           AND actor_discord_id IS NOT NULL
         ORDER BY created_at DESC, id DESC
         LIMIT 240"
    );
    $statement->execute();
    $rows = $statement->fetchAll();

    if (!is_array($rows)) {
        return [];
    }

    $guildId = trim((string) bg_config('discord.guild_id', ''));
    $roster = [];

    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }

        $actorId = trim((string) ($row['actor_discord_id'] ?? ''));

        if ($actorId === '' || isset($roster[$actorId])) {
            continue;
        }

        $memberRecord = $guildId !== '' ? bg_discord_try_fetch_guild_member_record($guildId, $actorId) : null;
        $roster[$actorId] = [
            'discord_user_id' => $actorId,
            'username' => (string) ($row['actor_username'] ?? 'Unknown moderator'),
            'display_name' => trim((string) ($row['actor_display_name'] ?? '')) ?: (string) ($row['actor_username'] ?? 'Unknown moderator'),
            'role_keys' => array_values(array_filter(array_map(
                static fn (string $value): string => trim($value),
                explode(',', (string) ($row['actor_role_keys'] ?? ''))
            ))),
            'last_login_at' => (string) ($row['created_at'] ?? ''),
            'in_server' => is_array($memberRecord),
        ];

        if (count($roster) >= $limit) {
            break;
        }
    }

    return array_values($roster);
}

function bg_discord_oauth_enabled(): bool
{
    return trim((string) bg_config('discord.client_id', '')) !== ''
        && trim((string) bg_config('discord.client_secret', '')) !== '';
}

function bg_discord_oauth_redirect_uri(): string
{
    $configured = trim((string) bg_config('discord.oauth_redirect_uri', ''));

    if ($configured !== '') {
        return $configured;
    }

    return bg_public_url('mod/callback.php');
}

function bg_mod_login_url(string $returnTo = ''): string
{
    return bg_append_query(bg_public_url('mod/login.php'), [
        'return_to' => $returnTo !== '' ? $returnTo : null,
    ]);
}

function bg_mod_store_oauth_state(string $state, string $returnTo = ''): void
{
    $_SESSION['bg_mod_oauth_state'] = [
        'value' => $state,
        'return_to' => $returnTo,
        'issued_at' => time(),
    ];
}

function bg_mod_pull_oauth_state(): ?array
{
    $payload = $_SESSION['bg_mod_oauth_state'] ?? null;
    unset($_SESSION['bg_mod_oauth_state']);

    return is_array($payload) ? $payload : null;
}

function bg_discord_oauth_authorize_url(string $returnTo = ''): string
{
    if (!bg_discord_oauth_enabled()) {
        throw new RuntimeException('Discord OAuth is not configured.');
    }

    $state = bin2hex(random_bytes(24));
    bg_mod_store_oauth_state($state, $returnTo);

    return 'https://discord.com/oauth2/authorize?' . http_build_query([
        'client_id' => (string) bg_config('discord.client_id', ''),
        'redirect_uri' => bg_discord_oauth_redirect_uri(),
        'response_type' => 'code',
        'scope' => (string) bg_config('discord.oauth_scopes', 'identify'),
        'state' => $state,
        'prompt' => 'consent',
    ]);
}

function bg_discord_oauth_exchange_code(string $code): array
{
    $payload = http_build_query([
        'client_id' => (string) bg_config('discord.client_id', ''),
        'client_secret' => (string) bg_config('discord.client_secret', ''),
        'grant_type' => 'authorization_code',
        'code' => $code,
        'redirect_uri' => bg_discord_oauth_redirect_uri(),
    ]);

    $response = bg_http_request(
        'https://discord.com/api/oauth2/token',
        'POST',
        [
            'Content-Type' => 'application/x-www-form-urlencoded',
            'Accept' => 'application/json',
        ],
        $payload
    );

    $decoded = json_decode($response['body'], true);

    if ($response['status'] >= 400 || !is_array($decoded) || !isset($decoded['access_token'])) {
        throw new RuntimeException('Discord OAuth token exchange failed.');
    }

    return $decoded;
}

function bg_discord_oauth_fetch_user(string $accessToken): array
{
    $response = bg_http_request(
        'https://discord.com/api/users/@me',
        'GET',
        [
            'Authorization' => 'Bearer ' . $accessToken,
            'Accept' => 'application/json',
        ]
    );

    $decoded = json_decode($response['body'], true);

    if ($response['status'] >= 400 || !is_array($decoded) || !isset($decoded['id'])) {
        throw new RuntimeException('Discord OAuth user profile fetch failed.');
    }

    return $decoded;
}

function bg_discord_fetch_guild_member(string $guildId, string $userId): array
{
    try {
        return bg_discord_request(sprintf('/guilds/%s/members/%s', rawurlencode($guildId), rawurlencode($userId)));
    } catch (Throwable $throwable) {
        throw new RuntimeException('Discord member record could not be loaded for the current user.');
    }
}

function bg_discord_fetch_guild_roles(string $guildId): array
{
    $response = bg_discord_request(sprintf('/guilds/%s/roles', rawurlencode($guildId)));
    return array_values(array_filter($response, 'is_array'));
}

function bg_discord_role_permissions_map(array $roles): array
{
    $map = [];

    foreach ($roles as $role) {
        if (!is_array($role) || !isset($role['id'])) {
            continue;
        }

        $map[(string) $role['id']] = (string) ($role['permissions'] ?? '0');
    }

    return $map;
}

function bg_discord_permission_granted(string $permissions): bool
{
    $value = (int) $permissions;
    $banMembers = 1 << 2;
    $administrator = 1 << 3;

    return (($value & $administrator) === $administrator) || (($value & $banMembers) === $banMembers);
}

function bg_discord_moderation_access_context(array $user, array $member, array $roles): array
{
    $userId = (string) ($user['id'] ?? '');
    $memberRoleIds = array_values(array_filter((array) ($member['roles'] ?? []), 'is_string'));
    $allowedUserIds = bg_parse_id_csv((string) bg_config('moderation.allowed_user_ids', ''));
    $allowedRoleIds = bg_parse_id_csv((string) bg_config('moderation.allowed_role_ids', ''));
    $rolePermissions = bg_discord_role_permissions_map($roles);
    $grantedByRolePermission = false;

    foreach ($memberRoleIds as $roleId) {
        if (in_array($roleId, $allowedRoleIds, true)) {
            $roleKeys = bg_mod_role_keys_for_member($memberRoleIds, 'allowed_role');
            return [
                'authorized' => true,
                'reason' => 'allowed_role',
                'role_ids' => $memberRoleIds,
                'role_keys' => $roleKeys,
            ];
        }

        if (isset($rolePermissions[$roleId]) && bg_discord_permission_granted($rolePermissions[$roleId])) {
            $grantedByRolePermission = true;
        }
    }

    if (in_array($userId, $allowedUserIds, true)) {
        $roleKeys = bg_mod_role_keys_for_member($memberRoleIds, 'allowed_user');
        return [
            'authorized' => true,
            'reason' => 'allowed_user',
            'role_ids' => $memberRoleIds,
            'role_keys' => $roleKeys,
        ];
    }

    if ($grantedByRolePermission) {
        $roleKeys = bg_mod_role_keys_for_member($memberRoleIds, 'discord_permissions');
        return [
            'authorized' => true,
            'reason' => 'discord_permissions',
            'role_ids' => $memberRoleIds,
            'role_keys' => $roleKeys,
        ];
    }

    return [
        'authorized' => false,
        'reason' => 'missing_moderation_permissions',
        'role_ids' => $memberRoleIds,
        'role_keys' => [],
    ];
}

function bg_discord_complete_moderator_login(string $code, string $state): array
{
    $storedState = bg_mod_pull_oauth_state();

    if (
        !is_array($storedState)
        || !isset($storedState['value'])
        || !hash_equals((string) $storedState['value'], $state)
        || ((int) ($storedState['issued_at'] ?? 0)) < (time() - 900)
    ) {
        throw new RuntimeException('Discord login state is missing or expired.');
    }

    $guildId = trim((string) bg_config('discord.guild_id', ''));

    if ($guildId === '') {
        throw new RuntimeException('Discord guild id is not configured.');
    }

    $tokenPayload = bg_discord_oauth_exchange_code($code);
    $user = bg_discord_oauth_fetch_user((string) $tokenPayload['access_token']);
    $member = bg_discord_fetch_guild_member($guildId, (string) $user['id']);
    $roles = bg_discord_fetch_guild_roles($guildId);
    $access = bg_discord_moderation_access_context($user, $member, $roles);

    if (!$access['authorized']) {
        throw new RuntimeException('Discord account does not have moderator access for this panel.');
    }

    $sessionPayload = [
        'id' => (string) $user['id'],
        'username' => (string) ($user['username'] ?? 'Unknown user'),
        'global_name' => (string) ($user['global_name'] ?? ''),
        'avatar_url' => bg_build_discord_avatar_url($user),
        'guild_member_roles' => $access['role_ids'],
        'role_keys' => $access['role_keys'] ?? bg_mod_role_keys_for_member((array) ($access['role_ids'] ?? []), (string) ($access['reason'] ?? '')),
        'capabilities' => bg_mod_user_capabilities([
            'guild_member_roles' => $access['role_ids'],
            'authorized_reason' => $access['reason'],
            'role_keys' => $access['role_keys'] ?? bg_mod_role_keys_for_member((array) ($access['role_ids'] ?? []), (string) ($access['reason'] ?? '')),
        ]),
        'authorized_reason' => $access['reason'],
        'authorized_at' => time(),
    ];

    bg_mod_store_user($sessionPayload);
    bg_record_mod_audit(
        'login_success',
        'Moderator session opened through Discord OAuth.',
        [
            'result' => 'success',
            'target_type' => 'moderator_session',
            'target_discord_id' => (string) $user['id'],
            'target_label' => trim((string) ($user['global_name'] ?? '')) ?: (string) ($user['username'] ?? 'Unknown user'),
            'details' => [
                'authorized_reason' => $access['reason'],
                'role_keys' => $sessionPayload['role_keys'],
            ],
        ],
        $sessionPayload
    );

    return [
        'user' => $sessionPayload,
        'return_to' => trim((string) ($storedState['return_to'] ?? '')),
    ];
}

function bg_turnstile_verify(?string $token, ?string $ipAddress = null): bool
{
    if ((bool) bg_config('security.turnstile_bypass', false)) {
        return true;
    }

    $secret = (string) bg_config('security.turnstile_secret', '');

    if ($secret === '' || $token === null || $token === '') {
        return false;
    }

    $payload = http_build_query([
        'secret' => $secret,
        'response' => $token,
        'remoteip' => $ipAddress,
    ]);

    $response = bg_http_request(
        'https://challenges.cloudflare.com/turnstile/v0/siteverify',
        'POST',
        [
            'Content-Type' => 'application/x-www-form-urlencoded',
            'Accept' => 'application/json',
        ],
        $payload
    );

    $decoded = json_decode($response['body'], true);
    return is_array($decoded) && ($decoded['success'] ?? false) === true;
}

function bg_store_uploaded_file(array $file): ?string
{
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return null;
    }

    if (($file['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
        throw new RuntimeException('Качването на файла не беше успешно.');
    }

    $maxBytes = (int) bg_config('limits.appeal_max_upload_bytes', 5242880);
    $size = (int) ($file['size'] ?? 0);

    if ($size <= 0 || $size > $maxBytes) {
        throw new RuntimeException('Файлът е твърде голям или невалиден.');
    }

    $temporaryPath = (string) ($file['tmp_name'] ?? '');

    if ($temporaryPath === '' || !is_uploaded_file($temporaryPath)) {
        throw new RuntimeException('Невалиден upload файл.');
    }

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mimeType = (string) $finfo->file($temporaryPath);

    $allowedMimeTypes = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
        'application/pdf' => 'pdf',
        'text/plain' => 'txt',
    ];

    if (!array_key_exists($mimeType, $allowedMimeTypes)) {
        throw new RuntimeException('Неподдържан тип файл.');
    }

    $extension = $allowedMimeTypes[$mimeType];
    $randomName = bin2hex(random_bytes(16)) . '.' . $extension;
    $targetRelativePath = 'appeal-uploads/' . $randomName;
    $targetAbsolutePath = bg_storage_path($targetRelativePath);

    if (!move_uploaded_file($temporaryPath, $targetAbsolutePath)) {
        throw new RuntimeException('Файлът не можа да бъде записан.');
    }

    return $targetRelativePath;
}

function bg_require_sync_token(): void
{
    $expected = (string) bg_config('security.sync_token', '');
    $provided = (string) (
        $_SERVER['HTTP_X_BG_SYNC_TOKEN']
        ?? $_GET['token']
        ?? $_POST['token']
        ?? bg_cli_arg_value('token')
        ?? ''
    );

    if ($expected === '' || !hash_equals($expected, $provided)) {
        bg_json([
            'ok' => false,
            'error' => 'unauthorized',
        ], 401);
    }
}

function bg_cli_arg_value(string $key): ?string
{
    if (PHP_SAPI !== 'cli') {
        return null;
    }

    $argv = $_SERVER['argv'] ?? [];

    if (!is_array($argv)) {
        return null;
    }

    foreach ($argv as $argument) {
        if (!is_string($argument) || strpos($argument, '=') === false) {
            continue;
        }

        [$name, $value] = explode('=', $argument, 2);

        if ($name === $key) {
            $value = trim($value);
            return $value === '' ? null : $value;
        }
    }

    return null;
}

function bg_acquire_lock(string $name)
{
    $path = bg_storage_path('cache/' . $name . '.lock');
    $handle = fopen($path, 'c+');

    if ($handle === false) {
        throw new RuntimeException('Неуспешно създаване на lock файл.');
    }

    if (!flock($handle, LOCK_EX | LOCK_NB)) {
        throw new RuntimeException('Синхронизацията вече се изпълнява.');
    }

    return $handle;
}

function bg_release_lock($handle): void
{
    if (is_resource($handle)) {
        flock($handle, LOCK_UN);
        fclose($handle);
    }
}

function bg_rate_limit_guard(string $key, int $seconds): void
{
    $path = bg_storage_path('cache/' . $key . '.json');
    $now = time();
    $lastRun = 0;

    if (is_file($path)) {
        $decoded = json_decode((string) file_get_contents($path), true);
        $lastRun = (int) ($decoded['last_run'] ?? 0);
    }

    if ($lastRun > 0 && ($now - $lastRun) < $seconds) {
        throw new RuntimeException('Синхронизацията е rate-limited. Опитайте отново след малко.');
    }

    file_put_contents($path, json_encode(['last_run' => $now], JSON_UNESCAPED_UNICODE));
}

function bg_fetch_ban_record_by_reference(string $publicReference): ?array
{
    $statement = bg_pdo()->prepare(
        'SELECT id, public_reference, discord_user_id, username, global_name, avatar_url, public_reason, banned_at, expires_at, status, appeal_status
         FROM discord_bans
         WHERE public_reference = :public_reference
         LIMIT 1'
    );
    $statement->execute(['public_reference' => $publicReference]);
    $row = $statement->fetch();

    return is_array($row) ? $row : null;
}

function bg_fetch_appeal_record_by_reference(string $publicReference): ?array
{
    $statement = bg_pdo()->prepare(
        'SELECT
            a.id,
            a.public_reference,
            a.ban_id,
            a.discord_user_id,
            a.discord_username,
            a.contact,
            a.appeal_reason,
            a.additional_information,
            a.attachment_path,
            a.status,
            a.moderator_response,
            a.reviewed_by,
            a.submitted_at,
            a.reviewed_at,
            b.public_reference AS ban_public_reference,
            b.username AS ban_username,
            b.global_name AS ban_global_name,
            b.public_reason AS ban_public_reason,
            b.private_reason AS ban_private_reason,
            b.status AS ban_status,
            b.appeal_status AS ban_appeal_status,
            b.discord_user_id AS ban_discord_user_id,
            b.moderator_name AS ban_moderator_name,
            b.banned_at AS ban_banned_at,
            b.unbanned_at AS ban_unbanned_at
         FROM discord_ban_appeals a
         LEFT JOIN discord_bans b ON b.id = a.ban_id
         WHERE a.public_reference = :public_reference
         LIMIT 1'
    );
    $statement->execute(['public_reference' => $publicReference]);
    $row = $statement->fetch();

    return is_array($row) ? $row : null;
}

function bg_fetch_recent_appeals(int $limit = 12): array
{
    $limit = max(1, min($limit, 50));
    $statement = bg_pdo()->query(
        "SELECT
            a.public_reference,
            a.discord_username,
            a.status,
            a.submitted_at,
            a.reviewed_at,
            b.public_reference AS ban_public_reference,
            b.status AS ban_status,
            b.public_reason AS ban_public_reason
         FROM discord_ban_appeals a
         LEFT JOIN discord_bans b ON b.id = a.ban_id
         ORDER BY a.submitted_at DESC
         LIMIT {$limit}"
    );

    $rows = $statement->fetchAll();
    return is_array($rows) ? $rows : [];
}

function bg_fetch_moderation_dashboard_counts(): array
{
    $pdo = bg_pdo();

    return [
        'pending_appeals' => (int) $pdo->query("SELECT COUNT(*) FROM discord_ban_appeals WHERE status = 'pending'")->fetchColumn(),
        'under_review_appeals' => (int) $pdo->query("SELECT COUNT(*) FROM discord_ban_appeals WHERE status = 'under_review'")->fetchColumn(),
        'approved_appeals' => (int) $pdo->query("SELECT COUNT(*) FROM discord_ban_appeals WHERE status = 'approved'")->fetchColumn(),
        'active_bans' => (int) $pdo->query("SELECT COUNT(*) FROM discord_bans WHERE status IN ('active', 'temporary')")->fetchColumn(),
    ];
}

function bg_process_moderation_action(
    array $record,
    string $action,
    string $reviewedBy,
    string $moderatorNote = ''
): array {
    $normalizedAction = strtolower(trim($action));

    if (!in_array($normalizedAction, ['unban', 'reject'], true)) {
        throw new RuntimeException('Invalid moderation action.');
    }

    $appealId = (int) ($record['id'] ?? 0);

    if ($appealId <= 0) {
        throw new RuntimeException('Appeal record is missing.');
    }

    $reviewer = trim($reviewedBy) !== '' ? trim($reviewedBy) : 'Discord moderation team';
    $pdo = bg_pdo();
    $pdo->beginTransaction();

    try {
        if ($normalizedAction === 'unban') {
            $guildId = trim((string) bg_config('discord.guild_id', ''));
            $targetUserId = trim((string) ($record['ban_discord_user_id'] ?? $record['discord_user_id'] ?? ''));

            if ($guildId === '' || $targetUserId === '') {
                throw new RuntimeException('Discord guild ID or user ID is missing for the unban action.');
            }

            if ((string) ($record['ban_status'] ?? '') !== 'unbanned') {
                bg_discord_unban_member($guildId, $targetUserId);
            }

            if (!empty($record['ban_id'])) {
                $pdo->prepare(
                    "UPDATE discord_bans
                     SET status = 'unbanned',
                         unbanned_at = CURRENT_TIMESTAMP,
                         appeal_status = 'approved',
                         updated_at = CURRENT_TIMESTAMP
                     WHERE id = :id"
                )->execute([
                    'id' => (int) $record['ban_id'],
                ]);
            }

            $response = trim($moderatorNote) !== ''
                ? trim($moderatorNote)
                : 'Appeal approved. Ban removed through the moderator panel.';

            $pdo->prepare(
                "UPDATE discord_ban_appeals
                 SET status = 'approved',
                     moderator_response = :moderator_response,
                     reviewed_by = :reviewed_by,
                     reviewed_at = CURRENT_TIMESTAMP,
                     updated_at = CURRENT_TIMESTAMP
                 WHERE id = :id"
            )->execute([
                'moderator_response' => $response,
                'reviewed_by' => $reviewer,
                'id' => $appealId,
            ]);

            $pdo->commit();

            return [
                'type' => 'success',
                'message' => 'Потребителят е unban-нат и appeal записът е маркиран като approved.',
            ];
        }

        if (!empty($record['ban_id'])) {
            $pdo->prepare(
                "UPDATE discord_bans
                 SET appeal_status = 'rejected',
                     updated_at = CURRENT_TIMESTAMP
                 WHERE id = :id"
            )->execute([
                'id' => (int) $record['ban_id'],
            ]);
        }

        $response = trim($moderatorNote) !== ''
            ? trim($moderatorNote)
            : 'Appeal rejected through the moderator panel.';

        $pdo->prepare(
            "UPDATE discord_ban_appeals
             SET status = 'rejected',
                 moderator_response = :moderator_response,
                 reviewed_by = :reviewed_by,
                 reviewed_at = CURRENT_TIMESTAMP,
                 updated_at = CURRENT_TIMESTAMP
             WHERE id = :id"
        )->execute([
            'moderator_response' => $response,
            'reviewed_by' => $reviewer,
            'id' => $appealId,
        ]);

        $pdo->commit();

        return [
            'type' => 'success',
            'message' => 'Appeal записът е маркиран като rejected.',
        ];
    } catch (Throwable $throwable) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        throw $throwable;
    }
}

function bg_fetch_active_ban_records_admin(int $limit = 24): array
{
    $limit = max(1, min($limit, 100));
    $statement = bg_pdo()->query(
        "SELECT
            id,
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
         FROM discord_bans
         WHERE status IN ('active', 'temporary')
         ORDER BY banned_at DESC, id DESC
         LIMIT {$limit}"
    );

    $rows = $statement->fetchAll();
    return is_array($rows) ? $rows : [];
}

function bg_mark_latest_ban_record_unbanned(PDO $pdo, string $discordUserId): ?string
{
    $statement = $pdo->prepare(
        "SELECT id, public_reference
         FROM discord_bans
         WHERE discord_user_id = :discord_user_id
           AND status IN ('active', 'temporary')
         ORDER BY banned_at DESC, id DESC
         LIMIT 1"
    );
    $statement->execute([
        'discord_user_id' => $discordUserId,
    ]);
    $row = $statement->fetch();

    if (!is_array($row)) {
        return null;
    }

    $pdo->prepare(
        "UPDATE discord_bans
         SET status = 'unbanned',
             unbanned_at = CURRENT_TIMESTAMP,
             updated_at = CURRENT_TIMESTAMP
         WHERE id = :id"
    )->execute([
        'id' => (int) $row['id'],
    ]);

    return (string) ($row['public_reference'] ?? '');
}

function bg_parse_duration_expression(?string $value, ?DateTimeImmutable $baseTime = null): array
{
    $baseTime ??= new DateTimeImmutable('now', new DateTimeZone('Europe/Sofia'));
    $normalized = strtolower(trim((string) $value));

    if ($normalized === '' || in_array($normalized, ['permanent', 'perm', 'none', 'forever', 'indefinite'], true)) {
        return [
            'seconds' => 0,
            'status' => 'active',
            'expires_at' => null,
            'expires_at_sql' => null,
        ];
    }

    $seconds = 0;

    if (preg_match('/^\d+$/', $normalized) === 1) {
        $seconds = (int) $normalized;
    } else {
        if (preg_match_all('/(\d+)\s*([smhdw])/i', $normalized, $matches, PREG_SET_ORDER) === 0) {
            throw new RuntimeException('Invalid duration expression. Use values like 7d, 12h, 30m, or permanent.');
        }

        $unmatched = preg_replace('/(\d+)\s*([smhdw])/i', '', $normalized);

        if ($unmatched === null || trim($unmatched) !== '') {
            throw new RuntimeException('Invalid duration expression. Use values like 7d, 12h, 30m, or permanent.');
        }

        $units = [
            's' => 1,
            'm' => 60,
            'h' => 3600,
            'd' => 86400,
            'w' => 604800,
        ];

        foreach ($matches as $match) {
            $seconds += ((int) $match[1]) * $units[strtolower((string) $match[2])];
        }
    }

    if ($seconds <= 0) {
        return [
            'seconds' => 0,
            'status' => 'active',
            'expires_at' => null,
            'expires_at_sql' => null,
        ];
    }

    $expiresAt = $baseTime->modify('+' . $seconds . ' seconds');

    return [
        'seconds' => $seconds,
        'status' => 'temporary',
        'expires_at' => $expiresAt,
        'expires_at_sql' => $expiresAt->format('Y-m-d H:i:s'),
    ];
}

function bg_upsert_manual_ban_record(PDO $pdo, array $event): array
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
            "UPDATE discord_bans
             SET username = :username,
                 global_name = :global_name,
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
             WHERE id = :id"
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
            'status' => $event['status'],
        ]);

        return [
            'action' => 'updated',
            'public_reference' => (string) $existing['public_reference'],
        ];
    }

    $publicReference = bg_generate_public_reference();

    $pdo->prepare(
        "INSERT INTO discord_bans (
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
        )"
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
        'status' => $event['status'],
        'appeal_status' => 'not_requested',
    ]);

    return [
        'action' => 'created',
        'public_reference' => $publicReference,
    ];
}

function bg_fetch_moderation_server_snapshot(int $rosterLimit = 16): array
{
    $guildId = trim((string) bg_config('discord.guild_id', ''));

    if ($guildId === '') {
        return [
            'guild_name' => 'BG-GAMER',
            'members' => 0,
            'online' => 0,
            'text_channels' => 0,
            'voice_channels' => 0,
            'stage_channels' => 0,
            'forum_channels' => 0,
            'voice_users' => 0,
            'active_voice_channel' => null,
            'active_bans' => 0,
            'pending_appeals' => 0,
            'approved_appeals' => 0,
            'audit_trend' => bg_fetch_mod_audit_trend(7),
            'login_roster' => bg_fetch_mod_login_roster($rosterLimit),
            'updated_at' => gmdate('c'),
        ];
    }

    $overview = bg_discord_fetch_guild_overview($guildId);
    $channels = bg_discord_fetch_guild_channels($guildId);
    $widget = bg_discord_fetch_public_widget($guildId);
    $channelCounts = [
        'text_channels' => 0,
        'voice_channels' => 0,
        'stage_channels' => 0,
        'forum_channels' => 0,
    ];

    foreach ($channels as $channel) {
        $type = (int) ($channel['type'] ?? -1);

        if (in_array($type, [0, 5], true)) {
            $channelCounts['text_channels']++;
            continue;
        }

        if ($type === 2) {
            $channelCounts['voice_channels']++;
            continue;
        }

        if ($type === 13) {
            $channelCounts['stage_channels']++;
            continue;
        }

        if ($type === 15) {
            $channelCounts['forum_channels']++;
        }
    }

    $voiceUsers = 0;
    $voiceChannelCounts = [];

    foreach ((array) ($widget['members'] ?? []) as $member) {
        if (!is_array($member)) {
            continue;
        }

        $channelId = trim((string) ($member['channel_id'] ?? ''));

        if ($channelId === '') {
            continue;
        }

        $voiceUsers++;
        $voiceChannelCounts[$channelId] = ($voiceChannelCounts[$channelId] ?? 0) + 1;
    }

    $activeVoiceChannel = null;

    if ($voiceChannelCounts !== []) {
        arsort($voiceChannelCounts);
        $topVoiceChannelId = (string) array_key_first($voiceChannelCounts);

        foreach ((array) ($widget['channels'] ?? []) as $channel) {
            if (is_array($channel) && (string) ($channel['id'] ?? '') === $topVoiceChannelId) {
                $activeVoiceChannel = trim((string) ($channel['name'] ?? ''));
                break;
            }
        }
    }

    return [
        'guild_name' => trim((string) ($overview['name'] ?? '')) ?: 'BG-GAMER',
        'members' => (int) ($overview['approximate_member_count'] ?? 0),
        'online' => (int) ($overview['approximate_presence_count'] ?? 0),
        'text_channels' => $channelCounts['text_channels'],
        'voice_channels' => $channelCounts['voice_channels'],
        'stage_channels' => $channelCounts['stage_channels'],
        'forum_channels' => $channelCounts['forum_channels'],
        'voice_users' => $voiceUsers,
        'active_voice_channel' => $activeVoiceChannel,
        'active_bans' => (int) bg_pdo()->query("SELECT COUNT(*) FROM discord_bans WHERE status IN ('active', 'temporary')")->fetchColumn(),
        'pending_appeals' => (int) bg_pdo()->query("SELECT COUNT(*) FROM discord_ban_appeals WHERE status = 'pending'")->fetchColumn(),
        'approved_appeals' => (int) bg_pdo()->query("SELECT COUNT(*) FROM discord_ban_appeals WHERE status = 'approved'")->fetchColumn(),
        'audit_trend' => bg_fetch_mod_audit_trend(7),
        'login_roster' => bg_fetch_mod_login_roster($rosterLimit),
        'updated_at' => gmdate('c'),
    ];
}

function bg_save_mod_role_capability_overrides(array $submitted): array
{
    $roles = bg_mod_role_catalog();
    $catalog = bg_mod_capability_catalog();
    $fixedCapabilities = bg_mod_fixed_role_capabilities();
    $overrides = [];

    foreach ($roles as $roleKey => $role) {
        if (($role['editable'] ?? false) !== true) {
            continue;
        }

        $roleSubmitted = $submitted[$roleKey] ?? [];

        if (!is_array($roleSubmitted)) {
            $roleSubmitted = [];
        }

        $normalized = [];

        foreach ($roleSubmitted as $capability) {
            $candidate = trim((string) $capability);

            if ($candidate !== '' && array_key_exists($candidate, $catalog)) {
                $normalized[$candidate] = $candidate;
            }
        }

        foreach ($fixedCapabilities as $capability) {
            $normalized[$capability] = $capability;
        }

        $overrides[$roleKey] = array_values($normalized);
    }

    bg_set_runtime_setting('moderation.role_capabilities', $overrides);
    return bg_mod_role_capabilities();
}

function bg_session_bucket(string $key): array
{
    $bucket = $_SESSION[$key] ?? [];
    return is_array($bucket) ? $bucket : [];
}

function bg_flash_set(string $key, mixed $value): void
{
    $_SESSION['bg_flash'][$key] = $value;
}

function bg_flash_pull(string $key, mixed $default = null): mixed
{
    $flash = bg_session_bucket('bg_flash');

    if (!array_key_exists($key, $flash)) {
        return $default;
    }

    $value = $flash[$key];
    unset($_SESSION['bg_flash'][$key]);

    if (isset($_SESSION['bg_flash']) && $_SESSION['bg_flash'] === []) {
        unset($_SESSION['bg_flash']);
    }

    return $value;
}

function bg_client_ip(): string
{
    $candidates = [
        $_SERVER['HTTP_CF_CONNECTING_IP'] ?? null,
        $_SERVER['HTTP_X_FORWARDED_FOR'] ?? null,
        $_SERVER['REMOTE_ADDR'] ?? null,
    ];

    foreach ($candidates as $candidate) {
        if ($candidate === null || $candidate === '') {
            continue;
        }

        $first = trim(explode(',', $candidate)[0]);

        if (filter_var($first, FILTER_VALIDATE_IP) !== false) {
            return $first;
        }
    }

    return '0.0.0.0';
}

function bg_request_rate_limit(string $key, int $windowSeconds, int $maxAttempts): void
{
    $path = bg_storage_path('cache/' . preg_replace('/[^a-z0-9._-]+/i', '-', $key) . '.json');
    $now = time();
    $payload = [
        'window_started_at' => $now,
        'attempts' => 0,
    ];

    if (is_file($path)) {
        $decoded = json_decode((string) file_get_contents($path), true);

        if (is_array($decoded)) {
            $payload['window_started_at'] = (int) ($decoded['window_started_at'] ?? $now);
            $payload['attempts'] = (int) ($decoded['attempts'] ?? 0);
        }
    }

    if (($now - $payload['window_started_at']) > $windowSeconds) {
        $payload = [
            'window_started_at' => $now,
            'attempts' => 0,
        ];
    }

    $payload['attempts']++;
    file_put_contents($path, json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), LOCK_EX);

    if ($payload['attempts'] > $maxAttempts) {
        throw new RuntimeException('Опитите за изпращане са временно ограничени. Опитайте отново след малко.');
    }
}

function bg_append_query(string $url, array $params): string
{
    $separator = str_contains($url, '?') ? '&' : '?';
    $filtered = array_filter(
        $params,
        static fn (mixed $value): bool => $value !== null && $value !== ''
    );

    if ($filtered === []) {
        return $url;
    }

    return $url . $separator . http_build_query($filtered);
}

function bg_moderation_link_secret(): string
{
    return trim((string) bg_config('security.moderation_link_secret', ''));
}

function bg_build_moderation_link_signature(string $appealReference, string $action, int $expiresAt): string
{
    $secret = bg_moderation_link_secret();

    if ($secret === '') {
        throw new RuntimeException('Moderation link secret is not configured.');
    }

    return hash_hmac(
        'sha256',
        implode('|', [$appealReference, $action, (string) $expiresAt]),
        $secret
    );
}

function bg_verify_moderation_link_signature(
    string $appealReference,
    string $action,
    int $expiresAt,
    string $signature
): bool {
    $secret = bg_moderation_link_secret();

    if ($secret === '' || $appealReference === '' || $action === '' || $expiresAt <= time() || $signature === '') {
        return false;
    }

    $expected = bg_build_moderation_link_signature($appealReference, $action, $expiresAt);
    return hash_equals($expected, $signature);
}

function bg_build_signed_moderation_url(string $appealReference, string $action = 'panel'): string
{
    $ttl = max(300, (int) bg_config('security.moderation_link_ttl', 1209600));
    $expiresAt = time() + $ttl;

    return bg_append_query(bg_public_url('mod/'), [
        'appeal' => $appealReference,
        'action' => $action,
        'expires' => $expiresAt,
        'token' => bg_build_moderation_link_signature($appealReference, $action, $expiresAt),
    ]);
}

function bg_redirect(string $url, int $statusCode = 302): never
{
    header('Location: ' . $url, true, $statusCode);
    exit;
}

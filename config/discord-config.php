<?php

declare(strict_types=1);

$privateEnv = [];
$privateConfigCandidates = [];
$privateConfigOverride = $_ENV['BG_GAMER_PRIVATE_CONFIG_FILE'] ?? $_SERVER['BG_GAMER_PRIVATE_CONFIG_FILE'] ?? getenv('BG_GAMER_PRIVATE_CONFIG_FILE');

if (defined('BG_GAMER_ROOT')) {
    $privateRoot = dirname(BG_GAMER_ROOT);
    $privateConfigCandidates[] = $privateRoot . DIRECTORY_SEPARATOR . 'bg-gamer-discord.private.php';
    $privateConfigCandidates[] = $privateRoot . DIRECTORY_SEPARATOR . '.bg-gamer-discord.private.php';

    $privateRootParent = dirname($privateRoot);

    if ($privateRootParent !== $privateRoot) {
        $privateConfigCandidates[] = $privateRootParent . DIRECTORY_SEPARATOR . 'bg-gamer-discord.private.php';
        $privateConfigCandidates[] = $privateRootParent . DIRECTORY_SEPARATOR . '.bg-gamer-discord.private.php';
    }
}

if (is_string($privateConfigOverride) && trim($privateConfigOverride) !== '') {
    array_unshift($privateConfigCandidates, trim($privateConfigOverride));
}

foreach (array_unique($privateConfigCandidates) as $candidate) {
    if (!is_string($candidate) || trim($candidate) === '' || !is_file($candidate)) {
        continue;
    }

    $loaded = require $candidate;

    if (is_array($loaded)) {
        $privateEnv = $loaded;
        break;
    }
}

$env = static function (string $key, ?string $default = null) use ($privateEnv): ?string {
    $candidates = [
        $_ENV[$key] ?? null,
        $_SERVER[$key] ?? null,
        getenv($key),
        $privateEnv[$key] ?? null,
    ];

    foreach ($candidates as $value) {
        if ($value === false || $value === null || $value === '') {
            continue;
        }

        return (string) $value;
    }

    return $default;
};

return [
    'app' => [
        'base_path' => rtrim($env('BG_GAMER_BASE_PATH', '/discord'), '/'),
        'base_url' => rtrim((string) $env('BG_GAMER_BASE_URL', ''), '/'),
        'rules_url' => $env('BG_GAMER_RULES_URL', 'https://bg-gamer.com/discord/'),
        'rules_channel_id' => $env('BG_GAMER_RULES_CHANNEL_ID'),
        'bans_robots' => $env('BG_GAMER_BANS_ROBOTS', ''),
        'appeal_success_path' => $env('BG_GAMER_APPEAL_SUCCESS_PATH', '/discord/appeal/success.php'),
    ],
    'discord' => [
        'bot_token' => $env('BG_GAMER_DISCORD_BOT_TOKEN'),
        'guild_id' => $env('BG_GAMER_DISCORD_GUILD_ID'),
        'api_base' => $env('BG_GAMER_DISCORD_API_BASE', 'https://discord.com/api/v10'),
        'invite_code' => $env('BG_GAMER_DISCORD_INVITE_CODE', 'PFkjeKBuxH'),
        'client_id' => $env('BG_GAMER_DISCORD_CLIENT_ID', $env('BG_GAMER_DISCORD_BOT_ID')),
        'client_secret' => $env('BG_GAMER_DISCORD_CLIENT_SECRET', $env('BG_GAMER_DISCORD_SECRET')),
        'oauth_redirect_uri' => $env('BG_GAMER_DISCORD_REDIRECT_URI'),
        'oauth_scopes' => $env('BG_GAMER_DISCORD_OAUTH_SCOPES', 'identify'),
    ],
    'botghost' => [
        'secret' => $env('BG_GAMER_KREMMUNA_SECRET', $env('BG_GAMER_BOTGHOST_SECRET')),
        'webhook_secret' => $env('BG_GAMER_KREMMUNA_WEBHOOK_SECRET', $env('BG_GAMER_BOTGHOST_WEBHOOK_SECRET')),
        'guild_id' => $env('BG_GAMER_KREMMUNA_GUILD_ID', $env('BG_GAMER_BOTGHOST_GUILD_ID', $env('BG_GAMER_DISCORD_GUILD_ID'))),
        'source_label' => $env('BG_GAMER_KREMMUNA_SOURCE_LABEL', $env('BG_GAMER_BOTGHOST_SOURCE_LABEL', 'kremmuna-webhook')),
        'signature_header' => $env('BG_GAMER_KREMMUNA_SIGNATURE_HEADER', $env('BG_GAMER_BOTGHOST_SIGNATURE_HEADER', 'X-BG-Signature')),
    ],
    'appeals' => [
        'discord_channel_id' => $env('BG_GAMER_APPEAL_CHANNEL_ID'),
    ],
    'database' => [
        'host' => $env('BG_GAMER_DB_HOST', '127.0.0.1'),
        'port' => (int) $env('BG_GAMER_DB_PORT', '3306'),
        'name' => $env('BG_GAMER_DB_NAME', 'bg_gamer'),
        'user' => $env('BG_GAMER_DB_USER', ''),
        'pass' => $env('BG_GAMER_DB_PASS', ''),
        'charset' => $env('BG_GAMER_DB_CHARSET', 'utf8mb4'),
    ],
    'security' => [
        'sync_token' => $env('BG_GAMER_SYNC_TOKEN'),
        'webhook_url' => $env('BG_GAMER_APPEAL_WEBHOOK_URL'),
        'turnstile_site_key' => $env('BG_GAMER_TURNSTILE_SITE_KEY'),
        'turnstile_secret' => $env('BG_GAMER_TURNSTILE_SECRET'),
        'turnstile_bypass' => $env('BG_GAMER_TURNSTILE_BYPASS', '0') === '1',
        'moderation_link_secret' => $env('BG_GAMER_MOD_LINK_SECRET'),
        'moderation_link_ttl' => (int) $env('BG_GAMER_MOD_LINK_TTL', '1209600'),
        'admin_review_url' => rtrim((string) $env('BG_GAMER_ADMIN_REVIEW_URL', ''), '/'),
        'csrf_namespace' => $env('BG_GAMER_CSRF_NAMESPACE', 'bg_gamer_ban_center'),
    ],
    'moderation' => [
        'log_channel_id' => $env('BG_GAMER_MOD_LOG_CHANNEL_ID', '1101968770282573905'),
        'allowed_user_ids' => $env('BG_GAMER_MOD_ALLOWED_USER_IDS', ''),
        'allowed_role_ids' => $env(
            'BG_GAMER_MOD_ALLOWED_ROLE_IDS',
            implode(',', [
                '114673142143778818',
                '490941599103188992',
                '114673978001457157',
                '441601063485964288',
            ])
        ),
        'role_ids' => [
            'admin' => $env('BG_GAMER_ROLE_ADMIN_ID', '114673142143778818'),
            'moderator' => $env('BG_GAMER_ROLE_MODERATOR_ID', '490941599103188992'),
            'social_manager' => $env('BG_GAMER_ROLE_SOCIAL_MANAGER_ID', '114673978001457157'),
            'support' => $env('BG_GAMER_ROLE_SUPPORT_ID', '441601063485964288'),
        ],
    ],
    'limits' => [
        'sync_interval_seconds' => (int) $env('BG_GAMER_SYNC_INTERVAL_SECONDS', '600'),
        'appeal_max_upload_bytes' => (int) $env('BG_GAMER_APPEAL_MAX_UPLOAD_BYTES', '5242880'),
        'appeal_max_excerpt_length' => (int) $env('BG_GAMER_APPEAL_EXCERPT_LENGTH', '260'),
        'public_reason_max_length' => (int) $env('BG_GAMER_PUBLIC_REASON_MAX_LENGTH', '220'),
        'page_size_max' => (int) $env('BG_GAMER_BANS_PAGE_SIZE_MAX', '6'),
    ],
    'debug' => [
        'expose_errors' => $env('BG_GAMER_DEBUG_ERRORS', '0') === '1',
    ],
];

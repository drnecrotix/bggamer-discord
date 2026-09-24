<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/config/ban-center.php';

bg_require_method(['GET', 'POST']);
bg_require_sync_token();

try {
    $database = (array) bg_config('database', []);

    $summary = [
        'driver_pdo' => extension_loaded('pdo'),
        'driver_pdo_mysql' => extension_loaded('pdo_mysql'),
        'host' => (string) ($database['host'] ?? ''),
        'port' => (int) ($database['port'] ?? 0),
        'name' => (string) ($database['name'] ?? ''),
        'user' => (string) ($database['user'] ?? ''),
        'password_present' => trim((string) ($database['pass'] ?? '')) !== '',
    ];

    if ($summary['host'] === '' || $summary['name'] === '' || $summary['user'] === '') {
        throw new RuntimeException('database_config_incomplete', 503);
    }

    $pdo = bg_pdo();

    $serverVersion = (string) $pdo->getAttribute(PDO::ATTR_SERVER_VERSION);
    $driverName = (string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);

    $hasBansTable = false;
    $hasAppealsTable = false;

    $tableStatement = $pdo->query("SHOW TABLES LIKE 'discord_bans'");
    if ($tableStatement !== false) {
        $hasBansTable = (bool) $tableStatement->fetchColumn();
    }

    $appealStatement = $pdo->query("SHOW TABLES LIKE 'discord_ban_appeals'");
    if ($appealStatement !== false) {
        $hasAppealsTable = (bool) $appealStatement->fetchColumn();
    }

    bg_json([
        'ok' => true,
        'database' => $summary,
        'connection' => [
            'driver' => $driverName,
            'server_version' => $serverVersion,
            'discord_bans_table' => $hasBansTable,
            'discord_ban_appeals_table' => $hasAppealsTable,
        ],
    ]);
} catch (Throwable $throwable) {
    $statusCode = (int) $throwable->getCode();

    if ($statusCode < 400 || $statusCode > 599) {
        $statusCode = 503;
    }

    bg_log_event('db-check-errors', [
        'message' => $throwable->getMessage(),
        'status_code' => $statusCode,
        'file' => basename($throwable->getFile()),
        'line' => $throwable->getLine(),
    ]);

    $payload = [
        'ok' => false,
        'error' => 'database_check_failed',
    ];

    if (bg_debug_errors_enabled()) {
        $payload['debug'] = [
            'message' => $throwable->getMessage(),
            'file' => basename($throwable->getFile()),
            'line' => $throwable->getLine(),
            'database' => $summary ?? null,
        ];
    }

    bg_json($payload, $statusCode);
}

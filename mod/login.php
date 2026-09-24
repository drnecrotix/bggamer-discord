<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/config/ban-center.php';
require_once __DIR__ . '/i18n.php';

$uiLang = bg_mod_current_language();

if (!bg_discord_oauth_enabled()) {
    bg_flash_set('mod_auth_message', [
        'type' => 'error',
        'message' => bg_mod_t('msg.auth.oauth_missing', [], $uiLang),
    ]);

    bg_redirect(bg_public_url('mod/'));
}

$returnTo = trim((string) ($_GET['return_to'] ?? ''));

try {
    bg_redirect(bg_discord_oauth_authorize_url($returnTo));
} catch (Throwable $throwable) {
    bg_log_event('mod-oauth-errors', [
        'message' => $throwable->getMessage(),
        'file' => basename($throwable->getFile()),
        'line' => $throwable->getLine(),
        'stage' => 'authorize',
    ]);

    bg_flash_set('mod_auth_message', [
        'type' => 'error',
        'message' => bg_mod_t('msg.auth.login_start_failed', [], $uiLang),
    ]);

    bg_redirect(bg_public_url('mod/'));
}

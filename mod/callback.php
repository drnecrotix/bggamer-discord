<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/config/ban-center.php';
require_once __DIR__ . '/i18n.php';

$uiLang = bg_mod_current_language();

$code = trim((string) ($_GET['code'] ?? ''));
$state = trim((string) ($_GET['state'] ?? ''));
$error = trim((string) ($_GET['error'] ?? ''));

if ($error !== '') {
    bg_flash_set('mod_auth_message', [
        'type' => 'error',
        'message' => bg_mod_t('msg.auth.login_denied', [], $uiLang),
    ]);

    bg_redirect(bg_public_url('mod/'));
}

if ($code === '' || $state === '') {
    bg_flash_set('mod_auth_message', [
        'type' => 'error',
        'message' => bg_mod_t('msg.auth.params_missing', [], $uiLang),
    ]);

    bg_redirect(bg_public_url('mod/'));
}

try {
    $result = bg_discord_complete_moderator_login($code, $state);

    bg_flash_set('mod_auth_message', [
        'type' => 'success',
        'message' => bg_mod_t('msg.auth.login_success', [], $uiLang),
    ]);

    $returnTo = trim((string) ($result['return_to'] ?? ''));
    $target = $returnTo !== '' ? $returnTo : bg_public_url('mod/');
    bg_redirect($target);
} catch (Throwable $throwable) {
    bg_log_event('mod-oauth-errors', [
        'message' => $throwable->getMessage(),
        'file' => basename($throwable->getFile()),
        'line' => $throwable->getLine(),
        'stage' => 'callback',
    ]);

    bg_flash_set('mod_auth_message', [
        'type' => 'error',
        'message' => $throwable instanceof RuntimeException
            ? bg_mod_translate_runtime_message($throwable->getMessage(), $uiLang)
            : bg_mod_t('msg.auth.login_complete_failed', [], $uiLang),
    ]);

    bg_redirect(bg_public_url('mod/'));
}

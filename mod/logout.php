<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/config/ban-center.php';
require_once __DIR__ . '/i18n.php';

$uiLang = bg_mod_current_language();

if (($moderatorUser = bg_mod_current_user()) !== null) {
    bg_record_mod_audit(
        'logout',
        'Moderator session was closed.',
        [
            'result' => 'info',
            'target_type' => 'moderator_session',
            'target_discord_id' => (string) ($moderatorUser['id'] ?? ''),
            'target_label' => trim((string) ($moderatorUser['global_name'] ?? '')) ?: (string) ($moderatorUser['username'] ?? 'Discord moderator'),
        ],
        $moderatorUser
    );
}

bg_mod_clear_user();
bg_flash_set('mod_auth_message', [
    'type' => 'success',
    'message' => bg_mod_t('msg.auth.logout_success', [], $uiLang),
]);

bg_redirect(bg_public_url('mod/'));

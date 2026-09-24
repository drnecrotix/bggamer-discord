<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/config/ban-center.php';
require_once __DIR__ . '/i18n.php';

bg_send_page_security_headers(false);

$uiLang = bg_mod_current_language();

$rulesUrl = (string) bg_config('app.rules_url', bg_public_url());
$allowedActions = ['panel', 'unban', 'reject'];
$requestedAction = strtolower(trim((string) ($_GET['action'] ?? 'panel')));
$requestedAction = in_array($requestedAction, $allowedActions, true) ? $requestedAction : 'panel';
$appealReference = trim((string) ($_GET['appeal'] ?? ''));
$expiresAt = (int) ($_GET['expires'] ?? 0);
$signature = trim((string) ($_GET['token'] ?? ''));
$hasSignedAccess = $appealReference !== ''
    && $expiresAt > 0
    && bg_verify_moderation_link_signature($appealReference, $requestedAction, $expiresAt, $signature);

$moderatorUser = bg_mod_current_user();

if (is_array($moderatorUser) && ((int) ($moderatorUser['authorized_at'] ?? 0)) < (time() - 43200)) {
    bg_mod_clear_user();
    $moderatorUser = null;
}

$hasModeratorAccess = is_array($moderatorUser);
$oauthEnabled = bg_discord_oauth_enabled();
$roleCatalog = bg_mod_role_catalog();
$capabilityCatalog = bg_mod_capability_catalog();
$roleCapabilities = bg_mod_role_capabilities();
$fixedCapabilities = bg_mod_fixed_role_capabilities();
$moderatorRoleKeys = bg_mod_user_role_keys($moderatorUser);
$moderatorCapabilities = bg_mod_user_capabilities($moderatorUser);
$isAdminModerator = $hasModeratorAccess && in_array('admin', $moderatorRoleKeys, true);

$canViewDashboard = $hasModeratorAccess && bg_mod_has_capability($moderatorUser, 'dashboard_view');
$canViewStats = $hasModeratorAccess && bg_mod_has_capability($moderatorUser, 'server_stats_view');
$canViewLoginRoster = $hasModeratorAccess && bg_mod_has_capability($moderatorUser, 'login_roster_view');
$canViewAudit = $hasModeratorAccess && bg_mod_has_capability($moderatorUser, 'audit_log_view');
$canViewBans = $hasModeratorAccess && bg_mod_has_capability($moderatorUser, 'bans_view');
$canViewPrivateReasons = $hasModeratorAccess && bg_mod_has_capability($moderatorUser, 'private_reason_view');
$canReviewAppeals = bg_mod_capability_granted($moderatorUser, 'appeal_review', $hasSignedAccess);
$canRejectAppeals = bg_mod_capability_granted($moderatorUser, 'appeal_reject', $hasSignedAccess);
$canApproveUnbanAppeals = bg_mod_capability_granted($moderatorUser, 'appeal_unban', $hasSignedAccess);
$canManageChannel = $hasModeratorAccess && bg_mod_has_capability($moderatorUser, 'settings_channel');
$canSendTests = $hasModeratorAccess && bg_mod_has_capability($moderatorUser, 'settings_tests');
$canEditPermissions = $hasModeratorAccess && bg_mod_has_capability($moderatorUser, 'settings_permissions');
$canDirectBan = $hasModeratorAccess && bg_mod_has_capability($moderatorUser, 'direct_ban');
$canDirectUnban = $hasModeratorAccess && bg_mod_has_capability($moderatorUser, 'direct_unban');
$canKickMembers = $hasModeratorAccess && bg_mod_has_capability($moderatorUser, 'member_kick');
$canWarnMembers = $hasModeratorAccess && bg_mod_has_capability($moderatorUser, 'member_warn');
$canTimeoutMembers = $hasModeratorAccess && bg_mod_has_capability($moderatorUser, 'member_timeout');
$canRemoveTimeouts = $hasModeratorAccess && bg_mod_has_capability($moderatorUser, 'member_timeout_remove');
$hasAnyDirectActionAccess = $canDirectBan || $canDirectUnban || $canKickMembers || $canWarnMembers || $canTimeoutMembers || $canRemoveTimeouts;

$dashboardCounts = [
    'pending_appeals' => 0,
    'under_review_appeals' => 0,
    'approved_appeals' => 0,
    'active_bans' => 0,
];
$serverSnapshot = [
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
    'audit_trend' => [],
    'login_roster' => [],
    'updated_at' => gmdate('c'),
];
$recentAppeals = [];
$auditEntries = [];
$activeBans = [];
$record = null;
$pageError = null;

$flashResult = bg_flash_pull('moderation_action_result');
$authMessage = bg_flash_pull('mod_auth_message');
$panelMessage = bg_flash_pull('mod_panel_message');
$dispatchDirectActionSignals = static function (
    string $action,
    string $targetUserId,
    string $targetLabel,
    string $reason,
    string $publicReason = '',
    array $extra = []
) use ($moderatorUser, $rulesUrl): array {
    $context = array_merge([
        'target_user_id' => $targetUserId,
        'target_label' => $targetLabel,
        'reason' => $reason,
        'public_reason' => $publicReason,
        'moderator_name' => bg_mod_display_name($moderatorUser),
        'guild_name' => 'BG-GAMER',
        'rules_url' => $rulesUrl,
    ], $extra);
    $dmResult = bg_try_send_discord_action_dm($action, $targetUserId, $context);
    $context['dm_status'] = !empty($dmResult['ok'])
        ? 'sent'
        : (!empty($dmResult['skipped']) ? 'not required' : 'failed');
    $logResult = bg_try_send_discord_direct_action_log($action, $context);

    return [
        'dm' => $dmResult,
        'log' => $logResult,
    ];
};

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $formType = strtolower(trim((string) ($_POST['form_type'] ?? '')));

    try {
        if ($formType === 'moderation_action') {
            if (!bg_verify_csrf((string) ($_POST['csrf_token'] ?? ''), 'mod_moderation_action')) {
                throw new RuntimeException('Session expired. Refresh the page and try again.');
            }

            $targetAppealReference = trim((string) ($_POST['appeal_reference'] ?? ''));
            $submittedAction = strtolower(trim((string) ($_POST['mod_action'] ?? '')));
            $requiredCapability = $submittedAction === 'unban' ? 'appeal_unban' : 'appeal_reject';
            bg_mod_require_capability($moderatorUser, $requiredCapability, $hasSignedAccess);

            if ($targetAppealReference === '') {
                throw new RuntimeException('Appeal reference is required for this moderation action.');
            }

            $targetRecord = bg_fetch_appeal_record_by_reference($targetAppealReference);

            if ($targetRecord === null) {
                throw new RuntimeException('Appeal record was not found.');
            }

            $reviewedBy = trim((string) ($_POST['reviewed_by'] ?? ''));

            if ($reviewedBy === '' && $hasModeratorAccess) {
                $reviewedBy = bg_mod_display_name($moderatorUser);
            }

            $moderatorNote = trim((string) ($_POST['moderator_note'] ?? ''));
            $result = bg_process_moderation_action(
                $targetRecord,
                $submittedAction,
                $reviewedBy,
                $moderatorNote
            );

            bg_record_mod_audit(
                $submittedAction === 'unban' ? 'appeal_unban' : 'appeal_reject',
                $submittedAction === 'unban'
                    ? 'Appeal approved and Discord ban removed.'
                    : 'Appeal rejected from the moderator panel.',
                [
                    'result' => 'success',
                    'target_type' => 'appeal',
                    'target_discord_id' => trim((string) ($targetRecord['discord_user_id'] ?? '')) ?: null,
                    'target_reference' => $targetAppealReference,
                    'target_label' => trim((string) ($targetRecord['discord_username'] ?? '')) ?: $targetAppealReference,
                    'details' => [
                        'reviewed_by' => $reviewedBy,
                        'moderator_note' => $moderatorNote,
                        'ban_reference' => (string) ($targetRecord['ban_public_reference'] ?? ''),
                    ],
                ],
                $moderatorUser
            );

            bg_flash_set('moderation_action_result', $result);

            $redirectUrl = $hasModeratorAccess
                ? bg_mod_page_url([
                    'appeal' => $targetAppealReference,
                    'action' => 'panel',
                ])
                : bg_build_signed_moderation_url($targetAppealReference, 'panel');

            bg_redirect($redirectUrl);
        }

        if ($formType === 'channel_settings') {
            bg_mod_require_capability($moderatorUser, 'settings_channel', false);

            if (!bg_verify_csrf((string) ($_POST['csrf_token'] ?? ''), 'mod_channel_settings')) {
                throw new RuntimeException('Session expired. Refresh the page and try again.');
            }

            $channelId = trim((string) ($_POST['appeal_channel_id'] ?? ''));

            if (preg_match('/^\d{15,21}$/', $channelId) !== 1) {
                throw new RuntimeException('Enter a valid Discord channel ID.');
            }

            bg_set_runtime_setting('appeals.discord_channel_id', $channelId);
            bg_record_mod_audit(
                'appeal_channel_update',
                'Appeal routing channel updated from the moderator panel.',
                [
                    'result' => 'success',
                    'target_type' => 'discord_channel',
                    'target_reference' => $channelId,
                    'target_label' => $channelId,
                ],
                $moderatorUser
            );
            bg_flash_set('mod_panel_message', [
                'type' => 'success',
                'message' => 'Appeal channel ID was updated successfully.',
            ]);

            bg_redirect(bg_mod_page_url(['appeal' => $appealReference !== '' ? $appealReference : null]));
        }

        if ($formType === 'test_channel_message') {
            bg_mod_require_capability($moderatorUser, 'settings_tests', false);

            if (!bg_verify_csrf((string) ($_POST['csrf_token'] ?? ''), 'mod_test_actions')) {
                throw new RuntimeException('Session expired. Refresh the page and try again.');
            }

            $targetChannelId = trim((string) ($_POST['test_channel_id'] ?? bg_effective_appeal_channel_id()));

            if (preg_match('/^\d{15,21}$/', $targetChannelId) !== 1) {
                throw new RuntimeException('Enter a valid Discord test channel ID.');
            }

            $messageText = trim((string) ($_POST['test_message'] ?? ''));
            $messageText = $messageText !== '' ? $messageText : 'BG-GAMER moderator panel test message.';

            bg_send_discord_channel_message([
                'content' => $messageText,
                'allowed_mentions' => [
                    'parse' => [],
                ],
                'embeds' => [[
                    'title' => 'Moderator panel test message',
                    'description' => 'This is a verification message sent from the BG-GAMER moderation panel.',
                    'color' => 9278719,
                    'fields' => [
                        [
                            'name' => 'Moderator',
                            'value' => bg_mod_display_name($moderatorUser),
                            'inline' => true,
                        ],
                        [
                            'name' => 'Target channel',
                            'value' => $targetChannelId,
                            'inline' => true,
                        ],
                    ],
                    'timestamp' => gmdate('c'),
                ]],
            ], $targetChannelId);

            bg_record_mod_audit(
                'test_channel_message',
                'Test bot message sent from the moderator panel.',
                [
                    'result' => 'success',
                    'target_type' => 'discord_channel',
                    'target_reference' => $targetChannelId,
                    'target_label' => $targetChannelId,
                    'details' => [
                        'message_text' => $messageText,
                    ],
                ],
                $moderatorUser
            );

            bg_flash_set('mod_panel_message', [
                'type' => 'success',
                'message' => 'Test channel message was sent successfully.',
            ]);

            bg_redirect(bg_mod_page_url(['appeal' => $appealReference !== '' ? $appealReference : null]));
        }

        if ($formType === 'admin_bot_post') {
            if (!$isAdminModerator) {
                throw new RuntimeException('This action is available only to admins.');
            }

            if (!bg_verify_csrf((string) ($_POST['csrf_token'] ?? ''), 'mod_admin_bot_post')) {
                throw new RuntimeException('Session expired. Refresh the page and try again.');
            }

            $targetChannelId = trim((string) ($_POST['bot_post_channel_id'] ?? bg_effective_appeal_channel_id()));

            if (preg_match('/^\d{15,21}$/', $targetChannelId) !== 1) {
                throw new RuntimeException('Enter a valid Discord channel ID.');
            }

            $messageText = trim((string) ($_POST['bot_post_message'] ?? ''));

            if ($messageText === '') {
                throw new RuntimeException('Enter a message before sending.');
            }

            bg_send_discord_channel_message([
                'content' => $messageText,
                'allowed_mentions' => [
                    'parse' => [],
                ],
            ], $targetChannelId);

            bg_record_mod_audit(
                'admin_bot_post',
                'Admin bot message sent from the moderator panel.',
                [
                    'result' => 'success',
                    'target_type' => 'discord_channel',
                    'target_reference' => $targetChannelId,
                    'target_label' => $targetChannelId,
                    'details' => [
                        'message_text' => $messageText,
                        'delivery_mode' => 'bot_channel_post',
                    ],
                ],
                $moderatorUser
            );

            bg_flash_set('mod_panel_message', [
                'type' => 'success',
                'message' => 'Bot message was sent successfully.',
            ]);

            bg_redirect(bg_mod_page_url(['appeal' => $appealReference !== '' ? $appealReference : null]));
        }

        if ($formType === 'test_webhook') {
            bg_mod_require_capability($moderatorUser, 'settings_tests', false);

            if (!bg_verify_csrf((string) ($_POST['csrf_token'] ?? ''), 'mod_test_actions')) {
                throw new RuntimeException('Session expired. Refresh the page and try again.');
            }

            $webhookUrl = bg_effective_webhook_url();

            if ($webhookUrl === '') {
                throw new RuntimeException('Webhook URL is not configured server-side.');
            }

            $messageText = trim((string) ($_POST['test_message'] ?? ''));
            $messageText = $messageText !== '' ? $messageText : 'BG-GAMER moderator panel webhook test.';

            bg_send_discord_webhook([
                'username' => 'BG-GAMER Mod Panel',
                'embeds' => [[
                    'title' => 'Moderator panel webhook test',
                    'description' => $messageText,
                    'color' => 9278719,
                    'fields' => [
                        [
                            'name' => 'Moderator',
                            'value' => bg_mod_display_name($moderatorUser),
                            'inline' => true,
                        ],
                        [
                            'name' => 'Appeal channel',
                            'value' => bg_effective_appeal_channel_id() !== '' ? bg_effective_appeal_channel_id() : 'Not configured',
                            'inline' => true,
                        ],
                    ],
                    'timestamp' => gmdate('c'),
                ]],
            ]);

            bg_record_mod_audit(
                'test_webhook',
                'Test webhook payload sent from the moderator panel.',
                [
                    'result' => 'success',
                    'target_type' => 'webhook',
                    'target_label' => bg_mod_webhook_host($webhookUrl),
                    'details' => [
                        'message_text' => $messageText,
                    ],
                ],
                $moderatorUser
            );

            bg_flash_set('mod_panel_message', [
                'type' => 'success',
                'message' => 'Test webhook payload was sent successfully.',
            ]);

            bg_redirect(bg_mod_page_url(['appeal' => $appealReference !== '' ? $appealReference : null]));
        }

        if ($formType === 'permission_policy') {
            bg_mod_require_capability($moderatorUser, 'settings_permissions', false);

            if (!bg_verify_csrf((string) ($_POST['csrf_token'] ?? ''), 'mod_permission_policy')) {
                throw new RuntimeException('Session expired. Refresh the page and try again.');
            }

            $submittedRoleCapabilities = $_POST['role_capabilities'] ?? [];

            if (!is_array($submittedRoleCapabilities)) {
                $submittedRoleCapabilities = [];
            }

            bg_save_mod_role_capability_overrides($submittedRoleCapabilities);
            bg_record_mod_audit(
                'permission_policy_update',
                'Moderator permission policy updated.',
                [
                    'result' => 'success',
                    'target_type' => 'permission_policy',
                    'details' => [
                        'editable_roles' => array_keys($submittedRoleCapabilities),
                    ],
                ],
                $moderatorUser
            );

            bg_flash_set('mod_panel_message', [
                'type' => 'success',
                'message' => 'Permission policy was updated successfully.',
            ]);

            bg_redirect(bg_mod_page_url(['appeal' => $appealReference !== '' ? $appealReference : null]));
        }

        if ($formType === 'direct_discord_action') {
            if (!bg_verify_csrf((string) ($_POST['csrf_token'] ?? ''), 'mod_direct_actions')) {
                throw new RuntimeException('Session expired. Refresh the page and try again.');
            }

            $directAction = strtolower(trim((string) ($_POST['direct_action'] ?? '')));
            $capabilityMap = [
                'ban' => 'direct_ban',
                'unban' => 'direct_unban',
                'kick' => 'member_kick',
                'warning' => 'member_warn',
                'timeout' => 'member_timeout',
                'remove_timeout' => 'member_timeout_remove',
            ];

            if (!isset($capabilityMap[$directAction])) {
                throw new RuntimeException('Unknown direct moderation action.');
            }

            bg_mod_require_capability($moderatorUser, $capabilityMap[$directAction], false);

            $targetUserId = trim((string) ($_POST['target_user_id'] ?? ''));
            $targetLabel = trim((string) ($_POST['target_user_label'] ?? ''));
            $reason = trim((string) ($_POST['moderation_reason'] ?? ''));
            $publicReason = sanitizePublicReason((string) ($_POST['public_reason'] ?? $reason));
            $guildId = trim((string) bg_config('discord.guild_id', ''));

            if ($guildId === '') {
                throw new RuntimeException('Discord guild ID is not configured.');
            }

            if (preg_match('/^\d{15,21}$/', $targetUserId) !== 1) {
                throw new RuntimeException('Enter a valid Discord user ID.');
            }

            if ($directAction === 'ban') {
                $durationInput = trim((string) ($_POST['ban_duration'] ?? ''));
                $timing = bg_parse_duration_expression($durationInput, new DateTimeImmutable('now', new DateTimeZone('Europe/Sofia')));

                bg_discord_ban_member($guildId, $targetUserId, $reason);
                $userProfile = bg_discord_fetch_user_profile($targetUserId);
                $username = trim((string) ($userProfile['username'] ?? ''));
                $globalName = trim((string) ($userProfile['global_name'] ?? ''));

                if ($username === '') {
                    $username = $targetLabel !== '' ? $targetLabel : 'Discord user ' . substr($targetUserId, -6);
                }

                $pdo = bg_pdo();
                $pdo->beginTransaction();

                try {
                    $banResult = bg_upsert_manual_ban_record($pdo, [
                        'discord_user_id' => $targetUserId,
                        'username' => $username,
                        'global_name' => $globalName !== '' ? $globalName : null,
                        'avatar_url' => is_array($userProfile) ? (bg_build_discord_avatar_url($userProfile) ?? null) : null,
                        'public_reason' => $publicReason,
                        'private_reason' => $reason !== '' ? $reason : bg_default_public_reason(),
                        'moderator_discord_id' => (string) ($moderatorUser['id'] ?? ''),
                        'moderator_name' => bg_mod_display_name($moderatorUser),
                        'banned_at' => (new DateTimeImmutable('now', new DateTimeZone('Europe/Sofia')))->format('Y-m-d H:i:s'),
                        'expires_at' => $timing['expires_at_sql'],
                        'status' => $timing['status'],
                    ]);
                    $pdo->commit();
                } catch (Throwable $throwable) {
                    if ($pdo->inTransaction()) {
                        $pdo->rollBack();
                    }

                    throw $throwable;
                }

                $notificationResult = $dispatchDirectActionSignals(
                    'ban',
                    $targetUserId,
                    $globalName !== '' ? $globalName : $username,
                    $reason,
                    $publicReason,
                    [
                        'duration' => $durationInput !== '' ? $durationInput : 'permanent',
                        'ban_reference' => (string) ($banResult['public_reference'] ?? ''),
                    ]
                );
                bg_record_mod_audit(
                    'direct_ban',
                    'Discord user was banned directly from the moderator panel.',
                    [
                        'result' => 'success',
                        'target_type' => 'discord_user',
                        'target_discord_id' => $targetUserId,
                        'target_reference' => (string) ($banResult['public_reference'] ?? ''),
                        'target_label' => $globalName !== '' ? $globalName : $username,
                        'details' => [
                            'duration' => $durationInput !== '' ? $durationInput : 'permanent',
                            'status' => $timing['status'],
                            'dm_status' => !empty($notificationResult['dm']['ok']) ? 'sent' : (!empty($notificationResult['dm']['skipped']) ? 'skipped' : 'failed'),
                            'log_status' => !empty($notificationResult['log']['ok']) ? 'sent' : 'failed',
                        ],
                    ],
                    $moderatorUser
                );

                bg_flash_set('mod_panel_message', [
                    'type' => 'success',
                    'message' => sprintf(
                        'Direct ban was sent successfully. Internal reference: %s.',
                        (string) ($banResult['public_reference'] ?? 'BG-BAN')
                    ),
                ]);

                bg_redirect(bg_mod_page_url(['appeal' => $appealReference !== '' ? $appealReference : null]));
            }

            if ($directAction === 'unban') {
                bg_discord_unban_member($guildId, $targetUserId);
                $pdo = bg_pdo();
                $pdo->beginTransaction();

                try {
                    $publicReference = bg_mark_latest_ban_record_unbanned($pdo, $targetUserId);
                    $pdo->commit();
                } catch (Throwable $throwable) {
                    if ($pdo->inTransaction()) {
                        $pdo->rollBack();
                    }

                    throw $throwable;
                }

                $notificationResult = $dispatchDirectActionSignals(
                    'unban',
                    $targetUserId,
                    $targetLabel !== '' ? $targetLabel : bg_mask_discord_id($targetUserId),
                    $reason
                );
                bg_record_mod_audit(
                    'direct_unban',
                    'Discord user was unbanned directly from the moderator panel.',
                    [
                        'result' => 'success',
                        'target_type' => 'discord_user',
                        'target_discord_id' => $targetUserId,
                        'target_reference' => $publicReference,
                        'target_label' => $targetLabel !== '' ? $targetLabel : bg_mask_discord_id($targetUserId),
                        'details' => [
                            'log_status' => !empty($notificationResult['log']['ok']) ? 'sent' : 'failed',
                        ],
                    ],
                    $moderatorUser
                );

                bg_flash_set('mod_panel_message', [
                    'type' => 'success',
                    'message' => $publicReference !== null
                        ? sprintf('Direct unban completed. Internal record %s was updated.', $publicReference)
                        : 'Direct unban completed. No active internal ban record was found for that user.',
                ]);

                bg_redirect(bg_mod_page_url(['appeal' => $appealReference !== '' ? $appealReference : null]));
            }

            if ($directAction === 'kick') {
                bg_discord_kick_member($guildId, $targetUserId, $reason);
                $notificationResult = $dispatchDirectActionSignals(
                    'kick',
                    $targetUserId,
                    $targetLabel !== '' ? $targetLabel : bg_mask_discord_id($targetUserId),
                    $reason
                );
                bg_record_mod_audit(
                    'direct_kick',
                    'Discord member was kicked directly from the moderator panel.',
                    [
                        'result' => 'success',
                        'target_type' => 'discord_user',
                        'target_discord_id' => $targetUserId,
                        'target_label' => $targetLabel !== '' ? $targetLabel : bg_mask_discord_id($targetUserId),
                        'details' => [
                            'log_status' => !empty($notificationResult['log']['ok']) ? 'sent' : 'failed',
                        ],
                    ],
                    $moderatorUser
                );
                bg_flash_set('mod_panel_message', [
                    'type' => 'success',
                    'message' => 'Direct kick was completed successfully.',
                ]);
                bg_redirect(bg_mod_page_url(['appeal' => $appealReference !== '' ? $appealReference : null]));
            }

            if ($directAction === 'warning') {
                $notificationResult = $dispatchDirectActionSignals(
                    'warning',
                    $targetUserId,
                    $targetLabel !== '' ? $targetLabel : bg_mask_discord_id($targetUserId),
                    $reason,
                    $publicReason
                );
                bg_record_mod_audit(
                    'member_warning',
                    'Discord member warning issued from the moderator panel.',
                    [
                        'result' => 'success',
                        'target_type' => 'discord_user',
                        'target_discord_id' => $targetUserId,
                        'target_label' => $targetLabel !== '' ? $targetLabel : bg_mask_discord_id($targetUserId),
                        'details' => [
                            'public_reason' => $publicReason,
                            'dm_status' => !empty($notificationResult['dm']['ok']) ? 'sent' : (!empty($notificationResult['dm']['skipped']) ? 'skipped' : 'failed'),
                            'log_status' => !empty($notificationResult['log']['ok']) ? 'sent' : 'failed',
                        ],
                    ],
                    $moderatorUser
                );
                bg_flash_set('mod_panel_message', [
                    'type' => 'success',
                    'message' => 'Warning notice was sent successfully.',
                ]);
                bg_redirect(bg_mod_page_url(['appeal' => $appealReference !== '' ? $appealReference : null]));
            }

            if ($directAction === 'timeout') {
                $timeoutMinutes = (int) ($_POST['timeout_minutes'] ?? 0);

                if ($timeoutMinutes <= 0 || $timeoutMinutes > 40320) {
                    throw new RuntimeException('Timeout duration must be between 1 and 40320 minutes.');
                }

                $timeoutUntil = (new DateTimeImmutable('now', new DateTimeZone('Europe/Sofia')))
                    ->modify('+' . $timeoutMinutes . ' minutes');
                bg_discord_timeout_member($guildId, $targetUserId, $timeoutUntil, $reason);
                $notificationResult = $dispatchDirectActionSignals(
                    'timeout',
                    $targetUserId,
                    $targetLabel !== '' ? $targetLabel : bg_mask_discord_id($targetUserId),
                    $reason,
                    $publicReason,
                    [
                        'timeout_minutes' => $timeoutMinutes,
                    ]
                );
                bg_record_mod_audit(
                    'member_timeout',
                    'Discord member timeout applied from the moderator panel.',
                    [
                        'result' => 'success',
                        'target_type' => 'discord_user',
                        'target_discord_id' => $targetUserId,
                        'target_label' => $targetLabel !== '' ? $targetLabel : bg_mask_discord_id($targetUserId),
                        'details' => [
                            'timeout_minutes' => $timeoutMinutes,
                            'until' => $timeoutUntil->format('c'),
                            'dm_status' => !empty($notificationResult['dm']['ok']) ? 'sent' : (!empty($notificationResult['dm']['skipped']) ? 'skipped' : 'failed'),
                            'log_status' => !empty($notificationResult['log']['ok']) ? 'sent' : 'failed',
                        ],
                    ],
                    $moderatorUser
                );
                bg_flash_set('mod_panel_message', [
                    'type' => 'success',
                    'message' => sprintf('Timeout applied successfully for %d minutes.', $timeoutMinutes),
                ]);
                bg_redirect(bg_mod_page_url(['appeal' => $appealReference !== '' ? $appealReference : null]));
            }

            if ($directAction === 'remove_timeout') {
                bg_discord_remove_member_timeout($guildId, $targetUserId, $reason);
                $notificationResult = $dispatchDirectActionSignals(
                    'remove_timeout',
                    $targetUserId,
                    $targetLabel !== '' ? $targetLabel : bg_mask_discord_id($targetUserId),
                    $reason
                );
                bg_record_mod_audit(
                    'member_timeout_removed',
                    'Discord member timeout removed from the moderator panel.',
                    [
                        'result' => 'success',
                        'target_type' => 'discord_user',
                        'target_discord_id' => $targetUserId,
                        'target_label' => $targetLabel !== '' ? $targetLabel : bg_mask_discord_id($targetUserId),
                        'details' => [
                            'log_status' => !empty($notificationResult['log']['ok']) ? 'sent' : 'failed',
                        ],
                    ],
                    $moderatorUser
                );
                bg_flash_set('mod_panel_message', [
                    'type' => 'success',
                    'message' => 'Timeout removal completed successfully.',
                ]);
                bg_redirect(bg_mod_page_url(['appeal' => $appealReference !== '' ? $appealReference : null]));
            }
        }

        throw new RuntimeException('Unknown moderator panel operation.');
    } catch (Throwable $throwable) {
        if ($hasModeratorAccess && $formType !== '') {
            bg_record_mod_audit(
                'panel_action_error',
                'Moderator panel action failed.',
                [
                    'result' => 'error',
                    'target_type' => $formType,
                    'target_reference' => $appealReference !== '' ? $appealReference : null,
                    'details' => [
                        'message' => $throwable->getMessage(),
                        'form_type' => $formType,
                    ],
                ],
                $moderatorUser
            );
        }

        $pageError = $throwable instanceof RuntimeException
            ? $throwable->getMessage()
            : 'Moderator panel operation failed. Check the server logs and try again.';

        bg_log_event('mod-panel-errors', [
            'message' => $throwable->getMessage(),
            'file' => basename($throwable->getFile()),
            'line' => $throwable->getLine(),
            'appeal_reference' => $appealReference,
            'action' => $requestedAction,
            'form_type' => $formType,
        ]);
    }
}

if ($hasModeratorAccess && $canViewDashboard) {
    try {
        $dashboardCounts = bg_fetch_moderation_dashboard_counts();
        $recentAppeals = bg_fetch_recent_appeals(12);
    } catch (Throwable $throwable) {
        $pageError = $pageError ?? 'Could not load moderation dashboard data.';
        bg_log_event('mod-panel-errors', [
            'message' => $throwable->getMessage(),
            'file' => basename($throwable->getFile()),
            'line' => $throwable->getLine(),
            'stage' => 'dashboard',
        ]);
    }
}

if ($hasModeratorAccess && ($canViewStats || $canViewLoginRoster)) {
    try {
        $serverSnapshot = bg_fetch_moderation_server_snapshot(18);
    } catch (Throwable $throwable) {
        $pageError = $pageError ?? 'Could not load the live server statistics.';
        bg_log_event('mod-panel-errors', [
            'message' => $throwable->getMessage(),
            'file' => basename($throwable->getFile()),
            'line' => $throwable->getLine(),
            'stage' => 'server_stats',
        ]);
    }
}

if ($hasModeratorAccess && $canViewAudit) {
    try {
        $auditEntries = bg_fetch_mod_audit_entries(40);
    } catch (Throwable $throwable) {
        $pageError = $pageError ?? 'Could not load the moderator audit log.';
    }
}

if ($hasModeratorAccess && $canViewBans) {
    try {
        $activeBans = bg_fetch_active_ban_records_admin(32);
    } catch (Throwable $throwable) {
        $pageError = $pageError ?? 'Could not load the blocked-user list.';
    }
}

if ($canReviewAppeals && $appealReference !== '') {
    try {
        $record = bg_fetch_appeal_record_by_reference($appealReference);

        if ($record === null) {
            $pageError = 'The requested appeal record was not found.';
        }
    } catch (Throwable $throwable) {
        $pageError = 'Could not load the selected appeal record.';
        bg_log_event('mod-panel-errors', [
            'message' => $throwable->getMessage(),
            'file' => basename($throwable->getFile()),
            'line' => $throwable->getLine(),
            'appeal_reference' => $appealReference,
            'action' => $requestedAction,
            'stage' => 'load_record',
        ]);
    }
}

$authMessage = bg_mod_localize_message_payload($authMessage, $uiLang);
$flashResult = bg_mod_localize_message_payload($flashResult, $uiLang);
$panelMessage = bg_mod_localize_message_payload($panelMessage, $uiLang);
$pageError = bg_mod_translate_runtime_message($pageError, $uiLang);

$currentAppealChannelId = bg_effective_appeal_channel_id();
$currentWebhookUrl = bg_effective_webhook_url();
$webhookHost = bg_mod_webhook_host($currentWebhookUrl);
$loginUrl = bg_mod_login_url(
    $appealReference !== ''
        ? bg_mod_page_url(['appeal' => $appealReference, 'action' => $requestedAction])
        : bg_mod_page_url()
);
$logoutUrl = bg_public_url('mod/logout.php');
$csrfActionToken = bg_csrf_token('mod_moderation_action');
$csrfSettingsToken = bg_csrf_token('mod_channel_settings');
$csrfTestsToken = bg_csrf_token('mod_test_actions');
$csrfAdminBotPostToken = bg_csrf_token('mod_admin_bot_post');
$csrfDirectActionToken = bg_csrf_token('mod_direct_actions');
$csrfPermissionToken = bg_csrf_token('mod_permission_policy');
$recordSubtitle = $record !== null
    ? trim((string) ($record['discord_username'] ?? '')) . ' | ' . trim((string) ($record['public_reference'] ?? ''))
    : bg_mod_t('hero.subtitle', [], $uiLang);
$displayMode = $hasModeratorAccess ? 'discord_session' : ($hasSignedAccess ? 'signed_link' : 'guest');
$panelUrl = $record !== null ? bg_mod_record_url((string) $record['public_reference'], 'panel', $displayMode, $expiresAt, $signature) : bg_mod_page_url();
$unbanUrl = $record !== null ? bg_mod_record_url((string) $record['public_reference'], 'unban', $displayMode, $expiresAt, $signature) : $panelUrl;
$rejectUrl = $record !== null ? bg_mod_record_url((string) $record['public_reference'], 'reject', $displayMode, $expiresAt, $signature) : $panelUrl;
$languageUrls = [
    'en' => bg_mod_current_view_url('en', $appealReference, $requestedAction, $expiresAt, $signature, $displayMode),
    'bg' => bg_mod_current_view_url('bg', $appealReference, $requestedAction, $expiresAt, $signature, $displayMode),
];

$loginRoster = is_array($serverSnapshot['login_roster'] ?? null) ? $serverSnapshot['login_roster'] : [];
$loginRosterInServer = count(array_filter($loginRoster, static fn (array $row): bool => (bool) ($row['in_server'] ?? false)));
$loginRosterOutsideServer = max(0, count($loginRoster) - $loginRosterInServer);
$serverTrend = is_array($serverSnapshot['audit_trend'] ?? null) ? $serverSnapshot['audit_trend'] : [];
$communityChartMetrics = [
    [
        'label' => bg_mod_t('metric.members', [], $uiLang),
        'value' => (int) ($serverSnapshot['members'] ?? 0),
    ],
    [
        'label' => bg_mod_t('metric.online', [], $uiLang),
        'value' => (int) ($serverSnapshot['online'] ?? 0),
    ],
    [
        'label' => bg_mod_t('metric.in_voice', [], $uiLang),
        'value' => (int) ($serverSnapshot['voice_users'] ?? 0),
    ],
];
$moderationChartMetrics = [
    [
        'label' => bg_mod_t('counts.active_bans', [], $uiLang),
        'value' => (int) ($serverSnapshot['active_bans'] ?? 0),
    ],
    [
        'label' => bg_mod_t('counts.pending_appeals', [], $uiLang),
        'value' => (int) ($serverSnapshot['pending_appeals'] ?? 0),
    ],
    [
        'label' => bg_mod_t('counts.approved_appeals', [], $uiLang),
        'value' => (int) ($serverSnapshot['approved_appeals'] ?? 0),
    ],
];
$channelMixMetrics = [
    [
        'label' => bg_mod_t('metric.text', [], $uiLang),
        'value' => (int) ($serverSnapshot['text_channels'] ?? 0),
    ],
    [
        'label' => bg_mod_t('metric.voice', [], $uiLang),
        'value' => (int) ($serverSnapshot['voice_channels'] ?? 0),
    ],
    [
        'label' => bg_mod_t('metric.stage', [], $uiLang),
        'value' => (int) ($serverSnapshot['stage_channels'] ?? 0),
    ],
    [
        'label' => bg_mod_t('metric.forum', [], $uiLang),
        'value' => (int) ($serverSnapshot['forum_channels'] ?? 0),
    ],
];
$directActionButtons = [
    [
        'action' => 'ban',
        'label' => bg_mod_t('actions.ban', [], $uiLang),
        'class' => 'btn-danger-soft',
        'enabled' => $canDirectBan,
    ],
    [
        'action' => 'unban',
        'label' => bg_mod_t('actions.unban', [], $uiLang),
        'class' => 'btn-success-soft',
        'enabled' => $canDirectUnban,
    ],
    [
        'action' => 'kick',
        'label' => bg_mod_t('actions.kick', [], $uiLang),
        'class' => 'btn-ghost',
        'enabled' => $canKickMembers,
    ],
    [
        'action' => 'warning',
        'label' => bg_mod_t('actions.warning', [], $uiLang),
        'class' => 'btn-ghost',
        'enabled' => $canWarnMembers,
    ],
    [
        'action' => 'timeout',
        'label' => bg_mod_t('actions.timeout', [], $uiLang),
        'class' => 'btn-ghost',
        'enabled' => $canTimeoutMembers,
    ],
    [
        'action' => 'remove_timeout',
        'label' => bg_mod_t('actions.remove_timeout', [], $uiLang),
        'class' => 'btn-ghost',
        'enabled' => $canRemoveTimeouts,
    ],
];
$settingsTabs = [];

if ($canManageChannel) {
    $settingsTabs[] = [
        'key' => 'routing',
        'label' => bg_mod_t('settings.tab.routing', [], $uiLang),
        'description' => bg_mod_t('settings.tab.routing_desc', [], $uiLang),
    ];
}

if ($canSendTests) {
    $settingsTabs[] = [
        'key' => 'tests',
        'label' => bg_mod_t('settings.tab.tests', [], $uiLang),
        'description' => bg_mod_t('settings.tab.tests_desc', [], $uiLang),
    ];
}

if ($canEditPermissions) {
    $settingsTabs[] = [
        'key' => 'permissions',
        'label' => bg_mod_t('settings.tab.permissions', [], $uiLang),
        'description' => bg_mod_t('settings.tab.permissions_desc', [], $uiLang),
    ];
}

$defaultSettingsTab = (string) ($settingsTabs[0]['key'] ?? '');
$workspaceViews = [];

if ($hasModeratorAccess && $canViewStats) {
    $workspaceViews[] = [
        'key' => 'overview',
        'label' => bg_mod_t('workspace.overview', [], $uiLang),
        'description' => bg_mod_t('workspace.overview_desc', [], $uiLang),
    ];
}

if ($record !== null || $hasModeratorAccess && ($hasAnyDirectActionAccess || $canViewBans || $canViewDashboard)) {
    $workspaceViews[] = [
        'key' => 'moderation',
        'label' => bg_mod_t('workspace.moderation', [], $uiLang),
        'description' => bg_mod_t('workspace.moderation_desc', [], $uiLang),
    ];
}

if ($hasModeratorAccess && $settingsTabs !== []) {
    $workspaceViews[] = [
        'key' => 'settings',
        'label' => bg_mod_t('workspace.settings', [], $uiLang),
        'description' => bg_mod_t('workspace.settings_desc', [], $uiLang),
    ];
}

if ($hasModeratorAccess && $canViewAudit) {
    $workspaceViews[] = [
        'key' => 'audit',
        'label' => bg_mod_t('workspace.audit', [], $uiLang),
        'description' => bg_mod_t('workspace.audit_desc', [], $uiLang),
    ];
}

$defaultWorkspaceView = $record !== null || $requestedAction !== 'panel'
    ? 'moderation'
    : (string) ($workspaceViews[0]['key'] ?? 'overview');
$surfaceQuickLinks = [];

if ($hasModeratorAccess && $canViewStats) {
    $surfaceQuickLinks[] = [
        'label' => bg_mod_t('shortcut.stats', [], $uiLang),
        'view' => 'overview',
        'target' => '#server-stats',
    ];
}

if ($hasModeratorAccess && $canViewLoginRoster) {
    $surfaceQuickLinks[] = [
        'label' => bg_mod_t('shortcut.logins', [], $uiLang),
        'view' => 'overview',
        'target' => '#login-roster-panel',
    ];
}

if ($hasModeratorAccess && $hasAnyDirectActionAccess) {
    $surfaceQuickLinks[] = [
        'label' => bg_mod_t('shortcut.actions', [], $uiLang),
        'view' => 'moderation',
        'target' => '#direct-actions',
    ];
}

if ($hasModeratorAccess && $canViewDashboard) {
    $surfaceQuickLinks[] = [
        'label' => bg_mod_t('shortcut.appeals', [], $uiLang),
        'view' => 'moderation',
        'target' => '#review-queue-panel',
    ];
}

if ($hasModeratorAccess && $canViewBans) {
    $surfaceQuickLinks[] = [
        'label' => bg_mod_t('shortcut.bans', [], $uiLang),
        'view' => 'moderation',
        'target' => '#blocked-list',
    ];
}

if ($hasModeratorAccess && $settingsTabs !== []) {
    $surfaceQuickLinks[] = [
        'label' => bg_mod_t('shortcut.settings', [], $uiLang),
        'view' => 'settings',
        'target' => '#panel-settings',
    ];
}

if ($hasModeratorAccess && $canViewAudit) {
    $surfaceQuickLinks[] = [
        'label' => bg_mod_t('shortcut.audit', [], $uiLang),
        'view' => 'audit',
        'target' => '#audit-log',
    ];
}

$membersValue = (int) ($serverSnapshot['members'] ?? 0);
$onlineValue = (int) ($serverSnapshot['online'] ?? 0);
$voiceValue = (int) ($serverSnapshot['voice_users'] ?? 0);
$activeBansValue = (int) ($serverSnapshot['active_bans'] ?? 0);
$pendingAppealsValue = (int) ($serverSnapshot['pending_appeals'] ?? 0);
$approvedAppealsValue = (int) ($serverSnapshot['approved_appeals'] ?? 0);
$onlineRatio = $membersValue > 0 ? max(0.0, min(100.0, round(($onlineValue / $membersValue) * 100, 1))) : 0.0;
$voiceRatio = $membersValue > 0 ? max(0.0, min(100.0, round(($voiceValue / $membersValue) * 100, 1))) : 0.0;
$moderationTotalValue = max(1, $activeBansValue + $pendingAppealsValue + $approvedAppealsValue);
$pendingRatio = max(0.0, min(100.0, round(($pendingAppealsValue / $moderationTotalValue) * 100, 1)));
$approvedRatio = max(0.0, min(100.0, round(($approvedAppealsValue / $moderationTotalValue) * 100, 1)));
$communityMetricMax = max(1, $membersValue, $onlineValue, $voiceValue);
$communitySummaryMetrics = [
    [
        'label' => bg_mod_t('metric.members', [], $uiLang),
        'value' => $membersValue,
        'ratio' => round(($membersValue / $communityMetricMax) * 100, 1),
        'accent' => '#c77fff',
    ],
    [
        'label' => bg_mod_t('metric.online', [], $uiLang),
        'value' => $onlineValue,
        'ratio' => round(($onlineValue / $communityMetricMax) * 100, 1),
        'accent' => '#74c7ff',
    ],
    [
        'label' => bg_mod_t('metric.in_voice', [], $uiLang),
        'value' => $voiceValue,
        'ratio' => round(($voiceValue / $communityMetricMax) * 100, 1),
        'accent' => '#b987ff',
    ],
];
$moderationMetricMax = max(1, $activeBansValue, $pendingAppealsValue, $approvedAppealsValue);
$moderationSummaryMetrics = [
    [
        'label' => bg_mod_t('counts.active_bans_short', [], $uiLang),
        'value' => $activeBansValue,
        'ratio' => round(($activeBansValue / $moderationMetricMax) * 100, 1),
        'accent' => '#ff9d7a',
    ],
    [
        'label' => bg_mod_t('counts.pending_short', [], $uiLang),
        'value' => $pendingAppealsValue,
        'ratio' => round(($pendingAppealsValue / $moderationMetricMax) * 100, 1),
        'accent' => '#ffb462',
    ],
    [
        'label' => bg_mod_t('counts.approved_short', [], $uiLang),
        'value' => $approvedAppealsValue,
        'ratio' => round(($approvedAppealsValue / $moderationMetricMax) * 100, 1),
        'accent' => '#7ef0c3',
    ],
];
$editableRoleLabels = [
    bg_mod_t('settings.permissions.moderator', [], $uiLang),
    bg_mod_t('settings.permissions.support', [], $uiLang),
    bg_mod_t('settings.permissions.social_manager', [], $uiLang),
];
$channelLegend = [
    [
        'label' => bg_mod_t('metric.text', [], $uiLang),
        'value' => (int) ($serverSnapshot['text_channels'] ?? 0),
        'color' => '#74c7ff',
    ],
    [
        'label' => bg_mod_t('metric.voice', [], $uiLang),
        'value' => (int) ($serverSnapshot['voice_channels'] ?? 0),
        'color' => '#b987ff',
    ],
    [
        'label' => bg_mod_t('metric.stage', [], $uiLang),
        'value' => (int) ($serverSnapshot['stage_channels'] ?? 0),
        'color' => '#7ef0c3',
    ],
    [
        'label' => bg_mod_t('metric.forum', [], $uiLang),
        'value' => (int) ($serverSnapshot['forum_channels'] ?? 0),
        'color' => '#f7a8ff',
    ],
];
$channelTotalValue = 0;

foreach ($channelLegend as $segment) {
    $channelTotalValue += (int) $segment['value'];
}

$donutOffset = 0.0;
$channelSegments = [];

foreach ($channelLegend as $segment) {
    $segmentValue = (int) $segment['value'];

    if ($channelTotalValue <= 0 || $segmentValue <= 0) {
        continue;
    }

    $segmentDegrees = ($segmentValue / $channelTotalValue) * 360;
    $segmentEnd = $donutOffset + $segmentDegrees;
    $channelSegments[] = sprintf('%s %.3fdeg %.3fdeg', $segment['color'], $donutOffset, $segmentEnd);
    $donutOffset = $segmentEnd;
}

$channelDonutGradient = $channelSegments !== []
    ? 'conic-gradient(from -90deg, ' . implode(', ', $channelSegments) . ')'
    : 'conic-gradient(from -90deg, rgba(255,255,255,0.08) 0deg 360deg)';

$auditChartEntries = $serverTrend;

if ($auditChartEntries === []) {
    for ($offset = 6; $offset >= 0; $offset--) {
        $auditChartEntries[] = [
            'label' => date('d.m', strtotime('-' . $offset . ' days')),
            'count' => 0,
        ];
    }
}

$auditPeakEntry = ['label' => '', 'count' => 0];
$auditTotalActions = 0;

foreach ($auditChartEntries as $entry) {
    $entryCount = (int) ($entry['count'] ?? 0);
    $auditTotalActions += $entryCount;

    if ($entryCount >= (int) $auditPeakEntry['count']) {
        $auditPeakEntry = [
            'label' => (string) ($entry['label'] ?? ''),
            'count' => $entryCount,
        ];
    }
}

$chartWidth = 420;
$chartHeight = 166;
$chartPaddingX = 16;
$chartPaddingY = 16;
$chartBaseline = $chartHeight - 18;
$chartPlotHeight = $chartBaseline - $chartPaddingY;
$auditTrendMax = 1;

foreach ($auditChartEntries as $entry) {
    $auditTrendMax = max($auditTrendMax, (int) ($entry['count'] ?? 0));
}

$trendPointData = [];
$trendLineParts = [];
$entryCount = count($auditChartEntries);
$stepX = $entryCount > 1 ? (($chartWidth - ($chartPaddingX * 2)) / ($entryCount - 1)) : 0;

foreach ($auditChartEntries as $index => $entry) {
    $entryValue = (int) ($entry['count'] ?? 0);
    $pointX = $chartPaddingX + ($stepX * $index);
    $pointRatio = $auditTrendMax > 0 ? ($entryValue / $auditTrendMax) : 0;
    $pointY = $chartBaseline - ($pointRatio * max(1, $chartPlotHeight - 10));
    $trendLineParts[] = sprintf('%s %.2f %.2f', $index === 0 ? 'M' : 'L', $pointX, $pointY);
    $trendPointData[] = [
        'x' => $pointX,
        'y' => $pointY,
        'count' => $entryValue,
        'label' => (string) ($entry['label'] ?? ''),
    ];
}

$trendLinePath = implode(' ', $trendLineParts);
$trendAreaPath = '';

if ($trendPointData !== []) {
    $firstPoint = $trendPointData[0];
    $lastPoint = $trendPointData[count($trendPointData) - 1];
    $trendAreaPath = $trendLinePath
        . sprintf(' L %.2f %.2f L %.2f %.2f Z', $lastPoint['x'], $chartBaseline, $firstPoint['x'], $chartBaseline);
}
?>
<!DOCTYPE html>
<html lang="<?php echo bg_escape($uiLang); ?>">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?php echo bg_escape(bg_mod_t('page.title', [], $uiLang)); ?></title>
  <meta name="robots" content="noindex,nofollow">
  <meta name="description" content="<?php echo bg_escape(bg_mod_t('page.meta', [], $uiLang)); ?>">
  <link rel="icon" type="image/png" href="../assets/bg-gamer-logo.png?v=20260714-3">
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link
    href="https://fonts.googleapis.com/css2?family=Barlow+Condensed:wght@500;600;700;800&family=IBM+Plex+Mono:wght@400;500;600&family=Manrope:wght@400;500;600;700;800&display=swap"
    rel="stylesheet"
  >
  <link
    href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/css/bootstrap.min.css"
    rel="stylesheet"
    integrity="sha384-sRIl4kxILFvY47J16cr9ZwB07vP4J8+LH7qKQnuqkuIAvNWLzeN8tE5YBujZqJLB"
    crossorigin="anonymous"
  >
  <link rel="stylesheet" href="../style.css?v=20260714-7">
  <style>
    .mod-shell {
      padding: clamp(4rem, 7vw, 6.2rem) 0;
    }

    .mod-layout {
      display: grid;
      grid-template-columns: minmax(0, 1.12fr) minmax(17rem, 18.5rem);
      gap: 1.15rem;
      align-items: start;
    }

    .mod-top-controls {
      display: grid;
      grid-template-columns: minmax(0, 1.35fr) minmax(18rem, 0.95fr);
      gap: 1rem;
      margin: 0 auto 1.3rem;
      padding: 1rem 1.05rem;
      max-width: min(100%, 74rem);
      border-radius: 26px;
      border: 1px solid rgba(255, 255, 255, 0.08);
      background:
        linear-gradient(180deg, rgba(156, 96, 255, 0.08), rgba(255, 255, 255, 0.02)),
        rgba(12, 18, 32, 0.86);
      box-shadow: 0 18px 44px rgba(0, 0, 0, 0.2);
      align-items: stretch;
    }

    .mod-control-card {
      border-radius: 20px;
      padding: 0.35rem 0.4rem;
      border: 0;
      background: transparent;
      box-shadow: none;
      min-width: 0;
    }

    .mod-control-card__header {
      display: flex;
      justify-content: center;
      gap: 0.9rem;
      align-items: start;
      flex-wrap: wrap;
      text-align: center;
    }

    .mod-control-card__header h2 {
      margin: 0.25rem 0 0;
      font-size: 1.05rem;
      line-height: 1.1;
    }

    .mod-control-card__header p {
      margin: 0.3rem 0 0;
      color: var(--text-soft);
      font-size: 0.92rem;
      line-height: 1.45;
    }

    .mod-quick-nav {
      display: flex;
      flex-wrap: wrap;
      gap: 0.65rem;
      margin-top: 0.95rem;
      justify-content: center;
    }

    .mod-quick-link {
      display: inline-flex;
      align-items: center;
      justify-content: center;
      padding: 0.7rem 0.9rem;
      border-radius: 999px;
      border: 1px solid rgba(255, 255, 255, 0.08);
      background: rgba(255, 255, 255, 0.035);
      color: var(--text);
      font-weight: 700;
      font-size: 0.9rem;
      line-height: 1;
      transition: transform 180ms ease, border-color 180ms ease, background 180ms ease, color 180ms ease;
    }

    .mod-quick-link:hover,
    .mod-quick-link:focus-visible {
      transform: translateY(-1px);
      border-color: rgba(190, 132, 255, 0.28);
      background: rgba(255, 255, 255, 0.055);
      color: var(--text);
    }

    .mod-stack,
    .mod-sidebar {
      display: grid;
      gap: 1.15rem;
      min-width: 0;
    }

    .mod-sidebar {
      width: 100%;
      max-width: 18.5rem;
      justify-self: end;
    }

    .mod-card,
    .mod-sidebar-card,
    .mod-compact-card,
    .mod-list-item,
    .mod-audit-item {
      border: 1px solid rgba(255, 255, 255, 0.08);
      background: rgba(12, 18, 32, 0.86);
      box-shadow: 0 24px 54px rgba(0, 0, 0, 0.24);
      min-width: 0;
    }

    .mod-card,
    .mod-sidebar-card,
    .mod-compact-card {
      border-radius: 24px;
      padding: 1.35rem;
    }

    .mod-header {
      display: flex;
      justify-content: space-between;
      align-items: start;
      gap: 1rem;
      flex-wrap: wrap;
      margin-bottom: 1.05rem;
    }

    .mod-header h1,
    .mod-card h2,
    .mod-sidebar-card h2,
    .mod-compact-card h2 {
      margin: 0;
    }

    .mod-header h1 {
      font-size: clamp(2.1rem, 4.2vw, 3.5rem);
      line-height: 0.96;
      letter-spacing: -0.04em;
    }

    .mod-card h2,
    .mod-compact-card h2 {
      font-size: clamp(1.5rem, 2vw, 2.2rem);
      line-height: 1.02;
      letter-spacing: -0.03em;
    }

    .mod-sidebar-card h2 {
      font-size: clamp(1.45rem, 1.9vw, 2rem);
      line-height: 1;
      letter-spacing: -0.03em;
    }

    .mod-copy,
    .mod-note,
    .mod-sidebar-card p,
    .mod-card p {
      color: var(--text-soft);
    }

    .mod-kicker,
    .mod-label,
    .mod-mini-label {
      display: block;
      color: var(--text-dim);
      font-family: "IBM Plex Mono", monospace;
      letter-spacing: 0.12em;
      text-transform: uppercase;
    }

    .mod-kicker,
    .mod-label {
      font-size: 0.76rem;
    }

    .mod-mini-label {
      font-size: 0.68rem;
      letter-spacing: 0.09em;
    }

    .mod-badge-row,
    .mod-pill-row,
    .mod-actions,
    .mod-inline-actions {
      display: flex;
      flex-wrap: wrap;
      gap: 0.65rem;
      align-items: center;
    }

    .mod-badge,
    .mod-pill,
    .mod-status {
      display: inline-flex;
      align-items: center;
      gap: 0.42rem;
      padding: 0.58rem 0.82rem;
      border-radius: 999px;
      border: 1px solid rgba(255, 255, 255, 0.08);
      background: rgba(255, 255, 255, 0.04);
      color: var(--text);
      font-weight: 700;
    }

    .mod-status--success,
    .mod-badge--success {
      border-color: rgba(101, 211, 159, 0.28);
      background: rgba(101, 211, 159, 0.12);
      color: #ddfff0;
    }

    .mod-status--warning,
    .mod-badge--warning {
      border-color: rgba(255, 180, 98, 0.24);
      background: rgba(255, 180, 98, 0.12);
      color: #ffe7c9;
    }

    .mod-status--danger,
    .mod-badge--danger {
      border-color: rgba(255, 130, 96, 0.28);
      background: rgba(255, 130, 96, 0.12);
      color: #ffd1c6;
    }

    .mod-alert {
      margin-bottom: 1rem;
      padding: 0.95rem 1rem;
      border-radius: 18px;
      border: 1px solid rgba(255, 255, 255, 0.08);
      background: rgba(255, 255, 255, 0.04);
    }

    .mod-alert--error {
      border-color: rgba(255, 130, 96, 0.28);
      background: rgba(255, 130, 96, 0.1);
      color: #ffd1c6;
    }

    .mod-alert--success {
      border-color: rgba(101, 211, 159, 0.28);
      background: rgba(101, 211, 159, 0.1);
      color: #ddfff0;
    }

    .mod-panel-grid,
    .mod-detail-grid,
    .mod-stat-grid,
    .mod-stat-cards,
    .mod-chart-grid,
    .mod-settings-grid,
    .mod-roster-grid {
      display: grid;
      grid-template-columns: repeat(2, minmax(0, 1fr));
      gap: 0.95rem;
      min-width: 0;
    }

    .mod-stat-cards {
      grid-template-columns: repeat(4, minmax(0, 1fr));
    }

    .mod-chart-grid {
      grid-template-columns: 1.2fr 1fr;
    }

    .mod-panel-grid article,
    .mod-detail-grid article,
    .mod-stat-grid article,
    .mod-stat-cards article,
    .mod-chart-card,
    .mod-settings-grid article,
    .mod-roster-grid article {
      border-radius: 18px;
      padding: 1rem 1.05rem;
      border: 1px solid rgba(255, 255, 255, 0.08);
      background: rgba(255, 255, 255, 0.03);
      min-width: 0;
    }

    .mod-panel-grid strong,
    .mod-detail-grid strong,
    .mod-stat-grid strong,
    .mod-stat-cards strong,
    .mod-settings-grid strong,
    .mod-roster-grid strong {
      display: block;
      margin-top: 0.38rem;
      font-size: 1rem;
      color: var(--text);
      word-break: break-word;
      white-space: pre-wrap;
    }

    .mod-stat-cards strong {
      font-size: clamp(1.35rem, 2vw, 1.85rem);
      line-height: 1;
    }

    .mod-form,
    .mod-settings-form,
    .mod-direct-form {
      display: grid;
      gap: 0.85rem;
      margin-top: 1rem;
    }

    .mod-form label,
    .mod-settings-form label,
    .mod-direct-form label {
      display: grid;
      gap: 0.45rem;
      color: var(--text-soft);
      font-weight: 600;
      min-width: 0;
    }

    .mod-form input,
    .mod-form textarea,
    .mod-settings-form input,
    .mod-settings-form textarea,
    .mod-direct-form input,
    .mod-direct-form textarea {
      width: 100%;
      border: 1px solid rgba(255, 255, 255, 0.08);
      border-radius: 16px;
      padding: 0.9rem 1rem;
      background: rgba(255, 255, 255, 0.03);
      color: var(--text);
      min-width: 0;
    }

    .mod-form textarea,
    .mod-settings-form textarea,
    .mod-direct-form textarea {
      min-height: 7.5rem;
      resize: vertical;
    }

    .mod-direct-form__grid {
      display: grid;
      grid-template-columns: repeat(2, minmax(0, 1fr));
      gap: 0.85rem;
    }

    .mod-actions .btn,
    .mod-inline-actions .btn,
    .mod-direct-form__buttons .btn {
      flex: 1 1 11rem;
    }

    .mod-direct-form__buttons {
      display: flex;
      flex-wrap: wrap;
      gap: 0.75rem;
    }

    .mod-divider {
      height: 1px;
      margin: 1rem 0;
      background: rgba(255, 255, 255, 0.08);
    }

    .mod-user-card {
      display: grid;
      grid-template-columns: auto minmax(0, 1fr);
      gap: 0.85rem;
      align-items: center;
    }

    .mod-user-card__meta {
      min-width: 0;
    }

    .mod-user__avatar {
      width: 3.2rem;
      height: 3.2rem;
      border-radius: 999px;
      object-fit: cover;
      border: 1px solid rgba(255, 255, 255, 0.1);
      background: rgba(255, 255, 255, 0.04);
    }

    .mod-user__avatar--fallback {
      display: inline-flex;
      align-items: center;
      justify-content: center;
      font-family: "IBM Plex Mono", monospace;
      font-weight: 700;
      color: var(--text);
    }

    .mod-user-card strong {
      display: block;
    }

    .mod-user-card small {
      color: var(--text-soft);
      word-break: break-word;
    }

    .mod-session-summary {
      margin: 0.78rem 0 0;
      color: var(--text-soft);
      font-size: 0.95rem;
      line-height: 1.45;
    }

    .mod-user-card__roles {
      display: flex;
      flex-wrap: wrap;
      gap: 0.45rem;
      margin-top: 0.55rem;
    }

    .mod-user-card__role {
      padding: 0.38rem 0.62rem;
      font-size: 0.72rem;
      letter-spacing: 0.04em;
    }

    .topbar-lang-switch {
      display: inline-flex;
      align-items: center;
      gap: 0.45rem;
      margin-left: 1rem;
      padding: 0.28rem;
      border: 1px solid rgba(255, 255, 255, 0.08);
      border-radius: 999px;
      background: rgba(255, 255, 255, 0.03);
    }

    .topbar-lang-switch__item {
      min-width: 2.75rem;
      padding: 0.48rem 0.7rem;
      border-radius: 999px;
      color: var(--text-soft);
      font-family: "IBM Plex Mono", monospace;
      font-size: 0.8rem;
      letter-spacing: 0.08em;
      text-align: center;
      text-decoration: none;
      transition: background 180ms ease, color 180ms ease, transform 180ms ease;
    }

    .topbar-lang-switch__item:hover,
    .topbar-lang-switch__item:focus-visible {
      color: var(--text);
      background: rgba(255, 255, 255, 0.06);
      transform: translateY(-1px);
    }

    .topbar-lang-switch__item.is-active {
      color: var(--text);
      background: rgba(156, 96, 255, 0.16);
      box-shadow: inset 0 1px 0 rgba(255, 255, 255, 0.04);
    }

    .mod-tab-nav {
      display: flex;
      flex-wrap: wrap;
      gap: 0.65rem;
      margin-bottom: 1rem;
      border: 0;
    }

    .mod-tab-nav .nav-link {
      min-height: auto;
      padding: 0.72rem 0.98rem;
      border: 1px solid rgba(255, 255, 255, 0.08);
      background: rgba(255, 255, 255, 0.03);
      color: var(--text-soft);
      border-radius: 16px;
      font-weight: 700;
    }

    .mod-tab-nav .nav-link.active {
      color: var(--text);
      background: rgba(156, 96, 255, 0.16);
      border-color: rgba(190, 132, 255, 0.32);
      box-shadow: inset 0 1px 0 rgba(255, 255, 255, 0.04);
    }

    .mod-tab-nav .nav-link small {
      display: block;
      margin-top: 0.22rem;
      color: var(--text-dim);
      font-size: 0.68rem;
      font-family: "IBM Plex Mono", monospace;
      letter-spacing: 0.06em;
      text-transform: uppercase;
    }

    .mod-tab-content {
      min-width: 0;
    }

    .mod-tab-pane {
      border-radius: 20px;
      border: 1px solid rgba(255, 255, 255, 0.07);
      background: rgba(255, 255, 255, 0.02);
      padding: 1.15rem;
      min-width: 0;
    }

    .mod-tab-pane h3 {
      margin: 0.4rem 0 0;
      font-size: 1.24rem;
    }

    .mod-settings-shell {
      display: grid;
      grid-template-columns: minmax(15.5rem, 18rem) minmax(0, 1fr);
      gap: 1rem;
      margin-top: 1rem;
      align-items: start;
    }

    .mod-settings-rail,
    .mod-settings-main {
      min-width: 0;
    }

    .mod-settings-rail {
      border-radius: 22px;
      border: 1px solid rgba(255, 255, 255, 0.08);
      background:
        linear-gradient(180deg, rgba(168, 112, 255, 0.08), rgba(255, 255, 255, 0.03)),
        rgba(255, 255, 255, 0.03);
      padding: 1rem;
      display: grid;
      gap: 0.95rem;
      position: sticky;
      top: 7.2rem;
    }

    .mod-settings-rail__intro {
      display: grid;
      gap: 0.3rem;
    }

    .mod-settings-rail__intro h3 {
      margin: 0;
      font-size: 1rem;
      line-height: 1.05;
    }

    .mod-settings-rail__intro p {
      margin: 0;
      color: var(--text-soft);
      font-size: 0.88rem;
      line-height: 1.45;
    }

    .mod-settings-rail__summary,
    .mod-settings-summary-grid {
      display: grid;
      gap: 0.75rem;
    }

    .mod-settings-rail__summary article,
    .mod-settings-summary-grid article {
      border-radius: 16px;
      border: 1px solid rgba(255, 255, 255, 0.07);
      background: rgba(255, 255, 255, 0.03);
      padding: 0.82rem 0.88rem;
      min-width: 0;
    }

    .mod-settings-rail__summary strong,
    .mod-settings-summary-grid strong {
      display: block;
      margin-top: 0.3rem;
      font-size: 1rem;
      line-height: 1.15;
      word-break: break-word;
    }

    .mod-settings-summary-grid {
      grid-template-columns: repeat(3, minmax(0, 1fr));
      margin-bottom: 1rem;
    }

    .mod-tab-nav--stacked {
      display: grid;
      gap: 0.72rem;
      margin: 0;
      padding: 0;
      list-style: none;
    }

    .mod-tab-nav--stacked .nav-item {
      margin: 0;
    }

    .mod-tab-nav--stacked .nav-link {
      width: 100%;
      min-height: 5.1rem;
      text-align: left;
      justify-content: flex-start;
      align-items: flex-start;
      border-radius: 18px;
      padding: 0.92rem 0.98rem;
      border: 1px solid rgba(255, 255, 255, 0.08);
      background: rgba(255, 255, 255, 0.025);
      transition: border-color 180ms ease, background 180ms ease, transform 180ms ease, color 180ms ease;
    }

    .mod-tab-nav--stacked .nav-link:hover,
    .mod-tab-nav--stacked .nav-link:focus-visible {
      border-color: rgba(190, 132, 255, 0.28);
      transform: translateY(-1px);
    }

    .mod-tab-nav--stacked .nav-link.active {
      background:
        linear-gradient(180deg, rgba(190, 132, 255, 0.18), rgba(255, 255, 255, 0.03)),
        rgba(255, 255, 255, 0.03);
      border-color: rgba(190, 132, 255, 0.34);
      box-shadow: inset 0 1px 0 rgba(255, 255, 255, 0.05);
    }

    .mod-workspace-nav {
      display: grid;
      grid-template-columns: repeat(4, minmax(0, 1fr));
      gap: 0.8rem;
      margin-top: 1.25rem;
    }

    .mod-workspace-nav--toolbar {
      max-width: 46rem;
      margin: 0.95rem auto 0;
    }

    .mod-workspace-tab {
      width: 100%;
      padding: 0.95rem 1rem;
      border-radius: 18px;
      border: 1px solid rgba(255, 255, 255, 0.08);
      background: rgba(255, 255, 255, 0.03);
      color: var(--text-soft);
      text-align: left;
      transition: border-color 180ms ease, background 180ms ease, transform 180ms ease, color 180ms ease;
    }

    .mod-workspace-tab:hover,
    .mod-workspace-tab:focus-visible {
      color: var(--text);
      transform: translateY(-1px);
      border-color: rgba(190, 132, 255, 0.24);
    }

    .mod-workspace-tab.is-active {
      color: var(--text);
      background:
        linear-gradient(180deg, rgba(170, 108, 255, 0.16), rgba(255, 255, 255, 0.03)),
        rgba(255, 255, 255, 0.03);
      border-color: rgba(190, 132, 255, 0.36);
      box-shadow: inset 0 1px 0 rgba(255, 255, 255, 0.04);
    }

    .mod-workspace-tab strong,
    .mod-workspace-tab small {
      display: block;
    }

    .mod-workspace-tab strong {
      font-size: 1.05rem;
      letter-spacing: 0.01em;
    }

    .mod-workspace-tab small {
      margin-top: 0.3rem;
      color: var(--text-dim);
      font-size: 0.76rem;
      line-height: 1.35;
    }

    .mod-pane,
    [data-mod-sidebar-pane] {
      display: none;
    }

    .mod-pane.is-active,
    [data-mod-sidebar-pane].is-active {
      display: block;
    }

    .mod-visual-grid {
      display: grid;
      grid-template-columns: repeat(2, minmax(0, 1fr));
      gap: 1rem;
      margin-top: 1rem;
    }

    .mod-visual-card {
      border-radius: 22px;
      padding: 1.15rem;
      border: 1px solid rgba(255, 255, 255, 0.08);
      background:
        radial-gradient(circle at top right, rgba(156, 96, 255, 0.14), transparent 40%),
        rgba(255, 255, 255, 0.03);
      display: grid;
      align-content: start;
      min-height: 100%;
      min-width: 0;
      overflow: hidden;
    }

    .mod-visual-card--primary,
    .mod-visual-card--audit {
      grid-column: 1 / -1;
    }

    .mod-visual-card--primary {
      padding: 1.3rem 1.3rem 1.2rem;
    }

    .mod-visual-card__header {
      display: flex;
      justify-content: space-between;
      gap: 0.8rem;
      align-items: start;
      margin-bottom: 1rem;
    }

    .mod-visual-card__header h3 {
      margin: 0.34rem 0 0;
      font-size: 1.2rem;
      line-height: 1.08;
      max-width: 18ch;
      text-wrap: balance;
    }

    .mod-visual-card__body {
      display: grid;
      grid-template-columns: minmax(0, 1.15fr) minmax(12rem, 0.95fr);
      gap: 1rem;
      align-items: center;
    }

    .mod-visual-card--primary .mod-visual-card__body {
      grid-template-columns: minmax(0, 1.3fr) minmax(18rem, 1fr);
      gap: 1.2rem;
      align-items: stretch;
    }

    .mod-focus-metric {
      display: grid;
      gap: 0.72rem;
    }

    .mod-focus-metric__value {
      display: grid;
      gap: 0.18rem;
    }

    .mod-focus-metric__value strong {
      font-size: clamp(2rem, 4vw, 3rem);
      line-height: 0.95;
      letter-spacing: -0.04em;
    }

    .mod-focus-metric__meta {
      display: flex;
      flex-wrap: wrap;
      gap: 0.55rem;
    }

    .mod-focus-chip {
      display: inline-flex;
      align-items: center;
      gap: 0.42rem;
      padding: 0.52rem 0.7rem;
      border-radius: 999px;
      border: 1px solid rgba(255, 255, 255, 0.08);
      background: rgba(255, 255, 255, 0.04);
      color: var(--text-soft);
      font-size: 0.84rem;
      font-weight: 700;
    }

    .mod-focus-chip strong {
      font-size: 0.96rem;
      letter-spacing: 0;
      color: var(--text);
    }

    .mod-ring-grid {
      display: grid;
      gap: 0.85rem;
    }

    .mod-ring-meter {
      display: grid;
      grid-template-columns: 4.6rem minmax(0, 1fr);
      gap: 0.8rem;
      align-items: center;
      padding: 0.72rem 0.8rem;
      border-radius: 18px;
      border: 1px solid rgba(255, 255, 255, 0.08);
      background: rgba(255, 255, 255, 0.03);
      min-width: 0;
    }

    .mod-ring-meter svg {
      width: 4.3rem;
      height: 4.3rem;
      transform: rotate(-90deg);
      overflow: visible;
    }

    .mod-ring-meter__track {
      fill: none;
      stroke: rgba(255, 255, 255, 0.08);
      stroke-width: 10;
    }

    .mod-ring-meter__value {
      fill: none;
      stroke: var(--ring-accent, #b987ff);
      stroke-width: 10;
      stroke-linecap: round;
      stroke-dasharray: 289;
      stroke-dashoffset: 289;
      filter: drop-shadow(0 10px 18px rgba(156, 96, 255, 0.22));
      transition: stroke-dashoffset 780ms cubic-bezier(0.2, 0.8, 0.2, 1);
    }

    .mod-ring-meter__copy {
      display: grid;
      gap: 0.18rem;
      min-width: 0;
    }

    .mod-ring-meter__copy strong {
      font-size: 1.15rem;
      line-height: 1;
    }

    .mod-ring-meter__copy small {
      color: var(--text-soft);
      font-size: 0.82rem;
    }

    .mod-compact-metrics {
      display: grid;
      grid-template-columns: 1fr;
      gap: 0.8rem;
      margin-top: 1rem;
    }

    .mod-visual-card--primary .mod-compact-metrics {
      grid-template-columns: repeat(3, minmax(0, 1fr));
    }

    .mod-compact-metrics article {
      display: grid;
      gap: 0.5rem;
      padding: 0.85rem 0.9rem;
      border-radius: 16px;
      border: 1px solid rgba(255, 255, 255, 0.07);
      background: rgba(255, 255, 255, 0.03);
    }

    .mod-compact-metric__value {
      display: flex;
      align-items: end;
      justify-content: space-between;
      gap: 0.7rem;
    }

    .mod-compact-metric__value .mod-mini-label {
      flex: 1 1 auto;
      min-width: 0;
      line-height: 1.35;
    }

    .mod-compact-metrics strong {
      flex-shrink: 0;
      font-size: 1.25rem;
      line-height: 1;
    }

    .mod-compact-metric__track {
      height: 0.42rem;
      border-radius: 999px;
      background: rgba(255, 255, 255, 0.07);
      overflow: hidden;
    }

    .mod-compact-metric__fill {
      display: block;
      height: 100%;
      width: 0;
      border-radius: inherit;
      background: linear-gradient(90deg, color-mix(in srgb, var(--metric-accent, #b987ff) 70%, white 10%), var(--metric-accent, #b987ff));
      box-shadow: 0 10px 18px color-mix(in srgb, var(--metric-accent, #b987ff) 28%, transparent);
      transition: width 820ms cubic-bezier(0.2, 0.8, 0.2, 1);
    }

    .mod-donut-board {
      display: grid;
      grid-template-columns: minmax(8.85rem, 10.25rem) minmax(0, 1fr);
      gap: 0.95rem;
      align-items: center;
      margin-top: 0.9rem;
    }

    .mod-donut {
      position: relative;
      width: clamp(8.85rem, 19vw, 10.5rem);
      height: clamp(8.85rem, 19vw, 10.5rem);
      margin: 0 auto;
      border-radius: 50%;
      background: var(--donut-gradient);
      box-shadow: inset 0 0 0 1px rgba(255, 255, 255, 0.05), 0 20px 36px rgba(0, 0, 0, 0.18);
      transform: scale(0.92);
      opacity: 0;
      transition: transform 700ms ease, opacity 700ms ease;
    }

    .mod-donut.is-live {
      transform: scale(1);
      opacity: 1;
    }

    .mod-donut::before {
      content: "";
      position: absolute;
      inset: 1.2rem;
      border-radius: 50%;
      background:
        radial-gradient(circle at top, rgba(255, 255, 255, 0.08), transparent 48%),
        rgba(12, 18, 32, 0.96);
      border: 1px solid rgba(255, 255, 255, 0.05);
    }

    .mod-donut__inner {
      position: absolute;
      inset: 0;
      z-index: 1;
      display: grid;
      place-items: center;
      text-align: center;
      padding: 1rem;
      gap: 0.12rem;
    }

    .mod-donut__inner strong {
      font-size: clamp(1.75rem, 2.8vw, 2.35rem);
      line-height: 0.95;
      letter-spacing: -0.04em;
    }

    .mod-donut__inner small {
      color: var(--text-soft);
      max-width: 5.5rem;
      margin: 0 auto;
      font-size: 0.68rem;
      line-height: 1.16;
    }

    .mod-legend {
      display: grid;
      gap: 0.7rem;
      align-content: start;
    }

    .mod-legend__item {
      display: grid;
      grid-template-columns: auto minmax(0, 1fr) auto;
      gap: 0.55rem 0.7rem;
      align-items: center;
      min-width: 0;
    }

    .mod-legend__swatch {
      width: 0.72rem;
      height: 0.72rem;
      border-radius: 999px;
      box-shadow: 0 0 0 6px color-mix(in srgb, var(--swatch) 18%, transparent);
      background: var(--swatch);
    }

    .mod-legend__content {
      display: grid;
      gap: 0.35rem;
      min-width: 0;
    }

    .mod-legend__text {
      display: flex;
      justify-content: space-between;
      gap: 0.75rem;
      align-items: center;
      min-width: 0;
    }

    .mod-legend__text div {
      display: grid;
      gap: 0.18rem;
      flex: 1 1 auto;
      min-width: 0;
    }

    .mod-legend__item strong {
      display: block;
      font-size: 0.94rem;
      line-height: 1.12;
      word-break: break-word;
    }

    .mod-legend__item span,
    .mod-legend__item small {
      color: var(--text-soft);
      min-width: 0;
    }

    .mod-legend__text > span {
      flex-shrink: 0;
      font-size: 0.95rem;
      font-weight: 700;
      color: var(--text);
    }

    .mod-legend__bar {
      height: 0.42rem;
      border-radius: 999px;
      background: rgba(255, 255, 255, 0.06);
      overflow: hidden;
    }

    .mod-legend__fill {
      display: block;
      width: 0;
      height: 100%;
      border-radius: inherit;
      background: linear-gradient(90deg, color-mix(in srgb, var(--legend-color, #b987ff) 68%, white 12%), var(--legend-color, #b987ff));
      box-shadow: 0 10px 18px color-mix(in srgb, var(--legend-color, #b987ff) 24%, transparent);
      transition: width 880ms cubic-bezier(0.2, 0.8, 0.2, 1);
    }

    .mod-trend-board {
      margin-top: 0.95rem;
      max-width: 100%;
    }

    .mod-visual-card--audit .mod-trend-board {
      max-width: 46rem;
    }

    .mod-trend-svg {
      width: 100%;
      height: auto;
      display: block;
    }

    .mod-trend-gridline {
      stroke: rgba(255, 255, 255, 0.08);
      stroke-width: 1;
      stroke-dasharray: 4 8;
    }

    .mod-trend-area {
      fill: url(#modTrendAreaGradient);
      opacity: 0;
      transition: opacity 520ms ease 180ms;
    }

    .mod-trend-line {
      fill: none;
      stroke: url(#modTrendLineGradient);
      stroke-width: 4;
      stroke-linecap: round;
      stroke-linejoin: round;
      filter: drop-shadow(0 12px 20px rgba(116, 199, 255, 0.22));
      opacity: 0;
    }

    .mod-trend-point {
      fill: #dba2ff;
      stroke: rgba(11, 16, 28, 0.95);
      stroke-width: 3;
      transform-origin: center;
      transform: scale(0.2);
      opacity: 0;
      transition: transform 320ms ease, opacity 320ms ease;
    }

    .mod-trend-board.is-live .mod-trend-area {
      opacity: 1;
    }

    .mod-trend-board.is-live .mod-trend-point {
      transform: scale(1);
      opacity: 1;
    }

    .mod-trend-tick {
      fill: rgba(201, 210, 233, 0.64);
      font-family: "IBM Plex Mono", monospace;
      font-size: 11px;
      letter-spacing: 0.02em;
    }

    .mod-trend-footer {
      display: grid;
      grid-template-columns: repeat(2, minmax(0, 1fr));
      gap: 0.85rem;
      margin-top: 0.85rem;
    }

    .mod-trend-footer article {
      padding: 0.78rem 0.82rem;
      border-radius: 16px;
      border: 1px solid rgba(255, 255, 255, 0.07);
      background: rgba(255, 255, 255, 0.03);
    }

    .mod-trend-footer strong {
      display: block;
      margin-top: 0.26rem;
      font-size: 1.08rem;
      line-height: 1.1;
    }

    .mod-trend-footer small {
      display: block;
      margin-top: 0.24rem;
      color: var(--text-soft);
      font-size: 0.82rem;
    }

    .mod-chart-card h3,
    .mod-list-item h3 {
      margin: 0;
      font-size: 1rem;
    }

    .mod-bars {
      display: grid;
      grid-template-columns: repeat(3, minmax(0, 1fr));
      gap: 0.8rem;
      align-items: end;
      min-width: 0;
    }

    .mod-bars--four {
      grid-template-columns: repeat(4, minmax(0, 1fr));
    }

    .mod-bar {
      display: grid;
      gap: 0.65rem;
    }

    .mod-bar__track {
      height: 11rem;
      border-radius: 18px;
      padding: 0.55rem;
      background: linear-gradient(180deg, rgba(255, 255, 255, 0.06), rgba(255, 255, 255, 0.02));
      border: 1px solid rgba(255, 255, 255, 0.07);
      display: flex;
      align-items: flex-end;
    }

    .mod-bar__fill {
      width: 100%;
      min-height: 0.85rem;
      border-radius: 12px;
      background: linear-gradient(180deg, rgba(211, 120, 255, 0.98), rgba(122, 92, 255, 0.92));
      box-shadow: 0 16px 28px rgba(157, 98, 255, 0.26);
      height: calc(var(--ratio, 0) * 1%);
    }

    .mod-bar__value {
      font-weight: 800;
      color: var(--text);
      font-size: 1.1rem;
    }

    .mod-trend {
      display: grid;
      grid-template-columns: repeat(7, minmax(0, 1fr));
      gap: 0.65rem;
      align-items: end;
    }

    .mod-trend__item {
      display: grid;
      gap: 0.55rem;
      justify-items: center;
    }

    .mod-trend__bar {
      width: 100%;
      height: 7.4rem;
      border-radius: 16px;
      background: rgba(255, 255, 255, 0.04);
      border: 1px solid rgba(255, 255, 255, 0.07);
      padding: 0.5rem;
      display: flex;
      align-items: flex-end;
    }

    .mod-trend__bar span {
      width: 100%;
      min-height: 0.75rem;
      border-radius: 10px;
      background: linear-gradient(180deg, rgba(111, 201, 255, 0.96), rgba(99, 129, 255, 0.9));
      box-shadow: 0 14px 24px rgba(99, 129, 255, 0.24);
      height: calc(var(--ratio, 0) * 1%);
    }

    .mod-horizontal-meters {
      display: grid;
      gap: 0.72rem;
    }

    .mod-meter {
      display: grid;
      gap: 0.4rem;
    }

    .mod-meter__row {
      display: flex;
      justify-content: space-between;
      gap: 0.75rem;
      align-items: baseline;
      color: var(--text-soft);
      font-size: 0.95rem;
    }

    .mod-meter__track {
      height: 0.75rem;
      border-radius: 999px;
      background: rgba(255, 255, 255, 0.05);
      overflow: hidden;
    }

    .mod-meter__fill {
      height: 100%;
      border-radius: inherit;
      width: calc(var(--ratio, 0) * 1%);
      background: linear-gradient(90deg, rgba(107, 204, 255, 0.94), rgba(207, 119, 255, 0.94));
    }

    .mod-roster {
      display: grid;
      gap: 0.75rem;
    }

    .mod-roster__item,
    .mod-audit-item,
    .mod-list-item {
      border-radius: 18px;
      padding: 0.95rem 1rem;
      min-width: 0;
    }

    .mod-roster__item {
      display: grid;
      gap: 0.55rem;
    }

    .mod-roster__head,
    .mod-audit-item__head,
    .mod-list-item__head {
      display: flex;
      justify-content: space-between;
      gap: 0.75rem;
      align-items: center;
      flex-wrap: wrap;
    }

    .mod-indicator {
      width: 0.68rem;
      height: 0.68rem;
      border-radius: 999px;
      background: rgba(255, 255, 255, 0.2);
      display: inline-block;
    }

    .mod-indicator--success {
      background: #6de3ad;
      box-shadow: 0 0 0 6px rgba(109, 227, 173, 0.14);
    }

    .mod-indicator--danger {
      background: #ff8f74;
      box-shadow: 0 0 0 6px rgba(255, 143, 116, 0.14);
    }

    details.mod-list-item > summary {
      list-style: none;
      cursor: pointer;
    }

    details.mod-list-item > summary::-webkit-details-marker {
      display: none;
    }

    .mod-list-item__details {
      display: grid;
      gap: 0.9rem;
      margin-top: 0.95rem;
    }

    .mod-list-item__grid {
      display: grid;
      grid-template-columns: repeat(2, minmax(0, 1fr));
      gap: 0.85rem;
    }

    .mod-list-item__grid article {
      border-radius: 16px;
      padding: 0.9rem 0.95rem;
      border: 1px solid rgba(255, 255, 255, 0.07);
      background: rgba(255, 255, 255, 0.03);
      min-width: 0;
    }

    .mod-list-item__grid strong {
      display: block;
      margin-top: 0.35rem;
      word-break: break-word;
      white-space: pre-wrap;
    }

    .mod-audit-list,
    .mod-list-stack {
      display: grid;
      gap: 0.78rem;
    }

    .mod-meta {
      color: var(--text-soft);
      font-size: 0.92rem;
    }

    .mod-empty {
      color: var(--text-soft);
      margin: 0;
    }

    .mod-perm-grid {
      display: grid;
      grid-template-columns: minmax(13rem, 1.35fr) repeat(3, minmax(6.2rem, 0.7fr));
      gap: 0.6rem;
      margin-top: 1rem;
      min-width: 0;
    }

    .mod-perm-grid > article,
    .mod-perm-grid > div {
      border-radius: 16px;
      padding: 0.85rem 0.9rem;
      border: 1px solid rgba(255, 255, 255, 0.07);
      background: rgba(255, 255, 255, 0.03);
      min-width: 0;
    }

    .mod-perm-grid__head {
      font-family: "IBM Plex Mono", monospace;
      color: var(--text-dim);
      letter-spacing: 0.08em;
      text-transform: uppercase;
      font-size: 0.74rem;
    }

    .mod-perm-grid label {
      display: inline-flex;
      align-items: center;
      justify-content: center;
      gap: 0.45rem;
      color: var(--text);
      width: 100%;
    }

    .mod-perm-grid input[type="checkbox"] {
      accent-color: #d553ff;
      width: 1rem;
      height: 1rem;
    }

    .mod-caption {
      color: var(--text-soft);
      font-size: 0.92rem;
      margin-top: 0.8rem;
    }

    .btn-danger-soft {
      color: #fff4f0;
      background: linear-gradient(135deg, rgba(255, 130, 96, 0.94), rgba(208, 72, 72, 0.94));
      border: 1px solid rgba(255, 255, 255, 0.08);
      box-shadow: 0 18px 40px rgba(208, 72, 72, 0.2);
    }

    .btn-success-soft {
      color: #03150e;
      background: linear-gradient(135deg, rgba(101, 211, 159, 0.98), rgba(144, 237, 190, 0.92));
      border: 1px solid rgba(255, 255, 255, 0.08);
      box-shadow: 0 18px 40px rgba(101, 211, 159, 0.18);
    }

    @media (max-width: 1199.98px) {
      .mod-top-controls,
      .mod-layout,
      .mod-chart-grid,
      .mod-stat-cards,
      .mod-visual-grid {
        grid-template-columns: 1fr;
      }

      .mod-stat-cards {
        grid-template-columns: repeat(2, minmax(0, 1fr));
      }

      .mod-sidebar,
      .mod-top-controls {
        max-width: none;
      }

      .mod-visual-card--primary .mod-compact-metrics {
        grid-template-columns: 1fr;
      }
    }

    @media (max-width: 991.98px) {
      .topbar-lang-switch {
        width: 100%;
        justify-content: center;
        margin: 0 0 0.5rem;
      }

      .mod-top-controls,
      .mod-layout,
      .mod-panel-grid,
      .mod-detail-grid,
      .mod-stat-grid,
      .mod-settings-shell,
      .mod-settings-grid,
      .mod-settings-summary-grid,
      .mod-roster-grid,
      .mod-direct-form__grid,
      .mod-list-item__grid,
      .mod-perm-grid,
      .mod-workspace-nav,
      .mod-visual-card__body,
      .mod-donut-board,
      .mod-trend-footer {
        grid-template-columns: 1fr;
      }

      .mod-bars,
      .mod-bars--four,
      .mod-trend {
        grid-template-columns: repeat(2, minmax(0, 1fr));
      }

      .mod-workspace-nav--toolbar {
        grid-template-columns: repeat(2, minmax(0, 1fr));
      }

      .mod-donut {
        width: 10rem;
        height: 10rem;
      }

      .mod-settings-rail {
        position: static;
      }
    }

    @media (max-width: 575.98px) {
      .mod-stat-cards,
      .mod-bars,
      .mod-bars--four,
      .mod-trend,
      .mod-compact-metrics {
        grid-template-columns: 1fr;
      }

      .mod-workspace-nav--toolbar,
      .mod-workspace-nav {
        grid-template-columns: 1fr;
      }

      .mod-workspace-tab {
        padding: 0.85rem 0.9rem;
      }
    }
  </style>
</head>
<body
  class="appeal-page"
  data-nav-page="mod"
  data-site-base=".."
  data-ui-lang="<?php echo bg_escape($uiLang); ?>"
  data-rules-url="<?php echo bg_escape($rulesUrl); ?>"
  data-nav-cta="<?php echo $hasModeratorAccess ? 'logout' : 'none'; ?>"
  data-nav-cta-href="<?php echo $hasModeratorAccess ? bg_escape($logoutUrl) : ''; ?>"
  data-nav-cta-label="<?php echo bg_escape(bg_mod_t('nav.logout', [], $uiLang)); ?>"
>
  <div class="page-noise" aria-hidden="true"></div>

  <header class="topbar" id="top">
    <nav class="navbar navbar-expand-lg" data-site-nav>
      <div class="container">
        <a class="navbar-brand brand" href="../" aria-label="<?php echo bg_escape(bg_mod_t('brand.lobby_aria', [], $uiLang)); ?>">
          <span class="brand__mark">
            <img src="../assets/bg-gamer-logo.png" alt="BG-GAMER logo" class="brand__logo">
          </span>
          <span class="brand__text">
            <strong>BG-GAMER</strong>
            <small><?php echo bg_escape(bg_mod_t('brand.moderator_panel', [], $uiLang)); ?></small>
          </span>
        </a>

        <button
          class="navbar-toggler border-0 shadow-none"
          type="button"
          data-bs-toggle="collapse"
          data-bs-target="#moderationNav"
          aria-controls="moderationNav"
          aria-expanded="false"
          aria-label="<?php echo bg_escape(bg_mod_t('aria.toggle_navigation', [], $uiLang)); ?>"
        >
          <span class="navbar-toggler-icon"></span>
        </button>

        <div class="collapse navbar-collapse justify-content-end" id="moderationNav">
          <ul class="navbar-nav navbar-nav--site align-items-lg-center mb-3 mb-lg-0" data-site-nav-list>
            <li class="nav-item"><a class="nav-link" href="../"><?php echo bg_escape(bg_mod_t('nav.lobby', [], $uiLang)); ?></a></li>
            <li class="nav-item"><a class="nav-link" href="../#activity"><?php echo bg_escape(bg_mod_t('nav.activity', [], $uiLang)); ?></a></li>
            <li class="nav-item"><a class="nav-link" href="../#bots"><?php echo bg_escape(bg_mod_t('nav.bots', [], $uiLang)); ?></a></li>
            <li class="nav-item"><a class="nav-link" href="../bans/"><?php echo bg_escape(bg_mod_t('nav.bans', [], $uiLang)); ?></a></li>
            <li class="nav-item"><a class="nav-link" href="../appeal/"><?php echo bg_escape(bg_mod_t('nav.appeal', [], $uiLang)); ?></a></li>
            <li class="nav-item"><a class="nav-link" href="<?php echo bg_escape($rulesUrl); ?>"><?php echo bg_escape(bg_mod_t('nav.rules', [], $uiLang)); ?></a></li>
            <li class="nav-item"><a class="nav-link" href="./" aria-current="page"><?php echo bg_escape(bg_mod_t('nav.mod_panel', [], $uiLang)); ?></a></li>
          </ul>
          <div class="topbar-lang-switch" aria-label="<?php echo bg_escape(bg_mod_t('language.label', [], $uiLang)); ?>">
            <a class="topbar-lang-switch__item<?php echo $uiLang === 'en' ? ' is-active' : ''; ?>" href="<?php echo bg_escape($languageUrls['en']); ?>">EN</a>
            <a class="topbar-lang-switch__item<?php echo $uiLang === 'bg' ? ' is-active' : ''; ?>" href="<?php echo bg_escape($languageUrls['bg']); ?>">BG</a>
          </div>
          <div class="site-nav__actions" data-site-nav-actions>
<?php if ($hasModeratorAccess): ?>
            <a class="btn btn-ghost navbar-cta ms-lg-4" href="<?php echo bg_escape($logoutUrl); ?>"><?php echo bg_escape(bg_mod_t('nav.logout', [], $uiLang)); ?></a>
<?php endif; ?>
          </div>
        </div>
      </div>
    </nav>
  </header>

  <main class="mod-shell">
    <div class="container">
<?php if ($hasModeratorAccess && ($workspaceViews !== [] || $surfaceQuickLinks !== [])): ?>
      <section class="mod-top-controls">
<?php if ($workspaceViews !== []): ?>
        <article class="mod-control-card">
          <div class="mod-control-card__header">
            <div>
              <span class="mod-kicker"><?php echo bg_escape(bg_mod_t('toolbar.workspace_kicker', [], $uiLang)); ?></span>
              <h2><?php echo bg_escape(bg_mod_t('toolbar.workspace_title', [], $uiLang)); ?></h2>
              <p><?php echo bg_escape(bg_mod_t('toolbar.workspace_copy', [], $uiLang)); ?></p>
            </div>
          </div>

          <nav
            class="mod-workspace-nav mod-workspace-nav--toolbar"
            data-mod-workspace-nav
            data-default-view="<?php echo bg_escape($defaultWorkspaceView); ?>"
            aria-label="<?php echo bg_escape(bg_mod_t('workspace.aria', [], $uiLang)); ?>"
          >
<?php foreach ($workspaceViews as $workspaceView): ?>
            <button
              type="button"
              class="mod-workspace-tab<?php echo $workspaceView['key'] === $defaultWorkspaceView ? ' is-active' : ''; ?>"
              data-mod-view-trigger="<?php echo bg_escape($workspaceView['key']); ?>"
            >
              <strong><?php echo bg_escape($workspaceView['label']); ?></strong>
              <small><?php echo bg_escape($workspaceView['description']); ?></small>
            </button>
<?php endforeach; ?>
          </nav>
        </article>
<?php endif; ?>

<?php if ($surfaceQuickLinks !== []): ?>
        <article class="mod-control-card">
          <div class="mod-control-card__header">
            <div>
              <span class="mod-kicker"><?php echo bg_escape(bg_mod_t('toolbar.shortcuts_kicker', [], $uiLang)); ?></span>
              <h2><?php echo bg_escape(bg_mod_t('toolbar.shortcuts_title', [], $uiLang)); ?></h2>
              <p><?php echo bg_escape(bg_mod_t('toolbar.shortcuts_copy', [], $uiLang)); ?></p>
            </div>
          </div>

          <div class="mod-quick-nav">
<?php foreach ($surfaceQuickLinks as $quickLink): ?>
            <button
              type="button"
              class="mod-quick-link"
              data-mod-jump="<?php echo bg_escape((string) $quickLink['target']); ?>"
              data-mod-jump-view="<?php echo bg_escape((string) $quickLink['view']); ?>"
            ><?php echo bg_escape((string) $quickLink['label']); ?></button>
<?php endforeach; ?>
          </div>
        </article>
<?php endif; ?>
      </section>
<?php endif; ?>

      <div class="mod-layout">
        <section class="mod-stack">
          <section class="mod-card">
            <div class="mod-header">
              <div>
                <span class="section-kicker"><?php echo bg_escape(bg_mod_t('hero.kicker', [], $uiLang)); ?></span>
                <h1><?php echo bg_escape(bg_mod_t('hero.title', [], $uiLang)); ?></h1>
                <p><?php echo bg_escape($recordSubtitle); ?></p>
              </div>
              <div class="mod-badge-row">
<?php if ($hasModeratorAccess): ?>
                <span class="mod-badge mod-badge--success"><?php echo bg_escape(bg_mod_t('badge.session_active', [], $uiLang)); ?></span>
<?php endif; ?>
<?php if ($hasSignedAccess): ?>
                <span class="mod-badge"><?php echo bg_escape(bg_mod_t('badge.signed_link_until', ['date' => date('d.m.Y H:i', $expiresAt)], $uiLang)); ?></span>
<?php elseif (!$hasModeratorAccess): ?>
                <span class="mod-badge mod-badge--warning"><?php echo bg_escape(bg_mod_t('badge.no_active_access', [], $uiLang)); ?></span>
<?php endif; ?>
              </div>
            </div>

<?php foreach ([$authMessage, $flashResult, $panelMessage] as $message): ?>
<?php if (is_array($message) && isset($message['message'])): ?>
            <div class="mod-alert <?php echo ($message['type'] ?? '') === 'error' ? 'mod-alert--error' : 'mod-alert--success'; ?>">
              <?php echo bg_escape((string) $message['message']); ?>
            </div>
<?php endif; ?>
<?php endforeach; ?>

<?php if ($pageError !== null): ?>
            <div class="mod-alert mod-alert--error"><?php echo bg_escape($pageError); ?></div>
<?php endif; ?>

<?php if (!$hasModeratorAccess && !$hasSignedAccess): ?>
            <div class="mod-copy">
              <p><?php echo bg_escape(bg_mod_t('hero.guest_text', [], $uiLang)); ?></p>
<?php if ($oauthEnabled): ?>
              <div class="mod-inline-actions">
                <a class="btn btn-brand btn-lg" href="<?php echo bg_escape($loginUrl); ?>"><?php echo bg_escape(bg_mod_t('hero.login', [], $uiLang)); ?></a>
              </div>
<?php else: ?>
              <p><?php echo bg_escape(bg_mod_t('hero.oauth_missing', [], $uiLang)); ?></p>
<?php endif; ?>
            </div>
<?php elseif ($record !== null): ?>
            <div class="mod-panel-grid">
              <article>
                <span class="mod-label"><?php echo bg_escape(bg_mod_t('appeal.reference', [], $uiLang)); ?></span>
                <strong><?php echo bg_escape((string) $record['public_reference']); ?></strong>
              </article>
              <article>
                <span class="mod-label"><?php echo bg_escape(bg_mod_t('appeal.status', [], $uiLang)); ?></span>
                <strong><?php echo bg_escape(bg_mod_appeal_status_text((string) $record['status'])); ?></strong>
              </article>
              <article>
                <span class="mod-label"><?php echo bg_escape(bg_mod_t('appeal.discord_user', [], $uiLang)); ?></span>
                <strong><?php echo bg_escape((string) $record['discord_username']); ?></strong>
              </article>
              <article>
                <span class="mod-label"><?php echo bg_escape(bg_mod_t('appeal.discord_user_id', [], $uiLang)); ?></span>
                <strong><?php echo bg_escape((string) $record['discord_user_id']); ?></strong>
              </article>
              <article>
                <span class="mod-label"><?php echo bg_escape(bg_mod_t('appeal.ban_reference', [], $uiLang)); ?></span>
                <strong><?php echo bg_escape((string) ($record['ban_public_reference'] ?? bg_mod_t('appeal.general', [], $uiLang))); ?></strong>
              </article>
              <article>
                <span class="mod-label"><?php echo bg_escape(bg_mod_t('appeal.ban_status', [], $uiLang)); ?></span>
                <strong><?php echo bg_escape(bg_mod_ban_status_text((string) ($record['ban_status'] ?? 'active'))); ?></strong>
              </article>
            </div>

            <div class="mod-divider"></div>

            <div class="mod-detail-grid">
              <article>
                <span class="mod-label"><?php echo bg_escape(bg_mod_t('appeal.contact', [], $uiLang)); ?></span>
                <strong><?php echo bg_escape((string) $record['contact']); ?></strong>
              </article>
              <article>
                <span class="mod-label"><?php echo bg_escape(bg_mod_t('appeal.attachment', [], $uiLang)); ?></span>
                <strong><?php echo bg_escape(trim((string) ($record['attachment_path'] ?? '')) !== '' ? bg_mod_t('appeal.attachment.stored', [], $uiLang) : bg_mod_t('appeal.attachment.none', [], $uiLang)); ?></strong>
              </article>
              <article>
                <span class="mod-label"><?php echo bg_escape(bg_mod_t('appeal.reason', [], $uiLang)); ?></span>
                <strong><?php echo bg_escape((string) $record['appeal_reason']); ?></strong>
              </article>
              <article>
                <span class="mod-label"><?php echo bg_escape(bg_mod_t('appeal.public_reason', [], $uiLang)); ?></span>
                <strong><?php echo bg_escape(sanitizePublicReason((string) ($record['ban_public_reason'] ?? bg_default_public_reason()))); ?></strong>
              </article>
<?php if ($canViewPrivateReasons && trim((string) ($record['ban_private_reason'] ?? '')) !== ''): ?>
              <article>
                <span class="mod-label"><?php echo bg_escape(bg_mod_t('appeal.private_reason', [], $uiLang)); ?></span>
                <strong><?php echo bg_escape((string) $record['ban_private_reason']); ?></strong>
              </article>
<?php endif; ?>
              <article>
                <span class="mod-label"><?php echo bg_escape(bg_mod_t('appeal.moderator_response', [], $uiLang)); ?></span>
                <strong><?php echo bg_escape((string) (($record['moderator_response'] ?? '') !== '' ? $record['moderator_response'] : bg_mod_t('appeal.response.none', [], $uiLang))); ?></strong>
              </article>
              <article>
                <span class="mod-label"><?php echo bg_escape(bg_mod_t('appeal.explanation', [], $uiLang)); ?></span>
                <strong><?php echo bg_escape((string) $record['additional_information']); ?></strong>
              </article>
              <article>
                <span class="mod-label"><?php echo bg_escape(bg_mod_t('appeal.submitted', [], $uiLang)); ?></span>
                <strong><?php echo bg_escape(bg_mod_format_datetime((string) ($record['submitted_at'] ?? ''))); ?></strong>
              </article>
            </div>

            <div class="mod-actions" style="margin-top:1rem;">
              <a class="btn btn-ghost btn-lg" href="<?php echo bg_escape($panelUrl); ?>"><?php echo bg_escape(bg_mod_t('appeal.overview', [], $uiLang)); ?></a>
<?php if ($canApproveUnbanAppeals): ?>
              <a class="btn btn-success-soft btn-lg" href="<?php echo bg_escape($unbanUrl); ?>"><?php echo bg_escape(bg_mod_t('appeal.open_unban', [], $uiLang)); ?></a>
<?php endif; ?>
<?php if ($canRejectAppeals): ?>
              <a class="btn btn-danger-soft btn-lg" href="<?php echo bg_escape($rejectUrl); ?>"><?php echo bg_escape(bg_mod_t('appeal.open_reject', [], $uiLang)); ?></a>
<?php endif; ?>
            </div>

<?php if (($requestedAction === 'unban' && $canApproveUnbanAppeals) || ($requestedAction === 'reject' && $canRejectAppeals)): ?>
            <form class="mod-form" method="post" action="">
              <input type="hidden" name="form_type" value="moderation_action">
              <input type="hidden" name="csrf_token" value="<?php echo bg_escape($csrfActionToken); ?>">
              <input type="hidden" name="appeal_reference" value="<?php echo bg_escape((string) $record['public_reference']); ?>">
              <input type="hidden" name="mod_action" value="<?php echo bg_escape($requestedAction); ?>">

              <label>
                <span><?php echo bg_escape(bg_mod_t('appeal.moderator_name', [], $uiLang)); ?></span>
                <input
                  type="text"
                  name="reviewed_by"
                  maxlength="120"
                  placeholder="<?php echo bg_escape(bg_mod_t('appeal.name_placeholder', [], $uiLang)); ?>"
                  value="<?php echo bg_escape($hasModeratorAccess ? bg_mod_display_name($moderatorUser) : ''); ?>"
                >
              </label>

              <label>
                <span><?php echo bg_escape(bg_mod_t('appeal.moderator_note', [], $uiLang)); ?></span>
                <textarea name="moderator_note" maxlength="2500" placeholder="<?php echo bg_escape(bg_mod_t('appeal.note_placeholder', [], $uiLang)); ?>"></textarea>
              </label>

<?php if ($requestedAction === 'unban'): ?>
              <button type="submit" class="btn btn-success-soft btn-lg"><?php echo bg_escape(bg_mod_t('appeal.confirm_unban', [], $uiLang)); ?></button>
<?php else: ?>
              <button type="submit" class="btn btn-danger-soft btn-lg"><?php echo bg_escape(bg_mod_t('appeal.confirm_reject', [], $uiLang)); ?></button>
<?php endif; ?>
            </form>
<?php endif; ?>
<?php elseif ($hasModeratorAccess): ?>
            <div class="mod-copy">
              <p><?php echo bg_escape(bg_mod_t('hero.overview_text', [], $uiLang)); ?></p>
              <div class="mod-stat-cards" style="margin-top:1rem;">
                <article>
                  <span class="mod-label"><?php echo bg_escape(bg_mod_t('counts.pending_appeals', [], $uiLang)); ?></span>
                  <strong><?php echo bg_escape((string) $dashboardCounts['pending_appeals']); ?></strong>
                </article>
                <article>
                  <span class="mod-label"><?php echo bg_escape(bg_mod_t('counts.under_review', [], $uiLang)); ?></span>
                  <strong><?php echo bg_escape((string) $dashboardCounts['under_review_appeals']); ?></strong>
                </article>
                <article>
                  <span class="mod-label"><?php echo bg_escape(bg_mod_t('counts.approved_appeals', [], $uiLang)); ?></span>
                  <strong><?php echo bg_escape((string) $dashboardCounts['approved_appeals']); ?></strong>
                </article>
                <article>
                  <span class="mod-label"><?php echo bg_escape(bg_mod_t('counts.active_bans', [], $uiLang)); ?></span>
                  <strong><?php echo bg_escape((string) $dashboardCounts['active_bans']); ?></strong>
                </article>
              </div>
            </div>
<?php else: ?>
            <div class="mod-copy">
              <p><?php echo bg_escape(bg_mod_t('hero.no_selected_record', [], $uiLang)); ?></p>
            </div>
<?php endif; ?>

<?php if (!$hasModeratorAccess && $workspaceViews !== []): ?>
            <nav
              class="mod-workspace-nav"
              data-mod-workspace-nav
              data-default-view="<?php echo bg_escape($defaultWorkspaceView); ?>"
              aria-label="<?php echo bg_escape(bg_mod_t('workspace.aria', [], $uiLang)); ?>"
            >
<?php foreach ($workspaceViews as $workspaceView): ?>
              <button
                type="button"
                class="mod-workspace-tab<?php echo $workspaceView['key'] === $defaultWorkspaceView ? ' is-active' : ''; ?>"
                data-mod-view-trigger="<?php echo bg_escape($workspaceView['key']); ?>"
              >
                <strong><?php echo bg_escape($workspaceView['label']); ?></strong>
                <small><?php echo bg_escape($workspaceView['description']); ?></small>
              </button>
<?php endforeach; ?>
            </nav>
<?php endif; ?>
          </section>

<?php if ($hasModeratorAccess && $canViewStats): ?>
          <section class="mod-card mod-pane<?php echo $defaultWorkspaceView === 'overview' ? ' is-active' : ''; ?>" id="server-stats" data-mod-pane="overview">
            <div class="mod-header">
              <div>
                <span class="section-kicker"><?php echo bg_escape(bg_mod_t('stats.kicker', [], $uiLang)); ?></span>
                <h2><?php echo bg_escape(bg_mod_t('stats.title', [], $uiLang)); ?></h2>
                <p><?php echo bg_escape(bg_mod_t('stats.copy', [], $uiLang)); ?></p>
              </div>
              <span class="mod-status mod-status--success"><?php echo bg_escape(bg_mod_t('stats.updated', ['date' => bg_mod_format_datetime((string) ($serverSnapshot['updated_at'] ?? ''))], $uiLang)); ?></span>
            </div>

            <div class="mod-visual-grid" data-mod-chart-root>
              <article class="mod-visual-card mod-visual-card--primary">
                <div class="mod-visual-card__header">
                  <div>
                    <span class="mod-label"><?php echo bg_escape(bg_mod_t('stats.community_pulse', [], $uiLang)); ?></span>
                    <h3><?php echo bg_escape(bg_mod_t('stats.at_a_glance', ['name' => (string) ($serverSnapshot['guild_name'] ?? 'BG-GAMER')], $uiLang)); ?></h3>
                  </div>
                </div>

                <div class="mod-visual-card__body">
                  <div class="mod-focus-metric">
                    <div class="mod-focus-metric__value">
                      <span class="mod-label"><?php echo bg_escape(bg_mod_t('stats.guild_members', [], $uiLang)); ?></span>
                      <strong data-countup-value="<?php echo bg_escape((string) $membersValue); ?>"><?php echo bg_escape(number_format($membersValue)); ?></strong>
                    </div>
                    <div class="mod-focus-metric__meta">
                      <span class="mod-focus-chip"><?php echo bg_escape(bg_mod_t('metric.online', [], $uiLang)); ?> <strong data-countup-value="<?php echo bg_escape((string) $onlineValue); ?>"><?php echo bg_escape(number_format($onlineValue)); ?></strong></span>
                      <span class="mod-focus-chip"><?php echo bg_escape(bg_mod_t('metric.in_voice', [], $uiLang)); ?> <strong data-countup-value="<?php echo bg_escape((string) $voiceValue); ?>"><?php echo bg_escape(number_format($voiceValue)); ?></strong></span>
                    </div>
                  </div>

                  <div class="mod-ring-grid">
                    <article class="mod-ring-meter" data-ring-progress="<?php echo bg_escape(number_format($onlineRatio, 1, '.', '')); ?>" style="--ring-accent:#74c7ff;">
                      <svg viewBox="0 0 120 120" aria-hidden="true">
                        <circle class="mod-ring-meter__track" cx="60" cy="60" r="46"></circle>
                        <circle class="mod-ring-meter__value" cx="60" cy="60" r="46"></circle>
                      </svg>
                      <div class="mod-ring-meter__copy">
                        <span class="mod-mini-label"><?php echo bg_escape(bg_mod_t('stats.online_share', [], $uiLang)); ?></span>
                        <strong><?php echo bg_escape(number_format($onlineRatio, 1)); ?>%</strong>
                        <small><?php echo bg_escape(bg_mod_t('stats.online_users', [], $uiLang)); ?></small>
                      </div>
                    </article>

                    <article class="mod-ring-meter" data-ring-progress="<?php echo bg_escape(number_format($voiceRatio, 1, '.', '')); ?>" style="--ring-accent:#b987ff;">
                      <svg viewBox="0 0 120 120" aria-hidden="true">
                        <circle class="mod-ring-meter__track" cx="60" cy="60" r="46"></circle>
                        <circle class="mod-ring-meter__value" cx="60" cy="60" r="46"></circle>
                      </svg>
                      <div class="mod-ring-meter__copy">
                        <span class="mod-mini-label"><?php echo bg_escape(bg_mod_t('stats.voice_share', [], $uiLang)); ?></span>
                        <strong><?php echo bg_escape(number_format($voiceRatio, 1)); ?>%</strong>
                        <small><?php echo bg_escape(bg_mod_t('metric.in_voice', [], $uiLang)); ?></small>
                      </div>
                    </article>
                  </div>
                </div>

                <div class="mod-compact-metrics">
<?php foreach ($communitySummaryMetrics as $metric): ?>
                  <article style="--metric-accent:<?php echo bg_escape((string) $metric['accent']); ?>;">
                    <div class="mod-compact-metric__value">
                      <span class="mod-mini-label"><?php echo bg_escape((string) $metric['label']); ?></span>
                      <strong data-countup-value="<?php echo bg_escape((string) $metric['value']); ?>"><?php echo bg_escape(number_format((int) $metric['value'])); ?></strong>
                    </div>
                    <div class="mod-compact-metric__track">
                      <span class="mod-compact-metric__fill" data-fill-target="<?php echo bg_escape(number_format((float) $metric['ratio'], 1, '.', '')); ?>"></span>
                    </div>
                  </article>
<?php endforeach; ?>
                </div>
              </article>

              <article class="mod-visual-card">
                <div class="mod-visual-card__header">
                  <div>
                    <span class="mod-label"><?php echo bg_escape(bg_mod_t('stats.moderation_pressure', [], $uiLang)); ?></span>
                    <h3><?php echo bg_escape(bg_mod_t('stats.blocked_pending_approved', [], $uiLang)); ?></h3>
                  </div>
                </div>

                <div class="mod-visual-card__body">
                  <div class="mod-focus-metric">
                    <div class="mod-focus-metric__value">
                      <span class="mod-label"><?php echo bg_escape(bg_mod_t('counts.active_bans', [], $uiLang)); ?></span>
                      <strong data-countup-value="<?php echo bg_escape((string) $activeBansValue); ?>"><?php echo bg_escape(number_format($activeBansValue)); ?></strong>
                    </div>
                    <div class="mod-focus-metric__meta">
                      <span class="mod-focus-chip"><?php echo bg_escape(bg_mod_t('counts.pending_short', [], $uiLang)); ?> <strong data-countup-value="<?php echo bg_escape((string) $pendingAppealsValue); ?>"><?php echo bg_escape(number_format($pendingAppealsValue)); ?></strong></span>
                      <span class="mod-focus-chip"><?php echo bg_escape(bg_mod_t('counts.approved_short', [], $uiLang)); ?> <strong data-countup-value="<?php echo bg_escape((string) $approvedAppealsValue); ?>"><?php echo bg_escape(number_format($approvedAppealsValue)); ?></strong></span>
                    </div>
                  </div>

                  <div class="mod-ring-grid">
                    <article class="mod-ring-meter" data-ring-progress="<?php echo bg_escape(number_format($pendingRatio, 1, '.', '')); ?>" style="--ring-accent:#ffb462;">
                      <svg viewBox="0 0 120 120" aria-hidden="true">
                        <circle class="mod-ring-meter__track" cx="60" cy="60" r="46"></circle>
                        <circle class="mod-ring-meter__value" cx="60" cy="60" r="46"></circle>
                      </svg>
                      <div class="mod-ring-meter__copy">
                        <span class="mod-mini-label"><?php echo bg_escape(bg_mod_t('stats.pending_share', [], $uiLang)); ?></span>
                        <strong><?php echo bg_escape(number_format($pendingRatio, 1)); ?>%</strong>
                        <small><?php echo bg_escape(bg_mod_t('counts.pending_appeals', [], $uiLang)); ?></small>
                      </div>
                    </article>

                    <article class="mod-ring-meter" data-ring-progress="<?php echo bg_escape(number_format($approvedRatio, 1, '.', '')); ?>" style="--ring-accent:#7ef0c3;">
                      <svg viewBox="0 0 120 120" aria-hidden="true">
                        <circle class="mod-ring-meter__track" cx="60" cy="60" r="46"></circle>
                        <circle class="mod-ring-meter__value" cx="60" cy="60" r="46"></circle>
                      </svg>
                      <div class="mod-ring-meter__copy">
                        <span class="mod-mini-label"><?php echo bg_escape(bg_mod_t('stats.approved_share', [], $uiLang)); ?></span>
                        <strong><?php echo bg_escape(number_format($approvedRatio, 1)); ?>%</strong>
                        <small><?php echo bg_escape(bg_mod_t('counts.approved_appeals', [], $uiLang)); ?></small>
                      </div>
                    </article>
                  </div>
                </div>

                <div class="mod-compact-metrics">
<?php foreach ($moderationSummaryMetrics as $metric): ?>
                  <article style="--metric-accent:<?php echo bg_escape((string) $metric['accent']); ?>;">
                    <div class="mod-compact-metric__value">
                      <span class="mod-mini-label"><?php echo bg_escape((string) $metric['label']); ?></span>
                      <strong data-countup-value="<?php echo bg_escape((string) $metric['value']); ?>"><?php echo bg_escape(number_format((int) $metric['value'])); ?></strong>
                    </div>
                    <div class="mod-compact-metric__track">
                      <span class="mod-compact-metric__fill" data-fill-target="<?php echo bg_escape(number_format((float) $metric['ratio'], 1, '.', '')); ?>"></span>
                    </div>
                  </article>
<?php endforeach; ?>
                </div>
              </article>

              <article class="mod-visual-card">
                <div class="mod-visual-card__header">
                  <div>
                    <span class="mod-label"><?php echo bg_escape(bg_mod_t('stats.channel_structure', [], $uiLang)); ?></span>
                    <h3><?php echo bg_escape(bg_mod_t('stats.channel_mix', [], $uiLang)); ?></h3>
                  </div>
                </div>

                <div class="mod-donut-board">
                  <div
                    class="mod-donut"
                    style="--donut-gradient: <?php echo bg_escape($channelDonutGradient); ?>;"
                    data-mod-donut
                  >
                    <div class="mod-donut__inner">
                      <span class="mod-label"><?php echo bg_escape(bg_mod_t('stats.channel_total', [], $uiLang)); ?></span>
                      <strong data-countup-value="<?php echo bg_escape((string) $channelTotalValue); ?>"><?php echo bg_escape(number_format($channelTotalValue)); ?></strong>
                      <small><?php echo bg_escape(bg_mod_t('stats.channel_structure', [], $uiLang)); ?></small>
                    </div>
                  </div>

                  <div class="mod-legend">
<?php foreach ($channelLegend as $segment): ?>
<?php
                    $segmentValue = (int) $segment['value'];
                    $segmentPercent = $channelTotalValue > 0 ? round(($segmentValue / $channelTotalValue) * 100, 1) : 0;
?>
                    <article class="mod-legend__item">
                      <span class="mod-legend__swatch" style="--swatch: <?php echo bg_escape((string) $segment['color']); ?>"></span>
                      <div class="mod-legend__content">
                        <div class="mod-legend__text">
                          <div>
                            <strong><?php echo bg_escape((string) $segment['label']); ?></strong>
                            <small><?php echo bg_escape(number_format($segmentPercent, 1)); ?>%</small>
                          </div>
                          <span><?php echo bg_escape((string) $segmentValue); ?></span>
                        </div>
                        <div class="mod-legend__bar">
                          <span class="mod-legend__fill" style="--legend-color: <?php echo bg_escape((string) $segment['color']); ?>;" data-fill-target="<?php echo bg_escape(number_format((float) $segmentPercent, 1, '.', '')); ?>"></span>
                        </div>
                      </div>
                    </article>
<?php endforeach; ?>
                  </div>
                </div>

                <p class="mod-caption">
<?php if (trim((string) ($serverSnapshot['active_voice_channel'] ?? '')) !== ''): ?>
                  <?php echo bg_escape(bg_mod_t('stats.voice_active', ['channel' => (string) $serverSnapshot['active_voice_channel'], 'count' => (string) ($serverSnapshot['voice_users'] ?? 0)], $uiLang)); ?>
<?php else: ?>
                  <?php echo bg_escape(bg_mod_t('stats.voice_empty', [], $uiLang)); ?>
<?php endif; ?>
                </p>
              </article>

              <article class="mod-visual-card mod-visual-card--audit">
                <div class="mod-visual-card__header">
                  <div>
                    <span class="mod-label"><?php echo bg_escape(bg_mod_t('stats.audit_trend', [], $uiLang)); ?></span>
                    <h3><?php echo bg_escape(bg_mod_t('stats.audit_trend_title', [], $uiLang)); ?></h3>
                  </div>
                </div>

                <div class="mod-trend-board" data-mod-trend-board>
                  <svg class="mod-trend-svg" viewBox="0 0 <?php echo bg_escape((string) $chartWidth); ?> <?php echo bg_escape((string) $chartHeight); ?>" role="img" aria-hidden="true">
                    <defs>
                      <linearGradient id="modTrendLineGradient" x1="0%" y1="0%" x2="100%" y2="0%">
                        <stop offset="0%" stop-color="#74c7ff"></stop>
                        <stop offset="100%" stop-color="#c77fff"></stop>
                      </linearGradient>
                      <linearGradient id="modTrendAreaGradient" x1="0%" y1="0%" x2="0%" y2="100%">
                        <stop offset="0%" stop-color="#74c7ff" stop-opacity="0.24"></stop>
                        <stop offset="100%" stop-color="#c77fff" stop-opacity="0.02"></stop>
                      </linearGradient>
                    </defs>
                    <line class="mod-trend-gridline" x1="<?php echo bg_escape((string) $chartPaddingX); ?>" y1="<?php echo bg_escape((string) $chartPaddingY); ?>" x2="<?php echo bg_escape((string) ($chartWidth - $chartPaddingX)); ?>" y2="<?php echo bg_escape((string) $chartPaddingY); ?>"></line>
                    <line class="mod-trend-gridline" x1="<?php echo bg_escape((string) $chartPaddingX); ?>" y1="<?php echo bg_escape((string) (($chartPaddingY + $chartBaseline) / 2)); ?>" x2="<?php echo bg_escape((string) ($chartWidth - $chartPaddingX)); ?>" y2="<?php echo bg_escape((string) (($chartPaddingY + $chartBaseline) / 2)); ?>"></line>
                    <line class="mod-trend-gridline" x1="<?php echo bg_escape((string) $chartPaddingX); ?>" y1="<?php echo bg_escape((string) $chartBaseline); ?>" x2="<?php echo bg_escape((string) ($chartWidth - $chartPaddingX)); ?>" y2="<?php echo bg_escape((string) $chartBaseline); ?>"></line>
<?php if ($trendAreaPath !== ''): ?>
                    <path class="mod-trend-area" d="<?php echo bg_escape($trendAreaPath); ?>"></path>
                    <path class="mod-trend-line" data-mod-trend-line d="<?php echo bg_escape($trendLinePath); ?>"></path>
<?php foreach ($trendPointData as $pointIndex => $point): ?>
                    <circle class="mod-trend-point" cx="<?php echo bg_escape(number_format((float) $point['x'], 2, '.', '')); ?>" cy="<?php echo bg_escape(number_format((float) $point['y'], 2, '.', '')); ?>" r="5" style="transition-delay: <?php echo bg_escape((string) ($pointIndex * 70)); ?>ms;"></circle>
                    <text x="<?php echo bg_escape(number_format((float) $point['x'], 2, '.', '')); ?>" y="<?php echo bg_escape((string) ($chartHeight - 2)); ?>" text-anchor="middle" class="mod-trend-tick"><?php echo bg_escape((string) $point['label']); ?></text>
<?php endforeach; ?>
<?php endif; ?>
                  </svg>

                  <div class="mod-trend-footer">
                    <article>
                      <span class="mod-mini-label"><?php echo bg_escape(bg_mod_t('stats.peak_day', [], $uiLang)); ?></span>
                      <strong><?php echo bg_escape((string) ($auditPeakEntry['label'] !== '' ? $auditPeakEntry['label'] : '-')); ?></strong>
                      <small><span data-countup-value="<?php echo bg_escape((string) $auditPeakEntry['count']); ?>"><?php echo bg_escape((string) $auditPeakEntry['count']); ?></span></small>
                    </article>
                    <article>
                      <span class="mod-mini-label"><?php echo bg_escape(bg_mod_t('stats.actions_total', [], $uiLang)); ?></span>
                      <strong data-countup-value="<?php echo bg_escape((string) $auditTotalActions); ?>"><?php echo bg_escape(number_format($auditTotalActions)); ?></strong>
                    </article>
                  </div>
                </div>
              </article>
            </div>
          </section>
<?php endif; ?>

<?php if ($hasModeratorAccess && $hasAnyDirectActionAccess): ?>
          <section class="mod-card mod-pane<?php echo $defaultWorkspaceView === 'moderation' ? ' is-active' : ''; ?>" id="direct-actions" data-mod-pane="moderation">
            <div class="mod-header">
              <div>
                <span class="section-kicker"><?php echo bg_escape(bg_mod_t('actions.kicker', [], $uiLang)); ?></span>
                <h2><?php echo bg_escape(bg_mod_t('actions.title', [], $uiLang)); ?></h2>
                <p><?php echo bg_escape(bg_mod_t('actions.copy', [], $uiLang)); ?></p>
              </div>
              <div class="mod-pill-row">
<?php foreach ($directActionButtons as $button): ?>
<?php if ($button['enabled']): ?>
                <span class="mod-pill"><?php echo bg_escape($button['label']); ?></span>
<?php endif; ?>
<?php endforeach; ?>
              </div>
            </div>

            <form class="mod-direct-form" method="post" action="">
              <input type="hidden" name="form_type" value="direct_discord_action">
              <input type="hidden" name="csrf_token" value="<?php echo bg_escape($csrfDirectActionToken); ?>">

              <div class="mod-direct-form__grid">
                <label>
                  <span><?php echo bg_escape(bg_mod_t('actions.discord_user_id', [], $uiLang)); ?></span>
                  <input type="text" name="target_user_id" maxlength="21" inputmode="numeric" placeholder="<?php echo bg_escape(bg_mod_t('actions.placeholder_user_id', [], $uiLang)); ?>">
                </label>
                <label>
                  <span><?php echo bg_escape(bg_mod_t('actions.display_label', [], $uiLang)); ?></span>
                  <input type="text" name="target_user_label" maxlength="120" placeholder="<?php echo bg_escape(bg_mod_t('actions.placeholder_display_label', [], $uiLang)); ?>">
                </label>
                <label>
                  <span><?php echo bg_escape(bg_mod_t('actions.public_reason', [], $uiLang)); ?></span>
                  <input type="text" name="public_reason" maxlength="220" placeholder="<?php echo bg_escape(bg_mod_t('actions.placeholder_public_reason', [], $uiLang)); ?>">
                </label>
                <label>
                  <span><?php echo bg_escape(bg_mod_t('actions.ban_duration', [], $uiLang)); ?></span>
                  <input type="text" name="ban_duration" maxlength="40" placeholder="<?php echo bg_escape(bg_mod_t('actions.placeholder_ban_duration', [], $uiLang)); ?>">
                </label>
                <label>
                  <span><?php echo bg_escape(bg_mod_t('actions.timeout_minutes', [], $uiLang)); ?></span>
                  <input type="number" name="timeout_minutes" min="1" max="40320" placeholder="<?php echo bg_escape(bg_mod_t('actions.placeholder_timeout', [], $uiLang)); ?>">
                </label>
                <label>
                  <span><?php echo bg_escape(bg_mod_t('actions.moderator_reason', [], $uiLang)); ?></span>
                  <textarea name="moderation_reason" maxlength="1500" placeholder="<?php echo bg_escape(bg_mod_t('actions.placeholder_reason', [], $uiLang)); ?>"></textarea>
                </label>
              </div>

              <div class="mod-direct-form__buttons">
<?php foreach ($directActionButtons as $button): ?>
                <button
                  type="submit"
                  class="btn <?php echo bg_escape($button['class']); ?>"
                  name="direct_action"
                  value="<?php echo bg_escape($button['action']); ?>"
<?php echo $button['enabled'] ? '' : ' disabled'; ?>
                ><?php echo bg_escape($button['label']); ?></button>
<?php endforeach; ?>
              </div>
            </form>
          </section>
<?php endif; ?>

<?php if ($hasModeratorAccess && $settingsTabs !== []): ?>
          <section class="mod-card mod-pane<?php echo $defaultWorkspaceView === 'settings' ? ' is-active' : ''; ?>" id="panel-settings" data-mod-pane="settings">
            <div class="mod-header">
              <div>
                <span class="section-kicker"><?php echo bg_escape(bg_mod_t('settings.kicker', [], $uiLang)); ?></span>
                <h2><?php echo bg_escape(bg_mod_t('settings.title', [], $uiLang)); ?></h2>
                <p><?php echo bg_escape(bg_mod_t('settings.copy', [], $uiLang)); ?></p>
              </div>
            </div>

            <div class="mod-settings-shell">
              <aside class="mod-settings-rail">
                <div class="mod-settings-rail__intro">
                  <span class="mod-label"><?php echo bg_escape(bg_mod_t('settings.kicker', [], $uiLang)); ?></span>
                  <h3><?php echo bg_escape(bg_mod_t('settings.title', [], $uiLang)); ?></h3>
                  <p><?php echo bg_escape(bg_mod_t('settings.copy', [], $uiLang)); ?></p>
                </div>

                <ul class="nav nav-pills mod-tab-nav mod-tab-nav--stacked" id="modSettingsTabs" role="tablist">
<?php foreach ($settingsTabs as $settingsTab): ?>
<?php $isActiveSettingsTab = $settingsTab['key'] === $defaultSettingsTab; ?>
                  <li class="nav-item" role="presentation">
                    <button
                      class="nav-link<?php echo $isActiveSettingsTab ? ' active' : ''; ?>"
                      id="settings-tab-<?php echo bg_escape($settingsTab['key']); ?>"
                      data-bs-toggle="pill"
                      data-bs-target="#settings-pane-<?php echo bg_escape($settingsTab['key']); ?>"
                      type="button"
                      role="tab"
                      aria-controls="settings-pane-<?php echo bg_escape($settingsTab['key']); ?>"
                      aria-selected="<?php echo $isActiveSettingsTab ? 'true' : 'false'; ?>"
                    >
                      <?php echo bg_escape($settingsTab['label']); ?>
                      <small><?php echo bg_escape($settingsTab['description']); ?></small>
                    </button>
                  </li>
<?php endforeach; ?>
                </ul>

                <div class="mod-settings-rail__summary">
                  <article>
                    <span class="mod-mini-label"><?php echo bg_escape(bg_mod_t('settings.current_channel', [], $uiLang)); ?></span>
                    <strong><?php echo bg_escape($currentAppealChannelId !== '' ? $currentAppealChannelId : bg_mod_t('misc.not_configured', [], $uiLang)); ?></strong>
                  </article>
                  <article>
                    <span class="mod-mini-label"><?php echo bg_escape(bg_mod_t('settings.webhook_host', [], $uiLang)); ?></span>
                    <strong><?php echo bg_escape($currentWebhookUrl !== '' ? $webhookHost : bg_mod_t('misc.not_configured', [], $uiLang)); ?></strong>
                  </article>
                </div>
              </aside>

              <div class="mod-settings-main">
                <div class="mod-settings-summary-grid">
                  <article>
                    <span class="mod-mini-label"><?php echo bg_escape(bg_mod_t('settings.current_channel', [], $uiLang)); ?></span>
                    <strong><?php echo bg_escape($currentAppealChannelId !== '' ? $currentAppealChannelId : bg_mod_t('misc.not_configured', [], $uiLang)); ?></strong>
                  </article>
                  <article>
                    <span class="mod-mini-label"><?php echo bg_escape(bg_mod_t('settings.webhook_host', [], $uiLang)); ?></span>
                    <strong><?php echo bg_escape($currentWebhookUrl !== '' ? $webhookHost : bg_mod_t('misc.not_configured', [], $uiLang)); ?></strong>
                  </article>
                  <article>
                    <span class="mod-mini-label"><?php echo bg_escape(bg_mod_t('settings.permissions.capability', [], $uiLang)); ?></span>
                    <strong><?php echo bg_escape(implode(', ', $editableRoleLabels)); ?></strong>
                  </article>
                </div>

                <div class="tab-content mod-tab-content" id="modSettingsTabContent">
<?php if ($canManageChannel): ?>
                  <div
                    class="tab-pane fade mod-tab-pane<?php echo $defaultSettingsTab === 'routing' ? ' show active' : ''; ?>"
                    id="settings-pane-routing"
                    role="tabpanel"
                    aria-labelledby="settings-tab-routing"
                    tabindex="0"
                  >
                    <span class="mod-label"><?php echo bg_escape(bg_mod_t('settings.routing.kicker', [], $uiLang)); ?></span>
                    <h3><?php echo bg_escape(bg_mod_t('settings.routing.title', [], $uiLang)); ?></h3>
                    <p class="mod-caption"><?php echo bg_escape(bg_mod_t('settings.routing.copy', [], $uiLang)); ?></p>

                    <div class="mod-settings-grid" style="margin-top:1rem;">
                      <article>
                        <span class="mod-label"><?php echo bg_escape(bg_mod_t('settings.current_channel', [], $uiLang)); ?></span>
                        <strong><?php echo bg_escape($currentAppealChannelId !== '' ? $currentAppealChannelId : bg_mod_t('misc.not_configured', [], $uiLang)); ?></strong>
                      </article>
                      <article>
                        <span class="mod-label"><?php echo bg_escape(bg_mod_t('settings.webhook_host', [], $uiLang)); ?></span>
                        <strong><?php echo bg_escape($currentWebhookUrl !== '' ? $webhookHost : bg_mod_t('misc.not_configured', [], $uiLang)); ?></strong>
                      </article>
                    </div>

                    <form class="mod-settings-form" method="post" action="">
                      <input type="hidden" name="form_type" value="channel_settings">
                      <input type="hidden" name="csrf_token" value="<?php echo bg_escape($csrfSettingsToken); ?>">

                      <label>
                        <span><?php echo bg_escape(bg_mod_t('settings.channel_input', [], $uiLang)); ?></span>
                        <input
                          type="text"
                          name="appeal_channel_id"
                          maxlength="21"
                          inputmode="numeric"
                          value="<?php echo bg_escape($currentAppealChannelId); ?>"
                          placeholder="<?php echo bg_escape(bg_mod_t('settings.channel_placeholder', [], $uiLang)); ?>"
                        >
                      </label>

                      <div class="mod-inline-actions">
                        <button type="submit" class="btn btn-brand"><?php echo bg_escape(bg_mod_t('settings.save_channel', [], $uiLang)); ?></button>
                      </div>
                    </form>
                  </div>
<?php endif; ?>

<?php if ($canSendTests): ?>
                  <div
                    class="tab-pane fade mod-tab-pane<?php echo $defaultSettingsTab === 'tests' ? ' show active' : ''; ?>"
                    id="settings-pane-tests"
                    role="tabpanel"
                    aria-labelledby="settings-tab-tests"
                    tabindex="0"
                  >
                    <span class="mod-label"><?php echo bg_escape(bg_mod_t('settings.tests.kicker', [], $uiLang)); ?></span>
                    <h3><?php echo bg_escape(bg_mod_t('settings.tests.title', [], $uiLang)); ?></h3>
                    <p class="mod-caption"><?php echo bg_escape(bg_mod_t('settings.tests.copy', [], $uiLang)); ?></p>

                    <div class="mod-settings-grid" style="margin-top:1rem;">
                      <article>
                        <span class="mod-label"><?php echo bg_escape(bg_mod_t('settings.default_test_channel', [], $uiLang)); ?></span>
                        <strong><?php echo bg_escape($currentAppealChannelId !== '' ? $currentAppealChannelId : bg_mod_t('misc.not_configured', [], $uiLang)); ?></strong>
                      </article>
                      <article>
                        <span class="mod-label"><?php echo bg_escape(bg_mod_t('settings.webhook_target', [], $uiLang)); ?></span>
                        <strong><?php echo bg_escape($currentWebhookUrl !== '' ? $webhookHost : bg_mod_t('misc.not_configured', [], $uiLang)); ?></strong>
                      </article>
                    </div>

                    <form class="mod-settings-form" method="post" action="">
                      <input type="hidden" name="form_type" value="test_channel_message">
                      <input type="hidden" name="csrf_token" value="<?php echo bg_escape($csrfTestsToken); ?>">

                      <label>
                        <span><?php echo bg_escape(bg_mod_t('settings.test_channel_id', [], $uiLang)); ?></span>
                        <input
                          type="text"
                          name="test_channel_id"
                          maxlength="21"
                          inputmode="numeric"
                          value="<?php echo bg_escape($currentAppealChannelId); ?>"
                          placeholder="<?php echo bg_escape(bg_mod_t('settings.test_channel_placeholder', [], $uiLang)); ?>"
                        >
                      </label>

                      <label>
                        <span><?php echo bg_escape(bg_mod_t('settings.test_message', [], $uiLang)); ?></span>
                        <textarea name="test_message" maxlength="1800" placeholder="<?php echo bg_escape(bg_mod_t('settings.test_message_placeholder', [], $uiLang)); ?>"></textarea>
                      </label>

                      <div class="mod-inline-actions">
                        <button type="submit" class="btn btn-ghost"><?php echo bg_escape(bg_mod_t('settings.send_bot_test', [], $uiLang)); ?></button>
                      </div>
                    </form>

<?php if ($isAdminModerator): ?>
                    <div class="mod-settings-grid" style="margin-top:1rem;">
                      <article style="grid-column: 1 / -1;">
                        <span class="mod-label"><?php echo bg_escape(bg_mod_t('settings.bot_post_kicker', [], $uiLang)); ?></span>
                        <strong><?php echo bg_escape(bg_mod_t('settings.bot_post_title', [], $uiLang)); ?></strong>
                        <p class="mod-caption" style="margin-top:0.65rem;"><?php echo bg_escape(bg_mod_t('settings.bot_post_copy', [], $uiLang)); ?></p>
                      </article>
                    </div>

                    <form class="mod-settings-form" method="post" action="">
                      <input type="hidden" name="form_type" value="admin_bot_post">
                      <input type="hidden" name="csrf_token" value="<?php echo bg_escape($csrfAdminBotPostToken); ?>">

                      <label>
                        <span><?php echo bg_escape(bg_mod_t('settings.bot_post_channel_id', [], $uiLang)); ?></span>
                        <input
                          type="text"
                          name="bot_post_channel_id"
                          maxlength="21"
                          inputmode="numeric"
                          value="<?php echo bg_escape($currentAppealChannelId); ?>"
                          placeholder="<?php echo bg_escape(bg_mod_t('settings.test_channel_placeholder', [], $uiLang)); ?>"
                        >
                      </label>

                      <label>
                        <span><?php echo bg_escape(bg_mod_t('settings.bot_post_message', [], $uiLang)); ?></span>
                        <textarea name="bot_post_message" maxlength="1800" placeholder="<?php echo bg_escape(bg_mod_t('settings.bot_post_message_placeholder', [], $uiLang)); ?>"></textarea>
                      </label>

                      <div class="mod-inline-actions">
                        <button type="submit" class="btn btn-ghost"><?php echo bg_escape(bg_mod_t('settings.send_bot_post', [], $uiLang)); ?></button>
                      </div>
                    </form>
<?php endif; ?>

                    <form class="mod-settings-form" method="post" action="">
                      <input type="hidden" name="form_type" value="test_webhook">
                      <input type="hidden" name="csrf_token" value="<?php echo bg_escape($csrfTestsToken); ?>">

                      <label>
                        <span><?php echo bg_escape(bg_mod_t('settings.webhook_text', [], $uiLang)); ?></span>
                        <textarea name="test_message" maxlength="1800" placeholder="<?php echo bg_escape(bg_mod_t('settings.webhook_placeholder', [], $uiLang)); ?>"></textarea>
                      </label>

                      <div class="mod-inline-actions">
                        <button type="submit" class="btn btn-ghost" <?php echo $currentWebhookUrl === '' ? 'disabled' : ''; ?>><?php echo bg_escape(bg_mod_t('settings.send_webhook_test', [], $uiLang)); ?></button>
                      </div>
                    </form>
                  </div>
<?php endif; ?>

<?php if ($canEditPermissions): ?>
                  <div
                    class="tab-pane fade mod-tab-pane<?php echo $defaultSettingsTab === 'permissions' ? ' show active' : ''; ?>"
                    id="settings-pane-permissions"
                    role="tabpanel"
                    aria-labelledby="settings-tab-permissions"
                    tabindex="0"
                  >
                    <span class="mod-label"><?php echo bg_escape(bg_mod_t('settings.permissions.kicker', [], $uiLang)); ?></span>
                    <h3><?php echo bg_escape(bg_mod_t('settings.permissions.title', [], $uiLang)); ?></h3>
                    <p class="mod-caption"><?php echo bg_escape(bg_mod_t('settings.permissions.copy', [], $uiLang)); ?></p>

                    <form method="post" action="">
                      <input type="hidden" name="form_type" value="permission_policy">
                      <input type="hidden" name="csrf_token" value="<?php echo bg_escape($csrfPermissionToken); ?>">

                      <div class="mod-perm-grid">
                        <div class="mod-perm-grid__head"><?php echo bg_escape(bg_mod_t('settings.permissions.capability', [], $uiLang)); ?></div>
                        <div class="mod-perm-grid__head"><?php echo bg_escape(bg_mod_t('settings.permissions.moderator', [], $uiLang)); ?></div>
                        <div class="mod-perm-grid__head"><?php echo bg_escape(bg_mod_t('settings.permissions.support', [], $uiLang)); ?></div>
                        <div class="mod-perm-grid__head"><?php echo bg_escape(bg_mod_t('settings.permissions.social_manager', [], $uiLang)); ?></div>

<?php foreach ($capabilityCatalog as $capabilityKey => $capabilityMeta): ?>
                        <article>
                          <strong><?php echo bg_escape(bg_mod_capability_short_label((string) $capabilityKey)); ?></strong>
                          <div class="mod-caption"><?php echo bg_escape(bg_mod_capability_description((string) $capabilityKey)); ?></div>
                        </article>
<?php foreach (['moderator', 'support', 'social_manager'] as $roleKey): ?>
<?php
                        $enabled = in_array($capabilityKey, $roleCapabilities[$roleKey] ?? [], true);
                        $isFixed = in_array($capabilityKey, $fixedCapabilities, true);
?>
                        <div>
                          <label>
                            <input
                              type="checkbox"
                              name="role_capabilities[<?php echo bg_escape($roleKey); ?>][]"
                              value="<?php echo bg_escape($capabilityKey); ?>"
<?php echo $enabled ? ' checked' : ''; ?>
<?php echo $isFixed ? ' disabled' : ''; ?>
                            >
                            <span><?php echo bg_escape($enabled ? bg_mod_t('state.enabled', [], $uiLang) : bg_mod_t('state.disabled', [], $uiLang)); ?></span>
                          </label>
<?php if ($isFixed): ?>
                          <input type="hidden" name="role_capabilities[<?php echo bg_escape($roleKey); ?>][]" value="<?php echo bg_escape($capabilityKey); ?>">
<?php endif; ?>
                        </div>
<?php endforeach; ?>
<?php endforeach; ?>
                      </div>

                      <p class="mod-caption">
                        <?php echo bg_escape(bg_mod_t('settings.permissions.footer', [], $uiLang)); ?>
                      </p>

                      <div class="mod-inline-actions" style="margin-top:1rem;">
                        <button type="submit" class="btn btn-brand"><?php echo bg_escape(bg_mod_t('settings.save_permissions', [], $uiLang)); ?></button>
                      </div>
                    </form>
                  </div>
<?php endif; ?>
                </div>
              </div>
            </div>
          </section>
<?php endif; ?>

<?php if ($hasModeratorAccess && $canViewBans): ?>
          <section class="mod-card mod-pane<?php echo $defaultWorkspaceView === 'moderation' ? ' is-active' : ''; ?>" id="blocked-list" data-mod-pane="moderation">
            <div class="mod-header">
              <div>
                <span class="section-kicker"><?php echo bg_escape(bg_mod_t('blocked.kicker', [], $uiLang)); ?></span>
                <h2><?php echo bg_escape(bg_mod_t('blocked.title', [], $uiLang)); ?></h2>
                <p><?php echo bg_escape(bg_mod_t('blocked.copy', [], $uiLang)); ?></p>
              </div>
              <span class="mod-status mod-status--warning"><?php echo bg_escape(bg_mod_t('blocked.records', ['count' => (string) count($activeBans)], $uiLang)); ?></span>
            </div>

<?php if ($activeBans !== []): ?>
            <div class="mod-list-stack">
<?php foreach ($activeBans as $ban): ?>
<?php
                $banReference = (string) ($ban['public_reference'] ?? '');
                $banLabel = trim((string) ($ban['global_name'] ?? '')) ?: (string) ($ban['username'] ?? bg_mod_t('misc.unknown_user', [], $uiLang));
?>
              <details class="mod-list-item">
                <summary>
                  <div class="mod-list-item__head">
                    <div>
                      <span class="mod-label"><?php echo bg_escape($banReference); ?></span>
                      <strong><?php echo bg_escape($banLabel); ?></strong>
                      <div class="mod-meta"><?php echo bg_escape(bg_mask_discord_id((string) ($ban['discord_user_id'] ?? ''))); ?> / <?php echo bg_escape(bg_mod_ban_status_text((string) ($ban['status'] ?? 'active'))); ?></div>
                    </div>
                    <div class="mod-pill-row">
                      <span class="mod-status mod-status--danger"><?php echo bg_escape(bg_mod_appeal_status_text((string) ($ban['appeal_status'] ?? 'not_requested'))); ?></span>
                    </div>
                  </div>
                </summary>

                <div class="mod-list-item__details">
                  <div class="mod-list-item__grid">
                    <article>
                      <span class="mod-label"><?php echo bg_escape(bg_mod_t('appeal.public_reason', [], $uiLang)); ?></span>
                      <strong><?php echo bg_escape(sanitizePublicReason((string) ($ban['public_reason'] ?? bg_default_public_reason()))); ?></strong>
                    </article>
                    <article>
                      <span class="mod-label"><?php echo bg_escape(bg_mod_t('blocked.moderator', [], $uiLang)); ?></span>
                      <strong><?php echo bg_escape((string) (($ban['moderator_name'] ?? '') !== '' ? $ban['moderator_name'] : bg_mod_t('misc.unknown_moderator', [], $uiLang))); ?></strong>
                    </article>
                    <article>
                      <span class="mod-label"><?php echo bg_escape(bg_mod_t('blocked.banned_at', [], $uiLang)); ?></span>
                      <strong><?php echo bg_escape(bg_mod_format_datetime((string) ($ban['banned_at'] ?? ''))); ?></strong>
                    </article>
                    <article>
                      <span class="mod-label"><?php echo bg_escape(bg_mod_t('blocked.expires_at', [], $uiLang)); ?></span>
                      <strong><?php echo bg_escape(trim((string) ($ban['expires_at'] ?? '')) !== '' ? bg_mod_format_datetime((string) $ban['expires_at']) : bg_mod_t('blocked.permanent', [], $uiLang)); ?></strong>
                    </article>
<?php if ($canViewPrivateReasons && trim((string) ($ban['private_reason'] ?? '')) !== ''): ?>
                    <article style="grid-column: 1 / -1;">
                      <span class="mod-label"><?php echo bg_escape(bg_mod_t('appeal.private_reason', [], $uiLang)); ?></span>
                      <strong><?php echo bg_escape((string) $ban['private_reason']); ?></strong>
                    </article>
<?php endif; ?>
                  </div>

<?php if ($canDirectUnban): ?>
                  <form method="post" action="" class="mod-inline-actions">
                    <input type="hidden" name="form_type" value="direct_discord_action">
                    <input type="hidden" name="csrf_token" value="<?php echo bg_escape($csrfDirectActionToken); ?>">
                    <input type="hidden" name="target_user_id" value="<?php echo bg_escape((string) ($ban['discord_user_id'] ?? '')); ?>">
                    <input type="hidden" name="target_user_label" value="<?php echo bg_escape($banLabel); ?>">
                    <button type="submit" class="btn btn-success-soft" name="direct_action" value="unban"><?php echo bg_escape(bg_mod_t('blocked.unban', [], $uiLang)); ?></button>
                  </form>
<?php endif; ?>
                </div>
              </details>
<?php endforeach; ?>
            </div>
<?php else: ?>
            <p class="mod-empty"><?php echo bg_escape(bg_mod_t('blocked.no_records', [], $uiLang)); ?></p>
<?php endif; ?>
          </section>
<?php endif; ?>

<?php if ($hasModeratorAccess && $canViewAudit): ?>
          <section class="mod-card mod-pane<?php echo $defaultWorkspaceView === 'audit' ? ' is-active' : ''; ?>" id="audit-log" data-mod-pane="audit">
            <div class="mod-header">
              <div>
                <span class="section-kicker"><?php echo bg_escape(bg_mod_t('audit.kicker', [], $uiLang)); ?></span>
                <h2><?php echo bg_escape(bg_mod_t('audit.title', [], $uiLang)); ?></h2>
                <p><?php echo bg_escape(bg_mod_t('audit.copy', [], $uiLang)); ?></p>
              </div>
              <span class="mod-status"><?php echo bg_escape(bg_mod_t('audit.entries', ['count' => (string) count($auditEntries)], $uiLang)); ?></span>
            </div>

<?php if ($auditEntries !== []): ?>
            <div class="mod-audit-list">
<?php foreach ($auditEntries as $entry): ?>
              <article class="mod-audit-item">
                <div class="mod-audit-item__head">
                  <div>
                    <span class="mod-label"><?php echo bg_escape(bg_mod_audit_action_label((string) ($entry['action_type'] ?? ''))); ?></span>
                    <strong><?php echo bg_escape(bg_mod_translate_runtime_message((string) ($entry['summary'] ?? bg_mod_t('misc.moderator_action', [], $uiLang)), $uiLang)); ?></strong>
                    <div class="mod-meta">
                      <?php echo bg_escape(bg_mod_actor_label((string) ($entry['actor_display_name'] ?? ''), (string) ($entry['actor_username'] ?? ''))); ?>
                      /
                      <?php echo bg_escape(bg_mod_format_datetime((string) ($entry['created_at'] ?? ''))); ?>
                    </div>
                  </div>
                  <div class="mod-pill-row">
                    <span class="mod-status <?php echo bg_escape(bg_mod_result_class((string) ($entry['action_result'] ?? 'success'))); ?>">
                      <?php echo bg_escape(bg_mod_result_label((string) ($entry['action_result'] ?? 'success'))); ?>
                    </span>
<?php foreach ((array) ($entry['role_keys'] ?? []) as $roleKey): ?>
                    <span class="mod-pill"><?php echo bg_escape(bg_mod_role_label($roleKey)); ?></span>
<?php endforeach; ?>
                  </div>
                </div>

<?php if (trim((string) ($entry['target_label'] ?? '')) !== '' || trim((string) ($entry['target_reference'] ?? '')) !== ''): ?>
                <div class="mod-meta" style="margin-top:0.55rem;">
                  <?php echo bg_escape(bg_mod_t('audit.target', [], $uiLang)); ?>:
                  <?php echo bg_escape((string) (($entry['target_label'] ?? '') !== '' ? $entry['target_label'] : ($entry['target_reference'] ?? ''))); ?>
<?php if (trim((string) ($entry['target_reference'] ?? '')) !== ''): ?>
                  / <?php echo bg_escape((string) $entry['target_reference']); ?>
<?php endif; ?>
                </div>
<?php endif; ?>
              </article>
<?php endforeach; ?>
            </div>
<?php else: ?>
            <p class="mod-empty"><?php echo bg_escape(bg_mod_t('audit.no_entries', [], $uiLang)); ?></p>
<?php endif; ?>
          </section>
<?php endif; ?>
        </section>

        <aside class="mod-sidebar">
          <section class="mod-sidebar-card">
            <span class="mod-kicker"><?php echo bg_escape(bg_mod_t('session.kicker', [], $uiLang)); ?></span>
            <h2><?php echo bg_escape(bg_mod_t('session.title', [], $uiLang)); ?></h2>
<?php if ($hasModeratorAccess): ?>
            <p class="mod-session-summary"><?php echo bg_escape(bg_mod_t('session.copy_member', [], $uiLang)); ?></p>
            <div class="mod-user-card" style="margin-top:0.8rem;">
<?php if (trim((string) ($moderatorUser['avatar_url'] ?? '')) !== ''): ?>
              <img class="mod-user__avatar" src="<?php echo bg_escape((string) $moderatorUser['avatar_url']); ?>" alt="<?php echo bg_escape(bg_mod_t('session.avatar_alt', [], $uiLang)); ?>">
<?php else: ?>
              <span class="mod-user__avatar mod-user__avatar--fallback"><?php echo bg_escape(bg_avatar_initials((string) ($moderatorUser['username'] ?? 'BG'), (string) ($moderatorUser['global_name'] ?? ''))); ?></span>
<?php endif; ?>
              <div class="mod-user-card__meta">
                <strong><?php echo bg_escape(bg_mod_display_name($moderatorUser)); ?></strong>
                <small><?php echo bg_escape((string) ($moderatorUser['username'] ?? '')); ?> / <?php echo bg_escape(bg_mask_discord_id((string) ($moderatorUser['id'] ?? ''))); ?></small>
<?php if ($moderatorRoleKeys !== []): ?>
                <div class="mod-user-card__roles">
<?php foreach ($moderatorRoleKeys as $roleKey): ?>
                  <span class="mod-pill mod-user-card__role"><?php echo bg_escape(bg_mod_role_label($roleKey)); ?></span>
<?php endforeach; ?>
                </div>
<?php endif; ?>
              </div>
            </div>

            <div class="mod-panel-grid" style="margin-top:1rem;">
              <article>
                <span class="mod-label"><?php echo bg_escape(bg_mod_t('session.guild_access', [], $uiLang)); ?></span>
                <strong><?php echo bg_escape(bg_mod_t('session.access.' . (string) ($moderatorUser['authorized_reason'] ?? 'discord_permissions'), [], $uiLang)); ?></strong>
              </article>
              <article>
                <span class="mod-label"><?php echo bg_escape(bg_mod_t('session.roles', [], $uiLang)); ?></span>
                <strong><?php echo bg_escape(bg_mod_role_list_text($moderatorRoleKeys)); ?></strong>
              </article>
            </div>

            <div class="mod-inline-actions" style="margin-top:1rem;">
              <a class="btn btn-ghost" href="<?php echo bg_escape($logoutUrl); ?>"><?php echo bg_escape(bg_mod_t('nav.logout', [], $uiLang)); ?></a>
            </div>
<?php else: ?>
            <p><?php echo bg_escape(bg_mod_t('session.copy_guest', [], $uiLang)); ?></p>
<?php if (!$oauthEnabled): ?>
            <div class="mod-alert mod-alert--error" style="margin-top:1rem;"><?php echo bg_escape(bg_mod_t('session.oauth_missing', [], $uiLang)); ?></div>
<?php endif; ?>
<?php endif; ?>
          </section>

<?php if ($hasModeratorAccess && $canViewLoginRoster): ?>
          <section class="mod-sidebar-card is-active" id="login-roster-panel" data-mod-sidebar-pane="overview">
            <span class="mod-kicker"><?php echo bg_escape(bg_mod_t('roster.kicker', [], $uiLang)); ?></span>
            <h2><?php echo bg_escape(bg_mod_t('roster.title', [], $uiLang)); ?></h2>
            <p><?php echo bg_escape(bg_mod_t('roster.copy', ['in_server' => (string) $loginRosterInServer, 'outside' => (string) $loginRosterOutsideServer], $uiLang)); ?></p>

<?php if ($loginRoster !== []): ?>
            <div class="mod-roster">
<?php foreach ($loginRoster as $loginUser): ?>
              <article class="mod-roster__item">
                <div class="mod-roster__head">
                  <div>
                    <strong><?php echo bg_escape((string) ($loginUser['display_name'] ?? bg_mod_t('misc.unknown_moderator', [], $uiLang))); ?></strong>
                    <div class="mod-meta"><?php echo bg_escape((string) ($loginUser['username'] ?? '')); ?></div>
                  </div>
                  <span class="mod-status <?php echo !empty($loginUser['in_server']) ? 'mod-status--success' : 'mod-status--danger'; ?>">
                    <span class="mod-indicator <?php echo !empty($loginUser['in_server']) ? 'mod-indicator--success' : 'mod-indicator--danger'; ?>"></span>
                    <?php echo !empty($loginUser['in_server']) ? bg_escape(bg_mod_t('roster.in_server', [], $uiLang)) : bg_escape(bg_mod_t('roster.not_in_server', [], $uiLang)); ?>
                  </span>
                </div>
                <div class="mod-meta">
                  <?php echo bg_escape(bg_mod_role_list_text((array) ($loginUser['role_keys'] ?? []))); ?>
                  /
                  <?php echo bg_escape(bg_mod_t('roster.last_login', ['date' => bg_mod_format_datetime((string) ($loginUser['last_login_at'] ?? ''))], $uiLang)); ?>
                </div>
              </article>
<?php endforeach; ?>
            </div>
<?php else: ?>
            <p class="mod-empty"><?php echo bg_escape(bg_mod_t('roster.none', [], $uiLang)); ?></p>
<?php endif; ?>
          </section>
<?php endif; ?>

<?php if ($hasModeratorAccess && $canViewDashboard): ?>
          <section class="mod-sidebar-card<?php echo $defaultWorkspaceView === 'moderation' ? ' is-active' : ''; ?>" id="review-queue-panel" data-mod-sidebar-pane="moderation">
            <span class="mod-kicker"><?php echo bg_escape(bg_mod_t('queue.kicker', [], $uiLang)); ?></span>
            <h2><?php echo bg_escape(bg_mod_t('queue.title', [], $uiLang)); ?></h2>
<?php if ($recentAppeals !== []): ?>
            <div class="mod-roster">
<?php foreach ($recentAppeals as $item): ?>
<?php $itemReference = (string) ($item['public_reference'] ?? ''); ?>
              <article class="mod-roster__item">
                <div class="mod-roster__head">
                  <div>
                    <strong><?php echo bg_escape((string) ($item['discord_username'] ?? bg_mod_t('misc.unknown_user', [], $uiLang))); ?></strong>
                    <div class="mod-meta"><?php echo bg_escape($itemReference); ?></div>
                  </div>
                  <span class="mod-status"><?php echo bg_escape(bg_mod_appeal_status_text((string) ($item['status'] ?? 'pending'))); ?></span>
                </div>
                <div class="mod-inline-actions">
                  <a class="btn btn-ghost" href="<?php echo bg_escape(bg_mod_page_url(['appeal' => $itemReference])); ?>"><?php echo bg_escape(bg_mod_t('queue.open', [], $uiLang)); ?></a>
                </div>
              </article>
<?php endforeach; ?>
            </div>
<?php else: ?>
            <p class="mod-empty"><?php echo bg_escape(bg_mod_t('queue.none', [], $uiLang)); ?></p>
<?php endif; ?>
          </section>
<?php elseif ($hasSignedAccess): ?>
          <section class="mod-sidebar-card<?php echo $defaultWorkspaceView === 'moderation' ? ' is-active' : ''; ?>" data-mod-sidebar-pane="moderation">
            <span class="mod-kicker"><?php echo bg_escape(bg_mod_t('signed.kicker', [], $uiLang)); ?></span>
            <h2><?php echo bg_escape(bg_mod_t('signed.title', [], $uiLang)); ?></h2>
            <p><?php echo bg_escape(bg_mod_t('signed.copy', [], $uiLang)); ?></p>
            <div class="mod-inline-actions">
              <a class="btn btn-ghost" href="<?php echo bg_escape($panelUrl); ?>"><?php echo bg_escape(bg_mod_t('signed.open_panel', [], $uiLang)); ?></a>
<?php if ($canApproveUnbanAppeals): ?>
              <a class="btn btn-success-soft" href="<?php echo bg_escape($unbanUrl); ?>"><?php echo bg_escape(bg_mod_t('signed.unban', [], $uiLang)); ?></a>
<?php endif; ?>
<?php if ($canRejectAppeals): ?>
              <a class="btn btn-danger-soft" href="<?php echo bg_escape($rejectUrl); ?>"><?php echo bg_escape(bg_mod_t('signed.reject', [], $uiLang)); ?></a>
<?php endif; ?>
            </div>
          </section>
<?php endif; ?>
        </aside>
      </div>
    </div>
  </main>

  <script
    src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/js/bootstrap.bundle.min.js"
    integrity="sha384-FKyoEForCGlyvwx9Hj09JcYn3nv7wiPVlz7YYwJrWVcXK/BmnVDxM+D2scQbITxI"
    crossorigin="anonymous"
  ></script>
  <script src="../assets/site-nav.js?v=20260714-4"></script>
  <script>
    (() => {
      const storageKey = "bg-mod-workspace-view";
      const settingsStorageKey = "bg-mod-settings-tab";
      const nav = document.querySelector("[data-mod-workspace-nav]");
      const buttons = Array.from(document.querySelectorAll("[data-mod-view-trigger]"));
      const panes = Array.from(document.querySelectorAll("[data-mod-pane]"));
      const sidebarPanes = Array.from(document.querySelectorAll("[data-mod-sidebar-pane]"));
      const chartRoot = document.querySelector("[data-mod-chart-root]");
      const jumpButtons = Array.from(document.querySelectorAll("[data-mod-jump]"));
      const settingsTabButtons = Array.from(document.querySelectorAll("#modSettingsTabs [data-bs-target]"));
      const reduceMotion = window.matchMedia("(prefers-reduced-motion: reduce)").matches;
      let chartsActivated = false;

      function readStoredView() {
        try {
          return window.localStorage.getItem(storageKey) || "";
        } catch (_error) {
          return "";
        }
      }

      function storeView(view) {
        try {
          window.localStorage.setItem(storageKey, view);
        } catch (_error) {
          // Ignore storage failures.
        }
      }

      function readStoredSettingsTab() {
        try {
          return window.localStorage.getItem(settingsStorageKey) || "";
        } catch (_error) {
          return "";
        }
      }

      function storeSettingsTab(tabId) {
        try {
          window.localStorage.setItem(settingsStorageKey, tabId);
        } catch (_error) {
          // Ignore storage failures.
        }
      }

      function isKnownView(view) {
        return buttons.some((button) => button.dataset.modViewTrigger === view);
      }

      function activateCountsAndFills() {
        if (!chartRoot) {
          return;
        }

        const formatter = new Intl.NumberFormat(document.documentElement.lang || undefined);

        chartRoot.querySelectorAll("[data-countup-value]").forEach((node) => {
          if (node.dataset.countupDone === "true") {
            return;
          }

          node.dataset.countupDone = "true";
          const target = Number.parseFloat(node.dataset.countupValue || "0");

          if (!Number.isFinite(target)) {
            return;
          }

          if (reduceMotion) {
            node.textContent = formatter.format(Math.round(target));
            return;
          }

          const startedAt = performance.now();
          const duration = 880;

          const tick = (now) => {
            const progress = Math.min(1, (now - startedAt) / duration);
            const eased = 1 - Math.pow(1 - progress, 3);
            node.textContent = formatter.format(Math.round(target * eased));

            if (progress < 1) {
              window.requestAnimationFrame(tick);
            }
          };

          window.requestAnimationFrame(tick);
        });

        chartRoot.querySelectorAll("[data-fill-target]").forEach((fill) => {
          if (fill.dataset.fillDone === "true") {
            return;
          }

          fill.dataset.fillDone = "true";
          const target = Math.max(0, Math.min(100, Number.parseFloat(fill.dataset.fillTarget || "0")));

          if (reduceMotion) {
            fill.style.width = `${target}%`;
            return;
          }

          window.requestAnimationFrame(() => {
            fill.style.width = `${target}%`;
          });
        });
      }

      function activateCharts() {
        if (!chartRoot || chartsActivated) {
          return;
        }

        chartsActivated = true;
        activateCountsAndFills();

        chartRoot.querySelectorAll("[data-ring-progress]").forEach((meter) => {
          const progress = Math.max(0, Math.min(100, Number.parseFloat(meter.dataset.ringProgress || "0")));
          const circle = meter.querySelector(".mod-ring-meter__value");

          if (!circle) {
            return;
          }

          const radius = Number.parseFloat(circle.getAttribute("r") || "46");
          const circumference = 2 * Math.PI * radius;
          const offset = circumference - ((progress / 100) * circumference);
          circle.style.strokeDasharray = `${circumference}`;
          circle.style.strokeDashoffset = reduceMotion ? `${offset}` : `${circumference}`;

          window.requestAnimationFrame(() => {
            circle.style.strokeDashoffset = `${offset}`;
          });
        });

        chartRoot.querySelectorAll("[data-mod-donut]").forEach((donut) => {
          window.requestAnimationFrame(() => {
            donut.classList.add("is-live");
          });
        });

        chartRoot.querySelectorAll("[data-mod-trend-board]").forEach((board) => {
          const path = board.querySelector("[data-mod-trend-line]");

          if (path) {
            const length = path.getTotalLength();
            path.style.strokeDasharray = `${length}`;
            path.style.strokeDashoffset = reduceMotion ? "0" : `${length}`;
            path.getBoundingClientRect();
            path.style.transition = reduceMotion
              ? "opacity 1ms linear"
              : "stroke-dashoffset 920ms cubic-bezier(0.2, 0.8, 0.2, 1) 120ms, opacity 220ms ease";
            path.style.opacity = "1";
            path.style.strokeDashoffset = "0";
          }

          board.classList.add("is-live");
        });
      }

      function setView(view, persist = true) {
        if (!isKnownView(view)) {
          return;
        }

        buttons.forEach((button) => {
          const isActive = button.dataset.modViewTrigger === view;
          button.classList.toggle("is-active", isActive);
          button.setAttribute("aria-pressed", isActive ? "true" : "false");
        });

        panes.forEach((pane) => {
          pane.classList.toggle("is-active", pane.dataset.modPane === view);
        });

        sidebarPanes.forEach((pane) => {
          pane.classList.toggle("is-active", pane.dataset.modSidebarPane === view);
        });

        if (persist) {
          storeView(view);
        }

        if (view === "overview") {
          activateCharts();
        }
      }

      function jumpToTarget(selector, view) {
        if (view && isKnownView(view)) {
          setView(view);
        }

        const target = typeof selector === "string" && selector !== ""
          ? document.querySelector(selector)
          : null;

        if (!target) {
          return;
        }

        window.setTimeout(() => {
          target.scrollIntoView({
            behavior: reduceMotion ? "auto" : "smooth",
            block: "start",
          });
        }, 60);
      }

      if (settingsTabButtons.length && window.bootstrap && window.bootstrap.Tab) {
        const showSettingsTab = (targetSelector) => {
          const tabButton = settingsTabButtons.find((button) => button.dataset.bsTarget === targetSelector);

          if (!tabButton) {
            return;
          }

          window.bootstrap.Tab.getOrCreateInstance(tabButton).show();
        };

        const storedTab = readStoredSettingsTab();

        if (storedTab !== "") {
          showSettingsTab(storedTab);
        }

        settingsTabButtons.forEach((button) => {
          button.addEventListener("shown.bs.tab", () => {
            storeSettingsTab(button.dataset.bsTarget || "");
          });
        });
      }

      if (buttons.length && nav) {
        const initialView = isKnownView(readStoredView())
          ? readStoredView()
          : (nav.dataset.defaultView || buttons[0].dataset.modViewTrigger || "overview");

        setView(initialView, false);

        buttons.forEach((button) => {
          button.addEventListener("click", () => {
            setView(button.dataset.modViewTrigger || "");
          });
        });
      } else {
        activateCharts();
      }

      jumpButtons.forEach((button) => {
        button.addEventListener("click", () => {
          jumpToTarget(button.dataset.modJump || "", button.dataset.modJumpView || "");
        });
      });
    })();
  </script>
</body>
</html>
<?php

function bg_mod_page_url(array $params = []): string
{
    if (!array_key_exists('lang', $params)) {
        $params['lang'] = bg_mod_current_language();
    }

    return bg_append_query(bg_public_url('mod/'), $params);
}

function bg_mod_record_url(
    string $appealReference,
    string $action,
    string $displayMode,
    int $expiresAt,
    string $signature
): string {
    if ($displayMode === 'discord_session') {
        return bg_mod_page_url([
            'appeal' => $appealReference,
            'action' => $action !== 'panel' ? $action : null,
        ]);
    }

    if ($displayMode === 'signed_link' && $expiresAt > 0 && $signature !== '') {
        $base = bg_public_url('mod/');
        return bg_append_query($base, [
            'appeal' => $appealReference,
            'action' => $action !== 'panel' ? $action : null,
            'expires' => $expiresAt,
            'token' => bg_build_moderation_link_signature($appealReference, $action, $expiresAt),
            'lang' => bg_mod_current_language(),
        ]);
    }

    return bg_mod_page_url([
        'appeal' => $appealReference,
        'action' => $action !== 'panel' ? $action : null,
    ]);
}

function bg_mod_current_view_url(
    string $lang,
    string $appealReference,
    string $requestedAction,
    int $expiresAt,
    string $signature,
    string $displayMode
): string {
    if ($displayMode === 'signed_link' && $appealReference !== '' && $expiresAt > 0 && $signature !== '') {
        return bg_append_query(bg_public_url('mod/'), [
            'appeal' => $appealReference,
            'action' => $requestedAction !== 'panel' ? $requestedAction : null,
            'expires' => $expiresAt,
            'token' => $signature,
            'lang' => $lang,
        ]);
    }

    return bg_mod_page_url([
        'appeal' => $appealReference !== '' ? $appealReference : null,
        'action' => $requestedAction !== 'panel' ? $requestedAction : null,
        'lang' => $lang,
    ]);
}

function bg_mod_display_name(?array $user): string
{
    if (!is_array($user)) {
        return bg_mod_t('misc.discord_moderation_team');
    }

    $globalName = trim((string) ($user['global_name'] ?? ''));

    if ($globalName !== '') {
        return $globalName;
    }

    return trim((string) ($user['username'] ?? bg_mod_t('misc.discord_moderation_team')));
}

function bg_mod_webhook_host(string $url): string
{
    if ($url === '') {
        return bg_mod_t('misc.not_configured');
    }

    $parts = parse_url($url);
    $host = trim((string) ($parts['host'] ?? ''));

    return $host !== '' ? $host : bg_mod_t('misc.configured');
}

function bg_mod_role_label(string $roleKey): string
{
    $key = 'role.' . $roleKey;
    $translated = bg_mod_t($key);
    return $translated !== $key ? $translated : ucfirst(str_replace('_', ' ', $roleKey));
}

function bg_mod_role_list_text(array $roleKeys): string
{
    if ($roleKeys === []) {
        return bg_mod_t('session.no_roles');
    }

    return implode(', ', array_map('bg_mod_role_label', $roleKeys));
}

function bg_mod_capability_short_label(string $capability): string
{
    $key = 'cap.' . $capability . '.label';
    $translated = bg_mod_t($key);
    return $translated !== $key ? $translated : $capability;
}

function bg_mod_capability_description(string $capability): string
{
    $key = 'cap.' . $capability . '.description';
    $translated = bg_mod_t($key);
    return $translated !== $key ? $translated : '';
}

function bg_mod_ban_status_text(string $status): string
{
    $normalized = strtolower(trim($status));
    if ($normalized === '') {
        $normalized = 'unknown';
    }

    $key = 'ban_status.' . $normalized;
    $translated = bg_mod_t($key);
    return $translated !== $key ? $translated : bg_mod_t('ban_status.unknown');
}

function bg_mod_appeal_status_text(string $status): string
{
    $normalized = strtolower(trim($status));
    if ($normalized === '') {
        $normalized = 'unknown';
    }

    $key = 'appeal_status.' . $normalized;
    $translated = bg_mod_t($key);
    return $translated !== $key ? $translated : bg_mod_t('appeal_status.unknown');
}

function bg_mod_ratio_percent(int $value, int $max): int
{
    if ($max <= 0 || $value <= 0) {
        return 0;
    }

    return (int) max(6, min(100, round(($value / $max) * 100)));
}

function bg_mod_format_datetime(string $value): string
{
    $trimmed = trim($value);

    if ($trimmed === '') {
        return bg_mod_t('misc.no_timestamp');
    }

    try {
        $date = (new DateTimeImmutable($trimmed))
            ->setTimezone(new DateTimeZone('Europe/Sofia'));
        return $date->format('d.m.Y H:i');
    } catch (Throwable) {
        return $trimmed;
    }
}

function bg_mod_actor_label(string $displayName, string $username): string
{
    $displayName = trim($displayName);
    $username = trim($username);

    if ($displayName !== '') {
        return $displayName;
    }

    if ($username !== '') {
        return $username;
    }

    return bg_mod_t('misc.unknown_moderator');
}

function bg_mod_audit_action_label(string $actionType): string
{
    $key = 'audit_action.' . $actionType;
    $translated = bg_mod_t($key);
    return $translated !== $key ? $translated : ucfirst(str_replace('_', ' ', $actionType));
}

function bg_mod_result_class(string $result): string
{
    return match ($result) {
        'error' => 'mod-status--danger',
        'info' => 'mod-status--warning',
        default => 'mod-status--success',
    };
}

function bg_mod_result_label(string $result): string
{
    return match (strtolower(trim($result))) {
        'error' => bg_mod_t('status.error'),
        'info', 'warning' => bg_mod_t('status.warning'),
        default => bg_mod_t('status.success'),
    };
}

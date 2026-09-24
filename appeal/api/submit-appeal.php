<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config/ban-center.php';

bg_require_method('POST');

$banReference = trim((string) ($_POST['ban_reference'] ?? ''));
$redirectUrl = bg_public_url('appeal/');
$redirectUrl = bg_append_query($redirectUrl, [
    'ban' => $banReference !== '' ? $banReference : null,
]);

$oldInput = [
    'ban_reference' => $banReference,
    'discord_username' => trim((string) ($_POST['discord_username'] ?? '')),
    'discord_user_id' => trim((string) ($_POST['discord_user_id'] ?? '')),
    'appeal_reason' => trim((string) ($_POST['appeal_reason'] ?? '')),
    'detailed_explanation' => trim((string) ($_POST['detailed_explanation'] ?? '')),
    'removal_reason' => trim((string) ($_POST['removal_reason'] ?? '')),
    'contact' => trim((string) ($_POST['contact'] ?? '')),
    'rules_confirmed' => isset($_POST['rules_confirmed']) ? '1' : '',
];

try {
    if (bg_honeypot_hit()) {
        throw new RuntimeException('Невалидно изпращане.');
    }

    if (!bg_verify_csrf((string) ($_POST['csrf_token'] ?? ''), 'appeal_form')) {
        throw new RuntimeException('Сесията изтече. Обновете страницата и опитайте отново.');
    }

    bg_request_rate_limit('appeal-submit-' . sha1(bg_client_ip()), 900, 4);

    $turnstileToken = (string) ($_POST['cf-turnstile-response'] ?? '');

    if (!bg_turnstile_verify($turnstileToken, bg_client_ip())) {
        throw new RuntimeException('Проверката за сигурност не беше успешна.');
    }

    $errors = [];

    if ($oldInput['discord_username'] === '' || mb_strlen($oldInput['discord_username'], 'UTF-8') > 80) {
        $errors['discord_username'] = 'Въведете валидно Discord име.';
    }

    if (preg_match('/^\d{15,21}$/', $oldInput['discord_user_id']) !== 1) {
        $errors['discord_user_id'] = 'Въведете валиден Discord User ID.';
    }

    if ($oldInput['appeal_reason'] === '' || mb_strlen($oldInput['appeal_reason'], 'UTF-8') > 190) {
        $errors['appeal_reason'] = 'Причината за обжалването е задължителна.';
    }

    if ($oldInput['detailed_explanation'] === '' || mb_strlen($oldInput['detailed_explanation'], 'UTF-8') < 20) {
        $errors['detailed_explanation'] = 'Добавете по-подробно обяснение.';
    }

    if ($oldInput['removal_reason'] === '' || mb_strlen($oldInput['removal_reason'], 'UTF-8') < 12) {
        $errors['removal_reason'] = 'Обяснете защо банът трябва да бъде премахнат.';
    }

    if ($oldInput['contact'] === '' || mb_strlen($oldInput['contact'], 'UTF-8') > 190) {
        $errors['contact'] = 'Полето за контакт е задължително.';
    }

    if ($oldInput['rules_confirmed'] !== '1') {
        $errors['rules_confirmed'] = 'Трябва да потвърдите, че сте прочели правилата.';
    }

    $banRecord = null;

    if ($banReference !== '') {
        $banRecord = bg_fetch_ban_record_by_reference($banReference);

        if ($banRecord === null) {
            $errors['ban_reference'] = 'Посоченият бан запис не беше намерен.';
        } elseif ($oldInput['discord_user_id'] !== (string) $banRecord['discord_user_id']) {
            $errors['discord_user_id'] = 'Discord ID не съвпада с избрания бан запис.';
        }
    }

    if ($errors !== []) {
        bg_flash_set('appeal_errors', $errors);
        bg_flash_set('appeal_old_input', $oldInput);
        bg_redirect($redirectUrl);
    }

    $attachmentPath = bg_store_uploaded_file($_FILES['attachment'] ?? []);
    $combinedDetails = trim(
        "Подробно обяснение:\n{$oldInput['detailed_explanation']}\n\nЗащо банът трябва да бъде премахнат:\n{$oldInput['removal_reason']}"
    );

    $pdo = bg_pdo();
    $pdo->beginTransaction();

    $insertStatement = $pdo->prepare(
        'INSERT INTO discord_ban_appeals (
            public_reference,
            ban_id,
            discord_user_id,
            discord_username,
            contact,
            appeal_reason,
            additional_information,
            attachment_path,
            status,
            submitted_at
        ) VALUES (
            :public_reference,
            :ban_id,
            :discord_user_id,
            :discord_username,
            :contact,
            :appeal_reason,
            :additional_information,
            :attachment_path,
            :status,
            CURRENT_TIMESTAMP
        )'
    );

    $temporaryReference = 'PENDING-' . strtoupper(bin2hex(random_bytes(5)));
    $insertStatement->execute([
        'public_reference' => $temporaryReference,
        'ban_id' => $banRecord['id'] ?? null,
        'discord_user_id' => $oldInput['discord_user_id'],
        'discord_username' => $oldInput['discord_username'],
        'contact' => $oldInput['contact'],
        'appeal_reason' => $oldInput['appeal_reason'],
        'additional_information' => $combinedDetails,
        'attachment_path' => $attachmentPath,
        'status' => 'pending',
    ]);

    $appealId = (int) $pdo->lastInsertId();
    $appealReference = bg_create_appeal_reference($appealId);

    $pdo->prepare(
        'UPDATE discord_ban_appeals
         SET public_reference = :public_reference
         WHERE id = :id'
    )->execute([
        'public_reference' => $appealReference,
        'id' => $appealId,
    ]);

    if ($banRecord !== null) {
        $pdo->prepare(
            "UPDATE discord_bans
             SET appeal_status = 'pending',
                 updated_at = CURRENT_TIMESTAMP
             WHERE id = :id"
        )->execute([
            'id' => (int) $banRecord['id'],
        ]);
    }

    $pdo->commit();

    try {
        $adminReviewBase = (string) bg_config('security.admin_review_url', '');
        $adminReviewUrl = $adminReviewBase !== ''
            ? bg_append_query($adminReviewBase, ['appeal' => $appealReference])
            : '';

        $webhookFields = [
            [
                'name' => 'Appeal reference',
                'value' => $appealReference,
                'inline' => true,
            ],
            [
                'name' => 'Discord user',
                'value' => $oldInput['discord_username'],
                'inline' => true,
            ],
            [
                'name' => 'Masked ID',
                'value' => bg_mask_discord_id($oldInput['discord_user_id']),
                'inline' => true,
            ],
            [
                'name' => 'Ban reference',
                'value' => $banReference !== '' ? $banReference : 'General appeal',
                'inline' => true,
            ],
            [
                'name' => 'Public reason',
                'value' => $banRecord !== null
                    ? sanitizePublicReason((string) ($banRecord['public_reason'] ?? ''))
                    : 'Не е посочен конкретен бан запис',
                'inline' => false,
            ],
            [
                'name' => 'Appeal excerpt',
                'value' => bg_excerpt_text($combinedDetails, (int) bg_config('limits.appeal_max_excerpt_length', 260)) ?: 'Няма детайли',
                'inline' => false,
            ],
        ];

        if ($adminReviewUrl !== '') {
            $webhookFields[] = [
                'name' => 'Admin review',
                'value' => $adminReviewUrl,
                'inline' => false,
            ];
        }

        $modPanelUrl = bg_append_query(bg_public_url('mod/'), [
            'appeal' => $appealReference,
        ]);
        $unbanUrl = bg_build_signed_moderation_url($appealReference, 'unban');
        $rejectUrl = bg_build_signed_moderation_url($appealReference, 'reject');

        bg_send_discord_channel_message([
            'content' => sprintf(
                'Moderation review required for `%s` from `%s`.',
                $appealReference,
                $oldInput['discord_username']
            ),
            'allowed_mentions' => [
                'parse' => [],
            ],
            'embeds' => [[
                'title' => 'New ban appeal submitted',
                'color' => 9278719,
                'fields' => [
                    [
                        'name' => 'Appeal reference',
                        'value' => $appealReference,
                        'inline' => true,
                    ],
                    [
                        'name' => 'Discord user',
                        'value' => $oldInput['discord_username'],
                        'inline' => true,
                    ],
                    [
                        'name' => 'Discord user ID',
                        'value' => $oldInput['discord_user_id'],
                        'inline' => true,
                    ],
                    [
                        'name' => 'Contact',
                        'value' => bg_excerpt_text($oldInput['contact'], 180) ?: 'No contact provided',
                        'inline' => true,
                    ],
                    [
                        'name' => 'Ban reference',
                        'value' => $banReference !== '' ? $banReference : 'General appeal',
                        'inline' => true,
                    ],
                    [
                        'name' => 'Attachment',
                        'value' => $attachmentPath !== null ? 'Stored server-side for moderator review.' : 'No attachment uploaded.',
                        'inline' => true,
                    ],
                    [
                        'name' => 'Appeal status',
                        'value' => 'Pending moderator review',
                        'inline' => true,
                    ],
                    [
                        'name' => 'Appeal reason',
                        'value' => bg_excerpt_text($oldInput['appeal_reason'], 240) ?: 'No reason provided',
                        'inline' => false,
                    ],
                    [
                        'name' => 'Detailed explanation',
                        'value' => bg_excerpt_text($oldInput['detailed_explanation'], 900) ?: 'No detailed explanation provided',
                        'inline' => false,
                    ],
                    [
                        'name' => 'Requested removal',
                        'value' => bg_excerpt_text($oldInput['removal_reason'], 900) ?: 'No removal rationale provided',
                        'inline' => false,
                    ],
                    [
                        'name' => 'Public reason',
                        'value' => $banRecord !== null
                            ? sanitizePublicReason((string) ($banRecord['public_reason'] ?? ''))
                            : 'Не е посочен конкретен бан запис',
                        'inline' => false,
                    ],
                ],
                'footer' => [
                    'text' => 'Use the buttons below for the signed moderator flows.',
                ],
                'timestamp' => gmdate('c'),
            ]],
            'components' => [[
                'type' => 1,
                'components' => [
                    [
                        'type' => 2,
                        'style' => 5,
                        'label' => 'Unban',
                        'url' => $unbanUrl,
                    ],
                    [
                        'type' => 2,
                        'style' => 5,
                        'label' => 'Reject',
                        'url' => $rejectUrl,
                    ],
                    [
                        'type' => 2,
                        'style' => 5,
                        'label' => 'Mod Panel',
                        'url' => $modPanelUrl,
                    ],
                ],
            ]],
        ]);

        bg_send_discord_webhook([
            'username' => 'BG-GAMER Appeal Intake',
            'embeds' => [[
                'title' => 'New ban appeal submitted',
                'color' => 9278719,
                'fields' => $webhookFields,
                'timestamp' => gmdate('c'),
            ]],
        ]);
    } catch (Throwable $throwable) {
        bg_log_event('appeal-webhook-errors', [
            'message' => $throwable->getMessage(),
            'file' => basename($throwable->getFile()),
            'line' => $throwable->getLine(),
            'appeal_reference' => $appealReference,
        ]);
    }

    bg_flash_set('appeal_success', [
        'reference' => $appealReference,
    ]);

    bg_redirect(bg_append_query(bg_public_url('appeal/success.php'), [
        'ref' => $appealReference,
    ]));
} catch (Throwable $throwable) {
    bg_log_event('appeal-submit-errors', [
        'message' => $throwable->getMessage(),
        'file' => basename($throwable->getFile()),
        'line' => $throwable->getLine(),
    ]);

    bg_flash_set('appeal_errors', [
        'form' => $throwable instanceof RuntimeException
            ? $throwable->getMessage()
            : 'Неуспешно изпращане. Опитайте отново след малко.',
    ]);
    bg_flash_set('appeal_old_input', $oldInput);
    bg_redirect($redirectUrl);
}

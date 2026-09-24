<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/config/ban-center.php';

$rulesUrl = (string) bg_config('app.rules_url', bg_public_url());
$inviteUrl = bg_discord_invite_url();
$turnstileSiteKey = (string) bg_config('security.turnstile_site_key', '');
$turnstileBypass = (bool) bg_config('security.turnstile_bypass', false);
$turnstileEnabled = $turnstileSiteKey !== '';
$securityLabel = $turnstileEnabled
    ? 'CSRF, Turnstile, rate limits'
    : 'CSRF, request checks, rate limits';

bg_send_page_security_headers($turnstileEnabled);

$banReference = trim((string) ($_GET['ban'] ?? ''));
$banRecord = null;
$referenceWarning = '';

if ($banReference !== '') {
    try {
        $banRecord = bg_fetch_ban_record_by_reference($banReference);

        if ($banRecord === null) {
            $referenceWarning = 'Посоченият ban reference не беше намерен. Можете да изпратите общо обжалване.';
        }
    } catch (Throwable) {
        $referenceWarning = 'Ban reference не можа да бъде зареден в този момент.';
    }
}

$errors = bg_flash_pull('appeal_errors', []);
$oldInput = bg_flash_pull('appeal_old_input', []);
$csrfToken = bg_csrf_token('appeal_form');
$caseReferenceLabel = $banRecord !== null
    ? (string) $banRecord['public_reference']
    : ($banReference !== '' ? $banReference : 'Общо обжалване');

$fieldValue = static function (string $key, string $default = '') use ($oldInput): string {
    return (string) ($oldInput[$key] ?? $default);
};
?>
<!DOCTYPE html>
<html lang="bg">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>BG-GAMER | Ban Appeal</title>
  <meta
    name="description"
    content="Подайте обжалване за Discord наказание в BG-GAMER чрез защитен формуляр с public ban reference."
  >
  <link rel="canonical" href="<?php echo bg_escape(bg_public_url('appeal/')); ?>">
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
  <link rel="stylesheet" href="assets/appeal.css?v=20260714-4">
</head>
<body
  class="appeal-page"
  data-nav-page="appeal"
  data-site-base=".."
  data-invite-url="<?php echo bg_escape($inviteUrl); ?>"
  data-rules-url="<?php echo bg_escape($rulesUrl); ?>"
  data-nav-cta="invite"
>
  <div class="page-noise" aria-hidden="true"></div>

  <header class="topbar" id="top">
    <nav class="navbar navbar-expand-lg" data-site-nav>
      <div class="container">
        <a class="navbar-brand brand" href="../" aria-label="BG-GAMER Discord lobby">
          <span class="brand__mark">
            <img src="../assets/bg-gamer-logo.png" alt="BG-GAMER logo" class="brand__logo">
          </span>
          <span class="brand__text">
            <strong>BG-GAMER</strong>
            <small>Appeal desk</small>
          </span>
        </a>

        <button
          class="navbar-toggler border-0 shadow-none"
          type="button"
          data-bs-toggle="collapse"
          data-bs-target="#appealNav"
          aria-controls="appealNav"
          aria-expanded="false"
          aria-label="Превключи навигацията"
        >
          <span class="navbar-toggler-icon"></span>
        </button>

        <div class="collapse navbar-collapse justify-content-end" id="appealNav">
          <ul class="navbar-nav navbar-nav--site align-items-lg-center mb-3 mb-lg-0" data-site-nav-list>
            <li class="nav-item"><a class="nav-link" href="../">Lobby</a></li>
            <li class="nav-item"><a class="nav-link" href="../#activity">Activity</a></li>
            <li class="nav-item"><a class="nav-link" href="../#bots">Bots</a></li>
            <li class="nav-item"><a class="nav-link" href="../bans/">Bans</a></li>
            <li class="nav-item"><a class="nav-link" href="./" aria-current="page">Appeal</a></li>
            <li class="nav-item"><a class="nav-link" href="<?php echo bg_escape($rulesUrl); ?>">Rules</a></li>
            <li class="nav-item"><a class="nav-link" href="../mod/">Mod Panel</a></li>
          </ul>
          <div class="site-nav__actions" data-site-nav-actions>
            <a class="btn btn-brand navbar-cta ms-lg-4" href="<?php echo bg_escape($inviteUrl); ?>">Open Invite</a>
          </div>
        </div>
      </div>
    </nav>
  </header>

  <main class="appeal-shell">
    <section class="appeal-hero section-space">
      <div class="container">
        <div class="appeal-hero__grid">
          <div class="appeal-hero__copy" data-reveal>
            <span class="section-kicker">Secure review intake</span>
            <h1>Обжалване на Discord бан</h1>
            <p class="appeal-hero__lead">
              Попълнете формата с коректните Discord данни и ясна аргументация. Заявката се записва в отделен moderation workflow и се изпраща към частния review канал.
            </p>

<?php if ($referenceWarning !== ''): ?>
            <div class="appeal-alert appeal-alert--warning"><?php echo bg_escape($referenceWarning); ?></div>
<?php endif; ?>

<?php if (isset($errors['form'])): ?>
            <div class="appeal-alert appeal-alert--error"><?php echo bg_escape((string) $errors['form']); ?></div>
<?php endif; ?>

            <div class="appeal-hero__notes">
              <div>
                <span>Ban reference</span>
                <strong><?php echo bg_escape($caseReferenceLabel); ?></strong>
              </div>
              <div>
                <span>Review channel</span>
                <strong>Private moderation feed</strong>
              </div>
              <div>
                <span>Защита</span>
                <strong><?php echo bg_escape($securityLabel); ?></strong>
              </div>
            </div>
          </div>

          <aside class="appeal-hero__panel" data-reveal>
            <span class="eyebrow">Referenced case</span>
<?php if ($banRecord !== null): ?>
            <h2><?php echo bg_escape((string) ($banRecord['username'] ?? 'Unknown user')); ?></h2>
            <dl class="appeal-case">
              <div>
                <dt>Public reference</dt>
                <dd><?php echo bg_escape((string) $banRecord['public_reference']); ?></dd>
              </div>
              <div>
                <dt>Публична причина</dt>
                <dd><?php echo bg_escape(sanitizePublicReason((string) ($banRecord['public_reason'] ?? ''))); ?></dd>
              </div>
              <div>
                <dt>Дата</dt>
                <dd><?php echo bg_escape(bg_format_public_datetime($banRecord['banned_at'] ?? null)); ?></dd>
              </div>
              <div>
                <dt>Статус</dt>
                <dd><?php echo bg_escape(bg_status_label((string) ($banRecord['status'] ?? 'active'))); ?></dd>
              </div>
            </dl>
<?php else: ?>
            <h2>General appeal intake</h2>
            <p>
              Ако не отваряте формата от конкретен ban запис, заявката ще бъде записана като общо обжалване и ще трябва ръчно да въведете коректните Discord данни.
            </p>
<?php endif; ?>

<?php if ($turnstileSiteKey === '' && !$turnstileBypass): ?>
            <div class="appeal-alert appeal-alert--warning">
              Turnstile site key не е конфигуриран в тази среда. Формата няма да валидира успешно, докато не бъде добавен.
            </div>
<?php endif; ?>
          </aside>
        </div>
      </div>
    </section>

    <section class="appeal-form-section section-space">
      <div class="container">
        <form
          class="appeal-form"
          action="api/submit-appeal.php"
          method="post"
          enctype="multipart/form-data"
          id="appealForm"
          data-reveal
        >
          <input type="hidden" name="csrf_token" value="<?php echo bg_escape($csrfToken); ?>">
          <input type="hidden" name="website" value="">

          <div class="appeal-form__head">
            <div>
              <span class="section-kicker">Appeal form</span>
              <h2>Подайте review заявката с точните данни.</h2>
            </div>
            <p>Ban reference се проверява server-side и не разчита на клиентския state.</p>
          </div>

          <div class="appeal-form__grid">
            <label class="appeal-field<?php echo isset($errors['discord_username']) ? ' has-error' : ''; ?>">
              <span>Discord потребителско име</span>
              <input
                type="text"
                name="discord_username"
                maxlength="80"
                value="<?php echo bg_escape($fieldValue('discord_username', (string) ($banRecord['username'] ?? ''))); ?>"
                required
              >
              <?php if (isset($errors['discord_username'])): ?><small><?php echo bg_escape((string) $errors['discord_username']); ?></small><?php endif; ?>
            </label>

            <label class="appeal-field<?php echo isset($errors['discord_user_id']) ? ' has-error' : ''; ?>">
              <span>Discord User ID</span>
              <input
                type="text"
                name="discord_user_id"
                inputmode="numeric"
                maxlength="21"
                value="<?php echo bg_escape($fieldValue('discord_user_id')); ?>"
                required
              >
              <?php if (isset($errors['discord_user_id'])): ?><small><?php echo bg_escape((string) $errors['discord_user_id']); ?></small><?php endif; ?>
            </label>

            <label class="appeal-field<?php echo isset($errors['ban_reference']) ? ' has-error' : ''; ?>">
              <span>Ban public reference</span>
              <input
                type="text"
                name="ban_reference"
                value="<?php echo bg_escape($fieldValue('ban_reference', (string) ($banRecord['public_reference'] ?? $banReference))); ?>"
<?php if ($banRecord !== null): ?>
                readonly
<?php else: ?>
                placeholder="По избор: BG-BAN reference"
<?php endif; ?>
              >
              <?php if (isset($errors['ban_reference'])): ?><small><?php echo bg_escape((string) $errors['ban_reference']); ?></small><?php endif; ?>
            </label>

            <label class="appeal-field<?php echo isset($errors['contact']) ? ' has-error' : ''; ?>">
              <span>Контакт за обратна връзка</span>
              <input
                type="text"
                name="contact"
                maxlength="190"
                placeholder="Email, Discord tag или друг удобен контакт"
                value="<?php echo bg_escape($fieldValue('contact')); ?>"
                required
              >
              <?php if (isset($errors['contact'])): ?><small><?php echo bg_escape((string) $errors['contact']); ?></small><?php endif; ?>
            </label>
          </div>

          <label class="appeal-field appeal-field--full<?php echo isset($errors['appeal_reason']) ? ' has-error' : ''; ?>">
            <span>Причина за обжалването</span>
            <input
              type="text"
              name="appeal_reason"
              maxlength="190"
              placeholder="Неправилен бан, изтекъл казус, липса на контекст и т.н."
              value="<?php echo bg_escape($fieldValue('appeal_reason')); ?>"
              required
            >
            <?php if (isset($errors['appeal_reason'])): ?><small><?php echo bg_escape((string) $errors['appeal_reason']); ?></small><?php endif; ?>
          </label>

          <div class="appeal-form__stack">
            <label class="appeal-field appeal-field--full<?php echo isset($errors['detailed_explanation']) ? ' has-error' : ''; ?>">
              <span>Подробно обяснение</span>
              <textarea
                name="detailed_explanation"
                rows="6"
                maxlength="2400"
                data-max-length="2400"
                required
              ><?php echo bg_escape($fieldValue('detailed_explanation')); ?></textarea>
              <div class="appeal-field__meta">
                <small><?php echo isset($errors['detailed_explanation']) ? bg_escape((string) $errors['detailed_explanation']) : 'Опишете контекста, тайминга и какво е пропуснато.'; ?></small>
                <span class="appeal-counter">0 / 2400</span>
              </div>
            </label>

            <label class="appeal-field appeal-field--full<?php echo isset($errors['removal_reason']) ? ' has-error' : ''; ?>">
              <span>Защо банът трябва да бъде премахнат</span>
              <textarea
                name="removal_reason"
                rows="5"
                maxlength="1800"
                data-max-length="1800"
                required
              ><?php echo bg_escape($fieldValue('removal_reason')); ?></textarea>
              <div class="appeal-field__meta">
                <small><?php echo isset($errors['removal_reason']) ? bg_escape((string) $errors['removal_reason']) : 'Опишете защо смятате, че решението трябва да бъде преразгледано.'; ?></small>
                <span class="appeal-counter">0 / 1800</span>
              </div>
            </label>
          </div>

          <div class="appeal-upload">
            <label class="appeal-field appeal-field--full">
              <span>Прикачен файл (по избор)</span>
              <input type="file" name="attachment" id="appealAttachment" accept=".jpg,.jpeg,.png,.webp,.pdf,.txt">
              <div class="appeal-field__meta">
                <small>Разрешени формати: JPG, PNG, WEBP, PDF, TXT. Максимум 5 MB.</small>
                <span id="appealAttachmentName">Няма избран файл</span>
              </div>
            </label>
          </div>

          <label class="appeal-check<?php echo isset($errors['rules_confirmed']) ? ' has-error' : ''; ?>">
            <input type="checkbox" name="rules_confirmed" value="1"<?php echo $fieldValue('rules_confirmed') === '1' ? ' checked' : ''; ?>>
            <span>Потвърждавам, че съм прочел <a href="<?php echo bg_escape($rulesUrl); ?>">правилата на сървъра</a> и подавам вярна информация.</span>
          </label>
          <?php if (isset($errors['rules_confirmed'])): ?><p class="appeal-check__error"><?php echo bg_escape((string) $errors['rules_confirmed']); ?></p><?php endif; ?>

          <div class="appeal-form__footer">
<?php if ($turnstileSiteKey !== '' || $turnstileBypass): ?>
<?php if ($turnstileSiteKey !== ''): ?>
            <div
              class="cf-turnstile"
              data-sitekey="<?php echo bg_escape($turnstileSiteKey); ?>"
              data-theme="dark"
            ></div>
<?php endif; ?>
<?php endif; ?>
            <button type="submit" class="btn btn-brand btn-lg" id="appealSubmitButton">Изпрати обжалването</button>
          </div>
        </form>
      </div>
    </section>
  </main>

  <footer class="appeal-footer">
    <div class="container">
      <p>BG-GAMER appeal intake пази tokens и moderation webhook-а изцяло server-side.</p>
      <a href="../bans/">Върни се към ban списъка</a>
    </div>
  </footer>

  <script
    src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/js/bootstrap.bundle.min.js"
    integrity="sha384-FKyoEForCGlyvwx9Hj09JcYn3nv7wiPVlz7YYwJrWVcXK/BmnVDxM+D2scQbITxI"
    crossorigin="anonymous"
  ></script>
  <script src="../assets/site-nav.js?v=20260714-4"></script>
<?php if ($turnstileEnabled): ?>
  <script src="https://challenges.cloudflare.com/turnstile/v0/api.js" async defer></script>
<?php endif; ?>
  <script src="assets/appeal.js?v=20260714-3"></script>
</body>
</html>

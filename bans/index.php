<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/config/ban-center.php';

bg_send_page_security_headers(false);

$pageTitle = 'BG-GAMER Ban Center';
$pageDescription = 'Публичен списък на активните наказания в Discord сървъра на BG-GAMER с възможност за преглед и обжалване.';
$canonicalUrl = bg_public_url('bans/');
$robotsDirective = trim((string) bg_config('app.bans_robots', ''));
$rulesUrl = (string) bg_config('app.rules_url', bg_public_url());
$inviteUrl = bg_discord_invite_url();
$initialPage = max(1, (int) ($_GET['page'] ?? 1));
$initialQuery = trim((string) ($_GET['q'] ?? ''));
$initialStatus = strtolower(trim((string) ($_GET['status'] ?? 'active')));
$initialDateFrom = bg_validate_date_filter($_GET['date_from'] ?? null) ?? '';
$initialDateTo = bg_validate_date_filter($_GET['date_to'] ?? null) ?? '';
?>
<!DOCTYPE html>
<html lang="bg">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?php echo bg_escape($pageTitle); ?></title>
  <meta name="description" content="<?php echo bg_escape($pageDescription); ?>">
  <link rel="canonical" href="<?php echo bg_escape($canonicalUrl); ?>">
  <meta property="og:title" content="<?php echo bg_escape($pageTitle); ?>">
  <meta property="og:description" content="<?php echo bg_escape($pageDescription); ?>">
  <meta property="og:type" content="website">
  <meta property="og:url" content="<?php echo bg_escape($canonicalUrl); ?>">
  <meta property="og:image" content="<?php echo bg_escape(bg_public_url('assets/bg-gamer-logo.png')); ?>">
<?php if ($robotsDirective !== ''): ?>
  <meta name="robots" content="<?php echo bg_escape($robotsDirective); ?>">
<?php endif; ?>
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
  <link rel="stylesheet" href="assets/bans-ui-v6.css?v=20260714-1">
</head>
<body
  class="ban-center-page"
  data-nav-page="bans"
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
            <small>Public ban board</small>
          </span>
        </a>

        <button
          class="navbar-toggler border-0 shadow-none"
          type="button"
          data-bs-toggle="collapse"
          data-bs-target="#banCenterNav"
          aria-controls="banCenterNav"
          aria-expanded="false"
          aria-label="Превключи навигацията"
        >
          <span class="navbar-toggler-icon"></span>
        </button>

        <div class="collapse navbar-collapse justify-content-end" id="banCenterNav">
          <ul class="navbar-nav navbar-nav--site align-items-lg-center mb-3 mb-lg-0" data-site-nav-list>
            <li class="nav-item"><a class="nav-link" href="../">Lobby</a></li>
            <li class="nav-item"><a class="nav-link" href="../#activity">Activity</a></li>
            <li class="nav-item"><a class="nav-link" href="../#bots">Bots</a></li>
            <li class="nav-item"><a class="nav-link" href="./" aria-current="page">Bans</a></li>
            <li class="nav-item"><a class="nav-link" href="../appeal/">Appeal</a></li>
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

  <main
    id="banCenterApp"
    data-endpoint="<?php echo bg_escape(bg_public_url('bans/api/bans.php')); ?>"
    data-initial-page="<?php echo bg_escape((string) $initialPage); ?>"
    data-initial-query="<?php echo bg_escape($initialQuery); ?>"
    data-initial-status="<?php echo bg_escape($initialStatus); ?>"
    data-initial-date-from="<?php echo bg_escape($initialDateFrom); ?>"
    data-initial-date-to="<?php echo bg_escape($initialDateTo); ?>"
  >
    <section class="ban-hero section-space">
      <div class="container">
        <div class="ban-hero__grid">
          <div class="ban-hero__copy" data-reveal>
            <span class="section-kicker">Public moderation ledger</span>
            <h1>Списък с банове</h1>
            <p class="ban-hero__lead">
              Публичен списък на активните наказания в Discord сървъра BG-GAMER. Всеки потребител има право да подаде заявление за преразглеждане.
            </p>

            <div class="ban-hero__actions">
              <a class="btn btn-brand btn-lg" href="../appeal/">Общо обжалване</a>
              <a class="btn btn-ghost btn-lg" href="<?php echo bg_escape($rulesUrl); ?>">Правила на сървъра</a>
            </div>

            <div class="ban-signal-strip" aria-live="polite">
              <div class="ban-signal-strip__item">
                <span>Активни наказания</span>
                <strong id="heroActiveCount">--</strong>
              </div>
              <div class="ban-signal-strip__item">
                <span>Последен sync</span>
                <strong id="heroSyncTime">Изчакване</strong>
              </div>
              <div class="ban-signal-strip__item">
                <span>Публични данни</span>
                <strong>Без ID, IP и mod notes</strong>
              </div>
            </div>
          </div>

          <aside class="ban-hero__panel" data-reveal aria-label="Ban Center status">
            <div class="ban-hero__panel-head">
              <span class="eyebrow">BG-GAMER Ban Center</span>
              <span class="ban-status-dot" id="banApiState">Loading</span>
            </div>
            <div class="ban-hero__metric">
              <span class="ban-hero__metric-label">Активни банове</span>
              <strong id="panelActiveCount">--</strong>
            </div>
            <p class="ban-hero__panel-copy">
              Данните се взимат от Kremmuna-ready intake endpoint и protected fallback sync, като се показват само публично безопасни полета.
            </p>
            <ul class="ban-hero__panel-list">
              <li>Kremmuna webhook-ът може да обновява бан записите в реално време.</li>
              <li>Показва се само public reason с автоматична редакция на чувствителни данни.</li>
              <li>Всеки запис води към отделен appeal flow чрез public reference.</li>
              <li>Pagination, търсене и статус филтри работят без презареждане на страницата.</li>
            </ul>
          </aside>
        </div>

        <form class="ban-toolbar" id="banFilters" novalidate>
          <label class="ban-field ban-field--search">
            <span>Търсене</span>
            <input
              type="search"
              name="q"
              id="banSearch"
              placeholder="Потребител, причина или BG-BAN reference"
              value="<?php echo bg_escape($initialQuery); ?>"
              autocomplete="off"
            >
          </label>

          <div class="ban-field ban-field--status">
            <span>Статус</span>
            <select name="status" id="banStatus">
              <option value="active"<?php echo $initialStatus === 'active' ? ' selected' : ''; ?>>Активни наказания</option>
              <option value="permanent"<?php echo $initialStatus === 'permanent' ? ' selected' : ''; ?>>Постоянни банове</option>
              <option value="temporary"<?php echo $initialStatus === 'temporary' ? ' selected' : ''; ?>>Временни банове</option>
              <option value="unbanned"<?php echo $initialStatus === 'unbanned' ? ' selected' : ''; ?>>Премахнати</option>
              <option value="expired"<?php echo $initialStatus === 'expired' ? ' selected' : ''; ?>>Изтекли</option>
              <option value="all"<?php echo $initialStatus === 'all' ? ' selected' : ''; ?>>Всички</option>
            </select>
          </div>

          <label class="ban-field">
            <span>От дата</span>
            <input type="date" name="date_from" id="banDateFrom" value="<?php echo bg_escape($initialDateFrom); ?>">
          </label>

          <label class="ban-field">
            <span>До дата</span>
            <input type="date" name="date_to" id="banDateTo" value="<?php echo bg_escape($initialDateTo); ?>">
          </label>

          <div class="ban-toolbar__actions">
            <button type="submit" class="btn btn-brand">Приложи</button>
            <button type="button" class="btn btn-ghost" id="banResetFilters">Изчисти</button>
          </div>
        </form>
      </div>
    </section>

    <section class="ban-board section-space" id="ban-board">
      <div class="container">
        <div class="ban-board__head" data-reveal>
          <div>
            <span class="section-kicker">Public active punishments</span>
            <h2>Текущи записи за преглед и обжалване.</h2>
          </div>
          <p id="banResultsSummary">Зареждане на ban списъка.</p>
        </div>

        <div class="ban-board__viewport" data-reveal>
          <div class="ban-list" id="banCards" aria-live="polite" aria-busy="true"></div>
          <div class="ban-state" id="banState"></div>
        </div>

        <div class="ban-pagination" data-reveal>
          <button type="button" class="ban-pagination__arrow" id="banPrevPage" aria-label="Предишна страница">
            Предишна
          </button>
          <div class="ban-pagination__pages" id="banPageButtons" aria-label="Страници"></div>
          <button type="button" class="ban-pagination__arrow" id="banNextPage" aria-label="Следваща страница">
            Следваща
          </button>
          <p class="ban-pagination__status" id="banPageStatus">Страница 1 от 1</p>
        </div>

        <noscript>
          <p class="ban-noscript">
            За да видите публичния ban списък и актуалните филтри, е необходимо JavaScript да е активен.
          </p>
        </noscript>
      </div>
    </section>
  </main>

  <footer class="ban-footer">
    <div class="container">
      <p>BG-GAMER Ban Center използва public-safe данни и отделен appeal workflow за всеки запис.</p>
      <a href="../appeal/">Отвори appeal страницата</a>
    </div>
  </footer>

  <script
    src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/js/bootstrap.bundle.min.js"
    integrity="sha384-FKyoEForCGlyvwx9Hj09JcYn3nv7wiPVlz7YYwJrWVcXK/BmnVDxM+D2scQbITxI"
    crossorigin="anonymous"
  ></script>
  <script src="../assets/site-nav.js?v=20260714-4"></script>
  <script src="assets/bans.js?v=20260714-7"></script>
</body>
</html>

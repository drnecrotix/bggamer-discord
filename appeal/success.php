<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/config/ban-center.php';

bg_send_page_security_headers(false);

$rulesUrl = (string) bg_config('app.rules_url', bg_public_url());
$inviteUrl = bg_discord_invite_url();
$flashSuccess = bg_flash_pull('appeal_success', []);
$reference = trim((string) ($_GET['ref'] ?? ($flashSuccess['reference'] ?? '')));

if ($reference === '') {
    bg_redirect(bg_public_url('appeal/'));
}
?>
<!DOCTYPE html>
<html lang="bg">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>BG-GAMER | Appeal submitted</title>
  <meta name="description" content="Ban appeal заявката е изпратена успешно към BG-GAMER moderation review flow.">
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
  class="appeal-page appeal-page--success"
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
          data-bs-target="#appealSuccessNav"
          aria-controls="appealSuccessNav"
          aria-expanded="false"
          aria-label="Превключи навигацията"
        >
          <span class="navbar-toggler-icon"></span>
        </button>

        <div class="collapse navbar-collapse justify-content-end" id="appealSuccessNav">
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

  <main class="appeal-success-shell">
    <section class="appeal-success-card">
      <span class="section-kicker">Appeal submitted</span>
      <h1>Заявката е приета.</h1>
      <p>
        Appeal номерът е <strong><?php echo bg_escape($reference); ?></strong>. Той е изпратен към private moderation review канала заедно с public ban reference и кратък откъс от заявката.
      </p>
      <div class="appeal-success-card__actions">
        <a class="btn btn-brand btn-lg" href="../bans/">Към ban списъка</a>
        <a class="btn btn-ghost btn-lg" href="../">Към Discord lobby</a>
      </div>
    </section>
  </main>

  <script
    src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/js/bootstrap.bundle.min.js"
    integrity="sha384-FKyoEForCGlyvwx9Hj09JcYn3nv7wiPVlz7YYwJrWVcXK/BmnVDxM+D2scQbITxI"
    crossorigin="anonymous"
  ></script>
  <script src="../assets/site-nav.js?v=20260714-4"></script>
</body>
</html>

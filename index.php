<?php

declare(strict_types=1);

// FTP compatibility entry point. Prefer pointing the document root directly
// to portal/public when the hosting panel allows it.
$portal = __DIR__.'/portal';
if (! is_file($portal.'/.env')) {
    header('Location: '.rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? '/index.php'), '/').'/install.php', true, 302);
    exit;
}
if (! is_file($portal.'/vendor/autoload.php')) {
    http_response_code(503);
    exit('Portal dependencies are missing. Upload the Composer vendor directory.');
}

require $portal.'/public/index.php';

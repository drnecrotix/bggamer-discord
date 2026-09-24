<?php

declare(strict_types=1);

// Standalone setup runs before Laravel has an APP_KEY or database-backed sessions.
$root = dirname(__DIR__);
$lock = $root.'/storage/app/installed.lock';
$keyFile = $root.'/storage/app/install.key';
$envFile = $root.'/.env';
require_once $root.'/installer-cleanup.php';
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
header('X-Robots-Tag: noindex, nofollow');

if (is_file($lock) || is_file($envFile)) {
    http_response_code(404);
    exit('Installer unavailable.');
}
if (! is_file($root.'/vendor/autoload.php') || is_file($root.'/bootstrap/cache/config.php')) {
    http_response_code(503);
    exit('Upload Composer vendor/ and remove cached config before installation.');
}
$expected = is_file($keyFile) ? trim((string) file_get_contents($keyFile)) : trim((string) getenv('BG_PORTAL_INSTALL_KEY'));
if (strlen($expected) < 32) {
    http_response_code(503);
    exit('Create a private storage/app/install.key with a random 32+ character setup code before opening this page.');
}
if (($_SERVER['HTTPS'] ?? '') !== 'on' && ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') !== 'https') {
    http_response_code(403);
    exit('HTTPS is required.');
}

session_name('bg_portal_install');
session_set_cookie_params(['secure' => true, 'httponly' => true, 'samesite' => 'Strict']);
session_start();
$_SESSION['csrf'] ??= bin2hex(random_bytes(32));
$error = null;
$success = false;
$installed = false;
$cleanupError = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (! hash_equals($_SESSION['csrf'], (string) ($_POST['csrf'] ?? ''))
            || ! hash_equals($expected, (string) ($_POST['install_key'] ?? ''))) {
            throw new RuntimeException('Invalid setup code or session.');
        }
        // Removing a file requires write access to its parent directory, not just to the file.
        // Check before creating .env or changing the database.
        if (! bgPortalInstallerCanSelfDelete(__FILE__)) {
            throw new RuntimeException('PHP cannot remove public/install.php. Make the public directory writable by PHP before installing.');
        }
        $email = strtolower(trim((string) ($_POST['owner_email'] ?? '')));
        $password = (string) ($_POST['owner_password'] ?? '');
        $url = trim((string) ($_POST['app_url'] ?? ''));
        $driver = (string) ($_POST['db_connection'] ?? '');
        $host = trim((string) ($_POST['db_host'] ?? ''));
        $port = trim((string) ($_POST['db_port'] ?? ''));
        $database = trim((string) ($_POST['db_database'] ?? ''));
        $username = trim((string) ($_POST['db_username'] ?? ''));
        $dbPassword = (string) ($_POST['db_password'] ?? '');
        if (! filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($password) < 14 || strlen($password) > 256
            || ! in_array($driver, ['mysql', 'pgsql'], true) || ! filter_var($url, FILTER_VALIDATE_URL)
            || parse_url($url, PHP_URL_SCHEME) !== 'https'
            || ! preg_match('/^[a-zA-Z0-9.-]{1,253}$/', $host)
            || ! ctype_digit($port) || (int) $port < 1 || (int) $port > 65535
            || ! preg_match('/^[a-zA-Z0-9_-]{1,64}$/', $database) || $username === '') {
            throw new RuntimeException('Check the URL, database settings and Owner email/password (14+ characters).');
        }
        foreach ([$email, $url, $database, $username, $dbPassword] as $value) {
            if (preg_match('/[\r\n\x00]/', $value)) {
                throw new RuntimeException('Invalid character in a setting.');
            }
        }
        $dsn = $driver === 'mysql'
            ? "mysql:host=$host;port=$port;dbname=$database;charset=utf8mb4"
            : "pgsql:host=$host;port=$port;dbname=$database";
        new PDO($dsn, $username, $dbPassword, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $template = file_get_contents($root.'/.env.example');
        if ($template === false) {
            throw new RuntimeException('Missing .env.example.');
        }
        $values = [
            'APP_NAME' => 'BG-GAMER Portal', 'APP_ENV' => 'production',
            'APP_KEY' => 'base64:'.base64_encode(random_bytes(32)), 'APP_DEBUG' => 'false',
            'APP_URL' => rtrim($url, '/'), 'DB_CONNECTION' => $driver,
            'DB_HOST' => $host, 'DB_PORT' => $port, 'DB_DATABASE' => $database,
            'DB_USERNAME' => $username, 'DB_PASSWORD' => $dbPassword,
            'SESSION_DRIVER' => 'file', 'CACHE_STORE' => 'file', 'QUEUE_CONNECTION' => 'sync',
            'SESSION_SECURE_COOKIE' => 'true',
        ];
        foreach ($values as $name => $value) {
            $escaped = str_replace(['\\', '"', '$'], ['\\\\', '\\"', '\\$'], $value);
            $line = $name.'="'.$escaped.'"';
            if (preg_match('/^'.preg_quote($name, '/').'=.*/m', $template)) {
                $template = preg_replace_callback('/^'.preg_quote($name, '/').'=.*/m', static fn () => $line, $template, 1);
            } else {
                $template .= "\n".$line;
            }
        }
        $handle = fopen($envFile, 'x');
        if (! $handle) {
            throw new RuntimeException('Cannot create .env. Check folder permissions.');
        }
        chmod($envFile, 0600);
        fwrite($handle, $template);
        fclose($handle);
        require $root.'/vendor/autoload.php';
        $app = require $root.'/bootstrap/app.php';
        $kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
        $kernel->bootstrap();
        Illuminate\Support\Facades\Artisan::call('migrate', ['--force' => true]);
        Illuminate\Support\Facades\DB::table('portal_owners')->insert([
            'email' => $email,
            'password' => Illuminate\Support\Facades\Hash::make($password),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        if (file_put_contents($lock, date(DATE_ATOM), LOCK_EX) === false) {
            throw new RuntimeException('Installation completed, but the lock file could not be created. Check storage permissions.');
        }
        chmod($lock, 0600);
        $installed = true;
        if (is_file($keyFile)) { @unlink($keyFile); }
        $success = bgPortalDeleteInstaller(__FILE__);
        if (! $success) {
            $cleanupError = 'Portal installed, but PHP could not remove public/install.php. Remove it via FTP now. The lock and .env block further installation.';
            error_log('BG-GAMER portal installer self-delete failed.');
        }
    } catch (Throwable $exception) {
        error_log('BG-GAMER portal setup failed: '.get_class($exception));
        $error = $exception instanceof RuntimeException ? $exception->getMessage() : 'Installation failed. Check server logs and database permissions.';
    }
}
function bgPortalInstallBase(): string {
    return rtrim(dirname((string) parse_url($_SERVER['REQUEST_URI'] ?? '/install.php', PHP_URL_PATH)), '/.');
}
function h(string $value): string { return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
?><!doctype html><html lang="bg"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Install · BG-GAMER Portal</title><link rel="stylesheet" href="<?= h(bgPortalInstallBase().'/portal.css') ?>"><main class="editor"><p class="eyebrow">BG-GAMER / FIRST RUN</p><h1>Install portal</h1><?php if ($installed): ?><?php if ($success): ?><p class="notice success">Готово. public/install.php е изтрит успешно.</p><?php else: ?><p class="error"><?= h($cleanupError) ?></p><?php endif; ?><a class="button" href="<?= h(bgPortalInstallBase().'/owner/login') ?>">Owner login</a><?php else: ?><p class="muted">Въведи еднократния setup code от непубличния файл storage/app/install.key.</p><?php if ($error): ?><p class="error"><?= h($error) ?></p><?php endif; ?><form method="post" autocomplete="off"><input type="hidden" name="csrf" value="<?= h($_SESSION['csrf']) ?>"><label>Setup code</label><input name="install_key" type="password" required><label>HTTPS адрес на портала</label><input name="app_url" type="url" placeholder="https://portal.example.com" required><label>Database</label><select name="db_connection"><option value="mysql">MySQL</option><option value="pgsql">PostgreSQL</option></select><label>Host</label><input name="db_host" value="127.0.0.1" required><label>Port</label><input name="db_port" value="3306" required><label>Database name</label><input name="db_database" required><label>Database user</label><input name="db_username" required><label>Database password</label><input name="db_password" type="password"><label>Owner email</label><input name="owner_email" type="email" required><label>Owner password (14+ characters)</label><input name="owner_password" type="password" minlength="14" required><button class="button">Инсталирай</button></form><?php endif; ?></main></html>

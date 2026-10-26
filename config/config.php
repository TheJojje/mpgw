<?php

declare(strict_types=1);

use Dotenv\Dotenv;

$root = dirname(__DIR__);

if (is_file($root . '/.env')) {
    Dotenv::createImmutable($root)->load();
}

$required = ['DB_HOST', 'DB_DATABASE', 'DB_USERNAME', 'APP_SECRET_KEY'];
foreach ($required as $key) {
    if (($_ENV[$key] ?? '') === '') {
        // I CLI-verktyg (t.ex. migrationer) vill vi inte tvinga fram allt direkt,
        // men saknad DB_* eller APP_SECRET_KEY ska alltid synas tydligt.
        fwrite(STDERR, "Varning: milj\xc3\xb6variabeln {$key} \xc3\xa4r inte satt (se .env.example)\n");
    }
}

$appEnv = $_ENV['APP_ENV'] ?? 'production';
$appDebug = filter_var($_ENV['APP_DEBUG'] ?? 'false', FILTER_VALIDATE_BOOL);
$sessionSecure = filter_var($_ENV['SESSION_SECURE'] ?? 'true', FILTER_VALIDATE_BOOL);

if ($appEnv === 'production' && ($appDebug || !$sessionSecure)) {
    fwrite(STDERR, "Fatalt: APP_ENV=production kraver APP_DEBUG=false och SESSION_SECURE=true (se .env.example)\n");
    exit(1);
}

return [
    'app' => [
        'env' => $appEnv,
        'debug' => $appDebug,
        'root' => $root,
        'secret_key' => $_ENV['APP_SECRET_KEY'] ?? '',
    ],
    'db' => [
        'host' => $_ENV['DB_HOST'] ?? '127.0.0.1',
        'port' => (int) ($_ENV['DB_PORT'] ?? 3306),
        'database' => $_ENV['DB_DATABASE'] ?? '',
        'username' => $_ENV['DB_USERNAME'] ?? '',
        'password' => $_ENV['DB_PASSWORD'] ?? '',
    ],
    'session' => [
        'name' => $_ENV['SESSION_NAME'] ?? 'mpgw_admin',
        'secure' => $sessionSecure,
    ],
    'oidc' => [
        'enabled' => ($_ENV['OIDC_ISSUER'] ?? '') !== '' && ($_ENV['OIDC_CLIENT_ID'] ?? '') !== '',
        'issuer' => $_ENV['OIDC_ISSUER'] ?? '',
        'client_id' => $_ENV['OIDC_CLIENT_ID'] ?? '',
        'client_secret' => $_ENV['OIDC_CLIENT_SECRET'] ?? '',
        'redirect_uri' => $_ENV['OIDC_REDIRECT_URI'] ?? '',
        'access_group' => $_ENV['OIDC_ACCESS_GROUP'] ?? 'GRP-MPGW-Users',
        'admin_group' => $_ENV['OIDC_ADMIN_GROUP'] ?? 'GRP-MPGW-Admin',
        'sso_logout' => filter_var($_ENV['OIDC_SSO_LOGOUT'] ?? 'false', FILTER_VALIDATE_BOOL),
        'post_logout_redirect_uri' => $_ENV['OIDC_POST_LOGOUT_REDIRECT_URI'] ?? '',
        'default_role' => $_ENV['OIDC_DEFAULT_ROLE'] ?? 'reader',
        'admin_role' => $_ENV['OIDC_ADMIN_ROLE'] ?? 'administrator',
    ],
    'alerts' => [
        'min_sample_size' => (int) ($_ENV['ALERT_MIN_SAMPLE_SIZE'] ?? 5),
        'match_rate_drop_threshold' => (float) ($_ENV['ALERT_MATCH_RATE_DROP_THRESHOLD'] ?? 0.20),
    ],
    'api' => [
        'default_rate_limit_per_minute' => (int) ($_ENV['API_DEFAULT_RATE_LIMIT_PER_MINUTE'] ?? 600),
    ],
    'smtp' => [
        'host' => $_ENV['SMTP_HOST'] ?? '',
        'port' => (int) ($_ENV['SMTP_PORT'] ?? 587),
        'username' => $_ENV['SMTP_USERNAME'] ?? '',
        'password' => $_ENV['SMTP_PASSWORD'] ?? '',
        'from' => $_ENV['SMTP_FROM'] ?? 'mpgw@example.com',
    ],
];

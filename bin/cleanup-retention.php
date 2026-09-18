#!/usr/bin/env php
<?php

declare(strict_types=1);

// Cron-entrypoint: raderar extraherad data (extracted_records +
// extracted_fields via CASCADE) och gamla raw_emails, styrt av respektive
// applikations retention_days / raw_email_retention_days.
//
// Exempel pa crontab-rad (en gang per dygn, kl 03:00 - forskjuten fran
// poll-mailboxes.php sa de inte belastar databasen samtidigt):
//   0 3 * * * /usr/bin/php8.3 /var/www/html/apps/int/mpgw/bin/cleanup-retention.php >> /var/www/html/apps/int/mpgw/var/log/retention.log 2>&1

use Mpgw\Service\RetentionService;

/** @var \DI\Container $container */
$container = require dirname(__DIR__) . '/config/bootstrap.php';

$service = $container->get(RetentionService::class);

$startedAt = microtime(true);
printf('[%s] Startar retention-cleanup...' . PHP_EOL, date('c'));

$result = $service->cleanupAll();

printf(
    '[%s] Klar: %d applikation(er) kontrollerade, %d extraherade poster raderade, %d råmail raderade, %d fel (%.2fs)' . PHP_EOL,
    date('c'),
    $result['applications_checked'],
    $result['records_deleted'],
    $result['raw_emails_deleted'],
    count($result['errors']),
    microtime(true) - $startedAt,
);

foreach ($result['errors'] as $error) {
    fwrite(STDERR, '  FEL: ' . $error . PHP_EOL);
}

exit(count($result['errors']) > 0 ? 1 : 0);

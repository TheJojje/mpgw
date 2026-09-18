#!/usr/bin/env php
<?php

declare(strict_types=1);

// Cron-entrypoint: hamtar nya mail fran alla aktiva mailboxes, sparar
// ratt mail, matchar mot mall och koer extraction_rules.
//
// Exempel pa crontab-rad (varannan minut):
//   */2 * * * * /usr/bin/php8.3 /var/www/html/apps/int/mpgw/bin/poll-mailboxes.php >> /var/www/html/apps/int/mpgw/var/log/poll.log 2>&1

use Mpgw\Service\MailboxPoller;

/** @var \DI\Container $container */
$container = require dirname(__DIR__) . '/config/bootstrap.php';

$poller = $container->get(MailboxPoller::class);

$startedAt = microtime(true);
printf('[%s] Startar polling...' . PHP_EOL, date('c'));

$result = $poller->pollAll();

printf(
    '[%s] Klar: %d mailbox(ar) pollade, %d mail hamtade, %d fel (%.2fs)' . PHP_EOL,
    date('c'),
    $result['mailboxes_polled'],
    $result['emails_fetched'],
    count($result['errors']),
    microtime(true) - $startedAt,
);

foreach ($result['errors'] as $error) {
    fwrite(STDERR, '  FEL: ' . $error . PHP_EOL);
}

exit(count($result['errors']) > 0 ? 1 : 0);

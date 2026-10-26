#!/usr/bin/env php
<?php

declare(strict_types=1);

// Beraknar om tradar (se ThreadService) for alla records i en applikation,
// eller for alla applikationer som har tradinstallningar. Kor t.ex. efter
// att tradinstallningar andrats pa prod, eller for att backfilla.
//
//   php bin/rebuild-threads.php [application_id]

use Mpgw\Infrastructure\Db\Repository\ApplicationRepository;
use Mpgw\Service\ThreadService;

/** @var \DI\Container $container */
$container = require dirname(__DIR__) . '/config/bootstrap.php';

$threads = $container->get(ThreadService::class);
$only = isset($argv[1]) ? (int) $argv[1] : null;

foreach ($container->get(ApplicationRepository::class)->all() as $application) {
    if ($only !== null && (int) $application['id'] !== $only) {
        continue;
    }
    if (empty($application['thread_key_fields'])) {
        continue;
    }
    $count = $threads->syncApplication((int) $application['id']);
    printf("%s: %d records omberaknade\n", $application['name'], $count);
}

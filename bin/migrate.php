#!/usr/bin/env php
<?php

declare(strict_types=1);

// Kor alla SQL-migrationsfiler i database/migrations/ i filnamnsordning.
// Enkel "kor allt varje gang"-migrering (CREATE TABLE IF NOT EXISTS) -
// tillrackligt for MVP, inget separat migreringsramverk.

require dirname(__DIR__) . '/vendor/autoload.php';

$config = require dirname(__DIR__) . '/config/config.php';

$dsn = sprintf('mysql:host=%s;port=%d;charset=utf8mb4', $config['db']['host'], $config['db']['port']);
$pdo = new PDO($dsn, $config['db']['username'], $config['db']['password'], [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
]);

$dbName = $config['db']['database'];
$pdo->exec(sprintf('CREATE DATABASE IF NOT EXISTS `%s` CHARACTER SET utf8mb4', $dbName));
$pdo->exec(sprintf('USE `%s`', $dbName));

$migrationsDir = dirname(__DIR__) . '/database/migrations';
$files = glob($migrationsDir . '/*.sql');
sort($files);

foreach ($files as $file) {
    printf('Kor migration: %s' . PHP_EOL, basename($file));
    $sql = file_get_contents($file);
    if ($sql === false) {
        fwrite(STDERR, "Kunde inte lasa {$file}\n");
        exit(1);
    }

    // PDO::exec stodjer flera statements separerade med ; for MySQL-drivrutinen.
    $pdo->exec($sql);
}

echo "Migrering klar." . PHP_EOL;

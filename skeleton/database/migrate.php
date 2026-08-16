<?php

declare(strict_types=1);

/**
 * Run pending migrations.
 *
 *     php skeleton/database/migrate.php
 */

use Dotenv\Dotenv;
use Phpvin\Application;
use Phpvin\Database\Migrator;

$root = dirname(__DIR__);

require $root . '/vendor/autoload.php';

if (class_exists(Dotenv::class)) {
    Dotenv::createImmutable($root)->safeLoad();
}

$app = new Application($root, require $root . '/config.php');
$app->boot();

$migrator = $app->container()->get(Migrator::class);
$applied = $migrator->run();

if ($applied === []) {
    echo "Nothing to migrate.\n";
    exit(0);
}

foreach ($applied as $name) {
    echo "  migrated  $name\n";
}

echo sprintf("\n%d migration%s applied.\n", count($applied), count($applied) === 1 ? '' : 's');

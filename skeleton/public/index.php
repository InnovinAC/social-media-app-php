<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

/** @var Phpvin\Application $app */
$app = require dirname(__DIR__) . '/bootstrap.php';

$app->run();

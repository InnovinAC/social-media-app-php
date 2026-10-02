<?php

declare(strict_types=1);

/*
 * Router for PHP's built-in server, used by `phpvin serve`.
 *
 * Without one, PHP before 8.4 answers a missing path that looks like a file
 * (/_phpvin/phpvin.js, say) with its own 404, and the application never sees
 * the request. Real files are served as they are; everything else goes to
 * public/index.php, the same split the bundled .htaccess makes.
 */

$root = (string) $_SERVER['DOCUMENT_ROOT'];
$path = urldecode((string) parse_url((string) $_SERVER['REQUEST_URI'], PHP_URL_PATH));

if ($path !== '/' && is_file($root . $path)) {
    return false;
}

require $root . '/index.php';

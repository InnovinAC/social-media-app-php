<?php
    declare(strict_types=1);
    ini_set('display_errors', 1);
    ini_set('display_startup_errors', 1);
    error_reporting(E_ALL);
    require __DIR__ . '/vendor/autoload.php';

    if (!defined('ROOT_DIR')) {
//        const ROOT_DIR = '../';
        define('ROOT_DIR', '../');
    }



    use App\Framework\Request;
    $request = Request::createFromGlobals();
    require_once __DIR__ . '/src/bootstrap.php';

    print(mt_rand(5, 10));

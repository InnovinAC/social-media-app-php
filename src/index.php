<?php
    declare(strict_types=1);
    ini_set('display_errors', 1);
    ini_set('display_startup_errors', 1);
    error_reporting(E_ALL);
    require __DIR__ . '/../vendor/autoload.php';

//    error_reporting(1);
    use App\Framework\Request;

//    try {
    $request = Request::createFromGlobals();
//    dd($request);
    require_once __DIR__ . '/bootstrap.php';










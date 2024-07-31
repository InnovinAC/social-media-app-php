<?php
    global $request;

    use \App\Library\Router;
$server = $request->server;
$url = $server['REQUEST_URI'];
$router = new Router();
include_once __DIR__ . '/routes/web.php';
$router->dispatch();
<?php
use \App\Library\Router;
//$router = Router::getInstance();
$router->add('/hey', 'SiteController@index', ['GET']);
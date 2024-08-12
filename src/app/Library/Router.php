<?php

    namespace App\Library;


    class Router {
        protected $routes = [
            'GET' => [],
            'POST' => [],
            'PATCH' => [],
            'DELETE' => []
        ];

        private static ?Router $instance = null;

        public function __construct() {}

        public static function getInstance(): Router {
            if (self::$instance === null) {
                self::$instance = new self();
            }
            return self::$instance;
        }

        public function add($route, $controllerAction, $methods = ['GET']) {
            foreach ($methods as $method) {
                $this->routes[strtoupper($method)][strtolower($route)] = $controllerAction;
            }
        }

        public function dispatch() {
            $url = $this->parseUrl();
            $method = $_SERVER['REQUEST_METHOD'];
            $params = [];

            if (isset($this->routes[$method])) {
                // Handle the root URL
                if (empty($url) || $url === ['']) {
                    $url = ['/'];
                }

                foreach ($this->routes[$method] as $route => $controllerAction) {
                    if ($this->match($route, $url, $params)) {
                        list($controllerName, $action) = explode('@', $controllerAction);
                        $controllerName = "App\Controllers\\$controllerName";

                        if (class_exists($controllerName)) {
                            $controller = new $controllerName;

                            if (method_exists($controller, $action)) {
                                $queryParams = $_GET;
                                return call_user_func_array([$controller, $action], array_merge($params, [$queryParams]));
                            }
                        }
                        break;
                    }
                }
            }

            http_response_code(404);
            echo "404 Not Found";
        }

        private function parseUrl() {
            if (isset($_GET['url'])) {
                return explode('/', filter_var(rtrim($_GET['url'], '/'), FILTER_SANITIZE_URL));
            }
            return [];
        }

        private function match($route, $url, &$params) {
            $routeParts = explode('/', trim($route, '/'));

            // Handle the case where both route and URL are empty (i.e., the root URL "/")
            if ($routeParts === [''] && $url === ['/']) {
                return true;
            }

            if (count($routeParts) != count($url)) {
                return false;
            }

            foreach ($routeParts as $key => $part) {
                if (preg_match('/^:/', $part)) {
                    $params[] = $url[$key];
                } elseif ($part !== str_replace('/', '', $url[$key])) {
                    return false;
                }
            }

            return true;
        }
    }

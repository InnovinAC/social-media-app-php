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

        // Private constructor to prevent direct object creation
        public function __construct() {}

        // Method to get the single instance of the Router class
        public static function getInstance(): Router {
            if (self::$instance === null) {
                self::$instance = new self();
            }
            return self::$instance;
        }

        // Method to add a route
        public function add($route, $controllerAction, $methods = ['GET']) {
            foreach ($methods as $method) {
                $this->routes[strtoupper($method)][strtolower($route)] = $controllerAction;
            }
        }

        // Method to dispatch the request
        public function dispatch() {
            $url = $this->parseUrl();
            $method = $_SERVER['REQUEST_METHOD'];
            $params = [];

            if (isset($this->routes[$method])) {
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

            // If no route is matched, show a 404 error or a custom page
            http_response_code(404);
            echo "404 Not Found";
        }

        // Method to parse the URL
        private function parseUrl() {
            if (isset($_GET['url'])) {
                return explode('/', filter_var(rtrim($_GET['url'], '/'), FILTER_SANITIZE_URL));
            }
            return [];
        }

        // Method to match the route with the URL
        private function match($route, $url, &$params) {
            $routeParts = explode('/', trim($route, '/'));
            if (count($routeParts) != count($url)) {
                return false;
            }

            foreach ($routeParts as $key => $part) {
//                dd($part, str_replace('/', '', $url[$key]));
//                dd(str_replace('/', '', $url[$key]));
                if (preg_match('/^:/', $part)) {
                    $params[] = $url[$key];
                } elseif ($part !== str_replace('/', '', $url[$key])) {
                    return false;
                }
            }

            return true;
        }
    }

<?php

    namespace App\Framework;
    class Request {

        public function __construct(
            public readonly array $postParams,
            public readonly array $getParams,
            public readonly array $cookies,
            public readonly array $files,
            public readonly array $server,
            public readonly array $request,
        ) {

        }

        public static function createFromGlobals(): static {
            try {
                return new static($_POST, $_GET, $_COOKIE, $_FILES, $_SERVER, $_REQUEST);
            } catch (\Exception $e) {
                var_dump($e);
            }
        }


        public static function get(string $key): string|null {
            $getParams = self::createFromGlobals()->getParams;
            return $getParams[$key] ?? null;
        }
        public static function getCookie(string $key): string|null {
            $cookieParams = self::createFromGlobals()->cookies;
            return $getParams[$key] ?? null;
        }




    }

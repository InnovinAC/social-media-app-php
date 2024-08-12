<?php
    namespace App\Library;

    class View {
        protected static $data = [];
        protected static $sections = [];
        protected static $sectionStack = [];
        protected static $includeStack = [];
        protected static $includes = [];
        protected static $layout;

        public static function render(string $name, array $data = []): void {
            self::$data = $data;
            extract(self::$data);
            ob_start();
            require __DIR__ . '/../../views/' . $name . '.view.php';
            $content = ob_get_clean();

            if (self::$layout) {
                ob_start();
                require __DIR__ . '/../../views/layout/' . self::$layout . '.view.php';
                echo ob_get_clean();
            } else {
                echo $content;
            }

        }

        public static function extends(string $layout): void {
            self::$layout = $layout;
        }

        public static function include(string $layout): void {
            if (file_exists(__DIR__ . '/../../views/includes/' . $layout . '.view.php')) {
                ob_start();
                require __DIR__ . '/../../views/includes/' . $layout . '.view.php';
                echo ob_get_clean();
            }
        }

        public static function section(string $name, $value = ''): void {
            if (!$value) {
                self::$sectionStack[] = $name;
                ob_start();
            } else {
                self::$sections[$name] =$value;
            }
        }

        public static function endSection(): void {
            $last = array_pop(self::$sectionStack);
            self::$sections[$last] = ob_get_clean();
        }

        public static function yield(string $name, string $default = ''): void {
            echo self::$sections[$name] ?? $default;
        }

        // Function to make templating functions globally available in view files
        public static function registerGlobals(): void {
            foreach (get_class_methods(__CLASS__) as $method) {
                if ($method !== 'render') {
                    $new_method = '_' . $method;
                    $GLOBALS['_' . $method] = [__CLASS__, $method];
                    $$new_method =   [__CLASS__, $method];
                }
//
                }
        }
    }

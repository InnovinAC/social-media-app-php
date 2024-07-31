<?php
    namespace App\Library;
    class View {
        public function __construct() {

        }

        public static function render(string $name): void {
            require __DIR__ . '/../../views/' . $name . '.view.php';
        }
    }

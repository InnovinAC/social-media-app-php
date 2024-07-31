<?php
    namespace Database;



    class Init {
        private static ?Init $instance = null;
        private $dbName;
        private $dbUser;
        private $dbPassword;
        private $dbHost;
        private $connection;

        private function __construct() {
            $this->dbName = env('DATABASE_NAME', 'sample_db');
            $this->dbUser = env('DATABASE_USER', 'root');
            $this->dbPassword = env('DATABASE_PASSWORD', '');
            $this->dbHost = env('DATABASE_HOST', 'localhost');
            try {
                $this->connect();
                // database connected;
            } catch (\Exception $e) {
                var_dump($e);
            }

        }

        static function getInstance(): Init {
            if (!self::$instance) {
                self::$instance = new self();
            }
            return self::$instance;
        }

        public function connect() {
            try {
                $connection = new \PDO('mysql:host=' . $this->dbHost . ';dbname=' . $this->dbName, $this->dbUser, $this->dbPassword);
                $this->connection = $connection;
            } catch (\Exception $e) {
                return $e;
            }

        }


        // Close the database connection
        public function __destruct() {
            $this->connection = null;
        }
    }
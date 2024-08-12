<?php

    namespace Database;
    use Dotenv\Dotenv;
    use PDO;


    class DatabaseInit {
        private static ?DatabaseInit $instance = null;
        private $dbName;
        private $dbUser;
        private $dbPassword;
        private $dbHost;
        private $dbPort;
        public $connection;

        private function __construct() {
            $dotenv = Dotenv::createImmutable(ROOT_DIR);
            $dotenv->load();
            $this->dbName = $_ENV['DATABASE_NAME'];
            $this->dbUser = $_ENV['DATABASE_USER'];
            $this->dbPassword = $_ENV['DATABASE_PASSWORD'];
            $this->dbHost = $_ENV['DATABASE_HOST'];
            $this->dbPort = $_ENV['DATABASE_HOST'];
            try {
                $this->connect();
            } catch (\Exception $e) {
                var_dump($e);
            }

        }

        static function get_instance(): DatabaseInit {
            if (!self::$instance) {
                self::$instance = new self();
            }
            return self::$instance;
        }

        public function connect() {
            try {
                $this->connection = new \PDO('mysql:host='.$this->dbHost.';dbname='.$this->dbName, $this->dbUser, $this->dbPassword);
                $this->connection->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            } catch (\Exception $e) {
                return $e;
            }

        }


        // Close the database connection
        public function __destruct() {
            print("here");
            $this->connection = null;
        }
    }
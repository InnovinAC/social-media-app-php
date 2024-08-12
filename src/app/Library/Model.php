<?php
    namespace App\Library;


    use Database\DatabaseInit;

    class Model {

        private DatabaseInit $db_instance;
        private string $query;
        private \ArrayObject $bind_params;
        protected \PDO $db;
        public function __construct() {
            $this->db_instance = DatabaseInit::get_instance();
            $this->db = $this->db_instance->connection;
        }


        private function get_table_name(): string {
            return __CLASS__ + 's';
        }

        protected function getById(string $id): self {
            try {
                $this->query .= 'SELECT * from '. $this->get_table_name(). ' where id = :id';
                $this->bind_params[] = [':id' => $id];
                return $this;
            } catch (\Exception $e) {

            }
        }

        protected function execute() {

        }






    }
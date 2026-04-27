<?php
namespace App\Config;

class Database {
    public $conn;

    public function getConnection() {
        $this->conn = null;

        try {
            $this->conn = new \PDO(
                "mysql:host=" . $_ENV['DB_HOST'] . ";dbname=" . $_ENV['DB_NAME'],
                $_ENV['DB_USER'],
                $_ENV['DB_PASS']
            );
            $this->conn->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
            $this->conn->setAttribute(\PDO::ATTR_DEFAULT_FETCH_MODE, \PDO::FETCH_ASSOC); 
        } catch(\PDOException $exception) {
            echo json_encode(["error" => "Database Connection Error: " . $exception->getMessage()]);
            exit;
        }

        return $this->conn;
    }
}
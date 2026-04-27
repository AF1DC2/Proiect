<?php
namespace App\Config;

class Database {
    private $host = "127.0.0.1";
    private $db_name = "carcassonne_api";
    private $username = "root";
    private $password = "";
    public $conn;

    public function getConnection() {
        $this->conn = null;

        try {
            $this->conn = new \PDO(
                "mysql:host=" . $this->host . ";dbname=" . $this->db_name,
                $this->username,
                $this->password
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
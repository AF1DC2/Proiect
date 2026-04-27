<?php
namespace App\Controllers;

use PDO;
use Exception;

class UserController {
    private $db;

    public function __construct(PDO $db) {
        $this->db = $db;
    }

    // GET /api/users
    public function getAllUsers() {
        try {
            $stmt = $this->db->query("SELECT userId, username, totalWins, created_at FROM users");
            $users = $stmt->fetchAll();
            
            http_response_code(200);
            echo json_encode($users);
        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode(["error" => "Failed to fetch users", "details" => $e->getMessage()]);
        }
    }

    // GET /api/users/{userId}
    public function getUserProfile($userId) {
        try {
            $stmt = $this->db->prepare("SELECT userId, username, totalWins, created_at FROM users WHERE userId = :userId");
            $stmt->execute(['userId' => $userId]);
            $user = $stmt->fetch();

            if (!$user) {
                http_response_code(404);
                echo json_encode(["error" => "User not found"]);
                return;
            }

            http_response_code(200);
            echo json_encode($user);
        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode(["error" => "Failed to fetch user profile"]);
        }
    }

    // POST /api/users
    public function registerUser($data) {
        if (!isset($data['username']) || !isset($data['password'])) {
            http_response_code(400);
            echo json_encode(["error" => "Username and password are required"]);
            return;
        }

        $username = trim($data['username']);
        $password = $data['password'];

        if (strlen($username) < 3 || strlen($password) < 6) {
            http_response_code(400);
            echo json_encode(["error" => "Username must be at least 3 characters and password at least 6 characters"]);
            return;
        }

        try {
            $stmt = $this->db->prepare("SELECT COUNT(*) FROM users WHERE username = :username");
            $stmt->execute(['username' => $username]);
            if ($stmt->fetchColumn() > 0) {
                http_response_code(409);
                echo json_encode(["error" => "Username is already taken"]);
                return;
            }

            $hashedPassword = password_hash($password, PASSWORD_BCRYPT);
            $userId = uniqid('u_');

            $insertStmt = $this->db->prepare("INSERT INTO users (userId, username, password_hash) VALUES (:userId, :username, :password)");
            $insertStmt->execute([
                'userId' => $userId,
                'username' => $username,
                'password' => $hashedPassword
            ]);

            http_response_code(201);
            echo json_encode([
                "userId" => $userId,
                "username" => $username,
                "totalWins" => 0
            ]);

        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode(["error" => "Failed to register user", "details" => $e->getMessage()]);
        }
    }
}
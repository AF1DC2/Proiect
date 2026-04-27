<?php
namespace App\Controllers;

use PDO;
use Exception;

class GameController {
    private $db;

    public function __construct(PDO $db) {
        $this->db = $db;
    }

    // POST /api/games
    public function createGame() {
        try {
            $gameId = uniqid('game_');

            $stmt = $this->db->prepare("INSERT INTO games (gameId, status) VALUES (:gameId, 'waiting_for_players')");
            $stmt->execute(['gameId' => $gameId]);

            http_response_code(201);
            echo json_encode([
                "gameId" => $gameId,
                "status" => "waiting_for_players"
            ]);
        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode(["error" => "Failed to create game", "details" => $e->getMessage()]);
        }
    }

    // POST /api/games/{gameId}/players
    public function joinGame($gameId, $data) {
        if (!isset($data['userId'])) {
            http_response_code(400);
            echo json_encode(["error" => "userId is required to join a game"]);
            return;
        }

        $userId = $data['userId'];

        try {
            $stmt = $this->db->prepare("SELECT status FROM games WHERE gameId = :gameId");
            $stmt->execute(['gameId' => $gameId]);
            $game = $stmt->fetch();

            if (!$game) {
                http_response_code(404);
                echo json_encode(["error" => "Game not found"]);
                return;
            }

            if ($game['status'] !== 'waiting_for_players') {
                http_response_code(403);
                echo json_encode(["error" => "Cannot join this game. Current status: " . $game['status']]);
                return;
            }

            $insertStmt = $this->db->prepare("INSERT INTO players (gameId, userId, score, meeplesLeft) VALUES (:gameId, :userId, 0, 7)");
            $insertStmt->execute([
                'gameId' => $gameId,
                'userId' => $userId
            ]);

            http_response_code(200);
            echo json_encode([
                "playerId" => $userId,
                "score" => 0,
                "meeplesLeft" => 7
            ]);

        } catch (Exception $e) {
            if ($e->getCode() == 23000) {
                http_response_code(409);
                echo json_encode(["error" => "User is already in this game lobby"]);
            } else {
                http_response_code(500);
                echo json_encode(["error" => "Failed to join game", "details" => $e->getMessage()]);
            }
        }
    }

    // GET /api/games/{gameId}/players
    public function getPlayersInGame($gameId) {
        try {
            $stmt = $this->db->prepare("
                SELECT p.userId AS playerId, u.username AS playerName, p.score, p.meeplesLeft 
                FROM players p
                JOIN users u ON p.userId = u.userId
                WHERE p.gameId = :gameId
            ");
            $stmt->execute(['gameId' => $gameId]);
            $players = $stmt->fetchAll();

            http_response_code(200);
            echo json_encode($players);
        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode(["error" => "Failed to fetch players", "details" => $e->getMessage()]);
        }
    }
}
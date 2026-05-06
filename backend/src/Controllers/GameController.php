<?php
namespace App\Controllers;

use PDO;
use Exception;
use Pusher\Pusher;

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

    // PATCH /api/games/{gameId}/start
    public function startGame($gameId) {
        try {
            // 1. Verify the game exists and is waiting for players
            $stmt = $this->db->prepare("SELECT status FROM games WHERE gameId = :gameId");
            $stmt->execute(['gameId' => $gameId]);
            $game = $stmt->fetch();

            if (!$game) {
                http_response_code(404);
                echo json_encode(["error" => "Game not found"]);
                return;
            }

            if ($game['status'] !== 'waiting_for_players') {
                http_response_code(400);
                echo json_encode(["error" => "Game has already started or is finished"]);
                return;
            }

            // 2. Get all players to determine who goes first
            $stmt = $this->db->prepare("SELECT userId FROM players WHERE gameId = :gameId ORDER BY joined_at ASC");
            $stmt->execute(['gameId' => $gameId]);
            $players = $stmt->fetchAll();

            if (count($players) < 2) {
                http_response_code(400);
                echo json_encode(["error" => "Need at least 2 players to start the game"]);
                return;
            }

            $firstPlayerId = $players[0]['userId'];

            // 3. Define the Deck
            $deck = [];
            for ($i = 1; $i <= 99; $i++) {
                // sprintf("%02d", $i) turns 1 into "01", 2 into "02", but leaves 10 as "10"
                $paddedNumber = sprintf("%02d", $i);
                $deck[] = "tile_" . $paddedNumber;
            }

            // 4. Shuffle the deck randomly
            shuffle($deck);

            // 4. Shuffle the deck randomly
            shuffle($deck);

            // --- START DATABASE TRANSACTION ---
            $this->db->beginTransaction();

            // 5. Update the game status and set the first turn
            $updateGameStmt = $this->db->prepare("
                UPDATE games 
                SET status = 'in_progress', current_turn_userId = :firstPlayerId 
                WHERE gameId = :gameId
            ");
            $updateGameStmt->execute([
                'firstPlayerId' => $firstPlayerId,
                'gameId' => $gameId
            ]);

            // 6. Insert the shuffled tiles into the game_tiles table
            $insertTileStmt = $this->db->prepare("
                INSERT INTO game_tiles (gameId, tile_type, draw_order, is_drawn) 
                VALUES (:gameId, :tileType, :drawOrder, FALSE)
            ");

            foreach ($deck as $index => $tileType) {
                $insertTileStmt->execute([
                    'gameId' => $gameId,
                    'tileType' => $tileType,
                    'drawOrder' => $index // 0 is the top of the deck, 98 is the bottom
                ]);
            }

            // --- COMMIT TRANSACTION ---
            $this->db->commit();

            // 7. Return Success
            http_response_code(200);
            echo json_encode([
                "message" => "Game started successfully",
                "gameId" => $gameId,
                "firstTurnPlayerId" => $firstPlayerId,
                "totalTiles" => count($deck)
            ]);

        } catch (Exception $e) {
            // If anything fails, roll back the database so we don't have half a deck
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            http_response_code(500);
            echo json_encode(["error" => "Failed to start game", "details" => $e->getMessage()]);
        }
    }

    // POST /api/games/{gameId}/turn/draw
    public function drawTile($gameId, $data) {
        if (!isset($data['userId'])) {
            http_response_code(400);
            echo json_encode(["error" => "userId is required to draw a tile"]);
            return;
        }

        $userId = $data['userId'];

        try {
            $this->db->beginTransaction();

            // 1. Fetch the current game state
            $stmt = $this->db->prepare("SELECT status, current_turn_userId, current_drawn_tile_id FROM games WHERE gameId = :gameId");
            $stmt->execute(['gameId' => $gameId]);
            $game = $stmt->fetch();

            if (!$game || $game['status'] !== 'in_progress') {
                $this->db->rollBack();
                http_response_code(400);
                echo json_encode(["error" => "Game is not in progress"]);
                return;
            }

            // 2. Enforce Turn Order
            if ($game['current_turn_userId'] !== $userId) {
                $this->db->rollBack();
                http_response_code(403);
                echo json_encode(["error" => "It is not your turn"]);
                return;
            }

            // 3. Prevent Double-Drawing
            // If they already drew a tile but refreshed their browser, we just return the tile they are already holding!
            if ($game['current_drawn_tile_id'] !== null) {
                $stmt = $this->db->prepare("SELECT id, tile_type FROM game_tiles WHERE id = :tileId");
                $stmt->execute(['tileId' => $game['current_drawn_tile_id']]);
                $existingTile = $stmt->fetch();

                $this->db->rollBack();
                http_response_code(200);
                echo json_encode([
                    "message" => "You already drew a tile this turn.",
                    "tileId" => $existingTile['id'],
                    "tileType" => $existingTile['tile_type']
                ]);
                return;
            }

            // 4. Find the top card of the deck (the first one that hasn't been drawn)
            $stmt = $this->db->prepare("
                SELECT id, tile_type 
                FROM game_tiles 
                WHERE gameId = :gameId AND is_drawn = FALSE 
                ORDER BY draw_order ASC 
                LIMIT 1
            ");
            $stmt->execute(['gameId' => $gameId]);
            $nextTile = $stmt->fetch();

            if (!$nextTile) {
                // If no tiles are left, the game is effectively over
                $this->db->rollBack();
                http_response_code(400);
                echo json_encode(["error" => "No tiles left in the deck. Game over!"]);
                return;
            }

            // 5. Mark the tile as drawn
            $updateTileStmt = $this->db->prepare("UPDATE game_tiles SET is_drawn = TRUE WHERE id = :id");
            $updateTileStmt->execute(['id' => $nextTile['id']]);

            // 6. Update the game state to show what tile is currently in play
            $updateGameStmt = $this->db->prepare("UPDATE games SET current_drawn_tile_id = :tileId WHERE gameId = :gameId");
            $updateGameStmt->execute([
                'tileId' => $nextTile['id'],
                'gameId' => $gameId
            ]);

            $this->db->commit();

            // 7. Send the tile data to the frontend so your partner can render the image!
            http_response_code(200);
            echo json_encode([
                "tileId" => $nextTile['id'],
                "tileType" => $nextTile['tile_type']
            ]);

        } catch (Exception $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            http_response_code(500);
            echo json_encode(["error" => "Failed to draw tile", "details" => $e->getMessage()]);
        }
    }

    // GET /api/games/{gameId}/moves
    public function getBoardMoves($gameId) {
        try {
            // Updated to use ON m.tileId = t.id
            $stmt = $this->db->prepare("
                SELECT m.x, m.y, m.rotation, t.tile_type 
                FROM moves m
                JOIN game_tiles t ON m.tileId = t.id
                WHERE m.gameId = :gameId
            ");
            $stmt->execute(['gameId' => $gameId]);
            $moves = $stmt->fetchAll();

            http_response_code(200);
            echo json_encode($moves);
        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode(["error" => "Failed to fetch board state", "details" => $e->getMessage()]);
        }
    }

    // POST /api/games/{gameId}/moves
    public function submitMove($gameId, $data) {
        if (!isset($data['userId']) || !isset($data['x']) || !isset($data['y']) || !isset($data['rotation'])) {
            http_response_code(400);
            echo json_encode(["error" => "Datele mutării sunt incomplete."]);
            return;
        }

        $userId = $data['userId'];
        $x = (int)$data['x'];
        $y = (int)$data['y'];
        $rotation = (int)$data['rotation'];

        try {
            $this->db->beginTransaction();

            // 1. Verify game state and turn
            $stmt = $this->db->prepare("SELECT status, current_turn_userId, current_drawn_tile_id FROM games WHERE gameId = :gameId");
            $stmt->execute(['gameId' => $gameId]);
            $game = $stmt->fetch();

            if (!$game || $game['status'] !== 'in_progress') {
                throw new Exception("Jocul nu este în desfășurare.");
            }
            if ($game['current_turn_userId'] !== $userId) {
                throw new Exception("Nu este rândul tău!");
            }
            if ($game['current_drawn_tile_id'] === null) {
                throw new Exception("Trebuie să tragi o piesă mai întâi.");
            }

            $tileId = $game['current_drawn_tile_id'];

            // 2. Check if the grid spot is already taken
            // --- NEW: Calculate the moveNumber ---
            $countStmt = $this->db->prepare("SELECT COUNT(*) FROM moves WHERE gameId = :gameId");
            $countStmt->execute(['gameId' => $gameId]);
            $currentMoveCount = (int)$countStmt->fetchColumn();
            $moveNumber = $currentMoveCount + 1;

            // 3. Insert the move into the database (Now includes moveNumber!)
            $stmt = $this->db->prepare("
                INSERT INTO moves (gameId, moveNumber, userId, tileId, x, y, rotation) 
                VALUES (:gameId, :moveNumber, :userId, :tileId, :x, :y, :rotation)
            ");
            $stmt->execute([
                'gameId' => $gameId,
                'moveNumber' => $moveNumber,
                'userId' => $userId,
                'tileId' => $tileId, // This matches your VARCHAR(50) column!
                'x' => $x,
                'y' => $y,
                'rotation' => $rotation
            ]);

            // 4. Calculate whose turn is next!
            $stmt = $this->db->prepare("SELECT userId FROM players WHERE gameId = :gameId ORDER BY joined_at ASC");
            $stmt->execute(['gameId' => $gameId]);
            // Fetch as a simple 1D array of userIds
            $players = $stmt->fetchAll(\PDO::FETCH_COLUMN); 

            $currentIndex = array_search($userId, $players);
            // Math magic: Add 1 to the index, but use Modulo (%) to loop back to 0 if we hit the end of the array
            $nextIndex = ($currentIndex + 1) % count($players); 
            $nextPlayerId = $players[$nextIndex];

            // 5. Update the game state (Clear the hand, advance the turn)
            $stmt = $this->db->prepare("
                UPDATE games 
                SET current_drawn_tile_id = NULL, current_turn_userId = :nextPlayerId 
                WHERE gameId = :gameId
            ");
            $stmt->execute([
                'nextPlayerId' => $nextPlayerId,
                'gameId' => $gameId
            ]);

            $this->db->commit();

            $pusherOptions = [
                'cluster' => 'eu',
                'useTLS' => true
            ];
            $pusher = new Pusher(
                'b852cb41209513497088',
                '9490cf2fa0fd1b8e5662',
                '2150990',
                $pusherOptions
            );

            // Send a ping to the specific game "channel"
            $pusher->trigger('game-' . $gameId, 'move-played', [
                'message' => 'O nouă piesă a fost plasată!',
                'nextTurn' => $nextPlayerId
            ]);

            http_response_code(200);
            echo json_encode(["message" => "Mutare înregistrată cu succes!", "nextTurn" => $nextPlayerId]);

        } catch (Exception $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            http_response_code(400); 
            echo json_encode(["error" => $e->getMessage()]);
        }
    }
}
<?php
namespace App\Controllers;

use PDO;
use Exception;
use Pusher\Pusher;
use App\Services\ScoreService;

class GameController {
    private $db;

    public function __construct(PDO $db) {
        $this->db = $db;
    }

    private function pusher(): Pusher {
        return new Pusher(
            'b852cb41209513497088',
            '9490cf2fa0fd1b8e5662',
            '2150990',
            ['cluster' => 'eu', 'useTLS' => true]
        );
    }

    private function fetchFinalScores(string $gameId): array {
        $stmt = $this->db->prepare("
            SELECT p.userId AS playerId, u.username AS playerName, p.score, p.meeplesLeft
            FROM players p
            JOIN users u ON p.userId = u.userId
            WHERE p.gameId = :gameId
            ORDER BY p.score DESC, u.username ASC
        ");
        $stmt->execute(['gameId' => $gameId]);
        return $stmt->fetchAll();
    }

    private function computeWinner(array $finalScores): ?string {
        if (empty($finalScores)) return null;
        $top = (int)$finalScores[0]['score'];
        $topPlayers = array_filter($finalScores, fn($p) => (int)$p['score'] === $top);
        return count($topPlayers) === 1 ? $finalScores[0]['playerId'] : null;
    }

    // Caller must NOT hold an open transaction. FKs aren't cascading,
    // so we delete child rows explicitly in dependency order.
    private function purgeGame(string $gameId): void {
        $this->db->beginTransaction();
        try {
            foreach (['moves', 'game_tiles', 'players', 'games'] as $table) {
                $stmt = $this->db->prepare("DELETE FROM $table WHERE gameId = :gameId");
                $stmt->execute(['gameId' => $gameId]);
            }
            $this->db->commit();
        } catch (Exception $e) {
            if ($this->db->inTransaction()) $this->db->rollBack();
            error_log("purgeGame failed for $gameId: " . $e->getMessage());
            throw $e;
        }
    }

    // Broadcasts game-ended (with final scores), then hard-deletes the game.
    // Caller must commit any pending transaction first.
    private function endAndPurgeGame(string $gameId, string $reason, ?string $forcedWinnerId = null): array {
        // End-of-game scoring runs first so finalScores reflect the reduced
        // points for any still-on-board meeples. Skip for lobby_abandoned —
        // the game never started, there is nothing to score.
        if ($reason !== 'lobby_abandoned') {
            try {
                $this->db->beginTransaction();
                (new ScoreService($this->db))->scoreEndOfGame($gameId);
                $this->db->commit();
            } catch (Exception $e) {
                if ($this->db->inTransaction()) $this->db->rollBack();
                error_log("end-of-game scoring failed for $gameId: " . $e->getMessage());
            }
        }

        $finalScores = $this->fetchFinalScores($gameId);
        $winnerId = $forcedWinnerId !== null ? $forcedWinnerId : $this->computeWinner($finalScores);

        $payload = [
            'reason'      => $reason,
            'winnerId'    => $winnerId,
            'finalScores' => $finalScores
        ];

        // Broadcast first so clients can read state before the rows vanish.
        $this->pusher()->trigger('game-' . $gameId, 'game-ended', $payload);

        try {
            $this->purgeGame($gameId);
        } catch (Exception $e) {
            // Already broadcast — clients will react regardless. Log and move on.
        }

        return $payload;
    }

    // GET /api/games/{gameId}
    public function getGame($gameId) {
        try {
            $stmt = $this->db->prepare("SELECT gameId, status, current_turn_userId, current_drawn_tile_id FROM games WHERE gameId = :gameId");
            $stmt->execute(['gameId' => $gameId]);
            $game = $stmt->fetch();

            if (!$game) {
                http_response_code(404);
                echo json_encode(["error" => "Game not found"]);
                return;
            }

            http_response_code(200);
            echo json_encode($game);
        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode(["error" => "Failed to fetch game", "details" => $e->getMessage()]);
        }
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

            $nameStmt = $this->db->prepare("SELECT username FROM users WHERE userId = :userId");
            $nameStmt->execute(['userId' => $userId]);
            $playerName = $nameStmt->fetchColumn();

            $this->pusher()->trigger('game-' . $gameId, 'player-joined', [
                'playerId' => $userId,
                'playerName' => $playerName,
                'score' => 0,
                'meeplesLeft' => 7
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

            // 3. Define the Deck (72 tiles — Carcassonne base game size, per API spec)
            $deck = [];
            for ($i = 1; $i <= 72; $i++) {
                $paddedNumber = sprintf("%02d", $i);
                $deck[] = "tile_" . $paddedNumber;
            }

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
                    'drawOrder' => $index
                ]);
            }

            // --- COMMIT TRANSACTION ---
            $this->db->commit();

            $this->pusher()->trigger('game-' . $gameId, 'game-started', [
                'gameId' => $gameId,
                'firstTurnPlayerId' => $firstPlayerId,
                'totalTiles' => count($deck)
            ]);

            http_response_code(200);
            echo json_encode([
                "message" => "Game started successfully",
                "gameId" => $gameId,
                "firstTurnPlayerId" => $firstPlayerId,
                "totalTiles" => count($deck)
            ]);

        } catch (Exception $e) {
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

            // 4. Find the top card of the deck
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
                $this->db->rollBack();
                http_response_code(400);
                echo json_encode(["error" => "No tiles left in the deck. Game over!"]);
                return;
            }

            // 5. Mark the tile as drawn
            $updateTileStmt = $this->db->prepare("UPDATE game_tiles SET is_drawn = TRUE WHERE id = :id");
            $updateTileStmt->execute(['id' => $nextTile['id']]);

            // 6. Update the game state
            $updateGameStmt = $this->db->prepare("UPDATE games SET current_drawn_tile_id = :tileId WHERE gameId = :gameId");
            $updateGameStmt->execute([
                'tileId' => $nextTile['id'],
                'gameId' => $gameId
            ]);

            $this->db->commit();

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
            // Updated: now also returns placeMeeple, meepleLocation, userId so the frontend can render meeples
            $stmt = $this->db->prepare("
                SELECT m.x, m.y, m.rotation, m.placeMeeple, m.meepleLocation, m.userId, m.meeple_returned, t.tile_type 
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

        // --- NEW: Meeple data (optional) ---
        $placeMeeple = isset($data['placeMeeple']) ? (bool)$data['placeMeeple'] : false;
        $meepleLocation = $data['meepleLocation'] ?? null;

        // Validate meeple location format if a meeple is being placed
        if ($placeMeeple) {
            $validLocations = ['n', 'e', 's', 'w', 'c'];
            if (!in_array($meepleLocation, $validLocations, true)) {
                http_response_code(400);
                echo json_encode(["error" => "Locație meeple invalidă. Folosește: n, e, s, w sau c."]);
                return;
            }
        } else {
            // Ensure null is saved when no meeple is placed
            $meepleLocation = null;
        }

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

            // 2. NEW: Verify the target cell is empty (was missing before!)
            $cellCheck = $this->db->prepare("SELECT COUNT(*) FROM moves WHERE gameId = :gameId AND x = :x AND y = :y");
            $cellCheck->execute(['gameId' => $gameId, 'x' => $x, 'y' => $y]);
            if ((int)$cellCheck->fetchColumn() > 0) {
                throw new Exception("Această poziție este deja ocupată.");
            }

            // 3. NEW: If placing a meeple, verify the player has at least 1 left
            if ($placeMeeple) {
                $meepleCheck = $this->db->prepare("
                    SELECT meeplesLeft FROM players 
                    WHERE gameId = :gameId AND userId = :userId
                ");
                $meepleCheck->execute(['gameId' => $gameId, 'userId' => $userId]);
                $meeplesLeft = $meepleCheck->fetchColumn();

                if ($meeplesLeft === false) {
                    throw new Exception("Jucătorul nu a fost găsit în acest joc.");
                }
                if ((int)$meeplesLeft <= 0) {
                    throw new Exception("Nu mai ai meeple disponibili!");
                }
            }

            // 4. Calculate moveNumber
            $countStmt = $this->db->prepare("SELECT COUNT(*) FROM moves WHERE gameId = :gameId");
            $countStmt->execute(['gameId' => $gameId]);
            $currentMoveCount = (int)$countStmt->fetchColumn();
            $moveNumber = $currentMoveCount + 1;

            // 5. Insert the move (now including meeple data)
            $stmt = $this->db->prepare("
                INSERT INTO moves (gameId, moveNumber, userId, tileId, x, y, rotation, placeMeeple, meepleLocation) 
                VALUES (:gameId, :moveNumber, :userId, :tileId, :x, :y, :rotation, :placeMeeple, :meepleLocation)
            ");
            $stmt->execute([
                'gameId' => $gameId,
                'moveNumber' => $moveNumber,
                'userId' => $userId,
                'tileId' => $tileId,
                'x' => $x,
                'y' => $y,
                'rotation' => $rotation,
                'placeMeeple' => $placeMeeple ? 1 : 0,
                'meepleLocation' => $meepleLocation
            ]);

            // 6. NEW: Decrement meeple inventory if one was placed
            if ($placeMeeple) {
                $updateMeeples = $this->db->prepare("
                    UPDATE players SET meeplesLeft = meeplesLeft - 1 
                    WHERE gameId = :gameId AND userId = :userId
                ");
                $updateMeeples->execute([
                    'gameId' => $gameId,
                    'userId' => $userId
                ]);
            }

            // 7. Calculate next player
            $stmt = $this->db->prepare("SELECT userId FROM players WHERE gameId = :gameId ORDER BY joined_at ASC");
            $stmt->execute(['gameId' => $gameId]);
            $players = $stmt->fetchAll(\PDO::FETCH_COLUMN);

            $currentIndex = array_search($userId, $players);
            $nextIndex = ($currentIndex + 1) % count($players);
            $nextPlayerId = $players[$nextIndex];

            // 8. Update game state
            $stmt = $this->db->prepare("
                UPDATE games
                SET current_drawn_tile_id = NULL, current_turn_userId = :nextPlayerId
                WHERE gameId = :gameId
            ");
            $stmt->execute([
                'nextPlayerId' => $nextPlayerId,
                'gameId' => $gameId
            ]);

            // 8.5. Score any feature this placement just completed (currently:
            // monasteries that became fully surrounded). Runs inside the same
            // transaction so move + scoring stay atomic.
            $scoreEvents = (new ScoreService($this->db))->scoreAfterMove($gameId, $x, $y);

            $this->db->commit();

            // 9. Pusher notification (now includes meeple info so frontends can refresh)
            $this->pusher()->trigger('game-' . $gameId, 'move-played', [
                'message' => 'O nouă piesă a fost plasată!',
                'nextTurn' => $nextPlayerId,
                'meeplePlaced' => $placeMeeple,
                'meepleLocation' => $meepleLocation,
                'scoreEvents' => $scoreEvents
            ]);

            // 10. Deck exhausted? End the game.
            $stmt = $this->db->prepare("SELECT COUNT(*) FROM game_tiles WHERE gameId = :gameId AND is_drawn = FALSE");
            $stmt->execute(['gameId' => $gameId]);
            $tilesLeft = (int)$stmt->fetchColumn();

            $gameOver = false;
            $winnerId = null;
            if ($tilesLeft === 0) {
                $endPayload = $this->endAndPurgeGame($gameId, 'deck_exhausted');
                $gameOver = true;
                $winnerId = $endPayload['winnerId'];
            }

            http_response_code(200);
            echo json_encode([
                "message" => "Mutare înregistrată cu succes!",
                "nextTurn" => $nextPlayerId,
                "meeplePlaced" => $placeMeeple,
                "gameOver" => $gameOver,
                "winnerId" => $winnerId
            ]);

        } catch (Exception $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            http_response_code(400);
            echo json_encode(["error" => $e->getMessage()]);
        }
    }

    // DELETE /api/games/{gameId}/players/{userId}
    public function leaveGame($gameId, $userId) {
        try {
            $this->db->beginTransaction();

            $stmt = $this->db->prepare("SELECT status, current_turn_userId FROM games WHERE gameId = :gameId");
            $stmt->execute(['gameId' => $gameId]);
            $game = $stmt->fetch();

            if (!$game) {
                $this->db->rollBack();
                http_response_code(404);
                echo json_encode(["error" => "Game not found"]);
                return;
            }

            $stmt = $this->db->prepare("SELECT COUNT(*) FROM players WHERE gameId = :gameId AND userId = :userId");
            $stmt->execute(['gameId' => $gameId, 'userId' => $userId]);
            if ((int)$stmt->fetchColumn() === 0) {
                $this->db->rollBack();
                http_response_code(404);
                echo json_encode(["error" => "Player is not in this game"]);
                return;
            }

            // Capture turn order before removal (needed to advance turn correctly).
            $stmt = $this->db->prepare("SELECT userId FROM players WHERE gameId = :gameId ORDER BY joined_at ASC");
            $stmt->execute(['gameId' => $gameId]);
            $oldOrder = $stmt->fetchAll(\PDO::FETCH_COLUMN);

            $stmt = $this->db->prepare("DELETE FROM players WHERE gameId = :gameId AND userId = :userId");
            $stmt->execute(['gameId' => $gameId, 'userId' => $userId]);

            $remaining = array_values(array_filter($oldOrder, fn($id) => $id !== $userId));

            // -------- Lobby state --------
            if ($game['status'] === 'waiting_for_players') {
                if (count($remaining) === 0) {
                    // Commit the delete first, then end+purge (which broadcasts).
                    $this->db->commit();
                    $this->endAndPurgeGame($gameId, 'lobby_abandoned', null);
                } else {
                    $this->db->commit();
                    $this->pusher()->trigger('game-' . $gameId, 'player-left', [
                        'playerId' => $userId,
                        'remaining' => count($remaining)
                    ]);
                }
                http_response_code(200);
                echo json_encode(["message" => "Left lobby"]);
                return;
            }

            // -------- In-progress state --------
            if (count($remaining) < 2) {
                // Not enough players to continue — last one standing wins.
                $winnerId = $remaining[0] ?? null;
                $this->db->commit();
                $endPayload = $this->endAndPurgeGame($gameId, 'players_left', $winnerId);
                http_response_code(200);
                echo json_encode([
                    "message" => "Game ended due to player leaving",
                    "gameOver" => true,
                    "winnerId" => $endPayload['winnerId']
                ]);
                return;
            }

            // Game continues. Advance turn if the leaver was active.
            $newTurn = $game['current_turn_userId'];
            if ($game['current_turn_userId'] === $userId) {
                $leaverIdx = array_search($userId, $oldOrder, true);
                $newTurn = $oldOrder[($leaverIdx + 1) % count($oldOrder)];
                // Guaranteed to be in $remaining (only one player was removed).
                $stmt = $this->db->prepare("
                    UPDATE games
                    SET current_turn_userId = :next, current_drawn_tile_id = NULL
                    WHERE gameId = :gameId
                ");
                $stmt->execute(['next' => $newTurn, 'gameId' => $gameId]);
            }

            $this->db->commit();

            $this->pusher()->trigger('game-' . $gameId, 'player-left', [
                'playerId' => $userId,
                'nextTurn' => $newTurn,
                'remaining' => count($remaining)
            ]);

            http_response_code(200);
            echo json_encode(["message" => "Left game", "nextTurn" => $newTurn]);

        } catch (Exception $e) {
            if ($this->db->inTransaction()) $this->db->rollBack();
            http_response_code(500);
            echo json_encode(["error" => "Failed to leave game", "details" => $e->getMessage()]);
        }
    }

    // POST /api/games/{gameId}/end
    public function endGameNow($gameId, $data) {
        if (!isset($data['userId'])) {
            http_response_code(400);
            echo json_encode(["error" => "userId is required to end a game"]);
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
            if ($game['status'] !== 'in_progress') {
                http_response_code(400);
                echo json_encode(["error" => "Only games in progress can be ended"]);
                return;
            }

            // Only a player in the game can end it.
            $stmt = $this->db->prepare("SELECT COUNT(*) FROM players WHERE gameId = :gameId AND userId = :userId");
            $stmt->execute(['gameId' => $gameId, 'userId' => $userId]);
            if ((int)$stmt->fetchColumn() === 0) {
                http_response_code(403);
                echo json_encode(["error" => "Only players in this game can end it"]);
                return;
            }

            $endPayload = $this->endAndPurgeGame($gameId, 'manual');

            http_response_code(200);
            echo json_encode([
                "message" => "Game ended",
                "gameOver" => true,
                "winnerId" => $endPayload['winnerId']
            ]);
        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode(["error" => "Failed to end game", "details" => $e->getMessage()]);
        }
    }
}

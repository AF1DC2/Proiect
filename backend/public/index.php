<?php
// public/index.php

require_once __DIR__ . '/../vendor/autoload.php';

$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/../');
$dotenv->load();

header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Methods: OPTIONS,GET,POST,PUT,PATCH,DELETE");
header("Access-Control-Allow-Headers: Content-Type, Access-Control-Allow-Headers, Authorization, X-Requested-With");

// Handle preflight OPTIONS requests for CORS
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$method = $_SERVER['REQUEST_METHOD'];
$dbConfig = new \App\Config\Database();
$dbConnection = $dbConfig->getConnection();

$userController = new \App\Controllers\UserController($dbConnection);
$gameController = new \App\Controllers\GameController($dbConnection);

switch (true) {
    // --------------
    // USER ROUTES
    // --------------
    
    // GET /api/users
    case ($method === 'GET' && $uri === '/api/users'):
        $userController->getAllUsers();
        break;

    // POST /api/users
    case ($method === 'POST' && $uri === '/api/users'):
        $data = json_decode(file_get_contents("php://input"), true);
        $userController->registerUser($data);
        break;

    // GET /api/users/{userId}
    case ($method === 'GET' && preg_match('#^/api/users/([^/]+)$#', $uri, $matches)):
        $userId = $matches[1];
        $userController->getUserProfile($userId);
        break;

    // POST /api/users/login
    case ($method === 'POST' && $uri === '/api/users/login'):
        $data = json_decode(file_get_contents("php://input"), true);
        $userController->loginUser($data);
        break;

    // --------------------
    // GAME LOBBY ROUTES
    // --------------------

    // POST /api/games
    case ($method === 'POST' && $uri === '/api/games'):
        $gameController->createGame();
        break;

    // GET /api/games/{gameId}
    case ($method === 'GET' && preg_match('#^/api/games/([^/]+)$#', $uri, $matches)):
        $gameId = $matches[1];
        echo json_encode(["message" => "Endpoint hit: Get game state", "gameId" => $gameId]);
        break;

    // GET /api/games/{gameId}/players (Get all players currently in a game)
    case ($method === 'GET' && preg_match('#^/api/games/([^/]+)/players$#', $uri, $matches)):
        $gameId = $matches[1];
        $gameController->getPlayersInGame($gameId);
        break;

    // POST /api/games/{gameId}/players (Join Game)
    case ($method === 'POST' && preg_match('#^/api/games/([^/]+)/players$#', $uri, $matches)):
        $gameId = $matches[1];
        $data = json_decode(file_get_contents("php://input"), true);
        $gameController->joinGame($gameId, $data);
        break;

    // DELETE /api/games/{gameId}/players/{userId} (Leave Game)
    case ($method === 'DELETE' && preg_match('#^/api/games/([^/]+)/players/([^/]+)$#', $uri, $matches)):
        $gameId = $matches[1];
        $userId = $matches[2];
        echo json_encode(["message" => "Endpoint hit: Remove player", "gameId" => $gameId, "userId" => $userId]);
        break;

    // PATCH /api/games/{gameId}/start (Start Game)
    case ($method === 'PATCH' && preg_match('#^/api/games/([^/]+)/start$#', $uri, $matches)):
        $gameId = $matches[1];
        $gameController->startGame($gameId);
        break;

    // ------------------
    // GAMEPLAY ROUTES
    // ------------------

    // POST /api/games/{gameId}/turn/draw (Draw a tile)
    case ($method === 'POST' && preg_match('#^/api/games/([^/]+)/turn/draw$#', $uri, $matches)):
        $gameId = $matches[1];
        $data = json_decode(file_get_contents("php://input"), true);
        $gameController->drawTile($gameId, $data);
        break;

    // GET /api/games/{gameId}/moves (Fetch the board state)
    case ($method === 'GET' && preg_match('#^/api/games/([^/]+)/moves$#', $uri, $matches)):
        $gameId = $matches[1];
        $gameController->getBoardMoves($gameId);
        break;

    // POST /api/games/{gameId}/moves (Submit a dragged tile)
    case ($method === 'POST' && preg_match('#^/api/games/([^/]+)/moves$#', $uri, $matches)):
        $gameId = $matches[1];
        $data = json_decode(file_get_contents("php://input"), true);
        $gameController->submitMove($gameId, $data);
        break;

    // 404 Not Found
    default:
        http_response_code(404);
        echo json_encode(["error" => "Endpoint not found"]);
        break;
}
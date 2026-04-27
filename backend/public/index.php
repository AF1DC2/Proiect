<?php
// public/index.php

require_once __DIR__ . '/../vendor/autoload.php';

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

switch (true) {
    // GET /api/users
    case ($method === 'GET' && $uri === '/api/users'):
        echo json_encode(["message" => "Endpoint hit: Get all users"]);
        break;

    // POST /api/users
    case ($method === 'POST' && $uri === '/api/users'):
        echo json_encode(["message" => "Endpoint hit: Register a new user"]);
        break;

    // GET /api/users/{userId}
    case ($method === 'GET' && preg_match('#^/api/users/([^/]+)$#', $uri, $matches)):
        $userId = $matches[1];
        echo json_encode(["message" => "Endpoint hit: Get user profile", "userId" => $userId]);
        break;

    // POST /api/games
    case ($method === 'POST' && $uri === '/api/games'):
        echo json_encode(["message" => "Endpoint hit: Create a new game"]);
        break;

    // GET /api/games/{gameId}
    case ($method === 'GET' && preg_match('#^/api/games/([^/]+)$#', $uri, $matches)):
        $gameId = $matches[1];
        echo json_encode(["message" => "Endpoint hit: Get game state", "gameId" => $gameId]);
        break;

    // POST /api/games/{gameId}/moves
    case ($method === 'POST' && preg_match('#^/api/games/([^/]+)/moves$#', $uri, $matches)):
        $gameId = $matches[1];
        // To read the JSON body sent in a POST request:
        $data = json_decode(file_get_contents("php://input"), true);
        echo json_encode(["message" => "Endpoint hit: Submit move", "gameId" => $gameId, "payload" => $data]);
        break;

    // 404 Not Found
    default:
        http_response_code(404);
        echo json_encode(["error" => "Endpoint not found"]);
        break;
}
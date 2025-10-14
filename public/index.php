<?php
// ======================================
// 🛡️ CORS GLOBAL (antes de despachar rutas)
// ======================================
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
$allowedOrigin = 'http://localhost:3000'; // tu app Vue

header("Access-Control-Allow-Origin: $allowedOrigin");
header('Access-Control-Allow-Credentials: true');
header('Access-Control-Allow-Headers: Authorization, Content-Type');
header('Access-Control-Allow-Methods: GET, POST, PUT, PATCH, DELETE, OPTIONS');
header('Access-Control-Max-Age: 86400'); // cache del preflight 24h
header('Vary: Origin');

// ⚙️ Responder automáticamente los preflight (OPTIONS)
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204); // Sin contenido
    exit;
}

require_once __DIR__ . '/../includes/app.php';

use Controllers\ClientController;
use Controllers\LoginController;
use Controllers\TripController;
use MVC\Router;


$router = new Router();


//Iniciar Sesion
$router->post('/api/login', [LoginController::class, 'login']);
$router->post('/api/logout', [LoginController::class, 'logout']);
$router->post('/api/client/create', [ClientController::class, 'store']);


//Admin
$router->get('/api/clients', [ClientController::class, 'all']);
$router->get('/api/client', [ClientController::class, 'show']);
$router->post('/api/client/destroy', [ClientController::class, 'destroy']);
$router->post('/api/client/update', [ClientController::class, 'update']);
$router->get('/api/trip', [TripController::class, 'show']);
$router->get('/api/trips', [TripController::class, 'index']);
$router->post('/api/trip/create', [TripController::class, 'store']);





$router->comprobarRutas();

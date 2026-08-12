<?php
// ======================================
// 🛡️ CORS GLOBAL (antes de despachar rutas)
// =====================================

// Lista blanca (incluye esquema + host + puerto)
$allowedOrigins = [
    'http://localhost:3000',
    'http://localhost:4321',

];

$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
$isAllowed = in_array($origin, $allowedOrigins, true);

// Si el Origin es válido, refléjalo
if ($isAllowed) {
    header("Access-Control-Allow-Origin: {$origin}");
    header('Access-Control-Allow-Credentials: true');
}

// Siempre variar por Origin para caches
header('Vary: Origin');

// Headers comunes
header('Access-Control-Allow-Headers: Authorization, Content-Type, X-Requested-With,Idempotency-Key');
header('Access-Control-Allow-Methods: GET, POST, PUT, PATCH, DELETE, OPTIONS');

header('Access-Control-Max-Age: 0');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Cache-Control: post-check=0, pre-check=0', false);
header('Pragma: no-cache');
header('Expires: 0');


// Preflight
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    if ($isAllowed) {
        header("Access-Control-Allow-Origin: {$origin}");
        header('Access-Control-Allow-Credentials: true');
    }
    header('Access-Control-Allow-Headers: Authorization, Content-Type, X-Requested-With,Idempotency-Key');
    header('Access-Control-Allow-Methods: GET, POST, PUT, PATCH, DELETE, OPTIONS');
    header('Access-Control-Max-Age: 0');

    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    header('Expires: 0');
    http_response_code(204);
    exit;
}


require_once __DIR__ . '/../includes/app.php';

use Controllers\ClientController;
use Controllers\CodesController;
use Controllers\CommentsController;
use Controllers\DashboardController;
use Controllers\LoginController;
use Controllers\ReservationController;
use Controllers\SurveyController;
use Controllers\TripController;
use MVC\Router;

$router = new Router();

// === Rutas ===

// Login / Logout
$router->post('/api/login', [LoginController::class, 'login']);
$router->post('/api/logout', [LoginController::class, 'logout']);
$router->post('/api/client/create', [ClientController::class, 'store']);

//Programador
$router->get('/api/users', [ClientController::class, 'index'], true);
$router->post('/api/users', [ClientController::class, 'store'], true);
$router->post('/api/users/update', [ClientController::class, 'update'], true);
$router->post('/api/users/destroy', [ClientController::class, 'destroy'], true);
$router->post('/api/passengers/update', [ReservationController::class, 'updatePassengerInformation'], true);


// Admin
$router->post('/api/trip/create', [TripController::class, 'store'], true);
$router->post('/api/trip/update', [TripController::class, 'update'], true);
$router->post('/api/trip/destroy', [TripController::class, 'destroy'], true);
$router->post('/api/passenger-payment', [TripController::class, 'confirmPassengerPayment'], true);

$router->get('/api/travels', [DashboardController::class, 'getTravels'], false);
$router->get('/api/travels/passengers', [DashboardController::class, 'getPassengersByTravel'], true);
$router->get('/api/admin/dashboard', [DashboardController::class, 'index']);


$router->post('/api/comments/update', [CommentsController::class, 'update'], true);


//Public API
$router->get('/api/trip', [TripController::class, 'show']);
$router->get('/api/trips', [TripController::class, 'index']);
$router->post('/api/comments/create', [CommentsController::class, 'store']);
$router->get('/api/comments', [CommentsController::class, 'index']);

$router->post('/api/reservation', [ReservationController::class, 'store']);
$router->get('/api/passengerVerifyEmail', [ReservationController::class, 'passengerVerifyEmail']);

$router->get('/api/codes', [CodesController::class, 'index']);
$router->get('/api/code', [CodesController::class, 'show']);
$router->post('/api/codes', [CodesController::class, 'store']);
$router->post('/api/codes/update', [CodesController::class, 'update']);
$router->post('/api/codes/destroy', [CodesController::class, 'delete']);
$router->get('/api/codes/validate', [CodesController::class, 'validate']);

$router->get('/api/survey', [SurveyController::class, 'show']);
$router->post('/api/survey', [SurveyController::class, 'store']);

$router->get('/api/admin/survey-responses', [SurveyController::class, 'adminIndex']);
$router->get('/api/admin/survey-response', [SurveyController::class, 'adminShow']);
$router->get('/api/admin/survey-stats', [SurveyController::class, 'adminStats']);

$router->get('/api/surveys/send-pending', [SurveyController::class, 'sendPendingSurveys']);

$router->comprobarRutas();
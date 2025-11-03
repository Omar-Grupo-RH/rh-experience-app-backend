<?php
// ======================================
// 🛡️ CORS GLOBAL (antes de despachar rutas)
// ======================================

$allowedOrigin = 'http://localhost:3000';
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';

if ($origin === $allowedOrigin) {
    header("Access-Control-Allow-Origin: $allowedOrigin");
}

header('Access-Control-Allow-Credentials: true');
header('Access-Control-Allow-Headers: Authorization, Content-Type, X-Requested-With');
header('Access-Control-Allow-Methods: GET, POST, PUT, PATCH, DELETE, OPTIONS');
header('Access-Control-Max-Age: 86400');
header('Vary: Origin');

// ⚙️ Manejo de preflight (muy importante)
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    if ($origin === $allowedOrigin) {
        header("Access-Control-Allow-Origin: $allowedOrigin");
    }
    header('Access-Control-Allow-Credentials: true');
    header('Access-Control-Allow-Headers: Authorization, Content-Type, X-Requested-With');
    header('Access-Control-Allow-Methods: GET, POST, PUT, PATCH, DELETE, OPTIONS');
    http_response_code(204);
    exit;
}

require_once __DIR__ . '/../includes/app.php';

use Controllers\ClientController;
use Controllers\CommentsController;
use Controllers\LoginController;
use Controllers\TripController;
use Controllers\CouponController;
use Controllers\DashboardController;
use Controllers\PaymentAPI;
use MVC\Router;

$router = new Router();

// === Rutas ===

// Login / Logout
$router->post('/api/login', [LoginController::class, 'login']);
$router->post('/api/logout', [LoginController::class, 'logout']);
$router->post('/api/client/create', [ClientController::class, 'store']);

// Admin
$router->get('/api/clients', [ClientController::class, 'all'], true);

$router->get('/api/trip', [TripController::class, 'show']);
$router->get('/api/trips', [TripController::class, 'index']);
$router->post('/api/trip/create', [TripController::class, 'store'], true);
$router->post('/api/trip/update', [TripController::class, 'update'], true);
$router->post('/api/trip/destroy', [TripController::class, 'destroy'], true);
$router->get('/api/coupons', [CouponController::class, 'index']);
$router->get('/api/coupon', [CouponController::class, 'show'], true);
$router->post('/api/coupon', [CouponController::class, 'store'], true);
$router->post('/api/coupon/update', [CouponController::class, 'update'], true);
$router->post('/api/coupon/destroy', [CouponController::class, 'destroy'], true);
$router->get('/api/travels', [DashboardController::class, 'getTravels'], true);
$router->get('/api/travels/passengers', [DashboardController::class, 'getPassengersByTravel'], true);

//Public API
$router->get('/api/payment/coupon', [PaymentAPI::class, 'getCoupon']);
$router->post('/api/payment', [PaymentAPI::class, 'paymentIntent']);

$router->post('/api/comments/create', [CommentsController::class, 'store'], false);
$router->get('/api/comments', [CommentsController::class, 'index'], false);


$router->comprobarRutas();

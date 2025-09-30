<?php

require_once __DIR__ . '/../includes/app.php';

use Controllers\ClientController;
use Controllers\LoginController;
use MVC\Router;


$router = new Router();


//Iniciar Sesion
$router->post('/api/login', [LoginController::class, 'login']);
$router->post('/api/logout', [LoginController::class, 'logout']);
$router->post('/api/client/create', [ClientController::class, 'store']);
$router->post('/api/client/update', [ClientController::class, 'update']);
$router->post('/api/client/destroy', [ClientController::class, 'destroy']);

$router->comprobarRutas();
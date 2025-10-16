<?php

namespace MVC;

use App\Middleware\AuthMiddleware;

class Router
{
    public array $getRoutes = [];
    public array $postRoutes = [];

    public function get(string $url, array $fn, bool $authRequired = false): void
    {
        $this->getRoutes[rtrim($url, '/')] = [
            'callback' => $fn,
            'auth' => $authRequired
        ];
    }

    public function post(string $url, array $fn, bool $authRequired = false): void
    {
        $this->postRoutes[rtrim($url, '/')] = [
            'callback' => $fn,
            'auth' => $authRequired
        ];
    }

    public function comprobarRutas(): void
    {
        // Normalizamos la URL actual
        $url_actual = strtok($_SERVER['REQUEST_URI'], '?') ?? '/';
        $url_actual = rtrim($url_actual, '/');
        if ($url_actual === '') $url_actual = '/';

        $method = $_SERVER['REQUEST_METHOD'];

        // Seleccionamos la ruta según el método
        $route = ($method === 'GET')
            ? ($this->getRoutes[$url_actual] ?? null)
            : ($this->postRoutes[$url_actual] ?? null);

        if ($route) {
            // Validación JWT si es requerida
            if ($route['auth'] === true) {
                AuthMiddleware::requireAuth();
            }

            call_user_func($route['callback'], $this);
            return;
        }

        // Si no se encuentra la ruta: responder JSON con CORS
        http_response_code(404);
        header('Content-Type: application/json; charset=utf-8');
        header('Access-Control-Allow-Origin: http://localhost:3000');
        header('Access-Control-Allow-Credentials: true');
        echo json_encode([
            'success' => false,
            'message' => 'Ruta no encontrada'
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
}
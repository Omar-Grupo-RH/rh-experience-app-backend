<?php

namespace App\Middleware;

use App\Auth\JwtService;
use App\Http\JsonResponse;

final class AuthMiddleware
{
    public static function requireAuth(): void
    {
        $token = JwtService::getTokenFromRequest();

        if (!$token) {
            JsonResponse::send(['success' => false, 'message' => 'Token no proporcionado'], 401);
            exit;
        }
        try {
            JwtService::decode($token);
        } catch (\Throwable $e) {
           
            JsonResponse::send(['success' => false, 'message' => 'Token inválido o expirado'], 401);

            exit;
        }
    }
}
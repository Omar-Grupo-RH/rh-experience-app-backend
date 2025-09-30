<?php

namespace Controllers;

use Model\Client;
use App\Http\JsonResponse;
use App\Auth\JwtService;
use App\Security\LogoutService;

class LoginController
{
    public static function login(): void
    {
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            header('Allow: POST');

            // Lee JSON
            $raw = file_get_contents('php://input');
            $payload = json_decode($raw, true) ?: [];

            $email = trim($payload['email'] ?? '');
            $password = $payload['password'] ?? '';

            if ($email === '' || $password === '') {
                JsonResponse::send(['success' => false, 'message' => 'Email y contraseña son obligatorios'], 400);
            }

            // Buscar usuario
            $user = Client::where('email', $email);
            if (!$user) {
                JsonResponse::send(['success' => false, 'message' => 'No se encontro una cuenta con este email'], 401);
            }

            // Verificar password
            if (!password_verify($password, $user->password)) {
                JsonResponse::send(['success' => false, 'message' => 'Contraseña incorrecta'], 401);
            }

            // Emitir token
            $accessToken = JwtService::issueAccessToken((int)$user->id, [
                'email' => $user->email,
                'role'  => $user->admin ? 'admin' : 'user'
            ]);

            // Opción A: devolver solo JSON
            JsonResponse::send([
                'success' => true,
                'message' => 'Login correcto',
                'token' => $accessToken,
                'user' => [
                    'id' => (int)$user->id,
                    'first_name' => $user->first_name,
                    'last_name' => $user->last_name,
                    'email' => $user->email,
                ]
            ]);

            // Opción B (alternativa): además poner en cookie segura
            // setcookie('access_token', $accessToken, [
            //     'expires'=>time()+3600,
            //     'path'=>'/',
            //     'httponly'=>true,
            //     'samesite'=>'Lax',
            //     'secure'=>isset($_SERVER['HTTPS']),
            // ]);
        }
    }

    public static function logout(): void
    {
        // Acepta POST (o GET si prefieres), pero mejor POST
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            header('Allow: POST');
            // Si quieres exigir que venga un token válido:
            // (no es estrictamente necesario en la versión básica)
            
            $token = JwtService::getTokenFromRequest();
            if ($token) {
                try {
                    $claims = JwtService::decode($token);
                    
                } catch (\Throwable $e) {
                }
            }

            // 1) Borra cookies HttpOnly (si usabas cookies)
            LogoutService::run();

            // 2) El cliente debe borrar su token de localStorage (instrucción en la respuesta)
            JsonResponse::send([
                'success'     => true,
                'message'     => 'Sesión cerrada. Borra el token en el cliente.',
                'must_reauth' => true
            ]);
        } else {

            JsonResponse::send(['success' => false, 'message' => 'Método no permitido'], 405);
        }
    }
}
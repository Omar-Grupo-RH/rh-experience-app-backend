<?php

namespace Controllers;

use Model\Client;
use App\Http\JsonResponse;
use Throwable;

class ClientController
{
    private static function readBody(): array
    {
        $payload = $_POST;
        $isJson  = stripos($_SERVER['CONTENT_TYPE'] ?? '', 'application/json') !== false;

        if (empty($payload) && $isJson) {
            $raw = file_get_contents('php://input');
            $payload = json_decode($raw, true);
            if (!is_array($payload)) {
                JsonResponse::send(['success' => false, 'message' => 'JSON inválido'], 400);
            }
        }

        // Normaliza strings
        foreach ($payload as $k => $v) {
            if (is_string($v)) $payload[$k] = trim($v);
        }
        return $payload;
    }


    /* =======================
       GET /api/clients (mostrar clientes)
       ======================= */
    public static function index(): void
    {
        $usuarios = Client::all('ASC');
        $usuariosFiltrados = array_map(function ($u) {
            return [
                'id'       => $u->id,
                'full_name' => $u->full_name,
                'username' => $u->username,
                'admin'    => $u->admin,
            ];
        }, $usuarios);

        JsonResponse::send([
            'success' => true,
            'data'    => $usuariosFiltrados,
        ], 200);
    }

    /* =======================
       GET /api/clients/id  (mostrar un cliente)
       ======================= */
    public static function show(): void
    {
        $id = $_GET['id'] ?? null;
        if (!$id) {
            JsonResponse::send(['success' => false, 'message' => 'Falta id'], 400);
        }

        $user = Client::find($id);
        if (!$user) {
            JsonResponse::send(['success' => false, 'message' => 'Usuario no encontrado'], 404);
        }


        JsonResponse::send([
            'success' => true,
            'message' => 'Cliente encontrado',
            'data' => $user,
        ], 200);
    }

    /* =======================
       POST /api/users  (almacenar un cliente)
       ======================= */

    public static function store(): void
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            JsonResponse::send(['success' => false, 'message' => 'Metodo no permitido'], 405);
            exit;
        }

        $payload = self::readBody();
        $newUser = new Client($payload);

        try {
            if (Client::where('username', $newUser->username)) {
                JsonResponse::send(['success' => false, 'message' => 'El usuario ya está en uso.'], 422);
                exit;
            }
        } catch (Throwable $e) {
            JsonResponse::send(['success' => false, 'message' => $e->getMessage()], 500);
            exit;
        }

        if (empty($newUser->username)) {
            JsonResponse::send(['success' => false, 'message' => 'El usuario es obligatorio'], 422);
            exit;
        }

        if (empty($newUser->full_name)) {
            JsonResponse::send(['success' => false, 'message' => 'El nombre del usuario es obligatorio'], 422);
            exit;
        }

        if (empty($newUser->password)) {
            JsonResponse::send(['success' => false, 'message' => 'La contraseña es obligatoria'], 422);
            exit;
        }

        $newUser->hashearPassword();

        try {
            $res = $newUser->guardar();
        } catch (Throwable $e) {
            JsonResponse::send(['success' => false, 'message' => $e->getMessage()], 500);
        }

        if (empty($res['resultado'])) {
            JsonResponse::send(['success' => false, 'message' => 'No se pudo crear el cliente'], 500);
        }

        $userCreated =  [
            'id'       => $res['id'],
            'username' => $newUser->username,
            'full_name' => $newUser->full_name,
            'admin'    => $newUser->admin,
        ];

        JsonResponse::send([
            'success' => true,
            'message' => 'Usuario creado correctamente',
            'data' => $userCreated
        ], 200);
    }

    public static function update(): void
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            JsonResponse::send(['success' => false, 'message' => 'Method Not Allowed'], 405);
            exit;
        }

        $payload = self::readBody();
        $id = $payload['id'] ?? null;
        if (!$id) {
            JsonResponse::send(['success' => false, 'message' => 'Falta id'], 400);
            exit;
        }

        if (empty($payload['full_name'])) {
            JsonResponse::send(['success' => false, 'message' => 'El nombre del usuario es obligatorio'], 422);
            exit;
        }

        $user = Client::find($id);
        if (!$user) {
            JsonResponse::send(['success' => false, 'message' => 'Usuario no encontrado'], 404);
        }

        // unicidad de email (sólo si viene y cambió)
        if (!empty($payload['username']) && $payload['username'] !== $user->username) {
            $usernameExist = Client::where('username', $payload['username']);
            if ($usernameExist && (int)$usernameExist->id !== (int)$user->id) {
                JsonResponse::send(['success' => false, 'message' => 'El correo ya está en uso.'], 422);
            }
        }

        $user->username = trim($payload['username']);
        $user->full_name = trim($payload['full_name']);
        $user->admin = $payload['admin'] ?? $user->admin;
                                
        // password opcional + hash
        if (!empty($payload['password'])) {
            $user->password = password_hash($payload['password'], PASSWORD_BCRYPT);
        }

        try {
            $res = $user->guardar();
        } catch (Throwable $e) {
            JsonResponse::send(['success' => false, 'message' => $e->getMessage()], 500);
        }

        if (empty($res['resultado'])) {
            JsonResponse::send(['success' => false, 'message' => 'No se pudo actualizar el cliente'], 500);
        }


        JsonResponse::send([
            'success' => true,
            'message' => 'Usuario actualizado',
            'data' => $user
        ], 200);
    }

    public static function destroy(): void
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            JsonResponse::send(['success' => false, 'message' => 'Method Not Allowed'], 405);
            exit;
        }
        $payload = self::readBody();
        $id = $payload['id'] ?? null;

        if (!$id) {
            JsonResponse::send(['success' => false, 'message' => 'Falta id del cupon'], 400);
        }

        try {
            $user = Client::find($id);
        } catch (\Throwable $e) {
            JsonResponse::send(['success' => false, 'message' => 'Error al buscar a el usuario'], 500);
        }

        if (!$user) {
            JsonResponse::send(['success' => false, 'message' => 'Usuario no encontrado'], 404);
        }
        try {
            $res = $user->eliminar();
        } catch (Throwable $e) {
            JsonResponse::send(['success' => false, 'message' => 'Error al eliminar el usuario'], 500);
        }

        if (!$res) {
            JsonResponse::send(['success' => false, 'message' => 'No se pudo eliminar el usuario'], 500);
        }

        JsonResponse::send([
            'success' => true,
            'message' => 'Usuario eliminado correctamente.',
        ], 200);
    }
}
<?php

namespace Controllers;

use Throwable;
use Model\Client;
use App\Http\JsonResponse;
use App\Security\LogoutService;
use App\Validation\ClientValidator;
use App\Auth\JwtService;
use App\Time\Clock;


class ClientController
{
    /* =======================
       Helpers del controlador
       ======================= */

    /** Lee el cuerpo (JSON o form) y devuelve array asociativo. Lanza 400 si JSON inválido. */
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

    /** Filtra solo campos permitidos del body */
    private static function pick(array $data, array $allowed): array
    {
        return array_intersect_key($data, array_flip($allowed));
    }

    /* =======================
       GET /api/clients  (mostrar)
       ======================= */
    public static function all(): void
    {
        header('Access-Control-Allow-Origin: http://localhost:3000'); // tu front
        header('Access-Control-Allow-Credentials: true');
        header('Access-Control-Allow-Headers: Authorization, Content-Type');
        header('Access-Control-Allow-Methods:GET');
        header('Allow: GET');
        if ($_SERVER['REQUEST_METHOD'] === 'GET') {

            $clients  = Client::whereAll('admin', 0);
            JsonResponse::send([
             
                'data' => $clients
            ], 201);
        }
    }

    /* =======================
       GET /api/clients/id  (mostrar un cliente)
       ======================= */

    public static function show(): void
    {
        header('Access-Control-Allow-Origin: http://localhost:3000');
        header('Access-Control-Allow-Credentials: true');
        header('Access-Control-Allow-Headers: Authorization, Content-Type, X-Requested-With');
        header('Access-Control-Allow-Methods: GET, OPTIONS');
        header('Content-Type: application/json; charset=utf-8');

        if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
            http_response_code(204);
            exit;
        }
        if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
            JsonResponse::send(['success' => false, 'message' => 'Method Not Allowed'], 405);
        }

        $id = $_GET['id'] ?? null; // si usas query param
        if (!$id) {
            JsonResponse::send(['success' => false, 'message' => 'Falta id'], 400);
        }

        $client = Client::where('id', $id);
        if (!$client) {
            JsonResponse::send(['success' => false, 'message' => 'Cliente no encontrado'], 404);
        }
        // Si es objeto:
        unset($client->password, $client->created_at, $client->updated_at, $client->admin);

        JsonResponse::send([
            'success' => true,
            'message' => 'Cliente encontrado',
            'data' => $client,
        ], 200); // ✅ 200 en GET
    }

    /* =======================
       POST /api/clients  (crear)
       ======================= */
    public static function store(): void
    {
        header('Access-Control-Allow-Origin: http://localhost:3000'); // tu front
        header('Access-Control-Allow-Credentials: true');
        header('Access-Control-Allow-Headers: Authorization, Content-Type');
        header('Access-Control-Allow-Methods:POST');
        header('Allow: POST');
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $payload = self::readBody();
            $client  = new Client($payload);

            // Unicidad de email
            try {
                if (Client::where('email', $client->email)) {
                    JsonResponse::send(['success' => false, 'errors' => ['El correo ya está en uso.']], 422);
                }
            } catch (Throwable $e) {
                JsonResponse::send(['success' => false, 'message' => 'Error de base de datos (unicidad email)'], 500);
            }

            // Validación de creación (incluye password)
            $errors = ClientValidator::validateCreate($client);
            if ($errors) {
                JsonResponse::send(['success' => false, 'errors' => $errors], 422);
            }

            // Hash + timestamps
            $client->hashearPassword();
            $client->created_at = Clock::today();   // o Clock::nowMs() según tu schema
            $client->updated_at = Clock::today();

            // Guardar
            try {
                $result = $client->guardar();
            } catch (Throwable $e) {
                JsonResponse::send(['success' => false, 'message' => 'Error al guardar el cliente'], 500);
            }

            if (empty($result['resultado'])) {
                JsonResponse::send(['success' => false, 'message' => 'No se pudo crear el cliente'], 500);
            }

            JsonResponse::send([
                'success' => true,
                'message' => 'Cliente creado correctamente',
                'data' => [
                    'id'         => $result['id'] ?? null,
                    'first_name' => $client->first_name,
                    'last_name' => $client->first_name,
                    'email'      => $client->email,
                    'phone'      => $client->phone,
                    'created_at' => $client->created_at,
                ]
            ], 201);
        }
    }

    /* ==========================================
       PUT/PATCH /api/clients/me  (editar perfil)
       Opcional: también acepta POST + _method=PUT
       ========================================== */
    public static function update(): void
    {
        header('Access-Control-Allow-Origin: http://localhost:3000');
        header('Access-Control-Allow-Credentials: true');
        header('Access-Control-Allow-Headers: Authorization, Content-Type, X-Requested-With');
        header('Access-Control-Allow-Methods: POST, OPTIONS');
        header('Content-Type: application/json; charset=utf-8');

        if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
            http_response_code(204);
            exit;
        }
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            JsonResponse::send(['success' => false, 'message' => 'Method Not Allowed'], 405);
        }

        $payload = self::readBody();
        $id = $payload['id'] ?? null;
        if (!$id) {
            JsonResponse::send(['success' => false, 'message' => 'Falta id'], 400);
        }

        $client = Client::find($id);
        if (!$client) {
            JsonResponse::send(['success' => false, 'message' => 'Cliente no encontrado'], 404);
        }

        // unicidad de email (sólo si viene y cambió)
        if (!empty($payload['email']) && $payload['email'] !== $client->email) {
            $emailExist = Client::where('email', $payload['email']);
            if ($emailExist && (int)$emailExist->id !== (int)$client->id) {
                JsonResponse::send(['success' => false, 'errors' => ['El correo ya está en uso.']], 422);
            }
        }

        // asignaciones (sólo si vienen)
        foreach (['first_name', 'last_name', 'email', 'phone', 'born_date'] as $k) {
            if (array_key_exists($k, $payload)) {
                $client->$k = trim((string)$payload[$k]);
            }
        }

        // password opcional + hash
        if (!empty($payload['password'])) {
            $client->password = password_hash($payload['password'], PASSWORD_BCRYPT);
        }

        // allergies: acepta array o string JSON; guarda como string JSON
        if (array_key_exists('allergies', $payload)) {
            $all = $payload['allergies'];
            $client->allergies = is_array($all)
                ? json_encode($all, JSON_UNESCAPED_UNICODE)
                : (string)$all;
        }

        $client->updated_at = Clock::today();

        // validación de edición
        $errors = ClientValidator::validateUpdate($client);
        if ($errors) {
            JsonResponse::send(['success' => false, 'errors' => $errors], 422);
        }

        try {
            $result = $client->guardar();
        } catch (Throwable $e) {
            JsonResponse::send(['success' => false, 'message' => 'Error al actualizar el cliente'], 500);
        }

        if (empty($result['resultado'])) {
            JsonResponse::send(['success' => false, 'message' => 'No se pudo actualizar el cliente'], 500); // 👈 mensaje corregido
        }

        // respuesta sanitizada (sin password/admin/created_at/updated_at) y allergies como array
        $all = $client->allergies ?? '[]';
        if (is_string($all)) {
            $all = json_decode($all, true) ?: [];
        }

        JsonResponse::send([
            'success' => true,
            'message' => 'Cliente actualizado',
            'data' => [
                'id'         => (int)$client->id,
                'first_name' => $client->first_name,
                'last_name'  => $client->last_name,
                'email'      => $client->email,
                'phone'      => $client->phone,
                'born_date'  => $client->born_date,
                'allergies'  => $all, // 👈 array
            ],
        ], 200);
    }


    /* ==================================
       DELETE /api/clients/me  (auto-borrado)
       o POST + _method=DELETE
       ================================== */
    public static function destroy(): void
    {
        header('Access-Control-Allow-Origin: http://localhost:3000'); // tu front
        header('Access-Control-Allow-Credentials: true');
        header('Access-Control-Allow-Headers: Authorization, Content-Type');
        header('Access-Control-Allow-Methods:POST');
        header('Allow: POST');
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {


            // Leer body JSON o FormData
            $payload = self::readBody();
            $id = $payload['id'] ?? null;


            if (!$id) {
                JsonResponse::send(['success' => false, 'message' => 'Falta id del cliente'], 400);
            }

            try {
                $client = Client::find($id);
            } catch (Throwable $e) {
                JsonResponse::send(['success' => false, 'message' => 'Error al buscar usuario'], 500);
            }

            if (!$client) {
                JsonResponse::send(['success' => false, 'message' => 'Usuario no encontrado'], 404);
            }

            try {
                $ok = $client->eliminar();
            } catch (Throwable $e) {
                JsonResponse::send(['success' => false, 'message' => 'Error al eliminar la cuenta'], 500);
            }

            if (!$ok) {
                JsonResponse::send(['success' => false, 'message' => 'No se pudo eliminar la cuenta'], 500);
            }

            JsonResponse::send([
                'success' => true,
                'message' => 'Cuenta eliminada correctamente.',
                'must_reauth' => true
            ], 200);
        } else {
            JsonResponse::send(['success' => false, 'message' => 'Método no permitido'], 405);
        }
    }
}
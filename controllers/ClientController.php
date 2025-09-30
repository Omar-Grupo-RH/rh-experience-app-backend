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
       POST /api/clients  (crear)
       ======================= */
    public static function store(): void
    {
        header('Access-Control-Allow-Origin: http://localhost:3000'); // tu front
        header('Access-Control-Allow-Credentials: true');
        header('Access-Control-Allow-Headers: Authorization, Content-Type');
        header('Access-Control-Allow-Methods: GET, POST, PUT, PATCH, DELETE, OPTIONS');
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
                    'last_name'  => $client->last_name,
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

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            // ID desde el JWT (el usuario solo puede editarse a sí mismo)
            $authUserId = JwtService::requireUserId();
            //$authUserId = 4;

            // Cargar registro
            try {
                $current = Client::find($authUserId);
            } catch (Throwable $e) {
                JsonResponse::send(['success' => false, 'message' => 'Error al buscar el cliente'], 500);
            }
            if (!$current) {
                JsonResponse::send(['success' => false, 'message' => 'Cliente no encontrado'], 404);
            }

            // Body y campos permitidos
            $payload = self::readBody();
            $data    = self::pick($payload, ['first_name', 'last_name', 'email', 'phone']);
            if (empty($data)) {
                JsonResponse::send(['success' => false, 'message' => 'No hay campos válidos para actualizar'], 400);
            }

            // Aplicar cambios + validar
            $current->sincronizar($data);
            $errors = ClientValidator::validateUpdate($current);
            if ($errors) {
                JsonResponse::send(['success' => false, 'errors' => $errors], 422);
            }

            // Unicidad de email (excluyendo mi propio id)
            try {
                $existe = Client::where('email', $current->email);
                if ($existe && (int)$existe->id !== (int)$current->id) {
                    JsonResponse::send(['success' => false, 'errors' => ['El correo ya está en uso por otro usuario']], 422);
                }
            } catch (Throwable $e) {
                JsonResponse::send(['success' => false, 'message' => 'Error de base de datos (unicidad email)'], 500);
            }

            // Timestamp y guardar
            $current->updated_at = Clock::today(); // o Clock::nowMs()
            try {
                $result = $current->guardar();
            } catch (Throwable $e) {
                JsonResponse::send(['success' => false, 'message' => 'Error al actualizar'], 500);
            }

            if (empty($result['resultado'])) {
                JsonResponse::send(['success' => false, 'message' => 'No se pudo actualizar', 'debug' => $result['error'] ?? null], 500);
            }

            // Forzar reautenticación (cerrar sesión / borrar cookies JWT)
            LogoutService::run();

            JsonResponse::send([
                'success'     => true,
                'message'     => 'Cliente actualizado. Debe iniciar sesión nuevamente.',
                'must_reauth' => true,
                'data'        => [
                    'id'         => (int)$current->id,
                    'first_name' => $current->first_name,
                    'last_name'  => $current->last_name,
                    'email'      => $current->email,
                    'phone'      => $current->phone,
                    'updated_at' => $current->updated_at,
                ]
            ], 200);
        }
    }

    /* ==================================
       DELETE /api/clients/me  (auto-borrado)
       o POST + _method=DELETE
       ================================== */
    public static function destroy(): void
    {



        if ($_SERVER['REQUEST_METHOD'] === 'POST' || $_SERVER['REQUEST_METHOD'] === 'DELETE') {
            header('Allow: DELETE, POST');
            // Usuario autenticado desde JWT
            $authUserId = JwtService::requireUserId();
            //$authUserId = '4';

            // Buscar y eliminar
            try {
                $client = Client::find($authUserId);
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

            // Forzar logout
            LogoutService::run();

            JsonResponse::send([
                'success' => true,
                'message' => 'Cuenta eliminada correctamente. Debe iniciar sesión si desea continuar.',
                'must_reauth' => true
            ], 200);
        } else {
            JsonResponse::send(['success' => false, 'message' => 'Método no permitido'], 405);
        }
    }
}
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
}

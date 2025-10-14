<?php

namespace Controllers;

use App\Http\JsonResponse;
use Model\Trip;

class TripController
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

    public static function index()
    {
        header('Access-Control-Allow-Origin: http://localhost:3000'); // tu front
        header('Access-Control-Allow-Credentials: true');
        header('Access-Control-Allow-Headers: Authorization, Content-Type');
        header('Access-Control-Allow-Methods:GET');
        header('Allow: GET');

        $trips = Trip::all();

        JsonResponse::send([
            'success' => true,
            'data' => $trips
        ], 201);
    }


    public static function show()
    {
        header('Access-Control-Allow-Origin: http://localhost:3000'); // tu front
        header('Access-Control-Allow-Credentials: true');
        header('Access-Control-Allow-Headers: Authorization, Content-Type');
        header('Access-Control-Allow-Methods:GET');
        header('Allow: GET');



        $id = $_GET['id'] ?? null; // si usas query param
        if (!$id) {
            JsonResponse::send(['success' => false, 'message' => 'Falta id'], 400);
        }

        $trip = Trip::where('id', $id);
        if (!$trip) {
            JsonResponse::send(['success' => false, 'message' => 'Evento no encontrado'], 404);
        }

        JsonResponse::send([
            'success' => true,
            'message' => 'Cliente encontrado',
            'data' => $trip,
        ], 200); // ✅ 200 en GET
    }

    public static function store()
    {
        header('Access-Control-Allow-Origin: http://localhost:3000'); // tu front
        header('Access-Control-Allow-Credentials: true');
        header('Access-Control-Allow-Headers: Authorization, Content-Type');
        header('Access-Control-Allow-Methods:POST');
        header('Allow: POST');
        // Lógica para obtener todos los clientes
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $payload = self::readBody();
            $trip = new Trip($payload);

            //verificar que no existe un trip agendado previamente
            $existTrip = Trip::where('trip_date', $trip->trip_date);

            if ($existTrip) {
                JsonResponse::send(['errors' => 'Ya hay un RH EXPERIENCE agendado esa fecha.'], 422);
                exit;
            }

            // Guardar
            try {
                $result = $trip->guardar();
            } catch (Throwable $e) {
                JsonResponse::send(['success' => false, 'message' => 'Error al agendar el RH EXPERIENCE'], 500);
            }
            if (empty($result['resultado'])) {
                JsonResponse::send(['success' => false, 'message' => 'No se pudo agendar el RH EXPERIENCE'], 500);
            }
            // id del insert
            $trip->id = $result['id'] ?? $trip->id;

            // ✅ Devuelve el registro creado
            JsonResponse::send([
                'success' => true,
                'message' => 'RH EXPERIENCE agendado correctamente',
                'data' => [
                    'id'         => (int)$trip->id,
                    'title'      => $trip->title,
                    'trip_date'  => $trip->trip_date, // para allDay, formato YYYY-MM-DD
                    'total_seat' => (int)$trip->total_seat,
                ],
            ], 201);
        }
    }



    public static function destroy(): void
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

        try {
            $trip = Trip::find($id);
        } catch (\Throwable $e) {
            JsonResponse::send(['success' => false, 'message' => 'Error al buscar el evento'], 500);
        }

        if (!$trip) {
            JsonResponse::send(['success' => false, 'message' => 'Evento no encontrado'], 404);
        }

        try {
            $ok = $trip->eliminar();               // 👈 tu método de modelo que hace DELETE
        } catch (\Throwable $e) {
            JsonResponse::send(['success' => false, 'message' => 'Error al eliminar el evento'], 500);
        }

        if (!$ok) {
            JsonResponse::send(['success' => false, 'message' => 'No se pudo eliminar el evento'], 500);
        }

        JsonResponse::send([
            'success' => true,
            'message' => 'RH EXPERIENCE eliminado correctamente',
        ], 200); // 👈 200, no 201
    }
}
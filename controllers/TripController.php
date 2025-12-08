<?php

namespace Controllers;

use App\Http\JsonResponse;
use App\Time\Clock;
use Model\Ticket;
use Model\Trip;
use Throwable;

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
        $trips = Trip::all(); // Traer todos los viajes
        $response = [];



        // Enviar respuesta JSON
        JsonResponse::send([
            'success' => true,
            'data'    => $trips,
        ], 200);
    }

    public static function show()
    {
        $id = $_GET['id'] ?? null;
        if (!$id) {
            JsonResponse::send(['success' => false, 'message' => 'Falta id'], 400);
        }

        $trip = Trip::where('id', $id);
        if (!$trip) {
            JsonResponse::send(['success' => false, 'message' => 'Evento no encontrado'], 404);
        }

        // Traer todos los tickets de este viaje
        $ticketsByTrip = Ticket::whereAll('trip_id', $id);

        // Contar todos los asientos ocupados y acumular posiciones
        $unavaibleSeats = 0;
        $seatsLocation = [];

        foreach ($ticketsByTrip as $ticket) {
            $seatNumbersArray = json_decode($ticket->seat_numbers, true) ?? [];

            $seatsLocation = array_merge($seatsLocation, $seatNumbersArray);
        }

        JsonResponse::send([
            'success' => true,
            'data' => [
                'trip_id'         => (int) $trip->id,
                'title'           => $trip->title,
                'trip_date'       => $trip->trip_date,
                'total_seats'     => (int) $trip->total_seats,
                'seats_location'  => $seatsLocation, // array con posiciones ocupadas
            ],
        ], 200);
    }

    public static function store()
    {

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
                    'trip_date'  => $trip->trip_date,
                    'total_seats' => (int)$trip->total_seats,
                    'avaible_seats' => (int)$trip->avaible_seats,
                ],
            ], 201);
        }
    }

    public static function update(): void
    {
        if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
            http_response_code(204);
            exit;
        }
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

        $trip = Trip::find($id);
        if (!$trip) {
            JsonResponse::send(['success' => false, 'message' => 'Tour no encontrado'], 404);
            exit;
        }
        $today = Clock::today();

        if ($payload['trip_date'] < $today) {
            JsonResponse::send(['success' => false, 'message' => 'No puedes reagendar el tour a una fecha pasada.'], 400);
            exit;
        }

        if (Trip::where('trip_date', $payload['trip_date'])) {
            JsonResponse::send(['success' => false, 'message' => 'Ya existe un RH EXPERIENCE agendado en esta fecha.'], 400);
            exit;
        }

        $trip->trip_date = $payload['trip_date'];

        try {
            $result = $trip->guardar();
        } catch (Throwable $e) {
            JsonResponse::send(['success' => false, 'message' => 'Error al reagendar el RH EXPERIENCE'], 500);
            exit;
        }
        if (empty($result['resultado'])) {
            JsonResponse::send(['success' => false, 'message' => 'No se pudo reagendar el RH EXPERIENCE'], 500);
            exit;
        }


        JsonResponse::send([
            'success' => true,
            'message' => 'RH EXPERIENCE reagendado correctamente',
            'data' => $trip,

        ], 200);
    }


    public static function destroy(): void
    {


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

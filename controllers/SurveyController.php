<?php

namespace Controllers;

use App\Http\JsonResponse;
use Classes\SurveyEmail;
use Model\ActiveRecord;
use Model\SurveyResponse;

class SurveyController
{
    public static function sendPendingSurveys()
    {
        $secret = $_GET['secret'] ?? '';

        if (!self::isValidSecret($secret)) {
            JsonResponse::send([
                'success' => false,
                'message' => 'No autorizado.',
            ], 401);
            return;
        }

        try {
            $dateToCheck = $_GET['date'] ?? null;
            

            if ($dateToCheck && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateToCheck)) {
                JsonResponse::send([
                    'success' => false,
                    'message' => 'La fecha debe tener formato YYYY-MM-DD.',
                ], 422);
                return;
            }

            if (!$dateToCheck) {
                $dateToCheck = (new \DateTime('yesterday', new \DateTimeZone('America/Merida')))
                    ->format('Y-m-d');
            }

            $trips = self::getTripsFromDate($dateToCheck);

            $sentCount = 0;
            $skippedCount = 0;

            foreach ($trips as $trip) {
                $passengers = self::getPassengersByTrip((int) $trip['id']);

                foreach ($passengers as $passenger) {
                    $email = trim($passenger['email'] ?? '');

                    if (!$email || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                        $skippedCount++;
                        continue;
                    }

                    if (self::surveyAlreadySent((int) $trip['id'], $email)) {
                        $skippedCount++;
                        continue;
                    }

                    $fullName = trim(
                        ($passenger['first_name'] ?? '') . ' ' . ($passenger['last_name'] ?? '')
                    );

                    $surveyUrl = self::buildSurveyUrl($trip, $passenger);

                    $surveyEmail = new SurveyEmail(
                        $email,
                        $fullName ?: 'Cliente',
                        $trip['title'] ?? 'RH Experience',
                        $trip['trip_date'] ?? '',
                        $surveyUrl
                    );

                    $wasSent = $surveyEmail->send();

                    if ($wasSent) {
                        self::registerSurveyEmail(
                            (int) $trip['id'],
                            $email,
                            $fullName
                        );

                        $sentCount++;
                    } else {
                        $skippedCount++;
                    }
                }

                self::markTripSurveyAsSent((int) $trip['id']);
            }

            JsonResponse::send([
                'success' => true,
                'message' => 'Proceso de encuestas ejecutado correctamente.',
                'trips_processed' => count($trips),
                'sent' => $sentCount,
                'skipped' => $skippedCount,
                'date_checked' => $dateToCheck,
            ], 200);
            return;
        } catch (\Throwable $e) {
            error_log('Error enviando encuestas pendientes: ' . $e->getMessage());

            JsonResponse::send([
                'success' => false,
                'message' => 'Ocurrió un error al enviar encuestas.',
            ], 500);
            return;
        }
    }

    public static function show()
    {
        $tripId = $_GET['trip_id'] ?? null;
        $email = $_GET['email'] ?? null;
        $ticketId = $_GET['ticket_id'] ?? null;

        if (!$tripId || !$email || !$ticketId) {
            JsonResponse::send([
                'success' => false,
                'message' => 'El enlace de la encuesta no es válido.',
            ], 422);
            return;
        }

        $db = ActiveRecord::getDB();

        try {
            $query = "
                SELECT 
                    tp.first_name,
                    tp.last_name,
                    tp.email,
                    tp.ticket_id,
                    tp.trip_id,
                    t.title AS trip_title,
                    t.trip_date
                FROM ticket_passengers tp
                INNER JOIN trips t
                    ON t.id = tp.trip_id
                WHERE tp.trip_id = ?
                AND tp.ticket_id = ?
                AND tp.email = ?
                LIMIT 1
            ";

            $stmt = $db->prepare($query);

            if (!$stmt) {
                throw new \Exception('Error preparando consulta de encuesta: ' . $db->error);
            }

            $tripIdInt = (int) $tripId;
            $ticketIdInt = (int) $ticketId;

            $stmt->bind_param('iis', $tripIdInt, $ticketIdInt, $email);
            $stmt->execute();

            $result = $stmt->get_result();
            $row = $result->fetch_assoc();

            if (!$row) {
                JsonResponse::send([
                    'success' => false,
                    'message' => 'No se encontró información para esta encuesta.',
                ], 404);
                return;
            }

            $alreadyAnswered = SurveyResponse::findByTripTicketEmail(
                $tripIdInt,
                $ticketIdInt,
                $email
            ) !== null;

            JsonResponse::send([
                'success' => true,
                'already_answered' => $alreadyAnswered,
                'data' => [
                    'passenger_name' => trim($row['first_name'] . ' ' . $row['last_name']),
                    'email' => $row['email'],
                    'trip_id' => (int) $row['trip_id'],
                    'ticket_id' => (int) $row['ticket_id'],
                    'trip_title' => $row['trip_title'],
                    'trip_date' => $row['trip_date'],
                ],
            ], 200);
            return;
        } catch (\Throwable $e) {
            error_log('Error mostrando encuesta: ' . $e->getMessage());

            JsonResponse::send([
                'success' => false,
                'message' => 'Ocurrió un error al cargar la encuesta.',
            ], 500);
            return;
        }
    }

    public static function store()
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            JsonResponse::send([
                'success' => false,
                'message' => 'Método no permitido.',
            ], 405);
            return;
        }

        $db = ActiveRecord::getDB();

        try {
            $payload = self::readBody();

            $tripId = (int) ($payload['trip_id'] ?? 0);
            $ticketId = (int) ($payload['ticket_id'] ?? 0);
            $email = trim($payload['email'] ?? '');
            $answers = $payload['answers'] ?? [];

            if (!$tripId || !$ticketId || !$email || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                JsonResponse::send([
                    'success' => false,
                    'message' => 'Datos de encuesta inválidos.',
                ], 422);
                return;
            }

            if (!is_array($answers) || empty($answers)) {
                JsonResponse::send([
                    'success' => false,
                    'message' => 'No se recibieron respuestas.',
                ], 422);
                return;
            }

            self::validateSurveyAnswers($answers);

            $surveyInfo = self::getSurveyInfo($tripId, $ticketId, $email);

            if (!$surveyInfo) {
                JsonResponse::send([
                    'success' => false,
                    'message' => 'No se encontró información válida para guardar esta encuesta.',
                ], 404);
                return;
            }

            if (SurveyResponse::findByTripTicketEmail($tripId, $ticketId, $email)) {
                JsonResponse::send([
                    'success' => false,
                    'message' => 'Esta encuesta ya fue respondida.',
                ], 409);
                return;
            }

            $answersJson = json_encode($answers, JSON_UNESCAPED_UNICODE);

            $query = "
                INSERT INTO survey_responses (
                    trip_id,
                    ticket_id,
                    email,
                    passenger_name,
                    trip_title,
                    trip_date,
                    answers_json,
                    created_at
                ) VALUES (?, ?, ?, ?, ?, ?, ?, NOW())
            ";

            $stmt = $db->prepare($query);

            if (!$stmt) {
                throw new \Exception('Error preparando guardado de encuesta: ' . $db->error);
            }

            $passengerName = trim(
                ($surveyInfo['first_name'] ?? '') . ' ' . ($surveyInfo['last_name'] ?? '')
            );

            $tripTitle = $surveyInfo['trip_title'] ?? '';
            $tripDate = $surveyInfo['trip_date'] ?? null;

            $stmt->bind_param(
                'iisssss',
                $tripId,
                $ticketId,
                $email,
                $passengerName,
                $tripTitle,
                $tripDate,
                $answersJson
            );

            $stmt->execute();

            JsonResponse::send([
                'success' => true,
                'message' => 'Encuesta guardada correctamente.',
            ], 201);
            return;
        } catch (\mysqli_sql_exception $e) {
            if ((int) $e->getCode() === 1062) {
                JsonResponse::send([
                    'success' => false,
                    'message' => 'Esta encuesta ya fue respondida.',
                ], 409);
                return;
            }

            error_log('MySQL error guardando encuesta: ' . $e->getMessage());

            JsonResponse::send([
                'success' => false,
                'message' => 'Ocurrió un error al guardar la encuesta.',
            ], 500);
            return;
        } catch (\InvalidArgumentException $e) {
            JsonResponse::send([
                'success' => false,
                'message' => $e->getMessage(),
            ], 422);
            return;
        } catch (\Throwable $e) {
            error_log('Error guardando encuesta: ' . $e->getMessage());

            JsonResponse::send([
                'success' => false,
                'message' => 'Ocurrió un error al guardar la encuesta.',
            ], 500);
            return;
        }
    }

    public static function adminIndex()
    {
        try {
            $tripId = isset($_GET['trip_id']) && $_GET['trip_id'] !== ''
                ? (int) $_GET['trip_id']
                : null;

            $responses = SurveyResponse::allByTrip($tripId);

            JsonResponse::send([
                'success' => true,
                'data' => $responses,
            ], 200);
            return;
        } catch (\Throwable $e) {
            error_log('Error listando respuestas de encuesta: ' . $e->getMessage());

            JsonResponse::send([
                'success' => false,
                'message' => 'Ocurrió un error al obtener las respuestas.',
            ], 500);
            return;
        }
    }

    public static function adminShow()
    {
        try {
            $id = $_GET['id'] ?? null;

            if (!$id) {
                JsonResponse::send([
                    'success' => false,
                    'message' => 'Falta el ID de la respuesta.',
                ], 422);
                return;
            }

            $response = SurveyResponse::findFull((int) $id);

            if (!$response) {
                JsonResponse::send([
                    'success' => false,
                    'message' => 'Respuesta no encontrada.',
                ], 404);
                return;
            }

            JsonResponse::send([
                'success' => true,
                'data' => $response,
            ], 200);
            return;
        } catch (\Throwable $e) {
            error_log('Error mostrando respuesta de encuesta: ' . $e->getMessage());

            JsonResponse::send([
                'success' => false,
                'message' => 'Ocurrió un error al obtener la respuesta.',
            ], 500);
            return;
        }
    }

    public static function adminStats()
    {
        try {
            $tripId = isset($_GET['trip_id']) && $_GET['trip_id'] !== ''
                ? (int) $_GET['trip_id']
                : null;

            $stats = SurveyResponse::statsByTrip($tripId);

            JsonResponse::send([
                'success' => true,
                'data' => $stats,
            ], 200);
            return;
        } catch (\Throwable $e) {
            error_log('Error obteniendo estadísticas de encuesta: ' . $e->getMessage());

            JsonResponse::send([
                'success' => false,
                'message' => 'Ocurrió un error al obtener estadísticas.',
            ], 500);
            return;
        }
    }

    private static function validateSurveyAnswers(array $answers): void
    {
        $required = [
            'church_experience_rating',
            'atv_experience_rating',
            'atv_security_rating',
            'atv_driving_easy',
            'beach_general_rating',
            'beach_client_ideal',
            'beach_recreation_rating',
            'beach_accessibility_rating',
            'beach_invite_client',
            'boat_trip_feeling',
            'boat_favorite_part',
            'boat_security_rating',
            'staff_before_info_rating',
            'staff_during_info_rating',
            'staff_driver_rating',
            'eco_environmental_education',
            'eco_passport_info',
            'eco_kit_rating',
            'eco_impact_experience',
            'final_general_tour_rating',
            'final_general_comment',
        ];

        foreach ($required as $field) {
            if (!isset($answers[$field]) || trim((string) $answers[$field]) === '') {
                throw new \InvalidArgumentException('Faltan respuestas obligatorias.');
            }
        }

        $scale10Fields = [
            'church_experience_rating',
            'atv_experience_rating',
        ];

        foreach ($scale10Fields as $field) {
            $value = (int) ($answers[$field] ?? 0);

            if ($value < 1 || $value > 10) {
                throw new \InvalidArgumentException('Una calificación del 1 al 10 no es válida.');
            }
        }

        $scale5Fields = [
            'beach_recreation_rating',
            'staff_before_info_rating',
            'staff_andrea_rating',
            'staff_jaqueline_rating',
            'staff_during_info_rating',
            'staff_driver_rating',
            'eco_kit_rating',
        ];

        foreach ($scale5Fields as $field) {
            if (!isset($answers[$field]) || trim((string) $answers[$field]) === '') {
                continue;
            }

            $value = (int) $answers[$field];

            if ($value < 1 || $value > 5) {
                throw new \InvalidArgumentException('Una calificación del 1 al 5 no es válida.');
            }
        }
    }

    private static function getSurveyInfo(int $tripId, int $ticketId, string $email): ?array
    {
        $db = ActiveRecord::getDB();

        $query = "
            SELECT 
                tp.first_name,
                tp.last_name,
                tp.email,
                tp.ticket_id,
                tp.trip_id,
                t.title AS trip_title,
                t.trip_date
            FROM ticket_passengers tp
            INNER JOIN trips t
                ON t.id = tp.trip_id
            WHERE tp.trip_id = ?
            AND tp.ticket_id = ?
            AND tp.email = ?
            LIMIT 1
        ";

        $stmt = $db->prepare($query);

        if (!$stmt) {
            throw new \Exception('Error preparando información de encuesta: ' . $db->error);
        }

        $stmt->bind_param('iis', $tripId, $ticketId, $email);
        $stmt->execute();

        $result = $stmt->get_result();

        return $result->fetch_assoc() ?: null;
    }

    private static function isValidSecret(string $secret): bool
    {
        $expectedSecret = $_ENV['SURVEY_CRON_SECRET'] ?? '';

        if (!$expectedSecret) {
            error_log('SURVEY_CRON_SECRET no configurado.');
            return false;
        }

        return hash_equals($expectedSecret, $secret);
    }

    private static function getTripsFromDate(string $date): array
    {
        $db = ActiveRecord::getDB();

        $query = "
            SELECT id, title, trip_date
            FROM trips
            WHERE trip_date = ?
            AND survey_sent = 0
            ORDER BY id ASC
        ";

        $stmt = $db->prepare($query);

        if (!$stmt) {
            throw new \Exception('Error preparando consulta de viajes: ' . $db->error);
        }

        $stmt->bind_param('s', $date);
        $stmt->execute();

        $result = $stmt->get_result();

        $trips = [];

        while ($row = $result->fetch_assoc()) {
            $trips[] = $row;
        }

        return $trips;
    }

    private static function getPassengersByTrip(int $tripId): array
    {
        $db = ActiveRecord::getDB();

        $query = "
            SELECT DISTINCT
                email,
                first_name,
                last_name,
                ticket_id
            FROM ticket_passengers
            WHERE trip_id = ?
            AND email IS NOT NULL
            AND email != ''
        ";

        $stmt = $db->prepare($query);

        if (!$stmt) {
            throw new \Exception('Error preparando consulta de pasajeros: ' . $db->error);
        }

        $stmt->bind_param('i', $tripId);
        $stmt->execute();

        $result = $stmt->get_result();

        $passengers = [];

        while ($row = $result->fetch_assoc()) {
            $passengers[] = $row;
        }

        return $passengers;
    }

    private static function surveyAlreadySent(int $tripId, string $email): bool
    {
        $db = ActiveRecord::getDB();

        $query = "
            SELECT id
            FROM survey_emails
            WHERE trip_id = ?
            AND passenger_email = ?
            LIMIT 1
        ";

        $stmt = $db->prepare($query);

        if (!$stmt) {
            throw new \Exception('Error preparando consulta de historial: ' . $db->error);
        }

        $stmt->bind_param('is', $tripId, $email);
        $stmt->execute();

        $result = $stmt->get_result();

        return (bool) $result->fetch_assoc();
    }

    private static function registerSurveyEmail(
        int $tripId,
        string $email,
        string $passengerName
    ): void {
        $db = ActiveRecord::getDB();

        $query = "
            INSERT INTO survey_emails
                (trip_id, passenger_email, passenger_name, sent_at)
            VALUES
                (?, ?, ?, NOW())
            ON DUPLICATE KEY UPDATE
                passenger_email = passenger_email
        ";

        $stmt = $db->prepare($query);

        if (!$stmt) {
            throw new \Exception('Error preparando registro de encuesta: ' . $db->error);
        }

        $stmt->bind_param('iss', $tripId, $email, $passengerName);
        $stmt->execute();
    }

    private static function markTripSurveyAsSent(int $tripId): void
    {
        $db = ActiveRecord::getDB();

        $query = "
            UPDATE trips
            SET survey_sent = 1,
                survey_sent_at = NOW()
            WHERE id = ?
        ";

        $stmt = $db->prepare($query);

        if (!$stmt) {
            throw new \Exception('Error preparando actualización de viaje: ' . $db->error);
        }

        $stmt->bind_param('i', $tripId);
        $stmt->execute();
    }

    private static function buildSurveyUrl(array $trip, array $passenger): string
    {
        $baseUrl = $_ENV['SURVEY_URL'] ?? 'http://localhost:3000/app/encuesta';

        $params = [
            'trip_id' => $trip['id'] ?? '',
            'email' => $passenger['email'] ?? '',
            'ticket_id' => $passenger['ticket_id'] ?? '',
        ];

        return $baseUrl . '?' . http_build_query($params);
    }

    private static function readBody(): array
    {
        $payload = $_POST;

        if (empty($payload)) {
            $raw = file_get_contents('php://input');
            $decoded = json_decode($raw, true);

            if (!is_array($decoded)) {
                throw new \InvalidArgumentException('El cuerpo de la solicitud no es un JSON válido.');
            }

            $payload = $decoded;
        }

        return $payload;
    }
}
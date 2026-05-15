<?php

namespace Controllers;

use App\Http\JsonResponse;
use Model\ActiveRecord;
use Model\Trip;
use Model\TripPassenger;

class DashboardController
{
    private const PROSPECT_PRICE = 800;

    public static function index()
    {
        try {
            $days = isset($_GET['days']) ? (int) $_GET['days'] : 30;

            if ($days <= 0 || $days > 365) {
                $days = 30;
            }

            JsonResponse::send([
                'success' => true,
                'data' => [
                    'next_experience' => self::getNextExperience(),
                    'summary' => self::getSummary($days),
                    'signups_trend' => self::getSignupsTrend($days),
                    'revenue_trend' => self::getRevenueTrend($days),
                    'registration_type_distribution' => self::getRegistrationTypeDistribution($days),
                    'satisfaction' => self::getSatisfactionStats($days),
                    'recent_reservations' => self::getRecentReservations(),
                    'recent_survey_responses' => self::getRecentSurveyResponses(),
                ],
            ], 200);

            return;
        } catch (\Throwable $e) {
            error_log('Dashboard error: ' . $e->getMessage());

            JsonResponse::send([
                'success' => false,
                'message' => 'Ocurrió un error al cargar el dashboard.',
            ], 500);

            return;
        }
    }

    private static function getNextExperience(): ?array
    {
        $db = ActiveRecord::getDB();

        $query = "
            SELECT 
                t.id,
                t.title,
                t.trip_date,
                t.total_seats AS available_seats,
                COUNT(tp.id) AS registered_passengers,
                SUM(
                    CASE 
                        WHEN rr.registration_type = 'prospect' THEN 1 
                        ELSE 0 
                    END
                ) AS prospect_passengers,
                SUM(
                    CASE 
                        WHEN rr.registration_type = 'prospect' THEN ? 
                        ELSE 0 
                    END
                ) AS estimated_revenue
            FROM trips t
            LEFT JOIN ticket_passengers tp
                ON tp.trip_id = t.id
            LEFT JOIN reservation_registrations rr
                ON rr.ticket_passenger_id = tp.id
            WHERE t.trip_date >= CURDATE()
            GROUP BY t.id, t.title, t.trip_date, t.total_seats
            ORDER BY t.trip_date ASC
            LIMIT 1
        ";

        $price = self::PROSPECT_PRICE;

        $stmt = $db->prepare($query);

        if (!$stmt) {
            throw new \Exception('Error preparando próxima experiencia: ' . $db->error);
        }

        $stmt->bind_param('i', $price);
        $stmt->execute();

        $result = $stmt->get_result();
        $row = $result->fetch_assoc();

        if (!$row) {
            return null;
        }

        $registered = (int) ($row['registered_passengers'] ?? 0);
        $available = (int) ($row['available_seats'] ?? 0);
        $capacity = $registered + $available;

        return [
            'id' => (int) $row['id'],
            'title' => $row['title'],
            'trip_date' => $row['trip_date'],
            'registered_passengers' => $registered,
            'available_seats' => $available,
            'total_capacity' => $capacity,
            'occupancy_percentage' => $capacity > 0
                ? round(($registered / $capacity) * 100, 2)
                : 0,
            'prospect_passengers' => (int) ($row['prospect_passengers'] ?? 0),
            'estimated_revenue' => (float) ($row['estimated_revenue'] ?? 0),
        ];
    }

    private static function getSummary(int $days): array
    {
        $db = ActiveRecord::getDB();
        $price = self::PROSPECT_PRICE;

        $query = "
            SELECT
                COUNT(tp.id) AS total_passengers,
                COUNT(DISTINCT tp.ticket_id) AS total_reservations,
                SUM(
                    CASE 
                        WHEN rr.registration_type = 'prospect' THEN 1 
                        ELSE 0 
                    END
                ) AS prospect_passengers,
                SUM(
                    CASE 
                        WHEN rr.registration_type = 'prospect' THEN ?
                        ELSE 0 
                    END
                ) AS estimated_revenue
            FROM ticket_passengers tp
            LEFT JOIN reservation_registrations rr
                ON rr.ticket_passenger_id = tp.id
            WHERE tp.created_at >= DATE_SUB(CURDATE(), INTERVAL {$days} DAY)
        ";

        $stmt = $db->prepare($query);

        if (!$stmt) {
            throw new \Exception('Error preparando resumen: ' . $db->error);
        }

        $stmt->bind_param('i', $price);
        $stmt->execute();

        $result = $stmt->get_result();
        $row = $result->fetch_assoc() ?: [];

        return [
            'days' => $days,
            'total_passengers' => (int) ($row['total_passengers'] ?? 0),
            'total_reservations' => (int) ($row['total_reservations'] ?? 0),
            'prospect_passengers' => (int) ($row['prospect_passengers'] ?? 0),
            'estimated_revenue' => (float) ($row['estimated_revenue'] ?? 0),
            'prospect_price' => self::PROSPECT_PRICE,
        ];
    }

    private static function getSignupsTrend(int $days): array
    {
        $db = ActiveRecord::getDB();

        $query = "
            SELECT
                tp.created_at AS date,
                COUNT(tp.id) AS passengers,
                COUNT(DISTINCT tp.ticket_id) AS reservations,
                SUM(
                    CASE 
                        WHEN rr.registration_type = 'prospect' THEN 1 
                        ELSE 0 
                    END
                ) AS prospects
            FROM ticket_passengers tp
            LEFT JOIN reservation_registrations rr
                ON rr.ticket_passenger_id = tp.id
            WHERE tp.created_at >= DATE_SUB(CURDATE(), INTERVAL {$days} DAY)
            GROUP BY tp.created_at
            ORDER BY tp.created_at ASC
        ";

        $result = $db->query($query);

        if (!$result) {
            throw new \Exception('Error preparando tendencia: ' . $db->error);
        }

        $items = [];

        while ($row = $result->fetch_assoc()) {
            $items[] = [
                'date' => $row['date'],
                'passengers' => (int) $row['passengers'],
                'reservations' => (int) $row['reservations'],
                'prospects' => (int) $row['prospects'],
            ];
        }

        return $items;
    }

    private static function getRevenueTrend(int $days): array
    {
        $db = ActiveRecord::getDB();
        $price = self::PROSPECT_PRICE;

        $query = "
            SELECT
                tp.created_at AS date,
                SUM(
                    CASE 
                        WHEN rr.registration_type = 'prospect' THEN 1 
                        ELSE 0 
                    END
                ) AS prospect_passengers,
                SUM(
                    CASE 
                        WHEN rr.registration_type = 'prospect' THEN ?
                        ELSE 0 
                    END
                ) AS estimated_revenue
            FROM ticket_passengers tp
            LEFT JOIN reservation_registrations rr
                ON rr.ticket_passenger_id = tp.id
            WHERE tp.created_at >= DATE_SUB(CURDATE(), INTERVAL {$days} DAY)
            GROUP BY tp.created_at
            ORDER BY tp.created_at ASC
        ";

        $stmt = $db->prepare($query);

        if (!$stmt) {
            throw new \Exception('Error preparando ingresos por día: ' . $db->error);
        }

        $stmt->bind_param('i', $price);
        $stmt->execute();

        $result = $stmt->get_result();

        $items = [];

        while ($row = $result->fetch_assoc()) {
            $items[] = [
                'date' => $row['date'],
                'prospect_passengers' => (int) ($row['prospect_passengers'] ?? 0),
                'estimated_revenue' => (float) ($row['estimated_revenue'] ?? 0),
            ];
        }

        return $items;
    }

    private static function getRegistrationTypeDistribution(int $days): array
    {
        $db = ActiveRecord::getDB();

        $query = "
            SELECT
                COALESCE(rr.registration_type, 'sin_tipo') AS registration_type,
                COUNT(tp.id) AS total
            FROM ticket_passengers tp
            LEFT JOIN reservation_registrations rr
                ON rr.ticket_passenger_id = tp.id
            WHERE tp.created_at >= DATE_SUB(CURDATE(), INTERVAL {$days} DAY)
            GROUP BY rr.registration_type
            ORDER BY total DESC
        ";

        $result = $db->query($query);

        if (!$result) {
            throw new \Exception('Error obteniendo distribución: ' . $db->error);
        }

        $items = [];

        while ($row = $result->fetch_assoc()) {
            $items[] = [
                'registration_type' => $row['registration_type'],
                'total' => (int) $row['total'],
            ];
        }

        return $items;
    }

    private static function getSatisfactionStats(int $days): array
    {
        $db = ActiveRecord::getDB();

        $query = "
            SELECT
                id,
                trip_id,
                trip_title,
                trip_date,
                answers_json,
                created_at
            FROM survey_responses
            WHERE created_at >= DATE_SUB(NOW(), INTERVAL {$days} DAY)
            ORDER BY created_at DESC
        ";

        $result = $db->query($query);

        if (!$result) {
            throw new \Exception('Error obteniendo satisfacción: ' . $db->error);
        }

        $scoreMap = [
            'Muy bueno' => 5,
            'Bueno' => 4,
            'Regular' => 3,
            'Malo' => 2,
            'Muy malo' => 1,
        ];

        $distribution = [
            'Muy bueno' => 0,
            'Bueno' => 0,
            'Regular' => 0,
            'Malo' => 0,
            'Muy malo' => 0,
        ];

        $totalResponses = 0;
        $ratedResponses = 0;
        $scoreSum = 0;
        $byTrip = [];

        while ($row = $result->fetch_assoc()) {
            $totalResponses++;

            $answers = json_decode($row['answers_json'] ?? '{}', true) ?: [];

            $generalRating = $answers['final_general_tour_rating'] ?? null;

            if (!$generalRating || !isset($scoreMap[$generalRating])) {
                continue;
            }

            $score = $scoreMap[$generalRating];

            $distribution[$generalRating]++;
            $ratedResponses++;
            $scoreSum += $score;

            $tripId = (int) $row['trip_id'];

            if (!isset($byTrip[$tripId])) {
                $byTrip[$tripId] = [
                    'trip_id' => $tripId,
                    'trip_title' => $row['trip_title'],
                    'trip_date' => $row['trip_date'],
                    'responses' => 0,
                    'score_sum' => 0,
                    'average_score' => 0,
                    'satisfaction_percentage' => 0,
                ];
            }

            $byTrip[$tripId]['responses']++;
            $byTrip[$tripId]['score_sum'] += $score;
        }

        foreach ($byTrip as &$trip) {
            $trip['average_score'] = $trip['responses'] > 0
                ? round($trip['score_sum'] / $trip['responses'], 2)
                : 0;

            $trip['satisfaction_percentage'] = round(($trip['average_score'] / 5) * 100, 2);

            unset($trip['score_sum']);
        }

        $averageScore = $ratedResponses > 0
            ? round($scoreSum / $ratedResponses, 2)
            : 0;

        return [
            'total_responses' => $totalResponses,
            'rated_responses' => $ratedResponses,
            'average_score' => $averageScore,
            'satisfaction_percentage' => round(($averageScore / 5) * 100, 2),
            'distribution' => $distribution,
            'by_trip' => array_values($byTrip),
        ];
    }

    private static function getRecentReservations(): array
    {
        $db = ActiveRecord::getDB();

        $query = "
            SELECT
                tp.id,
                tp.first_name,
                tp.last_name,
                tp.email,
                tp.seat_id,
                tp.created_at,
                t.title AS trip_title,
                t.trip_date,
                rr.registration_type
            FROM ticket_passengers tp
            INNER JOIN trips t
                ON t.id = tp.trip_id
            LEFT JOIN reservation_registrations rr
                ON rr.ticket_passenger_id = tp.id
            ORDER BY tp.id DESC
            LIMIT 6
        ";

        $result = $db->query($query);

        if (!$result) {
            throw new \Exception('Error obteniendo reservas recientes: ' . $db->error);
        }

        $items = [];

        while ($row = $result->fetch_assoc()) {
            $items[] = $row;
        }

        return $items;
    }

    private static function getRecentSurveyResponses(): array
    {
        $db = ActiveRecord::getDB();

        $query = "
            SELECT
                id,
                passenger_name,
                email,
                trip_title,
                trip_date,
                created_at
            FROM survey_responses
            ORDER BY created_at DESC
            LIMIT 5
        ";

        $result = $db->query($query);

        if (!$result) {
            throw new \Exception('Error obteniendo encuestas recientes: ' . $db->error);
        }

        $items = [];

        while ($row = $result->fetch_assoc()) {
            $items[] = $row;
        }

        return $items;
    }
    
    public static function getTravels()
    {
        $travels = Trip::allWithPassengersCount('ASC');
        JsonResponse::send([
            'success' => true,
            'data'    => $travels,
        ], 200);
    }

    public static function getPassengersByTravel()
    {
        $id = $_GET['id'] ?? null;

        if (!$id) {
            JsonResponse::send([
                'success' => false,
                'message' => 'Falta id'
            ], 400);
            return;
        }

        $trip = Trip::find((int) $id);

        if (!$trip) {
            JsonResponse::send([
                'success' => false,
                'message' => 'RH EXPERIENCE no encontrado'
            ], 404);
            return;
        }

        $passengers = TripPassenger::allPassengersWithRegistrationByTrip((int) $id);

        JsonResponse::send([
            'success' => true,
            'data' => $passengers,
        ], 200);

        return;
    }
}
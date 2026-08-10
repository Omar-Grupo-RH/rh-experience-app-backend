<?php

namespace Controllers;

use App\Http\JsonResponse;
use Model\ActiveRecord;
use Model\Trip;
use Model\TripPassenger;

class DashboardController
{
    private const PROSPECT_PRICE = 1500;
    private const RECOVERY_PRICE = 500;

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

    private static function paymentAmountCaseSql(): string
    {
        $prospectPrice = self::PROSPECT_PRICE;
        $recoveryPrice = self::RECOVERY_PRICE;

        return "
            CASE
                WHEN rr.registration_type = 'prospect' THEN {$prospectPrice}

                WHEN rr.registration_type = 'family_friend' THEN {$recoveryPrice}

                WHEN rr.registration_type = 'investor'
                    AND tp.id <> (
                        SELECT MIN(tp_inv.id)
                        FROM ticket_passengers tp_inv
                        WHERE tp_inv.ticket_id = tp.ticket_id
                    )
                THEN {$recoveryPrice}

                WHEN rr.registration_type = 'internal'
                    AND EXISTS (
                        SELECT 1
                        FROM ticket_passengers tp_prev
                        WHERE LOWER(TRIM(tp_prev.email)) = LOWER(TRIM(tp.email))
                        AND tp_prev.id < tp.id
                        LIMIT 1
                    )
                THEN {$recoveryPrice}

                ELSE 0
            END
        ";
    }

    private static function paidPassengerCaseSql(): string
    {
        $paymentAmountCase = self::paymentAmountCaseSql();

        return "
            CASE
                WHEN ({$paymentAmountCase}) > 0 THEN 1
                ELSE 0
            END
        ";
    }

    private static function getNextExperience(): ?array
    {
        $db = ActiveRecord::getDB();

        $paymentAmountCase = self::paymentAmountCaseSql();
        $paidPassengerCase = self::paidPassengerCaseSql();

        $query = "
    SELECT
        t.id,
        t.title,
        t.trip_date,
        t.total_seats AS total_capacity,

        COUNT(tp.id) AS registered_passengers,

        GREATEST(t.total_seats - COUNT(tp.id), 0) AS available_seats,

        SUM(
            CASE 
                WHEN rr.registration_type = 'prospect' THEN 1 
                ELSE 0 
            END
        ) AS prospect_passengers,

        SUM(
            CASE 
                WHEN rr.registration_type = 'family_friend' THEN 1 
                ELSE 0 
            END
        ) AS family_friend_passengers,

        SUM(
            CASE
                WHEN rr.registration_type = 'investor'
                    AND tp.id <> (
                        SELECT MIN(tp_inv.id)
                        FROM ticket_passengers tp_inv
                        WHERE tp_inv.ticket_id = tp.ticket_id
                    )
                THEN 1
                ELSE 0
            END
        ) AS investor_extra_passengers,

        SUM(
            CASE
                WHEN rr.registration_type = 'internal'
                    AND EXISTS (
                        SELECT 1
                        FROM ticket_passengers tp_prev
                        WHERE LOWER(TRIM(tp_prev.email)) = LOWER(TRIM(tp.email))
                        AND tp_prev.id < tp.id
                        LIMIT 1
                    )
                THEN 1
                ELSE 0
            END
        ) AS internal_second_visit_passengers,

        SUM({$paidPassengerCase}) AS paid_passengers,

        SUM({$paymentAmountCase}) AS estimated_revenue

    FROM trips t

    LEFT JOIN ticket_passengers tp
        ON tp.trip_id = t.id

    LEFT JOIN reservation_registrations rr
        ON rr.ticket_passenger_id = tp.id

    WHERE t.trip_date >= CURDATE()
      AND t.is_active = 1

    GROUP BY 
        t.id, 
        t.title, 
        t.trip_date, 
        t.total_seats

    ORDER BY t.trip_date ASC

    LIMIT 1
";

        $result = $db->query($query);

        if (!$result) {
            throw new \Exception('Error obteniendo próxima experiencia: ' . $db->error);
        }

        $row = $result->fetch_assoc();

        if (!$row) {
            return null;
        }

        $registered = (int) ($row['registered_passengers'] ?? 0);
        $capacity = (int) ($row['total_capacity'] ?? 15);
        $available = (int) ($row['available_seats'] ?? 0);

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
            'family_friend_passengers' => (int) ($row['family_friend_passengers'] ?? 0),
            'investor_extra_passengers' => (int) ($row['investor_extra_passengers'] ?? 0),
            'internal_second_visit_passengers' => (int) ($row['internal_second_visit_passengers'] ?? 0),
            'paid_passengers' => (int) ($row['paid_passengers'] ?? 0),

            'estimated_revenue' => (float) ($row['estimated_revenue'] ?? 0),
        ];
    }

    private static function getSummary(int $days): array
    {
        $db = ActiveRecord::getDB();

        $paymentAmountCase = self::paymentAmountCaseSql();
        $paidPassengerCase = self::paidPassengerCaseSql();

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
                        WHEN rr.registration_type = 'family_friend' THEN 1 
                        ELSE 0 
                    END
                ) AS family_friend_passengers,

                SUM(
                    CASE
                        WHEN rr.registration_type = 'investor'
                            AND tp.id <> (
                                SELECT MIN(tp_inv.id)
                                FROM ticket_passengers tp_inv
                                WHERE tp_inv.ticket_id = tp.ticket_id
                            )
                        THEN 1
                        ELSE 0
                    END
                ) AS investor_extra_passengers,

                SUM(
                    CASE
                        WHEN rr.registration_type = 'internal'
                            AND EXISTS (
                                SELECT 1
                                FROM ticket_passengers tp_prev
                                WHERE LOWER(TRIM(tp_prev.email)) = LOWER(TRIM(tp.email))
                                AND tp_prev.id < tp.id
                                LIMIT 1
                            )
                        THEN 1
                        ELSE 0
                    END
                ) AS internal_second_visit_passengers,

                SUM({$paidPassengerCase}) AS paid_passengers,

                SUM({$paymentAmountCase}) AS estimated_revenue

            FROM ticket_passengers tp
            LEFT JOIN reservation_registrations rr
                ON rr.ticket_passenger_id = tp.id
            WHERE tp.created_at >= DATE_SUB(CURDATE(), INTERVAL {$days} DAY)
        ";

        $result = $db->query($query);

        if (!$result) {
            throw new \Exception('Error preparando resumen: ' . $db->error);
        }

        $row = $result->fetch_assoc() ?: [];

        return [
            'days' => $days,

            'total_passengers' => (int) ($row['total_passengers'] ?? 0),
            'total_reservations' => (int) ($row['total_reservations'] ?? 0),

            'prospect_passengers' => (int) ($row['prospect_passengers'] ?? 0),
            'family_friend_passengers' => (int) ($row['family_friend_passengers'] ?? 0),
            'investor_extra_passengers' => (int) ($row['investor_extra_passengers'] ?? 0),
            'internal_second_visit_passengers' => (int) ($row['internal_second_visit_passengers'] ?? 0),
            'paid_passengers' => (int) ($row['paid_passengers'] ?? 0),

            'estimated_revenue' => (float) ($row['estimated_revenue'] ?? 0),

            'prospect_price' => self::PROSPECT_PRICE,
            'recovery_price' => self::RECOVERY_PRICE,
        ];
    }

    private static function getSignupsTrend(int $days): array
    {
        $db = ActiveRecord::getDB();

        $query = "
            SELECT
                DATE(tp.created_at) AS date,

                COUNT(tp.id) AS passengers,
                COUNT(DISTINCT tp.ticket_id) AS reservations,

                SUM(
                    CASE 
                        WHEN rr.registration_type = 'prospect' THEN 1 
                        ELSE 0 
                    END
                ) AS prospects,

                SUM(
                    CASE 
                        WHEN rr.registration_type = 'family_friend' THEN 1 
                        ELSE 0 
                    END
                ) AS family_friends,

                SUM(
                    CASE
                        WHEN rr.registration_type = 'investor'
                            AND tp.id <> (
                                SELECT MIN(tp_inv.id)
                                FROM ticket_passengers tp_inv
                                WHERE tp_inv.ticket_id = tp.ticket_id
                            )
                        THEN 1
                        ELSE 0
                    END
                ) AS investor_extras,

                SUM(
                    CASE
                        WHEN rr.registration_type = 'internal'
                            AND EXISTS (
                                SELECT 1
                                FROM ticket_passengers tp_prev
                                WHERE LOWER(TRIM(tp_prev.email)) = LOWER(TRIM(tp.email))
                                AND tp_prev.id < tp.id
                                LIMIT 1
                            )
                        THEN 1
                        ELSE 0
                    END
                ) AS internal_second_visits

            FROM ticket_passengers tp
            LEFT JOIN reservation_registrations rr
                ON rr.ticket_passenger_id = tp.id
            WHERE tp.created_at >= DATE_SUB(CURDATE(), INTERVAL {$days} DAY)
            GROUP BY DATE(tp.created_at)
            ORDER BY DATE(tp.created_at) ASC
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

                'prospects' => (int) ($row['prospects'] ?? 0),
                'family_friends' => (int) ($row['family_friends'] ?? 0),
                'investor_extras' => (int) ($row['investor_extras'] ?? 0),
                'internal_second_visits' => (int) ($row['internal_second_visits'] ?? 0),
            ];
        }

        return $items;
    }

    private static function getRevenueTrend(int $days): array
    {
        $db = ActiveRecord::getDB();

        $paymentAmountCase = self::paymentAmountCaseSql();
        $paidPassengerCase = self::paidPassengerCaseSql();

        $query = "
            SELECT
                DATE(tp.created_at) AS date,

                SUM(
                    CASE 
                        WHEN rr.registration_type = 'prospect' THEN 1 
                        ELSE 0 
                    END
                ) AS prospect_passengers,

                SUM(
                    CASE 
                        WHEN rr.registration_type = 'family_friend' THEN 1 
                        ELSE 0 
                    END
                ) AS family_friend_passengers,

                SUM(
                    CASE
                        WHEN rr.registration_type = 'investor'
                            AND tp.id <> (
                                SELECT MIN(tp_inv.id)
                                FROM ticket_passengers tp_inv
                                WHERE tp_inv.ticket_id = tp.ticket_id
                            )
                        THEN 1
                        ELSE 0
                    END
                ) AS investor_extra_passengers,

                SUM(
                    CASE
                        WHEN rr.registration_type = 'internal'
                            AND EXISTS (
                                SELECT 1
                                FROM ticket_passengers tp_prev
                                WHERE LOWER(TRIM(tp_prev.email)) = LOWER(TRIM(tp.email))
                                AND tp_prev.id < tp.id
                                LIMIT 1
                            )
                        THEN 1
                        ELSE 0
                    END
                ) AS internal_second_visit_passengers,

                SUM({$paidPassengerCase}) AS paid_passengers,

                SUM({$paymentAmountCase}) AS estimated_revenue

            FROM ticket_passengers tp
            LEFT JOIN reservation_registrations rr
                ON rr.ticket_passenger_id = tp.id
            WHERE tp.created_at >= DATE_SUB(CURDATE(), INTERVAL {$days} DAY)
            GROUP BY DATE(tp.created_at)
            ORDER BY DATE(tp.created_at) ASC
        ";

        $result = $db->query($query);

        if (!$result) {
            throw new \Exception('Error preparando ingresos por día: ' . $db->error);
        }

        $items = [];

        while ($row = $result->fetch_assoc()) {
            $items[] = [
                'date' => $row['date'],

                'prospect_passengers' => (int) ($row['prospect_passengers'] ?? 0),
                'family_friend_passengers' => (int) ($row['family_friend_passengers'] ?? 0),
                'investor_extra_passengers' => (int) ($row['investor_extra_passengers'] ?? 0),
                'internal_second_visit_passengers' => (int) ($row['internal_second_visit_passengers'] ?? 0),
                'paid_passengers' => (int) ($row['paid_passengers'] ?? 0),

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

        $paymentAmountCase = self::paymentAmountCaseSql();

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
                rr.registration_type,
                {$paymentAmountCase} AS estimated_amount
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
            $row['estimated_amount'] = (float) ($row['estimated_amount'] ?? 0);
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
            'data' => $travels,
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
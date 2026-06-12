<?php

namespace Model;

class SurveyResponse extends ActiveRecord
{
    protected static $tabla = 'survey_responses';

    protected static $columnasDB = [
        'id',
        'trip_id',
        'ticket_id',
        'email',
        'passenger_name',
        'trip_title',
        'trip_date',
        'answers_json',
        'created_at'
    ];

    public $id;
    public $trip_id;
    public $ticket_id;
    public $email;
    public $passenger_name;
    public $trip_title;
    public $trip_date;
    public $answers_json;
    public $created_at;

    public function __construct($args = [])
    {
        $this->id = $args['id'] ?? null;
        $this->trip_id = $args['trip_id'] ?? null;
        $this->ticket_id = $args['ticket_id'] ?? null;
        $this->email = $args['email'] ?? '';
        $this->passenger_name = $args['passenger_name'] ?? '';
        $this->trip_title = $args['trip_title'] ?? '';
        $this->trip_date = $args['trip_date'] ?? null;
        $this->answers_json = $args['answers_json'] ?? '{}';
        $this->created_at = $args['created_at'] ?? date('Y-m-d H:i:s');
    }

    public static function findByTripTicketEmail(int $tripId, int $ticketId, string $email): ?array
    {
        $db = self::getDB();

        $query = "
            SELECT *
            FROM " . static::$tabla . "
            WHERE trip_id = ?
            AND ticket_id = ?
            AND email = ?
            LIMIT 1
        ";

        $stmt = $db->prepare($query);

        if (!$stmt) {
            throw new \Exception('Error preparando consulta de encuesta: ' . $db->error);
        }

        $stmt->bind_param('iis', $tripId, $ticketId, $email);
        $stmt->execute();

        $result = $stmt->get_result();
        $row = $result->fetch_assoc();

        return $row ?: null;
    }

    public static function allByTrip(?int $tripId = null): array
    {
        $db = self::getDB();

        $where = "";
        $types = "";
        $values = [];

        if ($tripId) {
            $where = "WHERE sr.trip_id = ?";
            $types = "i";
            $values[] = $tripId;
        }

        $query = "
            SELECT
                sr.id,
                sr.trip_id,
                sr.ticket_id,
                sr.email,
                sr.passenger_name,
                sr.trip_title,
                sr.trip_date,
                sr.answers_json,
                sr.created_at,

                tp.id AS ticket_passenger_id,

                COALESCE(
                    rr_passenger.registration_type,
                    rr_ticket.registration_type
                ) AS registration_type,

                COALESCE(
                    rr_passenger.area_code,
                    rr_ticket.area_code
                ) AS area_code,

                COALESCE(
                    rr_passenger.alliance_name,
                    rr_ticket.alliance_name
                ) AS alliance_name,

                COALESCE(
                    rr_passenger.alliance_code,
                    rr_ticket.alliance_code
                ) AS alliance_code,

                COALESCE(
                    rr_passenger.prize_code,
                    rr_ticket.prize_code
                ) AS prize_code,

                COALESCE(
                    rr_passenger.development,
                    rr_ticket.development
                ) AS development,

                COALESCE(
                    rr_passenger.stage,
                    rr_ticket.stage
                ) AS stage,

                COALESCE(
                    rr_passenger.lot_number,
                    rr_ticket.lot_number
                ) AS lot_number,

                COALESCE(
                    rr_passenger.advisor_name,
                    rr_ticket.advisor_name
                ) AS advisor_name,

                COALESCE(
                    rr_passenger.advisor_code,
                    rr_ticket.advisor_code
                ) AS advisor_code,

                COALESCE(
                    rr_passenger.origin,
                    rr_ticket.origin
                ) AS origin,

                COALESCE(
                    rr_passenger.origin_alliance_name,
                    rr_ticket.origin_alliance_name
                ) AS origin_alliance_name

            FROM " . static::$tabla . " sr

            LEFT JOIN ticket_passengers tp
                ON tp.ticket_id = sr.ticket_id
                AND tp.trip_id = sr.trip_id
                AND LOWER(TRIM(tp.email)) = LOWER(TRIM(sr.email))

            LEFT JOIN reservation_registrations rr_passenger
                ON rr_passenger.ticket_passenger_id = tp.id

            LEFT JOIN reservation_registrations rr_ticket
                ON rr_ticket.ticket_id = sr.ticket_id
                AND rr_ticket.ticket_passenger_id IS NULL

            {$where}

            ORDER BY sr.created_at DESC
        ";

        if ($tripId) {
            $stmt = $db->prepare($query);

            if (!$stmt) {
                throw new \Exception('Error preparando consulta de respuestas: ' . $db->error);
            }

            $stmt->bind_param($types, ...$values);
            $stmt->execute();

            $result = $stmt->get_result();
        } else {
            $result = $db->query($query);

            if (!$result) {
                throw new \Exception('Error consultando respuestas: ' . $db->error);
            }
        }

        $responses = [];

        while ($row = $result->fetch_assoc()) {
            $responses[] = self::hydrateSurveyRow($row);
        }

        return $responses;
    }

    public static function findFull(int $id): ?array
    {
        $db = self::getDB();

        $query = "
            SELECT
                sr.id,
                sr.trip_id,
                sr.ticket_id,
                sr.email,
                sr.passenger_name,
                sr.trip_title,
                sr.trip_date,
                sr.answers_json,
                sr.created_at,

                tp.id AS ticket_passenger_id,

                COALESCE(
                    rr_passenger.registration_type,
                    rr_ticket.registration_type
                ) AS registration_type,

                COALESCE(
                    rr_passenger.area_code,
                    rr_ticket.area_code
                ) AS area_code,

                COALESCE(
                    rr_passenger.alliance_name,
                    rr_ticket.alliance_name
                ) AS alliance_name,

                COALESCE(
                    rr_passenger.alliance_code,
                    rr_ticket.alliance_code
                ) AS alliance_code,

                COALESCE(
                    rr_passenger.prize_code,
                    rr_ticket.prize_code
                ) AS prize_code,

                COALESCE(
                    rr_passenger.development,
                    rr_ticket.development
                ) AS development,

                COALESCE(
                    rr_passenger.stage,
                    rr_ticket.stage
                ) AS stage,

                COALESCE(
                    rr_passenger.lot_number,
                    rr_ticket.lot_number
                ) AS lot_number,

                COALESCE(
                    rr_passenger.advisor_name,
                    rr_ticket.advisor_name
                ) AS advisor_name,

                COALESCE(
                    rr_passenger.advisor_code,
                    rr_ticket.advisor_code
                ) AS advisor_code,

                COALESCE(
                    rr_passenger.origin,
                    rr_ticket.origin
                ) AS origin,

                COALESCE(
                    rr_passenger.origin_alliance_name,
                    rr_ticket.origin_alliance_name
                ) AS origin_alliance_name

            FROM " . static::$tabla . " sr

            LEFT JOIN ticket_passengers tp
                ON tp.ticket_id = sr.ticket_id
                AND tp.trip_id = sr.trip_id
                AND LOWER(TRIM(tp.email)) = LOWER(TRIM(sr.email))

            LEFT JOIN reservation_registrations rr_passenger
                ON rr_passenger.ticket_passenger_id = tp.id

            LEFT JOIN reservation_registrations rr_ticket
                ON rr_ticket.ticket_id = sr.ticket_id
                AND rr_ticket.ticket_passenger_id IS NULL

            WHERE sr.id = ?

            LIMIT 1
        ";

        $stmt = $db->prepare($query);

        if (!$stmt) {
            throw new \Exception('Error preparando consulta de respuesta: ' . $db->error);
        }

        $stmt->bind_param('i', $id);
        $stmt->execute();

        $result = $stmt->get_result();
        $row = $result->fetch_assoc();

        if (!$row) {
            return null;
        }

        return self::hydrateSurveyRow($row);
    }

    public static function statsByTrip(?int $tripId = null): array
    {
        $db = self::getDB();

        if ($tripId) {
            $query = "
                SELECT 
                    trip_id,
                    trip_title,
                    trip_date,
                    COUNT(*) AS total_responses,
                    MIN(created_at) AS first_response_at,
                    MAX(created_at) AS last_response_at
                FROM " . static::$tabla . "
                WHERE trip_id = ?
                GROUP BY trip_id, trip_title, trip_date
                ORDER BY trip_date DESC
            ";

            $stmt = $db->prepare($query);

            if (!$stmt) {
                throw new \Exception('Error preparando estadísticas: ' . $db->error);
            }

            $stmt->bind_param('i', $tripId);
            $stmt->execute();

            $result = $stmt->get_result();
        } else {
            $query = "
                SELECT 
                    trip_id,
                    trip_title,
                    trip_date,
                    COUNT(*) AS total_responses,
                    MIN(created_at) AS first_response_at,
                    MAX(created_at) AS last_response_at
                FROM " . static::$tabla . "
                GROUP BY trip_id, trip_title, trip_date
                ORDER BY trip_date DESC
            ";

            $result = $db->query($query);

            if (!$result) {
                throw new \Exception('Error consultando estadísticas: ' . $db->error);
            }
        }

        $stats = [];

        while ($row = $result->fetch_assoc()) {
            $stats[] = $row;
        }

        return $stats;
    }

    private static function hydrateSurveyRow(array $row): array
    {
        $answers = json_decode($row['answers_json'] ?? '{}', true) ?: [];

        $registrationType = $row['registration_type'] ?? null;
        $registrationTypeLabel = self::formatRegistrationType($registrationType);

        $answers['participant_type'] = $registrationTypeLabel;
        $answers['trip_participation_date'] = $row['trip_date'] ?? null;

        $row['registration_type'] = $registrationType;
        $row['registration_type_label'] = $registrationTypeLabel;

        $row['registration'] = [
            'registration_type' => $registrationType,
            'registration_type_label' => $registrationTypeLabel,
            'area_code' => $row['area_code'] ?? null,
            'alliance_name' => $row['alliance_name'] ?? null,
            'alliance_code' => $row['alliance_code'] ?? null,
            'prize_code' => $row['prize_code'] ?? null,
            'development' => $row['development'] ?? null,
            'stage' => $row['stage'] ?? null,
            'lot_number' => $row['lot_number'] ?? null,
            'advisor_name' => $row['advisor_name'] ?? null,
            'advisor_code' => $row['advisor_code'] ?? null,
            'origin' => $row['origin'] ?? null,
            'origin_alliance_name' => $row['origin_alliance_name'] ?? null,
        ];

        $row['answers'] = $answers;
        $row['answers_json'] = json_encode($answers, JSON_UNESCAPED_UNICODE);

        return $row;
    }

    private static function formatRegistrationType(?string $type): string
    {
        return match ($type) {
            'investor' => 'Inversionista',
            'alliance' => 'Alianza',
            'internal' => 'Comunidad RH',
            'family_friend' => 'Comunidad RH',
            'prospect' => 'Turista/Público',
            'prize' => 'Turista/Público',
            default => 'Sin clasificar',
        };
    }
}
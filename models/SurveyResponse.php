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

        if ($tripId) {
            $query = "
                SELECT *
                FROM " . static::$tabla . "
                WHERE trip_id = ?
                ORDER BY created_at DESC
            ";

            $stmt = $db->prepare($query);

            if (!$stmt) {
                throw new \Exception('Error preparando consulta de respuestas: ' . $db->error);
            }

            $stmt->bind_param('i', $tripId);
            $stmt->execute();
            $result = $stmt->get_result();
        } else {
            $query = "
                SELECT *
                FROM " . static::$tabla . "
                ORDER BY created_at DESC
            ";

            $result = $db->query($query);
        }

        $responses = [];

        while ($row = $result->fetch_assoc()) {
            $row['answers'] = json_decode($row['answers_json'] ?? '{}', true) ?: [];
            $responses[] = $row;
        }

        return $responses;
    }

    public static function findFull(int $id): ?array
    {
        $db = self::getDB();

        $query = "
            SELECT *
            FROM " . static::$tabla . "
            WHERE id = ?
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

        $row['answers'] = json_decode($row['answers_json'] ?? '{}', true) ?: [];

        return $row;
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
        }

        $stats = [];

        while ($row = $result->fetch_assoc()) {
            $stats[] = $row;
        }

        return $stats;
    }
}
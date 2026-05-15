<?php

namespace Model;

use DateTime;
use DateTimeZone;

class TicketPassenger extends ActiveRecord
{
    protected static $tabla = 'ticket_passengers';

    protected static $columnasDB = [
        'id',
        'ticket_id',
        'trip_id',
        'seat_id',
        'first_name',
        'last_name',
        'email',
        'phone',
        'born_date',
        'allergies',
        'health_conditions',
        'created_at'
    ];

    public ?int $id;
    public ?int $ticket_id;
    public ?int $trip_id;
    public ?string $seat_id;
    public string $first_name;
    public string $last_name;
    public string $email;
    public string $phone;
    public ?string $born_date;
    public string $allergies;
    public string $health_conditions;
    public string $created_at;

    public function __construct($args = [])
    {
        $this->id = $args['id'] ?? null;
        $this->ticket_id = isset($args['ticket_id']) ? (int) $args['ticket_id'] : null;
        $this->trip_id = isset($args['trip_id']) ? (int) $args['trip_id'] : null;

        $this->seat_id = isset($args['seat_id'])
            ? strtoupper(trim((string) $args['seat_id']))
            : null;

        $this->first_name = trim($args['first_name'] ?? '');
        $this->last_name = trim($args['last_name'] ?? '');
        $this->email = trim($args['email'] ?? '');
        $this->phone = trim($args['phone'] ?? '');

        $this->born_date = $args['born_date'] ?? null;

        $this->allergies = is_array($args['allergies'] ?? null)
            ? json_encode($args['allergies'], JSON_UNESCAPED_UNICODE)
            : ($args['allergies'] ?? '[]');

        $this->health_conditions = is_array($args['health_conditions'] ?? null)
            ? json_encode($args['health_conditions'], JSON_UNESCAPED_UNICODE)
            : ($args['health_conditions'] ?? '[]');

        $this->created_at = $args['created_at'] ?? $this->nowDateMerida();
    }

    public function nowDateMerida(): string
    {
        $dt = new DateTime('now', new DateTimeZone('America/Merida'));
        return $dt->format('Y-m-d');
    }

    public function fullName(): string
    {
        return trim($this->first_name . ' ' . $this->last_name);
    }
}
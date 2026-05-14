<?php

namespace Model;

use DateTime;
use DateTimeZone;

class Ticket extends ActiveRecord
{
    protected static $tabla = 'tickets';

    protected static $columnasDB = [
        'id',
        'trip_id',
        'seat_numbers',
        'created_at'
    ];

    public ?int $id;
    public ?int $trip_id;
    public string $seat_numbers;
    public string $created_at;

    public function __construct($args = [])
    {
        $this->id = $args['id'] ?? null;
        $this->trip_id = isset($args['trip_id']) ? (int) $args['trip_id'] : null;

        $this->seat_numbers = is_array($args['seat_numbers'] ?? null)
            ? json_encode($args['seat_numbers'], JSON_UNESCAPED_UNICODE)
            : ($args['seat_numbers'] ?? '[]');

        $this->created_at = $args['created_at'] ?? $this->nowDateMerida();
    }

    public function nowDateMerida(): string
    {
        $dt = new DateTime('now', new DateTimeZone('America/Merida'));
        return $dt->format('Y-m-d');
    }
}
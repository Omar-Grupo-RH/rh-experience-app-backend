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

    public $id;
    public $trip_id;

    public $seat_numbers;

    public $created_at;

    public function __construct($args = [])
    {
        $this->id          = $args['id'] ?? null;
        $this->trip_id   = $args['trip_id'] ?? null;

        $this->seat_numbers       = $args['seat_numbers'] ?? [];

        $this->created_at       = $args['created_at'] ?? $this->nowDateMerida();
    }
    public function nowDateMerida(): string
    {
        $dt = new DateTime('now', new DateTimeZone('America/Merida'));
        return $dt->format('Y-m-d');
    }
}

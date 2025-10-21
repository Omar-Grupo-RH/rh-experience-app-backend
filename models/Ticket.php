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
        'user_id',
        'guest_id',
        'seat_numbers',
        'accepted_terms',
        'created_at'
    ];

    public $id;
    public $trip_id;
    public $user_id;
    public $guest_id;
    public $seat_numbers;
    public $accepted_terms;
    public $created_at;

    public function __construct($args = [])
    {
        $this->id          = $args['id'] ?? null;
        $this->trip_id   = $args['trip_id'] ?? null;
        $this->user_id = $args['user_id'] ?? null;
        $this->guest_id       = $args['guest_id'] ?? null;
        $this->seat_numbers       = $args['seat_numbers'] ?? [];
        $this->accepted_terms       = $args['accepted_terms'] ?? 1;
        $this->created_at       = $args['created_at'] ?? $this->nowDateMerida();
    }
    public function nowDateMerida(): string
    {
        $dt = new DateTime('now', new DateTimeZone('America/Merida'));
        return $dt->format('Y-m-d');
    }
}
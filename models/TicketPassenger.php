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
        'seat_id',
        'first_name',
        'last_name',
        'email',
        'phone',
        'born_date',
        'allergies',
        'created_at'
    ];

    public $id;
    public $ticket_id;
    public $seat_id;
    public $first_name;
    public $last_name;
    public $email;
    public $phone;
    public $born_date;   // 'YYYY-MM-DD' o null
    public $allergies;   // array -> JSON en DB
    public $created_at;

    public function __construct($args = [])
    {
        $this->id         = $args['id'] ?? null;
        $this->ticket_id  = $args['ticket_id'] ?? null;
        $this->seat_id    = $args['seat_id'] ?? null;

        $this->first_name = $args['first_name'] ?? '';
        $this->last_name  = $args['last_name'] ?? '';
        $this->email      = $args['email'] ?? '';
        $this->phone      = $args['phone'] ?? '';

        $this->born_date  = $args['born_date'] ?? null;   // ej. '1999-06-18'
        $this->allergies  = $args['allergies'] ?? [];     // ej. ['mariscos','abejas']

        $this->created_at = $args['created_at'] ?? $this->nowDateMerida();
    }

    public function nowDateMerida(): string
    {
        $dt = new DateTime('now', new DateTimeZone('America/Merida'));
        return $dt->format('Y-m-d');
    }

    // Helper opcional
    public function fullName(): string
    {
        return trim($this->first_name . ' ' . $this->last_name);
    }
}

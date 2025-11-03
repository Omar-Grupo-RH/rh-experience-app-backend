<?php

namespace Model;

class TripPassenger extends ActiveRecord
{
    protected static $tabla = 'trips'; 
    protected static $columnasDB = [
        'passenger_id',
        'first_name',
        'last_name',
        'email',
        'phone',
        'born_date',
        'seat_id',
        'allergies',
        'created_at',
        'ticket_id',
        'seat_numbers',
        'trip_date',
        'title'
    ];

    public $passenger_id;
    public $first_name;
    public $last_name;
    public $email;
    public $phone;
    public $born_date;
    public $seat_id;
    public $allergies;
    public $created_at;
    public $ticket_id;
    public $seat_numbers;
    public $trip_date;
    public $title;

    public function __construct($args = [])
    {
        $this->passenger_id = $args['passenger_id'] ?? null;
        $this->first_name   = $args['first_name'] ?? '';
        $this->last_name    = $args['last_name'] ?? '';
        $this->email        = $args['email'] ?? '';
        $this->phone        = $args['phone'] ?? '';
        $this->born_date    = $args['born_date'] ?? null;
        $this->seat_id      = $args['seat_id'] ?? '';
        $this->allergies    = $args['allergies'] ?? '[]';
        $this->created_at   = $args['created_at'] ?? null;
        $this->ticket_id    = $args['ticket_id'] ?? null;
        $this->seat_numbers = $args['seat_numbers'] ?? '';
        $this->trip_date    = $args['trip_date'] ?? null;
        $this->title        = $args['title'] ?? '';
    }
}

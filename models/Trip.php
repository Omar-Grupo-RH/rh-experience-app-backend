<?php

namespace Model;


class Trip extends ActiveRecord
{
    protected static $tabla = 'trips';
    protected static $columnasDB = [
        'id',
        'trip_date',
        'total_seats',
        'title'
    ];

    public $id;
    public $trip_date;
    public $total_seats;
    public $title;
    public $avaible_seats;


    public function __construct($args = [])
    {
        $this->id          = $args['id'] ?? null;
        $this->trip_date   = $args['trip_date'] ?? null;
        $this->total_seats = $args['total_seats'] ?? 15;
        $this->title       = $args['title'] ?? '';
    }
}

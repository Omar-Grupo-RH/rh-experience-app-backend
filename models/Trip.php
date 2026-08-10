<?php

namespace Model;


class Trip extends ActiveRecord
{
    protected static $tabla = 'trips';
    protected static $columnasDB = [
        'id',
        'trip_date',
        'total_seats',
        'title',
        'is_active',
    ];

    public $id;
    public $trip_date;
    public $total_seats;
    public $title;
    public $avaible_seats;
    public $passengers_count;
    public $unavaible_seats;
    public $is_active;
    
    public function __construct($args = [])
    {
        $this->id          = $args['id'] ?? null;
        $this->trip_date   = $args['trip_date'] ?? null;
        $this->total_seats = $args['total_seats'] ?? 15;
        $this->title       = $args['title'] ?? '';
        $this->is_active   = $args['is_active'] ?? 1;
    }
}
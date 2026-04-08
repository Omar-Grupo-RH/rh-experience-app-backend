<?php

namespace Model;

use DateTime;
use DateTimeZone;

class Order extends ActiveRecord
{
    protected static $tabla = 'orders';
    protected static $columnasDB = [
        'id',
        'ticket_id',
        'buyer_id',
        'created_at'
    ];

    public $id;
    public $ticket_id;
    public $buyer_id;
    public $created_at;

    // Campos extra del JOIN (deben existir para que crearObjeto los llene)
    public $buyer_name;
    public $email;
    public $phone;
    public $city;
    public $is_client;
    public $advisor_name;
    public $is_first_visit;
    public $trip_date;
    public $trip_title;


    public function __construct($a = [])
    {
        $this->id = $a['id'] ?? null;
        $this->ticket_id = $a['ticket_id'] ?? null;
        $this->buyer_id  = $a['buyer_id'] ?? null;
        $this->created_at = $a['created_at'] ?? $this->nowDateMerida();
    }


    public function nowDateMerida(): string
    {
        $dt = new DateTime('now', new DateTimeZone('America/Merida'));
        return $dt->format('Y-m-d');
    }
}
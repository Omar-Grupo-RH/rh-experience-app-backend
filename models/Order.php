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
        'created_at'
    ];

    public ?int $id;
    public ?int $ticket_id;
    public string $created_at;

    public ?string $email = null;
    public ?string $phone = null;
    public ?string $city = null;
    public ?string $trip_date = null;
    public ?string $trip_title = null;

    public function __construct($a = [])
    {
        $this->id = $a['id'] ?? null;
        $this->ticket_id = isset($a['ticket_id']) ? (int) $a['ticket_id'] : null;
        $this->created_at = $a['created_at'] ?? $this->nowDateMerida();
    }

    public function nowDateMerida(): string
    {
        $dt = new DateTime('now', new DateTimeZone('America/Merida'));
        return $dt->format('Y-m-d');
    }
}
<?php

namespace Model;

use DateTime;
use DateTimeZone;

class Buyer extends ActiveRecord
{
    protected static $tabla = 'buyers';
    protected static $columnasDB = [
        'id',
        'full_name',
        'email',
        'phone',
        'city',
        'created_at',
        'is_client',
        'advisor_name',
        'is_first_visit'
    ];

    public $id;
    public $full_name;
    public $email;
    public $phone;
    public $city;
    public $created_at;
    public $is_client;
    public $advisor_name;
    public $is_first_visit;

    public function __construct($args = [])
    {
        $this->id         = $args['id'] ?? null;
        $this->full_name  = trim($args['full_name'] ?? '');
        $this->email      = trim($args['email'] ?? '');
        $this->phone      = trim($args['phone'] ?? '');
        $this->city       = trim($args['city'] ?? '');
        $this->created_at = $args['created_at'] ?? $this->nowDateMerida();
        $this->is_client  = $args['is_client'] ?? false;
        $this->advisor_name = trim($args['advisor_name'] ?? '');
        $this->is_first_visit = $args['is_first_visit'] ?? false;
    }

    /** Fecha local Mérida (DATE) */
    public function nowDateMerida(): string
    {
        $dt = new DateTime('now', new DateTimeZone('America/Merida'));
        return $dt->format('Y-m-d');
    }
}
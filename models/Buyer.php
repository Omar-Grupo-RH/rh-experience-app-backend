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
        'address',
        'city',
        'state',
        'zip',
        'country',
        'created_at'
    ];

    public $id;
    public $full_name;
    public $email;
    public $phone;
    public $address;
    public $city;
    public $state;
    public $zip;
    public $country;
    public $created_at;

    public function __construct($args = [])
    {
        $this->id         = $args['id'] ?? null;
        $this->full_name  = trim($args['full_name'] ?? '');
        $this->email      = trim($args['email'] ?? '');
        $this->phone      = trim($args['phone'] ?? '');
        $this->address    = trim($args['address'] ?? '');
        $this->city       = trim($args['city'] ?? '');
        $this->state      = trim($args['state'] ?? '');
        $this->zip        = trim($args['zip'] ?? '');
        $this->country    = trim($args['country'] ?? 'MX');
        $this->created_at = $args['created_at'] ?? $this->nowDateMerida();
    }

    /** Fecha local Mérida (DATE) */
    public function nowDateMerida(): string
    {
        $dt = new DateTime('now', new DateTimeZone('America/Merida'));
        return $dt->format('Y-m-d');
    }
}
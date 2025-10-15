<?php

namespace Model;

use DateTime;
use DateTimeZone;

class Coupon extends ActiveRecord
{
    protected static $tabla = 'coupons';
    protected static $columnasDB = [
        'id',
        'code',
        'discount',
        'usage_limit',
        'expiry_date',
        'is_active',
        'created_at',
        'description'
    ];

    public $id;
    public $code;
    public $discount;
    public $usage_limit;
    public $expiry_date;
    public $is_active;
    public $created_at;
    public $description;

    public function __construct($args = [])
    {
        $this->id          = $args['id'] ?? null;
        $this->code   = $args['code'] ?? '';
        $this->discount   = $args['discount'] ?? '';
        $this->usage_limit = $args['usage_limit'] ?? null;
        $this->expiry_date       = $args['expiry_date'] ?? '';
        $this->is_active      = $args['is_active'] ?? 1;
        $this->created_at      = $args['created_at'] ?? $this->nowDateMerida();
        $this->description      = $args['description'] ?? '';
    }


    public function nowDateMerida(): string
    {
        $dt = new DateTime('now', new DateTimeZone('America/Merida'));
        return $dt->format('Y-m-d');
    }
}

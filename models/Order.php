<?php

namespace Model;

class Order extends ActiveRecord
{
    protected static $tabla = 'orders';
    protected static $columnasDB = [
        'id',
        'ticket_id',
        'buyer_id',
        'coupon_id',
        'subtotal_cents',
        'discount_cents',
        'total_cents',
        'currency',
        'status',
        'created_at'
    ];

    public $id, $ticket_id, $buyer_id, $coupon_id, $subtotal_cents, $discount_cents, $total_cents, $currency, $status, $created_at;

    
    public function __construct($a = [])
    {
        $this->id = $a['id'] ?? null;
        $this->ticket_id = $a['ticket_id'] ?? null;
        $this->buyer_id  = $a['buyer_id'] ?? null;
        $this->coupon_id = $a['coupon_id'] ?? null;
        $this->subtotal_cents = (int)($a['subtotal_cents'] ?? 0);
        $this->discount_cents = (int)($a['discount_cents'] ?? 0);
        $this->total_cents    = (int)($a['total_cents'] ?? 0);
        $this->currency = $a['currency'] ?? 'MXN';
        $this->status   = $a['status'] ?? 'pending';
        $this->created_at = $a['created_at'] ?? $this->nowDateMerida();
    }


    public function nowDateMerida(): string
    {
        $dt = new DateTime('now', new DateTimeZone('America/Merida'));
        return $dt->format('Y-m-d');
    }
}
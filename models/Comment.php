<?php

namespace Model;

use DateTime;
use DateTimeZone;

class Comment extends ActiveRecord
{
    protected static $tabla = 'comments';
    protected static $columnasDB = [
        'id',
        'first_name',
        'last_name',
        'email',
        'rating',
        'comment',
        'created_at',
        'is_visible',
        'letters'
    ];

    public $id;
    public $first_name;
    public $last_name;
    public $email;
    public $rating;
    public $comment;
    public $created_at;
    public $is_visible;
    public $letters;

    public function __construct($args = [])
    {
        $this->id          = $args['id'] ?? null;
        $this->first_name   = $args['first_name'] ?? '';
        $this->last_name   = $args['last_name'] ?? '';
        $this->email = $args['email'] ?? null;
        $this->rating       = $args['rating'] ?? '';
        $this->comment      = $args['comment'] ?? '';
        $this->created_at      = $args['created_at'] ?? $this->nowDateMerida();
        $this->is_visible      = $args['is_visible'] ?? 1;
        $this->letters      = $args['letters'] ?? '';
    }


    public function nowDateMerida(): string
    {
        $dt = new DateTime('now', new DateTimeZone('America/Merida'));
        return $dt->format('Y-m-d');
    }
}

<?php

namespace Model;

use DateTime;
use DateTimeZone;

class Client extends ActiveRecord
{
    protected static $tabla = 'users';
    protected static $columnasDB = [
        'id',
        'first_name',
        'last_name',
        'phone',
        'email',
        'password',
        'is_email_verified',
        'created_at',
        'updated_at',
        'admin',
        'allergies',
        'born_date'
    ];

    public $id;
    public $first_name;
    public $last_name;
    public $phone;
    public $email;
    public $password;
    public $is_email_verified;
    public $created_at;
    public $updated_at;
    public $admin;
    public $allergies;
    public $born_date;

    // Contenedor de alertas/errores
    public static $alertas = [
        'error' => [],
        'ok'    => [],
    ];

    public function __construct($args = [])
    {
        $this->id               = $args['id'] ?? null;
        $this->first_name       = trim($args['first_name'] ?? '');
        $this->last_name        = trim($args['last_name'] ?? '');
        $this->phone            = trim($args['phone'] ?? '');
        $this->email            = trim($args['email'] ?? '');
        $this->password         = $args['password'] ?? '';
        $this->is_email_verified = (int)($args['is_email_verified'] ?? 0);

        $this->created_at       = $args['created_at'] ?? $this->nowDateMerida();
        $this->updated_at       = $args['updated_at'] ?? $this->nowDateMerida();
        $this->admin            = (int)($args['admin'] ?? 0);
        $this->allergies        = $args['allergies'] ?? '';
        $this->born_date        = $args['born_date'] ?? null;
    }

    /** Fecha local Mérida (DATE: Y-m-d). Cambia a Y-m-d H:i:s si tu columna es DATETIME. */
    public function nowDateMerida(): string
    {
        $dt = new DateTime('now', new DateTimeZone('America/Merida'));
        return $dt->format('Y-m-d');
    }

    public function hashearPassword(): void
    {
        if (!empty($this->password)) {
            $this->password = password_hash($this->password, PASSWORD_BCRYPT);
        }
    }
}
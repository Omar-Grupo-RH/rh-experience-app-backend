<?php

namespace Model;

class Client extends ActiveRecord
{
    protected static $tabla = 'users';
    protected static $columnasDB = [
        'id',
        'username',
        'password',
        'admin',
    ];

    public $id;
    public $username;
    public $password;
    public $admin;

    // Contenedor de alertas/errores
    public static $alertas = [
        'error' => [],
        'ok'    => [],
    ];

    public function __construct($args = [])
    {
        $this->id               = $args['id'] ?? null;
        $this->username       = trim($args['username'] ?? '');
        $this->password         = $args['password'] ?? '';
        $this->admin            = (int)($args['admin'] ?? 0);
    }

    public function hashearPassword(): void
    {
        if (!empty($this->password)) {
            $this->password = password_hash($this->password, PASSWORD_BCRYPT);
        }
    }
}
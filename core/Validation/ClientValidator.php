<?php

namespace App\Validation;

use Model\Client;

final class ClientValidator
{
    public static function validateCreate(Client $c): array
    {
        $e = self::required($c, true);
        return array_merge($e, self::formats($c));
    }

    public static function validateUpdate(Client $c): array
    {
        $e = self::required($c, false);
        return array_merge($e, self::formats($c));
    }

    public  static function isAdult(Client $c): bool
    {
        if (!$c->born_date) {
            return false; 
        }

        $bornDate = new \DateTime($c->born_date);
        $today = new \DateTime();
        $age = $today->diff($bornDate)->y;

        return $age >= 18;
    }

    private static function required(Client $c, bool $withPassword): array
    {
        $e = [];
        if ($c->first_name === '') $e[] = 'El nombre es obligatorio';
        if ($c->last_name  === '') $e[] = 'El apellido es obligatorio';
        if ($c->email      === '') $e[] = 'El email es obligatorio';
        if ($c->phone      === '') $e[] = 'El teléfono es obligatorio';

        if ($withPassword && ($c->password === '' || $c->password === null)) {
            $e[] = 'La contraseña es obligatoria';
        }
        if ($c->born_date === '' || $c->born_date === null) {
            $e[] = 'La fecha de nacimiento es obligatoria';
        } elseif (!self::isAdult($c)) {
            $e[] = 'Debe ser mayor de edad';
        }
        return $e;
    }

    private static function formats(Client $c): array
    {
        $e = [];
        if ($c->email && !filter_var($c->email, FILTER_VALIDATE_EMAIL)) {
            $e[] = 'El email no tiene un formato válido';
        }
        if ($c->phone && !preg_match('/^\d{10}$/', $c->phone)) {
            $e[] = 'El teléfono debe tener exactamente 10 dígitos';
        }
        return $e;
    }
}
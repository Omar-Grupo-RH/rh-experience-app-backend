<?php

namespace App\Security;

final class LogoutService
{
    public static function run(): void
    {
        // JWT cookies (si las usas)
        $opts = [
            'expires'  => time() - 3600,
            'path'     => '/',
            'secure'   => isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on',
            'httponly' => true,
            'samesite' => 'Lax',
        ];
        @setcookie('access_token',  '', $opts);
        @setcookie('refresh_token', '', $opts);
    }
}
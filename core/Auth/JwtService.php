<?php

namespace App\Auth;

use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Throwable;

final class JwtService
{
    // Mueve esto a config/env si quieres
    private const SECRET   = 'cambia-esta-clave-larga-y-segura';
    private const ALG      = 'HS256';
    private const ISSUER   = 'http://localhost:4000';
    private const AUDIENCE = 'http://localhost:4000';

    public static function issueAccessToken(int $userId, array $extra = [], int $ttl = 3600): string
    {
        $now = time();
        $payload = array_merge([
            'iss' => self::ISSUER,
            'aud' => self::AUDIENCE,
            'iat' => $now,
            'nbf' => $now,
            'exp' => $now + $ttl,
            'sub' => (string)$userId,

            'jti' => bin2hex(random_bytes(16)),
        ], $extra);

        return JWT::encode($payload, self::SECRET, self::ALG);
    }

    public static function decode(string $token): array
    {
        $decoded = JWT::decode($token, new Key(self::SECRET, self::ALG));
        $claims = (array)$decoded;

        // Opcional: validaciones extra
        // if (($claims['iss'] ?? null) !== self::ISSUER) throw new \Exception('iss inválido');
        // if (($claims['aud'] ?? null) !== self::AUDIENCE) throw new \Exception('aud inválido');

        return $claims;
    }

    public static function getTokenFromRequest(): ?string
    {
        $headers = function_exists('getallheaders') ? getallheaders() : [];
        $auth = $headers['Authorization'] ?? $headers['authorization'] ?? '';
        if (preg_match('/Bearer\s+(.+)/i', $auth, $m)) return trim($m[1]);
        if (!empty($_COOKIE['access_token'])) return $_COOKIE['access_token'];
        return null;
    }

    public static function requireUserId(): int
    {
        $token = self::getTokenFromRequest();
        if (!$token) self::unauthorized('No autenticado');

        try {
            $claims = self::decode($token);
        } catch (Throwable $e) {
            self::unauthorized('Token inválido');
        }

        $uid = filter_var($claims['sub'] ?? null, FILTER_VALIDATE_INT);
        if (!$uid) self::unauthorized('Token sin sub válido');

        return (int)$uid;
    }

    private static function unauthorized(string $msg): void
    {
        http_response_code(401);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success' => false, 'message' => $msg], JSON_UNESCAPED_UNICODE);
        exit;
    }
}

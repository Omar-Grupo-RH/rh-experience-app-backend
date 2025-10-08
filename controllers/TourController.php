<?php

namespace Controllers;


class TourController
{
    /** Lee el cuerpo (JSON o form) y devuelve array asociativo. Lanza 400 si JSON inválido. */
    private static function readBody(): array
    {
        $payload = $_POST;
        $isJson  = stripos($_SERVER['CONTENT_TYPE'] ?? '', 'application/json') !== false;

        if (empty($payload) && $isJson) {
            $raw = file_get_contents('php://input');
            $payload = json_decode($raw, true);
            if (!is_array($payload)) {
                JsonResponse::send(['success' => false, 'message' => 'JSON inválido'], 400);
            }
        }

        // Normaliza strings
        foreach ($payload as $k => $v) {
            if (is_string($v)) $payload[$k] = trim($v);
        }
        return $payload;
    }


    public static function store()
    {
        header('Access-Control-Allow-Origin: http://localhost:3000'); // tu front
        header('Access-Control-Allow-Credentials: true');
        header('Access-Control-Allow-Headers: Authorization, Content-Type');
        header('Access-Control-Allow-Methods:POST');
        header('Allow: POST');

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $payload = self::readBody();

            JsonResponse::send([
                'success' => true,
                'message' => 'Datos enviados',
                'data' => [
                    'payload'         => $payload ?? null
                ]
            ], 201);
        }
    }
}

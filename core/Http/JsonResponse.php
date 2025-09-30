<?php

namespace App\Http;

final class JsonResponse
{
    public static function send(array $data, int $code = 200): void
    {
        http_response_code($code);
        header('Content-Type: application/json; charset=utf-8');
        if (ob_get_length()) {
            ob_clean();
        }
        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }
}
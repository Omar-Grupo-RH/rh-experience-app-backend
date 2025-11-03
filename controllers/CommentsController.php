<?php

namespace Controllers;

use App\Http\JsonResponse;
use Model\Comment;

class CommentsController
{

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

    public static function index(): void
    {

        $comments = Comment::whereAll('is_visible', 1);
        JsonResponse::send([
            'success' => true,
            'data'    => $comments,
        ], 200);
    }

    public static function store(): void
    {
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $payload = self::readBody();
            $comment = new Comment($payload);
            // Guardar
            try {
                $result = $comment->guardar();
            } catch (Throwable $e) {
                JsonResponse::send(['success' => false, 'message' => 'Error al realizar el comentario'], 500);
            }
            if (empty($result['resultado'])) {
                JsonResponse::send(['success' => false, 'message' => 'No se pudo realizar el comentario'], 500);
            }


            $comment->id = $result['id'] ?? null;

            JsonResponse::send([
                'success' => true,
                'data' => $comment
            ], 201);
        }
    }
}

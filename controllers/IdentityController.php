<?php

namespace Controllers;

use App\Http\JsonResponse;
use Classes\IdentityImageService;
use Model\ActiveRecord;
use Throwable;

class IdentityController
{
    public static function uploadForPassenger(): void
    {
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
            JsonResponse::send([
                'success' => false,
                'message' => 'Método no permitido.'
            ], 405);
            return;
        }

        $passengerId = filter_var(
            $_POST['ticket_passenger_id'] ?? null,
            FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 1]]
        );

        if (!$passengerId) {
            JsonResponse::send([
                'success' => false,
                'message' => 'Pasajero no válido.'
            ], 422);
            return;
        }

        if (
            !isset($_FILES['ine_front'], $_FILES['ine_back'])
        ) {
            JsonResponse::send([
                'success' => false,
                'message' => 'Debes subir ambos lados del INE.'
            ], 422);
            return;
        }

        $relativePath = trim(
            $_ENV['IDENTITY_STORAGE_PATH'] ?? 'documents',
            '/'
        );

        $storage = dirname(__DIR__) . '/' . $relativePath;

        if (
            $storage === '' ||
            !str_starts_with($storage, '/')
        ) {
            JsonResponse::send([
                'success' => false,
                'message' => 'Almacenamiento no configurado.'
            ], 500);
            return;
        }

        $db = ActiveRecord::getDB();
        $savedFiles = [];

        try {
            // Verificar que el pasajero existe.
            $stmt = $db->prepare(
                'SELECT id
                 FROM ticket_passengers
                 WHERE id = ?
                 LIMIT 1'
            );

            $stmt->bind_param('i', $passengerId);
            $stmt->execute();

            if (!$stmt->get_result()->fetch_assoc()) {
                JsonResponse::send([
                    'success' => false,
                    'message' => 'Pasajero no encontrado.'
                ], 404);
                return;
            }

            // No reemplazamos documentos existentes
            // sin un flujo explícito de sustitución.
            $stmt = $db->prepare(
                'SELECT id
                 FROM passenger_documents
                 WHERE ticket_passenger_id = ?
                   AND document_type = "ine"
                 LIMIT 1'
            );

            $stmt->bind_param('i', $passengerId);
            $stmt->execute();

            if ($stmt->get_result()->fetch_assoc()) {
                JsonResponse::send([
                    'success' => false,
                    'message' => 'El pasajero ya tiene un INE registrado.'
                ], 409);
                return;
            }

            // Validar que el almacenamiento exista
            // y no esté dentro de una ruta web pública.
            if (!is_dir($storage) || !is_writable($storage)) {
                throw new \RuntimeException(
                    'La carpeta privada no existe o no permite escritura.'
                );
            }

            $front = IdentityImageService::save(
                $_FILES['ine_front'],
                $storage
            );

            $savedFiles[] = $storage . '/' . $front['path'];

            $back = IdentityImageService::save(
                $_FILES['ine_back'],
                $storage
            );

            $savedFiles[] = $storage . '/' . $back['path'];

            $db->begin_transaction();

            $stmt = $db->prepare(
                'INSERT INTO passenger_documents (
                    ticket_passenger_id,
                    document_type,
                    front_path,
                    back_path,
                    front_sha256,
                    back_sha256,
                    verification_status
                ) VALUES (
                    ?, "ine", ?, ?, ?, ?, "pending"
                )'
            );

            $stmt->bind_param(
                'issss',
                $passengerId,
                $front['path'],
                $back['path'],
                $front['sha256'],
                $back['sha256']
            );

            $stmt->execute();
            $documentId = $db->insert_id;

            $db->commit();

            JsonResponse::send([
                'success' => true,
                'message' => 'INE guardado correctamente.',
                'data' => [
                    'id' => $documentId,
                    'ticket_passenger_id' => $passengerId,
                    'verification_status' => 'pending'
                ]
            ], 201);
        } catch (Throwable $e) {
            try {
                $db->rollback();
            } catch (Throwable $rollbackError) {
                error_log($rollbackError->getMessage());
            }

            foreach ($savedFiles as $file) {
                if (is_file($file)) {
                    @unlink($file);
                }
            }

            error_log(
                'Error al guardar INE: ' . $e->getMessage()
            );

            JsonResponse::send([
                'success' => false,
                'message' => 'No se pudo guardar la identificación.'
            ], 500);
        }
    }
}

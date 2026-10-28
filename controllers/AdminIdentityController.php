<?php

namespace Controllers;

use App\Auth\JwtService;
use App\Http\JsonResponse;
use Model\ActiveRecord;
use Throwable;

class AdminIdentityController
{
    private static function adminId(): int
    {
        $userId = JwtService::requireUserId();
        $db = ActiveRecord::getDB();

        $stmt = $db->prepare(
            'SELECT admin FROM users WHERE id = ? LIMIT 1'
        );
        $stmt->bind_param('i', $userId);
        $stmt->execute();

        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$row || (int) $row['admin'] !== 1) {
            JsonResponse::send([
                'success' => false,
                'message' => 'No tienes permiso para consultar identificaciones.'
            ], 403);
            exit;
        }

        return $userId;
    }

    private static function passengerId(): int
    {
        $id = filter_var(
            $_GET['passenger_id'] ?? null,
            FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 1]]
        );

        if (!$id) {
            JsonResponse::send([
                'success' => false,
                'message' => 'ID de pasajero inválido.'
            ], 422);
            exit;
        }

        return (int) $id;
    }

    private static function findDocument(int $passengerId): ?array
    {
        $db = ActiveRecord::getDB();

        $stmt = $db->prepare(
            "SELECT
                pd.id,
                pd.ticket_passenger_id,
                pd.front_path,
                pd.back_path,
                pd.front_sha256,
                pd.back_sha256,
                pd.verification_status,
                pd.uploaded_at,
                pd.verified_at,
                pd.verified_by,
                tp.first_name,
                tp.last_name,
                tp.seat_id,
                tp.ticket_code
             FROM ticket_passengers tp
             LEFT JOIN passenger_documents pd
               ON pd.ticket_passenger_id = tp.id
              AND pd.document_type = 'ine'
             WHERE tp.id = ?
             LIMIT 1"
        );

        $stmt->bind_param('i', $passengerId);
        $stmt->execute();

        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        return $row ?: null;
    }

    private static function storageRoot(): string
    {
        // Misma carpeta que tu ReservationController.
        $backendRoot = realpath(dirname(__DIR__));
        $storage = realpath($backendRoot . '/documents');

        if (!$storage || !is_dir($storage)) {
            throw new \RuntimeException(
                'No se encontró el almacenamiento de documentos.'
            );
        }

        $public = realpath($backendRoot . '/public');

        if (
            $public &&
            (
                $storage === $public ||
                str_starts_with(
                    $storage,
                    $public . DIRECTORY_SEPARATOR
                )
            )
        ) {
            throw new \RuntimeException(
                'Directorio de almacenamiento inseguro.'
            );
        }

        return $storage;
    }

    public static function show(): void
    {
        self::adminId();
        $passengerId = self::passengerId();

        try {
            $document = self::findDocument($passengerId);

            if (!$document) {
                JsonResponse::send([
                    'success' => false,
                    'message' => 'Pasajero no encontrado.'
                ], 404);
                return;
            }

            JsonResponse::send([
                'success' => true,
                'data' => [
                    'passenger_id' => $passengerId,
                    'first_name' => $document['first_name'],
                    'last_name' => $document['last_name'],
                    'seat_id' => $document['seat_id'],
                    'ticket_code' => $document['ticket_code'],
                    'has_identity' => $document['id'] !== null,
                    'has_front' => !empty($document['front_path']),
                    'has_back' => !empty($document['back_path']),
                    'verification_status' =>
                    $document['verification_status'],
                    'uploaded_at' => $document['uploaded_at'],
                    'verified_at' => $document['verified_at']
                ]
            ], 200);
        } catch (Throwable $e) {
            error_log('Admin INE show: ' . $e->getMessage());

            JsonResponse::send([
                'success' => false,
                'message' => 'No se pudo consultar la documentación.'
            ], 500);
        }
    }

    public static function image(): void
    {
        $adminId = self::adminId();
        $passengerId = self::passengerId();

        $side = $_GET['side'] ?? '';

        if (!in_array($side, ['front', 'back'], true)) {
            JsonResponse::send([
                'success' => false,
                'message' => 'Lado de INE inválido.'
            ], 422);
            return;
        }

        try {
            $document = self::findDocument($passengerId);

            if (!$document || !$document['id']) {
                JsonResponse::send([
                    'success' => false,
                    'message' => 'No existe INE para este pasajero.'
                ], 404);
                return;
            }

            $relativePath = $document[$side . '_path'];

            if (!is_string($relativePath) || $relativePath === '') {
                JsonResponse::send([
                    'success' => false,
                    'message' => 'Imagen no encontrada.'
                ], 404);
                return;
            }

            // Solo permitir archivos dentro de identity/.
            if (
                !preg_match(
                    '~^identity/[0-9]{4}/[0-9]{2}/[a-f0-9]{48}\.jpg$~',
                    $relativePath
                )
            ) {
                throw new \RuntimeException(
                    'Ruta de identificación inválida.'
                );
            }

            $storage = self::storageRoot();
            $file = realpath($storage . '/' . $relativePath);

            if (
                !$file ||
                !str_starts_with(
                    $file,
                    $storage . DIRECTORY_SEPARATOR . 'identity' .
                        DIRECTORY_SEPARATOR
                ) ||
                !is_file($file)
            ) {
                JsonResponse::send([
                    'success' => false,
                    'message' => 'Archivo no encontrado.'
                ], 404);
                return;
            }

            $expectedHash = $document[$side . '_sha256'];

            if (
                $expectedHash &&
                !hash_equals(
                    strtolower($expectedHash),
                    hash_file('sha256', $file)
                )
            ) {
                throw new \RuntimeException(
                    'La integridad del INE no coincide.'
                );
            }

            // Registro básico de acceso. Para producción:
            // sustituir/complementar con auditoría persistente.
            error_log(
                'INE_VIEW admin_id=' . $adminId .
                    ' passenger_id=' . $passengerId .
                    ' side=' . $side
            );

            header('Content-Type: image/jpeg');
            header('Content-Disposition: inline');
            header('Content-Length: ' . filesize($file));
            header('Cache-Control: private, no-store, max-age=0');
            header('Pragma: no-cache');
            header('X-Content-Type-Options: nosniff');
            header('Referrer-Policy: no-referrer');
            header('X-Frame-Options: DENY');

            readfile($file);
            exit;
        } catch (Throwable $e) {
            error_log('Admin INE image: ' . $e->getMessage());

            JsonResponse::send([
                'success' => false,
                'message' => 'No se pudo visualizar la identificación.'
            ], 500);
        }
    }

    public static function verify(): void
    {
        $adminId = self::adminId();

        $input = json_decode(
            file_get_contents('php://input'),
            true
        );

        if (!is_array($input)) {
            JsonResponse::send([
                'success' => false,
                'message' => 'Solicitud inválida.'
            ], 422);
            return;
        }

        $passengerId = filter_var(
            $input['passenger_id'] ?? null,
            FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 1]]
        );

        $status = $input['status'] ?? null;

        if (
            !$passengerId ||
            !in_array($status, ['verified', 'rejected'], true)
        ) {
            JsonResponse::send([
                'success' => false,
                'message' => 'Pasajero o estado inválido.'
            ], 422);
            return;
        }

        try {
            $db = ActiveRecord::getDB();

            $stmt = $db->prepare(
                "UPDATE passenger_documents
                 SET verification_status = ?,
                     verified_at = NOW(),
                     verified_by = ?
                 WHERE ticket_passenger_id = ?
                   AND document_type = 'ine'"
            );

            $stmt->bind_param(
                'sii',
                $status,
                $adminId,
                $passengerId
            );

            $stmt->execute();

            $affected = $stmt->affected_rows;
            $stmt->close();

            if ($affected === 0) {
                $document = self::findDocument($passengerId);

                if (!$document || !$document['id']) {
                    JsonResponse::send([
                        'success' => false,
                        'message' => 'No existe INE para este pasajero.'
                    ], 404);
                    return;
                }
            }

            JsonResponse::send([
                'success' => true,
                'message' => $status === 'verified'
                    ? 'Identificación verificada.'
                    : 'Identificación rechazada.',
                'data' => [
                    'passenger_id' => (int) $passengerId,
                    'verification_status' => $status
                ]
            ], 200);
        } catch (Throwable $e) {
            error_log('Admin INE verify: ' . $e->getMessage());

            JsonResponse::send([
                'success' => false,
                'message' => 'No se pudo actualizar la verificación.'
            ], 500);
        }
    }
}

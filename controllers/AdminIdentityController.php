<?php

namespace Controllers;

use App\Auth\JwtService;
use App\Http\JsonResponse;
use Classes\Email;
use Classes\IdentityImageService;
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
                    'has_identity' => (
                        $document['id'] !== null &&
                        !empty($document['front_path']) &&
                        !empty($document['back_path'])
                    ),
                    'has_document_record' => $document['id'] !== null,
                    'can_replace' => (
                        $document['id'] !== null &&
                        $document['verification_status'] === 'rejected'
                    ),
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

        $passengerId = filter_var(
            $input['passenger_id'] ?? null,
            FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 1]]
        );

        $status = $input['status'] ?? '';

        if (
            !$passengerId ||
            !in_array($status, ['verified', 'rejected'], true)
        ) {
            JsonResponse::send([
                'success' => false,
                'message' => 'Solicitud inválida.'
            ], 422);
            return;
        }

        $db = ActiveRecord::getDB();
        $committed = false;
        $pathsToDelete = [];
        $documentId = 0;
        $passenger = null;

        try {
            $db->begin_transaction();

            $stmt = $db->prepare(
                "SELECT
                pd.id AS document_id,
                pd.verification_status,
                pd.front_path,
                pd.back_path,
                tp.first_name,
                tp.last_name,
                tp.email
             FROM passenger_documents pd
             INNER JOIN ticket_passengers tp
               ON tp.id = pd.ticket_passenger_id
             WHERE pd.ticket_passenger_id = ?
               AND pd.document_type = 'ine'
             FOR UPDATE"
            );

            $stmt->bind_param('i', $passengerId);
            $stmt->execute();
            $passenger = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if (!$passenger) {
                $db->rollback();

                JsonResponse::send([
                    'success' => false,
                    'message' => 'El pasajero no tiene un INE registrado.'
                ], 404);
                return;
            }

            // Un INE verificado queda bloqueado.
            if ($passenger['verification_status'] === 'verified') {
                $db->rollback();

                JsonResponse::send([
                    'success' => false,
                    'message' => 'El INE ya fue verificado y no puede modificarse.'
                ], 409);
                return;
            }

            if (
                $passenger['verification_status'] !== 'pending' ||
                empty($passenger['front_path']) ||
                empty($passenger['back_path'])
            ) {
                $db->rollback();

                JsonResponse::send([
                    'success' => false,
                    'message' => 'El INE no está pendiente de revisión.'
                ], 409);
                return;
            }

            $documentId = (int) $passenger['document_id'];

            if ($status === 'verified') {
                $stmt = $db->prepare(
                    "UPDATE passenger_documents
                 SET verification_status = 'verified',
                     verified_at = NOW(),
                     verified_by = ?
                 WHERE id = ?"
                );

                $stmt->bind_param(
                    'ii',
                    $adminId,
                    $documentId
                );
                $stmt->execute();
                $stmt->close();

                self::logIdentityEvent(
                    $db,
                    $documentId,
                    $passengerId,
                    $adminId,
                    'verified'
                );
            } else {
                // Validar ambos archivos ANTES de modificar MySQL.
                $pathsToDelete = [
                    self::privateIdentityFile(
                        $passenger['front_path']
                    ),
                    self::privateIdentityFile(
                        $passenger['back_path']
                    )
                ];

                // Conservar el registro de rechazo,
                // pero quitar las rutas activas.
                $stmt = $db->prepare(
                    "UPDATE passenger_documents
                 SET verification_status = 'rejected',
                     front_path = NULL,
                     back_path = NULL,
                     front_sha256 = NULL,
                     back_sha256 = NULL,
                     verified_at = NOW(),
                     verified_by = ?,
                     rejection_notified_at = NULL,
                     rejection_email_error = NULL
                 WHERE id = ?"
                );

                $stmt->bind_param(
                    'ii',
                    $adminId,
                    $documentId
                );
                $stmt->execute();
                $stmt->close();

                self::logIdentityEvent(
                    $db,
                    $documentId,
                    $passengerId,
                    $adminId,
                    'rejected'
                );
            }

            $db->commit();
            $committed = true;

            if ($status === 'verified') {
                JsonResponse::send([
                    'success' => true,
                    'message' => 'Identificación verificada.',
                    'data' => [
                        'passenger_id' => (int) $passengerId,
                        'verification_status' => 'verified'
                    ]
                ], 200);
                return;
            }

            // Después del commit, eliminar físicamente
            // los archivos que ya no están vinculados.
            $filesDeleted = true;

            foreach (array_unique($pathsToDelete) as $path) {
                if (is_file($path) && !@unlink($path)) {
                    $filesDeleted = false;
                    error_log(
                        'No se pudo eliminar INE de pasajero ' .
                            $passengerId
                    );
                }
            }

            $emailSent = false;
            $emailError = null;

            if ($filesDeleted) {
                try {
                    $emailSent = self::sendRejectionEmail(
                        $passenger
                    );

                    if (!$emailSent) {
                        $emailError = 'El servidor SMTP no confirmó el envío.';
                    }
                } catch (Throwable $mailError) {
                    $emailError = $mailError->getMessage();
                    error_log(
                        'INE rejection mail: ' . $mailError->getMessage()
                    );
                }
            } else {
                $emailError =
                    'La eliminación física de los archivos quedó incompleta.';
            }

            $stmt = $db->prepare(
                "UPDATE passenger_documents
             SET rejection_notified_at =
                    CASE WHEN ? = 1 THEN NOW() ELSE NULL END,
                 rejection_email_error = ?
             WHERE id = ?"
            );

            $sentFlag = $emailSent ? 1 : 0;
            $shortError = $emailError
                ? substr($emailError, 0, 255)
                : null;

            $stmt->bind_param(
                'isi',
                $sentFlag,
                $shortError,
                $documentId
            );
            $stmt->execute();
            $stmt->close();

            self::logIdentityEvent(
                $db,
                $documentId,
                $passengerId,
                $adminId,
                $emailSent
                    ? 'notification_sent'
                    : 'notification_failed',
                $shortError
            );

            JsonResponse::send([
                'success' => true,
                'message' => $emailSent
                    ? 'INE rechazado y pasajero notificado.'
                    : 'INE rechazado, pero la notificación requiere atención.',
                'data' => [
                    'passenger_id' => (int) $passengerId,
                    'verification_status' => 'rejected',
                    'files_deleted' => $filesDeleted,
                    'email_sent' => $emailSent,
                    'email_error' => $emailError
                ]
            ], 200);
        } catch (Throwable $e) {
            if (!$committed) {
                try {
                    $db->rollback();
                } catch (Throwable $rollbackError) {
                    error_log($rollbackError->getMessage());
                }
            }

            error_log('Admin INE verify: ' . $e->getMessage());

            JsonResponse::send([
                'success' => false,
                'message' => $committed
                    ? 'Se actualizó el INE, pero falló una operación posterior.'
                    : 'No se pudo actualizar la identificación.'
            ], 500);
        }
    }



    private static function logIdentityEvent(
        \mysqli $db,
        int $documentId,
        int $passengerId,
        int $adminId,
        string $type,
        ?string $notes = null
    ): void {
        $stmt = $db->prepare(
            "INSERT INTO identity_document_events (
            passenger_document_id,
            ticket_passenger_id,
            admin_user_id,
            event_type,
            notes
        ) VALUES (?, ?, ?, ?, ?)"
        );

        $stmt->bind_param(
            'iiiss',
            $documentId,
            $passengerId,
            $adminId,
            $type,
            $notes
        );

        $stmt->execute();
        $stmt->close();
    }

    private static function privateIdentityFile(
        string $relativePath
    ): string {
        if (!preg_match(
            '~^identity/[0-9]{4}/[0-9]{2}/[a-f0-9]{48}\.jpg$~',
            $relativePath
        )) {
            throw new \RuntimeException(
                'Ruta del documento no válida.'
            );
        }

        $storage = self::storageRoot();
        $base = realpath($storage . '/identity');
        $path = realpath($storage . '/' . $relativePath);

        if (
            !$base ||
            !$path ||
            !str_starts_with(
                $path,
                $base . DIRECTORY_SEPARATOR
            ) ||
            !is_file($path)
        ) {
            throw new \RuntimeException(
                'Archivo privado no encontrado.'
            );
        }

        return $path;
    }

    private static function sendRejectionEmail(
        array $passenger
    ): bool {
        $phone = trim(
            $_ENV['IDENTITY_SUPPORT_PHONE'] ?? ''
        );

        if ($phone === '') {
            throw new \RuntimeException(
                'Falta configurar IDENTITY_SUPPORT_PHONE.'
            );
        }

        $recipient = trim($passenger['email'] ?? '');

        if (!filter_var($recipient, FILTER_VALIDATE_EMAIL)) {
            throw new \RuntimeException(
                'El pasajero no tiene un correo válido.'
            );
        }

        $name = trim(
            ($passenger['first_name'] ?? '') . ' ' .
                ($passenger['last_name'] ?? '')
        );

        $safeName = htmlspecialchars(
            $name,
            ENT_QUOTES,
            'UTF-8'
        );

        $safePhone = htmlspecialchars(
            $phone,
            ENT_QUOTES,
            'UTF-8'
        );


        // WhatsApp requiere el número en formato internacional,
        // sin espacios, paréntesis ni símbolo +.
        // Ejemplo México: 529991234567.
        $whatsappNumber = preg_replace('/\D+/', '', $phone);

        if (
            !$whatsappNumber ||
            strlen($whatsappNumber) < 10 ||
            strlen($whatsappNumber) > 15
        ) {
            throw new \RuntimeException(
                'El número de WhatsApp no está configurado correctamente.'
            );
        }

        $whatsappMessage =
            'Hola, soy ' . $name .
            '. Recibí un correo de RH Experience indicando que ' .
            'mi identificación INE fue rechazada. ' .
            'Me gustaría recibir indicaciones para enviar ' .
            'nuevamente mis documentos.';

        $whatsappUrl = 'https://wa.me/' .
            $whatsappNumber .
            '?text=' .
            rawurlencode($whatsappMessage);

        $safeWhatsappUrl = htmlspecialchars(
            $whatsappUrl,
            ENT_QUOTES,
            'UTF-8'
        );


        $subject = 'Acción requerida: identificación INE - RH Experience';

        $body = <<<HTML
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
</head>
<body style="margin:0;padding:24px;background:#f1f5f9;font-family:Arial,sans-serif;color:#1e293b;">
  <table role="presentation" style="max-width:620px;width:100%;margin:auto;background:#ffffff;border-radius:16px;border-collapse:separate;">
    <tr>
      <td style="background:#315fa8;color:white;padding:28px;border-radius:16px 16px 0 0;">
        <h1 style="margin:0;font-size:24px;">RH Experience</h1>
        <p style="margin:8px 0 0;">Actualización de documentación</p>
      </td>
    </tr>
    <tr>
      <td style="padding:32px;">
        <h2 style="font-size:21px;margin-top:0;">Hola, {$safeName}</h2>
        <p style="line-height:1.7;">
          Te informamos que las imágenes de tu identificación
          oficial (INE) no pudieron ser validadas.
        </p>
        <p style="line-height:1.7;">
          Por seguridad, las imágenes anteriores fueron retiradas
          de tu expediente.
        </p>
        <p style="line-height:1.7;">
          Para continuar con la actualización de tus documentos,
          comunícate con nuestro equipo de RH Experience al:
        </p>
        <p style="background:#eff6ff;border-radius:12px;padding:18px;text-align:center;font-size:22px;font-weight:bold;color:#315fa8;">
          {$safePhone}
        </p>


<!-- Botón de contacto por WhatsApp -->
<table role="presentation"
       cellpadding="0"
       cellspacing="0"
       border="0"
       style="width:100%;margin:24px 0;">
  <tr>
    <td align="center">
      <a
        href="{$safeWhatsappUrl}"
        target="_blank"
        style="
          display:inline-block;
          background-color:#25D366;
          color:#ffffff;
          padding:15px 26px;
          border-radius:10px;
          font-family:Arial,sans-serif;
          font-size:15px;
          font-weight:bold;
          text-decoration:none;
          text-align:center;
        "
      >
        Contactar a RH Experience por WhatsApp
      </a>
    </td>
  </tr>
</table>
<box border radius="2xl" clip gap={0}>
  <box background="#315fa8" theme="dark" padding={4} gap={1}>
    <title size="lg" color="#ffffff">RH Experience</title>
    <text color="#dce8ff" size="sm">Actualización de documentación</text>
  </box>
  <box padding={4} gap={3}>
    **Hola, María González:**

    Te informamos que las imágenes de tu identificación oficial (INE) no pudieron ser validadas.

    Para continuar con la actualización de tus documentos, comunícate con nuestro equipo de RH Experience.

    <box background="surface-secondary" padding={3} radius="lg" align="center">
      <title size="lg" tabularNums>+52 999 123 4567</title>
      <caption>Ejemplo de número de atención</caption>
    </box>
    <box background="#25D366" radius="lg" padding={3} align="center" theme="dark">
      <row align="center" justify="center" gap={2}>
        <icon name="message-circle" color="#ffffff"/>
        <text weight="semibold" color="#ffffff">Contactar por WhatsApp</text>
      </row>
    </box>
    <text size="sm" color="secondary">El botón abrirá la conversación con un mensaje precargado. El pasajero deberá pulsar Enviar en WhatsApp.</text>
    <divider color="subtle"/>
    <text size="sm">Atentamente, **Equipo RH Experience**</text>
  </box>
</box>

Con esos dos cambios, **cada vez que se rechace un INE y se envíe el correo**, el pasajero podrá pulsar el botón y contactar directamente al número configurado, sin necesidad de copiarlo o guardarlo.

El enlace no envía automáticamente el mensaje: solamente abre el chat de WhatsApp con el texto preparado.

        <p style="line-height:1.7;">
          Nuestro equipo te indicará cómo proporcionar nuevamente
          las fotografías del frente y reverso de tu INE de manera segura.
        </p>
        <p style="font-size:13px;color:#64748b;">
          No respondas a este correo adjuntando tu identificación,
          a menos que nuestro equipo te indique un canal autorizado.
        </p>
        <p style="margin-top:28px;">Atentamente,<br><strong>Equipo RH Experience</strong></p>
      </td>
    </tr>
  </table>
</body>
</html>
HTML;

        $email = new Email(
            $recipient,
            $name,
            $subject,
            ''
        );

        $email->setBody($body);

        return $email->send();
    }


    public static function replace(): void
    {
        $adminId = self::adminId();

        $passengerId = filter_var(
            $_POST['passenger_id'] ?? null,
            FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 1]]
        );

        if (!$passengerId) {
            JsonResponse::send([
                'success' => false,
                'message' => 'Pasajero inválido.'
            ], 422);
            return;
        }

        foreach (['ine_front', 'ine_back'] as $field) {
            $file = $_FILES[$field] ?? null;

            if (
                !is_array($file) ||
                ($file['error'] ?? UPLOAD_ERR_NO_FILE)
                !== UPLOAD_ERR_OK ||
                !is_uploaded_file($file['tmp_name'] ?? '') ||
                (int) ($file['size'] ?? 0) < 1 ||
                (int) ($file['size'] ?? 0) > 8 * 1024 * 1024
            ) {
                JsonResponse::send([
                    'success' => false,
                    'message' => "Archivo inválido: {$field}."
                ], 422);
                return;
            }
        }

        $db = ActiveRecord::getDB();
        $createdFiles = [];
        $committed = false;

        try {
            $storage = self::storageRoot();
            $db->begin_transaction();

            $stmt = $db->prepare(
                "SELECT id, verification_status
             FROM passenger_documents
             WHERE ticket_passenger_id = ?
               AND document_type = 'ine'
             FOR UPDATE"
            );

            $stmt->bind_param('i', $passengerId);
            $stmt->execute();
            $document = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if (
                !$document ||
                $document['verification_status'] !== 'rejected'
            ) {
                $db->rollback();

                JsonResponse::send([
                    'success' => false,
                    'message' => 'Solo puedes reemplazar un INE rechazado.'
                ], 409);
                return;
            }

            $front = IdentityImageService::save(
                $_FILES['ine_front'],
                $storage
            );
            $createdFiles[] = $storage . '/' . $front['path'];

            $back = IdentityImageService::save(
                $_FILES['ine_back'],
                $storage
            );
            $createdFiles[] = $storage . '/' . $back['path'];

            $documentId = (int) $document['id'];

            $stmt = $db->prepare(
                "UPDATE passenger_documents
             SET front_path = ?,
                 back_path = ?,
                 front_sha256 = ?,
                 back_sha256 = ?,
                 verification_status = 'pending',
                 uploaded_at = NOW(),
                 verified_at = NULL,
                 verified_by = NULL
             WHERE id = ?"
            );

            $stmt->bind_param(
                'ssssi',
                $front['path'],
                $back['path'],
                $front['sha256'],
                $back['sha256'],
                $documentId
            );

            $stmt->execute();
            $stmt->close();

            self::logIdentityEvent(
                $db,
                $documentId,
                $passengerId,
                $adminId,
                'replaced'
            );

            $db->commit();
            $committed = true;

            JsonResponse::send([
                'success' => true,
                'message' => 'INE reemplazado. Pendiente de verificación.',
                'data' => [
                    'passenger_id' => (int) $passengerId,
                    'verification_status' => 'pending'
                ]
            ], 200);
        } catch (Throwable $e) {
            if (!$committed) {
                try {
                    $db->rollback();
                } catch (Throwable $rollbackError) {
                    error_log($rollbackError->getMessage());
                }

                foreach ($createdFiles as $file) {
                    if (is_file($file)) {
                        @unlink($file);
                    }
                }
            }

            error_log('INE replace: ' . $e->getMessage());

            JsonResponse::send([
                'success' => false,
                'message' => 'No se pudieron reemplazar las imágenes.'
            ], 500);
        }
    }


    public static function waiverPdf(): void
    {
        self::adminId();
        $passengerId = self::passengerId();

        try {
            $db = \Model\ActiveRecord::getDB();

            $stmt = $db->prepare(
                "SELECT
                signed_pdf_path,
                signed_pdf_sha256
             FROM passenger_waivers
             WHERE ticket_passenger_id = ?
               AND status = 'signed'
             ORDER BY id DESC
             LIMIT 1"
            );

            $stmt->bind_param('i', $passengerId);
            $stmt->execute();

            $row = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if (!$row) {
                \App\Http\JsonResponse::send([
                    'success' => false,
                    'message' => 'El pasajero no tiene responsiva firmada.'
                ], 404);
                return;
            }

            $relative = $row['signed_pdf_path'];

            if (!preg_match(
                '~^waivers/[0-9]{4}/[0-9]{2}/[a-f0-9]{48}\.pdf$~',
                $relative
            )) {
                throw new \RuntimeException(
                    'Ruta de responsiva no válida.'
                );
            }

            $storage = self::storageRoot();
            $folder = realpath($storage . '/waivers');
            $file = realpath($storage . '/' . $relative);

            if (
                !$folder ||
                !$file ||
                !str_starts_with(
                    $file,
                    $folder . DIRECTORY_SEPARATOR
                ) ||
                !is_file($file)
            ) {
                \App\Http\JsonResponse::send([
                    'success' => false,
                    'message' => 'Archivo no encontrado.'
                ], 404);
                return;
            }

            $hash = hash_file('sha256', $file);

            if (!hash_equals(
                strtolower($row['signed_pdf_sha256']),
                strtolower($hash)
            )) {
                throw new \RuntimeException(
                    'La integridad del PDF no coincide.'
                );
            }

            header('Content-Type: application/pdf');
            header(
                'Content-Disposition: attachment; filename="responsiva.pdf"'
            );
            header('Content-Length: ' . filesize($file));
            header('Cache-Control: private, no-store');
            header('Pragma: no-cache');
            header('X-Content-Type-Options: nosniff');

            readfile($file);
            exit;
        } catch (\Throwable $e) {
            error_log(
                'Error consultando responsiva: ' . $e->getMessage()
            );

            \App\Http\JsonResponse::send([
                'success' => false,
                'message' => 'No se pudo consultar la responsiva.'
            ], 500);
        }
    }
}
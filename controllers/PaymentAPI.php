<?php

namespace Controllers;


use Throwable;
use Model\TicketPassenger;
use Model\Coupon;
use App\Time\Clock;
use App\Http\JsonResponse;
use Model\Ticket;
use Classes\Email;
use Classes\TicketPdfService;


class PaymentAPI
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

    public static function paymentIntent(): void
    {
        header('Access-Control-Allow-Origin: http://localhost:3000');
        header('Access-Control-Allow-Credentials: true');
        header('Access-Control-Allow-Headers: Authorization, Content-Type');
        header('Access-Control-Allow-Methods:POST');
        header('Allow: POST');

        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            JsonResponse::send(['success' => false, 'message' => 'Method Not Allowed'], 405);
            exit;
        }

        $payload = self::readBody();

        // Validación mínima
        if (empty($payload['trip_id']) || empty($payload['seats']) || empty($payload['passengers'])) {
            JsonResponse::send(['success' => false, 'message' => 'Payload incompleto'], 422);
            exit;
        }

        $ticket = null;
        $createdPassengerIds = [];

        // Helper para limpiar en caso de fallo:
        $cleanup = function () use (&$ticket, &$createdPassengerIds) {
            try {
                if ($ticket && !empty($ticket->id)) {
                    // Si tienes FK ON DELETE CASCADE, esto borra también los pasajeros:
                    $ticket->eliminar();
                } else {
                    // Si NO tienes FK, limpia manualmente pasajeros creados:
                    if (!empty($createdPassengerIds)) {
                        foreach ($createdPassengerIds as $pid) {
                            $p = TicketPassenger::find($pid);
                            if ($p) {
                                $p->eliminar();
                            }
                        }
                    }
                }
            } catch (\Throwable $e) {
                error_log('Cleanup error: ' . $e->getMessage());
            }
        };

        // 1) Validar cupón (si viene)
        $coupon = null;
        if (!empty($payload['couponId'])) {
            try {
                $coupon = Coupon::where('id', $payload['couponId']);
                if (!$coupon) {
                    JsonResponse::send(['success' => false, 'message' => 'Cupon no encontrado'], 404);
                    exit;
                }

                $usageLimit = is_null($coupon->usage_limit) ? null : (int)$coupon->usage_limit;
                $isActive   = (string)$coupon->is_active === '1';
                $today      = \App\Time\Clock::today();

                if ($usageLimit === 0) {
                    JsonResponse::send(['success' => false, 'message' => 'Ya se canjearon todos los cupones disponibles.'], 400);
                    exit;
                }
                if (!empty($coupon->expiry_date) && $coupon->expiry_date < $today) {
                    JsonResponse::send(['success' => false, 'message' => 'El cupon ya vencio.'], 400);
                    exit;
                }
                if (!$isActive) {
                    JsonResponse::send(['success' => false, 'message' => 'El cupon no esta activo.'], 400);
                    exit;
                }
            } catch (\Throwable $e) {
                JsonResponse::send(['success' => false, 'message' => 'Error al buscar el cupon'], 500);
                exit;
            }
        }

        // 2) Crear Ticket
        try {
            $ticket = new Ticket([
                'trip_id'      => $payload['trip_id'],
                'seat_numbers' => json_encode($payload['seats'], JSON_UNESCAPED_UNICODE),
            ]);

            $resTicket = $ticket->guardar();
            if (empty($resTicket['resultado'])) {
                JsonResponse::send(['success' => false, 'message' => 'No se pudo crear el ticket'], 500);
                exit;
            }
            $ticket->id = (int)($resTicket['id'] ?? 0);
            if (!$ticket->id) {
                // Por si la BD no devolvió id
                $cleanup();
                JsonResponse::send(['success' => false, 'message' => 'Ticket creado sin ID'], 500);
                exit;
            }
        } catch (\Throwable $e) {
            JsonResponse::send(['success' => false, 'message' => $e->getMessage()], 500);
            exit;
        }

        // 3) Crear Pasajeros
        try {
            foreach ($payload['passengers'] as $p) {
                $passenger = new TicketPassenger([
                    'ticket_id'  => (int)$ticket->id,
                    'seat_id'    => $p['seat_id']    ?? '',
                    'first_name' => $p['first_name'] ?? '',
                    'last_name'  => $p['last_name']  ?? '',
                    'email'      => $p['email']      ?? '',
                    'phone'      => $p['phone']      ?? '',
                    'born_date'  => $p['born_date']  ?? null,
                    'allergies'  => json_encode($p['allergies'] ?? [], JSON_UNESCAPED_UNICODE),
                ]);

                $resP = $passenger->guardar();
                if (empty($resP['resultado'])) {
                    // Falló crear este pasajero -> eliminar lo creado
                    $cleanup();
                    JsonResponse::send(['success' => false, 'message' => 'No se pudo crear un pasajero'], 500);
                    exit;
                }
                $pid = (int)($resP['id'] ?? 0);
                if ($pid) {
                    $createdPassengerIds[] = $pid;
                }
            }
        } catch (\Throwable $e) {
            $cleanup();
            JsonResponse::send(['success' => false, 'message' => 'Error al guardar los pasajeros, revisa los datos ingresados'], 500);
            exit;
        }

        // 4) Descontar cupón (si aplica)
        if ($coupon) {
            try {
                if (!is_null($coupon->usage_limit)) {
                    $coupon->usage_limit = max(0, ((int)$coupon->usage_limit) - 1);
                    if ((int)$coupon->usage_limit === 0) {
                        $coupon->is_active = 0;
                    }
                }
                $resC = $coupon->guardar();
                if (empty($resC['resultado'])) {
                    // Falló actualizar cupón -> revertir ticket + pasajeros
                    $cleanup();
                    JsonResponse::send(['success' => false, 'message' => 'No se pudo actualizar el cupon'], 500);
                    exit;
                }
            } catch (\Throwable $e) {
                // Fallo cupón -> revertir ticket + pasajeros
                $cleanup();
                JsonResponse::send(['success' => false, 'message' => 'Error al actualizar el cupon'], 500);
                exit;
            }
        }

        // 5) PDF + Email (esto NO revierte la BD si falla)
        $pdfPath = null;
        try {
            $pdfPath = TicketPdfService::buildTicketsPdf($payload);
        } catch (\Throwable $e) {
            error_log('Error generando PDF de boletos: ' . $e->getMessage());
        }

        $email = new Email(
            $payload['tickets_email'] ?? '',
            $payload['tickets_name']  ?? '',
            'Confirmación de Reserva',
            formatearFecha($payload['date']),
            implode(', ', $payload['seats'] ?? []),
            $payload['trip_id'] ?? ''
        );

        if ($pdfPath && file_exists($pdfPath)) {
            $fileName = sprintf('RH-Tickets-Trip-%s.pdf', $payload['tickets_name'] ?? 'X');
            $email->addAttachment($pdfPath, $fileName);
        }

        $sent = false;
        try {
            $sent = $email->send();
        } catch (\Throwable $e) {
            error_log('Error enviando email: ' . $e->getMessage());
        }

        if ($sent && $pdfPath && is_file($pdfPath)) {
            if (!@unlink($pdfPath)) {
                error_log("No se pudo eliminar el PDF: {$pdfPath}");
            }
        }

        // 6) Respuesta final
        JsonResponse::send([
            'success' => true,
            'message' => 'Reservacion exitosa, revisa tu correo electronico.',
        ], 200);
    }


    public static function getCoupon(): void
    {
        $code = $_GET['code'] ?? null;
        $today = Clock::today();

        if (!$code) {
            JsonResponse::send(['success' => false, 'message' => 'Falta codigo de descuento'], 400);
        }

        $coupon = Coupon::where('code', $code);
        if (!$coupon) {
            JsonResponse::send(['success' => false, 'message' => 'Cupon no encontrado'], 404);
        }

        if ($coupon->usage_limit === '0') {
            JsonResponse::send(['success' => false, 'message' => 'Ya se canjearon todos los cupones disponibles.'], 400);
        }

        if ($coupon->expiry_date < $today) {
            JsonResponse::send(['success' => false, 'message' => 'El cupon ya vencio.'], 400);
        }
        if ($coupon->is_active === '0') {
            JsonResponse::send(['success' => false, 'message' => 'El cupon no esta activo.'], 400);
        }

        //todo bien y se va a usar
        $coupon->usage_limit = $coupon->usage_limit - 1;

        JsonResponse::send([
            'success' => true,
            'message' => 'Cupon validado',
            'data' => $coupon,

        ], 200);
    }
}

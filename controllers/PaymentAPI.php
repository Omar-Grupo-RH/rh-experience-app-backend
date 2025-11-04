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

        // --- MONTO (obligatorio para Stripe) ---
        // Esperamos total en unidades de la moneda (p.ej. 2100.00 MXN)
        // Lo convertimos a centavos. Si ya viene en centavos, adapta esta parte.
        $amountUnits = $payload['total'] ?? null;
        if (!is_numeric($amountUnits) || $amountUnits <= 0) {
            JsonResponse::send(['success' => false, 'message' => 'Total inválido'], 422);
            exit;
        }
        // Redondeo seguro a centavos
        $amountInCents = (int) round(((float)$amountUnits) * 100);

        $currency = $payload['currency'] ?? ($_ENV['STRIPE_CURRENCY'] ?? 'mxn'); // default MXN

        $ticket = null;
        $createdPassengerIds = [];

        // Helper para limpiar en caso de fallo:
        $cleanup = function () use (&$ticket, &$createdPassengerIds) {
            try {
                if ($ticket && !empty($ticket->id)) {
                    $ticket->eliminar(); // si hay cascada, borra pasajeros
                } else {
                    if (!empty($createdPassengerIds)) {
                        foreach ($createdPassengerIds as $pid) {
                            $p = TicketPassenger::find($pid);
                            if ($p) $p->eliminar();
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
                    $cleanup();
                    JsonResponse::send(['success' => false, 'message' => 'No se pudo crear un pasajero'], 500);
                    exit;
                }
                $pid = (int)($resP['id'] ?? 0);
                if ($pid) $createdPassengerIds[] = $pid;
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
                    $cleanup();
                    JsonResponse::send(['success' => false, 'message' => 'No se pudo actualizar el cupon'], 500);
                    exit;
                }
            } catch (\Throwable $e) {
                $cleanup();
                JsonResponse::send(['success' => false, 'message' => 'Error al actualizar el cupon'], 500);
                exit;
            }
        }

        // 5) Crear PaymentIntent (NUEVO)
        try {
            \Stripe\Stripe::setApiKey($_ENV['SECRET_KEY_STRIPE']);

            // Idempotency key para evitar intentos duplicados si el front reintenta
            $idempotencyKey = 'pi_ticket_' . $ticket->id . '_' . md5(json_encode([$amountInCents, $currency, $payload['seats']]));

            $paymentIntent = \Stripe\PaymentIntent::create([
                'amount' => $amountInCents,          // centavos
                'currency' => $currency,             // 'mxn' por defecto
                'automatic_payment_methods' => ['enabled' => true],
                'metadata' => [
                    'ticket_id'   => (string)$ticket->id,
                    'trip_id'     => (string)$payload['trip_id'],
                    'seats'       => implode(',', $payload['seats'] ?? []),
                    'coupon_id'   => (string)($payload['couponId'] ?? ''),
                    'customer_email' => (string)($payload['tickets_email'] ?? ''),
                    'tickets_name'   => (string)($payload['tickets_name'] ?? ''),
                    'date'        => (string)($payload['date'] ?? ''),
                ],
                'receipt_email' => $payload['tickets_email'] ?? null, // opcional
            ], [
                'idempotency_key' => $idempotencyKey
            ]);
        } catch (\Stripe\Exception\ApiErrorException $e) {
            // Stripe falló -> revertimos lo creado en BD
            $cleanup();
            JsonResponse::send(['success' => false, 'message' => 'Stripe error: ' . $e->getMessage()], 500);
            exit;
        } catch (\Throwable $e) {
            $cleanup();
            JsonResponse::send(['success' => false, 'message' => 'Error creando PaymentIntent'], 500);
            exit;
        }

        // 6) (OPCIONAL) PDF + Email — RECOMIENDO mover esto al webhook cuando status=succeeded
        $pdfPath = null;
        try {
            $pdfPath = TicketPdfService::buildTicketsPdf($payload);
        } catch (\Throwable $e) {
            error_log('Error generando PDF de boletos: ' . $e->getMessage());
        }

        $email = new Email(
            $payload['tickets_email'] ?? '',
            $payload['tickets_name']  ?? '',
            'Confirmación de Reserva (pendiente de pago)',
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
            if (!@unlink($pdfPath)) error_log("No se pudo eliminar el PDF: {$pdfPath}");
        }

        // 7) Respuesta final con clientSecret
        JsonResponse::send([
            'success'       => true,
            'message'       => 'Reservación creada. Completa el pago para confirmar.',
            'clientSecret'  => $paymentIntent->client_secret,
            'payment_intent_id' => $paymentIntent->id,
            'ticket_id'     => $ticket->id,
            'currency'      => $currency,
            'amount'        => $amountInCents, // centavos
        ], 200);
    }


    public static function paymentTest(): void
    {
        header('Content-Type: application/json');

        \Stripe\Stripe::setApiKey($_ENV['SECRET_KEY_STRIPE']);

        $input = json_decode(file_get_contents('php://input'), true);


        try {
            $paymentIntent = \Stripe\PaymentIntent::create([
                'amount' => $input['amount'], // en centavos
                'currency' => 'usd',
                'automatic_payment_methods' => [
                    'enabled' => true,
                ],
            ]);

            echo json_encode([
                'clientSecret' => $paymentIntent->client_secret,
            ]);
        } catch (Error $e) {
            http_response_code(500);
            echo json_encode(['error' => $e->getMessage()]);
        }
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
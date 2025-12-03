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
use Model\Buyer;
use Model\Order;
use DateTime;
use DateTimeZone;
use Model\Trip;

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
        if (empty($payload['trip_data']['trip_id']) || empty($payload['trip_data']['seats']) || empty($payload['passengers'])) {
            JsonResponse::send(['success' => false, 'message' => 'Payload incompleto'], 422);
            exit;
        }

        // --- MONTO ---
        $amountUnits = $payload['price_data']['total'] ?? null; // ejemplo: 2100.00
        if (!is_numeric($amountUnits) || $amountUnits <= 0) {
            JsonResponse::send(['success' => false, 'message' => 'Total inválido'], 422);
            exit;
        }
        $amountInCents = (int) round(((float)$amountUnits) * 100); // a centavos
        $currency      = $payload['price_data']['currency'] ?? ($_ENV['STRIPE_CURRENCY'] ?? 'mxn');

        $ticket               = null;
        $createdPassengerIds  = [];
        $buyer                = null;
        $order                = null;
        $coupon               = null;
        $couponId             = !empty($payload['price_data']['couponId'])
            ? (int)$payload['price_data']['couponId']
            : null;

        // Helper para limpiar en caso de fallo ANTES de crear el PaymentIntent
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

        // 1) Validar cupón (SOLO valida; el descuento real se aplica al monto pero NO se descuenta usage_limit todavía)
        if ($couponId !== null) {
            try {
                $coupon = Coupon::where('id', $couponId);
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

        // 2) Crear Ticket (reserva / apartado)
        try {
            $ticket = new Ticket([
                'trip_id'      => $payload['trip_data']['trip_id'],
                'seat_numbers' => json_encode($payload['trip_data']['seats'], JSON_UNESCAPED_UNICODE),
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
                    'first_name' => cleanString($p['first_name']) ?? '',
                    'last_name'  => cleanString($p['last_name'])  ?? '',
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

        // 4) Crear Buyer
        $payload['buyer']['full_name'] = cleanString($payload['buyer']['full_name']);
        try {
            $buyer = new Buyer($payload['buyer']);
            $resBuyer = $buyer->guardar();
            if (empty($resBuyer['resultado'])) {
                $cleanup();
                JsonResponse::send(['success' => false, 'message' => 'No se pudo crear el buyer'], 500);
                exit;
            }
            $buyer->id = (int)($resBuyer['id'] ?? 0);
            if (!$buyer->id) {
                $cleanup();
                JsonResponse::send(['success' => false, 'message' => 'Buyer creado sin ID'], 500);
                exit;
            }
        } catch (\Throwable $e) {
            $cleanup();
            JsonResponse::send(['success' => false, 'message' => 'Error creando el buyer'], 500);
            exit;
        }

        // 5) Crear Order en status "pending"
        $subtotalUnits = $payload['price_data']['subtotal'] ?? $amountUnits;
        $discountUnits = $payload['price_data']['discount'] ?? 0;

        $subtotalCents = (int) round(((float)$subtotalUnits) * 100);
        $discountCents = (int) round(((float)$discountUnits) * 100);

        try {
            $order = new Order([
                'ticket_id'      => (int)$ticket->id,
                'buyer_id'       => (int)$buyer->id,
                'coupon_id'      => $couponId,
                'subtotal_cents' => $subtotalCents,
                'discount_cents' => $discountCents,
                'total_cents'    => $amountInCents,
                'currency'       => strtoupper($currency),
                'status'         => 'pendiente',
            ]);

            $resOrder = $order->guardar();
            if (empty($resOrder['resultado'])) {
                $cleanup();
                JsonResponse::send(['success' => false, 'message' => 'No se pudo crear la orden'], 500);
                exit;
            }

            $order->id = (int)($resOrder['id'] ?? 0);
            if (!$order->id) {
                $cleanup();
                JsonResponse::send(['success' => false, 'message' => 'Orden creada sin ID'], 500);
                exit;
            }
        } catch (\Throwable $e) {
            $cleanup();
            JsonResponse::send(['success' => false, 'message' => $e->getMessage()], 500);
            exit;
        }

        // 6) Crear PaymentIntent en Stripe
        try {
            \Stripe\Stripe::setApiKey($_ENV['SECRET_KEY_STRIPE']);

            $idempotencyKey = 'pi_ticket_' . $ticket->id . '_' . md5(json_encode([
                $amountInCents,
                $currency,
                $payload['trip_data']['seats']
            ]));

            $paymentIntent = \Stripe\PaymentIntent::create([
                'amount'   => $amountInCents,
                'currency' => $currency,
                'automatic_payment_methods' => ['enabled' => true],
                'metadata' => [
                    'ticket_id'      => (int)$ticket->id,
                    'order_id'       => (int)$order->id,
                    'buyer_id'       => (int)$buyer->id,
                    'trip_id'        => (int)$payload['trip_data']['trip_id'],
                    'seats'          => implode(',', $payload['trip_data']['seats'] ?? []),
                    'coupon_id'      =>  $couponId !== null ? (string)$couponId : '',
                    'customer_email' => (string)($payload['trip_data']['tickets_email'] ?? ''),
                    'tickets_name'   => (string)($payload['trip_data']['tickets_name'] ?? ''),
                    'date' => (string)(new DateTime('now', new DateTimeZone('America/Merida')))->format('Y-m-d'),
                    'subtotal_cents' => (int)$subtotalCents,
                    'discount_cents' => (int)$discountCents,
                    'total_cents'    => (int)$amountInCents,
                ],
                'receipt_email' => $payload['trip_data']['tickets_email'] ?? null,
            ], [
                'idempotency_key' => $idempotencyKey
            ]);
        } catch (\Stripe\Exception\ApiErrorException $e) {
            // Stripe falló -> revertimos lo creado
            $cleanup();
            JsonResponse::send(['success' => false, 'message' => 'Stripe error: ' . $e->getMessage()], 500);
            exit;
        } catch (\Throwable $e) {
            $cleanup();
            JsonResponse::send(['success' => false, 'message' => $e->getMessage()], 500);
            exit;
        }


        JsonResponse::send([
            'success'           => true,
            'message'           => 'Reservación creada. Completa el pago para confirmar.',
            'clientSecret'      => $paymentIntent->client_secret,
            'payment_intent_id' => $paymentIntent->id,
            'ticket_id'         => $ticket->id,
            'buyer_id'          => $buyer->id,
            'order_id'          => $order->id,
            'currency'          => $currency,
            'amount'            => $amountInCents,
        ], 200);
    }

    /**
     * 2) CONFIRMAR PAGO: marcar order como paid, descontar cupón, generar PDF y enviar email
     * Endpoint: POST /payment/confirm
     */
    public static function confirmPayment(): void
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            JsonResponse::send(['success' => false, 'message' => 'Method Not Allowed'], 405);
            exit;
        }

        $payload = self::readBody();
        $paymentIntentId = $payload['payment_intent_id'] ?? null;

        if (!$paymentIntentId) {
            JsonResponse::send(['success' => false, 'message' => 'payment_intent_id requerido'], 422);
            exit;
        }

        \Stripe\Stripe::setApiKey($_ENV['SECRET_KEY_STRIPE']);

        try {
            $pi = \Stripe\PaymentIntent::retrieve($paymentIntentId);
        } catch (\Throwable $e) {
            JsonResponse::send(['success' => false, 'message' => 'No se pudo obtener el PaymentIntent'], 500);
            exit;
        }

        if ($pi->status !== 'succeeded') {
            JsonResponse::send([
                'success' => false,
                'message' => 'El pago aún no está confirmado en Stripe (status: ' . $pi->status . ')',
            ], 400);
            exit;
        }

        // Recuperar IDs desde metadata
        $orderId  = isset($pi->metadata->order_id) ? (int)$pi->metadata->order_id : null;
        $ticketId = isset($pi->metadata->ticket_id) ? (int)$pi->metadata->ticket_id : null;
        $buyerId  = isset($pi->metadata->buyer_id) ? (int)$pi->metadata->buyer_id : null;
        $couponId = !empty($pi->metadata->coupon_id) ? (int)$pi->metadata->coupon_id : null;
        $emailTo = !empty($pi->metadata->customer_email) ?
            (string)$pi->metadata->customer_email : (!empty($pi->receipt_email) ? (string)$pi->receipt_email : null);


        $order  = Order::find($orderId);
        $ticket = Ticket::find($ticketId);
        $buyer  = Buyer::find($buyerId);

        $dateTrip = Trip::where('id', $ticket->trip_id);

        error_log("IDs obtenidos: order_id={$orderId}, ticket_id={$ticketId}, buyer_id={$buyerId}");
        error_log("Email del metadata: " . ($emailTo ?? 'NO ENCONTRADO'));

        if (!$orderId || !$ticketId || !$buyerId) {
            JsonResponse::send([
                'success' => false,
                'message' => 'Faltan IDs en metadata del PaymentIntent',
            ], 500);
            exit;
        }

        if (!$orderId || !$ticketId || !$buyerId) {
            JsonResponse::send([
                'success' => false,
                'message' => 'Faltan IDs en metadata del PaymentIntent',
            ], 500);
            exit;
        }


        if (!$order || !$ticket || !$buyer) {
            error_log("No se encontraron: order=" . ($order ? 'Sí' : 'No') .
                ", ticket=" . ($ticket ? 'Sí' : 'No') .
                ", buyer=" . ($buyer ? 'Sí' : 'No'));
            JsonResponse::send([
                'success' => false,
                'message' => 'Orden, ticket o buyer no encontrados',
            ], 404);
            exit;
        }

        // Idempotencia: si ya estaba paid, no duplicamos nada
        if ($order->status === 'pagado') {
            JsonResponse::send([
                'success'  => true,
                'message'  => 'La orden ya estaba pagada.',
                'order_id' => $order->id,
            ], 200);
            exit;
        }

        // 1) Actualizar Order a paid
        $order->status            = 'pagado';
        $order->payment_intent_id = $pi->id;
        try {
            $res = $order->guardar();
            if (empty($res['resultado'])) {
                JsonResponse::send(['success' => false, 'message' => 'No se pudo marcar la orden como pagada'], 500);
                exit;
            }
        } catch (\Throwable $e) {
            JsonResponse::send(['success' => false, 'message' => 'Error actualizando la orden: ' . $e->getMessage()], 500);
            exit;
        }

        // 2) Descontar cupón solo AHORA que el pago sí se completó
        if ($couponId) {
            try {
                $coupon = Coupon::where('id', $couponId);
                if ($coupon) {
                    if (!is_null($coupon->usage_limit)) {
                        $coupon->usage_limit = max(0, ((int)$coupon->usage_limit) - 1);
                        if ((int)$coupon->usage_limit === 0) {
                            $coupon->is_active = 0;
                        }
                    }
                    $coupon->guardar();
                }
            } catch (\Throwable $e) {
                error_log('Error actualizando cupón tras pago: ' . $e->getMessage());
            }
        }

        // 3) Generar PDF y enviar correo de boletos (pago confirmado)
        $pdfPath = null;
        $pdfGenerated = false;
        $emailSent = false;

        try {
            $passengers = TicketPassenger::whereAll('ticket_id', $ticket->id) ?? [];
            $seats = json_decode($ticket->seat_numbers ?? '[]', true) ?: [];

            // Asegura que haya email para enviar
            if (!$emailTo) {
                error_log('No hay email para enviar los boletos');
            } else {
                $payloadPdf = [
                    'trip_data' => [
                        'date'          => formatearFecha($dateTrip->trip_date) ?? 'No hay date',
                        'seats'         => $seats,
                        'tickets_email' => $emailTo ?? '',
                        'tickets_name'  => $buyer->full_name ?? 'Cliente',
                    ],
                    'passengers' => [],
                ];


                foreach ($passengers as $p) {
                    $allergies = json_decode($p->allergies ?? '[]', true);
                    if (!is_array($allergies)) {
                        $allergies = [];
                    }

                    $payloadPdf['passengers'][] = [
                        'seat_id'    => $p->seat_id ?? '',
                        'first_name' => $p->first_name ?? '',
                        'last_name'  => $p->last_name ?? '',
                        'email'      => $p->email ?? '',
                        'phone'      => $p->phone ?? '',
                        'born_date'  => $p->born_date ?? '',
                        'allergies'  => $allergies,
                    ];
                }

                error_log("Payload para PDF: " . json_encode($payloadPdf));

                error_log('Generando PDF con datos: ' . json_encode($payloadPdf));

                // Generar PDF
                error_log("Llamando a buildTicketsPdf...");
                $pdfPath = TicketPdfService::buildTicketsPdf($payloadPdf);
                error_log("Resultado de buildTicketsPdf: " . ($pdfPath ?? 'NULL'));

                if ($pdfPath && file_exists($pdfPath)) {
                    $pdfGenerated = true;
                    error_log("PDF generado exitosamente: {$pdfPath} (" . filesize($pdfPath) . " bytes)");
                } else {
                    error_log("ERROR: PDF no se generó o no existe");
                    // Verifica si hay algún error en el log de PHP
                }

                if ($emailTo && $pdfGenerated) {
                    error_log("Preparando email para: {$emailTo}");

                    // Enviar email con PDF adjunto
                    $email = new Email(
                        $emailTo,
                        $payloadPdf['trip_data']['tickets_name'],
                        '🎟️ Tus boletos RH Experience - Pago Confirmado',
                        formatearFecha($dateTrip->trip_date),
                        implode(', ', $payloadPdf['trip_data']['seats']),

                    );

                    $fileName = sprintf(
                        'RH-Tickets-Trip-%s-%s.pdf',
                        $payloadPdf['trip_data']['trip_id'],
                        date('Ymd-His')
                    );

                    error_log("Adjuntando archivo: {$pdfPath} como {$fileName}");
                    $email->addAttachment($pdfPath, $fileName);


                    $emailSent = $email->send();

                    if ($emailSent) {
                        error_log("Email enviado exitosamente a: $emailTo");
                    } else {
                        error_log('Error al enviar email: ' . $email->getError());
                    }

                    // Limpiar archivo PDF temporal (opcional)
                    // unlink($pdfPath);
                } else {
                    error_log('No se pudo generar el PDF');
                }
            }
        } catch (\Throwable $e) {
            error_log('Error generando/enviando boletos: ' . $e->getMessage() . "\n" . $e->getTraceAsString());
        }

        JsonResponse::send([
            'success'  => true,
            'message'  => 'Pago confirmado y orden marcada como pagada.',
            'payload_pdf'=> $payloadPdf

        ], 200);
    }

    public static function cancelPayment(): void
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            JsonResponse::send(['success' => false, 'message' => 'Method Not Allowed'], 405);
            exit;
        }

        $payload  = self::readBody();
        $orderId  = $payload['order_id']  ?? null;
        $ticketId = $payload['ticket_id'] ?? null;

        if (!$orderId && !$ticketId) {
            JsonResponse::send(['success' => false, 'message' => 'order_id o ticket_id requerido'], 422);
            exit;
        }

        try {
            $order  = null;
            $ticket = null;
            $buyer  = null;
            $coupon = null;

            if ($orderId) {
                $order = Order::find((int)$orderId);
            } elseif ($ticketId) {
                $order = Order::where('ticket_id', (int)$ticketId);
            }

            if ($order) {
                $ticketId = (int)$order->ticket_id;
                $buyerId  = (int)$order->buyer_id;
                $ticket   = Ticket::find($ticketId);
                $buyer    = Buyer::find($buyerId);

                if (!empty($order->coupon_id)) {
                    $coupon = Coupon::where('id', (int)$order->coupon_id);
                    // OJO: no revertimos usage_limit aquí porque solo lo descontamos en confirmPayment()
                }
            } elseif ($ticketId) {
                $ticket = Ticket::find((int)$ticketId);
            }

            // 1) Borrar ORDER si existe
            if ($order) {
                $order->eliminar();
            }

            // 2) Borrar TICKET (por cascada se van pasajeros si está bien definido)
            if ($ticket) {
                $ticket->eliminar();
            }

            // 3) Borrar BUYER solo si no tiene otras órdenes (para no borrar uno que sí pagó otra cosa)
            if ($buyer) {
                $otherOrders = Order::whereArray(['buyer_id' => $buyer->id]);
                if (empty($otherOrders)) {
                    $buyer->eliminar();
                }
            }

            JsonResponse::send([
                'success' => true,
                'message' => 'Reserva cancelada y datos limpiados por fallo de pago.',
            ], 200);
        } catch (\Throwable $e) {
            JsonResponse::send([
                'success' => false,
                'message' => 'Error al cancelar la reserva: ' . $e->getMessage(),
            ], 500);
        }
    }

    public static function getCoupon(): void
    {
        $code = $_GET['code'] ?? null;
        $today = Clock::today();

        if (!$code) {
            JsonResponse::send(['success' => false, 'message' => 'Falta codigo de descuento'], 400);
            exit;
        }

        $coupon = Coupon::where('code', $code);
        if (!$coupon) {
            JsonResponse::send(['success' => false, 'message' => 'Cupon no encontrado'], 404);
            exit;
        }

        if ($coupon->usage_limit === '0') {
            JsonResponse::send(['success' => false, 'message' => 'Ya se canjearon todos los cupones disponibles.'], 400);
            exit;
        }

        if ($coupon->expiry_date < $today) {
            JsonResponse::send(['success' => false, 'message' => 'El cupon ya vencio.'], 400);
            exit;
        }
        if ($coupon->is_active === '0') {
            JsonResponse::send(['success' => false, 'message' => 'El cupon no esta activo.'], 400);
            exit;
        }

        JsonResponse::send([
            'success' => true,
            'message' => 'Cupon validado',
            'data' => $coupon,
        ], 200);
    }
}

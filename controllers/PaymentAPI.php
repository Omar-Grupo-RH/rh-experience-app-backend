<?php

namespace Controllers;


use Throwable;
use Model\Coupon;
use App\Time\Clock;
use App\Http\JsonResponse;
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
        $stripe = new \Stripe\StripeClient($_ENV['SECRET_KEY_STRIPE']);

        $payload = self::readBody();

        // 1) Generar el PDF de boletos (una página por pasajero)
        $pdfPath = null;
        try {
            $pdfPath = TicketPdfService::buildTicketsPdf($payload);
        } catch (\Throwable $e) {
            error_log('Error generando PDF de boletos: ' . $e->getMessage());
        }

        $email = new Email(
            $payload['tickets_email'],
            $payload['tickets_name'],
            'Confirmación de Reserva',
            formatearFecha($payload['date']),
            implode(', ', $payload['seats'] ?? []),   // asientos como string
            $payload['trip_id'] ?? ''                 // trip id
        );

        // 3) Adjuntar el PDF (si se generó bien)
        if ($pdfPath && file_exists($pdfPath)) {
            $fileName = sprintf('RH-Tickets-Trip-%s.pdf', $payload['tickets_name'] ?? 'X');
            $email->addAttachment($pdfPath, $fileName);
        }

        // 4) Enviar
        $sent = $email->send();

        // 5) Borrar el PDF si se envió correctamente
        if ($sent && $pdfPath && is_file($pdfPath)) {
            if (!@unlink($pdfPath)) {
                error_log("No se pudo eliminar el PDF: {$pdfPath}");
            }
        }

        JsonResponse::send([
            'success' => true,
            'message' => 'Cupon validado',
            'data' => $payload,
        ], 200);

        JsonResponse::send([
            'success' => true,
            'message' => $sent ? 'Boletos enviados por email' : 'Se generó la reserva pero el email no pudo enviarse',
            'data'    => [
                'email_sent' => $sent,
                'pdf_path'   => $pdfPath,
                'payload'    => $payload,
            ],
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

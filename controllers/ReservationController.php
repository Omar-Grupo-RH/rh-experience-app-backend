<?php

namespace Controllers;

use App\Http\JsonResponse;
use Classes\Email;
use Classes\TicketPdfService;
use Model\Buyer;
use Model\Order;
use Model\Ticket;
use Model\TicketPassenger;
use Model\ActiveRecord; // Importamos la base

class ReservationController
{
    public static function store()
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            JsonResponse::send(['success' => false, 'message' => 'Método no permitido'], 405);
            exit;
        }

        $payload = self::readBody();

        // Obtenemos la conexión de MySQLi desde tu ActiveRecord
        $db = ActiveRecord::getDB();

        // Iniciamos la transacción de MySQLi
        $db->begin_transaction();

        try {
            // 1. Crear el Ticket
            $ticket = new Ticket([
                'trip_id'      => $payload['trip_data']['trip_id'],
                'seat_numbers' => json_encode($payload['trip_data']['seats'], JSON_UNESCAPED_UNICODE),
            ]);

            $resTicket = $ticket->guardar();
            if (!$resTicket['resultado']) throw new \Exception("No se pudo crear el ticket maestro.");
            $ticket->id = $resTicket['id'];

            // 2. Crear Pasajeros
            foreach ($payload['passengers'] as $p) {
                $passenger = new TicketPassenger([
                    'ticket_id'  => (int)$ticket->id,
                    'seat_id'    => $p['seat_id'] ?? '',
                    'first_name' => trim($p['first_name'] ?? ''),
                    'last_name'  => trim($p['last_name'] ?? ''),
                    'email'      => $p['email'] ?? '',
                    'phone'      => $p['phone'] ?? '',
                    'born_date'  => $p['born_date'] ?? null,
                    'allergies'  => json_encode($p['allergies'] ?? [], JSON_UNESCAPED_UNICODE),
                ]);

                $resP = $passenger->guardar();
                if (!$resP['resultado']) throw new \Exception("Error al registrar al pasajero: " . $p['first_name']);
            }

            // 3. Crear Buyer (Comprador)
            $buyer = new Buyer($payload['buyer']);
            $resBuyer = $buyer->guardar();
            if (!$resBuyer['resultado']) throw new \Exception("No se pudo registrar la información del comprador.");
            $buyer->id = $resBuyer['id'];

            // 4. Crear la Orden
            $order = new Order([
                'ticket_id' => (int)$ticket->id,
                'buyer_id'  => (int)$buyer->id,
            ]);

            $resOrder = $order->guardar();
            if (!$resOrder['resultado']) throw new \Exception("Error al generar la orden de compra.");

            // 5. Descuento de Asientos (Evitando Sobreventa / Race Condition)
            $tripId = (int)$payload['trip_data']['trip_id'];
            $numPassengers = count($payload['passengers']);

            // Ejecutamos SQL directo para que sea una operación atómica en la DB
            $sqlTrip = "UPDATE trips SET total_seats = total_seats - {$numPassengers} 
                        WHERE id = {$tripId} AND total_seats >= {$numPassengers}";

            $db->query($sqlTrip);

            if ($db->affected_rows === 0) {
                throw new \Exception("Lo sentimos, ya no hay asientos disponibles para este viaje.");
            }

            // SI TODO ESTÁ BIEN: Confirmamos los cambios en la BD
            $db->commit();

            // 6. Proceso de Email y PDF (Fuera de la transacción)
            self::enviarBoletosConfirmados($ticket, $buyer, $payload);

            JsonResponse::send([
                'success' => true,
                'message' => 'Reservación completada exitosamente.',
                'ticket_id' => $ticket->id
            ], 201);
        } catch (\Throwable $e) {
            // SI ALGO FALLA: Deshacemos todo lo que se intentó guardar
            $db->rollback();

            error_log("Error en Reserva: " . $e->getMessage());
            JsonResponse::send([
                'success' => false,
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Lógica aislada para el envío de archivos
     */
    private static function enviarBoletosConfirmados($ticket, $buyer, $payload)
    {
        try {
            $emailTo = $payload['trip_data']['tickets_email'] ?? $buyer->email;

            // Generar payload para el PDFService
            $payloadPdf = [
                'trip_data' => [
                    'trip_id' => $payload['trip_data']['trip_id'],
                    'date'    => $payload['trip_data']['date'],
                    'seats'   => json_decode($ticket->seat_numbers),
                    'tickets_name' => $buyer->full_name
                ],
                'passengers' => $payload['passengers']
            ];

            $pdfPath = TicketPdfService::buildTicketsPdf($payloadPdf);

            if ($pdfPath && file_exists($pdfPath)) {
                $email = new Email($emailTo, $buyer->full_name, 'Tus Boletos RH', $payload['trip_data']['date'], implode(', ', $payloadPdf['trip_data']['seats']));
                $email->addAttachment($pdfPath, 'boletos-rh.pdf');
                $email->send();
            }
        } catch (\Throwable $e) {
            // No detenemos la respuesta al cliente si falla el mail
            error_log("Error enviando email post-venta: " . $e->getMessage());
        }
    }

    private static function readBody(): array
    {
        $payload = $_POST;
        if (empty($payload)) {
            $raw = file_get_contents('php://input');
            $payload = json_decode($raw, true) ?? [];
        }
        return $payload;
    }
}
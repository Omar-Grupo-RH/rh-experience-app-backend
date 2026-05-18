<?php

namespace Controllers;

use App\Http\JsonResponse;
use App\Time\Clock;
use Classes\Email;
use Classes\TicketPdfService;
use Model\ActiveRecord;
use Model\Ticket;
use Model\Trip;
use Throwable;

class TripController
{
    private static function readBody(): array
    {
        $payload = $_POST;
        $isJson  = stripos($_SERVER['CONTENT_TYPE'] ?? '', 'application/json') !== false;

        if (empty($payload) && $isJson) {
            $raw = file_get_contents('php://input');
            $payload = json_decode($raw, true);

            if (!is_array($payload)) {
                JsonResponse::send([
                    'success' => false,
                    'message' => 'JSON inválido'
                ], 400);
                exit;
            }
        }

        foreach ($payload as $k => $v) {
            if (is_string($v)) {
                $payload[$k] = trim($v);
            }
        }

        return $payload;
    }

    public static function index()
    {
        $trips = Trip::all();

        JsonResponse::send([
            'success' => true,
            'data'    => $trips,
        ], 200);
    }

    public static function show()
    {
        $id = $_GET['id'] ?? null;

        if (!$id) {
            JsonResponse::send([
                'success' => false,
                'message' => 'Falta id'
            ], 400);
            exit;
        }

        $trip = Trip::where('id', $id);

        if (!$trip) {
            JsonResponse::send([
                'success' => false,
                'message' => 'Evento no encontrado'
            ], 404);
            exit;
        }

        $ticketsByTrip = Ticket::whereAll('trip_id', $id);

        $seatsLocation = [];

        foreach ($ticketsByTrip as $ticket) {
            $seatNumbersArray = json_decode($ticket->seat_numbers, true) ?? [];
            $seatsLocation = array_merge($seatsLocation, $seatNumbersArray);
        }

        JsonResponse::send([
            'success' => true,
            'data' => [
                'trip_id'         => (int) $trip->id,
                'title'           => $trip->title,
                'trip_date'       => $trip->trip_date,
                'total_seats'     => (int) $trip->total_seats,
                'seats_location'  => $seatsLocation,
            ],
        ], 200);
    }

    public static function store()
    {
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $payload = self::readBody();
            $trip = new Trip($payload);

            $existTrip = Trip::where('trip_date', $trip->trip_date);

            if ($existTrip) {
                JsonResponse::send([
                    'errors' => 'Ya hay un RH EXPERIENCE agendado esa fecha.'
                ], 422);
                exit;
            }

            try {
                $result = $trip->guardar();
            } catch (Throwable $e) {
                error_log('Error al agendar RH EXPERIENCE: ' . $e->getMessage());

                JsonResponse::send([
                    'success' => false,
                    'message' => 'Error al agendar el RH EXPERIENCE'
                ], 500);
                exit;
            }

            if (empty($result['resultado'])) {
                JsonResponse::send([
                    'success' => false,
                    'message' => 'No se pudo agendar el RH EXPERIENCE'
                ], 500);
                exit;
            }

            $trip->id = $result['id'] ?? $trip->id;

            JsonResponse::send([
                'success' => true,
                'message' => 'RH EXPERIENCE agendado correctamente',
                'data' => [
                    'id'            => (int) $trip->id,
                    'title'         => $trip->title,
                    'trip_date'     => $trip->trip_date,
                    'total_seats'   => (int) $trip->total_seats,
                    'avaible_seats' => (int) $trip->avaible_seats,
                ],
            ], 201);
        }
    }

    public static function update(): void
    {
        if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
            http_response_code(204);
            exit;
        }

        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            JsonResponse::send([
                'success' => false,
                'message' => 'Method Not Allowed'
            ], 405);
            exit;
        }

        $payload = self::readBody();

        $id = $payload['id'] ?? null;

        if (!$id) {
            JsonResponse::send([
                'success' => false,
                'message' => 'Falta id'
            ], 400);
            exit;
        }

        $newDate = $payload['trip_date'] ?? null;

        if (!$newDate) {
            JsonResponse::send([
                'success' => false,
                'message' => 'Falta la nueva fecha del RH EXPERIENCE'
            ], 400);
            exit;
        }

        $trip = Trip::find($id);

        if (!$trip) {
            JsonResponse::send([
                'success' => false,
                'message' => 'Tour no encontrado'
            ], 404);
            exit;
        }

        $oldDate = $trip->trip_date;
        $tripTitle = $trip->title;

        $today = Clock::today();

        if ($newDate < $today) {
            JsonResponse::send([
                'success' => false,
                'message' => 'No puedes reagendar el tour a una fecha pasada.'
            ], 400);
            exit;
        }

        if (self::tripDateExistsForAnotherTrip($newDate, (int) $trip->id)) {
            JsonResponse::send([
                'success' => false,
                'message' => 'Ya existe un RH EXPERIENCE agendado en esta fecha.'
            ], 400);
            exit;
        }

        $dateChanged = $oldDate !== $newDate;

        $trip->trip_date = $newDate;

        try {
            $result = $trip->guardar();
        } catch (Throwable $e) {
            error_log('Error al reagendar RH EXPERIENCE: ' . $e->getMessage());

            JsonResponse::send([
                'success' => false,
                'message' => 'Error al reagendar el RH EXPERIENCE'
            ], 500);
            exit;
        }

        if (empty($result['resultado'])) {
            JsonResponse::send([
                'success' => false,
                'message' => 'No se pudo reagendar el RH EXPERIENCE'
            ], 500);
            exit;
        }

        $notificationResult = [
            'emails_sent' => 0,
            'emails_failed' => 0,
            'tickets_found' => 0,
        ];

        if ($dateChanged) {
            $notificationResult = self::notifyTripUpdated(
                (int) $trip->id,
                $tripTitle,
                $oldDate,
                $newDate
            );
        }

        JsonResponse::send([
            'success' => true,
            'message' => $dateChanged
                ? 'RH EXPERIENCE reagendado correctamente. Se notificó a los pasajeros registrados.'
                : 'RH EXPERIENCE actualizado correctamente.',
            'data' => $trip,
            'notifications' => $notificationResult,
        ], 200);
    }

    public static function destroy(): void
    {
        if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
            http_response_code(204);
            exit;
        }

        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            JsonResponse::send([
                'success' => false,
                'message' => 'Method Not Allowed'
            ], 405);
            exit;
        }

        $payload = self::readBody();
        $id = $payload['id'] ?? null;

        if (!$id) {
            JsonResponse::send([
                'success' => false,
                'message' => 'Falta id'
            ], 400);
            exit;
        }

        try {
            $trip = Trip::find($id);
        } catch (Throwable $e) {
            JsonResponse::send([
                'success' => false,
                'message' => 'Error al buscar el evento'
            ], 500);
            return;
        }

        if (!$trip) {
            JsonResponse::send([
                'success' => false,
                'message' => 'Evento no encontrado'
            ], 404);
            return;
        }

        try {
            $ok = $trip->eliminar();
        } catch (Throwable $e) {
            JsonResponse::send([
                'success' => false,
                'message' => 'Error al eliminar el evento'
            ], 500);
            exit;
        }

        if (!$ok) {
            JsonResponse::send([
                'success' => false,
                'message' => 'No se pudo eliminar el evento'
            ], 500);
            exit;
        }

        JsonResponse::send([
            'success' => true,
            'message' => 'RH EXPERIENCE eliminado correctamente',
        ], 200);
    }

    private static function tripDateExistsForAnotherTrip(string $tripDate, int $currentTripId): bool
    {
        $db = ActiveRecord::getDB();

        $query = "
            SELECT id
            FROM trips
            WHERE trip_date = ?
            AND id != ?
            LIMIT 1
        ";

        $stmt = $db->prepare($query);

        if (!$stmt) {
            throw new \Exception('Error preparando validación de fecha: ' . $db->error);
        }

        $stmt->bind_param('si', $tripDate, $currentTripId);
        $stmt->execute();

        $result = $stmt->get_result();

        return (bool) $result->fetch_assoc();
    }

    private static function notifyTripUpdated(
        int $tripId,
        string $tripTitle,
        string $oldDate,
        string $newDate
    ): array {
        $tickets = self::getTicketsWithPassengersByTrip($tripId);

        $emailsSent = 0;
        $emailsFailed = 0;

        foreach ($tickets as $ticket) {
            try {
                $sent = self::sendTripUpdatedEmail(
                    $ticket,
                    $tripTitle,
                    $oldDate,
                    $newDate
                );

                if ($sent) {
                    $emailsSent++;
                } else {
                    $emailsFailed++;
                }
            } catch (Throwable $e) {
                $emailsFailed++;
                error_log('Error notificando actualización de viaje. Ticket ID ' . $ticket['ticket_id'] . ': ' . $e->getMessage());
            }
        }

        return [
            'tickets_found' => count($tickets),
            'emails_sent' => $emailsSent,
            'emails_failed' => $emailsFailed,
        ];
    }

    private static function getTicketsWithPassengersByTrip(int $tripId): array
    {
        $db = ActiveRecord::getDB();

        $query = "
            SELECT
                tk.id AS ticket_id,
                tk.seat_numbers,

                tp.id AS passenger_id,
                tp.seat_id,
                tp.first_name,
                tp.last_name,
                tp.email,
                tp.phone
            FROM tickets tk
            INNER JOIN ticket_passengers tp
                ON tp.ticket_id = tk.id
            WHERE tk.trip_id = ?
            ORDER BY tk.id ASC, tp.seat_id ASC
        ";

        $stmt = $db->prepare($query);

        if (!$stmt) {
            throw new \Exception('Error preparando consulta de tickets: ' . $db->error);
        }

        $stmt->bind_param('i', $tripId);
        $stmt->execute();

        $result = $stmt->get_result();

        $tickets = [];

        while ($row = $result->fetch_assoc()) {
            $ticketId = (int) $row['ticket_id'];

            if (!isset($tickets[$ticketId])) {
                $seatNumbers = json_decode($row['seat_numbers'] ?? '[]', true);

                if (!is_array($seatNumbers)) {
                    $seatNumbers = [];
                }

                $tickets[$ticketId] = [
                    'ticket_id' => $ticketId,
                    'seat_numbers' => $seatNumbers,
                    'passengers' => [],
                ];
            }

            $tickets[$ticketId]['passengers'][] = [
                'seat_id' => $row['seat_id'],
                'first_name' => $row['first_name'],
                'last_name' => $row['last_name'],
                'email' => $row['email'],
                'phone' => $row['phone'],
            ];
        }

        return array_values($tickets);
    }

    private static function sendTripUpdatedEmail(
        array $ticket,
        string $tripTitle,
        string $oldDate,
        string $newDate
    ): bool {
        $passengers = $ticket['passengers'] ?? [];

        if (empty($passengers)) {
            return false;
        }

        $mainPassenger = $passengers[0];

        $emailTo = trim($mainPassenger['email'] ?? '');

        if (!$emailTo || !filter_var($emailTo, FILTER_VALIDATE_EMAIL)) {
            error_log('No se pudo enviar actualización: email inválido para ticket ' . $ticket['ticket_id']);
            return false;
        }

        $mainPassengerName = trim(
            ($mainPassenger['first_name'] ?? '') . ' ' . ($mainPassenger['last_name'] ?? '')
        );

        if ($mainPassengerName === '') {
            $mainPassengerName = 'Cliente RH Experience';
        }

        $seatNumbers = $ticket['seat_numbers'] ?? [];

        if (empty($seatNumbers)) {
            $seatNumbers = array_values(array_filter(array_map(function ($p) {
                return $p['seat_id'] ?? null;
            }, $passengers)));
        }

        $payloadPdf = [
            'trip_data' => [
                'trip_id' => '',
                'date' => $newDate,
                'seats' => $seatNumbers,
                'tickets_name' => $mainPassengerName,
            ],
            'passengers' => $passengers,
        ];

        $ticketPdfPath = TicketPdfService::buildTicketsPdf($payloadPdf);

        $email = new Email(
            $emailTo,
            $mainPassengerName,
            'Actualización de tu RH Experience',
            $newDate,
            implode(', ', $seatNumbers),
            (string) $ticket['ticket_id']
        );

        $email->setBody(
            self::buildTripUpdatedEmailHtml(
                $mainPassengerName,
                $tripTitle,
                $oldDate,
                $newDate,
                implode(', ', $seatNumbers)
            )
        );

        if ($ticketPdfPath && file_exists($ticketPdfPath)) {
            $email->addAttachment($ticketPdfPath, 'boleto-actualizado-rh-experience.pdf');
        }

        return $email->send();
    }

    private static function buildTripUpdatedEmailHtml(
        string $name,
        string $tripTitle,
        string $oldDate,
        string $newDate,
        string $seats
    ): string {
        $name = htmlspecialchars($name, ENT_QUOTES, 'UTF-8');
        $tripTitle = htmlspecialchars($tripTitle, ENT_QUOTES, 'UTF-8');
        $oldDate = htmlspecialchars($oldDate, ENT_QUOTES, 'UTF-8');
        $newDate = htmlspecialchars($newDate, ENT_QUOTES, 'UTF-8');
        $seats = htmlspecialchars($seats, ENT_QUOTES, 'UTF-8');

        $year = date('Y');

        return <<<HTML
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <title>Actualización de RH Experience</title>
</head>
<body style="margin:0;padding:0;background:#f8fafc;font-family:Arial,sans-serif;color:#1f2937;">
  <table width="100%" cellpadding="0" cellspacing="0" style="background:#f8fafc;padding:30px 0;">
    <tr>
      <td align="center">
        <table width="620" cellpadding="0" cellspacing="0" style="background:#ffffff;border-radius:18px;overflow:hidden;border:1px solid #e5e7eb;">
          <tr>
            <td style="background:#315fa8;padding:28px;color:#ffffff;">
              <h1 style="margin:0;font-size:26px;">RH Experience</h1>
              <p style="margin:8px 0 0;font-size:14px;">Actualización importante sobre tu viaje</p>
            </td>
          </tr>

          <tr>
            <td style="padding:28px;">
              <div style="display:inline-block;background:#fff7ed;color:#c2410c;border:1px solid #fed7aa;padding:8px 14px;border-radius:999px;font-size:12px;font-weight:bold;margin-bottom:18px;">
                VIAJE ACTUALIZADO
              </div>

              <h2 style="margin:0 0 12px;font-size:22px;color:#111827;">
                Hola {$name}
              </h2>

              <p style="font-size:15px;line-height:1.6;color:#4b5563;">
                Te informamos que tu RH Experience ha sido actualizado y reagendado.
                Adjuntamos tu nuevo boleto con la fecha actualizada.
              </p>

              <div style="background:#f1f5f9;border-radius:14px;padding:18px;margin:20px 0;">
                <p style="margin:0;font-size:13px;color:#64748b;">RH Experience</p>
                <p style="margin:5px 0 0;font-size:18px;font-weight:bold;color:#111827;">{$tripTitle}</p>
              </div>

              <table width="100%" cellpadding="0" cellspacing="0" style="margin:20px 0;border-collapse:collapse;">
                <tr>
                  <td style="width:50%;padding:14px;background:#fff7ed;border:1px solid #fed7aa;border-radius:12px;">
                    <p style="margin:0;font-size:11px;color:#c2410c;text-transform:uppercase;font-weight:bold;">Fecha anterior</p>
                    <p style="margin:6px 0 0;font-size:16px;color:#7c2d12;font-weight:bold;">{$oldDate}</p>
                  </td>
                  <td style="width:16px;"></td>
                  <td style="width:50%;padding:14px;background:#ecfdf5;border:1px solid #bbf7d0;border-radius:12px;">
                    <p style="margin:0;font-size:11px;color:#15803d;text-transform:uppercase;font-weight:bold;">Nueva fecha</p>
                    <p style="margin:6px 0 0;font-size:16px;color:#14532d;font-weight:bold;">{$newDate}</p>
                  </td>
                </tr>
              </table>

              <div style="background:#eff6ff;border:1px solid #bfdbfe;border-radius:14px;padding:18px;margin:20px 0;">
                <p style="margin:0;font-size:13px;color:#1d4ed8;font-weight:bold;">Asientos</p>
                <p style="margin:6px 0 0;font-size:15px;color:#1e3a8a;">{$seats}</p>
              </div>

              <p style="font-size:15px;line-height:1.6;color:#4b5563;">
                Por favor conserva el boleto actualizado adjunto en este correo y preséntalo el día del viaje.
              </p>

              <div style="background:#f8fafc;border-radius:14px;padding:18px;margin-top:24px;border:1px solid #e2e8f0;">
                <p style="margin:0 0 8px;font-weight:bold;color:#111827;">¿Necesitas ayuda?</p>
                <p style="margin:0;color:#475569;font-size:14px;">Correo: experience@gruporh.mx</p>
                <p style="margin:4px 0 0;color:#475569;font-size:14px;">Teléfono: +52 999 242 0278</p>
              </div>
            </td>
          </tr>

          <tr>
            <td style="padding:18px 28px;background:#f8fafc;color:#94a3b8;font-size:12px;text-align:center;">
              &copy; {$year} RH Experience. Este correo fue enviado automáticamente.
            </td>
          </tr>
        </table>
      </td>
    </tr>
  </table>
</body>
</html>
HTML;
    }
}
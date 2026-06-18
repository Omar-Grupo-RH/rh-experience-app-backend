<?php

namespace Controllers;

use App\Http\JsonResponse;
use Classes\Email;
use Classes\TicketPdfService;
use Model\ActiveRecord;
use Model\Order;
use Model\ReservationRegistration;
use Model\Ticket;
use Model\TicketPassenger;
use Model\TripPassenger;

class ReservationController
{
    public static function store()
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            JsonResponse::send([
                'success' => false,
                'message' => 'Método no permitido'
            ], 405);
            return;
        }

        $db = ActiveRecord::getDB();

        try {
            $payload = self::readBody();
            self::validatePayload($payload);

            $idempotencyKey = self::getIdempotencyKey($payload);
            $normalizedPayload = self::normalizePayloadForHash($payload);
            $payloadHash = hash(
                'sha256',
                json_encode($normalizedPayload, JSON_UNESCAPED_UNICODE)
            );

            $tripId = (int) $payload['trip_data']['trip_id'];
            $seats = self::normalizeSeats($payload['trip_data']['seats']);
            $numPassengers = count($payload['passengers']);

            if (count($seats) !== $numPassengers) {
                JsonResponse::send([
                    'success' => false,
                    'message' => 'El número de asientos no coincide con el número de pasajeros.'
                ], 422);
                return;
            }

            $db->begin_transaction();

            $idem = self::lockIdempotencyKey($db, $idempotencyKey, $payloadHash);

            if ($idem['status'] === 'completed') {
                $db->commit();

                $response = json_decode($idem['response_json'] ?? '{}', true);

                JsonResponse::send($response ?: [
                    'success' => true,
                    'message' => 'Reservación ya procesada.',
                    'ticket_id' => (int) $idem['ticket_id']
                ], 200);

                return;
            }

            if ($idem['payload_hash'] !== $payloadHash) {
                $db->rollback();

                JsonResponse::send([
                    'success' => false,
                    'message' => 'La clave de idempotencia ya fue usada con una reservación diferente.'
                ], 409);

                return;
            }

            self::ensureSeatsAreAvailable($db, $tripId, $seats, $numPassengers);

            $ticket = new Ticket([
                'trip_id' => $tripId,
                'seat_numbers' => $seats
            ]);

            $resTicket = $ticket->guardar();

            if (empty($resTicket['resultado'])) {
                throw new \Exception('No se pudo crear el ticket maestro.');
            }

            $ticket->id = (int) $resTicket['id'];

            $registrationType = $payload['RegistrationType']['registration_type'] ?? '';

            foreach ($payload['passengers'] as $index => $p) {
                $seatId = $seats[$index];

                $ticketCode = TicketPassenger::generateUniqueTicketCode();

                $paymentStatus = TicketPassenger::resolvePaymentStatus(
                    $registrationType,
                    $index,
                    $p
                );

                $passenger = new TicketPassenger([
                    'ticket_id'  => $ticket->id,
                    'trip_id'    => $tripId,
                    'ticket_code' => $ticketCode,
                    'payment_status' => $paymentStatus,
                    'paid_at' => null,
                    'paid_by' => null,
                    'seat_id'    => $seatId,
                    'first_name' => trim($p['first_name'] ?? ''),
                    'last_name'  => trim($p['last_name'] ?? ''),
                    'email'      => trim($p['email'] ?? ''),
                    'phone'      => trim($p['phone'] ?? ''),
                    'born_date'  => $p['born_date'] ?? null,
                    'allergies'  => $p['allergies'] ?? [],
                    'health_conditions' => $p['health_conditions'] ?? []
                ]);

                $resP = $passenger->guardar();

                if (empty($resP['resultado'])) {
                    throw new \Exception('No se pudo registrar uno de los pasajeros.');
                }

                $passengerId = (int) $resP['id'];

                self::saveReservationRegistration(
                    $ticket->id,
                    $passengerId,
                    $payload['RegistrationType'] ?? null
                );
            }

            $order = new Order([
                'ticket_id' => $ticket->id
            ]);

            $resOrder = $order->guardar();

            if (empty($resOrder['resultado'])) {
                throw new \Exception('Error al generar la orden de compra.');
            }

            $payment = self::calculateReservationCost($payload);

            $response = [
                'success' => true,
                'message' => 'Reservación completada exitosamente.',
                'ticket_id' => $ticket->id,
                'payment' => $payment
            ];

            self::completeIdempotencyKey(
                $db,
                $idempotencyKey,
                $ticket->id,
                $response
            );

            $db->commit();

            self::enviarBoletosConfirmados($ticket, $payload);

            JsonResponse::send($response, 201);
            return;
        } catch (\mysqli_sql_exception $e) {
            self::safeRollback($db);

            error_log('MySQL error en reserva: ' . $e->getMessage());

            if ((int) $e->getCode() === 1062) {
                JsonResponse::send([
                    'success' => false,
                    'message' => 'Uno o más asientos ya fueron reservados. Por favor selecciona otros.'
                ], 409);
                return;
            }

            JsonResponse::send([
                'success' => false,
                'message' => 'Ocurrió un error al procesar la reservación.'
            ], 500);
            return;
        } catch (\InvalidArgumentException $e) {
            self::safeRollback($db);

            JsonResponse::send([
                'success' => false,
                'message' => $e->getMessage()
            ], 422);
            return;
        } catch (\Throwable $e) {
            self::safeRollback($db);

            error_log('Error en reserva: ' . $e->getMessage());

            JsonResponse::send([
                'success' => false,
                'message' => 'Ocurrió un error al procesar la reservación.'
            ], 500);
            return;
        }
    }

    public static function passengerVerifyEmail()
    {
        try {
            $emailPassenger = trim($_GET['email'] ?? '');

            if (!$emailPassenger) {
                JsonResponse::send([
                    'success' => false,
                    'message' => 'El email del pasajero es obligatorio',
                ], 422);

                return;
            }

            if (!filter_var($emailPassenger, FILTER_VALIDATE_EMAIL)) {
                JsonResponse::send([
                    'success' => false,
                    'message' => 'El email del pasajero no es válido',
                ], 422);

                return;
            }

            $passenger = TripPassenger::where('email', $emailPassenger);

            if ($passenger) {
                JsonResponse::send([
                    'success' => true,
                    'exists' => true,
                    'message' => 'Este pasajero ya ha sido registrado anteriormente.',
                    'passenger' => [
                        'id' => $passenger->id ?? null,
                        'first_name' => $passenger->first_name ?? '',
                        'last_name' => $passenger->last_name ?? '',
                        'email' => $passenger->email ?? '',
                        'phone' => $passenger->phone ?? '',
                    ],
                ], 200);

                return;
            }

            JsonResponse::send([
                'success' => true,
                'exists' => false,
                'message' => 'Este pasajero aún no ha sido registrado.',
                'passenger' => null,
            ], 200);

            return;
        } catch (\Throwable $e) {
            error_log('Error validando email del pasajero: ' . $e->getMessage());

            JsonResponse::send([
                'success' => false,
                'message' => 'Ocurrió un error al validar el email del pasajero.',
            ], 500);

            return;
        }
    }

    private static function readBody(): array
    {
        $payload = $_POST;

        if (empty($payload)) {
            $raw = file_get_contents('php://input');
            $decoded = json_decode($raw, true);

            if (!is_array($decoded)) {
                throw new \InvalidArgumentException('El cuerpo de la solicitud no es un JSON válido.');
            }

            $payload = $decoded;
        }

        return $payload;
    }

    private static function validatePayload(array $payload): void
    {
        if (empty($payload['trip_data']) || !is_array($payload['trip_data'])) {
            throw new \InvalidArgumentException('Faltan los datos del viaje.');
        }

        if (empty($payload['trip_data']['trip_id'])) {
            throw new \InvalidArgumentException('Falta el ID del viaje.');
        }

        if (empty($payload['trip_data']['seats']) || !is_array($payload['trip_data']['seats'])) {
            throw new \InvalidArgumentException('Debes seleccionar al menos un asiento.');
        }

        if (empty($payload['RegistrationType']) || !is_array($payload['RegistrationType'])) {
            throw new \InvalidArgumentException('Faltan los datos del tipo de registro.');
        }

        if (empty($payload['RegistrationType']['registration_type'])) {
            throw new \InvalidArgumentException('El tipo de registro es obligatorio.');
        }

        $allowedRegistrationTypes = [
            'internal',
            'alliance',
            'investor',
            'prospect',
            'prize',
            'family_friend'
        ];

        $registrationType = $payload['RegistrationType']['registration_type'];

        if (!in_array($registrationType, $allowedRegistrationTypes, true)) {
            throw new \InvalidArgumentException('El tipo de registro no es válido.');
        }

        if (empty($payload['passengers']) || !is_array($payload['passengers'])) {
            throw new \InvalidArgumentException('Debes agregar al menos un pasajero.');
        }

        foreach ($payload['passengers'] as $p) {
            if (empty(trim($p['first_name'] ?? ''))) {
                throw new \InvalidArgumentException('El nombre del pasajero es obligatorio.');
            }

            if (empty(trim($p['last_name'] ?? ''))) {
                throw new \InvalidArgumentException('El apellido del pasajero es obligatorio.');
            }

            if (empty($p['email']) || !filter_var($p['email'], FILTER_VALIDATE_EMAIL)) {
                throw new \InvalidArgumentException('El correo del pasajero no es válido.');
            }

            if (empty(trim($p['phone'] ?? ''))) {
                throw new \InvalidArgumentException('El teléfono del pasajero es obligatorio.');
            }
        }

        $passengersCount = count($payload['passengers']);

        if (in_array($registrationType, ['investor', 'prize'], true) && $passengersCount > 2) {
            throw new \InvalidArgumentException('Este tipo de registro permite máximo 2 pasajeros.');
        }

        if (!in_array($registrationType, ['investor', 'prize'], true) && $passengersCount > 1) {
            throw new \InvalidArgumentException('Este tipo de registro permite máximo 1 pasajero.');
        }
    }

    private static function normalizeSeats(array $seats): array
    {
        $cleanSeats = [];

        foreach ($seats as $seat) {
            if (is_array($seat)) {
                $value = $seat['seat_id']
                    ?? $seat['seatId']
                    ?? $seat['id']
                    ?? $seat['number']
                    ?? null;
            } else {
                $value = $seat;
            }

            if (!is_string($value) && !is_numeric($value)) {
                continue;
            }

            $value = strtoupper(trim((string) $value));

            if ($value !== '' && preg_match('/^[A-Z0-9\-]+$/', $value)) {
                $cleanSeats[] = $value;
            }
        }

        $cleanSeats = array_values(array_unique($cleanSeats));

        if (empty($cleanSeats)) {
            throw new \InvalidArgumentException('Los asientos seleccionados no son válidos.');
        }

        return $cleanSeats;
    }

    private static function getIdempotencyKey(array $payload): string
    {
        $key = $_SERVER['HTTP_IDEMPOTENCY_KEY'] ?? ($payload['idempotency_key'] ?? '');

        $key = trim($key);

        if ($key === '') {
            throw new \InvalidArgumentException('Falta la clave de idempotencia.');
        }

        if (strlen($key) > 100) {
            throw new \InvalidArgumentException('La clave de idempotencia es demasiado larga.');
        }

        return $key;
    }

    private static function normalizePayloadForHash(array $payload): array
    {
        unset($payload['idempotency_key']);

        if (isset($payload['trip_data']['seats']) && is_array($payload['trip_data']['seats'])) {
            $payload['trip_data']['seats'] = self::normalizeSeats($payload['trip_data']['seats']);
            sort($payload['trip_data']['seats']);
        }

        return $payload;
    }

    private static function lockIdempotencyKey(
        \mysqli $db,
        string $key,
        string $payloadHash
    ): array {
        $stmt = $db->prepare("
            INSERT INTO reservation_idempotency 
                (idempotency_key, payload_hash, status, created_at, updated_at)
            VALUES 
                (?, ?, 'processing', NOW(), NOW())
            ON DUPLICATE KEY UPDATE 
                idempotency_key = idempotency_key
        ");

        $stmt->bind_param('ss', $key, $payloadHash);
        $stmt->execute();

        $stmt = $db->prepare("
            SELECT idempotency_key, payload_hash, status, ticket_id, response_json
            FROM reservation_idempotency
            WHERE idempotency_key = ?
            FOR UPDATE
        ");

        $stmt->bind_param('s', $key);
        $stmt->execute();

        $result = $stmt->get_result();
        $row = $result->fetch_assoc();

        if (!$row) {
            throw new \Exception('No se pudo bloquear la clave de idempotencia.');
        }

        return $row;
    }

    private static function completeIdempotencyKey(
        \mysqli $db,
        string $key,
        int $ticketId,
        array $response
    ): void {
        $responseJson = json_encode($response, JSON_UNESCAPED_UNICODE);

        $stmt = $db->prepare("
            UPDATE reservation_idempotency
            SET status = 'completed',
                ticket_id = ?,
                response_json = ?,
                updated_at = NOW()
            WHERE idempotency_key = ?
        ");

        $stmt->bind_param('iss', $ticketId, $responseJson, $key);
        $stmt->execute();
    }

    private static function ensureSeatsAreAvailable(
        \mysqli $db,
        int $tripId,
        array $seats,
        int $numPassengers
    ): void {
        $stmt = $db->prepare("
            SELECT total_seats
            FROM trips
            WHERE id = ?
            FOR UPDATE
        ");

        $stmt->bind_param('i', $tripId);
        $stmt->execute();

        $result = $stmt->get_result();
        $trip = $result->fetch_assoc();

        if (!$trip) {
            throw new \InvalidArgumentException('El viaje seleccionado no existe.');
        }

        $totalSeats = (int) ($trip['total_seats'] ?? 15);

        if ($totalSeats <= 0) {
            $totalSeats = 15;
        }

        $stmt = $db->prepare("
            SELECT COUNT(*) AS reserved_count
            FROM ticket_passengers
            WHERE trip_id = ?
        ");

        $stmt->bind_param('i', $tripId);
        $stmt->execute();

        $result = $stmt->get_result();
        $reserved = $result->fetch_assoc();

        $reservedCount = (int) ($reserved['reserved_count'] ?? 0);
        $availableSeats = $totalSeats - $reservedCount;

        if ($availableSeats < $numPassengers) {
            throw new \InvalidArgumentException('Lo sentimos, ya no hay asientos disponibles para este viaje.');
        }

        if (!empty($seats)) {
            $placeholders = implode(',', array_fill(0, count($seats), '?'));
            $types = 'i' . str_repeat('s', count($seats));
            $params = array_merge([$tripId], $seats);

            $query = "
                SELECT seat_id
                FROM ticket_passengers
                WHERE trip_id = ?
                AND seat_id IN ($placeholders)
                LIMIT 1
            ";

            $stmt = $db->prepare($query);
            $stmt->bind_param($types, ...$params);
            $stmt->execute();

            $result = $stmt->get_result();

            if ($result->num_rows > 0) {
                throw new \InvalidArgumentException('Uno o más asientos ya fueron reservados. Por favor selecciona otros.');
            }
        }
    }

    private static function calculateReservationCost(array $payload): array
    {
        $registrationType = $payload['RegistrationType']['registration_type'] ?? '';
        $passengers = $payload['passengers'] ?? [];
        $ticketsCount = count($passengers);

        $paidTicketsCount = 0;
        $pricePerTicket = 0;
        $paymentReason = '';

        if ($registrationType === 'prospect') {
            $paidTicketsCount = $ticketsCount;
            $pricePerTicket = 1500;
            $paymentReason = 'Cliente prospecto';
        }

        if ($registrationType === 'family_friend') {
            $paidTicketsCount = $ticketsCount;
            $pricePerTicket = 500;
            $paymentReason = 'Familiares y amigos';
        }

        if ($registrationType === 'investor') {
            $paidTicketsCount = max(0, $ticketsCount - 1);
            $pricePerTicket = 500;
            $paymentReason = 'Invitado extra de inversionista';
        }

        if ($registrationType === 'internal') {
            foreach ($passengers as $passenger) {
                $email = trim($passenger['email'] ?? '');

                if (!$email || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    continue;
                }

                $existingPassenger = TripPassenger::where('email', $email);

                if ($existingPassenger) {
                    $paidTicketsCount = $ticketsCount;
                    $pricePerTicket = 500;
                    $paymentReason = 'Segunda visita de personal interno';
                    break;
                }
            }
        }

        $totalAmount = $paidTicketsCount * $pricePerTicket;

        return [
            'requires_payment' => $totalAmount > 0,
            'tickets_count' => $ticketsCount,
            'paid_tickets_count' => $paidTicketsCount,
            'free_tickets_count' => max(0, $ticketsCount - $paidTicketsCount),
            'price_per_ticket' => $pricePerTicket,
            'total_amount' => $totalAmount,
            'payment_reason' => $paymentReason,
        ];
    }

    private static function saveReservationRegistration(
        int $ticketId,
        int $ticketPassengerId,
        ?array $registrationPayload
    ): void {
        if (!$registrationPayload || !is_array($registrationPayload)) {
            throw new \InvalidArgumentException('Faltan los datos del tipo de registro.');
        }

        $registration = new ReservationRegistration([
            'ticket_id' => $ticketId,
            'ticket_passenger_id' => $ticketPassengerId,

            'registration_type' => $registrationPayload['registration_type'] ?? '',

            'area_code' => $registrationPayload['area_code'] ?? null,

            'alliance_name' => $registrationPayload['alliance_name'] ?? null,
            'alliance_code' => $registrationPayload['alliance_code'] ?? null,

            'prize_code' => $registrationPayload['prize_code'] ?? null,

            'development' => $registrationPayload['development'] ?? null,
            'stage' => $registrationPayload['stage'] ?? null,
            'lot_number' => $registrationPayload['lot_number'] ?? null,

            'advisor_name' => $registrationPayload['advisor_name'] ?? null,
            'advisor_code' => $registrationPayload['advisor_code'] ?? null,
            'origin' => $registrationPayload['origin'] ?? null,
            'origin_alliance_name' => $registrationPayload['origin_alliance_name'] ?? null,
        ]);

        $errors = $registration->validar();

        if (!empty($errors)) {
            throw new \InvalidArgumentException(implode(' ', $errors));
        }

        $result = $registration->guardar();

        if (empty($result['resultado'])) {
            throw new \Exception('No se pudo guardar el tipo de registro.');
        }
    }

    private static function safeRollback(\mysqli $db): void
    {
        try {
            $db->rollback();
        } catch (\Throwable $e) {
            error_log('Rollback falló: ' . $e->getMessage());
        }
    }

    private static function enviarBoletosConfirmados($ticket, array $payload): void
    {
        try {
            $emailTo = $payload['trip_data']['tickets_email']
                ?? $payload['passengers'][0]['email']
                ?? null;

            if (!$emailTo || !filter_var($emailTo, FILTER_VALIDATE_EMAIL)) {
                error_log('No se envió email: correo inválido.');
                return;
            }

            $seatNumbers = json_decode($ticket->seat_numbers, true);

            if (!is_array($seatNumbers)) {
                $seatNumbers = [];
            }

            $firstPassenger = $payload['passengers'][0];

            $ticketsName = trim(
                ($payload['trip_data']['tickets_name'] ?? '') ?: (($firstPassenger['first_name'] ?? '') . ' ' . ($firstPassenger['last_name'] ?? ''))
            );

            $tripDate = $payload['trip_data']['date'] ?? '';

            $payloadPdf = [
                'trip_data' => [
                    'trip_id' => $payload['trip_data']['trip_id'],
                    'date' => $tripDate,
                    'seats' => $seatNumbers,
                    'tickets_name' => $ticketsName
                ],
                'passengers' => $payload['passengers']
            ];

            $ticketsPdfPath = TicketPdfService::buildTicketsPdf($payloadPdf);

            $email = new Email(
                $emailTo,
                $ticketsName,
                'Tus Boletos RH',
                $tripDate,
                implode(', ', $seatNumbers)
            );

            if ($ticketsPdfPath && file_exists($ticketsPdfPath)) {
                $email->addAttachment($ticketsPdfPath, 'boletos-rh.pdf');
            }

            $payment = self::calculateReservationCost($payload);

            if ($payment['requires_payment']) {
                $paymentPdfPath = TicketPdfService::buildPaymentSlipPdf([
                    'client_name' => $ticketsName,
                    'client_email' => $emailTo,
                    'trip_date' => $tripDate,
                    'seats' => $seatNumbers,
                    'tickets_count' => $payment['tickets_count'],
                    'paid_tickets_count' => $payment['paid_tickets_count'],
                    'free_tickets_count' => $payment['free_tickets_count'],
                    'price_per_ticket' => $payment['price_per_ticket'],
                    'total_amount' => $payment['total_amount'],
                    'payment_reason' => $payment['payment_reason'],
                    'passengers' => $payload['passengers'],
                    'registration' => $payload['RegistrationType'] ?? [],
                ]);

                if ($paymentPdfPath && file_exists($paymentPdfPath)) {
                    $email->addAttachment($paymentPdfPath, 'ficha-de-pago-rh-experience.pdf');
                }
            }

            $email->send();
        } catch (\Throwable $e) {
            error_log('Error enviando email post-reserva: ' . $e->getMessage());
        }
    }
}
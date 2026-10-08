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
use Model\Trip;
use Model\TripPassenger;

class ReservationController
{
    private const NO_SHOW_PENALTY_AMOUNT = 500;

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
            $registrationType = $payload['RegistrationType']['registration_type'] ?? '';

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

            foreach ($payload['passengers'] as $index => $p) {
                $seatId = $seats[$index];

                $ticketCode = TicketPassenger::generateUniqueTicketCode();

                $paymentStatus = TicketPassenger::resolvePaymentStatus(
                    $registrationType,
                    $index,
                    $p
                );
                $penaltyAmount = TicketPassenger::passengerRequiresNoShowPenalty(
                    trim($p['email'] ?? '')
                ) ? self::NO_SHOW_PENALTY_AMOUNT : 0;
                $phoneCountryCode = preg_replace('/\D/', '', trim($p['phone_country_code'] ?? '52'));
                $phone = preg_replace('/\D/', '', trim($p['phone'] ?? ''));
                $passenger = new TicketPassenger([
                    'ticket_id' => $ticket->id,
                    'trip_id' => $tripId,
                    'ticket_code' => $ticketCode,
                    'payment_status' => $paymentStatus,
                    'penalty_amount' => $penaltyAmount,
                    'seat_id' => $seatId,
                    'first_name' => trim($p['first_name'] ?? ''),
                    'last_name' => trim($p['last_name'] ?? ''),
                    'email' => trim($p['email'] ?? ''),
                    'city' => trim($p['city'] ?? ''),
                    'phone_country_code' => $phoneCountryCode,
                    'phone' => $phone,
                    'born_date' => $p['born_date'] ?? null,
                    'allergies' => $p['allergies'] ?? [],
                    'health_conditions' => $p['health_conditions'] ?? []
                ]);

                $resP = $passenger->guardar();

                if (empty($resP['resultado'])) {
                    throw new \Exception('No se pudo registrar uno de los pasajeros.');
                }

                $passengerId = (int) $resP['id'];

                $payload['passengers'][$index]['ticket_passenger_id'] = $passengerId;
                $payload['passengers'][$index]['ticket_id'] = $ticket->id;
                $payload['passengers'][$index]['trip_id'] = $tripId;
                $payload['passengers'][$index]['seat_id'] = $seatId;
                $payload['passengers'][$index]['ticket_code'] = $ticketCode;
                $payload['passengers'][$index]['payment_status'] = $paymentStatus;
                $payload['passengers'][$index]['penalty_amount'] = $penaltyAmount;
                $payload['passengers'][$index]['paid_at'] = null;
                $payload['passengers'][$index]['paid_by'] = null;
                $payload['passengers'][$index]['registration_type'] = $registrationType;

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

    public static function updatePassengerInformation()
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            JsonResponse::send([
                'success' => false,
                'message' => 'Método no permitido'
            ], 405);
            return;
        }

        try {
            $payload = self::readBody();

            $cleanText = function ($value) {
                $value = trim((string) $value);
                $value = strip_tags($value);
                $value = preg_replace('/\s+/', ' ', $value);

                return $value;
            };

            $isSafeText = function ($value) {
                return (bool) preg_match('/^[\p{L}\p{N}\sáéíóúÁÉÍÓÚñÑüÜ.,\-]+$/u', $value);
            };

            $isValidDate = function ($date) {
                $dateTime = \DateTime::createFromFormat('Y-m-d', $date);

                return $dateTime && $dateTime->format('Y-m-d') === $date;
            };

            $isAdult = function ($bornDate) {
                $birthDate = new \DateTime($bornDate);
                $today = new \DateTime();

                return $today->diff($birthDate)->y >= 18;
            };

            $cleanTags = function ($items, $fieldName) use ($cleanText, $isSafeText) {
                $cleanItems = [];

                foreach ($items as $item) {
                    $item = $cleanText($item);

                    if ($item === '') {
                        continue;
                    }

                    if (mb_strlen($item) > 80) {
                        throw new \InvalidArgumentException(
                            "El campo {$fieldName} contiene un valor demasiado largo."
                        );
                    }

                    if (!$isSafeText($item)) {
                        throw new \InvalidArgumentException(
                            "El campo {$fieldName} contiene caracteres no permitidos."
                        );
                    }

                    $cleanItems[] = $item;
                }

                return array_values(array_unique($cleanItems));
            };

            $errors = [];

            $id = (int) ($payload['id'] ?? 0);
            $firstName = $cleanText($payload['first_name'] ?? '');
            $lastName = $cleanText($payload['last_name'] ?? '');
            $email = trim((string) ($payload['email'] ?? ''));
            $phoneCountryCode = trim((string) ($payload['phone_country_code'] ?? ''));
            $phone = trim((string) ($payload['phone'] ?? ''));
            $city = $cleanText($payload['city'] ?? '');
            $bornDate = trim((string) ($payload['born_date'] ?? ''));

            $allergies = $payload['allergies'] ?? [];
            $healthConditions = $payload['health_conditions'] ?? [];

            if ($id <= 0) {
                $errors['id'] = 'ID de pasajero no válido.';
            }

            if ($firstName === '') {
                $errors['first_name'] = 'El nombre es obligatorio.';
            } elseif (mb_strlen($firstName) < 3 || mb_strlen($firstName) > 100) {
                $errors['first_name'] = 'El nombre debe tener entre 3 y 100 caracteres.';
            } elseif (!$isSafeText($firstName)) {
                $errors['first_name'] = 'El nombre contiene caracteres no permitidos.';
            }

            if ($lastName === '') {
                $errors['last_name'] = 'El apellido es obligatorio.';
            } elseif (mb_strlen($lastName) < 3 || mb_strlen($lastName) > 100) {
                $errors['last_name'] = 'El apellido debe tener entre 3 y 100 caracteres.';
            } elseif (!$isSafeText($lastName)) {
                $errors['last_name'] = 'El apellido contiene caracteres no permitidos.';
            }

            if ($email === '') {
                $errors['email'] = 'El correo electrónico es obligatorio.';
            } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $errors['email'] = 'El correo electrónico no es válido.';
            } elseif (mb_strlen($email) > 190) {
                $errors['email'] = 'El correo electrónico es demasiado largo.';
            }

            if (!in_array($phoneCountryCode, ['52', '1'], true)) {
                $errors['phone_country_code'] = 'La lada debe ser 52 o 1.';
            }

            if ($phone === '') {
                $errors['phone'] = 'El teléfono es obligatorio.';
            } elseif (!preg_match('/^[0-9]{10}$/', $phone)) {
                $errors['phone'] = 'El teléfono debe tener exactamente 10 dígitos, sin espacios, guiones ni caracteres especiales.';
            }

            if ($city === '') {
                $errors['city'] = 'La ciudad es obligatoria.';
            } elseif (mb_strlen($city) < 3 || mb_strlen($city) > 100) {
                $errors['city'] = 'La ciudad debe tener entre 3 y 100 caracteres.';
            } elseif (!$isSafeText($city)) {
                $errors['city'] = 'La ciudad contiene caracteres no permitidos.';
            }

            if ($bornDate === '') {
                $errors['born_date'] = 'La fecha de nacimiento es obligatoria.';
            } elseif (!$isValidDate($bornDate)) {
                $errors['born_date'] = 'La fecha de nacimiento no es válida.';
            } elseif (!$isAdult($bornDate)) {
                $errors['born_date'] = 'El pasajero debe ser mayor de edad.';
            }

            if (!is_array($allergies)) {
                $errors['allergies'] = 'Las alergias deben enviarse como arreglo.';
            }

            if (!is_array($healthConditions)) {
                $errors['health_conditions'] = 'Las condiciones de salud deben enviarse como arreglo.';
            }

            if (!empty($errors)) {
                JsonResponse::send([
                    'success' => false,
                    'message' => 'Datos inválidos.',
                    'errors' => $errors
                ], 422);
                return;
            }

            $cleanAllergies = $cleanTags($allergies, 'alergias');
            $cleanHealthConditions = $cleanTags(
                $healthConditions,
                'condiciones de salud'
            );

            $allergiesJson = json_encode($cleanAllergies, JSON_UNESCAPED_UNICODE);
            $healthConditionsJson = json_encode(
                $cleanHealthConditions,
                JSON_UNESCAPED_UNICODE
            );

            $passenger = TripPassenger::find($id);

            if (!$passenger) {
                JsonResponse::send([
                    'success' => false,
                    'message' => 'Pasajero no encontrado.'
                ], 404);
                return;
            }

            $db = TripPassenger::getDB();

            $query = "
            UPDATE ticket_passengers
            SET
                first_name = ?,
                last_name = ?,
                email = ?,
                phone_country_code = ?,
                phone = ?,
                city = ?,
                born_date = ?,
                allergies = ?,
                health_conditions = ?
            WHERE id = ?
            LIMIT 1
        ";

            $stmt = $db->prepare($query);

            if (!$stmt) {
                JsonResponse::send([
                    'success' => false,
                    'message' => 'No se pudo preparar la actualización del pasajero.'
                ], 500);
                return;
            }

            $stmt->bind_param(
                'sssssssssi',
                $firstName,
                $lastName,
                $email,
                $phoneCountryCode,
                $phone,
                $city,
                $bornDate,
                $allergiesJson,
                $healthConditionsJson,
                $id
            );

            if (!$stmt->execute()) {
                JsonResponse::send([
                    'success' => false,
                    'message' => 'No se pudo actualizar la información del pasajero.'
                ], 500);
                return;
            }

            JsonResponse::send([
                'success' => true,
                'message' => 'Información del pasajero actualizada correctamente.',
                'data' => [
                    'id' => $id,
                    'first_name' => $firstName,
                    'last_name' => $lastName,
                    'email' => $email,
                    'phone_country_code' => $phoneCountryCode,
                    'phone' => $phone,
                    'city' => $city,
                    'born_date' => $bornDate,
                    'allergies' => $cleanAllergies,
                    'health_conditions' => $cleanHealthConditions,
                ]
            ], 200);
            return;
        } catch (\InvalidArgumentException $e) {
            JsonResponse::send([
                'success' => false,
                'message' => $e->getMessage()
            ], 422);
            return;
        } catch (\Throwable $e) {
            error_log('Error al actualizar información del pasajero: ' . $e->getMessage());

            JsonResponse::send([
                'success' => false,
                'message' => 'Ocurrió un error al actualizar la información del pasajero.'
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

            $noShowCount = TicketPassenger::passengerNoShowCount($emailPassenger);
            $recentInternalNoShow = TicketPassenger::passengerRecentInternalNoShow($emailPassenger);
            $requiresPenalty = $noShowCount >= 2 || $recentInternalNoShow !== null;
            $passenger = TripPassenger::where('email', $emailPassenger);

            if ($passenger) {
                JsonResponse::send([
                    'success' => true,
                    'exists' => true,
                    'no_show_count' => $noShowCount,
                    'requires_no_show_penalty' => $requiresPenalty,
                    'requires_internal_no_show_penalty' => $recentInternalNoShow !== null,
                    'internal_no_show_until' => $recentInternalNoShow['restriction_ends_at'] ?? null,
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
                'no_show_count' => $noShowCount,
                'requires_no_show_penalty' => $requiresPenalty,
                'requires_internal_no_show_penalty' => $recentInternalNoShow !== null,
                'internal_no_show_until' => $recentInternalNoShow['restriction_ends_at'] ?? null,
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

        if ($registrationType === 'family_friend') {
            return;
        }

        if (!in_array($registrationType, ['investor', 'prize', 'family_friend'], true) && $passengersCount > 1) {
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

        $pricePerTicket = 0;
        $paymentReason = self::getPaymentReasonByRegistrationType($registrationType);

        $paidTicketsCount = 0;
        $freeTicketsCount = 0;
        $baseTotalAmount = 0;
        $penaltyTotalAmount = 0;
        $totalAmount = 0;

        $passengerPaymentDetails = [];

        foreach ($passengers as $index => $passenger) {
            $paymentStatus = $passenger['payment_status'] ?? 'free';
            $penaltyAmount = (int) ($passenger['penalty_amount'] ?? 0);

            $amount = 0;
            $baseAmount = 0;

            if ($paymentStatus === 'pending' || $paymentStatus === 'paid') {
                $amount = self::getPaymentAmount(
                    $registrationType,
                    $index,
                    $paymentStatus,
                    $penaltyAmount
                );
                $baseAmount = max(0, $amount - $penaltyAmount);
                $paidTicketsCount++;
            } else {
                $freeTicketsCount++;
            }

            $baseTotalAmount += $baseAmount;
            $penaltyTotalAmount += $penaltyAmount;
            $totalAmount += $amount;
            $pricePerTicket = max($pricePerTicket, $amount);

            $passengerPaymentDetails[] = [
                'index' => $index + 1,
                'first_name' => $passenger['first_name'] ?? '',
                'last_name' => $passenger['last_name'] ?? '',
                'email' => $passenger['email'] ?? '',
                'seat_id' => $passenger['seat_id'] ?? '',
                'ticket_code' => $passenger['ticket_code'] ?? '',
                'registration_type' => $passenger['registration_type'] ?? $registrationType,
                'payment_status' => $paymentStatus,
                'base_amount' => $baseAmount,
                'penalty_amount' => $penaltyAmount,
                'amount' => $amount,
            ];
        }

        if (array_sum(array_column($passengerPaymentDetails, 'penalty_amount')) > 0) {
            $paymentReason = trim(
                $paymentReason
                    . ' + penalización de $500 por No show interno en los últimos seis meses o dos inasistencias previas',
                ' +'
            );
        }

        return [
            'requires_payment' => $totalAmount > 0,
            'tickets_count' => $ticketsCount,
            'paid_tickets_count' => $paidTicketsCount,
            'free_tickets_count' => $freeTicketsCount,
            'price_per_ticket' => $pricePerTicket,
            'base_total_amount' => $baseTotalAmount,
            'penalty_total_amount' => $penaltyTotalAmount,
            'total_amount' => $totalAmount,
            'payment_reason' => $paymentReason,
            'passengers' => $passengerPaymentDetails,
        ];
    }

    private static function getPricePerTicketByRegistrationType(string $registrationType): int
    {
        return match ($registrationType) {
            'prospect' => 1500,
            'family_friend' => 500,
            'investor' => 500,
            'internal' => 500,
            default => 0,
        };
    }

    private static function getPaymentAmount(
        string $registrationType,
        int $passengerIndex,
        string $paymentStatus,
        int $penaltyAmount = 0
    ): int {
        if ($paymentStatus !== 'pending' && $paymentStatus !== 'paid') {
            return 0;
        }

        $baseAmount = match ($registrationType) {
            'prospect' => 1500,
            'family_friend' => 500,
            'investor' => $passengerIndex === 0 ? 0 : 500,
            'internal' => 500,
            default => 0,
        };

        return $baseAmount + max(0, $penaltyAmount);
    }

    private static function getPaymentReasonByRegistrationType(string $registrationType): string
    {
        return match ($registrationType) {
            'prospect' => 'Cliente prospecto',
            'family_friend' => 'Invitado extra, familiares o amigos',
            'investor' => 'Invitado extra de inversionista',
            'internal' => 'Segunda visita de personal interno',
            default => '',
        };
    }

    private static function getNoShowPenaltyNotice(array $passengers): string
    {
        $penalizedPassengers = [];
        $hasRecentInternalNoShow = false;

        foreach ($passengers as $passenger) {
            $email = trim($passenger['email'] ?? '');

            if (!TicketPassenger::passengerRequiresNoShowPenalty($email)) {
                continue;
            }

            if (TicketPassenger::passengerRecentInternalNoShow($email)) {
                $hasRecentInternalNoShow = true;
            }

            $name = trim(
                ($passenger['first_name'] ?? '') . ' ' . ($passenger['last_name'] ?? '')
            );

            $penalizedPassengers[] = $name ?: $email;
        }

        if (!$penalizedPassengers) {
            return '';
        }

        $reason = $hasRecentInternalNoShow
            ? 'No show interno dentro de los últimos seis meses o dos inasistencias previas'
            : 'dos inasistencias previas';

        return 'Se aplica una penalización de $500 MXN por boleto debido a '
            . $reason . ' registradas para: '
            . implode(', ', $penalizedPassengers)
            . '. Este importe es adicional al costo normal del boleto. Por este motivo se adjunta una ficha de pago, incluso si la modalidad normalmente es gratuita.';
    }

    private static function getPassengerIndexInTicket(int $ticketId, int $passengerId): int
    {
        $db = TicketPassenger::getDB();
        $stmt = $db->prepare("
            SELECT COUNT(*) AS previous_passengers
            FROM ticket_passengers
            WHERE ticket_id = ? AND id < ?
        ");

        if (!$stmt) {
            throw new \Exception('No se pudo determinar la posición del pasajero.');
        }

        $stmt->bind_param('ii', $ticketId, $passengerId);
        $stmt->execute();

        $result = $stmt->get_result()->fetch_assoc();

        return (int) ($result['previous_passengers'] ?? 0);
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

    public static function reschedulePassenger()
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            JsonResponse::send([
                'success' => false,
                'message' => 'Método no permitido'
            ], 405);
            return;
        }

        try {
            $payload = self::readBody();

            $passengerId = (int) ($payload['passenger_id'] ?? 0);
            $currentTripId = (int) ($payload['current_trip_id'] ?? 0);
            $newTripId = (int) ($payload['new_trip_id'] ?? 0);
            $newSeatId = trim((string) ($payload['new_seat_id'] ?? ''));

            if (!$passengerId || !$currentTripId || !$newTripId || !$newSeatId) {
                JsonResponse::send([
                    'success' => false,
                    'message' => 'Faltan datos requeridos para reagendar el pasajero.'
                ], 400);
                return;
            }

            if ($currentTripId === $newTripId) {
                JsonResponse::send([
                    'success' => false,
                    'message' => 'El nuevo tour debe ser diferente al tour actual.'
                ], 422);
                return;
            }

            $db = TicketPassenger::getDB();

            // Obtener pasajero actual
            $passenger = TicketPassenger::find($passengerId);

            if (!$passenger) {
                JsonResponse::send([
                    'success' => false,
                    'message' => 'Pasajero no encontrado.'
                ], 404);
                return;
            }

            $oldSeatId = $passenger->seat_id;

            // Validar que el nuevo tour existe y tiene disponibilidad
            $query = "
                SELECT
                    t.id,
                    COALESCE(t.total_seats, 15) AS total_seats,
                    COUNT(tp.id) AS unavaible_seats,
                    GREATEST(COALESCE(t.total_seats, 15) - COUNT(tp.id), 0) AS avaible_seats
                FROM trips t
                LEFT JOIN ticket_passengers tp ON tp.trip_id = t.id
                WHERE t.id = ? AND t.is_active = 1
                GROUP BY t.id
            ";

            $stmt = $db->prepare($query);

            if (!$stmt) {
                JsonResponse::send([
                    'success' => false,
                    'message' => 'Error al validar el nuevo tour.'
                ], 500);
                return;
            }

            $stmt->bind_param('i', $newTripId);
            $stmt->execute();
            $result = $stmt->get_result();
            $trip = $result->fetch_assoc();

            if (!$trip) {
                JsonResponse::send([
                    'success' => false,
                    'message' => 'El nuevo tour no existe o no está disponible.'
                ], 404);
                return;
            }

            if ($trip['avaible_seats'] <= 0) {
                JsonResponse::send([
                    'success' => false,
                    'message' => 'El nuevo tour no tiene asientos disponibles.'
                ], 422);
                return;
            }

            // Validar que el asiento no esté ocupado
            $checkSeatQuery = "
                SELECT id FROM ticket_passengers
                WHERE trip_id = ? AND seat_id = ?
                LIMIT 1
            ";

            $seatStmt = $db->prepare($checkSeatQuery);

            if (!$seatStmt) {
                JsonResponse::send([
                    'success' => false,
                    'message' => 'Error al validar el asiento.'
                ], 500);
                return;
            }

            $seatStmt->bind_param('is', $newTripId, $newSeatId);
            $seatStmt->execute();
            $seatResult = $seatStmt->get_result();

            if ($seatResult->num_rows > 0) {
                JsonResponse::send([
                    'success' => false,
                    'message' => 'El asiento seleccionado ya está ocupado.'
                ], 422);
                return;
            }

            // Obtener el ticket actual del pasajero para actualizar seat_numbers
            $ticketQuery = "
                SELECT id, seat_numbers FROM tickets
                WHERE id = ?
                LIMIT 1
            ";

            $ticketStmt = $db->prepare($ticketQuery);

            if (!$ticketStmt) {
                JsonResponse::send([
                    'success' => false,
                    'message' => 'Error al obtener el ticket.'
                ], 500);
                return;
            }

            $ticketStmt->bind_param('i', $passenger->ticket_id);
            $ticketStmt->execute();
            $ticketResult = $ticketStmt->get_result();
            $ticket = $ticketResult->fetch_assoc();

            if (!$ticket) {
                JsonResponse::send([
                    'success' => false,
                    'message' => 'Ticket no encontrado.'
                ], 404);
                return;
            }

            // Actualizar seat_numbers en la tabla tickets
            $seatNumbers = json_decode($ticket['seat_numbers'] ?? '[]', true);

            if (!is_array($seatNumbers)) {
                $seatNumbers = [];
            }

            // Verificar si el pasajero es el único en el ticket o si hay más pasajeros
            $hasMultiplePassengers = count($seatNumbers) > 1;

            if ($hasMultiplePassengers) {
                // El ticket tiene múltiples pasajeros, crear un nuevo ticket para este pasajero

                // Remover el asiento anterior del array
                $seatNumbers = array_filter($seatNumbers, function ($seat) use ($oldSeatId) {
                    return $seat !== $oldSeatId;
                });
                // Reindexar array
                $seatNumbers = array_values($seatNumbers);
                $updatedSeatNumbers = json_encode($seatNumbers, JSON_UNESCAPED_UNICODE);

                // Actualizar ticket anterior con el array reducido
                $updateOldTicketQuery = "
                    UPDATE tickets
                    SET seat_numbers = ?
                    WHERE id = ?
                    LIMIT 1
                ";

                $updateOldTicketStmt = $db->prepare($updateOldTicketQuery);

                if (!$updateOldTicketStmt) {
                    JsonResponse::send([
                        'success' => false,
                        'message' => 'Error al actualizar el ticket anterior.'
                    ], 500);
                    return;
                }

                $updateOldTicketStmt->bind_param('si', $updatedSeatNumbers, $ticket['id']);

                if (!$updateOldTicketStmt->execute()) {
                    JsonResponse::send([
                        'success' => false,
                        'message' => 'No se pudo actualizar el ticket anterior.'
                    ], 500);
                    return;
                }

                // Crear nuevo ticket para este pasajero
                $newSeatNumbersJson = json_encode([$newSeatId], JSON_UNESCAPED_UNICODE);

                $createNewTicketQuery = "
                    INSERT INTO tickets (trip_id, seat_numbers, created_at)
                    VALUES (?, ?, NOW())
                ";

                $createNewTicketStmt = $db->prepare($createNewTicketQuery);

                if (!$createNewTicketStmt) {
                    JsonResponse::send([
                        'success' => false,
                        'message' => 'Error al crear nuevo ticket.'
                    ], 500);
                    return;
                }

                $createNewTicketStmt->bind_param('is', $newTripId, $newSeatNumbersJson);

                if (!$createNewTicketStmt->execute()) {
                    JsonResponse::send([
                        'success' => false,
                        'message' => 'No se pudo crear el nuevo ticket.'
                    ], 500);
                    return;
                }

                $newTicketId = $createNewTicketStmt->insert_id;

                // Actualizar pasajero con nuevo ticket, trip y asiento
                $updateQuery = "
                    UPDATE ticket_passengers
                    SET ticket_id = ?, trip_id = ?, seat_id = ?
                    WHERE id = ?
                    LIMIT 1
                ";

                $updateStmt = $db->prepare($updateQuery);

                if (!$updateStmt) {
                    JsonResponse::send([
                        'success' => false,
                        'message' => 'Error al reagendar el pasajero.'
                    ], 500);
                    return;
                }

                $updateStmt->bind_param('iisi', $newTicketId, $newTripId, $newSeatId, $passengerId);

                if (!$updateStmt->execute()) {
                    JsonResponse::send([
                        'success' => false,
                        'message' => 'No se pudo reagendar el pasajero.'
                    ], 500);
                    return;
                }
            } else {
                // El ticket tiene solo un pasajero, actualizar normalmente

                // Reemplazar el asiento anterior con el nuevo
                $seatNumbers = array_map(function ($seat) use ($oldSeatId, $newSeatId) {
                    return $seat === $oldSeatId ? $newSeatId : $seat;
                }, $seatNumbers);

                $updatedSeatNumbers = json_encode($seatNumbers, JSON_UNESCAPED_UNICODE);

                // Actualizar ticket con new seat_numbers y trip_id
                $updateTicketQuery = "
                    UPDATE tickets
                    SET seat_numbers = ?, trip_id = ?
                    WHERE id = ?
                    LIMIT 1
                ";

                $updateTicketStmt = $db->prepare($updateTicketQuery);

                if (!$updateTicketStmt) {
                    JsonResponse::send([
                        'success' => false,
                        'message' => 'Error al actualizar el ticket.'
                    ], 500);
                    return;
                }

                $updateTicketStmt->bind_param('sii', $updatedSeatNumbers, $newTripId, $ticket['id']);

                if (!$updateTicketStmt->execute()) {
                    JsonResponse::send([
                        'success' => false,
                        'message' => 'No se pudo actualizar el ticket.'
                    ], 500);
                    return;
                }

                // Actualizar pasajero con nuevo trip y asiento
                $updateQuery = "
                    UPDATE ticket_passengers
                    SET trip_id = ?, seat_id = ?
                    WHERE id = ?
                    LIMIT 1
                ";

                $updateStmt = $db->prepare($updateQuery);

                if (!$updateStmt) {
                    JsonResponse::send([
                        'success' => false,
                        'message' => 'Error al reagendar el pasajero.'
                    ], 500);
                    return;
                }

                $updateStmt->bind_param('isi', $newTripId, $newSeatId, $passengerId);

                if (!$updateStmt->execute()) {
                    JsonResponse::send([
                        'success' => false,
                        'message' => 'No se pudo reagendar el pasajero.'
                    ], 500);
                    return;
                }
            }

            // Obtener datos actualizados del pasajero
            $updatedPassenger = TicketPassenger::find($passengerId);

            try {
                self::sendPassengerTicketsEmail(
                    $passengerId,
                    'Tu Boleto Reagendado - RH Experience',
                    'rescheduled'
                );
            } catch (\Throwable $emailError) {
                error_log('Error enviando email al reagendar pasajero: ' . $emailError->getMessage());
            }

            JsonResponse::send([
                'success' => true,
                'message' => 'Pasajero reagendado correctamente.',
                'data' => [
                    'id' => (int) $updatedPassenger->id,
                    'trip_id' => (int) $updatedPassenger->trip_id,
                    'seat_id' => $updatedPassenger->seat_id,
                    'first_name' => $updatedPassenger->first_name,
                    'last_name' => $updatedPassenger->last_name,
                    'email' => $updatedPassenger->email,
                    'ticket_code' => $updatedPassenger->ticket_code,
                ]
            ], 200);
            return;
        } catch (\Throwable $e) {
            error_log('Error al reagendar pasajero: ' . $e->getMessage());

            JsonResponse::send([
                'success' => false,
                'message' => $e->getMessage()
            ], 500);
            return;
        }
    }

    public static function resendPassengerTickets()
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            JsonResponse::send([
                'success' => false,
                'message' => 'Método no permitido'
            ], 405);
            return;
        }

        try {
            $payload = self::readBody();

            $passengerId = (int) ($payload['passenger_id'] ?? 0);

            if ($passengerId <= 0) {
                JsonResponse::send([
                    'success' => false,
                    'message' => 'El pasajero es obligatorio.'
                ], 400);
                return;
            }

            $passenger = TicketPassenger::find($passengerId);

            if (!$passenger) {
                JsonResponse::send([
                    'success' => false,
                    'message' => 'Pasajero no encontrado.'
                ], 404);
                return;
            }

            if (!$passenger->email || !filter_var($passenger->email, FILTER_VALIDATE_EMAIL)) {
                JsonResponse::send([
                    'success' => false,
                    'message' => 'El pasajero no tiene un correo electrónico válido.'
                ], 422);
                return;
            }

            $sent = self::sendPassengerTicketsEmail(
                $passengerId,
                'Tus Boletos RH Experience'
            );

            if (!$sent) {
                JsonResponse::send([
                    'success' => false,
                    'message' => 'No se pudo reenviar el correo del pasajero.'
                ], 500);
                return;
            }

            JsonResponse::send([
                'success' => true,
                'message' => 'Correo reenviado correctamente a ' . $passenger->email,
                'data' => [
                    'id' => (int) $passenger->id,
                    'email' => $passenger->email,
                ]
            ], 200);
            return;
        } catch (\Throwable $e) {
            error_log('Error al reenviar boletos del pasajero: ' . $e->getMessage());

            JsonResponse::send([
                'success' => false,
                'message' => 'Ocurrió un error al reenviar el correo del pasajero.'
            ], 500);
            return;
        }
    }

    public static function deletePassenger()
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            JsonResponse::send([
                'success' => false,
                'message' => 'Método no permitido'
            ], 405);
            return;
        }

        $db = TicketPassenger::getDB();

        try {
            $payload = self::readBody();
            $passengerId = (int) ($payload['passenger_id'] ?? 0);

            if ($passengerId <= 0) {
                JsonResponse::send([
                    'success' => false,
                    'message' => 'El pasajero es obligatorio.'
                ], 400);
                return;
            }

            $db->begin_transaction();

            $passengerStmt = $db->prepare("
                SELECT id, ticket_id, seat_id
                FROM ticket_passengers
                WHERE id = ?
                LIMIT 1
                FOR UPDATE
            ");

            if (!$passengerStmt) {
                throw new \Exception('No se pudo preparar la eliminación del pasajero.');
            }

            $passengerStmt->bind_param('i', $passengerId);
            $passengerStmt->execute();
            $passenger = $passengerStmt->get_result()->fetch_assoc();

            if (!$passenger) {
                $db->rollback();
                JsonResponse::send([
                    'success' => false,
                    'message' => 'Pasajero no encontrado.'
                ], 404);
                return;
            }

            $ticketStmt = $db->prepare("
                SELECT id, seat_numbers
                FROM tickets
                WHERE id = ?
                LIMIT 1
                FOR UPDATE
            ");

            if (!$ticketStmt) {
                throw new \Exception('No se pudo preparar la actualización del ticket.');
            }

            $ticketId = (int) $passenger['ticket_id'];
            $ticketStmt->bind_param('i', $ticketId);
            $ticketStmt->execute();
            $ticket = $ticketStmt->get_result()->fetch_assoc();

            if (!$ticket) {
                throw new \Exception('Ticket no encontrado.');
            }

            $seatNumbers = json_decode($ticket['seat_numbers'] ?? '[]', true);
            $seatNumbers = is_array($seatNumbers) ? $seatNumbers : [];
            $passengerSeat = (string) $passenger['seat_id'];

            $remainingSeats = array_values(array_filter(
                $seatNumbers,
                static fn($seat) => (string) $seat !== $passengerSeat
            ));

            if (!empty($remainingSeats)) {
                $updatedSeatNumbers = json_encode(
                    $remainingSeats,
                    JSON_UNESCAPED_UNICODE
                );

                $updateTicketStmt = $db->prepare("
                    UPDATE tickets
                    SET seat_numbers = ?
                    WHERE id = ?
                    LIMIT 1
                ");

                if (!$updateTicketStmt) {
                    throw new \Exception('No se pudo liberar el asiento.');
                }

                $updateTicketStmt->bind_param('si', $updatedSeatNumbers, $ticketId);

                if (!$updateTicketStmt->execute()) {
                    throw new \Exception('No se pudo liberar el asiento.');
                }
            }

            $registrationStmt = $db->prepare("
                DELETE FROM reservation_registrations
                WHERE ticket_passenger_id = ?
            ");

            if (!$registrationStmt) {
                throw new \Exception('No se pudo eliminar la información de inscripción.');
            }

            $registrationStmt->bind_param('i', $passengerId);

            if (!$registrationStmt->execute()) {
                throw new \Exception('No se pudo eliminar la información de inscripción.');
            }

            $deletePassengerStmt = $db->prepare("
                DELETE FROM ticket_passengers
                WHERE id = ?
                LIMIT 1
            ");

            if (!$deletePassengerStmt) {
                throw new \Exception('No se pudo eliminar el pasajero.');
            }

            $deletePassengerStmt->bind_param('i', $passengerId);

            if (!$deletePassengerStmt->execute()) {
                throw new \Exception('No se pudo eliminar el pasajero.');
            }

            if (empty($remainingSeats)) {
                $deleteOrderStmt = $db->prepare("
                    DELETE FROM orders
                    WHERE ticket_id = ?
                ");

                if (!$deleteOrderStmt) {
                    throw new \Exception('No se pudo eliminar la orden del ticket.');
                }

                $deleteOrderStmt->bind_param('i', $ticketId);

                if (!$deleteOrderStmt->execute()) {
                    throw new \Exception('No se pudo eliminar la orden del ticket.');
                }

                $deleteTicketStmt = $db->prepare("
                    DELETE FROM tickets
                    WHERE id = ?
                    LIMIT 1
                ");

                if (!$deleteTicketStmt) {
                    throw new \Exception('No se pudo eliminar el ticket vacío.');
                }

                $deleteTicketStmt->bind_param('i', $ticketId);

                if (!$deleteTicketStmt->execute()) {
                    throw new \Exception('No se pudo eliminar el ticket vacío.');
                }
            }

            $db->commit();

            JsonResponse::send([
                'success' => true,
                'message' => 'Pasajero eliminado y asiento liberado correctamente.',
                'data' => [
                    'id' => $passengerId,
                    'ticket_id' => $ticketId,
                    'seat_id' => $passengerSeat,
                    'remaining_seats' => $remainingSeats,
                ]
            ], 200);
            return;
        } catch (\Throwable $e) {
            self::safeRollback($db);
            error_log('Error al eliminar pasajero: ' . $e->getMessage());

            JsonResponse::send([
                'success' => false,
                'message' => 'No se pudo eliminar el pasajero ni liberar su asiento.'
            ], 500);
            return;
        }
    }

    public static function updateAttendanceStatus()
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            JsonResponse::send([
                'success' => false,
                'message' => 'Método no permitido'
            ], 405);
            return;
        }

        try {
            $payload = self::readBody();
            $passengerId = (int) ($payload['passenger_id'] ?? 0);
            $attendanceStatus = trim((string) ($payload['attendance_status'] ?? ''));

            $allowedStatuses = [
                'no_show_interno',
                'ausencia_justificada',
                'ausencia',
                'asistio',
            ];

            if ($passengerId <= 0 || !in_array($attendanceStatus, $allowedStatuses, true)) {
                JsonResponse::send([
                    'success' => false,
                    'message' => 'El pasajero o el estado de asistencia no es válido.'
                ], 422);
                return;
            }

            $db = TicketPassenger::getDB();
            $existsStmt = $db->prepare(" 
                SELECT id
                FROM ticket_passengers
                WHERE id = ?
                LIMIT 1
            ");

            if (!$existsStmt) {
                throw new \Exception('No se pudo validar el pasajero.');
            }

            $existsStmt->bind_param('i', $passengerId);
            $existsStmt->execute();

            if (!$existsStmt->get_result()->fetch_assoc()) {
                JsonResponse::send([
                    'success' => false,
                    'message' => 'Pasajero no encontrado.'
                ], 404);
                return;
            }

            $stmt = $db->prepare("
                UPDATE ticket_passengers
                SET attendance_status = ?
                WHERE id = ?
                LIMIT 1
            ");

            if (!$stmt) {
                throw new \Exception('No se pudo preparar la actualización de asistencia.');
            }

            $stmt->bind_param('si', $attendanceStatus, $passengerId);

            if (!$stmt->execute()) {
                JsonResponse::send([
                    'success' => false,
                    'message' => 'No se pudo actualizar la asistencia del pasajero.'
                ], 404);
                return;
            }

            JsonResponse::send([
                'success' => true,
                'message' => 'Asistencia actualizada correctamente.',
                'data' => [
                    'id' => $passengerId,
                    'attendance_status' => $attendanceStatus,
                ]
            ], 200);
            return;
        } catch (\Throwable $e) {
            error_log('Error al actualizar asistencia: ' . $e->getMessage());

            JsonResponse::send([
                'success' => false,
                'message' => 'No se pudo actualizar la asistencia del pasajero.'
            ], 500);
            return;
        }
    }

    private static function sendPassengerTicketsEmail(
        int $passengerId,
        string $subject,
        string $context = 'reservation'
    ): bool {
        $passenger = TicketPassenger::find($passengerId);

        if (!$passenger || !$passenger->email) {
            return false;
        }

        $trip = Trip::find((int) $passenger->trip_id);

        if (!$trip) {
            return false;
        }

        $registration = self::findPassengerRegistration($passengerId);

        $registrationType = $registration['registration_type']
            ?? $passenger->registration_type
            ?? '';

        $seatId = (string) $passenger->seat_id;
        $passengerName = trim(($passenger->first_name ?? '') . ' ' . ($passenger->last_name ?? ''));

        $registrationPayload = [
            'registration_type' => $registrationType,
            'area_code' => $registration['area_code'] ?? null,
            'alliance_name' => $registration['alliance_name'] ?? null,
            'alliance_code' => $registration['alliance_code'] ?? null,
            'prize_code' => $registration['prize_code'] ?? null,
            'development' => $registration['development'] ?? null,
            'stage' => $registration['stage'] ?? null,
            'lot_number' => $registration['lot_number'] ?? null,
            'advisor_name' => $registration['advisor_name'] ?? null,
            'advisor_code' => $registration['advisor_code'] ?? null,
            'origin' => $registration['origin'] ?? null,
            'origin_alliance_name' => $registration['origin_alliance_name'] ?? null,
        ];

        $ticketsPdfPath = TicketPdfService::buildTicketsPdf([
            'trip_data' => [
                'trip_id' => (int) $passenger->trip_id,
                'date' => $trip->trip_date,
                'seats' => [$seatId],
                'tickets_name' => $passengerName,
            ],
            'passengers' => [
                [
                    'seat_id' => $seatId,
                    'first_name' => $passenger->first_name ?? '',
                    'last_name' => $passenger->last_name ?? '',
                    'email' => $passenger->email ?? '',
                    'phone_country_code' => $passenger->phone_country_code ?? '',
                    'phone' => $passenger->phone ?? '',
                    'city' => $passenger->city ?? '',
                    'born_date' => $passenger->born_date ?? null,
                    'ticket_code' => $passenger->ticket_code ?? '',
                    'registration_type' => $registrationType,
                ]
            ],
            'registration' => $registrationPayload,
        ]);

        $paymentNotice = '';

        if (
            ($passenger->payment_status ?? 'free') === 'pending'
            && ($passenger->penalty_amount ?? 0) > 0
        ) {
            $paymentNotice = self::getNoShowPenaltyNotice([[
                'first_name' => $passenger->first_name ?? '',
                'last_name' => $passenger->last_name ?? '',
                'email' => $passenger->email,
            ]]);
        }

        $email = new Email(
            $passenger->email,
            $passengerName,
            $subject,
            $trip->trip_date,
            $seatId,
            (string) $passenger->trip_id,
            $context,
            $paymentNotice
        );

        if ($ticketsPdfPath && file_exists($ticketsPdfPath)) {
            $email->addAttachment($ticketsPdfPath, 'boletos-rh.pdf');
        }

        if (($passenger->payment_status ?? 'free') === 'pending') {
            $passengerIndex = self::getPassengerIndexInTicket(
                (int) $passenger->ticket_id,
                (int) $passenger->id
            );
            $penaltyAmount = (int) ($passenger->penalty_amount ?? 0);
            $pricePerTicket = self::getPaymentAmount(
                $registrationType,
                $passengerIndex,
                'pending',
                $penaltyAmount
            );
            $paymentReason = self::getPaymentReasonByRegistrationType($registrationType);

            if ($penaltyAmount > 0) {
                $paymentReason = trim(
                    $paymentReason
                        . ' + penalización de $500 por No show interno en los últimos seis meses o dos inasistencias previas',
                    ' +'
                );
            }

            $paymentPdfPath = TicketPdfService::buildPaymentSlipPdf([
                'client_name' => $passengerName,
                'client_email' => $passenger->email,
                'trip_date' => $trip->trip_date,
                'seats' => [$seatId],
                'tickets_count' => 1,
                'paid_tickets_count' => 1,
                'free_tickets_count' => 0,
                'price_per_ticket' => $pricePerTicket,
                'total_amount' => $pricePerTicket,
                'payment_reason' => $paymentReason,
                'passengers' => [
                    [
                        'index' => 1,
                        'first_name' => $passenger->first_name ?? '',
                        'last_name' => $passenger->last_name ?? '',
                        'email' => $passenger->email ?? '',
                        'seat_id' => $seatId,
                        'ticket_code' => $passenger->ticket_code ?? '',
                        'registration_type' => $registrationType,
                        'payment_status' => 'pending',
                        'base_amount' => max(0, $pricePerTicket - $penaltyAmount),
                        'penalty_amount' => $penaltyAmount,
                        'amount' => $pricePerTicket,
                    ]
                ],
                'registration' => $registrationPayload,
            ]);

            if ($paymentPdfPath && file_exists($paymentPdfPath)) {
                $email->addAttachment($paymentPdfPath, 'ficha-de-pago-rh-experience.pdf');
            }
        }

        $email->send();

        return true;
    }

    public static function downloadPassengerDocuments()
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            JsonResponse::send([
                'success' => false,
                'message' => 'Método no permitido'
            ], 405);
            return;
        }

        try {
            $payload = self::readBody();
            $passengerId = (int) ($payload['passenger_id'] ?? 0);

            if ($passengerId <= 0) {
                JsonResponse::send([
                    'success' => false,
                    'message' => 'El pasajero es obligatorio.'
                ], 400);
                return;
            }

            $passenger = TicketPassenger::find($passengerId);

            if (!$passenger) {
                JsonResponse::send([
                    'success' => false,
                    'message' => 'Pasajero no encontrado.'
                ], 404);
                return;
            }

            $trip = Trip::find((int) $passenger->trip_id);

            if (!$trip) {
                JsonResponse::send([
                    'success' => false,
                    'message' => 'Viaje no encontrado.'
                ], 404);
                return;
            }

            $registration = self::findPassengerRegistration($passengerId);
            $registrationType = $registration['registration_type'] ?? $passenger->registration_type ?? '';
            $seatId = (string) $passenger->seat_id;
            $passengerName = trim(($passenger->first_name ?? '') . ' ' . ($passenger->last_name ?? ''));

            $registrationPayload = [
                'registration_type' => $registrationType,
                'area_code' => $registration['area_code'] ?? null,
                'alliance_name' => $registration['alliance_name'] ?? null,
                'alliance_code' => $registration['alliance_code'] ?? null,
                'prize_code' => $registration['prize_code'] ?? null,
                'development' => $registration['development'] ?? null,
                'stage' => $registration['stage'] ?? null,
                'lot_number' => $registration['lot_number'] ?? null,
                'advisor_name' => $registration['advisor_name'] ?? null,
                'advisor_code' => $registration['advisor_code'] ?? null,
                'origin' => $registration['origin'] ?? null,
                'origin_alliance_name' => $registration['origin_alliance_name'] ?? null,
            ];

            $documentsPayload = [
                'file_label' => $passengerName ?: 'pasajero',
                'tickets' => [
                    'trip_data' => [
                        'trip_id' => (int) $passenger->trip_id,
                        'date' => $trip->trip_date,
                        'seats' => [$seatId],
                        'tickets_name' => $passengerName,
                    ],
                    'passengers' => [
                        [
                            'seat_id' => $seatId,
                            'first_name' => $passenger->first_name ?? '',
                            'last_name' => $passenger->last_name ?? '',
                            'email' => $passenger->email ?? '',
                            'phone' => $passenger->phone ?? '',
                            'ticket_code' => $passenger->ticket_code ?? '',
                            'registration_type' => $registrationType,
                        ]
                    ],
                    'registration' => $registrationPayload,
                ],
            ];

            if (($passenger->payment_status ?? 'free') === 'pending') {
                $passengerIndex = self::getPassengerIndexInTicket(
                    (int) $passenger->ticket_id,
                    (int) $passenger->id
                );
                $penaltyAmount = (int) ($passenger->penalty_amount ?? 0);
                $pricePerTicket = self::getPaymentAmount(
                    $registrationType,
                    $passengerIndex,
                    'pending',
                    $penaltyAmount
                );

                $documentsPayload['payment_slip'] = [
                    'client_name' => $passengerName,
                    'client_email' => $passenger->email,
                    'trip_date' => $trip->trip_date,
                    'seats' => [$seatId],
                    'tickets_count' => 1,
                    'price_per_ticket' => $pricePerTicket,
                    'total_amount' => $pricePerTicket,
                    'passengers' => [
                        [
                            'index' => 1,
                            'first_name' => $passenger->first_name ?? '',
                            'last_name' => $passenger->last_name ?? '',
                            'email' => $passenger->email ?? '',
                            'seat_id' => $seatId,
                            'registration_type' => $registrationType,
                            'payment_status' => 'pending',
                            'base_amount' => max(0, $pricePerTicket - $penaltyAmount),
                            'penalty_amount' => $penaltyAmount,
                            'amount' => $pricePerTicket,
                        ]
                    ],
                    'registration' => $registrationPayload,
                ];
            }

            $pdfPath = TicketPdfService::buildPassengerDocumentsPdf($documentsPayload);

            if (!$pdfPath || !file_exists($pdfPath)) {
                throw new \Exception('No se pudo generar el documento del pasajero.');
            }

            $fileContents = file_get_contents($pdfPath);
            $safeName = preg_replace('/[^A-Za-z0-9_\-]/', '-', $passengerName ?: 'pasajero');
            $downloadName = 'RH-Documentos-' . trim($safeName, '-') . '.pdf';

            if (ob_get_length()) {
                ob_clean();
            }

            header('Content-Type: application/pdf');
            header('Content-Disposition: attachment; filename="' . $downloadName . '"');
            header('Content-Length: ' . strlen($fileContents));
            echo $fileContents;
            exit;
        } catch (\Throwable $e) {
            error_log('Error al generar documentos del pasajero: ' . $e->getMessage());

            JsonResponse::send([
                'success' => false,
                'message' => 'No se pudo generar el documento del pasajero.'
            ], 500);
            return;
        }
    }

    private static function findPassengerRegistration(int $passengerId): array
    {
        $db = TicketPassenger::getDB();

        $stmt = $db->prepare("
            SELECT
                registration_type,
                area_code,
                alliance_name,
                alliance_code,
                prize_code,
                development,
                stage,
                lot_number,
                advisor_name,
                advisor_code,
                origin,
                origin_alliance_name
            FROM reservation_registrations
            WHERE ticket_passenger_id = ?
            LIMIT 1
        ");

        if (!$stmt) {
            return [];
        }

        $stmt->bind_param('i', $passengerId);
        $stmt->execute();

        return $stmt->get_result()->fetch_assoc() ?: [];
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
                ($payload['trip_data']['tickets_name'] ?? '')
                    ?: (($firstPassenger['first_name'] ?? '') . ' ' . ($firstPassenger['last_name'] ?? ''))
            );

            $tripDate = $payload['trip_data']['date'] ?? '';

            $payloadPdf = [
                'trip_data' => [
                    'trip_id' => $payload['trip_data']['trip_id'],
                    'date' => $tripDate,
                    'seats' => $seatNumbers,
                    'tickets_name' => $ticketsName
                ],
                'passengers' => $payload['passengers'],
                'registration' => $payload['RegistrationType'] ?? [],
            ];

            $ticketsPdfPath = TicketPdfService::buildTicketsPdf($payloadPdf);

            $paymentNotice = self::getNoShowPenaltyNotice($payload['passengers']);

            $email = new Email(
                $emailTo,
                $ticketsName,
                'Tus Boletos RH',
                $tripDate,
                implode(', ', $seatNumbers),
                '',
                'reservation',
                $paymentNotice
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
                    'base_total_amount' => $payment['base_total_amount'],
                    'penalty_total_amount' => $payment['penalty_total_amount'],
                    'total_amount' => $payment['total_amount'],
                    'payment_reason' => $payment['payment_reason'],
                    'passengers' => $payment['passengers'],
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

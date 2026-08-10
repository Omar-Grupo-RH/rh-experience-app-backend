<?php

namespace Model;

use DateTime;
use DateTimeZone;

class TicketPassenger extends ActiveRecord
{
    protected static $tabla = 'ticket_passengers';

    protected static $columnasDB = [
        'id',
        'ticket_id',
        'trip_id',
        'ticket_code',
        'payment_status',
        'paid_at',
        'paid_by',
        'seat_id',
        'first_name',
        'last_name',
        'email',
        'phone_country_code',
        'phone',
        'born_date',
        'allergies',
        'health_conditions',
        'created_at'
    ];

    public ?int $id;
    public ?int $ticket_id;
    public ?int $trip_id;
    public ?string $ticket_code;
    public string $payment_status;
    public ?string $paid_at;
    public ?int $paid_by;
    public ?string $seat_id;
    public string $first_name;
    public string $last_name;
    public string $email;
    public string $phone_country_code;
    public string $phone;
    public ?string $born_date;
    public string $allergies;
    public string $health_conditions;
    public string $created_at;

    public function __construct($args = [])
    {
        $this->id = $args['id'] ?? null;
        $this->ticket_id = isset($args['ticket_id']) ? (int) $args['ticket_id'] : null;
        $this->trip_id = isset($args['trip_id']) ? (int) $args['trip_id'] : null;

        $this->ticket_code = $args['ticket_code'] ?? null;
        $this->payment_status = $args['payment_status'] ?? 'free';
        $this->paid_at = $args['paid_at'] ?? null;
        $this->paid_by = isset($args['paid_by']) ? (int) $args['paid_by'] : null;

        $this->seat_id = isset($args['seat_id'])
            ? strtoupper(trim((string) $args['seat_id']))
            : null;

        $this->first_name = trim($args['first_name'] ?? '');
        $this->last_name = trim($args['last_name'] ?? '');
        $this->email = trim($args['email'] ?? '');
        $this->phone_country_code = trim($args['phone_country_code'] ?? '');
        $this->phone = trim($args['phone'] ?? '');

        $this->born_date = $args['born_date'] ?? null;

        $this->allergies = is_array($args['allergies'] ?? null)
            ? json_encode($args['allergies'], JSON_UNESCAPED_UNICODE)
            : ($args['allergies'] ?? '[]');

        $this->health_conditions = is_array($args['health_conditions'] ?? null)
            ? json_encode($args['health_conditions'], JSON_UNESCAPED_UNICODE)
            : ($args['health_conditions'] ?? '[]');

        $this->created_at = $args['created_at'] ?? $this->nowDateMerida();
    }

    public function nowDateMerida(): string
    {
        $dt = new DateTime('now', new DateTimeZone('America/Merida'));
        return $dt->format('Y-m-d');
    }

    public function fullName(): string
    {
        return trim($this->first_name . ' ' . $this->last_name);
    }

    public static function generateTicketCode(): string
    {
        return 'RH-' . strtoupper(bin2hex(random_bytes(4)));
    }

    public static function generateUniqueTicketCode(): string
    {
        $db = self::getDB();

        do {
            $code = self::generateTicketCode();

            $query = "
                SELECT id
                FROM " . static::$tabla . "
                WHERE ticket_code = ?
                LIMIT 1
            ";

            $stmt = $db->prepare($query);

            if (!$stmt) {
                throw new \Exception('Error validando código de boleto: ' . $db->error);
            }

            $stmt->bind_param('s', $code);
            $stmt->execute();

            $result = $stmt->get_result();
            $exists = $result->fetch_assoc();
        } while ($exists);

        return $code;
    }

    public static function passengerHasPreviousRegistration(string $email): bool
    {
        $db = self::getDB();

        $email = trim($email);

        if (!$email || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return false;
        }

        $query = "
            SELECT id
            FROM " . static::$tabla . "
            WHERE LOWER(TRIM(email)) = LOWER(TRIM(?))
            LIMIT 1
        ";

        $stmt = $db->prepare($query);

        if (!$stmt) {
            throw new \Exception('Error validando historial del pasajero: ' . $db->error);
        }

        $stmt->bind_param('s', $email);
        $stmt->execute();

        $result = $stmt->get_result();

        return (bool) $result->fetch_assoc();
    }

    public static function resolvePaymentStatus(
        string $registrationType,
        int $passengerIndex,
        array $passengerData
    ): string {
        if ($registrationType === 'prospect') {
            return 'pending';
        }

        if ($registrationType === 'family_friend') {
            return 'pending';
        }

        if ($registrationType === 'investor') {
            return $passengerIndex === 0 ? 'free' : 'pending';
        }

        if ($registrationType === 'internal') {
            $email = trim($passengerData['email'] ?? '');

            return self::passengerHasPreviousRegistration($email)
                ? 'pending'
                : 'free';
        }

        return 'free';
    }

    public static function confirmPayment(int $passengerId): ?array
    {
        $db = self::getDB();

        $select = "
            SELECT
                id,
                ticket_code,
                payment_status,
                paid_at
            FROM " . static::$tabla . "
            WHERE id = ?
            LIMIT 1
        ";

        $stmt = $db->prepare($select);

        if (!$stmt) {
            throw new \Exception('Error preparando consulta de pasajero: ' . $db->error);
        }

        $stmt->bind_param('i', $passengerId);
        $stmt->execute();

        $passenger = $stmt->get_result()->fetch_assoc();

        if (!$passenger) {
            return null;
        }

        if ($passenger['payment_status'] === 'free') {
            throw new \Exception('Este boleto es gratuito, no requiere pago.');
        }

        if ($passenger['payment_status'] === 'paid') {
            return [
                'id' => (int) $passenger['id'],
                'ticket_code' => $passenger['ticket_code'],
                'payment_status' => 'paid',
                'paid_at' => $passenger['paid_at'],
            ];
        }

        $update = "
            UPDATE " . static::$tabla . "
            SET
                payment_status = 'paid',
                paid_at = NOW()
            WHERE id = ?
            LIMIT 1
        ";

        $stmtUpdate = $db->prepare($update);

        if (!$stmtUpdate) {
            throw new \Exception('Error preparando actualización de pago: ' . $db->error);
        }

        $stmtUpdate->bind_param('i', $passengerId);
        $stmtUpdate->execute();

        $selectUpdated = "
            SELECT
                id,
                ticket_code,
                payment_status,
                paid_at
            FROM " . static::$tabla . "
            WHERE id = ?
            LIMIT 1
        ";

        $stmtUpdated = $db->prepare($selectUpdated);

        if (!$stmtUpdated) {
            throw new \Exception('Error consultando pago actualizado: ' . $db->error);
        }

        $stmtUpdated->bind_param('i', $passengerId);
        $stmtUpdated->execute();

        $updated = $stmtUpdated->get_result()->fetch_assoc();

        return [
            'id' => (int) $updated['id'],
            'ticket_code' => $updated['ticket_code'],
            'payment_status' => $updated['payment_status'],
            'paid_at' => $updated['paid_at'],
        ];
    }
}
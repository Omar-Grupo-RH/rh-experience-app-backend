<?php

namespace Model;

class TripPassenger extends ActiveRecord
{
    protected static $tabla = 'ticket_passengers';

    protected static $columnasDB = [
        'id',
        'ticket_id',
        'trip_id',
        'seat_id',
        'first_name',
        'last_name',
        'email',
        'phone_country_code',
        'phone',
        'born_date',
        'allergies',
        'created_at',
        'health_conditions',

        'registration_id',
        'registration_type',
        'area_code',
        'alliance_name',
        'alliance_code',
        'prize_code',
        'development',
        'stage',
        'lot_number',
        'advisor_name',
        'advisor_code',
        'origin',
        'origin_alliance_name',
        'registration_created_at',

        'trip_date',
        'title',
    ];

    public $id;
    public $ticket_id;
    public $trip_id;
    public $seat_id;
    public $first_name;
    public $last_name;
    public $email;
    public $phone_country_code;
    public $phone;
    public $born_date;
    public $allergies;
    public $health_conditions;
    public $created_at;

    public $registration_id;
    public $registration_type;
    public $area_code;
    public $alliance_name;
    public $alliance_code;
    public $prize_code;
    public $development;
    public $stage;
    public $lot_number;
    public $advisor_name;
    public $advisor_code;
    public $origin;
    public $origin_alliance_name;
    public $registration_created_at;

    public $trip_date;
    public $title;

    public function __construct($args = [])
    {
        $this->id = $args['id'] ?? null;
        $this->ticket_id = $args['ticket_id'] ?? null;
        $this->trip_id = $args['trip_id'] ?? null;
        $this->seat_id = $args['seat_id'] ?? '';

        $this->first_name = $args['first_name'] ?? '';
        $this->last_name = $args['last_name'] ?? '';
        $this->email = $args['email'] ?? '';
        $this->phone_country_code = $args['phone_country_code'] ?? '';
        $this->phone = $args['phone'] ?? '';
        $this->born_date = $args['born_date'] ?? null;
        $this->allergies = $args['allergies'] ?? '[]';
        $this->health_conditions = $args['health_conditions'] ?? '[]';
        $this->created_at = $args['created_at'] ?? null;

        $this->registration_id = $args['registration_id'] ?? null;
        $this->registration_type = $args['registration_type'] ?? null;
        $this->area_code = $args['area_code'] ?? null;
        $this->alliance_name = $args['alliance_name'] ?? null;
        $this->alliance_code = $args['alliance_code'] ?? null;
        $this->prize_code = $args['prize_code'] ?? null;
        $this->development = $args['development'] ?? null;
        $this->stage = $args['stage'] ?? null;
        $this->lot_number = $args['lot_number'] ?? null;
        $this->advisor_name = $args['advisor_name'] ?? null;
        $this->advisor_code = $args['advisor_code'] ?? null;
        $this->origin = $args['origin'] ?? null;
        $this->origin_alliance_name = $args['origin_alliance_name'] ?? null;
        $this->registration_created_at = $args['registration_created_at'] ?? null;

        $this->trip_date = $args['trip_date'] ?? null;
        $this->title = $args['title'] ?? '';
    }

    public static function allPassengersWithRegistrationByTrip(int $tripId): array
    {
        $db = self::getDB();

        $query = "
        SELECT 
            tp.id,
            tp.ticket_id,
            tp.trip_id,

            tp.ticket_code,
            tp.payment_status,
            tp.paid_at,
            tp.paid_by,

            tp.seat_id,
            tp.first_name,
            tp.last_name,
            tp.email,
            tp.phone_country_code,
            tp.phone,
            tp.born_date,
            tp.allergies,
            tp.health_conditions,    
            tp.created_at,

            rr.id AS registration_id,
            rr.registration_type,
            rr.area_code,
            rr.alliance_name,
            rr.alliance_code,
            rr.prize_code,
            rr.development,
            rr.stage,
            rr.lot_number,
            rr.advisor_name,
            rr.advisor_code,
            rr.origin,
            rr.origin_alliance_name,
            rr.created_at AS registration_created_at,

            t.trip_date,
            t.title

        FROM ticket_passengers tp

        LEFT JOIN reservation_registrations rr
            ON rr.ticket_passenger_id = tp.id

        LEFT JOIN trips t
            ON t.id = tp.trip_id

        WHERE tp.trip_id = ?

        ORDER BY tp.seat_id ASC
    ";

        $stmt = $db->prepare($query);

        if (!$stmt) {
            throw new \Exception('Error preparando consulta: ' . $db->error);
        }

        $tripId = (int) $tripId;

        $stmt->bind_param('i', $tripId);
        $stmt->execute();

        $result = $stmt->get_result();

        $passengers = [];

        while ($row = $result->fetch_assoc()) {
            $row['id'] = (int) $row['id'];
            $row['ticket_id'] = (int) $row['ticket_id'];
            $row['trip_id'] = (int) $row['trip_id'];

            $row['ticket_code'] = $row['ticket_code'] ?? null;
            $row['payment_status'] = $row['payment_status'] ?? 'free';
            $row['paid_at'] = $row['paid_at'] ?? null;
            $row['paid_by'] = $row['paid_by'] ?? null;

            $passengers[] = $row;
        }

        return $passengers;
    }
}
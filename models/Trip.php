<?php

namespace Model;


class Trip extends ActiveRecord
{
    protected static $tabla = 'trips';
    protected static $columnasDB = [
        'id',
        'trip_date',
        'total_seats',
        'title',
        'is_active',
    ];

    public $id;
    public $trip_date;
    public $total_seats;
    public $title;
    public $avaible_seats;
    public $passengers_count;
    public $unavaible_seats;
    public $is_active;

    public const DEFAULT_CAPACITY = 15;
    public const ALLOWED_CAPACITIES = [8, 15];

    // Debe coincidir con frontend-experience/src/utils/busLayout.js (fila A reservada para staff)
    private const SEAT_LAYOUTS = [
        8  => ['B' => 2, 'C' => 3, 'D' => 3],
        15 => ['B' => 2, 'C' => 3, 'D' => 3, 'E' => 3, 'F' => 4],
    ];

    public function __construct($args = [])
    {
        $this->id          = $args['id'] ?? null;
        $this->trip_date   = $args['trip_date'] ?? null;
        $this->total_seats = $args['total_seats'] ?? self::DEFAULT_CAPACITY;
        $this->title       = $args['title'] ?? '';
        $this->is_active   = $args['is_active'] ?? 1;
    }

    public static function isAllowedCapacity($capacity): bool
    {
        return is_numeric($capacity)
            && in_array((int) $capacity, self::ALLOWED_CAPACITIES, true);
    }

    /**
     * Asientos válidos para la capacidad indicada, o null si la capacidad
     * no tiene un layout definido (en ese caso no se restringe por layout).
     */
    public static function seatIdsForCapacity($capacity): ?array
    {
        $capacity = (int) $capacity;

        if (!isset(self::SEAT_LAYOUTS[$capacity])) {
            return null;
        }

        $ids = [];

        foreach (self::SEAT_LAYOUTS[$capacity] as $row => $count) {
            for ($i = 1; $i <= $count; $i++) {
                $ids[] = $row . $i;
            }
        }

        return $ids;
    }

    /**
     * Devuelve los asientos que no pertenecen al layout de la capacidad.
     */
    public static function invalidSeatsForCapacity(array $seats, $capacity): array
    {
        $validIds = self::seatIdsForCapacity($capacity);

        if ($validIds === null) {
            return [];
        }

        return array_values(array_filter($seats, function ($seat) use ($validIds) {
            return !in_array(strtoupper(trim((string) $seat)), $validIds, true);
        }));
    }
}
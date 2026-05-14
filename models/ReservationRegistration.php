<?php

namespace Model;

use DateTime;
use DateTimeZone;

class ReservationRegistration extends ActiveRecord
{
    protected static $tabla = 'reservation_registrations';

    protected static $columnasDB = [
        'id',
        'ticket_id',
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
        'created_at'
    ];

    public ?int $id;
    public ?int $ticket_id;

    public string $registration_type;

    public ?string $area_code;
    public ?string $alliance_name;
    public ?string $alliance_code;
    public ?string $prize_code;
    public ?string $development;
    public ?string $stage;
    public ?string $lot_number;
    public ?string $advisor_name;
    public ?string $advisor_code;
    public ?string $origin;
    public ?string $origin_alliance_name;

    public string $created_at;

    public function __construct($args = [])
    {
        $this->id = $args['id'] ?? null;
        $this->ticket_id = isset($args['ticket_id']) ? (int) $args['ticket_id'] : null;

        $this->registration_type = trim($args['registration_type'] ?? '');

        $this->area_code = $this->cleanNullable($args['area_code'] ?? null);

        $this->alliance_name = $this->cleanNullable($args['alliance_name'] ?? null);
        $this->alliance_code = $this->cleanNullable($args['alliance_code'] ?? null);

        $this->prize_code = $this->cleanNullable($args['prize_code'] ?? null);

        $this->development = $this->cleanNullable($args['development'] ?? null);
        $this->stage = $this->cleanNullable($args['stage'] ?? null);
        $this->lot_number = $this->cleanNullable($args['lot_number'] ?? null);

        $this->advisor_name = $this->cleanNullable($args['advisor_name'] ?? null);
        $this->advisor_code = $this->cleanNullable($args['advisor_code'] ?? null);
        $this->origin = $this->cleanNullable($args['origin'] ?? null);
        $this->origin_alliance_name = $this->cleanNullable($args['origin_alliance_name'] ?? null);

        $this->created_at = $args['created_at'] ?? $this->nowDateMerida();
    }

    private function cleanNullable($value ): ?string
    {
        if ($value === null) return null;

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    public function validar(): array
    {
        $errores = [];

        $allowedTypes = [
            'internal',
            'alliance',
            'prize',
            'investor',
            'prospect'
        ];

        if (!$this->ticket_id) {
            $errores[] = 'El ticket es obligatorio.';
        }

        if (!$this->registration_type || !in_array($this->registration_type, $allowedTypes, true)) {
            $errores[] = 'El tipo de registro no es válido.';
        }

        if ($this->registration_type === 'internal' && !$this->area_code) {
            $errores[] = 'El código de área es obligatorio.';
        }

        if ($this->registration_type === 'alliance') {
            if (!$this->alliance_name) {
                $errores[] = 'El nombre de la alianza es obligatorio.';
            }

            if (!$this->alliance_code) {
                $errores[] = 'El código de alianza es obligatorio.';
            }
        }

        if ($this->registration_type === 'prize' && !$this->prize_code) {
            $errores[] = 'El código de premio es obligatorio.';
        }

        if ($this->registration_type === 'investor') {
            if (!$this->development) {
                $errores[] = 'El desarrollo es obligatorio.';
            }

            if (!$this->stage) {
                $errores[] = 'La etapa es obligatoria.';
            }

            if (!$this->lot_number) {
                $errores[] = 'El lote es obligatorio.';
            }
        }

        if ($this->registration_type === 'prospect') {
            if (!$this->development) {
                $errores[] = 'El desarrollo es obligatorio.';
            }

            if (!$this->advisor_name) {
                $errores[] = 'El nombre del asesor es obligatorio.';
            }

            if (!$this->advisor_code) {
                $errores[] = 'El código del asesor es obligatorio.';
            }

            if (!$this->origin) {
                $errores[] = 'El origen es obligatorio.';
            }

            if ($this->origin === 'alianza' && !$this->origin_alliance_name) {
                $errores[] = 'El nombre de la alianza de origen es obligatorio.';
            }
        }

        return $errores;
    }

    public function nowDateMerida(): string
    {
        $dt = new DateTime('now', new DateTimeZone('America/Merida'));
        return $dt->format('Y-m-d');
    }
}
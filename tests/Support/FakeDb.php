<?php

declare(strict_types=1);

namespace Tests\Support;

/**
 * Doble de prueba que imita la interfaz de mysqli usada por los modelos
 * (prepare -> bind_param -> execute -> get_result -> fetch_assoc).
 *
 * Responde según la consulta recibida para poder probar las reglas de
 * negocio de TicketPassenger sin una base de datos real.
 */
final class FakeDb
{
    public string $error = '';

    /** Valor devuelto para la consulta de inasistencias (no_show_count). */
    public int $noShowCount = 0;

    /** Fila devuelta para la consulta de no-show interno reciente, o null. */
    public ?array $internalNoShow = null;

    /** Si el pasajero tiene un registro previo (consulta por email). */
    public bool $hasPreviousRegistration = false;

    public function prepare(string $query): FakeStmt
    {
        if (str_contains($query, 'no_show_count')) {
            return new FakeStmt([['no_show_count' => $this->noShowCount]]);
        }

        if (str_contains($query, 'no_show_date')) {
            return new FakeStmt([[
                'no_show_date' => $this->internalNoShow['no_show_date'] ?? null,
                'restriction_ends_at' => $this->internalNoShow['restriction_ends_at'] ?? null,
            ]]);
        }

        // Consulta de historial previo del pasajero (SELECT id ... WHERE email)
        return new FakeStmt(
            $this->hasPreviousRegistration ? [['id' => 1]] : []
        );
    }
}

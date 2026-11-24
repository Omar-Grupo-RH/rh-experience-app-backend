<?php

declare(strict_types=1);

namespace Tests\Unit\Models;

use Model\Trip;
use PHPUnit\Framework\TestCase;

final class TripTest extends TestCase
{
    public function testConstructorAppliesDefaults(): void
    {
        $trip = new Trip();

        $this->assertNull($trip->id);
        $this->assertNull($trip->trip_date);
        $this->assertSame(Trip::DEFAULT_CAPACITY, $trip->total_seats);
        $this->assertSame('', $trip->title);
        $this->assertSame(1, $trip->is_active);
    }

    public function testConstructorAcceptsExplicitValues(): void
    {
        $trip = new Trip([
            'id' => 7,
            'trip_date' => '2026-12-01',
            'total_seats' => 8,
            'title' => 'Viaje de prueba',
            'is_active' => 0,
        ]);

        $this->assertSame(7, $trip->id);
        $this->assertSame('2026-12-01', $trip->trip_date);
        $this->assertSame(8, $trip->total_seats);
        $this->assertSame('Viaje de prueba', $trip->title);
        $this->assertSame(0, $trip->is_active);
    }

    public function testIsAllowedCapacityAcceptsOnlyConfiguredCapacities(): void
    {
        $this->assertTrue(Trip::isAllowedCapacity(8));
        $this->assertTrue(Trip::isAllowedCapacity(15));
        $this->assertTrue(Trip::isAllowedCapacity('15'));

        $this->assertFalse(Trip::isAllowedCapacity(10));
        $this->assertFalse(Trip::isAllowedCapacity(0));
        $this->assertFalse(Trip::isAllowedCapacity(-8));
        $this->assertFalse(Trip::isAllowedCapacity('abc'));
        $this->assertFalse(Trip::isAllowedCapacity(null));
    }

    public function testSeatIdsForCapacityEight(): void
    {
        $this->assertSame(
            ['B1', 'B2', 'C1', 'C2', 'C3', 'D1', 'D2', 'D3'],
            Trip::seatIdsForCapacity(8)
        );
    }

    public function testSeatIdsForCapacityFifteen(): void
    {
        $ids = Trip::seatIdsForCapacity(15);

        $this->assertCount(15, $ids);
        $this->assertSame('B1', $ids[0]);
        $this->assertSame('F4', $ids[14]);
        // La fila A (staff) nunca forma parte del layout vendible.
        $this->assertNotContains('A1', $ids);
    }

    public function testSeatIdsForUnknownCapacityReturnsNull(): void
    {
        $this->assertNull(Trip::seatIdsForCapacity(10));
        $this->assertNull(Trip::seatIdsForCapacity(0));
    }

    public function testInvalidSeatsForCapacityDetectsSeatsOutsideLayout(): void
    {
        $invalid = Trip::invalidSeatsForCapacity(['B1', 'E1', 'F4', 'Z9'], 8);

        $this->assertSame(['E1', 'F4', 'Z9'], $invalid);
    }

    public function testInvalidSeatsForCapacityNormalizesCaseAndSpaces(): void
    {
        $this->assertSame([], Trip::invalidSeatsForCapacity([' b1 ', 'c3'], 8));
    }

    public function testStaffRowIsInvalidForPassengers(): void
    {
        $this->assertSame(['A1'], Trip::invalidSeatsForCapacity(['A1', 'B1'], 15));
    }

    public function testInvalidSeatsForUnknownCapacityDoesNotRestrict(): void
    {
        $this->assertSame([], Trip::invalidSeatsForCapacity(['Z9'], 99));
    }
}

<?php

declare(strict_types=1);

namespace Tests\Unit\Models;

use Model\ActiveRecord;
use Model\SurveyResponse;
use PHPUnit\Framework\TestCase;
use Tests\Support\FakeResult;
use Tests\Support\FakeStmt;

final class SurveyResponseTest extends TestCase
{
    protected function tearDown(): void
    {
        ActiveRecord::setDB(null);
    }

    public function testHistoricalAndreaRatingIsExcludedFromListAndDetail(): void
    {
        $row = [
            'answers_json' => json_encode([
                'staff_andrea_rating' => '5',
                'staff_jaqueline_rating' => '4',
            ]),
            'trip_date' => '2026-11-24',
            'registration_type' => 'investor',
        ];

        ActiveRecord::setDB(new class ($row) {
            public function __construct(private array $row)
            {
            }

            public function query(string $query): FakeResult
            {
                return new FakeResult([$this->row]);
            }

            public function prepare(string $query): FakeStmt
            {
                return new FakeStmt([$this->row]);
            }
        });

        foreach ([SurveyResponse::allByTrip()[0], SurveyResponse::findFull(1)] as $response) {
            $this->assertArrayNotHasKey('staff_andrea_rating', $response['answers']);
            $this->assertArrayNotHasKey('staff_andrea_rating', json_decode($response['answers_json'], true));
            $this->assertSame('4', $response['answers']['staff_jaqueline_rating']);
            $this->assertSame('Inversionista', $response['answers']['participant_type']);
        }
    }
}

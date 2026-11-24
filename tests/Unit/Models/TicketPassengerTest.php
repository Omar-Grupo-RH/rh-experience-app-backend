<?php

declare(strict_types=1);

namespace Tests\Unit\Models;

use DateTime;
use DateTimeZone;
use Model\ActiveRecord;
use Model\TicketPassenger;
use PHPUnit\Framework\TestCase;
use Tests\Support\FakeDb;

final class TicketPassengerTest extends TestCase
{
    private FakeDb $db;

    protected function setUp(): void
    {
        $this->db = new FakeDb();
        ActiveRecord::setDB($this->db);
    }

    protected function tearDown(): void
    {
        ActiveRecord::setDB(null);
    }

    // ---------- Constructor / normalización ----------

    public function testConstructorAppliesBusinessDefaults(): void
    {
        $p = new TicketPassenger();

        $this->assertSame('free', $p->payment_status);
        $this->assertSame(0, $p->penalty_amount);
        $this->assertSame('ausencia', $p->attendance_status);
        $this->assertSame('[]', $p->allergies);
        $this->assertSame('[]', $p->health_conditions);
        $this->assertNull($p->seat_id);

        $hoyMerida = (new DateTime('now', new DateTimeZone('America/Merida')))->format('Y-m-d');
        $this->assertSame($hoyMerida, $p->created_at);
    }

    public function testConstructorNormalizesSeatAndTrimsFields(): void
    {
        $p = new TicketPassenger([
            'seat_id' => ' b3 ',
            'first_name' => '  Ana ',
            'last_name' => ' López ',
            'email' => ' ana@example.com ',
            'phone' => ' 9991234567 ',
            'city' => ' Mérida ',
        ]);

        $this->assertSame('B3', $p->seat_id);
        $this->assertSame('Ana', $p->first_name);
        $this->assertSame('López', $p->last_name);
        $this->assertSame('ana@example.com', $p->email);
        $this->assertSame('9991234567', $p->phone);
        $this->assertSame('Mérida', $p->city);
    }

    public function testConstructorEncodesArrayAllergiesAsJson(): void
    {
        $p = new TicketPassenger([
            'allergies' => ['nuez', 'camarón'],
            'health_conditions' => ['asma'],
        ]);

        $this->assertSame(['nuez', 'camarón'], json_decode($p->allergies, true));
        $this->assertSame(['asma'], json_decode($p->health_conditions, true));
    }

    public function testFullNameConcatenatesAndTrims(): void
    {
        $p = new TicketPassenger(['first_name' => 'Ana', 'last_name' => 'López']);
        $this->assertSame('Ana López', $p->fullName());

        $soloNombre = new TicketPassenger(['first_name' => 'Ana']);
        $this->assertSame('Ana', $soloNombre->fullName());
    }

    // ---------- Código de boleto ----------

    public function testGenerateTicketCodeMatchesExpectedFormat(): void
    {
        $code = TicketPassenger::generateTicketCode();

        $this->assertMatchesRegularExpression('/^RH-[0-9A-F]{8}$/', $code);
    }

    public function testGenerateTicketCodeIsRandom(): void
    {
        $codes = [];
        for ($i = 0; $i < 20; $i++) {
            $codes[] = TicketPassenger::generateTicketCode();
        }

        $this->assertSame(count($codes), count(array_unique($codes)));
    }

    // ---------- resolvePaymentStatus: reglas de negocio ----------

    public function testProspectAndFamilyFriendAlwaysPending(): void
    {
        $data = ['email' => 'nuevo@example.com'];

        $this->assertSame('pending', TicketPassenger::resolvePaymentStatus('prospect', 0, $data));
        $this->assertSame('pending', TicketPassenger::resolvePaymentStatus('family_friend', 0, $data));
    }

    public function testInvestorFirstPassengerFreeRestPending(): void
    {
        $data = ['email' => 'inversor@example.com'];

        $this->assertSame('free', TicketPassenger::resolvePaymentStatus('investor', 0, $data));
        $this->assertSame('pending', TicketPassenger::resolvePaymentStatus('investor', 1, $data));
        $this->assertSame('pending', TicketPassenger::resolvePaymentStatus('investor', 2, $data));
    }

    public function testInternalFirstTimeIsFree(): void
    {
        $this->db->hasPreviousRegistration = false;

        $this->assertSame(
            'free',
            TicketPassenger::resolvePaymentStatus('internal', 0, ['email' => 'colab@example.com'])
        );
    }

    public function testInternalWithPreviousRegistrationIsPending(): void
    {
        $this->db->hasPreviousRegistration = true;

        $this->assertSame(
            'pending',
            TicketPassenger::resolvePaymentStatus('internal', 0, ['email' => 'colab@example.com'])
        );
    }

    public function testOtherTypesDefaultToFree(): void
    {
        $data = ['email' => 'otro@example.com'];

        $this->assertSame('free', TicketPassenger::resolvePaymentStatus('alliance', 0, $data));
        $this->assertSame('free', TicketPassenger::resolvePaymentStatus('prize', 0, $data));
    }

    public function testTwoNoShowsForcePendingRegardlessOfType(): void
    {
        $this->db->noShowCount = 2;

        $this->assertSame(
            'pending',
            TicketPassenger::resolvePaymentStatus('alliance', 0, ['email' => 'faltista@example.com'])
        );
    }

    public function testSingleNoShowDoesNotForcePending(): void
    {
        $this->db->noShowCount = 1;

        $this->assertSame(
            'free',
            TicketPassenger::resolvePaymentStatus('alliance', 0, ['email' => 'unafalta@example.com'])
        );
    }

    public function testRecentInternalNoShowForcesPending(): void
    {
        $this->db->internalNoShow = [
            'no_show_date' => '2026-09-01',
            'restriction_ends_at' => '2027-03-01',
        ];

        $this->assertSame(
            'pending',
            TicketPassenger::resolvePaymentStatus('internal', 0, ['email' => 'noshow@example.com'])
        );
    }

    // ---------- Penalizaciones / historial con email inválido ----------

    public function testInvalidEmailNeverTriggersPenaltyQueries(): void
    {
        $this->assertFalse(TicketPassenger::passengerRequiresNoShowPenalty('no-es-email'));
        $this->assertFalse(TicketPassenger::passengerHasPreviousRegistration(''));
        $this->assertSame(0, TicketPassenger::passengerNoShowCount('   '));
        $this->assertNull(TicketPassenger::passengerRecentInternalNoShow('x'));
    }
}

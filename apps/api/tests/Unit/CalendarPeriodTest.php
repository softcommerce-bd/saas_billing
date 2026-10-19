<?php

namespace Tests\Unit;

use App\Modules\Subscriptions\Services\CalendarPeriod;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class CalendarPeriodTest extends TestCase
{
    private CalendarPeriod $calendar;

    protected function setUp(): void
    {
        $this->calendar = new CalendarPeriod('Asia/Dhaka');
    }

    private function dhaka(string $local): CarbonImmutable
    {
        return CarbonImmutable::parse($local, 'Asia/Dhaka')->utc();
    }

    public static function freshActivations(): array
    {
        // activation (Dhaka local), months, expected valid-through (local date)
        return [
            'mid-month monthly' => ['2027-01-15 14:00', 1, '2027-02-15'],
            'quarterly' => ['2027-01-15 14:00', 3, '2027-04-15'],
            'half-yearly' => ['2027-01-15 14:00', 6, '2027-07-15'],
            'yearly' => ['2027-01-15 14:00', 12, '2028-01-15'],
            'jan 31 clamps to feb 28' => ['2027-01-31 10:00', 1, '2027-02-28'],
            'jan 31 leap year clamps to feb 29' => ['2028-01-31 10:00', 1, '2028-02-29'],
            'jan 30 clamps to feb 28' => ['2027-01-30 10:00', 1, '2027-02-28'],
            'dec 31 rolls year' => ['2027-12-31 23:59', 1, '2028-01-31'],
            'aug 31 + 6 months' => ['2027-08-31 09:00', 6, '2028-02-29'],
            'feb 29 + 12 months' => ['2028-02-29 09:00', 12, '2029-02-28'],
        ];
    }

    #[DataProvider('freshActivations')]
    public function test_fresh_activation_valid_through_same_day_n_months_later(string $activatedLocal, int $months, string $expectedThrough): void
    {
        $now = $this->dhaka($activatedLocal);
        $window = $this->calendar->extendByMonths($now, null, null, $months);

        $this->assertSame($expectedThrough, $this->calendar->validThrough($window['expires_at'])->toDateString());
        // expires_at is the exclusive boundary at local midnight.
        $this->assertSame('00:00:00', $window['expires_at']->setTimezone('Asia/Dhaka')->format('H:i:s'));
        $this->assertTrue($window['period_start']->equalTo($now));
    }

    public function test_expiry_is_start_of_next_local_day_stored_in_utc(): void
    {
        // Valid through 31 December => access ends at 1 January 00:00 Dhaka = 31 Dec 18:00 UTC.
        $window = $this->calendar->extendByMonths($this->dhaka('2027-10-31 12:00'), null, null, 2);

        $this->assertSame('2027-12-31', $this->calendar->validThrough($window['expires_at'])->toDateString());
        $this->assertSame('2027-12-31 18:00:00', $window['expires_at']->utc()->format('Y-m-d H:i:s'));
        $this->assertSame('UTC', $window['expires_at']->timezoneName);
    }

    public function test_local_date_uses_dhaka_not_utc(): void
    {
        // 19:30 UTC on Jan 15 is already 01:30 on Jan 16 in Dhaka.
        $now = CarbonImmutable::parse('2027-01-15 19:30', 'UTC');
        $window = $this->calendar->extendByMonths($now, null, null, 1);

        $this->assertSame('2027-02-16', $this->calendar->validThrough($window['expires_at'])->toDateString());
    }

    public function test_early_renewal_extends_from_current_expiry(): void
    {
        $first = $this->calendar->extendByMonths($this->dhaka('2027-01-15 10:00'), null, null, 1);
        // Renew 5 days before expiry.
        $now = $this->dhaka('2027-02-10 10:00');
        $second = $this->calendar->extendByMonths($now, $first['expires_at'], $first['anchor_day'], 1);

        $this->assertSame('2027-03-15', $this->calendar->validThrough($second['expires_at'])->toDateString());
        $this->assertTrue($second['period_start']->equalTo($first['expires_at']));
    }

    public function test_anchor_day_prevents_month_end_drift(): void
    {
        $first = $this->calendar->extendByMonths($this->dhaka('2027-01-31 10:00'), null, null, 1);
        $this->assertSame('2027-02-28', $this->calendar->validThrough($first['expires_at'])->toDateString());
        $this->assertSame(31, $first['anchor_day']);

        $second = $this->calendar->extendByMonths($this->dhaka('2027-02-20 10:00'), $first['expires_at'], $first['anchor_day'], 1);
        $this->assertSame('2027-03-31', $this->calendar->validThrough($second['expires_at'])->toDateString());

        $third = $this->calendar->extendByMonths($this->dhaka('2027-03-25 10:00'), $second['expires_at'], $second['anchor_day'], 1);
        $this->assertSame('2027-04-30', $this->calendar->validThrough($third['expires_at'])->toDateString());
    }

    public function test_renewal_after_expiry_starts_from_activation_time(): void
    {
        $first = $this->calendar->extendByMonths($this->dhaka('2027-01-15 10:00'), null, null, 1);
        $now = $this->dhaka('2027-03-03 16:00');
        $second = $this->calendar->extendByMonths($now, $first['expires_at'], $first['anchor_day'], 1);

        $this->assertSame('2027-04-03', $this->calendar->validThrough($second['expires_at'])->toDateString());
        $this->assertSame(3, $second['anchor_day']);
        $this->assertTrue($second['period_start']->equalTo($now));
    }

    public function test_renewal_exactly_at_expiry_instant_is_treated_as_expired(): void
    {
        $first = $this->calendar->extendByMonths($this->dhaka('2027-01-15 10:00'), null, null, 1);
        $second = $this->calendar->extendByMonths($first['expires_at'], $first['expires_at'], $first['anchor_day'], 1);

        // Expired exactly now: fresh start on Feb 16 (local), valid through Mar 16.
        $this->assertSame('2027-03-16', $this->calendar->validThrough($second['expires_at'])->toDateString());
    }

    public function test_day_based_trial_includes_today(): void
    {
        $window = $this->calendar->extendByDays($this->dhaka('2027-01-01 09:00'), null, 14);

        $this->assertSame('2027-01-14', $this->calendar->validThrough($window['expires_at'])->toDateString());
    }

    public function test_day_based_extension_of_valid_subscription(): void
    {
        $first = $this->calendar->extendByDays($this->dhaka('2027-01-01 09:00'), null, 14);
        $second = $this->calendar->extendByDays($this->dhaka('2027-01-05 09:00'), $first['expires_at'], 7);

        $this->assertSame('2027-01-21', $this->calendar->validThrough($second['expires_at'])->toDateString());
    }

    public function test_rejects_non_positive_lengths(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->calendar->extendByMonths(CarbonImmutable::now(), null, null, 0);
    }
}

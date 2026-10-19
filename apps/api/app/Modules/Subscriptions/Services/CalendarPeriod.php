<?php

namespace App\Modules\Subscriptions\Services;

use Carbon\CarbonImmutable;

/**
 * Calendar-month validity policy (documented in docs/subscriptions/subscription-lifecycle.md).
 *
 *  - Periods are computed on local calendar dates in the business timezone (Asia/Dhaka).
 *  - A subscription is "valid through" a local date T (inclusive). The stored expires_at is
 *    the EXCLUSIVE boundary: local midnight at the start of T + 1 day, converted to UTC.
 *  - Fresh activation on local date D for N months: valid through D + N months.
 *  - Renewal of a still-valid subscription extends from the current valid-through date.
 *  - Month-end dates are clamped (Jan 31 + 1 month = Feb 28/29) but the original
 *    anchor day is remembered, so the next renewal returns to the 31st (Mar 31).
 */
final class CalendarPeriod
{
    public function __construct(private readonly string $timezone) {}

    /**
     * Local date (at midnight) of an instant.
     */
    public function localDate(CarbonImmutable $instant): CarbonImmutable
    {
        return $instant->setTimezone($this->timezone)->startOfDay();
    }

    /**
     * Inclusive local "valid through" date for a stored exclusive expiry.
     */
    public function validThrough(CarbonImmutable $expiresAt): CarbonImmutable
    {
        return $this->localDate($expiresAt)->subDay();
    }

    /**
     * Exclusive UTC boundary for a period valid through the given local date.
     */
    public function boundaryAfter(CarbonImmutable $validThroughLocal): CarbonImmutable
    {
        return $validThroughLocal->setTimezone($this->timezone)->startOfDay()->addDay()->utc();
    }

    /**
     * Valid-through date that is $months calendar months after $reference, on the anchor day
     * (clamped to the length of the target month).
     */
    public function addMonths(CarbonImmutable $referenceLocal, int $months, int $anchorDay): CarbonImmutable
    {
        if ($months < 1) {
            throw new \InvalidArgumentException('Months must be positive.');
        }

        $target = $referenceLocal->setTimezone($this->timezone)->startOfMonth()->addMonthsNoOverflow($months);

        return $target->setDay(min($anchorDay, $target->daysInMonth))->startOfDay();
    }

    /**
     * @return array{expires_at: CarbonImmutable, anchor_day: int, period_start: CarbonImmutable}
     */
    public function extendByMonths(CarbonImmutable $now, ?CarbonImmutable $currentExpiresAt, ?int $anchorDay, int $months): array
    {
        if ($currentExpiresAt !== null && $currentExpiresAt->greaterThan($now)) {
            $reference = $this->validThrough($currentExpiresAt);
            $anchor = $anchorDay ?? $reference->day;
            $periodStart = $currentExpiresAt;
        } else {
            $reference = $this->localDate($now);
            $anchor = $reference->day;
            $periodStart = $now;
        }

        $through = $this->addMonths($reference, $months, $anchor);

        return [
            'expires_at' => $this->boundaryAfter($through),
            'anchor_day' => $anchor,
            'period_start' => $periodStart,
        ];
    }

    /**
     * Day-based extension (trials and complimentary grants). Fresh day-based periods
     * include the current local day: 14 days starting Jan 1 is valid through Jan 14.
     *
     * @return array{expires_at: CarbonImmutable, period_start: CarbonImmutable}
     */
    public function extendByDays(CarbonImmutable $now, ?CarbonImmutable $currentExpiresAt, int $days): array
    {
        if ($days < 1) {
            throw new \InvalidArgumentException('Days must be positive.');
        }

        if ($currentExpiresAt !== null && $currentExpiresAt->greaterThan($now)) {
            $through = $this->validThrough($currentExpiresAt)->addDays($days);
            $periodStart = $currentExpiresAt;
        } else {
            $through = $this->localDate($now)->addDays($days - 1);
            $periodStart = $now;
        }

        return ['expires_at' => $this->boundaryAfter($through), 'period_start' => $periodStart];
    }
}

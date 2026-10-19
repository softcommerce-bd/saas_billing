<?php

namespace App\Support\Time;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Date;

/**
 * Authoritative timestamps are UTC. Business reporting boundaries and subscription
 * calendar periods use the configured business timezone (Asia/Dhaka by default).
 */
final class BusinessClock
{
    public static function timezone(): string
    {
        return (string) config('app.business_timezone', 'Asia/Dhaka');
    }

    public static function now(): CarbonImmutable
    {
        return CarbonImmutable::instance(Date::now())->utc();
    }

    public static function toLocal(\DateTimeInterface $instant): CarbonImmutable
    {
        return CarbonImmutable::instance($instant)->setTimezone(self::timezone());
    }
}

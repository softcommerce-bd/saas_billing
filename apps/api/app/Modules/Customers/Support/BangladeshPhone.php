<?php

namespace App\Modules\Customers\Support;

/**
 * Normalises Bangladesh mobile numbers to E.164 (+8801XXXXXXXXX).
 *
 * Accepted inputs: 01712345678, 1712345678, 8801712345678, +8801712345678,
 * 008801712345678, with optional spaces, dashes, dots or parentheses.
 * Valid operator prefixes are 013-019.
 */
final class BangladeshPhone
{
    public static function normalizeMobile(?string $input): ?string
    {
        if ($input === null) {
            return null;
        }

        $digits = preg_replace('/[\s\-().]/', '', trim($input));
        if ($digits === '' || ! preg_match('/^\+?\d+$/', $digits)) {
            return null;
        }

        $digits = ltrim($digits, '+');
        if (str_starts_with($digits, '00880')) {
            $digits = substr($digits, 4);
        } elseif (str_starts_with($digits, '880')) {
            $digits = substr($digits, 3);
        }

        if (strlen($digits) === 10 && $digits[0] === '1') {
            $digits = '0'.$digits;
        }

        if (! preg_match('/^01[3-9]\d{8}$/', $digits)) {
            return null;
        }

        return '+88'.$digits;
    }

    /**
     * Local display format: 01712-345678.
     */
    public static function display(?string $e164): ?string
    {
        if ($e164 === null || ! str_starts_with($e164, '+880')) {
            return $e164;
        }

        $local = substr($e164, 3);

        return substr($local, 0, 5).'-'.substr($local, 5);
    }
}

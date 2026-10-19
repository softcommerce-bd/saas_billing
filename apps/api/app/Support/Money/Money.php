<?php

namespace App\Support\Money;

use InvalidArgumentException;

/**
 * Exact money handling in integer minor units (1 BDT = 100 poisha).
 * Never construct money from a float.
 */
final class Money
{
    /**
     * Parse a decimal string such as "1250", "1250.5" or "1,250.50" into minor units.
     */
    public static function parseToMinor(string $amount, int $scale = 2): int
    {
        $normalized = str_replace([',', ' '], '', trim($amount));

        if (! preg_match('/^(-)?(\d+)(?:\.(\d+))?$/', $normalized, $m)) {
            throw new InvalidArgumentException("Invalid money amount [{$amount}].");
        }

        $fraction = $m[3] ?? '';
        if (strlen($fraction) > $scale) {
            // Only allow extra digits when they are zeros ("500.000" is fine, "500.001" is not).
            if (trim(substr($fraction, $scale), '0') !== '') {
                throw new InvalidArgumentException("Amount [{$amount}] has more than {$scale} decimal places.");
            }
            $fraction = substr($fraction, 0, $scale);
        }

        $digits = ltrim($m[2].str_pad($fraction, $scale, '0'), '0');
        if (strlen($digits) > 17) {
            throw new InvalidArgumentException("Amount [{$amount}] is too large.");
        }

        $minor = (int) ($digits === '' ? '0' : $digits);

        return $m[1] === '-' ? -$minor : $minor;
    }

    /**
     * Format minor units as a plain decimal string ("125050" -> "1250.50").
     */
    public static function toDecimalString(int $minor, int $scale = 2): string
    {
        $sign = $minor < 0 ? '-' : '';
        $abs = (string) abs($minor);
        $abs = str_pad($abs, $scale + 1, '0', STR_PAD_LEFT);

        return $sign.substr($abs, 0, -$scale).'.'.substr($abs, -$scale);
    }
}

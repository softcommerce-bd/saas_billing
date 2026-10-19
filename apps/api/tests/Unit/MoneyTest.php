<?php

namespace Tests\Unit;

use App\Support\Money\Money;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class MoneyTest extends TestCase
{
    public static function validAmounts(): array
    {
        return [
            ['0', 0], ['1', 100], ['1.5', 150], ['1.05', 105], ['1,250.50', 125050],
            ['999999999.99', 99999999999], ['500.000', 50000], [' 12 ', 1200], ['-3.25', -325], ['0.01', 1],
        ];
    }

    #[DataProvider('validAmounts')]
    public function test_parses_decimal_strings_exactly(string $input, int $expected): void
    {
        $this->assertSame($expected, Money::parseToMinor($input));
    }

    public static function invalidAmounts(): array
    {
        return [['abc'], ['1.234'], ['1e5'], [''], ['1..2'], ['0x10'], ['1.2.3']];
    }

    #[DataProvider('invalidAmounts')]
    public function test_rejects_invalid_amounts(string $input): void
    {
        $this->expectException(InvalidArgumentException::class);
        Money::parseToMinor($input);
    }

    public function test_formats_minor_units(): void
    {
        $this->assertSame('1250.50', Money::toDecimalString(125050));
        $this->assertSame('0.05', Money::toDecimalString(5));
        $this->assertSame('0.00', Money::toDecimalString(0));
        $this->assertSame('-3.25', Money::toDecimalString(-325));
    }

    public function test_round_trip_has_no_floating_point_drift(): void
    {
        foreach ([1, 10, 29, 33, 101, 999, 123456789] as $minor) {
            $this->assertSame($minor, Money::parseToMinor(Money::toDecimalString($minor)));
        }
    }
}

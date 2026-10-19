<?php

namespace Tests\Unit;

use App\Modules\Customers\Support\BangladeshPhone;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class BangladeshPhoneTest extends TestCase
{
    public static function numbers(): array
    {
        return [
            ['01712345678', '+8801712345678'],
            ['1712345678', '+8801712345678'],
            ['8801712345678', '+8801712345678'],
            ['+8801712345678', '+8801712345678'],
            ['008801712345678', '+8801712345678'],
            ['017-1234-5678', '+8801712345678'],
            ['+880 1912 345678', '+8801912345678'],
            ['(013) 12345678', '+8801312345678'],
            ['01212345678', null],   // invalid operator prefix
            ['0171234567', null],    // too short
            ['017123456789', null],  // too long
            ['02-9876543', null],    // landline
            ['abc', null],
            ['', null],
        ];
    }

    #[DataProvider('numbers')]
    public function test_normalizes_mobile_numbers(string $input, ?string $expected): void
    {
        $this->assertSame($expected, BangladeshPhone::normalizeMobile($input));
    }

    public function test_display_format(): void
    {
        $this->assertSame('01712-345678', BangladeshPhone::display('+8801712345678'));
        $this->assertNull(BangladeshPhone::display(null));
    }
}

<?php

namespace Tests\Unit;

use App\Support\Money;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class MoneyTest extends TestCase
{
    public function test_input_is_converted_to_paisa_without_floating_point_loss(): void
    {
        $this->assertSame(12500050, Money::toPaisa('1,25,000.5'));
        $this->assertSame(1, Money::toPaisa('0.01'));
        $this->assertSame(29, Money::toPaisa('0.29'));
        $this->assertSame(1000, Money::toPaisa(' 10 '));
        $this->assertSame(12500000, Money::toPaisa('125,000'));
        $this->assertSame(1250000000, Money::toPaisa('1,25,00,000'));
        $this->assertSame(9999999999999, Money::toPaisa('99,99,99,99,999.99'));
        $this->assertSame('125000.50', Money::toInput(12500050));
        $this->assertSame('-0.50', Money::toInput(-50));
    }

    /** @return array<string, array{string}> */
    public static function invalidAmounts(): array
    {
        return ['negative' => ['-5'], 'three decimals' => ['1.005'], 'text' => ['12abc'], 'empty' => [''], 'exponent' => ['1e5'],
            'misplaced comma' => ['12,50'], 'leading comma' => [',500'], 'twelve digits' => ['100000000000'], 'grouped twelve digits' => ['1,00,00,00,00,000']];
    }

    #[DataProvider('invalidAmounts')]
    public function test_invalid_input_is_rejected(string $amount): void
    {
        $this->assertFalse(Money::isValidInput($amount));
        $this->expectException(InvalidArgumentException::class);
        Money::toPaisa($amount);
    }

    public function test_amounts_use_bangladeshi_lakh_and_crore_grouping(): void
    {
        $this->assertSame('৳0.00', Money::format(0));
        $this->assertSame('৳999.05', Money::format(99905));
        $this->assertSame('৳1,000.00', Money::format(100000));
        $this->assertSame('৳1,25,000.50', Money::format(12500050));
        $this->assertSame('৳1,23,45,678.90', Money::format(1234567890));
        $this->assertSame('-৳1,500.00', Money::format(-150000));
        $this->assertSame('1,500.00', Money::format(150000, false));
    }
}

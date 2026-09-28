<?php

namespace Tests\Unit;

use App\Support\DocumentMath;
use InvalidArgumentException;
use Tests\TestCase;

class DocumentMathTest extends TestCase
{
    private const MAX = 9_999_999_999_999;

    private function line(int $quantity, int $price, int $rate = 0, ?string $discountType = null, int $discountValue = 0): array
    {
        return ['quantity' => $quantity, 'unit_price' => $price, 'discount_type' => $discountType, 'discount_value' => $discountValue, 'tax_rate' => $rate];
    }

    public function test_mul_div_is_exact_beyond_the_integer_range(): void
    {
        $this->assertSame([9_999_999_999_999, 0], DocumentMath::mulDiv(9_999_999_999_999, 9_999_999_999_999, 9_999_999_999_999));
        $this->assertSame([3, 1], DocumentMath::mulDiv(10, 1, 3));
        $this->assertSame(4, DocumentMath::mulDivRound(7, 1, 2));
        $this->assertSame(3, DocumentMath::mulDivRound(10, 1, 3));
    }

    public function test_exclusive_vat_and_line_discounts(): void
    {
        // 2.5 × ৳100 = ৳250, 10% off = ৳225, 15% VAT = ৳33.75.
        $result = DocumentMath::calculate([$this->line(2500, 10000, 1500, DocumentMath::DISCOUNT_PERCENT, 1000)], null, 0, false, self::MAX);

        $this->assertSame([], $result['errors']);
        $this->assertSame(['amount' => 25000, 'discount' => 2500, 'net' => 22500, 'tax' => 3375, 'total' => 25875], $result['lines'][0]);
        $this->assertSame([25000, 2500, 3375, 25875], [$result['subtotal'], $result['discount_total'], $result['tax_total'], $result['total']]);
    }

    public function test_inclusive_vat_is_extracted_from_the_price(): void
    {
        $result = DocumentMath::calculate([$this->line(1000, 11500, 1500)], null, 0, true, self::MAX);

        $this->assertSame(['amount' => 11500, 'discount' => 0, 'net' => 10000, 'tax' => 1500, 'total' => 11500], $result['lines'][0]);
    }

    public function test_the_document_discount_is_split_exactly_across_lines_before_tax(): void
    {
        $lines = [$this->line(1000, 100, 1500), $this->line(1000, 100), $this->line(1000, 100)];
        $result = DocumentMath::calculate($lines, DocumentMath::DISCOUNT_AMOUNT, 100, false, self::MAX);

        $this->assertSame([34, 33, 33], array_column($result['lines'], 'discount'));
        $this->assertSame(100, $result['discount_total']);
        $this->assertSame(10, $result['tax_total']);
        $this->assertSame(210, $result['total']);
        $this->assertSame($result['total'], array_sum(array_column($result['lines'], 'net')) + $result['tax_total']);
    }

    public function test_discounts_larger_than_the_amount_and_oversized_lines_are_refused(): void
    {
        $result = DocumentMath::calculate([$this->line(1000, 100, 0, DocumentMath::DISCOUNT_AMOUNT, 101)], DocumentMath::DISCOUNT_AMOUNT, 500, false, self::MAX);
        $this->assertArrayHasKey('lines.0.discount_value', $result['errors']);
        $this->assertArrayHasKey('discount_value', $result['errors']);

        $huge = DocumentMath::calculate([$this->line(DocumentMath::MAX_QUANTITY, self::MAX)], null, 0, false, self::MAX);
        $this->assertArrayHasKey('lines.0.unit_price', $huge['errors']);
    }

    public function test_quantity_and_rate_parsing(): void
    {
        $this->assertSame(1500, DocumentMath::toMilli('1.5'));
        $this->assertSame('1.5', DocumentMath::formatMilli(1500));
        $this->assertSame('2', DocumentMath::formatMilli(2000));
        $this->assertSame(750, DocumentMath::toBasisPoints('7.5'));
        $this->assertSame('15', DocumentMath::formatBasisPoints(1500));
        $this->expectException(InvalidArgumentException::class);
        DocumentMath::toBasisPoints('101');
    }
}

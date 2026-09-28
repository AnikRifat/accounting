<?php

namespace App\Support;

use InvalidArgumentException;

/**
 * Integer arithmetic for document lines: quantities are thousandths (1.5 → 1500), money is paisa and rates and
 * percentages are basis points (15% → 1500). Every division rounds half up; nothing touches a float.
 *
 * Per line: amount = quantity × unit price; its own discount; then its share of the document discount (split by
 * largest remainder, so shares add up exactly). Tax is charged on what is left (exclusive) or extracted from it
 * (inclusive). Totals are sums of the rounded lines.
 */
final class DocumentMath
{
    public const DISCOUNT_AMOUNT = 'amount';

    public const DISCOUNT_PERCENT = 'percent';

    /** 99,999,999.999 units. */
    public const MAX_QUANTITY = 99_999_999_999;

    /** 100% in basis points. */
    public const FULL = 10_000;

    public const QUANTITY_PATTERN = '/^\d{1,8}(\.\d{1,3})?$/';

    public const RATE_PATTERN = '/^\d{1,3}(\.\d{1,2})?$/';

    /** "1.5" → 1500 thousandths. */
    public static function toMilli(string $quantity): int
    {
        return self::scaled($quantity, self::QUANTITY_PATTERN, 3);
    }

    /** "7.5" (percent) → 750 basis points. */
    public static function toBasisPoints(string $percent): int
    {
        $points = self::scaled($percent, self::RATE_PATTERN, 2);
        if ($points > self::FULL) {
            throw new InvalidArgumentException('A percentage can be at most 100.');
        }

        return $points;
    }

    /** 1500 → "1.5"; 2000 → "2". */
    public static function formatMilli(int $milli): string
    {
        return self::unscaled($milli, 3);
    }

    /** 750 → "7.5"; 1500 → "15". */
    public static function formatBasisPoints(int $points): string
    {
        return self::unscaled($points, 2);
    }

    /**
     * floor($a × $b ÷ $c) and its remainder for non-negative integers, exact even where $a × $b exceeds PHP_INT_MAX.
     *
     * @return array{0: int, 1: int}
     */
    public static function mulDiv(int $a, int $b, int $c): array
    {
        if ($a < 0 || $b < 0 || $c <= 0) {
            throw new InvalidArgumentException('mulDiv takes non-negative factors and a positive divisor.');
        }
        [$quotient, $remainder] = [0, 0];
        [$step, $stepRemainder] = [intdiv($a, $c), $a % $c];
        while ($b > 0) {
            if ($b & 1) {
                $quotient += $step;
                $remainder += $stepRemainder;
                if ($remainder >= $c) {
                    $remainder -= $c;
                    $quotient++;
                }
            }
            $b >>= 1;
            if ($b > 0) {
                $step *= 2;
                $stepRemainder *= 2;
                if ($stepRemainder >= $c) {
                    $stepRemainder -= $c;
                    $step++;
                }
            }
        }

        return [$quotient, $remainder];
    }

    /** round($a × $b ÷ $c), half up. */
    public static function mulDivRound(int $a, int $b, int $c): int
    {
        [$quotient, $remainder] = self::mulDiv($a, $b, $c);

        return $remainder >= $c - $remainder ? $quotient + 1 : $quotient;
    }

    /**
     * Prices every line and the document. Input lines hold quantity (thousandths), unit_price (paisa),
     * discount_type (null|amount|percent), discount_value (paisa or basis points) and tax_rate (basis points).
     * Errors are keyed by line index ("lines.2.discount_value") or "discount_value" for the document discount.
     *
     * @param  list<array{quantity: int, unit_price: int, discount_type: ?string, discount_value: int, tax_rate: int}>  $lines
     * @return array{lines: list<array{amount: int, discount: int, net: int, tax: int, total: int}>, subtotal: int, discount_total: int, tax_total: int, total: int, errors: array<string, string>}
     */
    public static function calculate(array $lines, ?string $discountType, int $discountValue, bool $taxInclusive, int $max): array
    {
        $errors = [];
        $priced = [];
        foreach (array_values($lines) as $index => $line) {
            $quantity = $line['quantity'];
            $price = $line['unit_price'];
            if ($price > 0 && $quantity > intdiv($max * 1000, $price)) {
                $errors["lines.{$index}.unit_price"] = __('This line is too large.');
                $priced[] = ['amount' => 0, 'own' => 0];

                continue;
            }
            $amount = self::mulDivRound($quantity, $price, 1000);
            $own = self::discount($line['discount_type'], $line['discount_value'], $amount);
            if ($own === null) {
                $errors["lines.{$index}.discount_value"] = __('The discount can\'t be more than the line amount.');
                $own = 0;
            }
            $priced[] = ['amount' => $amount, 'own' => $own];
        }
        $afterLines = array_map(fn (array $line): int => $line['amount'] - $line['own'], $priced);
        $base = array_sum($afterLines);
        $documentDiscount = self::discount($discountType, $discountValue, $base);
        if ($documentDiscount === null) {
            $errors['discount_value'] = __('The discount can\'t be more than the subtotal.');
            $documentDiscount = 0;
        }
        $shares = self::allocate($documentDiscount, $afterLines);

        $result = [];
        foreach ($priced as $index => $line) {
            $gross = $afterLines[$index] - $shares[$index];
            $rate = $lines[$index]['tax_rate'];
            if ($taxInclusive) {
                $tax = $rate > 0 ? self::mulDivRound($gross, $rate, self::FULL + $rate) : 0;
                [$net, $total] = [$gross - $tax, $gross];
            } else {
                $tax = self::mulDivRound($gross, $rate, self::FULL);
                [$net, $total] = [$gross, $gross + $tax];
            }
            $result[] = ['amount' => $line['amount'], 'discount' => $line['own'] + $shares[$index], 'net' => $net, 'tax' => $tax, 'total' => $total];
        }
        $totals = [
            'subtotal' => array_sum(array_column($result, 'amount')),
            'discount_total' => array_sum(array_column($result, 'discount')),
            'tax_total' => array_sum(array_column($result, 'tax')),
            'total' => array_sum(array_column($result, 'total')),
        ];
        if ($totals['subtotal'] > $max || $totals['total'] > $max) {
            $errors['lines'] = __('The document total is too large.');
        }

        return ['lines' => $result, ...$totals, 'errors' => $errors];
    }

    /**
     * Splits $amount across $weights in proportion, by largest remainder, so the parts add up to $amount exactly.
     *
     * @param  list<int>  $weights
     * @return list<int>
     */
    public static function allocate(int $amount, array $weights): array
    {
        $total = array_sum($weights);
        if ($amount === 0 || $total === 0) {
            return array_fill(0, count($weights), 0);
        }
        $parts = [];
        $remainders = [];
        foreach ($weights as $index => $weight) {
            [$parts[$index], $remainders[$index]] = self::mulDiv($amount, $weight, $total);
        }
        $left = $amount - array_sum($parts);
        arsort($remainders);
        foreach (array_slice(array_keys($remainders), 0, $left) as $index) {
            $parts[$index]++;
        }
        ksort($parts);

        return array_values($parts);
    }

    /** A discount in paisa, or null when a fixed discount is more than the amount it applies to. */
    private static function discount(?string $type, int $value, int $amount): ?int
    {
        return match ($type) {
            self::DISCOUNT_PERCENT => self::mulDivRound($amount, min($value, self::FULL), self::FULL),
            self::DISCOUNT_AMOUNT => $value <= $amount ? $value : null,
            default => 0,
        };
    }

    private static function scaled(string $value, string $pattern, int $decimals): int
    {
        $value = trim($value);
        if (preg_match($pattern, $value) !== 1) {
            throw new InvalidArgumentException("Invalid number [{$value}].");
        }
        [$whole, $fraction] = array_pad(explode('.', $value), 2, '');

        return (int) $whole * 10 ** $decimals + (int) str_pad($fraction, $decimals, '0');
    }

    private static function unscaled(int $value, int $decimals): string
    {
        $unit = 10 ** $decimals;
        $fraction = rtrim(str_pad((string) ($value % $unit), $decimals, '0', STR_PAD_LEFT), '0');

        return intdiv($value, $unit).($fraction === '' ? '' : '.'.$fraction);
    }
}

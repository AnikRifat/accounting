<?php

namespace App\Support;

use InvalidArgumentException;

/**
 * BDT amounts are stored as integer paisa (1 taka = 100 paisa); never use floats for money.
 */
class Money
{
    /**
     * A non-negative taka amount of at most 11 digits with up to two decimals. Commas are only
     * accepted as Bangladeshi (1,25,000) or international (125,000) grouping separators.
     */
    public const INPUT_PATTERN = '/^(\d{1,11}|\d{1,3}(,\d{3})+|\d{1,2}(,\d{2})*,\d{3})(\.\d{1,2})?$/';

    public static function isValidInput(string $amount): bool
    {
        return preg_match(self::INPUT_PATTERN, trim($amount)) === 1
            && strlen(strtok(str_replace(',', '', trim($amount)), '.')) <= 11;
    }

    /** Converts user input such as "1,25,000.5" to paisa (12500050). */
    public static function toPaisa(string $amount): int
    {
        if (! self::isValidInput($amount)) {
            throw new InvalidArgumentException('Invalid amount ['.trim($amount).'].');
        }
        [$taka, $paisa] = array_pad(explode('.', str_replace(',', '', trim($amount))), 2, '');

        return (int) $taka * 100 + (int) str_pad($paisa, 2, '0');
    }

    /** Converts paisa to a plain input value, e.g. 12500050 → "125000.50". */
    public static function toInput(int $paisa): string
    {
        return ($paisa < 0 ? '-' : '').intdiv(abs($paisa), 100).'.'.str_pad((string) (abs($paisa) % 100), 2, '0', STR_PAD_LEFT);
    }

    /** Formats paisa with Bangladeshi lakh/crore grouping, e.g. 12500050 → "৳1,25,000.50". */
    public static function format(int $paisa, bool $symbol = true): string
    {
        $digits = (string) intdiv(abs($paisa), 100);
        $grouped = strlen($digits) > 3
            ? preg_replace('/\B(?=(\d{2})+$)/', ',', substr($digits, 0, -3)).','.substr($digits, -3)
            : $digits;

        return ($paisa < 0 ? '-' : '').($symbol ? '৳' : '').$grouped.'.'.str_pad((string) (abs($paisa) % 100), 2, '0', STR_PAD_LEFT);
    }
}

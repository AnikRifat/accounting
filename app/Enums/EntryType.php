<?php

namespace App\Enums;

enum EntryType: string
{
    case Income = 'income';
    case Expense = 'expense';
    case Transfer = 'transfer';
    case Opening = 'opening';
    case Receipt = 'receipt';
    case Payment = 'payment';

    public function label(): string
    {
        return match ($this) {
            self::Income => __('Income'),
            self::Expense => __('Expense'),
            self::Transfer => __('Transfer'),
            self::Opening => __('Opening balance'),
            self::Receipt => __('Receipt'),
            self::Payment => __('Payment'),
        };
    }

    /** Income and expense entries are bills: their unpaid part is settled by receipts or payments. */
    public function isBill(): bool
    {
        return $this === self::Income || $this === self::Expense;
    }

    public function isSettlement(): bool
    {
        return $this === self::Receipt || $this === self::Payment;
    }

    /** @return list<self> Types that staff record through the entry form. */
    public static function recordable(): array
    {
        return [self::Income, self::Expense, self::Transfer];
    }
}

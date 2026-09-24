<?php

namespace App\Enums;

/** Payment status of a posted income or expense entry, relative to today (Asia/Dhaka). */
enum DueStatus: string
{
    case Paid = 'paid';
    case PartlyPaid = 'partly_paid';
    case Due = 'due';
    case Overdue = 'overdue';

    public function label(): string
    {
        return match ($this) {
            self::Paid => __('Paid'),
            self::PartlyPaid => __('Partly paid'),
            self::Due => __('Due'),
            self::Overdue => __('Overdue'),
        };
    }
}

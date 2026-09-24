<?php

namespace App\Enums;

/** The kind of a payment method (an `is_cash` account). */
enum PaymentType: string
{
    case Cash = 'cash';
    case Bank = 'bank';
    case MobileBanking = 'mobile_banking';
    case Card = 'card';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Cash => __('Cash'),
            self::Bank => __('Bank'),
            self::MobileBanking => __('Mobile banking'),
            self::Card => __('Card'),
            self::Other => __('Other'),
        };
    }
}

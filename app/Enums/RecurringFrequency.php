<?php

namespace App\Enums;

use Carbon\CarbonImmutable;

enum RecurringFrequency: string
{
    case Weekly = 'weekly';
    case Monthly = 'monthly';
    case Quarterly = 'quarterly';
    case Yearly = 'yearly';

    public function label(): string
    {
        return match ($this) {
            self::Weekly => __('Weekly'),
            self::Monthly => __('Monthly'),
            self::Quarterly => __('Every 3 months'),
            self::Yearly => __('Yearly'),
        };
    }

    /** The run date after $date. Month steps keep the schedule's day, clamped to short months (31 Jan → 28 Feb → 31 Mar). */
    public function next(CarbonImmutable $date, int $day): CarbonImmutable
    {
        if ($this === self::Weekly) {
            return $date->addWeek();
        }
        $month = $date->startOfMonth()->addMonthsNoOverflow(match ($this) {
            self::Monthly => 1,
            self::Quarterly => 3,
            default => 12,
        });

        return $month->setDay(min($day, $month->daysInMonth));
    }
}

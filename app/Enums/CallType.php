<?php

namespace App\Enums;

/** How a lead was contacted. */
enum CallType: string
{
    case Call = 'call';
    case Visit = 'visit';

    public function label(): string
    {
        return match ($this) {
            self::Call => __('Phone call'),
            self::Visit => __('Visit'),
        };
    }
}

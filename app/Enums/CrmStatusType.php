<?php

namespace App\Enums;

/** What a CRM status describes: where a lead stands, or how one call went. */
enum CrmStatusType: string
{
    case Lead = 'lead';
    case Call = 'call';

    public function label(): string
    {
        return match ($this) {
            self::Lead => __('Lead status'),
            self::Call => __('Call result'),
        };
    }
}

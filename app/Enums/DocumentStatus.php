<?php

namespace App\Enums;

/**
 * Lifecycle of a document. Payment progress (paid, partly paid, overdue) is never stored: it is derived from
 * the ledger for a posted document and from its recorded payments otherwise.
 */
enum DocumentStatus: string
{
    case Draft = 'draft';
    case Issued = 'issued';
    case Accepted = 'accepted';
    case Declined = 'declined';
    case Converted = 'converted';
    case Void = 'void';

    public function label(): string
    {
        return match ($this) {
            self::Draft => __('Draft'),
            self::Issued => __('Issued'),
            self::Accepted => __('Accepted'),
            self::Declined => __('Declined'),
            self::Converted => __('Converted'),
            self::Void => __('Void'),
        };
    }

    public function tone(): string
    {
        return match ($this) {
            self::Draft => 'neutral',
            self::Issued => 'info',
            self::Accepted, self::Converted => 'success',
            self::Declined, self::Void => 'danger',
        };
    }
}

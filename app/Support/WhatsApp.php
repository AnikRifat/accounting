<?php

namespace App\Support;

/**
 * Builds wa.me click-to-chat links (no WhatsApp API). Bangladeshi mobile numbers in any common form
 * (01711-000000, +880 1711 000000, 8801711000000) become 8801XXXXXXXXX; other numbers keep their digits.
 */
final class WhatsApp
{
    /** A wa.me link to the number with the text prefilled; without a usable number the user picks the chat. */
    public static function link(?string $phone, string $text): string
    {
        $number = self::normalize($phone);

        return 'https://wa.me/'.($number ?? '').'?text='.rawurlencode($text);
    }

    /** The number in international form without "+", or null when it is empty or not a phone number. */
    public static function normalize(?string $phone): ?string
    {
        $digits = preg_replace('/\D/', '', (string) $phone) ?? '';
        if (str_starts_with($digits, '00')) {
            $digits = substr($digits, 2);
        }
        if (preg_match('/^(?:880|0)?(1[3-9]\d{8})$/', $digits, $match) === 1) {
            return '880'.$match[1];
        }

        return strlen($digits) >= 8 && strlen($digits) <= 15 ? $digits : null;
    }
}

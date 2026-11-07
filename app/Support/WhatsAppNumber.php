<?php

namespace App\Support;

/**
 * A Nigerian mobile number in the one shape the free plan stores and links
 * to: 234 followed by ten digits, as wa.me wants it.
 *
 * Stricter than App\Services\Messaging\PhoneNumber, which turns anything it
 * does not recognise into bare digits so its existing callers never throw.
 * A signup form should instead refuse a number it cannot dial.
 */
final class WhatsAppNumber
{
    /** 080…, 81…, +234…, 234… → 234XXXXXXXXXX, or null when it is not a valid mobile number. */
    public static function normalise(?string $raw): ?string
    {
        $digits = preg_replace('/\D/', '', (string) $raw);

        if (str_starts_with($digits, '234')) {
            $local = substr($digits, 3);
            // "+234 0803…" — a trunk zero typed after the country code.
            if (strlen($local) === 11 && $local[0] === '0') {
                $local = substr($local, 1);
            }
        } elseif (strlen($digits) === 11 && $digits[0] === '0') {
            $local = substr($digits, 1);
        } else {
            $local = $digits;
        }

        // Nigerian mobile numbers: ten digits after the country code, the
        // first of them 7, 8 or 9 (070…, 080…, 081…, 090…, 091…).
        if (strlen($local) !== 10 || ! in_array($local[0], ['7', '8', '9'], true)) {
            return null;
        }

        return '234'.$local;
    }

    /** "0803 123 4567" — how the owner would write it themselves. */
    public static function local(?string $number): string
    {
        $normalised = self::normalise($number);

        if ($normalised === null) {
            return (string) $number;
        }

        $local = '0'.substr($normalised, 3);

        return substr($local, 0, 4).' '.substr($local, 4, 3).' '.substr($local, 7);
    }

    /** A wa.me link, with the message filled in when one is given. */
    public static function link(?string $number, ?string $text = null): string
    {
        $digits = self::normalise($number) ?? preg_replace('/\D/', '', (string) $number);

        return 'https://wa.me/'.$digits.($text !== null ? '?text='.rawurlencode($text) : '');
    }
}

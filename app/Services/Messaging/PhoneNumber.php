<?php

namespace App\Services\Messaging;

// Normalizes a phone number to digits-only Nigerian international format
// (234XXXXXXXXXX — no '+', no leading trunk '0') for WhatsApp/SMS APIs.
// Customers and riders enter numbers in local format (e.g. 08012345678);
// Meta's Cloud API and wa.me links both require the country code, so a
// bare digit-strip alone (the previous behavior) silently produced an
// undeliverable number. Business operates in Nigeria only right now —
// revisit if that changes.
class PhoneNumber
{
    private const COUNTRY_CODE = '234';

    public static function toNigerianInternational(?string $raw): ?string
    {
        if ($raw === null) {
            return null;
        }

        $digits = preg_replace('/\D/', '', $raw);

        if ($digits === '') {
            return $digits;
        }

        if (str_starts_with($digits, self::COUNTRY_CODE)) {
            return $digits;
        }

        if (str_starts_with($digits, '0')) {
            return self::COUNTRY_CODE.substr($digits, 1);
        }

        // No country code, no local trunk prefix — e.g. already a bare
        // 10-digit subscriber number (8012345678).
        if (strlen($digits) === 10) {
            return self::COUNTRY_CODE.$digits;
        }

        // Doesn't match a known Nigerian shape — return the stripped digits
        // as-is rather than guessing further; better than throwing on a
        // path with several existing callers.
        return $digits;
    }
}

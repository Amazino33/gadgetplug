<?php

namespace App\Services\Meta;

use App\Services\Messaging\PhoneNumber;

// SHA256 normalization for Meta CAPI's user_data fields (em/ph/fn/ln/ct).
// Meta's own spec: lowercase, trim, then hash — phone additionally needs the
// full E.164 digit string (country code, no '+', no leading trunk '0')
// first, which is exactly what PhoneNumber::toNigerianInternational() already
// produces for the WhatsApp/SMS senders — reused here rather than
// re-implementing Nigerian number normalization a second time.
class MetaEventHasher
{
    public function hashEmail(?string $email): ?string
    {
        return $this->hash($email);
    }

    public function hashPhone(?string $phone): ?string
    {
        return $this->hash(PhoneNumber::toNigerianInternational($phone));
    }

    public function hashFirstName(?string $fullName): ?string
    {
        return $this->hash($this->splitName($fullName)['first']);
    }

    public function hashLastName(?string $fullName): ?string
    {
        return $this->hash($this->splitName($fullName)['last']);
    }

    /**
     * There's no explicit "first name"/"last name" field anywhere in this
     * app — customers give one full name string at checkout. Meta's own
     * matching degrades gracefully on a best-effort split (first word vs.
     * the rest), same as most CAPI integrations do without a real
     * first/last-name capture.
     *
     * @return array{first: ?string, last: ?string}
     */
    public function splitName(?string $fullName): array
    {
        $fullName = trim((string) $fullName);

        if ($fullName === '') {
            return ['first' => null, 'last' => null];
        }

        $parts = preg_split('/\s+/', $fullName, 2);

        return [
            'first' => $parts[0] ?? null,
            'last'  => $parts[1] ?? null,
        ];
    }

    public function hashCity(?string $city): ?string
    {
        return $this->hash($city);
    }

    private function hash(?string $value): ?string
    {
        $value = trim((string) $value);

        if ($value === '') {
            return null;
        }

        return hash('sha256', mb_strtolower($value));
    }
}

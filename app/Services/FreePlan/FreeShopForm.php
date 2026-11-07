<?php

namespace App\Services\FreePlan;

use App\Models\Vendor;
use App\Support\NigerianStates;
use App\Support\ShopSlug;
use App\Support\WhatsAppNumber;
use Closure;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\File;

/**
 * The fields the signup form and the edit-application form share, and their
 * rules. The signup adds the account fields (owner, email, password, terms)
 * on top; the edit form adds the owner's name only.
 */
final class FreeShopForm
{
    /** The field no person can see. */
    public const HONEYPOT = 'company_website';

    /** Step 1's fields, so the form can reopen on the step that has an error. */
    public const STEP_ONE = [
        'business_name', 'owner_name', 'whatsapp', 'email', 'password',
        'password_confirmation', 'category', 'state', 'city_area',
    ];

    private const SOCIAL_HOSTS = ['instagram.com', 'facebook.com', 'fb.com', 'tiktok.com'];

    /**
     * Normalises what can be normalised before validation, so the rules see
     * the shape that will be stored: the number as 234…, the slug lowercase.
     * A number that cannot be normalised is left as typed for the rule to
     * reject.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public static function prepare(array $input): array
    {
        foreach (['business_name', 'owner_name', 'email', 'city_area', 'description', 'social_link'] as $field) {
            if (isset($input[$field]) && is_string($input[$field])) {
                $input[$field] = trim($input[$field]);
            }
        }

        $input['slug'] = strtolower(trim((string) ($input['slug'] ?? '')));

        if (filled($input['whatsapp'] ?? null)) {
            $input['whatsapp'] = WhatsAppNumber::normalise((string) $input['whatsapp']) ?? (string) $input['whatsapp'];
        }

        foreach (['email', 'description', 'social_link'] as $field) {
            if (($input[$field] ?? null) === '') {
                $input[$field] = null;
            }
        }

        return $input;
    }

    /**
     * @param  Vendor|null  $vendor  the shop editing its own application, if any
     * @return array<string, mixed>
     */
    public static function rules(?Vendor $vendor = null): array
    {
        $image = File::types(['jpg', 'jpeg', 'png', 'webp'])->max(5 * 1024);

        // An edit may keep the proof already on file instead of sending it again.
        $hasPhotoOnFile = $vendor && filled($vendor->verification_photo);

        return [
            'business_name' => ['required', 'string', 'max:100'],
            'whatsapp'      => ['required', 'string', function (string $attribute, $value, Closure $fail) use ($vendor) {
                if (! preg_match('/^234\d{10}$/', (string) $value)) {
                    $fail('Enter a valid Nigerian WhatsApp number, e.g. 0803 123 4567.');
                } elseif (FreeShopApplications::whatsAppInUse((string) $value, $vendor?->id)) {
                    $fail('This WhatsApp number is already used by another shop.');
                }
            }],
            'category'      => ['required', Rule::in(Vendor::BUSINESS_CATEGORIES)],
            'state'         => ['required', Rule::in(NigerianStates::ALL)],
            'city_area'     => ['required', 'string', 'max:100'],
            'slug'          => ['required', 'string', function (string $attribute, $value, Closure $fail) use ($vendor) {
                if ($problem = ShopSlug::problem((string) $value, $vendor?->id)) {
                    $fail($problem);
                }
            }],
            'description'   => ['nullable', 'string', 'max:200'],
            'logo'          => ['nullable', $image],
            'verification_photo' => $hasPhotoOnFile
                ? ['nullable', $image]
                : ['nullable', 'required_without:social_link', $image],
            'social_link'   => $hasPhotoOnFile
                ? ['nullable', 'url', 'max:255', self::socialRule()]
                : ['nullable', 'required_without:verification_photo', 'url', 'max:255', self::socialRule()],
        ];
    }

    /** @return array<string, string> */
    public static function messages(): array
    {
        return [
            'verification_photo.required_without' => 'Add a photo of your shop or a link to your Instagram, Facebook or TikTok page.',
            'social_link.required_without'        => 'Add a link to your Instagram, Facebook or TikTok page, or a photo of your shop.',
            'terms.accepted'                      => 'Please agree to the terms to continue.',
        ];
    }

    /** @return array<string, string> */
    public static function attributes(): array
    {
        return [
            'business_name'      => 'business name',
            'owner_name'         => 'your full name',
            'whatsapp'           => 'WhatsApp number',
            'city_area'          => 'city / area',
            'slug'               => 'shop link',
            'verification_photo' => 'shop photo',
            'social_link'        => 'social media link',
        ];
    }

    private static function socialRule(): Closure
    {
        return function (string $attribute, $value, Closure $fail) {
            $host = strtolower((string) parse_url((string) $value, PHP_URL_HOST));

            $ok = collect(self::SOCIAL_HOSTS)->contains(
                fn (string $allowed) => $host === $allowed || str_ends_with($host, '.'.$allowed)
            );

            if (! $ok) {
                $fail('Use a link to your Instagram, Facebook or TikTok page.');
            }
        };
    }
}

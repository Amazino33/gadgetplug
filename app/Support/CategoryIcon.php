<?php

namespace App\Support;

// Maps a category to an icon in resources/views/components/icon.blade.php.
//
// Matched on keywords rather than exact names because categories are free text
// a vendor can rename at any time — "Audio", "Audio & Headphones" and
// "Headphones & Speakers" should all land on the same icon without anyone
// having to keep a lookup table in sync with the database.
class CategoryIcon
{
    /**
     * Checked in order, so longer and more specific keywords must come first.
     *
     * "headphone" in particular has to be tested before "phone", or every
     * "Audio & Headphones" category matches the phone icon — it contains the
     * word. Same reasoning puts "smartwatch" ahead of "watch".
     */
    private const KEYWORDS = [
        'headphone'  => 'headphones',
        'earphone'   => 'headphones',
        'audio'      => 'headphones',
        'speaker'    => 'headphones',
        'earbud'     => 'headphones',
        'sound'      => 'headphones',
        'smartphone' => 'phone',
        'phone'      => 'phone',
        'mobile'     => 'phone',
        'tablet'     => 'tablet',
        'ipad'       => 'tablet',
        'laptop'     => 'laptop',
        'computer'   => 'laptop',
        'notebook'   => 'laptop',
        'pc'         => 'laptop',
        'smartwatch' => 'watch',
        'watch'      => 'watch',
        'wearable'   => 'watch',
        'console'    => 'gamepad',
        'gaming'     => 'gamepad',
        'game'       => 'gamepad',
        'camera'     => 'camera',
        'photo'      => 'camera',
        'cable'      => 'cable',
        'charger'    => 'cable',
        'accessor'   => 'cable',
        'peripheral' => 'cable',
    ];

    public static function for(?string $categoryName): string
    {
        $name = strtolower(trim((string) $categoryName));

        if ($name === '') {
            return 'package';
        }

        foreach (self::KEYWORDS as $keyword => $icon) {
            if (str_contains($name, $keyword)) {
                return $icon;
            }
        }

        return 'package';
    }
}

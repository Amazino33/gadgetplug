<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

// Single row (id = 1 always), mirroring AffiliateSetting and
// PlatformMessagingSetting: the free plan's limits and where new applications
// are announced.
class FreePlanSetting extends Model
{
    protected $guarded = [];

    protected $casts = [
        'free_plan_product_limit'      => 'integer',
        'free_plan_photos_per_product' => 'integer',
    ];

    public static function current(): self
    {
        // firstOrCreate rather than find: a database restored from before this
        // feature would otherwise have no row and every limit check would throw.
        return static::firstOrCreate(['id' => 1], [
            'free_plan_product_limit'      => 50,
            'free_plan_photos_per_product' => 3,
        ]);
    }

    public static function productLimit(): int
    {
        return max(0, (int) static::current()->free_plan_product_limit);
    }

    public static function photosPerProduct(): int
    {
        return max(1, (int) static::current()->free_plan_photos_per_product);
    }

    /** Null when nobody has been set up to hear about applications. */
    public static function adminEmail(): ?string
    {
        $email = trim((string) static::current()->admin_notification_email);

        return $email !== '' ? $email : null;
    }
}

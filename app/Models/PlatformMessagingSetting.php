<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

// Single row (id = 1 always), mirroring AffiliateSetting. Holds the settings
// GadgetPlug owns rather than individual vendors.
class PlatformMessagingSetting extends Model
{
    protected $guarded = [];

    public static function current(): self
    {
        // firstOrCreate rather than findOrFail: the migration seeds the row, but
        // a database restored from before this feature would otherwise throw on
        // every online order rather than simply having no fallback configured.
        return static::firstOrCreate(['id' => 1]);
    }

    public function hasFallbackStorekeeperNumber(): bool
    {
        return filled($this->fallback_storekeeper_whatsapp);
    }
}

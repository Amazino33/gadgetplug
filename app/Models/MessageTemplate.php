<?php

namespace App\Models;

use Database\Seeders\MessageTemplateSeeder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MessageTemplate extends Model
{
    protected $guarded = [];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    /**
     * Templates GadgetPlug owns rather than the vendor, while the platform is
     * handling online orders.
     *
     * Both are promises the platform makes about an online order — that the
     * store is told to pack it, and that the buyer is told it was received. A
     * vendor deactivating either would break that promise silently, from the
     * buyer's side indistinguishably from the system being broken.
     *
     * Enforced where the messages are sent, not only in the UI: the resource
     * hides the edit controls, but send-time is what actually guarantees it.
     */
    public const PLATFORM_LOCKED_KEYS = [
        'storekeeper_new_order',
        'customer_received',
    ];

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }

    public static function isPlatformLocked(string $key): bool
    {
        return in_array($key, self::PLATFORM_LOCKED_KEYS, true);
    }

    public function isLocked(): bool
    {
        return self::isPlatformLocked((string) $this->key);
    }

    /**
     * The template to send for a key, for a vendor.
     *
     * A locked key never returns null: it ignores is_active, and falls back to
     * the shipped default when the vendor has no row at all. That last part
     * matters — MessageTemplateSeeder uses firstOrCreate, so a vendor onboarded
     * after a template was added has no row for it until someone remembers to
     * run messages:sync-templates. Until now that produced a message that
     * silently never sent.
     *
     * The fallback is deliberately not persisted: writing rows at send time
     * would race with the seeder and quietly recreate templates a vendor is
     * entitled to delete once the key is unlocked.
     */
    public static function resolveFor(int $vendorId, string $key): ?self
    {
        $locked = self::isPlatformLocked($key);

        $template = static::query()
            ->where('vendor_id', $vendorId)
            ->where('key', $key)
            ->when(! $locked, fn ($query) => $query->where('is_active', true))
            ->first();

        if ($template) {
            return $template;
        }

        if (! $locked) {
            return null;
        }

        $default = collect(MessageTemplateSeeder::defaults())->firstWhere('key', $key);

        if (! $default) {
            return null;
        }

        return new static([
            'vendor_id'      => $vendorId,
            'key'            => $default['key'],
            'recipient_type' => $default['recipient_type'],
            'channel'        => $default['channel'],
            'body'           => $default['body'],
            'is_active'      => true,
        ]);
    }
}

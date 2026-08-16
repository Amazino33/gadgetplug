<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Collection;
use App\Models\VendorPayout;
use Spatie\Sluggable\HasSlug;
use Spatie\Sluggable\SlugOptions;

class Vendor extends Model
{
    use HasFactory, HasSlug;

    protected $fillable = [
        'user_id', 'name', 'slug', 'logo', 'is_verified',
        'description', 'whatsapp', 'bank_name', 'account_number', 'account_name',
        'pos_vat_enabled', 'pos_vat_rate', 'pos_blind_count_participants',
        'pos_blind_count_frequency', 'pos_blind_count_custom_days', 'owner_can_manage_roles',
        'pos_min_margin_percent', 'online_sales_enabled', 'initial_capital',
        'restock_window_days', 'restock_lead_time_days', 'restock_target_cover_days', 'restock_safety_buffer_days',
    ];

    protected $casts = [
        'pos_min_margin_percent'     => 'decimal:2',
        'online_sales_enabled'      => 'boolean',
        'initial_capital'           => 'decimal:2',
        'restock_window_days'       => 'integer',
        'restock_lead_time_days'    => 'integer',
        'restock_target_cover_days' => 'integer',
        'restock_safety_buffer_days' => 'integer',
    ];

    // Single place both RestockReport and the reports-hub Restock card resolve
    // "what settings does this vendor actually use" — each column is nullable
    // and falls back to ProductVelocityService's own defaults, so there is
    // exactly one place either fallback set lives.
    /**
     * @return array{windowDays: int, leadTimeDays: int, targetCoverDays: int, safetyBufferDays: ?int}
     */
    public function restockSettings(): array
    {
        return [
            'windowDays'       => $this->restock_window_days ?? 30,
            'leadTimeDays'     => $this->restock_lead_time_days ?? 5,
            'targetCoverDays'  => $this->restock_target_cover_days ?? 30,
            'safetyBufferDays' => $this->restock_safety_buffer_days,
        ];
    }

    public function getSlugOptions(): SlugOptions
    {
        return SlugOptions::create()
            ->generateSlugsFrom('name')
            ->saveSlugsTo('slug')
            ->slugsShouldBeNoLongerThan(80)
            ->doNotGenerateSlugsOnUpdate(); // preserve existing slugs unless name changes via StoreProfile
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    // Original owner
    public function user()
    {
        return $this->belongsTo(User::class);
    }

    // All team members
    public function users()
    {
        return $this->belongsToMany(User::class, 'vendor_users')
            ->withTimestamps();
    }

    public function products()
    {
        return $this->hasMany(Product::class);
    }

    public function stores()
    {
        return $this->hasMany(Store::class);
    }

    // The one store a vendor falls back to when nothing else has been chosen —
    // every vendor has exactly one, guaranteed by the backfill's assertion.
    // A hasOne rather than a lookup method so it can be eager-loaded alongside
    // the other vendor relations instead of firing a query per vendor.
    public function defaultStore()
    {
        return $this->hasOne(Store::class)->where('is_default', true);
    }

    public function logisticsCompanies()
    {
        return $this->hasMany(LogisticsCompany::class);
    }

    public function deliveryPersons()
    {
        return $this->hasMany(DeliveryPerson::class);
    }

    public function messageTemplates()
    {
        return $this->hasMany(MessageTemplate::class);
    }

    public function payouts()
    {
        return $this->hasMany(VendorPayout::class);
    }

    public function isOwner(User $user): bool
    {
        return $this->user_id === $user->id;
    }

    public function canSellOnline(): bool
    {
        return (bool) $this->online_sales_enabled;
    }

    public function canAccess(User $user): bool
    {
        return $user->hasRole('super_admin')
            || $this->isOwner($user)
            || $this->users()->where('user_id', $user->id)->exists();
    }

    public function canManage(User $user): bool
    {
        return $user->hasRole('super_admin') || $this->isOwner($user);
    }

    public static function getTenantsForUser(\App\Models\User $user): Collection
    {
        if ($user->hasRole('super_admin')) {
            return static::all();
        }

        // Vendors the user owns
        $owned = static::where('user_id', $user->id)->get();

        // Vendors the user is a team member of
        $member = $user->vendors();

        return $owned->merge($member)->unique('id');
    }

    public function hasOtherApprovers(int $excludeUserId): bool
    {
        $allUsers = collect([$this->user])->merge($this->users);
        return $allUsers->where('id', '!=', $excludeUserId)
                 ->contains(fn (User $user) => $user->hasVendorPermission($this->id, 'approve_procurement'));
    }
}
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
        'description', 'whatsapp', 'city', 'state', 'bank_name', 'account_number', 'account_name',
        'pos_vat_enabled', 'pos_vat_rate', 'pos_blind_count_participants',
        'pos_blind_count_frequency', 'pos_blind_count_custom_days', 'owner_can_manage_roles',
        'pos_min_margin_percent', 'online_sales_enabled', 'initial_capital',
        'dashboard_blocked', 'dashboard_blocked_reason', 'dashboard_blocked_at',
        'restock_window_days', 'restock_lead_time_days', 'restock_target_cover_days', 'restock_safety_buffer_days',
    ];

    protected $casts = [
        'pos_min_margin_percent'     => 'decimal:2',
        'online_sales_enabled'      => 'boolean',
        'dashboard_blocked'         => 'boolean',
        'dashboard_blocked_at'      => 'datetime',
        'initial_capital'           => 'decimal:2',
        'restock_window_days'       => 'integer',
        'restock_lead_time_days'    => 'integer',
        'restock_target_cover_days' => 'integer',
        'restock_safety_buffer_days' => 'integer',
    ];

    /**
     * "City, State" — or whichever half of it the store has filled in.
     *
     * Null when neither is set, so a caller can hide the whole line rather
     * than print a dangling separator. Most stores have set nothing, which is
     * the case this is written around, not an edge case.
     */
    public function getLocationAttribute(): ?string
    {
        $parts = array_filter([trim((string) $this->city), trim((string) $this->state)]);

        return $parts ? implode(', ', $parts) : null;
    }

    /**
     * The store's logo as a URL, matching how the receipts already resolve it.
     *
     * Note that nothing currently uploads to `vendors.logo` — there is no field
     * for it on StoreProfile — so this is null for every store today and the
     * initials chip is what shows. It is wired now so a logo appears the moment
     * one can be set.
     */
    public function getLogoUrlAttribute(): ?string
    {
        return $this->logo ? asset('storage/'.$this->logo) : null;
    }

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

    public function receiptSetting()
    {
        return $this->hasOne(VendorReceiptSetting::class);
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

    /**
     * Account-level block, used when a vendor owes the platform or has broken
     * the terms: no panel, no till, until an admin lifts it.
     *
     * Super admins are exempt everywhere this is checked — support has to be
     * able to look inside the account they just blocked, and locking ourselves
     * out would only make the debt harder to settle.
     */
    public function isDashboardBlocked(): bool
    {
        return (bool) $this->dashboard_blocked;
    }

    /**
     * What the blocked vendor is told. The admin's own wording when they gave
     * one, because "why am I locked out" is otherwise a support ticket every
     * single time.
     */
    public function dashboardBlockMessage(): string
    {
        $reason = trim((string) $this->dashboard_blocked_reason);

        return $reason !== ''
            ? $reason
            : 'Your account has been suspended pending a payment or terms review.';
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
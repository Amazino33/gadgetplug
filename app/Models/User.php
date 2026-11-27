<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Filament\Models\Contracts\FilamentUser;
use Filament\Models\Contracts\HasTenants;
use Filament\Panel;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Fortify\TwoFactorAuthenticatable;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable implements FilamentUser, HasTenants
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, HasRoles, Notifiable, TwoFactorAuthenticatable;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'phone',
        'address',
        'pos_pin',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'two_factor_secret',
        'two_factor_recovery_codes',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    /**
     * Get the user's initials
     */
    // Checks for the super_admin role ignoring the Spatie team scope entirely.
    // hasRole() and roles() both filter by team_id when teams are enabled, and
    // VendorTeamResolver always falls back to filament()->getTenant() even after
    // setPermissionsTeamId(null), so the only reliable way is a raw DB check.
    public function isSuperAdmin(): bool
    {
        return DB::table('model_has_roles')
            ->join('roles', 'roles.id', '=', 'model_has_roles.role_id')
            ->where('model_has_roles.model_id', $this->id)
            ->where('model_has_roles.model_type', get_class($this))
            ->whereNull('model_has_roles.team_id')
            ->where('roles.name', 'super_admin')
            ->where('roles.guard_name', 'web')
            ->exists();
    }

    // The global back-office catalogue editor (team_id null). Raw check for the
    // same reason as isSuperAdmin(): hasRole() would filter by whatever team
    // the resolver currently returns.
    public function isCatalogueEditor(): bool
    {
        return DB::table('model_has_roles')
            ->join('roles', 'roles.id', '=', 'model_has_roles.role_id')
            ->where('model_has_roles.model_id', $this->id)
            ->where('model_has_roles.model_type', get_class($this))
            ->whereNull('model_has_roles.team_id')
            ->where('roles.name', 'catalogue_editor')
            ->where('roles.guard_name', 'web')
            ->exists();
    }

    // A permission held through a GLOBAL role (team_id null), checked the same
    // way whatever panel or tenant the request is in. Spatie filters roles by
    // the current team, so inside the vendor panel a plain can() would never
    // see a global role — and outside it, a vendor role would never count.
    public function hasGlobalPermission(string $permission): bool
    {
        $previousTeam = getPermissionsTeamId();
        setPermissionsTeamId(null);
        $this->unsetRelation('roles')->unsetRelation('permissions');

        try {
            return $this->checkPermissionTo($permission);
        } finally {
            setPermissionsTeamId($previousTeam);
            $this->unsetRelation('roles')->unsetRelation('permissions');
        }
    }

    public function initials(): string
    {
        return Str::of($this->name)
            ->explode(' ')
            ->take(2)
            ->map(fn ($word) => Str::substr($word, 0, 1))
            ->implode('');
    }

    // Vendors this user owns
    public function ownedVendors()
    {
        return $this->hasMany(Vendor::class);
    }

    // Vendors this user is a team member of
    public function memberVendors()
    {
        return $this->belongsToMany(Vendor::class, 'vendor_users')
            ->withTimestamps();
    }

    // All vendors (owned + member) — used by Filament tenant resolution
    public function vendors()
    {
        $owned = $this->ownedVendors()->get();
        $member = $this->memberVendors()->get();

        return $owned->merge($member)->unique('id');
    }

    // Stores this user has been granted, across every vendor.
    public function stores()
    {
        return $this->belongsToMany(Store::class, 'store_user')
            ->withTimestamps();
    }

    /**
     * All stores this user belongs to or operates in:
     * - Vendor owners have access to all stores of their owned vendor(s)
     * - Staff members are assigned via store_user
     */
    public function resolvedStores(): Collection
    {
        $stores = collect();

        if ($this->relationLoaded('ownedVendors')) {
            foreach ($this->ownedVendors as $vendor) {
                if ($vendor->relationLoaded('stores')) {
                    foreach ($vendor->stores as $store) {
                        $stores->push($store);
                    }
                } else {
                    $stores = $stores->merge($vendor->stores()->get());
                }
            }
        } else {
            foreach ($this->ownedVendors()->with('stores')->get() as $vendor) {
                $stores = $stores->merge($vendor->stores);
            }
        }

        if ($this->relationLoaded('stores')) {
            $stores = $stores->merge($this->stores);
        } else {
            $stores = $stores->merge($this->stores()->with('vendor')->get());
        }

        return $stores->unique('id')->values();
    }

    /**
     * Returns the user's roles along with their context (store/vendor name):
     * - Global roles: Super Admin, Catalogue Editor
     * - Store Owner: for vendor owners
     * - Store / Vendor roles: e.g. Inventory Officer, Storekeeper, etc.
     * - Customer: if unaffiliated
     */
    public function resolvedRolesWithContext(): Collection
    {
        $roles = collect();

        if ($this->isSuperAdmin()) {
            $roles->push([
                'name' => 'Super Admin',
                'context' => null,
                'color' => 'danger',
                'type' => 'global',
            ]);
        }

        if ($this->isCatalogueEditor()) {
            $roles->push([
                'name' => 'Catalogue Editor',
                'context' => null,
                'color' => 'warning',
                'type' => 'global',
            ]);
        }

        $owned = $this->relationLoaded('ownedVendors') ? $this->ownedVendors : $this->ownedVendors()->get();
        foreach ($owned as $vendor) {
            $roles->push([
                'name' => 'Store Owner',
                'context' => $vendor->name,
                'color' => 'success',
                'type' => 'owner',
            ]);
        }

        $teamRoles = DB::table('model_has_roles')
            ->join('roles', 'roles.id', '=', 'model_has_roles.role_id')
            ->leftJoin('vendors', 'vendors.id', '=', 'model_has_roles.team_id')
            ->where('model_has_roles.model_id', $this->id)
            ->whereNotNull('model_has_roles.team_id')
            ->select('roles.name as role_name', 'vendors.name as vendor_name')
            ->get();

        foreach ($teamRoles as $tr) {
            $roles->push([
                'name' => Str::headline($tr->role_name),
                'context' => $tr->vendor_name,
                'color' => 'info',
                'type' => 'store_role',
            ]);
        }

        $otherGlobalRoles = DB::table('model_has_roles')
            ->join('roles', 'roles.id', '=', 'model_has_roles.role_id')
            ->where('model_has_roles.model_id', $this->id)
            ->whereNull('model_has_roles.team_id')
            ->whereNotIn('roles.name', ['super_admin', 'catalogue_editor'])
            ->pluck('roles.name');

        foreach ($otherGlobalRoles as $gr) {
            $roles->push([
                'name' => Str::headline($gr),
                'context' => null,
                'color' => 'gray',
                'type' => 'global',
            ]);
        }

        $members = $this->relationLoaded('memberVendors') ? $this->memberVendors : $this->memberVendors()->get();
        if ($members->isNotEmpty() && $teamRoles->isEmpty() && $owned->isEmpty()) {
            foreach ($members as $mv) {
                $roles->push([
                    'name' => 'Staff Member',
                    'context' => $mv->name,
                    'color' => 'info',
                    'type' => 'store_role',
                ]);
            }
        }

        if ($roles->isEmpty()) {
            $roles->push([
                'name' => 'Customer',
                'context' => null,
                'color' => 'gray',
                'type' => 'customer',
            ]);
        }

        return $roles;
    }

    // The user's stores within one vendor. Filtered on stores.vendor_id
    // explicitly — the same manual scoping every vendor-owned query in this
    // codebase uses, and the qualified column name matters here because the
    // pivot join puts two tables in play.
    public function storesForVendor(int $vendorId): EloquentCollection
    {
        return $this->stores()->where('stores.vendor_id', $vendorId)->get();
    }

    public function getTenants(Panel $panel): Collection
    {
        if ($this->isSuperAdmin()) {
            return Vendor::all();
        }

        return $this->vendors();
    }

    /** Set for someone who counts stock and nothing else (see StockTakeCounters). */
    public function counterProfile()
    {
        return $this->hasOne(StockTakeCounter::class);
    }

    public function isCounter(): bool
    {
        return $this->counterProfile()->exists();
    }

    public function canAccessTenant(Model $tenant): bool
    {
        if ($this->isSuperAdmin()) {
            return true;
        }

        return $this->vendors()->contains($tenant);
    }

    public function hasVendorRole(int $vendorId, array|string $roles): bool
    {
        $roles = (array) $roles;

        if ($this->ownedVendors()->where('id', $vendorId)->exists()) {
            return true;
        }

        setPermissionsTeamId($vendorId);
        $this->unsetRelation('roles');

        return $this->hasAnyRole($roles);
    }

    public function hasVendorPermission(int $vendorId, string|array $permission): bool
    {
        // The platform's super admin holds no role inside any shop, so a shop's
        // own permissions would refuse them everywhere. They may do anything in
        // any shop, as its owner may.
        if ($this->isSuperAdmin()) {
            return true;
        }

        if ($this->ownedVendors()->where('id', $vendorId)->exists()) {
            return true;
        }

        setPermissionsTeamId($vendorId);
        $this->unsetRelation('roles');

        return $this->hasAnyPermission((array) $permission);
    }

    public function orders()
    {
        return $this->hasMany(Order::class);
    }

    public function affiliate()
    {
        return $this->hasOne(Affiliate::class);
    }

    public function wishlistedProducts()
    {
        return $this->belongsToMany(Product::class, 'wishlists')->withTimestamps();
    }

    public function vendorApplication()
    {
        return $this->hasOne(VendorApplication::class);
    }

    public function assistantSetting(): HasOne
    {
        return $this->hasOne(AssistantSetting::class);
    }

    public function assistantThreads(): HasMany
    {
        return $this->hasMany(AssistantThread::class);
    }

    public function assistantMessages(): HasMany
    {
        return $this->hasMany(AssistantMessage::class);
    }

    public function canAccessPanel(Panel $panel): bool
    {
        if ($panel->getId() === 'admin') {
            // The catalogue editor is admitted to the panel, but every admin
            // resource and page except All Products keeps its own super_admin
            // gate — see AdminPanelAccessTest, which checks all of them.
            // Delivery-settings staff come in for that one page; every other
            // admin page keeps its own gate (see AdminPanelAccessTest).
            // Dispatch staff come in for Orders (the dispatch board) and
            // Logistics companies only.
            // Tip writers come in for Help Centre → In-App Tips, App Features
            // and Tip Settings only (each gated by TipPolicy); adoption
            // viewers for Help Centre → Feature Adoption only.
            return $this->isSuperAdmin() || $this->isCatalogueEditor()
                || $this->hasGlobalPermission('manage_delivery_settings')
                || $this->hasGlobalPermission('manage_dispatch')
                || $this->hasGlobalPermission('manage_in_app_tips')
                || $this->hasGlobalPermission('view_feature_adoption');
        }

        if ($panel->getId() === 'staff') {
            // Back-office staff panel: the catalogue editor today. New staff
            // jobs (marketing materials, etc.) extend this same list.
            return $this->isSuperAdmin() || $this->isCatalogueEditor();
        }

        if ($panel->getId() === 'vendor') {
            // A counter has the phone app and nothing else.
            return $this->isSuperAdmin() || ($this->vendors()->isNotEmpty() && ! $this->isCounter());
        }

        return false;
    }
}

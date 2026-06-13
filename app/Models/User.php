<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Str;
use Laravel\Fortify\TwoFactorAuthenticatable;
use Filament\Models\Contracts\HasTenants;
use Filament\Panel;
use Illuminate\Support\Collection;
use Illuminate\Database\Eloquent\Model;
use Spatie\Permission\Traits\HasRoles;
use Filament\Models\Contracts\FilamentUser;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable implements HasTenants, FilamentUser
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable, TwoFactorAuthenticatable, HasRoles, HasApiTokens;

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
        return \Illuminate\Support\Facades\DB::table('model_has_roles')
            ->join('roles', 'roles.id', '=', 'model_has_roles.role_id')
            ->where('model_has_roles.model_id', $this->id)
            ->where('model_has_roles.model_type', get_class($this))
            ->whereNull('model_has_roles.team_id')
            ->where('roles.name', 'super_admin')
            ->where('roles.guard_name', 'web')
            ->exists();
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

    public function getTenants(Panel $panel): Collection
    {
        if ($this->isSuperAdmin()) {
            return Vendor::all();
        }

        return $this->vendors();
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

    public function wishlistedProducts()
    {
        return $this->belongsToMany(Product::class, 'wishlists')->withTimestamps();
    }

    public function vendorApplication()
    {
        return $this->hasOne(VendorApplication::class);
    }

    public function canAccessPanel(Panel $panel): bool
    {
        if ($panel->getId() === 'admin') {
            return $this->isSuperAdmin();
        }

        if ($panel->getId() === 'vendor') {
            return $this->isSuperAdmin() || $this->vendors()->isNotEmpty();
        }

        return false;
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Collection;
use App\Models\VendorPayout;
use Spatie\Sluggable\HasSlug;
use Spatie\Sluggable\SlugOptions;

class Vendor extends Model
{
    use HasSlug;

    protected $fillable = [
        'user_id', 'name', 'slug', 'logo', 'is_verified',
        'description', 'whatsapp', 'bank_name', 'account_number', 'account_name',
        'pos_vat_enabled', 'pos_vat_rate', 'pos_blind_count_participants', 'owner_can_manage_roles'
    ];

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

    public function payouts()
    {
        return $this->hasMany(VendorPayout::class);
    }

    public function isOwner(User $user): bool
    {
        return $this->user_id === $user->id;
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
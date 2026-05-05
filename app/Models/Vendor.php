<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Collection;

class Vendor extends Model
{
    protected $guarded = [];

    // Original owner
    public function user()
    {
        return $this->belongsTo(User::class);
    }

    // All team members
    public function users()
    {
        return $this->belongsToMany(User::class, 'vendor_users')
            ->withPivot('role')
            ->withTimestamps();
    }

    public function products()
    {
        return $this->hasMany(Product::class);
    }

    public function isOwner(User $user): bool
    {
        return $this->user_id === $user->id;
    }

    public function hasAnyRole(User $user, array $roles): bool
    {
        return $this->users()
            ->where('user_id', $user->id)
            ->wherePivotIn('role', $roles)
            ->exists();
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
        $member = $user->vendors()->get();

        return $owned->merge($member)->unique('id');
    }
}
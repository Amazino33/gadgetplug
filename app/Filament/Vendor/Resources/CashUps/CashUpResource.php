<?php

namespace App\Filament\Vendor\Resources\CashUps;

use App\Filament\Vendor\Resources\CashUps\Pages\ListCashUps;
use App\Models\PosSession;
use App\Services\ActiveStore;
use BackedEnum;
use Filament\Resources\Resource;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

/**
 * End-of-day cash-ups waiting to be looked at.
 *
 * A cashier counts their drawer and reads their terminal blind, and the server
 * works out what should have been there. What is left over is this screen's
 * business: a manager explains the part that has an explanation, and whatever
 * survives that becomes money a named person owes.
 *
 * Nothing here is created or edited. The counts come from the till and are
 * frozen the moment they are submitted — a manager who could type a count would
 * make the count worthless.
 */
class CashUpResource extends Resource
{
    protected static ?string $model = PosSession::class;

    protected static ?string $tenantOwnershipRelationshipName = 'vendor';

    protected static string|null|BackedEnum $navigationIcon  = 'heroicon-o-calculator';
    protected static string|null|UnitEnum   $navigationGroup = 'Money';
    protected static ?string                $navigationLabel = 'End of Day Record';
    protected static ?string                $modelLabel      = 'end of day record';
    protected static ?int                   $navigationSort  = 2;

    public static function getPages(): array
    {
        return ['index' => ListCashUps::route('/')];
    }

    /**
     * Days waiting on a manager.
     *
     * On the navigation item because an unreviewed cash-up is a difference
     * nobody has looked at, and the longer it sits the harder it is to find out
     * what happened.
     */
    public static function getNavigationBadge(): ?string
    {
        $vendor = filament()->getTenant();

        if (! $vendor) {
            return null;
        }

        $waiting = static::scopeToActiveStore(
            PosSession::query()->forVendor($vendor->id)->pendingReview()
        )->count();

        return $waiting > 0 ? (string) $waiting : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'warning';
    }

    /**
     * A cashier sees their own days; a reviewer sees the branch they are in.
     *
     * The cashier's own view matters: being told you are short without being
     * able to see the working is not something anybody should have to accept.
     */
    public static function getEloquentQuery(): Builder
    {
        $query = static::scopeToActiveStore(parent::getEloquentQuery());
        $user = auth()->user();
        $vendor = filament()->getTenant();

        if ($user?->isSuperAdmin() || ($vendor && $vendor->isOwner($user))) {
            return $query;
        }

        if ($vendor && $user?->hasVendorPermission($vendor->id, 'receive_cash')) {
            return $query;
        }

        return $query->where('cashier_id', $user?->id);
    }

    /**
     * Narrow to the branch the panel is currently working in.
     *
     * Whether this applied is said out loud on the page rather than left to be
     * inferred — a figure labelled as one branch that is silently the whole
     * business is how somebody ends up chasing the wrong person.
     */
    public static function scopeToActiveStore(Builder $query): Builder
    {
        $storeId = ActiveStore::currentId();

        return $storeId ? $query->where('store_id', $storeId) : $query;
    }

    public static function canAccess(): bool
    {
        $user = auth()->user();
        $vendor = filament()->getTenant();

        if (! $vendor || ! $user) {
            return false;
        }

        // Reviewers, plus any cashier who has ever cashed up here — so they can
        // read their own history.
        return $user->isSuperAdmin()
            || $vendor->isOwner($user)
            || $user->hasVendorPermission($vendor->id, 'receive_cash')
            || PosSession::query()->forVendor($vendor->id)->forCashier($user->id)->exists();
    }

    public static function canCreate(): bool
    {
        // Opened and closed at the till, never from here.
        return false;
    }
}

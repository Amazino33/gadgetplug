<?php

namespace App\Filament\Vendor\Resources\CashSubmissions;

use App\Filament\Vendor\Resources\CashSubmissions\Pages\ListCashSubmissions;
use App\Models\CashSubmission;
use BackedEnum;
use Filament\Resources\Resource;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

/**
 * Cash handed from whoever took it to whoever holds it next.
 *
 * Two names on every row and nothing settled by one person alone. A handover
 * sits outstanding until the person named as receiving answers for it, which is
 * the part that actually stops cash leaking — a record written by one side
 * rests on the word of the person who might have kept it.
 */
class CashSubmissionResource extends Resource
{
    protected static ?string $model = CashSubmission::class;

    protected static ?string $tenantOwnershipRelationshipName = 'vendor';

    protected static string|null|BackedEnum $navigationIcon  = 'heroicon-o-banknotes';
    protected static string|null|UnitEnum   $navigationGroup = 'Money';
    protected static ?string                $navigationLabel = 'Cash Submissions';
    protected static ?string                $modelLabel      = 'cash submission';
    protected static ?int                   $navigationSort  = 1;

    public static function getPages(): array
    {
        return ['index' => ListCashSubmissions::route('/')];
    }

    /**
     * Handovers waiting on the signed-in user to answer for them.
     *
     * On the navigation item because an unanswered handover is money nobody has
     * accounted for, and it should be visible without going looking.
     */
    public static function getNavigationBadge(): ?string
    {
        $vendor = filament()->getTenant();

        if (! $vendor) {
            return null;
        }

        $waiting = CashSubmission::where('vendor_id', $vendor->id)
            ->where('received_by', auth()->id())
            ->where('status', CashSubmission::STATUS_PENDING)
            ->count();

        return $waiting > 0 ? (string) $waiting : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'warning';
    }

    /**
     * Everyone sees the handovers they are part of. The owner sees all of them,
     * because the whole point is somebody above both parties being able to look.
     */
    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery();
        $user = auth()->user();
        $vendor = filament()->getTenant();

        if ($user?->isSuperAdmin() || ($vendor && $vendor->isOwner($user))) {
            return $query;
        }

        return $query->where(fn (Builder $q) => $q
            ->where('submitted_by', $user?->id)
            ->orWhere('received_by', $user?->id));
    }

    public static function canAccess(): bool
    {
        $user = auth()->user();
        $vendor = filament()->getTenant();

        return $vendor && (
            $user->isSuperAdmin()
            || $vendor->isOwner($user)
            || $user->hasVendorPermission($vendor->id, ['submit_cash', 'receive_cash'])
        );
    }

    public static function canCreate(): bool
    {
        // Recorded through the Submit action, which needs the drawer balance in
        // front of the person doing it. A blank create form could not show that.
        return false;
    }
}

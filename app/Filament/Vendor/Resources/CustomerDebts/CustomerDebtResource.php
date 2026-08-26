<?php

namespace App\Filament\Vendor\Resources\CustomerDebts;

use App\Filament\Vendor\Resources\CustomerDebts\Pages\ListCustomerDebts;
use App\Filament\Vendor\Resources\CustomerDebts\Pages\ViewCustomerDebt;
use App\Models\PosCustomer;
use App\Models\PosCustomerLedgerEntry;
use Filament\Resources\Resource;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

/**
 * Who owes the store money, and what each of them has done about it.
 *
 * Built on PosCustomer rather than the ledger because a debt is a person, not a
 * row — the question staff ask is "who owes us?", and a list of charges answers
 * a different one.
 *
 * Every figure shown is summed from the ledger by a subquery. There is no
 * balance column to read, so nothing here can disagree with the history behind
 * it. Creating and editing are closed: a debt only moves by selling on credit
 * or by recording a repayment, both of which write their own immutable rows.
 */
class CustomerDebtResource extends Resource
{
    protected static ?string $model = PosCustomer::class;

    protected static ?string $tenantOwnershipRelationshipName = 'vendor';

    protected static string|null|\BackedEnum $navigationIcon  = 'heroicon-o-banknotes';
    protected static string|null|UnitEnum    $navigationGroup = 'Point of Sale';
    protected static ?string                 $navigationLabel = 'Customer Debts';
    protected static ?string                 $modelLabel      = 'customer debt';
    protected static ?int                    $navigationSort  = 3;

    public static function canAccess(): bool
    {
        $user   = auth()->user();
        $vendor = filament()->getTenant();

        return $vendor && (
            $user->isSuperAdmin()
            || $vendor->isOwner($user)
            || $user->hasVendorPermission($vendor->id, 'view_customer_debts')
        );
    }

    public static function canCreate(): bool        { return false; }
    public static function canEdit($record): bool   { return false; }
    public static function canDelete($record): bool { return false; }
    public static function canDeleteAny(): bool     { return false; }

    /** How much this vendor is owed in total, so the number is visible without opening the page. */
    public static function getNavigationBadge(): ?string
    {
        $vendor = filament()->getTenant();

        if (! $vendor) {
            return null;
        }

        $count = static::baseDebtQuery($vendor->id)->count();

        return $count > 0 ? (string) $count : null;
    }

    public static function getNavigationBadgeColor(): string
    {
        return 'danger';
    }

    /**
     * Customers with a positive balance, carrying their derived figures.
     *
     * Subqueries rather than a PHP loop so the table can sort and paginate on
     * outstanding — a debt list is read worst-first, and that ordering has to
     * happen in the database to survive pagination.
     */
    public static function baseDebtQuery(int $vendorId): Builder
    {
        $sumOf = fn (string $direction) => fn ($query) => $query->where('direction', $direction);

        return PosCustomer::query()
            ->where('vendor_id', $vendorId)
            // Relation aggregates rather than hand-built subqueries: Laravel
            // composes the bindings itself, which a manual selectSub gets wrong
            // as soon as the inner query carries a value of its own.
            ->withSum('ledgerEntries as outstanding_amount', 'amount')
            ->withSum(['ledgerEntries as charged_amount' => $sumOf(PosCustomerLedgerEntry::DIRECTION_CHARGE)], 'amount')
            ->withSum(['ledgerEntries as paid_amount' => $sumOf(PosCustomerLedgerEntry::DIRECTION_PAYMENT)], 'amount')
            ->withSum(['ledgerEntries as written_off_amount' => $sumOf(PosCustomerLedgerEntry::DIRECTION_WRITEOFF)], 'amount')
            // Filtered with a correlated subquery, not HAVING: SQLite refuses a
            // HAVING clause on a non-aggregate query, and adding a GROUP BY to
            // satisfy it would then break MySQL under ONLY_FULL_GROUP_BY. This
            // form is plain SQL both accept.
            ->whereRaw('(select coalesce(sum(amount), 0) from pos_customer_ledger_entries'
                . ' where pos_customer_ledger_entries.pos_customer_id = pos_customers.id) > 0');
    }

    public static function getEloquentQuery(): Builder
    {
        return static::baseDebtQuery((int) filament()->getTenant()?->id);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListCustomerDebts::route('/'),
            'view'  => ViewCustomerDebt::route('/{record}'),
        ];
    }
}

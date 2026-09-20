<?php

namespace App\Filament\Vendor\Resources\Procurements;

use App\Models\Procurement;
use App\Services\ActiveStore;
use Filament\Actions\Action;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\Layout\Panel;
use Filament\Tables\Columns\Layout\Split;
use Filament\Tables\Columns\Layout\Stack;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class ProcurementResource extends Resource
{
    protected static ?string $model = Procurement::class;

    protected static ?string $tenantOwnershipRelationshipName = 'vendor';

    protected static string|null|\BackedEnum $navigationIcon  = 'heroicon-o-inbox-arrow-down';
    protected static string|null|\UnitEnum  $navigationGroup = 'Procurement';
    protected static ?string                $navigationLabel = 'Procurements';
    protected static ?int $navigationSort = 2;

    /**
     * A delivery belongs to the branch it was sent to.
     *
     * Goods sent to Oraimo are Oraimo's business: their staff receive them,
     * check them against the waybill and put them on the shelf. Somebody at
     * another branch approving that order is approving stock they cannot see,
     * which is how a delivery ends up recorded onto the wrong shelf.
     *
     * Three things stay visible beyond your own branch, each for a reason:
     *
     *  - Orders with no destination at all. These predate the destination
     *    field and belong to no branch, so confining them to one would strand
     *    them. They keep working exactly as before.
     *  - Orders you created yourself. Whoever records a purchase needs to
     *    watch it through to approval; they still cannot approve it.
     *  - Everything, if you are the owner or a super admin — accessibleFor
     *    hands them every branch, so that falls out of the same rule rather
     *    than needing a special case here.
     */
    public static function reachableBy(Builder $query): Builder
    {
        $vendor = filament()->getTenant();
        $user   = auth()->user();

        if (! $vendor || ! $user) {
            return $query;
        }

        $branches = ActiveStore::accessibleFor($vendor, $user)->pluck('id');

        return $query->where(fn (Builder $q) => $q
            ->whereIn('procurements.store_id', $branches)
            ->orWhereNull('procurements.store_id')
            ->orWhere('procurements.created_by', $user->id));
    }

    /**
     * Whether this user may receive this particular delivery into stock.
     *
     * The same rule as the list, minus the creator exemption: seeing your own
     * order through is not the same as being the branch that took delivery of
     * it, and approving is what actually moves the stock.
     */
    public static function canApprove(Procurement $procurement): bool
    {
        $vendor = filament()->getTenant();
        $user   = auth()->user();

        if (! $vendor || ! $user) {
            return false;
        }

        // No destination recorded: nothing to be wrong about, and these are
        // the orders that predate the field.
        if ($procurement->store_id === null) {
            return true;
        }

        return ActiveStore::canAccess($vendor, $user, (int) $procurement->store_id);
    }

    public static function getEloquentQuery(): Builder
    {
        return static::reachableBy(parent::getEloquentQuery());
    }

    /**
     * How many deliveries are sitting unapproved.
     *
     * Approving is what actually puts the goods on a shelf, so an order left
     * pending is stock the system does not know it has. The number is here to
     * be noticed from any other screen in the panel.
     *
     * Counted through the same branch rule as the list, so a storekeeper is
     * never badged about a delivery they cannot open.
     */
    public static function getNavigationBadge(): ?string
    {
        if (! \Illuminate\Support\Facades\Schema::hasTable('procurements')) {
            return null;
        }

        $vendor = filament()->getTenant();

        if (! $vendor) {
            return null;
        }

        // Both open states, not just 'pending'. A delivery somebody sent back
        // is the one most in need of chasing — counting only untouched ones
        // would make a disputed delivery quietly disappear from the badge and
        // sit unresolved precisely because nobody was reminded of it.
        $count = static::reachableBy(
            Procurement::query()->where('procurements.vendor_id', $vendor->id)
        )->whereIn('procurements.status', [
            Procurement::STATUS_PENDING,
            Procurement::STATUS_CHANGES_REQUESTED,
        ])->count();

        // Null rather than "0": a badge showing nothing to do is just noise on
        // the navigation for the many days there is nothing to do.
        return $count > 0 ? (string) $count : null;
    }

    public static function getNavigationBadgeColor(): string
    {
        return 'warning';
    }

    public static function getNavigationBadgeTooltip(): ?string
    {
        return 'Deliveries waiting to be agreed and received into stock';
    }

    public static function canAccess(): bool
    {
        if (! \Illuminate\Support\Facades\Schema::hasTable('procurements')) {
            return false;
        }

        $user   = auth()->user();
        $vendor = filament()->getTenant();
        return $vendor && $user->hasVendorPermission($vendor->id, 'manage_procurement');
    }

    public static function canCreate(): bool
    {
        $user   = auth()->user();
        $vendor = filament()->getTenant();
        return $vendor && $user->hasVendorPermission($vendor->id, 'manage_procurement');
    }

    public static function canEdit($record): bool   { return false; }
    public static function canDelete($record): bool { return false; }

    public static function form(Schema $schema): Schema
    {
        // Not used directly — wizard is on the create page
        return $schema->schema([]);
    }

    public static function table(Table $table): Table
    {
        return $table
            // Split, not nine columns side by side.
            //
            // The list had the same problem the review screen had: a row wider
            // than a phone, dragged sideways to find the status. Filament
            // stacks a Split below its breakpoint and lays it out as a row
            // above, so one definition gives a card on a phone and a table on
            // a desktop — and search, sorting and filters keep working, which
            // hand-rolled cards would have cost.
            ->columns([
                Split::make([
                    Stack::make([
                        TextColumn::make('reference')
                            ->label('Ref #')
                            ->searchable()
                            ->weight('bold')
                            ->copyable(),

                        TextColumn::make('supplier.name')
                            ->label('Supplier')
                            ->searchable()
                            ->sortable()
                            ->size('sm')
                            ->color('gray'),
                    ]),

                    Stack::make([
                        TextColumn::make('total_cost')
                            ->label('Total Cost')
                            ->money('NGN')
                            ->sortable()
                            ->weight('bold'),

                        TextColumn::make('store.name')
                            ->label('Deliver To')
                            ->placeholder('Default store')
                            ->icon('heroicon-m-building-storefront')
                            ->size('sm')
                            ->color('gray'),
                    ]),

                    Stack::make([
                        TextColumn::make('status')
                            ->badge()
                            ->color(fn (?string $state) => match ($state) {
                                'pending'           => 'warning',
                                'changes_requested' => 'info',
                                'approved'          => 'success',
                                'voided'            => 'danger',
                                default             => 'gray',
                            })
                            ->formatStateUsing(fn (?string $state) => match ($state) {
                                'pending'           => 'Awaiting Check',
                                // Named for what somebody has to do about it
                                // rather than for the state it is in. "Changes
                                // requested" describes the record; "sent back"
                                // tells the person reading the list that it is
                                // moving, and which way.
                                'changes_requested' => 'Sent Back',
                                default             => ucfirst((string) $state),
                            }),

                        TextColumn::make('payment_status')
                            ->label('Payment')
                            ->badge()
                            ->color(fn (?string $state) => match ($state) {
                                'full'         => 'success',
                                'part_payment' => 'warning',
                                'credit'       => 'danger',
                                default        => 'gray',
                            })
                            ->formatStateUsing(fn (?string $state) => match ($state) {
                                'full'         => 'Fully Paid',
                                'part_payment' => 'Part-Payment',
                                'credit'       => 'Credit',
                                default        => $state ?? '-',
                            }),
                    ]),
                ])->from('md'),

                // The detail nobody scans a list for, one tap away rather than
                // pushing the columns that matter off the side of the screen.
                Panel::make([
                    Stack::make([
                        TextColumn::make('items_count')
                            ->label('Items')
                            ->counts('items')
                            ->icon('heroicon-m-squares-2x2')
                            ->size('sm'),

                        TextColumn::make('amount_paid')
                            ->label('Amount Paid')
                            ->money('NGN')
                            ->icon('heroicon-m-banknotes')
                            ->size('sm'),

                        TextColumn::make('creator.name')
                            ->label('Logged By')
                            ->icon('heroicon-m-user')
                            ->size('sm'),

                        TextColumn::make('created_at')
                            ->label('Date')
                            ->dateTime('d M Y, H:i')
                            ->icon('heroicon-m-calendar')
                            ->sortable()
                            ->size('sm'),
                    ])->space(1),
                ])->collapsible(),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                SelectFilter::make('status')
                    ->options([
                        'pending'           => 'Awaiting Check',
                        'changes_requested' => 'Sent Back',
                        'approved'          => 'Approved',
                        'voided'            => 'Voided',
                    ]),
                SelectFilter::make('payment_status')
                    ->label('Payment')
                    ->options(['full' => 'Fully Paid', 'part_payment' => 'Part-Payment', 'credit' => 'Credit']),
            ])
            ->recordAction('view')
            ->actions([
                Action::make('view')
                    ->icon('heroicon-o-eye')
                    ->url(fn (Procurement $record) => Pages\ViewProcurement::getUrl(['record' => $record])),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index'  => Pages\ListProcurements::route('/'),
            'view'   => Pages\ViewProcurement::route('/{record}'),
        ];
    }
}

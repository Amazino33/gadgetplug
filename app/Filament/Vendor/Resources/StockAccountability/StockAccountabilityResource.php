<?php

declare(strict_types=1);

namespace App\Filament\Vendor\Resources\StockAccountability;

use App\Filament\Vendor\Resources\Products\Schemas\ProductForm;
use App\Filament\Vendor\Resources\StockAccountability\Pages;
use App\Models\StockAccountabilityEntry;
use App\Models\User;
use App\Services\StockAccountabilityLedger;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use UnitEnum;

// Read-only view of the accountability ledger. No create, edit or delete —
// entries are append-only and the model throws on both, so offering the buttons
// would only produce errors. The one write available here is Reverse, which
// adds a cancelling entry rather than removing anything.
class StockAccountabilityResource extends Resource
{
    protected static ?string $model = StockAccountabilityEntry::class;

    protected static ?string $tenantOwnershipRelationshipName = 'vendor';

    protected static string|null|\BackedEnum $navigationIcon  = 'heroicon-o-scale';
    protected static string|null|UnitEnum    $navigationGroup = 'Inventory';
    protected static ?string                 $navigationLabel = 'Stock Accountability';
    protected static ?int                    $navigationSort  = 6;

    // Reuses the same gate as the audit screen this is the consequence of —
    // anyone trusted to review counts can see what came of them.
    public static function canAccess(): bool
    {
        $user   = auth()->user();
        $vendor = filament()->getTenant();

        return $vendor && $user?->hasVendorPermission($vendor->id, 'view_audit_sessions');
    }

    public static function canCreate(): bool { return false; }

    public static function canEdit($record): bool { return false; }

    public static function canDelete($record): bool { return false; }

    // Reversing withdraws an accusation, so it sits with the owner for the same
    // reason attributing does.
    public static function canReverse(StockAccountabilityEntry $record): bool
    {
        $user = auth()->user();

        if (! $user) {
            return false;
        }

        return $user->isSuperAdmin() || $record->vendor?->isOwner($user) === true;
    }

    private const DISPOSITION_LABELS = [
        'written_off' => 'Written off',
        'recoverable' => 'Recoverable',
        'recorded'    => 'Recorded only',
        'reversal'    => 'Reversal',
    ];

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('occurred_at')
                    ->label('Date')
                    ->date('d M Y')
                    ->sortable(),

                TextColumn::make('product.name')
                    ->label('Product')
                    ->searchable()
                    ->wrap()
                    ->description(fn (StockAccountabilityEntry $r): string => 'Count #'.$r->audit_session_id),

                TextColumn::make('storekeeper.name')
                    ->label('Accountable')
                    ->searchable()
                    // Unattributed is a real, deliberate state — a loss recorded
                    // against the store with nobody named — so it is spelled out
                    // rather than left as an empty cell that reads like a bug.
                    ->placeholder('Not attributed')
                    ->description(fn (StockAccountabilityEntry $r): ?string => $r->resolver
                        ? 'by '.$r->resolver->name
                        : null),

                TextColumn::make('quantity_variance')
                    ->label('Variance')
                    ->alignCenter()
                    ->badge()
                    ->formatStateUsing(fn (int $state): string => ($state > 0 ? '+' : '').$state)
                    ->color(fn (int $state): string => match (true) {
                        $state < 0 => 'danger',
                        $state > 0 => 'warning',
                        default    => 'gray',
                    }),

                // Derived from cost price, so it is behind the same gate as cost
                // itself — see the Value at Risk column on Audit Sessions.
                TextColumn::make('amount')
                    ->label('Amount (₦)')
                    ->alignRight()
                    ->visible(fn (): bool => ProductForm::canSeeCostPrice())
                    ->formatStateUsing(fn ($state): string => '₦'.number_format((float) $state, 2))
                    ->color(fn (StockAccountabilityEntry $r): string => $r->disposition === 'reversal' ? 'success' : 'gray'),

                TextColumn::make('disposition')
                    ->label('Outcome')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => self::DISPOSITION_LABELS[$state] ?? $state)
                    ->color(fn (string $state): string => match ($state) {
                        'written_off' => 'warning',
                        'recoverable' => 'danger',
                        'recorded'    => 'gray',
                        'reversal'    => 'success',
                        default       => 'gray',
                    }),

                TextColumn::make('reason_code')
                    ->label('Reason')
                    ->badge()
                    ->color('warning')
                    ->placeholder('—')
                    ->toggleable(),

                TextColumn::make('note')
                    ->label('Note')
                    ->wrap()
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('occurred_at', 'desc')
            ->filters([
                SelectFilter::make('disposition')
                    ->label('Outcome')
                    ->options(self::DISPOSITION_LABELS),

                SelectFilter::make('storekeeper_id')
                    ->label('Accountable')
                    ->options(fn (): array => User::query()
                        ->whereHas('memberVendors', fn ($q) => $q->where('vendors.id', filament()->getTenant()?->id))
                        ->orderBy('name')
                        ->pluck('name', 'id')
                        ->toArray())
                    ->searchable(),
            ])
            ->recordActions([
                Action::make('reverse')
                    ->label('Reverse')
                    ->icon('heroicon-o-arrow-uturn-left')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalHeading('Reverse this entry')
                    ->modalDescription(fn (StockAccountabilityEntry $r): string => sprintf(
                        'Writes a cancelling entry for %s. The original stays on the record — the trail will show both what was claimed and that it was withdrawn.',
                        $r->storekeeper?->name ?? 'this unattributed loss',
                    ))
                    ->form([
                        Textarea::make('note')
                            ->label('Why is this being reversed?')
                            ->rows(2)
                            ->required(),
                    ])
                    // A reversal cannot itself be reversed, and an entry already
                    // cancelled should not offer the button again.
                    ->visible(fn (StockAccountabilityEntry $record): bool =>
                        $record->disposition !== 'reversal'
                        && ! StockAccountabilityEntry::where('audit_session_id', $record->audit_session_id)
                            ->where('disposition', 'reversal')
                            ->exists()
                        && static::canReverse($record)
                    )
                    ->action(function (StockAccountabilityEntry $record, array $data, StockAccountabilityLedger $ledger): void {
                        try {
                            $ledger->reverse($record, (int) auth()->id(), $data['note']);
                            Notification::make()->title('Entry reversed.')->success()->send();
                        } catch (\Throwable $e) {
                            Notification::make()->title($e->getMessage())->danger()->send();
                        }
                    }),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListStockAccountability::route('/'),
        ];
    }
}

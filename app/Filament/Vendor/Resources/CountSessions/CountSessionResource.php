<?php

declare(strict_types=1);

namespace App\Filament\Vendor\Resources\CountSessions;

use App\Filament\Vendor\Resources\CountSessions\Pages;
use App\Filament\Vendor\Resources\Products\Schemas\ProductForm;
use App\Models\BlindCountSession;
use Filament\Resources\Resource;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use UnitEnum;

// The counts themselves, as opposed to Audit Sessions which lists every counted
// line flat. Without this there was no way to see "a count happened on Tuesday
// and here is what came of it" — only hundreds of individual product rows with
// nothing grouping them.
class CountSessionResource extends Resource
{
    protected static ?string $model = BlindCountSession::class;

    protected static ?string $tenantOwnershipRelationshipName = 'vendor';

    protected static string|null|\BackedEnum $navigationIcon  = 'heroicon-o-clipboard-document-check';
    protected static string|null|UnitEnum    $navigationGroup = 'Inventory';
    // "Audit Sessions" is what this is called in the business. It replaces the
    // old screen of that name, which listed every counted line flat with
    // nothing grouping them — you audit a count, not a wall of product rows.
    protected static ?string                 $navigationLabel = 'Audit Sessions';
    protected static ?int $navigationSort = 4;

    // Without these, Filament titles the page from the model name and it reads
    // "Blind Count Sessions" — internal wording nobody outside the code uses.
    protected static ?string $modelLabel = 'Audit Session';

    protected static ?string $pluralModelLabel = 'Audit Sessions';

    public static function canAccess(): bool
    {
        $user   = auth()->user();
        $vendor = filament()->getTenant();

        return $vendor && $user?->hasVendorPermission($vendor->id, 'view_audit_sessions');
    }

    public static function canCreate(): bool { return false; }

    public static function canEdit($record): bool { return false; }

    public static function canDelete($record): bool { return false; }

    public static function getNavigationBadge(): ?string
    {
        $vendor = filament()->getTenant();

        if (! $vendor) {
            return null;
        }

        $open = BlindCountSession::where('vendor_id', $vendor->id)
            ->whereIn('status', ['a_counting', 'b_counting'])
            ->count();

        return $open > 0 ? (string) $open : null;
    }

    public static function table(Table $table): Table
    {
        return $table
            // Rollups walk each session's lines and their cases, so without this
            // the list would fire a handful of queries per row.
            ->modifyQueryUsing(fn ($query) => $query->with([
                'storekeeperA:id,name',
                'storekeeperB:id,name',
                'auditLines.product:id,name,cost_price',
                'auditLines.shortageCase',
            ]))
            ->columns([
                TextColumn::make('created_at')
                    ->label('Counted')
                    ->dateTime('d M Y, g:ia')
                    ->sortable(),

                TextColumn::make('frequency')
                    ->label('Type')
                    ->badge()
                    ->formatStateUsing(fn (?string $state): string => ucfirst((string) $state))
                    ->color('gray'),

                TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        'a_counting' => 'First count in progress',
                        'b_counting' => 'Awaiting second counter',
                        'completed'  => 'Completed',
                        default      => ucfirst($state),
                    })
                    ->color(fn (string $state): string => match ($state) {
                        'a_counting' => 'warning',
                        'b_counting' => 'info',
                        'completed'  => 'success',
                        default      => 'gray',
                    }),

                TextColumn::make('storekeeperA.name')
                    ->label('Counted by')
                    ->description(fn (BlindCountSession $r): string => $r->storekeeperB
                        ? 'verified by '.$r->storekeeperB->name
                        : 'solo count'),

                TextColumn::make('lines')
                    ->label('Products')
                    ->alignCenter()
                    ->getStateUsing(fn (BlindCountSession $r): int => count($r->product_order ?? [])),

                // "0 variances" on a count whose baselines were never recorded is
                // a lie by omission — it means "we cannot tell", not "nothing was
                // missing". Say which.
                TextColumn::make('variances')
                    ->label('Variances')
                    ->alignCenter()
                    ->badge()
                    ->getStateUsing(function (BlindCountSession $r): string {
                        if ($r->isEntirelyUnmeasurable()) {
                            return 'Not measurable';
                        }

                        return (string) $r->varianceLines()->count();
                    })
                    ->color(fn (BlindCountSession $r): string => match (true) {
                        $r->isEntirelyUnmeasurable()    => 'gray',
                        $r->varianceLines()->count() > 0 => 'danger',
                        default                          => 'success',
                    })
                    ->tooltip(fn (BlindCountSession $r): ?string => $r->hasUnmeasurableLines()
                        ? $r->unmeasurableLines()->count().' line(s) have no recorded system figure, so their variance cannot be worked out.'
                        : null),

                TextColumn::make('shortfall')
                    ->label('Shortfall?')
                    ->badge()
                    ->getStateUsing(fn (BlindCountSession $r): string => $r->hasShortfall() ? 'Yes' : '—')
                    ->color(fn (BlindCountSession $r): string => $r->hasShortfall() ? 'danger' : 'gray'),

                // Cost-derived, so behind the same gate as everywhere else.
                TextColumn::make('shortage_value')
                    ->label('Shortage (₦)')
                    ->alignRight()
                    ->visible(fn (): bool => ProductForm::canSeeCostPrice())
                    ->getStateUsing(function (BlindCountSession $r): string {
                        if ($r->isEntirelyUnmeasurable()) {
                            return '—';
                        }

                        $value = $r->shortageValueAtCost();

                        return $value > 0 ? '₦'.number_format($value, 2) : '—';
                    })
                    ->color(fn (BlindCountSession $r): string => ! $r->isEntirelyUnmeasurable() && $r->shortageValueAtCost() > 0
                        ? 'danger'
                        : 'gray'),

                TextColumn::make('unresolved')
                    ->label('Unresolved')
                    ->alignCenter()
                    ->badge()
                    ->getStateUsing(fn (BlindCountSession $r): int => $r->unresolvedCount())
                    ->color(fn (BlindCountSession $r): string => $r->unresolvedCount() > 0 ? 'warning' : 'success')
                    // Explains the pairing that reads as a contradiction: no
                    // measurable variance, yet lines still awaiting someone.
                    ->tooltip(fn (BlindCountSession $r): ?string => $r->unresolvedCount() > 0 && $r->isEntirelyUnmeasurable()
                        ? 'Flagged as differing when counted, but with no recorded system figure the difference cannot be quantified. Resolving each line corrects stock; no shortage can be charged.'
                        : null),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                SelectFilter::make('status')
                    ->options([
                        'a_counting' => 'First count in progress',
                        'b_counting' => 'Awaiting second counter',
                        'completed'  => 'Completed',
                    ]),
            ])
            // The row itself opens the session — this list exists to be drilled
            // into, so a separate View button would just be noise.
            ->recordUrl(fn (BlindCountSession $record): string => Pages\ViewCountSession::getUrl(['record' => $record]));
    }

    public static function getRelations(): array
    {
        return [
            RelationManagers\LinesRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListCountSessions::route('/'),
            'view'  => Pages\ViewCountSession::route('/{record}'),
        ];
    }
}

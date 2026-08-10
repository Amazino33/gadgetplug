<?php

declare(strict_types=1);

namespace App\Filament\Vendor\Resources\AuditSessions;

use App\Filament\Vendor\Resources\AuditSessions\Pages;
use App\Filament\Vendor\Resources\Products\Schemas\ProductForm;
use App\Models\AuditSession;
use App\Models\User;
use App\Actions\Inventory\ProcessAuditCountAction;
use App\Actions\Inventory\AdjustStockAction;
use App\Services\StockAccountabilityLedger;
use Filament\Forms\Components\Textarea;
use Filament\Resources\Resource;
use Filament\Tables\Table;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Exception;
use BackedEnum;
use UnitEnum;

class AuditSessionResource extends Resource
{
    protected static ?string $model = AuditSession::class;

    protected static ?string $tenantOwnershipRelationshipName = 'vendor';

    protected static string|null|BackedEnum $navigationIcon  = 'heroicon-o-clipboard-document-list';
    protected static string|null|UnitEnum   $navigationGroup = 'Inventory';
    protected static ?string                $navigationLabel = 'Audit Sessions';
    protected static ?int                   $navigationSort  = 4;

    public static function canAccess(): bool
    {
        $user   = auth()->user();
        $vendor = filament()->getTenant();
        return $vendor && $user->hasVendorPermission($vendor->id, 'view_audit_sessions');
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([

                // ── 1. Product thumbnail ─────────────────────────────────────────
                ImageColumn::make('product_thumbnail')
                    ->label('')
                    ->getStateUsing(
                        fn (AuditSession $r): ?string =>
                            $r->product?->getFirstMediaUrl('product-images', 'thumb') ?: null
                    )
                    ->defaultImageUrl(fn () => asset('images/logo.svg'))
                    ->size(44)
                    ->rounded()
                    ->extraImgAttributes(['class' => 'object-cover']),

                // ── 2. Product name + SKU ────────────────────────────────────────
                TextColumn::make('product.name')
                    ->label('Product')
                    ->searchable()
                    ->sortable()
                    ->wrap()
                    ->description(
                        fn (AuditSession $r): string => 'SKU: ' . ($r->product?->sku ?? '—')
                    )
                    ->extraAttributes(['style' => 'min-width: 180px']),

                // ── 3. System Qty ────────────────────────────────────────────────
                TextColumn::make('product.stock_quantity')
                    ->label('System Qty')
                    ->alignCenter()
                    ->numeric()
                    ->badge()
                    ->color('gray'),

                // ── 4. Count A + staff name ──────────────────────────────────────
                TextColumn::make('count_a')
                    ->label('Count A')
                    ->alignCenter()
                    ->numeric()
                    ->description(
                        fn (AuditSession $r): string => $r->storekeeperA?->name ?? '—'
                    ),

                // ── 5. Count B + staff name ──────────────────────────────────────
                TextColumn::make('count_b')
                    ->label('Count B')
                    ->alignCenter()
                    ->numeric()
                    ->placeholder('—')
                    ->description(
                        fn (AuditSession $r): string =>
                            $r->storekeeperB?->name ?? ($r->status === 'pending' ? 'Awaiting...' : '—')
                    ),

                // ── 6. Unit Variance (physical count − system qty) ───────────────
                // Measured against the baseline frozen when the count was taken,
                // not against live stock. Reading it live meant every sale made
                // after the count quietly enlarged the "variance".
                TextColumn::make('unit_variance')
                    ->label('Unit Variance')
                    ->alignCenter()
                    ->getStateUsing(function (AuditSession $r): string {
                        $diff = $r->countedVariance();

                        return $diff === null ? '—' : ($diff > 0 ? '+' : '').$diff;
                    })
                    ->color(function (AuditSession $r): string {
                        $diff = $r->countedVariance();

                        return match (true) {
                            $diff === null => 'gray',
                            $diff < 0      => 'danger',
                            $diff > 0      => 'warning',
                            default        => 'success',
                        };
                    })
                    ->tooltip(fn (AuditSession $r): ?string => $r->system_quantity === null
                        ? 'No baseline was recorded for this count, so its variance cannot be measured.'
                        : "Counted {$r->countedQuantity()} against a system figure of {$r->system_quantity} at count time.")
                    ->badge(),

                // ── 7. Value at Risk ─────────────────────────────────────────────
                // Derived from cost price, so it discloses cost by arithmetic —
                // anyone who can see a variance of 3 units and a value of ₦7,710
                // knows the unit cost. It was previously ungated here while the
                // Products screens hid cost behind view_cost_price, which made
                // that gate bypassable by opening this page instead.
                TextColumn::make('value_at_risk')
                    ->label('Value at Risk (₦)')
                    ->alignRight()
                    ->visible(fn (): bool => ProductForm::canSeeCostPrice())
                    ->getStateUsing(function (AuditSession $r): string {
                        $diff = $r->countedVariance();

                        if ($diff === null || $r->product?->cost_price === null) {
                            return '—';
                        }

                        $value  = $diff * (float) $r->product->cost_price;
                        $prefix = $value < 0 ? '−' : ($value > 0 ? '+' : '');

                        return $prefix.'₦'.number_format(abs($value), 2);
                    })
                    ->color(function (AuditSession $r): string {
                        $diff = $r->countedVariance();

                        return match (true) {
                            $diff === null => 'gray',
                            $diff < 0      => 'danger',
                            $diff > 0      => 'warning',
                            default        => 'success',
                        };
                    }),

                // ── 8. Status badge ──────────────────────────────────────────────
                TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        'pending'             => 'Pending',
                        'verified'            => 'Verified',
                        'discrepancy'         => 'Review Needed',
                        'resolved_by_override'=> 'Resolved (Override)',
                        default               => ucfirst($state),
                    })
                    ->color(fn (string $state): string => match ($state) {
                        'pending'             => 'warning',
                        'verified'            => 'success',
                        'discrepancy'         => 'warning',
                        'resolved_by_override'=> 'info',
                        default               => 'gray',
                    }),

                // ── 9. Reason code ───────────────────────────────────────────────
                TextColumn::make('reason_code')
                    ->label('Reason')
                    ->badge()
                    ->color('warning')
                    ->placeholder('—'),

                // ── 10. Financial write-off value ────────────────────────────────
                TextColumn::make('loss_value')
                    ->label('Write-Off (₦)')
                    ->alignRight()
                    ->getStateUsing(fn (AuditSession $r): string =>
                        $r->loss_value > 0
                            ? '−₦' . number_format((float) $r->loss_value, 2)
                            : '—'
                    )
                    ->color(fn (AuditSession $r): string =>
                        $r->loss_value > 0 ? 'danger' : 'gray'
                    ),

            ])
            ->recordActions([

                // ── Storekeeper B submits their count ────────────────────────────
                Action::make('verify_count')
                    ->label('Submit My Count')
                    ->icon('heroicon-o-check-circle')
                    ->color('success')
                    ->requiresConfirmation()
                    ->modalHeading('Verify Physical Stock')
                    ->slideOver()
                    ->form([
                        TextInput::make('count_b')
                            ->label('My Physical Count')
                            ->numeric()
                            ->required()
                            ->minValue(0),
                    ])
                    ->visible(fn (AuditSession $record): bool =>
                        $record->status === 'pending' &&
                        $record->storekeeper_a_id !== auth()->id()
                    )
                    ->action(function (AuditSession $record, array $data, ProcessAuditCountAction $processAudit): void {
                        try {
                            $audit = $processAudit->execute($record, auth()->id(), (int) $data['count_b']);

                            if ($audit->status === 'verified') {
                                Notification::make()->title('Match! Stock Updated.')->success()->send();
                            } else {
                                Notification::make()
                                    ->title('Discrepancy Logged')
                                    ->body('Count did not match. A manager must resolve it.')
                                    ->danger()
                                    ->send();

                                $managers = User::where(fn ($q) => $q
                                        ->whereHas('ownedVendors', fn ($q) => $q->where('id', $record->vendor_id))
                                        ->orWhereHas('roles', fn ($q) => $q
                                            ->where('name', 'inventory_manager')
                                            ->where('team_id', $record->vendor_id)
                                        )
                                    )
                                    ->where('id', '!=', auth()->id())
                                    ->get();

                                Notification::make()
                                    ->title('Audit Discrepancy — Action Required')
                                    ->body("Counts for \"{$record->product->name}\" don't match (A: {$record->count_a}, B: {$audit->count_b}). Please resolve.")
                                    ->danger()
                                    ->sendToDatabase($managers);
                            }
                        } catch (Exception $e) {
                            Notification::make()->title('Error')->body($e->getMessage())->danger()->send();
                        }
                    }),

                // ── Manager resolves discrepancy ─────────────────────────────────
                Action::make('manager_override')
                    ->label('Resolve Discrepancy')
                    ->icon('heroicon-o-exclamation-triangle')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalHeading('Manager Override')
                    ->modalDescription(fn (AuditSession $record): string => $record->storekeeper_b_id
                        ? "A counted {$record->count_a}. B counted {$record->count_b}. Enter the correct stock figure."
                        : "Solo count found {$record->count_a}. System expected {$record->product?->stock_quantity}. Enter the correct stock figure."
                    )
                    ->slideOver()
                    ->form([
                        TextInput::make('manager_override_count')
                            ->label('Final Correct Stock')
                            ->numeric()
                            ->required()
                            ->minValue(0),
                        Select::make('reason_code')
                            ->label('Reason for Discrepancy')
                            ->options([
                                'Damaged in Store'        => 'Damaged in Store',
                                'Suspected Theft'         => 'Suspected Theft',
                                'Waybill Shortage'        => 'Waybill Shortage',
                                'Data Entry Error'        => 'Data Entry Error',
                                'Supplier Short Delivery' => 'Supplier Short Delivery',
                                'Other'                   => 'Other',
                            ])
                            ->required()
                            ->searchable(),
                    ])
                    ->visible(fn (AuditSession $record): bool =>
                        $record->status === 'discrepancy' &&
                        auth()->user()->hasVendorPermission($record->vendor_id, 'edit_order_items')
                    )
                    ->action(function (AuditSession $record, array $data, AdjustStockAction $adjustStock): void {
                        $finalCount         = (int) $data['manager_override_count'];
                        $reasonCode         = $data['reason_code'];
                        $currentSystemStock = (int) $record->product->stock_quantity;
                        $difference         = $finalCount - $currentSystemStock;
                        $lossValue          = $difference < 0
                            ? abs($difference) * (float) ($record->product->cost_price ?? 0)
                            : 0;

                        if ($difference !== 0) {
                            $adjustStock->execute(
                                productId:       $record->product_id,
                                quantityChanged: $difference,
                                transactionType: 'audit_correction',
                                userId:          auth()->id(),
                                reference:       "Audit Override #{$record->id}",
                                description:     "Manager override forced stock to {$finalCount}. Reason: {$reasonCode}.",
                                auditSessionId:  $record->id,
                                reasonCode:      $reasonCode,
                            );
                        }

                        $record->update([
                            'manager_id'             => auth()->id(),
                            'manager_override_count' => $finalCount,
                            'status'                 => 'resolved_by_override',
                            'reason_code'            => $reasonCode,
                            'loss_value'             => $lossValue,
                        ]);
                    })
                    ->successNotificationTitle('Discrepancy resolved.'),

                // ── Owner attributes the settled variance to someone ─────────────
                //
                // Deliberately separate from resolving. Resolving corrects the
                // shelf figure and is an inventory job; this decides who answers
                // for the loss and whether money is owed, which is the owner's
                // call alone. Keeping them apart also means correcting stock is
                // never held up waiting on a decision about a person.
                Action::make('attribute_shortage')
                    ->label('Attribute')
                    ->icon('heroicon-o-scale')
                    ->color('danger')
                    ->modalHeading('Attribute this variance')
                    ->modalDescription(fn (AuditSession $record): string => $record->system_quantity === null
                        ? 'This count has no recorded baseline, so its variance cannot be attributed.'
                        : sprintf(
                            'Counted %d against a system figure of %d at count time — a variance of %+d unit(s).',
                            $record->countedQuantity(),
                            $record->system_quantity,
                            $record->countedVariance(),
                        ))
                    ->slideOver()
                    ->form([
                        Select::make('storekeeper_id')
                            ->label('Accountable staff member')
                            ->helperText('Leave blank to record the loss against the store without naming anyone.')
                            // No "primary storekeeper" exists in the data model,
                            // so the owner picks explicitly rather than the system
                            // guessing from whoever happened to count.
                            ->options(fn (AuditSession $record): array => User::query()
                                ->whereHas('memberVendors', fn ($q) => $q->where('vendors.id', $record->vendor_id))
                                ->orderBy('name')
                                ->pluck('name', 'id')
                                ->toArray())
                            ->searchable()
                            ->placeholder('Nobody — record against the store'),

                        Select::make('disposition')
                            ->label('What happens to the value')
                            ->options([
                                'written_off' => 'Write off as a business loss',
                                'recoverable' => 'Recoverable — the staff member owes it',
                                'recorded'    => 'Record only, no financial effect',
                            ])
                            ->default('written_off')
                            ->required()
                            ->live()
                            ->helperText(fn ($state): string => match ($state) {
                                'recoverable' => 'Adds to what this person owes until it is settled or reversed.',
                                'recorded'    => 'Keeps the record with no amount attached.',
                                default       => 'Absorbed by the business. No cash moves, so nothing posts to a bank or cash account.',
                            }),

                        Select::make('reason_code')
                            ->label('Reason')
                            ->options([
                                'Damaged in Store'        => 'Damaged in Store',
                                'Suspected Theft'         => 'Suspected Theft',
                                'Waybill Shortage'        => 'Waybill Shortage',
                                'Data Entry Error'        => 'Data Entry Error',
                                'Supplier Short Delivery' => 'Supplier Short Delivery',
                                'Other'                   => 'Other',
                            ])
                            ->searchable(),

                        Textarea::make('note')->label('Note (optional)')->rows(2),
                    ])
                    ->visible(fn (AuditSession $record): bool =>
                        $record->isSettled()
                        && $record->system_quantity !== null
                        && (int) $record->countedVariance() !== 0
                        && static::canAttribute($record)
                    )
                    ->action(function (AuditSession $record, array $data, StockAccountabilityLedger $ledger): void {
                        try {
                            $ledger->attribute(
                                audit: $record,
                                disposition: $data['disposition'],
                                storekeeperId: $data['storekeeper_id'] ? (int) $data['storekeeper_id'] : null,
                                resolvedBy: (int) auth()->id(),
                                reasonCode: $data['reason_code'] ?? $record->reason_code,
                                note: $data['note'] ?? null,
                            );

                            Notification::make()->title('Variance attributed.')->success()->send();
                        } catch (\Throwable $e) {
                            Notification::make()->title($e->getMessage())->danger()->send();
                        }
                    }),

            ])
            ->defaultSort('created_at', 'desc')
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make()
                        ->visible(fn () => auth()->user()->isSuperAdmin() || filament()->getTenant()?->isOwner(auth()->user())),
                ]),
            ]);
    }

    // Deciding that a named person owes money is an owner's call, not a
    // delegable inventory permission — so this is not wired to a Spatie
    // permission that could be handed to a manager from the Roles screen.
    public static function canAttribute(AuditSession $record): bool
    {
        $user = auth()->user();

        if (! $user) {
            return false;
        }

        return $user->isSuperAdmin() || $record->vendor?->isOwner($user) === true;
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ManageAuditSessions::route('/'),
        ];
    }
}

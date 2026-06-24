<?php

declare(strict_types=1);

namespace App\Filament\Vendor\Resources\AuditSessions;

use App\Filament\Vendor\Resources\AuditSessions\AuditSessionResource\Pages;
use App\Models\AuditSession;
use App\Models\User;
use App\Actions\Inventory\ProcessAuditCountAction;
use App\Actions\Inventory\AdjustStockAction;
use Filament\Resources\Resource;
use Filament\Tables\Table;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
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
        return $vendor && $user->hasVendorPermission($vendor->id, 'manage_inventory');
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
                TextColumn::make('unit_variance')
                    ->label('Unit Variance')
                    ->alignCenter()
                    ->getStateUsing(function (AuditSession $r): string {
                        $counted   = $r->count_b ?? $r->count_a ?? 0;
                        $systemQty = $r->product?->stock_quantity ?? 0;
                        $diff      = (int) $counted - (int) $systemQty;
                        return ($diff > 0 ? '+' : '') . $diff;
                    })
                    ->color(function (AuditSession $r): string {
                        $counted   = $r->count_b ?? $r->count_a ?? 0;
                        $systemQty = $r->product?->stock_quantity ?? 0;
                        $diff      = (int) $counted - (int) $systemQty;
                        return match (true) {
                            $diff < 0 => 'danger',
                            $diff > 0 => 'warning',
                            default   => 'success',
                        };
                    })
                    ->badge(),

                // ── 7. Value at Risk ─────────────────────────────────────────────
                TextColumn::make('value_at_risk')
                    ->label('Value at Risk (₦)')
                    ->alignRight()
                    ->getStateUsing(function (AuditSession $r): string {
                        $counted    = $r->count_b ?? $r->count_a ?? 0;
                        $systemQty  = $r->product?->stock_quantity ?? 0;
                        $diff       = (int) $counted - (int) $systemQty;
                        $costPrice  = (float) ($r->product?->cost_price ?? 0);
                        $value      = $diff * $costPrice;
                        $prefix     = $value < 0 ? '−' : ($value > 0 ? '+' : '');
                        return $prefix . '₦' . number_format(abs($value), 2);
                    })
                    ->color(function (AuditSession $r): string {
                        $counted   = $r->count_b ?? $r->count_a ?? 0;
                        $systemQty = $r->product?->stock_quantity ?? 0;
                        $diff      = (int) $counted - (int) $systemQty;
                        return $diff < 0 ? 'danger' : ($diff > 0 ? 'warning' : 'success');
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
                    ->modalDescription(fn (AuditSession $record): string =>
                        "A counted {$record->count_a}. B counted {$record->count_b}. Enter the correct stock figure."
                    )
                    ->slideOver()
                    ->form([
                        TextInput::make('manager_override_count')
                            ->label('Final Correct Stock')
                            ->numeric()
                            ->required()
                            ->minValue(0),
                    ])
                    ->visible(fn (AuditSession $record): bool =>
                        $record->status === 'discrepancy' &&
                        auth()->user()->hasVendorPermission($record->vendor_id, 'edit_order_items')
                    )
                    ->action(function (AuditSession $record, array $data, AdjustStockAction $adjustStock): void {
                        $finalCount         = (int) $data['manager_override_count'];
                        $currentSystemStock = (int) $record->product->stock_quantity;
                        $difference         = $finalCount - $currentSystemStock;

                        if ($difference !== 0) {
                            $adjustStock->execute(
                                productId:       $record->product_id,
                                quantityChanged: $difference,
                                transactionType: 'audit_correction',
                                userId:          auth()->id(),
                                reference:       "Audit Override #{$record->id}",
                                description:     "Manager override forced stock to {$finalCount}."
                            );
                        }

                        $record->update([
                            'manager_id'             => auth()->id(),
                            'manager_override_count' => $finalCount,
                            'status'                 => 'resolved_by_override',
                        ]);
                    })
                    ->successNotificationTitle('Discrepancy resolved.'),

            ])
            ->defaultSort('created_at', 'desc')
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ManageAuditSessions::route('/'),
        ];
    }
}

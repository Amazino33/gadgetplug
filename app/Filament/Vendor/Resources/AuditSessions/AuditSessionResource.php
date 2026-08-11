<?php

declare(strict_types=1);

namespace App\Filament\Vendor\Resources\AuditSessions;

use App\Filament\Vendor\Resources\AuditSessions\Pages;
use App\Filament\Vendor\Resources\Products\Schemas\ProductForm;
use App\Models\AuditSession;
use App\Models\User;
use App\Actions\Inventory\ProcessAuditCountAction;
use App\Actions\Inventory\AdjustStockAction;
use App\Models\InventoryShortageCase;
use App\Services\ShortageCaseService;
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
use Illuminate\Support\Str;
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

                // ── Shortage case ────────────────────────────────────────────────
                TextColumn::make('case_status')
                    ->label('Case')
                    ->badge()
                    ->getStateUsing(fn (AuditSession $r): string => match (static::caseFor($r)?->status) {
                        'pending_disposition' => 'Awaiting decision',
                        'investigating'       => 'Investigating',
                        'charged'             => 'Charged',
                        'written_off'         => 'Written off',
                        // A balanced line never opens a case, and that is the
                        // normal outcome rather than something missing.
                        default               => 'Balanced',
                    })
                    ->color(fn (AuditSession $r): string => match (static::caseFor($r)?->status) {
                        'pending_disposition' => 'warning',
                        'investigating'       => 'info',
                        'charged'             => 'danger',
                        'written_off'         => 'gray',
                        default               => 'success',
                    }),

                TextColumn::make('charged_to')
                    ->label('Accountable')
                    ->getStateUsing(fn (AuditSession $r): string => static::caseFor($r)?->chargedStorekeeper?->name ?? '—')
                    ->placeholder('—')
                    ->toggleable(),

                // Retail. Safe without the cost permission — it is what the store
                // lost in sales, and discloses nothing about margin on its own.
                TextColumn::make('charge_amount')
                    ->label('Charge (₦)')
                    ->alignRight()
                    ->getStateUsing(function (AuditSession $r): string {
                        $case = static::caseFor($r);

                        return $case ? '₦'.number_format((float) $case->charge_amount, 2) : '—';
                    })
                    ->description(fn (AuditSession $r): ?string => static::caseFor($r)?->price_fallback
                        ? 'charged at cost — no retail price'
                        : null),

                // Cost breakdown. Gated behind the same permission as everywhere
                // else, because cost and margin each give the other away once the
                // retail charge is known.
                TextColumn::make('cost_component')
                    ->label('Cost part (₦)')
                    ->alignRight()
                    ->visible(fn (): bool => ProductForm::canSeeCostPrice())
                    ->getStateUsing(function (AuditSession $r): string {
                        $case = static::caseFor($r);

                        return $case ? '₦'.number_format((float) $case->cost_component, 2) : '—';
                    })
                    ->toggleable(),

                TextColumn::make('margin_component')
                    ->label('Margin part (₦)')
                    ->alignRight()
                    ->visible(fn (): bool => ProductForm::canSeeCostPrice())
                    ->getStateUsing(function (AuditSession $r): string {
                        $case = static::caseFor($r);

                        return $case ? '₦'.number_format((float) $case->margin_component, 2) : '—';
                    })
                    ->toggleable(),

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

                // ── Owner names who carries the loss ─────────────────────────────
                //
                // Separate from disposing because a case opens unattributed: this
                // store has no assigned-storekeeper concept to default to, so
                // somebody must be named before a charge is possible.
                Action::make('assign_accountable')
                    ->label('Assign')
                    ->icon('heroicon-o-user-plus')
                    ->color('gray')
                    ->modalHeading('Who is accountable for this shortage?')
                    ->form([
                        Select::make('storekeeper_id')
                            ->label('Accountable staff member')
                            ->helperText('Leave blank to leave the loss unattributed.')
                            ->options(fn (AuditSession $record): array => User::query()
                                ->whereHas('memberVendors', fn ($q) => $q->where('vendors.id', $record->vendor_id))
                                ->orderBy('name')
                                ->pluck('name', 'id')
                                ->toArray())
                            ->searchable()
                            ->placeholder('Nobody'),
                    ])
                    ->visible(fn (AuditSession $record): bool => static::caseFor($record)?->awaitsDisposition() === true)
                    ->action(function (AuditSession $record, array $data, ShortageCaseService $cases): void {
                        $case = static::caseFor($record);

                        // The real gate. Hiding the button is presentation; this
                        // is what denies a storekeeper who calls the action
                        // directly, and what blocks reassigning a case onto
                        // yourself to route around self-disposition.
                        if (! $case || auth()->user()->cannot('reassign', $case)) {
                            Notification::make()->title('Only the store owner can assign accountability.')->danger()->send();

                            return;
                        }

                        try {
                            $cases->reassign($case, $data['storekeeper_id'] ? (int) $data['storekeeper_id'] : null);
                            Notification::make()->title('Accountability updated.')->success()->send();
                        } catch (\Throwable $e) {
                            Notification::make()->title($e->getMessage())->danger()->send();
                        }
                    }),

                // ── Owner disposes the case ──────────────────────────────────────
                Action::make('dispose_case')
                    ->label('Dispose')
                    ->icon('heroicon-o-scale')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalHeading('Decide what happens to this shortage')
                    ->modalDescription(function (AuditSession $record): string {
                        $case = static::caseFor($record);

                        if (! $case) {
                            return '';
                        }

                        $who = $case->chargedStorekeeper?->name ?? 'nobody yet';

                        return sprintf(
                            '%d unit(s) short, %s at retail. Currently assigned to: %s.',
                            $case->shortage_qty,
                            '₦'.number_format((float) $case->charge_amount, 2),
                            $who,
                        );
                    })
                    ->slideOver()
                    ->form([
                        Select::make('disposition')
                            ->label('Decision')
                            ->options([
                                'write_off'   => 'Write off — the company absorbs it',
                                'charge'      => 'Charge the assigned staff member',
                                'investigate' => 'Investigate — park it, decide later',
                            ])
                            ->required()
                            ->live()
                            ->helperText(fn ($state): string => match ($state) {
                                'charge'      => 'Posts one charge to the accountability ledger at the frozen retail figure.',
                                'investigate' => 'No money moves. Can be charged or written off later.',
                                default       => 'No staff debt. Only the cost is a real loss — the margin was never earned.',
                            }),

                        Textarea::make('reason')
                            ->label('Reason')
                            ->rows(2)
                            // Investigating is a holding position, so it does not
                            // demand a justification the owner may not have yet.
                            ->required(fn ($get): bool => in_array($get('disposition'), ['write_off', 'charge'], true)),
                    ])
                    ->visible(fn (AuditSession $record): bool => static::caseFor($record)?->awaitsDisposition() === true)
                    ->action(function (AuditSession $record, array $data, ShortageCaseService $cases): void {
                        $case = static::caseFor($record);

                        if (! $case || auth()->user()->cannot('dispose', $case)) {
                            Notification::make()
                                ->title('You cannot dispose this case.')
                                ->body('Disposition is the store owner\'s decision, and nobody may dispose a shortage charged to themselves.')
                                ->danger()
                                ->send();

                            return;
                        }

                        try {
                            match ($data['disposition']) {
                                'write_off'   => $cases->writeOff($case, (int) auth()->id(), $data['reason']),
                                'charge'      => $cases->charge($case, (int) auth()->id(), $data['reason']),
                                'investigate' => $cases->investigate($case, (int) auth()->id(), $data['reason'] ?? null),
                            };

                            Notification::make()->title('Case disposed.')->success()->send();
                        } catch (\Throwable $e) {
                            Notification::make()->title($e->getMessage())->danger()->send();
                        }
                    }),

                // ── Owner records money coming back ──────────────────────────────
                //
                // v1 is manual entry throughout. The ordering of the options is
                // the recommended precedence — net against cash the storekeeper
                // already holds before reaching for salary — but nothing here
                // cascades automatically; the owner chooses.
                Action::make('record_recovery')
                    ->label('Record recovery')
                    ->icon('heroicon-o-banknotes')
                    ->color('success')
                    ->modalHeading('Record money recovered')
                    ->modalDescription(function (AuditSession $record): string {
                        $case = static::caseFor($record);

                        return $case
                            ? 'Outstanding on this case: ₦'.number_format(app(ShortageCaseService::class)->outstandingFor($case), 2)
                            : '';
                    })
                    ->slideOver()
                    ->form([
                        Select::make('type')
                            ->label('How was it recovered?')
                            ->options([
                                'recovery_cash'   => 'Cash the storekeeper holds or owes',
                                'recovery_salary' => 'Salary deduction',
                                'recovery_manual' => 'Paid back directly',
                            ])
                            ->default('recovery_cash')
                            ->required()
                            ->helperText('Net against cash held first where possible, then salary for any remainder.'),

                        TextInput::make('amount')
                            ->label('Amount (₦)')
                            ->numeric()
                            ->required()
                            ->minValue(0.01)
                            // Advisory only. The service re-checks inside its
                            // transaction, which is what actually prevents two
                            // concurrent part-payments both slipping through.
                            ->maxValue(fn (AuditSession $record): float => static::caseFor($record)
                                ? app(ShortageCaseService::class)->outstandingFor(static::caseFor($record))
                                : 0)
                            ->helperText('Part payments are fine — the case closes itself once nothing is left.'),

                        Textarea::make('note')->label('Note (optional)')->rows(2),
                    ])
                    ->visible(fn (AuditSession $record): bool => static::caseFor($record)?->status === 'charged')
                    ->action(function (AuditSession $record, array $data, ShortageCaseService $cases): void {
                        $case = static::caseFor($record);

                        if (! $case || auth()->user()->cannot('recordRecovery', $case)) {
                            Notification::make()->title('Only the store owner can record a recovery.')->danger()->send();

                            return;
                        }

                        try {
                            $cases->recover(
                                case: $case,
                                type: $data['type'],
                                amount: (float) $data['amount'],
                                // Keyed on the case and a fresh id, so a
                                // double-submitted form posts once.
                                eventKey: 'case:'.$case->id.':recovery:'.Str::ulid(),
                                recordedBy: (int) auth()->id(),
                                note: $data['note'] ?? null,
                            );

                            Notification::make()->title('Recovery recorded.')->success()->send();
                        } catch (\Throwable $e) {
                            Notification::make()->title($e->getMessage())->danger()->send();
                        }
                    }),

                // ── Owner gives up on the remainder ──────────────────────────────
                Action::make('convert_to_writeoff')
                    ->label('Write off remainder')
                    ->icon('heroicon-o-scissors')
                    ->color('warning')
                    ->requiresConfirmation()
                    ->modalHeading('Write off what is left')
                    ->modalDescription(function (AuditSession $record): string {
                        $case = static::caseFor($record);

                        if (! $case) {
                            return '';
                        }

                        $outstanding = app(ShortageCaseService::class)->outstandingFor($case);

                        return sprintf(
                            'Stops pursuing ₦%s. The company absorbs the unrecovered cost; margin that was never earned is simply not recognised.',
                            number_format($outstanding, 2),
                        );
                    })
                    ->form([
                        Textarea::make('reason')
                            ->label('Why is the remainder unrecoverable?')
                            ->rows(2)
                            ->required(),
                    ])
                    ->visible(fn (AuditSession $record): bool => static::caseFor($record)?->status === 'charged')
                    ->action(function (AuditSession $record, array $data, ShortageCaseService $cases): void {
                        $case = static::caseFor($record);

                        if (! $case || auth()->user()->cannot('recordRecovery', $case)) {
                            Notification::make()->title('Only the store owner can write off a remainder.')->danger()->send();

                            return;
                        }

                        try {
                            $cases->convertToWriteOff(
                                case: $case,
                                eventKey: "case:{$case->id}:writeoff_conversion",
                                recordedBy: (int) auth()->id(),
                                reason: $data['reason'],
                            );

                            Notification::make()->title('Remainder written off.')->success()->send();
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
    /** The shortage case opened for this count line, if the variance was non-zero. */
    public static function caseFor(AuditSession $record): ?InventoryShortageCase
    {
        return InventoryShortageCase::where('count_line_id', $record->id)->first();
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ManageAuditSessions::route('/'),
        ];
    }
}

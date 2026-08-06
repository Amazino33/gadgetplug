<?php

namespace App\Filament\Pages;

use App\Models\Affiliate;
use App\Models\AffiliatePayout;
use App\Models\AffiliateSetting;
use App\Models\WalletTransaction;
use App\Services\Affiliate\WalletService;
use BackedEnum;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use UnitEnum;

// Manual weekly payout, per the money core's locked rule: no payment-provider
// API in v1. This page lists only affiliates currently eligible (available
// balance >= the configured minimum), lets the admin select which of them to
// pay in this run, and for each selected affiliate: creates one
// affiliate_payouts row, writes one ledger debit for their full available
// balance, and marks it paid — all in the same action, matching how this was
// scoped ("generate a batch, write debits, mark paid" as one flow, not two).
class AffiliatePayoutBatch extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string|null|BackedEnum $navigationIcon = 'heroicon-o-banknotes';

    protected static ?string $navigationLabel = 'Payout Batch';

    protected static ?string $title = 'Run Payout Batch';

    protected static ?int $navigationSort = 4;

    protected string $view = 'filament.pages.affiliate-payout-batch';

    public static function getNavigationGroup(): ?string
    {
        return 'Affiliates';
    }

    public static function canAccess(): bool
    {
        return auth()->user()?->hasRole('super_admin') ?? false;
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(Affiliate::query()->where('is_active', true))
            // Eligibility (available balance >= minimum) is a derived value,
            // not a column — filtered in PHP after fetch rather than in SQL.
            // Fine at expected affiliate-program scale; would need a materialized
            // balance column to stay cheap at real scale.
            ->modifyQueryUsing(function (Builder $query) {
                $minPayout = (float) AffiliateSetting::current()->min_payout_amount;
                $walletService = app(WalletService::class);

                $eligibleIds = (clone $query)->get()
                    ->filter(fn (Affiliate $affiliate) => $walletService->availableBalance($affiliate->id) >= $minPayout)
                    ->pluck('id');

                return $query->whereKey($eligibleIds);
            })
            ->columns([
                TextColumn::make('code')->label('Affiliate')->weight('bold'),
                TextColumn::make('user.name')->label('Name'),
                TextColumn::make('user.email')->label('Email'),
                TextColumn::make('available_balance')
                    ->label('Available Balance')
                    ->weight('bold')
                    ->color('success')
                    ->getStateUsing(fn (Affiliate $record) => app(WalletService::class)->availableBalance($record->id))
                    ->formatStateUsing(fn ($state) => '₦' . number_format((float) $state, 2)),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    BulkAction::make('payOut')
                        ->label('Mark Selected as Paid')
                        ->icon('heroicon-o-banknotes')
                        ->color('success')
                        ->requiresConfirmation()
                        ->modalDescription('Pays each selected affiliate their full available balance, writes a debit to their wallet, and marks it paid. This cannot be undone — resend a bank transfer manually if you make a mistake.')
                        ->action(fn (Collection $records) => $this->runBatch($records)),
                ]),
            ]);
    }

    private function runBatch(Collection $affiliates): void
    {
        $walletService = app(WalletService::class);
        $batchReference = 'PAYOUT-' . now()->format('Y-m-d-His');
        $paidCount = 0;
        $totalPaid = 0.0;

        foreach ($affiliates as $affiliate) {
            DB::transaction(function () use ($affiliate, $walletService, $batchReference, &$paidCount, &$totalPaid) {
                // Re-check inside the transaction — the balance could have
                // moved between the table rendering and this action running.
                $balance = $walletService->availableBalance($affiliate->id);

                if ($balance <= 0) {
                    return;
                }

                $payout = AffiliatePayout::create([
                    'affiliate_id'    => $affiliate->id,
                    'batch_reference' => $batchReference,
                    'amount'          => $balance,
                    'status'          => 'paid',
                    'paid_at'         => now(),
                    'paid_by'         => auth()->id(),
                ]);

                WalletTransaction::create([
                    'affiliate_id'        => $affiliate->id,
                    'affiliate_payout_id' => $payout->id,
                    'type'                => 'debit',
                    'amount'              => -$balance,
                    'description'         => "Payout batch {$batchReference} — marked paid.",
                ]);

                $paidCount++;
                $totalPaid += $balance;
            });
        }

        Notification::make()
            ->title($paidCount > 0
                ? "Paid {$paidCount} affiliate(s) — ₦" . number_format($totalPaid, 2) . ' total.'
                : 'Nothing to pay — select at least one affiliate with a positive balance.')
            ->success()
            ->send();
    }
}

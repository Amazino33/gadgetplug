<?php

namespace App\Filament\Resources\Affiliates\Schemas;

use App\Models\Affiliate;
use App\Models\AffiliateLevel;
use App\Models\AffiliateSetting;
use App\Services\Affiliate\AffiliateLevelProgressionService;
use App\Services\Affiliate\WalletService;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class AffiliateInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Level & Progress')
                ->columns(3)
                ->schema([
                    TextEntry::make('level.name')
                        ->label('Current Level')
                        ->badge()
                        ->color('success')
                        ->placeholder('No level yet'),

                    TextEntry::make('lifetime_sales_value')
                        ->label('Lifetime Cleared Sales')
                        ->getStateUsing(fn (Affiliate $record) => '₦' . number_format(
                            app(AffiliateLevelProgressionService::class)->lifetimeSalesValue($record->id), 2
                        )),

                    TextEntry::make('next_level_progress')
                        ->label('Progress to Next Level')
                        ->getStateUsing(function (Affiliate $record) {
                            $lifetimeValue = app(AffiliateLevelProgressionService::class)->lifetimeSalesValue($record->id);
                            $currentSortOrder = $record->level?->sort_order ?? -1;

                            $nextLevel = AffiliateLevel::where('is_active', true)
                                ->where('sort_order', '>', $currentSortOrder)
                                ->orderBy('sort_order')
                                ->first();

                            if (! $nextLevel) {
                                return 'Highest level reached';
                            }

                            $remaining = max((float) $nextLevel->target - $lifetimeValue, 0);

                            return "₦" . number_format($remaining, 2) . " more to reach {$nextLevel->name}";
                        }),

                    TextEntry::make('demotion_risk')
                        ->label('Demotion Risk')
                        ->badge()
                        ->getStateUsing(function (Affiliate $record) {
                            if ($record->level === null) {
                                return null;
                            }

                            $lastActivity = app(AffiliateLevelProgressionService::class)->lastQualifyingActivityAt($record->id)
                                ?? $record->created_at;
                            $windowDays = (int) AffiliateSetting::current()->inactivity_demotion_days;
                            $daysSince = (int) $lastActivity->diffInDays(now());
                            $daysLeft = $windowDays - $daysSince;

                            return $daysLeft <= 0
                                ? 'Overdue for demotion check'
                                : "{$daysLeft} day(s) of inactivity left before demotion";
                        })
                        ->color(function (Affiliate $record) {
                            if ($record->level === null) {
                                return 'gray';
                            }

                            $lastActivity = app(AffiliateLevelProgressionService::class)->lastQualifyingActivityAt($record->id)
                                ?? $record->created_at;
                            $windowDays = (int) AffiliateSetting::current()->inactivity_demotion_days;
                            $daysLeft = $windowDays - (int) $lastActivity->diffInDays(now());

                            return match (true) {
                                $daysLeft <= 0 => 'danger',
                                $daysLeft <= 5 => 'warning',
                                default        => 'success',
                            };
                        })
                        ->visible(fn (Affiliate $record) => $record->level !== null),
                ]),

            Section::make('Affiliate')
                ->columns(3)
                ->schema([
                    TextEntry::make('code')->label('Referral Code')->copyable()->weight('bold'),
                    TextEntry::make('user.name')->label('Name'),
                    TextEntry::make('user.email')->label('Email'),

                    TextEntry::make('is_active')
                        ->label('Status')
                        ->badge()
                        ->formatStateUsing(fn ($state) => $state ? 'Active' : 'Inactive')
                        ->color(fn ($state) => $state ? 'success' : 'gray'),

                    TextEntry::make('created_at')->label('Joined')->dateTime('d M Y'),
                ]),

            Section::make('Wallet')
                ->columns(2)
                ->schema([
                    TextEntry::make('pending_balance')
                        ->label('Pending Balance')
                        ->getStateUsing(fn (Affiliate $record) => '₦' . number_format(
                            app(WalletService::class)->pendingBalance($record->id), 2
                        ))
                        ->helperText('Commissions still held (pending or in the return window) — not yet earned.'),

                    TextEntry::make('available_balance')
                        ->label('Available Balance')
                        ->weight('bold')
                        ->color('success')
                        ->getStateUsing(fn (Affiliate $record) => '₦' . number_format(
                            app(WalletService::class)->availableBalance($record->id), 2
                        ))
                        ->helperText('The only balance that is withdrawable/payable.'),
                ]),
        ]);
    }
}

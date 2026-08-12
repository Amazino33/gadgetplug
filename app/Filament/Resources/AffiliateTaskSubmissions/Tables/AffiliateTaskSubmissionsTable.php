<?php

namespace App\Filament\Resources\AffiliateTaskSubmissions\Tables;

use App\Models\AffiliateReachBand;
use App\Models\AffiliateTaskSubmission;
use App\Services\Affiliate\AffiliateTaskService;
use App\Services\Affiliate\DailySocialShareService;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class AffiliateTaskSubmissionsTable
{
    /**
     * A daily share and an ordinary manual task are reviewed in the same queue
     * but settled by different services — the share resolves a band, a cap and
     * a streak, an ordinary task just credits its flat points. Routing on
     * task_type here keeps both crediting paths locked and idempotent instead
     * of duplicating either.
     */
    private static function isDailyShare(AffiliateTaskSubmission $record): bool
    {
        return $record->task?->task_type === DailySocialShareService::TASK_TYPE;
    }

    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                ImageColumn::make('proof')
                    ->label('')
                    ->getStateUsing(fn (AffiliateTaskSubmission $record): ?string => $record->getFirstMediaUrl('proof', 'thumb') ?: null)
                    ->size(44)
                    ->circular(false),

                TextColumn::make('affiliate.code')->label('Affiliate')->weight('bold')->searchable(),
                TextColumn::make('task.name')->label('Task')->searchable(),

                TextColumn::make('reported_reach')
                    ->label('Reach')
                    ->numeric()
                    ->placeholder('—')
                    // The band this reach WOULD land in, shown before approval
                    // so the reviewer sees what they are about to award rather
                    // than discovering it afterwards.
                    ->description(function (AffiliateTaskSubmission $record): ?string {
                        if ($record->reported_reach === null) {
                            return null;
                        }

                        if ($record->status === 'approved') {
                            return ($record->reachBand?->name ?? 'no band') . ' → ' . $record->points_awarded . ' pts';
                        }

                        $band = AffiliateReachBand::forReach((int) $record->reported_reach);

                        return $band ? "{$band->name} → {$band->points} pts" : 'No matching band → 0 pts';
                    }),

                TextColumn::make('streak_day')
                    ->label('Streak')
                    ->placeholder('—')
                    ->formatStateUsing(fn ($state, AffiliateTaskSubmission $record) => $state
                        ? "Day {$state}" . ($record->streak_bonus_points ? " (+{$record->streak_bonus_points} bonus)" : '')
                        : '—')
                    ->toggleable(),

                TextColumn::make('notes')->label('Notes')->limit(40)->placeholder('—')->toggleable(),

                TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn ($state) => ucfirst($state))
                    ->color(fn ($state) => match ($state) {
                        'submitted' => 'warning',
                        'approved'  => 'success',
                        'rejected'  => 'danger',
                        default     => 'gray',
                    }),

                TextColumn::make('share_date')->label('Share Day')->date('d M Y')->placeholder('—')->sortable()->toggleable(),
                TextColumn::make('submitted_at')->label('Submitted')->dateTime('d M Y, g:ia')->sortable(),
                TextColumn::make('reviewer.name')->label('Reviewed By')->placeholder('—'),
                TextColumn::make('rejected_reason')->label('Rejection Reason')->placeholder('—')->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('submitted_at', 'desc')
            ->filters([
                SelectFilter::make('status')->options(['submitted' => 'Pending', 'approved' => 'Approved', 'rejected' => 'Rejected']),
                SelectFilter::make('affiliate_task_id')->label('Task')->relationship('task', 'name')->searchable(),

                Filter::make('daily_share')
                    ->label('Daily shares only')
                    ->query(fn ($query) => $query->whereHas('task', fn ($q) => $q->where('task_type', DailySocialShareService::TASK_TYPE))),
            ])
            ->recordActions([
                Action::make('approve')
                    ->label('Approve')
                    ->icon('heroicon-o-check-circle')
                    ->color('success')
                    ->requiresConfirmation()
                    ->modalDescription(function (AffiliateTaskSubmission $record): string {
                        if (! self::isDailyShare($record)) {
                            return 'Approve this submission and credit ' . (int) $record->task->points_reward . ' Plug Points?';
                        }

                        $band = AffiliateReachBand::forReach((int) $record->reported_reach);

                        return $band
                            ? "Reach {$record->reported_reach} falls in \"{$band->name}\" — approve and credit {$band->points} Plug Points (subject to today's cap)?"
                            : 'No active band covers this reach, so approving credits 0 points. Continue?';
                    })
                    ->visible(fn (AffiliateTaskSubmission $record) => $record->status === 'submitted')
                    ->action(function (AffiliateTaskSubmission $record) {
                        self::isDailyShare($record)
                            ? app(DailySocialShareService::class)->approve($record, auth()->user())
                            : app(AffiliateTaskService::class)->approve($record, auth()->user());

                        $record->refresh();

                        Notification::make()
                            ->title('Submission approved')
                            ->body($record->points_awarded !== null ? "{$record->points_awarded} Plug Points credited." : null)
                            ->success()
                            ->send();
                    }),

                Action::make('reject')
                    ->label('Reject')
                    ->icon('heroicon-o-x-circle')
                    ->color('danger')
                    ->visible(fn (AffiliateTaskSubmission $record) => $record->status === 'submitted')
                    ->form([
                        Textarea::make('reason')->label('Reason for rejection')->required()->rows(3),
                    ])
                    ->action(function (AffiliateTaskSubmission $record, array $data) {
                        self::isDailyShare($record)
                            ? app(DailySocialShareService::class)->reject($record, auth()->user(), $data['reason'])
                            : app(AffiliateTaskService::class)->reject($record, auth()->user(), $data['reason']);

                        Notification::make()->title('Submission rejected')->warning()->send();
                    }),
            ]);
    }
}

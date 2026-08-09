<?php

namespace App\Filament\Resources\AffiliateTaskSubmissions\Tables;

use App\Models\AffiliateTaskSubmission;
use App\Services\Affiliate\AffiliateTaskService;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class AffiliateTaskSubmissionsTable
{
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

                TextColumn::make('submitted_at')->label('Submitted')->dateTime('d M Y, g:ia')->sortable(),
                TextColumn::make('reviewer.name')->label('Reviewed By')->placeholder('—'),
                TextColumn::make('rejected_reason')->label('Rejection Reason')->placeholder('—')->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('submitted_at', 'desc')
            ->filters([
                SelectFilter::make('status')->options(['submitted' => 'Pending', 'approved' => 'Approved', 'rejected' => 'Rejected']),
                SelectFilter::make('affiliate_task_id')->label('Task')->relationship('task', 'name')->searchable(),
            ])
            ->recordActions([
                Action::make('approve')
                    ->label('Approve')
                    ->icon('heroicon-o-check-circle')
                    ->color('success')
                    ->requiresConfirmation()
                    ->modalDescription(fn (AffiliateTaskSubmission $record) => 'Approve this submission and credit ₦' . number_format((float) $record->task->reward_amount, 2) . ' to the affiliate\'s wallet?')
                    ->visible(fn (AffiliateTaskSubmission $record) => $record->status === 'submitted')
                    ->action(function (AffiliateTaskSubmission $record) {
                        app(AffiliateTaskService::class)->approve($record, auth()->user());

                        Notification::make()->title('Submission approved')->success()->send();
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
                        app(AffiliateTaskService::class)->reject($record, auth()->user(), $data['reason']);

                        Notification::make()->title('Submission rejected')->warning()->send();
                    }),
            ]);
    }
}

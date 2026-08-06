<?php

namespace App\Filament\Resources\Affiliates\RelationManagers;

use App\Models\AffiliateTaskSubmission;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class TaskSubmissionsRelationManager extends RelationManager
{
    protected static string $relationship = 'taskSubmissions';

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                ImageColumn::make('proof')
                    ->label('')
                    ->getStateUsing(fn (AffiliateTaskSubmission $record): ?string => $record->getFirstMediaUrl('proof', 'thumb') ?: null)
                    ->size(40),

                TextColumn::make('task.name')->label('Task')->weight('bold')->searchable(),

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
                TextColumn::make('rejected_reason')->label('Rejection Reason')->placeholder('—')->toggleable(),
            ])
            ->defaultSort('submitted_at', 'desc')
            ->filters([
                SelectFilter::make('status')->options(['submitted' => 'Pending', 'approved' => 'Approved', 'rejected' => 'Rejected']),
            ]);
    }
}

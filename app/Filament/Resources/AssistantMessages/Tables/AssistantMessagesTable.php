<?php

declare(strict_types=1);

namespace App\Filament\Resources\AssistantMessages\Tables;

use App\Actions\Assistant\FlagMessageAction;
use App\Actions\Assistant\UnflagMessageAction;
use App\Models\AssistantMessage;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

class AssistantMessagesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('created_at')
                    ->label('Time')
                    ->dateTime()
                    ->sortable(),

                TextColumn::make('user.name')
                    ->label('Vendor / User')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('store_name_snapshot')
                    ->label('Store / Branch')
                    ->getStateUsing(function (AssistantMessage $record): string {
                        if ($record->store_id !== null) {
                            return $record->store?->name ?? $record->store_name_snapshot ?? 'None';
                        }

                        if (! empty($record->store_name_snapshot)) {
                            return $record->store_name_snapshot.'*';
                        }

                        return 'None';
                    })
                    ->placeholder('None')
                    ->sortable(),

                TextColumn::make('role')
                    ->badge()
                    ->colors([
                        'primary' => 'user',
                        'success' => 'assistant',
                        'warning' => 'system',
                    ])
                    ->sortable(),

                TextColumn::make('content')
                    ->label('Message Content')
                    ->limit(60)
                    ->searchable(),

                IconColumn::make('is_flagged')
                    ->label('Flagged')
                    ->boolean()
                    ->state(fn (AssistantMessage $record): bool => $record->isFlagged())
                    ->trueIcon('heroicon-s-flag')
                    ->falseIcon('heroicon-o-minus')
                    ->trueColor('danger')
                    ->falseColor('gray')
                    ->sortable(query: fn (Builder $query, string $direction) => $query->orderBy('flagged_at', $direction)),

                TextColumn::make('flag_note')
                    ->label('Flag Note')
                    ->limit(40)
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('flaggedBy.name')
                    ->label('Flagged By')
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                SelectFilter::make('user_id')
                    ->label('Vendor / User')
                    ->relationship('user', 'name')
                    ->searchable()
                    ->preload(),

                SelectFilter::make('store_id')
                    ->label('Store')
                    ->relationship('store', 'name')
                    ->searchable()
                    ->preload(),

                SelectFilter::make('role')
                    ->options([
                        'user' => 'User',
                        'assistant' => 'Assistant',
                        'system' => 'System',
                    ]),

                TernaryFilter::make('flagged')
                    ->label('Flagged Status')
                    ->placeholder('All messages')
                    ->trueLabel('Flagged only')
                    ->falseLabel('Unflagged only')
                    ->queries(
                        true: fn (Builder $query) => $query->whereNotNull('flagged_at'),
                        false: fn (Builder $query) => $query->whereNull('flagged_at'),
                    ),

                Filter::make('created_at')
                    ->form([
                        DatePicker::make('created_from')->label('From date'),
                        DatePicker::make('created_until')->label('Until date'),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        return $query
                            ->when(
                                $data['created_from'] ?? null,
                                fn (Builder $q, $date) => $q->whereDate('created_at', '>=', $date),
                            )
                            ->when(
                                $data['created_until'] ?? null,
                                fn (Builder $q, $date) => $q->whereDate('created_at', '<=', $date),
                            );
                    }),
            ])
            ->recordActions([
                ViewAction::make(),

                Action::make('flag')
                    ->label(fn (AssistantMessage $record): string => $record->isFlagged() ? 'Update Flag' : 'Flag')
                    ->icon('heroicon-o-flag')
                    ->color('danger')
                    ->schema([
                        Textarea::make('flag_note')
                            ->label('Flag Note (optional)')
                            ->placeholder('Add an optional note (up to 280 characters)...')
                            ->maxLength(280),
                    ])
                    ->fillForm(fn (AssistantMessage $record): array => [
                        'flag_note' => $record->flag_note,
                    ])
                    ->action(function (AssistantMessage $record, array $data): void {
                        (new FlagMessageAction)->execute(
                            auth()->user(),
                            $record,
                            $data['flag_note'] ?? null
                        );

                        Notification::make()
                            ->title('Message flagged')
                            ->success()
                            ->send();
                    }),

                Action::make('unflag')
                    ->label('Unflag')
                    ->icon('heroicon-o-x-mark')
                    ->color('gray')
                    ->visible(fn (AssistantMessage $record): bool => $record->isFlagged())
                    ->requiresConfirmation()
                    ->modalHeading('Unflag Assistant Message')
                    ->modalDescription('Are you sure you want to remove the flag and note from this message?')
                    ->action(function (AssistantMessage $record): void {
                        (new UnflagMessageAction)->execute(auth()->user(), $record);

                        Notification::make()
                            ->title('Message unflagged')
                            ->success()
                            ->send();
                    }),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    BulkAction::make('bulk_flag')
                        ->label('Flag Selected')
                        ->icon('heroicon-o-flag')
                        ->color('danger')
                        ->schema([
                            Textarea::make('flag_note')
                                ->label('Flag Note (optional)')
                                ->placeholder('Add an optional note applied to all selected messages...')
                                ->maxLength(280),
                        ])
                        ->action(function (Collection $records, array $data): void {
                            $action = new FlagMessageAction;
                            $actor = auth()->user();

                            foreach ($records as $record) {
                                $action->execute($actor, $record, $data['flag_note'] ?? null);
                            }

                            Notification::make()
                                ->title('Selected messages flagged')
                                ->success()
                                ->send();
                        }),

                    BulkAction::make('bulk_unflag')
                        ->label('Unflag Selected')
                        ->icon('heroicon-o-x-mark')
                        ->color('gray')
                        ->requiresConfirmation()
                        ->modalHeading('Unflag Selected Messages')
                        ->modalDescription('Are you sure you want to remove flags from all selected messages?')
                        ->action(function (Collection $records): void {
                            $action = new UnflagMessageAction;
                            $actor = auth()->user();

                            foreach ($records as $record) {
                                if ($record->isFlagged()) {
                                    $action->execute($actor, $record);
                                }
                            }

                            Notification::make()
                                ->title('Selected messages unflagged')
                                ->success()
                                ->send();
                        }),
                ]),
            ]);
    }
}

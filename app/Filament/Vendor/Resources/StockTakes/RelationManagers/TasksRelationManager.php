<?php

namespace App\Filament\Vendor\Resources\StockTakes\RelationManagers;

use App\Models\Aisle;
use App\Models\StockTakeTask;
use App\Services\StockTake\StockTakeDevice;
use App\Services\StockTake\StockTakePlanner;
use Filament\Actions\Action;
use Filament\Forms\Components\Checkbox;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\On;

/** The stock take's tasks: who has which aisles, by when, and where each stands. */
class TasksRelationManager extends RelationManager
{
    protected static string $relationship = 'tasks';

    protected static ?string $title = 'Tasks';

    #[On('stock-take-updated')]
    public function refreshTasks(): void {}

    /** On the View page, but tasks can still be cancelled from here. */
    public function isReadOnly(): bool
    {
        return false;
    }

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['assignee:id,name', 'aisles'])->withCount('products'))
            ->defaultSort('created_at', 'desc')
            ->emptyStateHeading('No tasks yet')
            ->emptyStateDescription('Use New count task at the top to give someone aisles to count.')
            ->columns([
                TextColumn::make('type')->badge()
                    ->formatStateUsing(fn ($state) => match ($state) {
                        StockTakeTask::TYPE_RECOUNT => 'Recount',
                        StockTakeTask::TYPE_MAPPING => 'Mapping',
                        default                     => 'Count',
                    })
                    ->color(fn ($state) => $state === StockTakeTask::TYPE_RECOUNT ? 'warning' : 'gray'),
                TextColumn::make('assignee.name')->label('Given to')->weight('bold'),
                TextColumn::make('covers')
                    ->label('Aisles')
                    ->state(fn (StockTakeTask $record) => $record->aisles->isNotEmpty()
                        ? $record->aisles->sortBy('sort_order')->map(fn (Aisle $a) => $a->label())->implode(', ')
                        : "{$record->products_count} products")
                    ->wrap(),
                TextColumn::make('due_date')->label('Due')->date('D d M')->placeholder('—')
                    ->badge(fn (StockTakeTask $record) => $this->overdue($record))
                    ->color(fn (StockTakeTask $record) => $this->overdue($record) ? 'danger' : null)
                    ->description(fn (StockTakeTask $record) => $this->overdue($record) ? 'Overdue' : null),
                TextColumn::make('status')->badge()
                    ->formatStateUsing(fn ($state) => match ($state) {
                        StockTakeTask::STATUS_ASSIGNED           => 'Not started',
                        StockTakeTask::STATUS_IN_PROGRESS        => 'Counting',
                        StockTakeTask::STATUS_SUBMITTED          => 'Submitted',
                        StockTakeTask::STATUS_APPROVED           => 'Approved',
                        StockTakeTask::STATUS_PARTIALLY_APPROVED => 'Partly approved',
                        StockTakeTask::STATUS_CANCELLED          => 'Cancelled',
                        default                                  => (string) $state,
                    })
                    ->color(fn ($state) => match ($state) {
                        StockTakeTask::STATUS_IN_PROGRESS                                  => 'warning',
                        StockTakeTask::STATUS_SUBMITTED                                    => 'info',
                        StockTakeTask::STATUS_APPROVED, StockTakeTask::STATUS_PARTIALLY_APPROVED => 'success',
                        default                                                            => 'gray',
                    }),
                // One phone per task (release 2): which phone holds it, and what it
                // had not yet uploaded when last seen.
                TextColumn::make('device_label')
                    ->label('Phone')
                    ->placeholder('—')
                    ->state(fn (StockTakeTask $record) => $record->device_id === null ? null : ($record->device_label ?? 'A phone'))
                    ->description(fn (StockTakeTask $record) => $record->device_id === null ? null : collect([
                        $record->device_seen_at ? 'Seen ' . $record->device_seen_at->diffForHumans() : null,
                        (int) $record->device_pending > 0 ? "{$record->device_pending} not uploaded" : 'All uploaded',
                    ])->filter()->implode(' · ')),
                TextColumn::make('submitted_at')->label('Submitted')->dateTime('D d M, g:ia', 'Africa/Lagos')->placeholder('—'),
            ])
            ->recordActions([
                Action::make('releasePhone')
                    ->label('Release phone')
                    ->color('warning')
                    ->icon('heroicon-o-device-phone-mobile')
                    ->visible(fn (StockTakeTask $record) => $record->isOpen() && $record->device_id !== null)
                    ->modalHeading('Let another phone carry on with this task?')
                    ->modalDescription(fn (StockTakeTask $record) => (int) $record->device_pending > 0
                        ? "When last seen, that phone ({$record->device_label}) still had {$record->device_pending} count(s) not uploaded. If it can get signal, open the task on it first: they upload by themselves. Released now, they are lost."
                        : 'Everything counted on that phone has reached the server. The next phone to open the task carries on from there.')
                    ->schema(fn (StockTakeTask $record) => (int) $record->device_pending > 0 ? [
                        Checkbox::make('accept_loss')
                            ->label("I understand: the {$record->device_pending} count(s) not uploaded from that phone will be lost")
                            ->accepted(),
                    ] : [])
                    ->modalSubmitActionLabel('Release')
                    ->action(function (StockTakeTask $record, array $data) {
                        try {
                            app(StockTakeDevice::class)->release(auth()->user(), $record, (bool) ($data['accept_loss'] ?? false));
                        } catch (ValidationException|AuthorizationException $e) {
                            Notification::make()->title('Not released')->body($e instanceof ValidationException ? collect($e->errors())->flatten()->first() : $e->getMessage())->danger()->send();

                            return;
                        }

                        Notification::make()->title('Released')->body('The task can now be opened on another phone.')->success()->send();
                        $this->dispatch('stock-take-updated');
                    }),
                Action::make('cancel')
                    ->label('Cancel')
                    ->color('danger')
                    ->icon('heroicon-o-x-circle')
                    ->visible(fn (StockTakeTask $record) => $record->isOpen())
                    ->requiresConfirmation()
                    ->modalDescription('Its aisles become free for another task. Anything already counted on it stays on record but does not count as counted.')
                    ->action(function (StockTakeTask $record) {
                        try {
                            app(StockTakePlanner::class)->cancelTask(auth()->user(), $record);
                        } catch (ValidationException|AuthorizationException $e) {
                            Notification::make()->title('Not done')->body($e instanceof ValidationException ? collect($e->errors())->flatten()->first() : $e->getMessage())->danger()->send();

                            return;
                        }

                        Notification::make()->title('Task cancelled')->success()->send();
                        $this->dispatch('stock-take-updated');
                    }),
            ]);
    }

    private function overdue(StockTakeTask $task): bool
    {
        return $task->isOpen() && $task->due_date !== null && $task->due_date->isBefore(\App\Support\Pos\BusinessDate::today());
    }
}

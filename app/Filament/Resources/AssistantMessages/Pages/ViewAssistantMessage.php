<?php

declare(strict_types=1);

namespace App\Filament\Resources\AssistantMessages\Pages;

use App\Actions\Assistant\FlagMessageAction;
use App\Actions\Assistant\UnflagMessageAction;
use App\Filament\Resources\AssistantMessages\AssistantMessageResource;
use App\Models\AssistantMessage;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Collection;

class ViewAssistantMessage extends ViewRecord
{
    protected static string $resource = AssistantMessageResource::class;

    protected string $view = 'filament.resources.assistant-messages.pages.view-assistant-message';

    public function getTitle(): string|Htmlable
    {
        return 'Message #'.$this->record->id.' Context';
    }

    /**
     * @return Collection<int, AssistantMessage>
     */
    public function getSurroundingMessagesProperty(): Collection
    {
        $current = $this->record;

        // 20 messages before in the same thread
        $before = AssistantMessage::query()
            ->with(['user', 'store'])
            ->where('thread_id', $current->thread_id)
            ->where('id', '<', $current->id)
            ->orderBy('id', 'desc')
            ->limit(20)
            ->get()
            ->reverse();

        // 20 messages after in the same thread
        $after = AssistantMessage::query()
            ->with(['user', 'store'])
            ->where('thread_id', $current->thread_id)
            ->where('id', '>', $current->id)
            ->orderBy('id', 'asc')
            ->limit(20)
            ->get();

        return $before->concat([$current])->concat($after);
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('flag')
                ->label(fn (): string => $this->record->isFlagged() ? 'Update Flag' : 'Flag Message')
                ->icon('heroicon-o-flag')
                ->color('danger')
                ->schema([
                    Textarea::make('flag_note')
                        ->label('Flag Note (optional)')
                        ->placeholder('Add an optional note (up to 280 characters)...')
                        ->maxLength(280),
                ])
                ->fillForm(fn (): array => [
                    'flag_note' => $this->record->flag_note,
                ])
                ->action(function (array $data): void {
                    (new FlagMessageAction)->execute(
                        auth()->user(),
                        $this->record,
                        $data['flag_note'] ?? null
                    );

                    $this->record->refresh();

                    Notification::make()
                        ->title('Message flagged')
                        ->success()
                        ->send();
                }),

            Action::make('unflag')
                ->label('Unflag Message')
                ->icon('heroicon-o-x-mark')
                ->color('gray')
                ->visible(fn (): bool => $this->record->isFlagged())
                ->requiresConfirmation()
                ->action(function (): void {
                    (new UnflagMessageAction)->execute(auth()->user(), $this->record);

                    $this->record->refresh();

                    Notification::make()
                        ->title('Message unflagged')
                        ->success()
                        ->send();
                }),
        ];
    }
}

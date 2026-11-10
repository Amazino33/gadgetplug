<?php

declare(strict_types=1);

namespace App\Filament\Resources\AssistantUnansweredQuestions;

use App\Enums\Assistant\AnswerStatus;
use App\Filament\Resources\AssistantUnansweredQuestions\Pages\ListAssistantUnansweredQuestions;
use App\Models\AssistantUnansweredQuestion;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Phase 1.3: the questions Ask GadgetPlug could not answer (no tool for it,
 * tools or the model failed, or the reply could not be phrased), for the
 * platform admin to review: what people want that the assistant lacks, and
 * what is breaking. Platform staff only, like the other Assistant screens.
 *
 * Filament fills closure arguments by name: $query, $record, $state, $data.
 */
class AssistantUnansweredQuestionResource extends Resource
{
    protected static ?string $model = AssistantUnansweredQuestion::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-question-mark-circle';

    protected static ?string $navigationLabel = 'Unanswered';

    protected static ?string $modelLabel = 'unanswered question';

    protected static ?int $navigationSort = 2;

    public static function getNavigationGroup(): ?string
    {
        return 'Assistant';
    }

    public static function canAccess(): bool
    {
        return auth()->user()?->isSuperAdmin() ?? false;
    }

    public static function shouldRegisterNavigation(): bool
    {
        return static::canAccess();
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function canDeleteAny(): bool
    {
        return false;
    }

    public static function getNavigationBadge(): ?string
    {
        $open = AssistantUnansweredQuestion::query()->whereNull('reviewed_at')->count();

        return $open > 0 ? (string) $open : null;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['vendor:id,name', 'user:id,name']))
            ->columns([
                TextColumn::make('created_at')
                    ->label('Asked')
                    ->dateTime('d M Y, H:i')
                    ->timezone((string) config('reporting.timezone', 'Africa/Lagos'))
                    ->sortable(),
                TextColumn::make('vendor.name')->label('Business')->placeholder('-')->searchable(),
                TextColumn::make('user.name')->label('Asked by')->searchable(),
                TextColumn::make('question')->wrap()->limit(160)->searchable(),
                TextColumn::make('reason')
                    ->badge()
                    ->formatStateUsing(fn (AnswerStatus $state): string => $state->label())
                    ->color(fn (AnswerStatus $state): string => $state === AnswerStatus::CannotAnswer ? 'warning' : 'danger'),
                TextColumn::make('answer')->label('Reply given')->wrap()->limit(120)->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('detail')->wrap()->limit(200)->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('reviewed_at')
                    ->label('Reviewed')
                    ->since()
                    ->placeholder('Open')
                    ->description(fn (AssistantUnansweredQuestion $record): ?string => $record->review_note ? mb_strimwidth($record->review_note, 0, 80, '...') : null),
            ])
            ->filters([
                TernaryFilter::make('reviewed')
                    ->label('Status')
                    ->placeholder('All')
                    ->trueLabel('Reviewed')
                    ->falseLabel('Open')
                    ->default(false)
                    ->queries(
                        true: fn (Builder $query) => $query->whereNotNull('reviewed_at'),
                        false: fn (Builder $query) => $query->whereNull('reviewed_at'),
                    ),
                SelectFilter::make('reason')
                    ->options(collect(AnswerStatus::cases())
                        ->filter(fn (AnswerStatus $s): bool => $s->isUnanswered())
                        ->mapWithKeys(fn (AnswerStatus $s): array => [$s->value => $s->label()])
                        ->all()),
                SelectFilter::make('vendor_id')->label('Business')->relationship('vendor', 'name')->searchable(),
            ])
            ->recordActions([
                Action::make('review')
                    ->label(fn (AssistantUnansweredQuestion $record): string => $record->reviewed_at ? 'Edit note' : 'Mark reviewed')
                    ->icon('heroicon-o-check')
                    ->schema([
                        Textarea::make('review_note')
                            ->label('Note (optional)')
                            ->rows(3)
                            ->maxLength(1000)
                            ->placeholder('e.g. needs a tool for transfers'),
                    ])
                    ->fillForm(fn (AssistantUnansweredQuestion $record): array => ['review_note' => $record->review_note])
                    ->action(function (AssistantUnansweredQuestion $record, array $data): void {
                        $record->update([
                            'reviewed_at' => $record->reviewed_at ?? now(),
                            'reviewed_by' => $record->reviewed_by ?? auth()->id(),
                            'review_note' => $data['review_note'] ?? null,
                        ]);

                        Notification::make()->title('Marked reviewed')->success()->send();
                    }),
                Action::make('reopen')
                    ->label('Reopen')
                    ->icon('heroicon-o-arrow-uturn-left')
                    ->color('gray')
                    ->visible(fn (AssistantUnansweredQuestion $record): bool => $record->reviewed_at !== null)
                    ->action(fn (AssistantUnansweredQuestion $record) => $record->update(['reviewed_at' => null, 'reviewed_by' => null])),
            ])
            ->defaultSort('created_at', 'desc');
    }

    public static function getPages(): array
    {
        return [
            'index' => ListAssistantUnansweredQuestions::route('/'),
        ];
    }
}

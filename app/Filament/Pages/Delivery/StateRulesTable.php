<?php

namespace App\Filament\Pages\Delivery;

use App\Filament\Pages\DeliverySettings;
use App\Models\DeliveryRule;
use App\Models\State;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Notifications\Notification;
use Filament\Support\Enums\FontWeight;
use Filament\Support\Enums\Size;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\On;

/**
 * Rules by state: each state's default and how many of its LGAs we deliver to.
 * A state opens in a slide-over listing its LGAs, where they are edited one by
 * one or in bulk.
 */
class StateRulesTable extends TableWidget
{
    protected int|string|array $columnSpan = 'full';

    // Rendered with the page, not when scrolled to: a blank card where a table
    // belongs reads as broken.
    protected static bool $isLazy = false;

    protected static ?string $heading = 'Rules by state and LGA';

    public static function canView(): bool
    {
        return DeliverySettings::canAccess();
    }

    public function boot(): void
    {
        DeliveryRuleForm::authorize();
    }

    #[On('delivery-rules-changed')]
    public function refreshRules(): void
    {
        // Re-rendering reloads the counts.
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(
                State::query()
                    ->with('defaultRule')
                    ->withCount([
                        'lgas',
                        'overrideRules',
                        'overrideRules as delivered_overrides_count' => fn (Builder $query) => $query->where('mode', '!=', DeliveryRule::NOT_DELIVERED),
                    ])
            )
            ->description('A state\'s default applies to all its LGAs; an LGA override beats it. Tap a state to see its LGAs.')
            ->defaultSort('name')
            ->paginated([10, 25, 50, 'all'])
            ->defaultPaginationPageOption(10)
            ->searchPlaceholder('Search state or LGA')
            ->recordAction('lgas')
            ->columns([
                TextColumn::make('name')
                    ->label('State')
                    ->weight(FontWeight::SemiBold)
                    ->sortable()
                    // Finding an LGA finds its state.
                    ->searchable(query: fn (Builder $query, string $search): Builder => $query
                        ->where('name', 'like', "%{$search}%")
                        ->orWhereHas('lgas', fn (Builder $lgas) => $lgas->where('name', 'like', "%{$search}%")))
                    ->description(fn (State $record): string => self::deliveredCount($record).' of '.$record->lgas_count.' delivered'),

                TextColumn::make('default_rule')
                    ->label('Default')
                    ->badge()
                    ->state(fn (State $record): string => DeliveryRule::describe($record->defaultRule?->mode, $record->defaultRule?->fee))
                    ->color(fn (State $record): string => DeliveryRule::COLORS[$record->defaultRule?->mode ?? DeliveryRule::NOT_DELIVERED]),

                TextColumn::make('override_rules_count')
                    ->label('Overrides')
                    ->badge()
                    ->color(fn (int $state): string => $state > 0 ? 'info' : 'gray')
                    ->formatStateUsing(fn (int $state): string => $state === 0 ? 'None' : (string) $state)
                    ->visibleFrom('sm'),
            ])
            // Tapping a row opens its LGAs; both actions also sit in one menu,
            // which is all that fits beside the badge on a phone.
            ->recordActions([
                ActionGroup::make([
                    Action::make('lgas')
                        ->label('View LGAs')
                        ->icon('heroicon-m-list-bullet')
                        ->slideOver()
                        ->modalHeading(fn (State $record): string => $record->name.' — LGAs')
                        ->modalDescription('Distances are straight-line from the nearest store; road distance is longer.')
                        ->modalContent(fn (State $record) => view('filament.admin.delivery.lga-rules', ['stateId' => $record->id]))
                        ->modalSubmitAction(false)
                        ->modalCancelActionLabel('Close'),

                    Action::make('editDefault')
                        ->label('Edit default rule')
                        ->icon('heroicon-m-pencil-square')
                        ->color('gray')
                        ->modalHeading(fn (State $record): string => $record->name.' — default rule')
                        ->modalDescription('Applies to every LGA in the state that has no override.')
                        ->modalWidth('md')
                        ->fillForm(fn (State $record): array => DeliveryRuleForm::fill([
                            'mode'          => $record->defaultRule?->mode ?? DeliveryRule::NOT_DELIVERED,
                            'fee'           => (float) ($record->defaultRule?->fee ?? 0),
                            'expected_cost' => $record->defaultRule?->expected_cost !== null ? (float) $record->defaultRule->expected_cost : null,
                            'delivery_days' => $record->defaultRule?->delivery_days,
                        ]))
                        ->schema(DeliveryRuleForm::fields())
                        ->action(function (State $record, array $data): void {
                            DeliveryRuleForm::authorize();
                            DeliveryRule::setForState($record->id, DeliveryRuleForm::validate($data));

                            Notification::make()->title('Default saved for '.$record->name.'.')->success()->send();
                            $this->dispatch('delivery-rules-changed');
                        }),
                ])
                    ->label('Actions')
                    ->iconButton()
                    ->size(Size::Large),
            ])
            ->emptyStateHeading('No states match')
            ->emptyStateIcon('heroicon-o-map');
    }

    /** LGAs that end up delivered: the default's reach, adjusted by overrides. */
    private static function deliveredCount(State $state): int
    {
        $overrides = (int) $state->override_rules_count;
        $deliveredOverrides = (int) $state->delivered_overrides_count;
        $defaultDelivers = ($state->defaultRule?->mode ?? DeliveryRule::NOT_DELIVERED) !== DeliveryRule::NOT_DELIVERED;

        return $defaultDelivers
            ? $state->lgas_count - ($overrides - $deliveredOverrides)
            : $deliveredOverrides;
    }
}

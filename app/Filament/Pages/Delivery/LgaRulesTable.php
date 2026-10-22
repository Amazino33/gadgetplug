<?php

namespace App\Filament\Pages\Delivery;

use App\Filament\Pages\DeliverySettings;
use App\Models\DeliveryRule;
use App\Models\Lga;
use App\Services\Delivery\DeliveryDistance;
use App\Support\Naira;
use App\Support\Plural;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Notifications\Notification;
use Filament\Support\Enums\FontWeight;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\On;

/** One state's LGAs and the rule each follows, opened from the rules table. */
class LgaRulesTable extends TableWidget
{
    public int $stateId;

    protected int|string|array $columnSpan = 'full';

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
        $this->resetTable();
    }

    public function table(Table $table): Table
    {
        $distance = app(DeliveryDistance::class);

        return $table
            // The slide-over already names the state.
            ->heading(null)
            // Phone styles in the admin theme; tapping a row edits it.
            ->extraAttributes(['class' => 'gp-lga-rules'])
            ->query(Lga::query()->where('state_id', $this->stateId)->with(['overrideRule', 'state.defaultRule']))
            ->defaultSort('name')
            ->recordAction('edit')
            ->paginated(false)
            ->columns([
                TextColumn::make('name')
                    ->label('LGA')
                    ->weight(FontWeight::SemiBold)
                    ->searchable()
                    ->sortable()
                    ->wrap()
                    ->description(fn (Lga $lga): string => collect([
                        ($km = $distance->kmFromNearestHub($lga)) === null ? 'No hub pinned' : $km.' km straight-line',
                        // The Override column is hidden on a phone; say it here instead.
                        $lga->overrideRule ? 'override' : null,
                    ])->filter()->implode(' · ')),

                TextColumn::make('rule')
                    ->label('Rule')
                    ->badge()
                    ->state(fn (Lga $lga): string => DeliveryRule::describe($lga->effectiveRule()['mode'], $lga->effectiveRule()['fee']))
                    ->color(fn (Lga $lga): string => DeliveryRule::COLORS[$lga->effectiveRule()['mode']]),

                TextColumn::make('source')
                    ->label('')
                    ->badge()
                    ->state(fn (Lga $lga): ?string => $lga->overrideRule ? 'Override' : null)
                    ->color('info')
                    ->visibleFrom('sm'),

                TextColumn::make('expected_cost')
                    ->label('Expected cost')
                    ->state(fn (Lga $lga): string => Naira::format($lga->effectiveRule()['expected_cost']))
                    ->visibleFrom('md'),

                TextColumn::make('days')
                    ->label('Days')
                    ->state(fn (Lga $lga): string => $lga->effectiveRule()['delivery_days'] ?? '—')
                    ->visibleFrom('md'),
            ])
            ->recordActions([
                Action::make('edit')
                    ->label('Edit')
                    ->icon('heroicon-m-pencil-square')
                    ->iconButton()
                    ->modalHeading(fn (Lga $record): string => $record->name.' — delivery rule')
                    ->modalDescription(fn (Lga $record): string => $record->overrideRule
                        ? 'This LGA has its own rule (an override).'
                        : 'Now following the '.$record->state->name.' default. Saving gives it its own rule.')
                    ->modalWidth('md')
                    ->fillForm(fn (Lga $record): array => DeliveryRuleForm::fill($record->effectiveRule()))
                    ->schema(DeliveryRuleForm::fields())
                    ->action(function (Lga $record, array $data): void {
                        DeliveryRuleForm::authorize();
                        DeliveryRule::setForLga($record, DeliveryRuleForm::validate($data));

                        $this->changed('Saved for '.$record->name.'.');
                    }),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    BulkAction::make('setRule')
                        ->label('Set rule for selected')
                        ->icon('heroicon-m-pencil-square')
                        ->modalHeading('Set the rule for the selected LGAs')
                        ->modalWidth('md')
                        ->fillForm(['mode' => DeliveryRule::FEE])
                        ->schema(DeliveryRuleForm::fields())
                        ->action(function (Collection $records, array $data): void {
                            DeliveryRuleForm::authorize();
                            $values = DeliveryRuleForm::validate($data);
                            $records->each(fn (Lga $lga) => DeliveryRule::setForLga($lga, $values));

                            $this->changed($records->count().' '.Plural::lgas($records->count()).' updated.');
                        })
                        ->deselectRecordsAfterCompletion(),

                    BulkAction::make('clearOverrides')
                        ->label('Clear overrides')
                        ->icon('heroicon-m-arrow-uturn-left')
                        ->color('danger')
                        ->requiresConfirmation()
                        ->modalHeading('Clear the selected overrides?')
                        ->modalDescription('Those LGAs go back to following their state\'s default rule.')
                        ->modalSubmitActionLabel('Clear overrides')
                        ->action(function (Collection $records): void {
                            DeliveryRuleForm::authorize();

                            // Through the model, so each removal is activity-logged.
                            DeliveryRule::whereIn('lga_id', $records->modelKeys())->get()->each->delete();

                            $this->changed('Overrides cleared — those LGAs follow their state again.');
                        })
                        ->deselectRecordsAfterCompletion(),
                ]),
            ])
            ->emptyStateHeading('No LGAs match')
            ->emptyStateIcon('heroicon-o-map');
    }

    private function changed(string $message): void
    {
        Notification::make()->title($message)->success()->send();
        $this->dispatch('delivery-rules-changed');
    }
}

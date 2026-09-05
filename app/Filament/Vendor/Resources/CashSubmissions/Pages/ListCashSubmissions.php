<?php

namespace App\Filament\Vendor\Resources\CashSubmissions\Pages;

use App\Actions\Cash\ResolveCashSubmissionAction;
use App\Actions\Cash\SubmitCashAction;
use App\Filament\Vendor\Resources\CashSubmissions\CashSubmissionResource;
use App\Models\CashSubmission;
use App\Models\User;
use App\Services\ActiveStore;
use App\Services\Cash\CashDrawer;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Support\HtmlString;
use Throwable;

class ListCashSubmissions extends ListRecords
{
    protected static string $resource = CashSubmissionResource::class;

    public function getSubheading(): ?HtmlString
    {
        $vendor = filament()->getTenant();
        $storeId = ActiveStore::currentId() ?? $vendor->defaultStore?->id;

        if (! $storeId) {
            return null;
        }

        $holding = CashDrawer::expectedFrom($vendor->id, $storeId, auth()->id());

        return new HtmlString(
            $holding > 0
                ? 'You are holding <strong>₦'.number_format($holding, 2).'</strong> in takings that have not been handed over.'
                : 'You are not holding any takings.'
        );
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('submit')
                ->label('Submit cash')
                ->icon('heroicon-o-arrow-up-tray')
                ->color('primary')
                ->visible(fn () => $this->canSubmit())
                ->schema([
                    Select::make('received_by')
                        ->label('Handing it to')
                        ->options(fn () => $this->possibleReceivers())
                        ->required()
                        ->searchable(),

                    TextInput::make('amount')
                        ->label('Amount handed over')
                        ->numeric()
                        ->required()
                        ->prefix('₦')
                        ->default(fn () => $this->expectedNow())
                        ->helperText(fn () => 'The system expects ₦'.number_format($this->expectedNow(), 2).'.'),

                    Textarea::make('reason')
                        ->label('Why the difference?')
                        ->rows(2)
                        // Asked for only when it is needed, and required then:
                        // a difference has to be explained by the person who
                        // knows what happened, while they are still standing
                        // there.
                        ->visible(fn (Get $get) => abs((float) $get('amount') - $this->expectedNow()) >= 0.01)
                        ->required(fn (Get $get) => abs((float) $get('amount') - $this->expectedNow()) >= 0.01),
                ])
                ->action(function (array $data) {
                    $vendor = filament()->getTenant();

                    try {
                        $submission = app(SubmitCashAction::class)->execute(
                            submitter: auth()->user(),
                            receiver: User::findOrFail($data['received_by']),
                            store: ActiveStore::currentId() ?? $vendor->defaultStore->id,
                            amount: (float) $data['amount'],
                            reason: $data['reason'] ?? null,
                        );
                    } catch (Throwable $e) {
                        Notification::make()->title($e->getMessage())->danger()->send();

                        return;
                    }

                    Notification::make()
                        ->title($submission->reference.' recorded')
                        ->body('Waiting for '.$submission->receiver->name.' to confirm they got it.')
                        ->success()
                        ->send();
                }),
        ];
    }

    public function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('reference')
                    ->label('Ref')
                    ->fontFamily('mono')
                    ->searchable(),

                Tables\Columns\TextColumn::make('submitter.name')
                    ->label('From')
                    ->searchable(),

                Tables\Columns\TextColumn::make('receiver.name')
                    ->label('To')
                    ->searchable(),

                Tables\Columns\TextColumn::make('amount')
                    ->label('Handed over')
                    ->money('NGN')
                    ->sortable(),

                Tables\Columns\TextColumn::make('expected_amount')
                    ->label('Expected')
                    ->money('NGN')
                    ->toggleable(),

                Tables\Columns\TextColumn::make('variance')
                    ->label('Difference')
                    ->state(fn (CashSubmission $record) => $record->variance())
                    ->money('NGN')
                    ->color(fn ($state) => abs((float) $state) < 0.01 ? 'gray' : ((float) $state < 0 ? 'danger' : 'warning'))
                    ->weight('bold')
                    ->description(fn (CashSubmission $record) => $record->reason),

                Tables\Columns\TextColumn::make('status')
                    ->badge()
                    ->color(fn (string $state) => match ($state) {
                        CashSubmission::STATUS_CONFIRMED => 'success',
                        CashSubmission::STATUS_DISPUTED  => 'danger',
                        default                          => 'warning',
                    })
                    ->description(fn (CashSubmission $record) => $record->dispute_note),

                Tables\Columns\TextColumn::make('created_at')
                    ->label('When')
                    ->dateTime('d M Y, g:ia')
                    ->sortable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->options([
                        CashSubmission::STATUS_PENDING   => 'Waiting to be confirmed',
                        CashSubmission::STATUS_CONFIRMED => 'Confirmed',
                        CashSubmission::STATUS_DISPUTED  => 'Disputed',
                    ]),

                Tables\Filters\Filter::make('short')
                    ->label('Short only')
                    ->query(fn ($query) => $query->whereColumn('amount', '<', 'expected_amount')),
            ])
            ->recordActions([
                Action::make('confirm')
                    ->label('I got this')
                    ->icon('heroicon-o-check-circle')
                    ->color('success')
                    ->requiresConfirmation()
                    ->modalDescription(fn (CashSubmission $record) => 'Confirm that '.$record->submitter->name
                        .' handed you ₦'.number_format((float) $record->amount, 2).'.')
                    // Only the person named as receiving, which is the control.
                    ->visible(fn (CashSubmission $record) => $record->isPending()
                        && (int) $record->received_by === auth()->id())
                    ->action(function (CashSubmission $record) {
                        try {
                            app(ResolveCashSubmissionAction::class)->confirm($record, auth()->user());
                            Notification::make()->title('Confirmed')->success()->send();
                        } catch (Throwable $e) {
                            Notification::make()->title($e->getMessage())->danger()->send();
                        }
                    }),

                Action::make('dispute')
                    ->label('Not what I got')
                    ->icon('heroicon-o-exclamation-triangle')
                    ->color('danger')
                    ->visible(fn (CashSubmission $record) => $record->isPending()
                        && (int) $record->received_by === auth()->id())
                    ->schema([
                        TextInput::make('disputed_amount')
                            ->label('What actually reached you')
                            ->numeric()
                            ->prefix('₦'),
                        Textarea::make('note')
                            ->label('What happened')
                            ->required()
                            ->rows(2),
                    ])
                    ->action(function (CashSubmission $record, array $data) {
                        try {
                            app(ResolveCashSubmissionAction::class)->dispute(
                                $record,
                                auth()->user(),
                                $data['note'],
                                $data['disputed_amount'] !== null ? (float) $data['disputed_amount'] : null,
                            );
                            Notification::make()
                                ->title('Disputed')
                                ->body('The money stays on '.$record->submitter->name.' until it is sorted.')
                                ->warning()
                                ->send();
                        } catch (Throwable $e) {
                            Notification::make()->title($e->getMessage())->danger()->send();
                        }
                    }),
            ])
            ->emptyStateHeading('No cash handed over yet')
            ->emptyStateDescription('When a storekeeper submits their takings, it appears here for the person receiving it to confirm.');
    }

    private function expectedNow(): float
    {
        $vendor = filament()->getTenant();

        return CashDrawer::expectedFrom(
            $vendor->id,
            ActiveStore::currentId() ?? $vendor->defaultStore?->id ?? 0,
            auth()->id(),
        );
    }

    private function canSubmit(): bool
    {
        $vendor = filament()->getTenant();

        return $vendor && auth()->user()->hasVendorPermission($vendor->id, 'submit_cash');
    }

    /**
     * Who cash may be handed to: anyone on the team who can receive it, minus
     * yourself. Handing to yourself would leave one name on a two-name record.
     */
    private function possibleReceivers(): array
    {
        $vendor = filament()->getTenant();

        return $vendor->users()
            ->get()
            ->push($vendor->user)
            ->filter()
            ->unique('id')
            ->reject(fn (User $user) => $user->id === auth()->id())
            ->filter(fn (User $user) => $user->hasVendorPermission($vendor->id, 'receive_cash'))
            ->pluck('name', 'id')
            ->all();
    }
}

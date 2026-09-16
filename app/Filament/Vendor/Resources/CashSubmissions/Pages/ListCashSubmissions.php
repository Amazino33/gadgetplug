<?php

namespace App\Filament\Vendor\Resources\CashSubmissions\Pages;

use App\Actions\Cash\LogTillExpenseAction;
use App\Actions\Cash\ResolveCashSubmissionAction;
use App\Actions\Cash\SubmitCashAction;
use App\Filament\Vendor\Resources\CashSubmissions\CashSubmissionResource;
use App\Models\CashSubmission;
use App\Models\User;
use App\Services\ActiveStore;
use App\Services\Auth\StorePermission;
use App\Services\Cash\CashDrawer;
use App\Services\Cash\CashHandoffToken;
use App\Services\QrCode;
use Filament\Actions\Action;
use Filament\Forms\Components\Radio;
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
            // Sits beside submitting rather than in an accounts screen, because
            // it has to be done at the moment the money leaves the till by the
            // person who spent it. An expense declared later, once a shortage
            // has already been put to somebody, is indistinguishable from an
            // excuse.
            Action::make('logExpense')
                ->label('Log till spending')
                ->icon('heroicon-o-banknotes')
                ->color('gray')
                ->visible(fn () => $this->canSubmit())
                ->modalHeading('Money spent out of the till')
                ->modalDescription('Declare it now and it comes off what you are expected to hand over. Undeclared, it shows up as a shortage.')
                ->schema([
                    TextInput::make('amount')
                        ->label('Amount spent')
                        ->numeric()
                        ->required()
                        ->minValue(0.01)
                        ->prefix('₦'),

                    Textarea::make('reason')
                        ->label('What it went on')
                        ->required()
                        ->rows(2)
                        ->placeholder('e.g. Diesel for the generator, data for the POS terminal'),
                ])
                ->action(function (array $data) {
                    $vendor = filament()->getTenant();

                    try {
                        app(LogTillExpenseAction::class)->execute(
                            loggedBy: auth()->user(),
                            store:    ActiveStore::currentId() ?? $vendor->defaultStore->id,
                            amount:   (float) $data['amount'],
                            reason:   $data['reason'],
                        );
                    } catch (Throwable $e) {
                        Notification::make()->title($e->getMessage())->danger()->send();

                        return;
                    }

                    Notification::make()
                        ->title('Spending recorded')
                        ->body('₦' . number_format((float) $data['amount'], 2) . ' comes off what you owe.')
                        ->success()
                        ->send();
                }),

            Action::make('submit')
                ->label('Submit cash')
                ->icon('heroicon-o-arrow-up-tray')
                ->color('primary')
                ->visible(fn () => $this->canSubmit())
                ->schema([
                    Select::make('received_by')
                        ->label('Handing it to')
                        ->options(fn () => $this->possibleReceivers())
                        // Optional now. Naming somebody in advance means only
                        // they can answer for it; leaving it blank produces a
                        // code for whoever actually turns up to take the money,
                        // which is how it usually happens.
                        ->placeholder('Whoever scans the code')
                        ->helperText('Leave blank to hand it to whoever comes for it. They scan your code to confirm.')
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
                            receiver: filled($data['received_by'] ?? null)
                                ? User::findOrFail($data['received_by'])
                                : null,
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
                        ->body($submission->receiver
                            ? 'Waiting for '.$submission->receiver->name.' to confirm they got it.'
                            : 'Show your code to whoever takes the money, so they can confirm it.')
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
                Action::make('showCode')
                    ->label('Show code')
                    ->icon('heroicon-o-qr-code')
                    ->color('primary')
                    // Only the person who handed it over, and only while it is
                    // still unanswered. A code for a settled handover would
                    // open nothing.
                    ->visible(fn (CashSubmission $record) => $record->isPending()
                        && (int) $record->submitted_by === auth()->id())
                    ->modalHeading(fn (CashSubmission $record) => 'Handing over ₦'.number_format((float) $record->amount, 2))
                    ->modalDescription('Let the person taking the money scan this. It expires in '
                        .CashHandoffToken::TTL_MINUTES.' minutes and works once.')
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('Done')
                    ->modalContent(function (CashSubmission $record) {
                        // A fresh code each time it is opened. They are
                        // single-use and short-lived, and the handover itself
                        // can only be answered once whichever code is used.
                        $url = route('cash.handoff', CashHandoffToken::issue($record));

                        return new HtmlString(
                            '<div class="flex flex-col items-center gap-3 py-4">'
                            .QrCode::svg($url, 240)
                            .'<p class="text-sm text-gray-500">'.$record->reference.'</p>'
                            .'</div>'
                        );
                    }),

                Action::make('confirm')
                    ->label('I got this')
                    ->icon('heroicon-o-check-circle')
                    ->color('success')
                    ->requiresConfirmation()
                    ->modalDescription(fn (CashSubmission $record) => 'Confirm that '.$record->submitter->name
                        .' handed you ₦'.number_format((float) $record->amount, 2).'.')
                    // Only the person named as receiving, which is the control.
                    ->visible(fn (CashSubmission $record) => $record->isPending()
                        && $this->mayAnswerFor($record))
                    ->action(function (CashSubmission $record) {
                        try {
                            app(ResolveCashSubmissionAction::class)->confirm($record, auth()->user());
                            Notification::make()->title('Confirmed')->success()->send();
                        } catch (Throwable $e) {
                            Notification::make()->title($e->getMessage())->danger()->send();
                        }
                    }),

                // The conversation the statement flagged, finally recordable.
                // Without this a contested handover sat as 'disputed' forever
                // and the money stayed on the submitter with no way to close it.
                Action::make('settle')
                    ->label('Settle it')
                    ->icon('heroicon-o-scale')
                    ->color('warning')
                    ->visible(fn (CashSubmission $record) => $record->isDisputed()
                        && $this->maySettle($record))
                    ->modalHeading(fn (CashSubmission $record) => 'Settle '.$record->reference)
                    ->modalDescription(fn (CashSubmission $record) => sprintf(
                        '%s says they handed over ₦%s. %s recorded ₦%s. The difference is ₦%s.',
                        $record->submitter->name,
                        number_format((float) $record->amount, 2),
                        $record->receiver?->name ?? 'The receiver',
                        number_format((float) ($record->disputed_amount ?? 0), 2),
                        number_format($record->disputedGap(), 2),
                    ))
                    ->schema([
                        Radio::make('outcome')
                            ->label('What was agreed?')
                            ->options([
                                CashSubmission::OUTCOME_ACCEPTED     => 'The full amount did arrive — accept the claim',
                                CashSubmission::OUTCOME_CHARGED      => 'The difference is down to the submitter — charge them',
                                CashSubmission::OUTCOME_WRITTEN_OFF  => 'Write the difference off — nobody pays it',
                            ])
                            ->required(),

                        Textarea::make('note')
                            ->label('What was agreed, in words')
                            ->rows(2)
                            ->required()
                            ->placeholder('e.g. Recounted together, two notes had stuck together'),
                    ])
                    ->action(function (CashSubmission $record, array $data) {
                        try {
                            app(ResolveCashSubmissionAction::class)->settle(
                                $record, auth()->user(), $data['outcome'], $data['note'],
                            );
                        } catch (Throwable $e) {
                            Notification::make()->title($e->getMessage())->danger()->send();

                            return;
                        }

                        Notification::make()
                            ->title('Settled')
                            ->body(match ($data['outcome']) {
                                CashSubmission::OUTCOME_CHARGED => 'The difference now sits on '.$record->submitter->name.'.',
                                CashSubmission::OUTCOME_ACCEPTED => 'The full amount is credited to '.$record->submitter->name.'.',
                                default => 'The difference has been written off.',
                            })
                            ->success()
                            ->send();
                    }),

                Action::make('dispute')
                    ->label('Not what I got')
                    ->icon('heroicon-o-exclamation-triangle')
                    ->color('danger')
                    ->visible(fn (CashSubmission $record) => $record->isPending()
                        && $this->mayAnswerFor($record))
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

    /**
     * Whether the signed-in person may answer for this handover.
     *
     * Named in advance means only them. Otherwise it is whoever can receive
     * cash at that particular branch — and never the person who handed it over,
     * whatever else they hold.
     */
    private function mayAnswerFor(CashSubmission $record): bool
    {
        if ((int) $record->submitted_by === auth()->id()) {
            return false;
        }

        if ($record->received_by !== null) {
            return (int) $record->received_by === auth()->id();
        }

        return StorePermission::allows(
            auth()->user(),
            (int) $record->vendor_id,
            (int) $record->store_id,
            'receive_cash',
        );
    }

    /**
     * Whether the signed-in person may settle this disagreement.
     *
     * Not either party to it, unless they own the business — in a small shop
     * the owner is often one of the two, and an unsettleable dispute is worse
     * than one decided by the person whose money it is.
     */
    private function maySettle(CashSubmission $record): bool
    {
        $vendor = filament()->getTenant();
        $user = auth()->user();

        if ($user->isSuperAdmin() || $vendor?->isOwner($user)) {
            return true;
        }

        if (in_array(auth()->id(), [(int) $record->submitted_by, (int) $record->received_by], true)) {
            return false;
        }

        return StorePermission::allows($user, (int) $record->vendor_id, (int) $record->store_id, 'receive_cash');
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

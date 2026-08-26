<?php

namespace App\Filament\Vendor\Resources\CustomerDebts\Pages;

use App\Actions\Pos\RecordCustomerPaymentAction;
use App\Actions\Pos\WriteOffCustomerDebtAction;
use App\Policies\PosCustomerDebtPolicy;
use App\Filament\Vendor\Resources\CustomerDebts\CustomerDebtResource;
use App\Models\PosCustomer;
use App\Services\ActiveStore;
use App\Services\Pos\CustomerDebtService;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use RuntimeException;

class ListCustomerDebts extends ListRecords
{
    protected static string $resource = CustomerDebtResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->label('Customer')
                    ->weight('bold')
                    ->searchable()
                    ->description(fn (PosCustomer $record) => $record->phone),

                TextColumn::make('shop_location')
                    ->label('Where')
                    ->placeholder('—')
                    ->toggleable()
                    ->wrap(),

                TextColumn::make('charged_amount')
                    ->label('Sold on credit')
                    ->money('NGN')
                    ->alignEnd()
                    ->sortable(),

                TextColumn::make('paid_amount')
                    ->label('Paid')
                    // Stored negative so outstanding stays a plain SUM; shown
                    // positive, because "paid ₦5,000" is what a person means.
                    ->formatStateUsing(fn ($state) => '₦' . number_format(abs((float) $state), 2))
                    ->alignEnd()
                    ->sortable(),

                TextColumn::make('outstanding_amount')
                    ->label('Still owing')
                    ->money('NGN')
                    ->weight('bold')
                    ->color('danger')
                    ->alignEnd()
                    ->sortable(),
            ])
            // Worst first: a debt list is read from the top.
            ->defaultSort('outstanding_amount', 'desc')
            ->recordActions([
                ViewAction::make()->label('History'),

                Action::make('recordPayment')
                    ->label('Record payment')
                    ->icon('heroicon-o-banknotes')
                    ->color('success')
                    ->modalHeading(fn (PosCustomer $record) => "Record payment from {$record->name}")
                    ->modalDescription(fn (PosCustomer $record) => 'Currently owing ₦'
                        . number_format(app(CustomerDebtService::class)->outstanding($record->id), 2) . '.')
                    ->schema([
                        TextInput::make('amount')
                            ->label('Amount received now (₦)')
                            ->numeric()
                            ->minValue(0.01)
                            ->required()
                            ->helperText('Part payments are fine — record what actually changed hands.'),

                        Textarea::make('note')
                            ->label('Note')
                            ->rows(2)
                            ->placeholder('Optional — anything worth remembering about this payment.'),
                    ])
                    ->action(function (PosCustomer $record, array $data) {
                        try {
                            // Staff is the authenticated user, never typed: who
                            // took the money is not a field anybody should be
                            // able to fill in on someone else's behalf.
                            app(RecordCustomerPaymentAction::class)->execute(
                                customer: $record,
                                amount: (float) $data['amount'],
                                collectedBy: auth()->user(),
                                storeId: ActiveStore::currentId(),
                                note: $data['note'] ?: null,
                            );
                        } catch (RuntimeException $e) {
                            Notification::make()->title('Payment not recorded')->body($e->getMessage())->danger()->send();

                            return;
                        }

                        Notification::make()
                            ->title('Payment recorded')
                            ->body('Now owing ₦' . number_format(
                                app(CustomerDebtService::class)->outstanding($record->id), 2
                            ) . '.')
                            ->success()
                            ->send();
                    }),

                Action::make('writeOff')
                    ->label('Write off')
                    ->icon('heroicon-o-x-circle')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalHeading(fn (PosCustomer $record) => "Write off {$record->name}'s balance")
                    ->modalDescription(fn (PosCustomer $record) => 'This forgives ₦'
                        . number_format(app(CustomerDebtService::class)->outstanding($record->id), 2)
                        . ' and cannot be undone. The record stays; the money does not.')
                    // Hidden when not permitted, but the action re-checks the
                    // policy itself — hiding a button is a courtesy, not a rule.
                    ->visible(fn (PosCustomer $record) => app(PosCustomerDebtPolicy::class)
                        ->writeOff(auth()->user(), $record))
                    ->schema([
                        Textarea::make('reason')
                            ->label('Why is this being written off?')
                            ->rows(3)
                            ->required()
                            ->helperText('Recorded permanently against the customer.'),
                    ])
                    ->action(function (PosCustomer $record, array $data) {
                        try {
                            app(WriteOffCustomerDebtAction::class)->execute(
                                customer: $record,
                                decidedBy: auth()->user(),
                                reason: $data['reason'],
                                storeId: ActiveStore::currentId(),
                            );
                        } catch (RuntimeException $e) {
                            Notification::make()->title('Not written off')->body($e->getMessage())->danger()->send();

                            return;
                        }

                        Notification::make()
                            ->title('Debt written off')
                            ->body('The balance is closed and the loss stands in the books.')
                            ->success()
                            ->send();
                    }),
            ]);
    }
}

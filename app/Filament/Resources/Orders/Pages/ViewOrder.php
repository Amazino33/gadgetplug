<?php

namespace App\Filament\Resources\Orders\Pages;

use App\Filament\Resources\Orders\OrderResource;
use App\Filament\Vendor\Resources\Orders\Schemas\OrderInfolist;
use App\Models\Order;
use App\Services\Orders\InvalidOrderTransition;
use App\Services\Orders\OrderStatusService;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Schema;
use Illuminate\Auth\Access\AuthorizationException;

// GadgetPlug's side of an order — reuses the vendor panel's order infolist for
// the details. The store packs and hands over from the vendor panel; what
// happens after that is recorded here: the money collected on a
// pay-on-delivery order, delivery (by override until riders confirm with a
// code), and cancellation. Every action goes through OrderStatusService.
class ViewOrder extends ViewRecord
{
    protected static string $resource = OrderResource::class;

    public function infolist(Schema $schema): Schema
    {
        return OrderInfolist::configure($schema);
    }

    protected function getHeaderActions(): array
    {
        return [
            // Pay-on-delivery money only counts once GadgetPlug has it. Books
            // this vendor order's revenue (its own items, never the delivery
            // fee) into the vendor's Due from GadgetPlug account, with any
            // supplier payable, once.
            Action::make('recordPaymentReceived')
                ->label('Record payment received')
                ->icon('heroicon-o-banknotes')
                ->color('success')
                ->requiresConfirmation()
                ->modalHeading('Record payment received')
                ->modalDescription(fn (Order $record) => 'Books ₦'.number_format((float) $record->total_amount, 2)
                    .' to '.($record->items->first()?->vendor?->name ?? 'the vendor')
                    .'\'s Due from GadgetPlug balance, as money collected on '.$record->reference
                    .'. Do this only once the money is actually with GadgetPlug — it happens once and cannot be undone from here.')
                ->schema([
                    Select::make('payment_channel')
                        ->label('How did the customer pay?')
                        ->options(['cash' => 'Cash', 'bank_transfer' => 'Bank transfer'])
                        ->required(),
                ])
                ->visible(fn (Order $record) => $record->payment_method === 'pay_on_delivery'
                    && in_array($record->status, ['shipped', 'delivered'], true)
                    && ! $record->isRevenueRecognized()
                    && auth()->user()->can('dispatch', $record))
                ->action(function (Order $record, array $data): void {
                    $reason = $this->attempt(fn () => app(OrderStatusService::class)
                        ->recordPaymentReceived($record, auth()->user(), $data['payment_channel']));

                    if ($reason === false) {
                        return;
                    }

                    if ($reason !== null) {
                        Notification::make()
                            ->title($reason === 'already_recognized'
                                ? 'This order was already counted — nothing changed.'
                                : 'Could not record this payment. Try again; if it keeps failing, check the log.')
                            ->warning()
                            ->send();

                        return;
                    }

                    $this->refreshFormData(['payment_channel', 'revenue_recognized_at']);

                    Notification::make()->title('Payment recorded — added to the vendor\'s Due from GadgetPlug balance.')->success()->send();
                }),

            // Until riders confirm delivery with the customer's code, this is
            // the only way an order reaches delivered. Logistics only: no stock
            // or money moves.
            Action::make('markDelivered')
                ->label('Mark delivered')
                ->icon('heroicon-o-check-badge')
                ->color('primary')
                ->modalHeading('Mark delivered (override)')
                ->modalDescription('Records that the customer has the goods. Moves no stock or money — for a pay-on-delivery order, record the payment separately.')
                ->schema([
                    Textarea::make('reason')
                        ->label('Why are you marking this delivered?')
                        ->placeholder('e.g. Rider confirmed by phone; customer confirmed on WhatsApp.')
                        ->required()
                        ->maxLength(500),
                    Toggle::make('notify_customer')
                        ->label('Notify customer')
                        ->default(true),
                ])
                ->visible(fn (Order $record) => OrderStatusService::canMove($record, 'delivered')
                    && auth()->user()->can('dispatch', $record))
                ->action(function (Order $record, array $data): void {
                    $done = $this->attempt(fn () => app(OrderStatusService::class)
                        ->markDelivered($record, auth()->user(), $data['reason'], $data['notify_customer'] ?? true));

                    if ($done === false) {
                        return;
                    }

                    $this->refreshFormData(['status']);
                    Notification::make()->title('Order marked delivered.')->success()->send();
                }),

            Action::make('cancelOrder')
                ->label('Cancel order')
                ->icon('heroicon-o-x-circle')
                ->color('danger')
                ->modalHeading('Cancel order')
                ->modalDescription('Releases any stock held for this order and reverses any revenue already booked for it.')
                ->schema([
                    Textarea::make('reason')
                        ->label('Why is this order being cancelled?')
                        ->required()
                        ->maxLength(500),
                ])
                ->visible(fn (Order $record) => OrderStatusService::canMove($record, 'cancelled')
                    && auth()->user()->can('dispatch', $record))
                ->action(function (Order $record, array $data): void {
                    $done = $this->attempt(fn () => app(OrderStatusService::class)
                        ->cancel($record, auth()->user(), $data['reason']));

                    if ($done === false) {
                        return;
                    }

                    $this->refreshFormData(['status']);
                    Notification::make()->title('Order cancelled.')->success()->send();
                }),
        ];
    }

    /** Runs a service call; a refused move becomes a notification and false. */
    private function attempt(callable $call): mixed
    {
        try {
            return $call();
        } catch (InvalidOrderTransition $e) {
            Notification::make()->title($e->getMessage())->danger()->send();
        } catch (AuthorizationException) {
            Notification::make()->title('You do not have permission to do that.')->danger()->send();
        }

        return false;
    }
}

<?php

namespace App\Filament\Vendor\Resources\OrderItems\Pages;

use App\Filament\Vendor\Resources\OrderItems\OrderItemResource;
use App\Filament\Vendor\Resources\Orders\OrderResource;
use App\Models\OrderItem;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Components\Utilities\Get;
use Illuminate\Database\Eloquent\Model;


class ViewOrderItem extends ViewRecord
{
    protected static string $resource = OrderItemResource::class;
    protected string $view = 'filament.vendor.pages.view-order-item';

    protected function resolveRecord(int|string $key): Model
    {
        return OrderItem::with(['order.items.product', 'product'])->findOrFail($key);
    }

    public function getTitle(): string
    {
        return $this->record->order->reference;
    }

    public function getBreadcrumb(): string
    {
        return $this->record->order->reference;
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('callCustomer')
                ->label('Call Customer')
                ->icon('heroicon-o-phone')
                ->color('info')
                ->url(fn () => 'tel:' . $this->record->order->customer_phone),

            // Sends the vendor to the order page's Send Message action rather than
            // opening a blank WhatsApp chat — that path renders the vendor's
            // templates and logs the message against the order.
            Action::make('messageCustomer')
                ->label('Message')
                ->icon('heroicon-o-chat-bubble-oval-left')
                ->color('success')
                ->url(fn () => OrderResource::getUrl('view', ['record' => $this->record->order_id], tenant: filament()->getTenant())),

            Action::make('updateStatus')
                ->label('Update Status')
                ->icon('heroicon-o-arrow-path')
                ->color('warning')
                ->schema([
                    Select::make('status')
                        ->label('New Status')
                        ->options(fn () => match ($this->record->order->status) {
                            'pending', 'confirmed', 'paid' => [
                                'shipped'   => 'Hand to Rider / Dispatch',
                                'cancelled' => 'Cancel Order',
                            ],
                            'shipped' => [
                                'delivered' => 'Mark as Delivered',
                            ],
                            default => [],
                        })
                        ->required()
                        ->live(),

                    // Mirrors ListOrders::requiresPaymentChannel() exactly — this
                    // was the one remaining entry point that let a pay-on-delivery
                    // order reach 'delivered' without ever being asked how the
                    // customer paid, leaving it stuck off the Financial Report
                    // with no error to say so.
                    Select::make('payment_channel')
                        ->label('How did the customer pay?')
                        ->options(['cash' => 'Cash', 'bank_transfer' => 'Bank Transfer'])
                        ->required()
                        ->helperText('Cash goes to your cash account, bank transfer to your bank account.')
                        ->visible(fn (Get $get) => $this->requiresPaymentChannel($get('status'))),
                ])
                ->action(function (array $data): void {
                    $order = $this->record->order;

                    if ($this->requiresPaymentChannel($data['status']) && ! in_array($data['payment_channel'] ?? null, ['cash', 'bank_transfer'], true)) {
                        Notification::make()
                            ->title('Say how the customer paid before marking this delivered.')
                            ->body('Pick Cash or Bank Transfer — that is what puts the money into your accounts and onto the Financial Report.')
                            ->warning()
                            ->send();

                        return;
                    }

                    $order->update(array_filter([
                        'status'          => $data['status'],
                        'payment_channel' => $this->requiresPaymentChannel($data['status']) ? $data['payment_channel'] : null,
                    ]));

                    $this->record->refresh()->load(['order.items.product', 'product']);

                    Notification::make()
                        ->title(match ($data['status']) {
                            'shipped'   => 'Order handed to rider',
                            'delivered' => 'Order marked as delivered',
                            'cancelled' => 'Order cancelled',
                            default     => 'Status updated',
                        })
                        ->success()
                        ->send();
                })
                ->visible(fn () => !in_array(
                    $this->record->order->status,
                    ['delivered', 'cancelled', 'paid_but_failed_stock']
                )),
        ];
    }

    private function requiresPaymentChannel(?string $newStatus): bool
    {
        $order = $this->record->order;

        return $newStatus === 'delivered'
            && $order->payment_method === 'pay_on_delivery'
            && ! $order->isRevenueRecognized();
    }
}

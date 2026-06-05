<?php

namespace App\Filament\Vendor\Resources\OrderItems\Pages;

use App\Filament\Vendor\Resources\OrderItems\OrderItemResource;
use App\Models\OrderItem;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
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

            Action::make('whatsappCustomer')
                ->label('WhatsApp')
                ->icon('heroicon-o-chat-bubble-oval-left')
                ->color('success')
                ->url(fn () => 'https://api.whatsapp.com/send?phone=' . preg_replace('/\D/', '', $this->record->order->customer_phone))
                ->openUrlInNewTab(),

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
                        ->required(),
                ])
                ->action(function (array $data): void {
                    $this->record->order->update(['status' => $data['status']]);
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
}

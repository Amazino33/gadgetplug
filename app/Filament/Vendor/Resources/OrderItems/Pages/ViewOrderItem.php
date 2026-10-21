<?php

namespace App\Filament\Vendor\Resources\OrderItems\Pages;

use App\Filament\Vendor\Resources\OrderItems\OrderItemResource;
use App\Filament\Vendor\Resources\Orders\OrderResource;
use App\Models\OrderItem;
use App\Services\Orders\InvalidOrderTransition;
use App\Services\Orders\OrderStatusService;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Auth\Access\AuthorizationException;
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

            // The store's steps only — see OrderStatusService.
            Action::make('updateStatus')
                ->label('Update Status')
                ->icon('heroicon-o-arrow-path')
                ->color('warning')
                ->schema([
                    Select::make('status')
                        ->label('New Status')
                        ->options(fn () => OrderStatusService::storeOptionsFor($this->record->order))
                        ->required(),
                ])
                ->action(function (array $data): void {
                    try {
                        OrderStatusService::applyStoreStep($this->record->order, auth()->user(), $data['status']);
                    } catch (InvalidOrderTransition|AuthorizationException $e) {
                        Notification::make()->title($e instanceof AuthorizationException ? 'Only staff of the store holding this order can update it.' : $e->getMessage())->danger()->send();

                        return;
                    }

                    $this->record->refresh()->load(['order.items.product', 'product']);

                    Notification::make()
                        ->title($data['status'] === 'shipped' ? 'Order handed to rider' : 'Order marked as packed')
                        ->success()
                        ->send();
                })
                ->visible(fn () => OrderStatusService::storeOptionsFor($this->record->order) !== []
                    && auth()->user()->can('fulfil', $this->record->order)),
        ];
    }
}

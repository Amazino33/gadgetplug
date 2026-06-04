<?php

namespace App\Filament\Vendor\Resources\Orders\Pages;

use App\Actions\Inventory\DispatchStockAction;
use App\Actions\Inventory\ReleaseReservationAction;
use App\Filament\Vendor\Resources\Orders\OrderResource;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Support\Facades\Log;

class ViewOrder extends ViewRecord
{
    protected static string $resource = OrderResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('callCustomer')
                ->label('Call')
                ->icon('heroicon-o-phone')
                ->color('info')
                ->url(fn () => 'tel:' . $this->record->customer_phone),

            Action::make('whatsappCustomer')
                ->label('WhatsApp')
                ->icon('heroicon-o-chat-bubble-oval-left')
                ->color('success')
                ->url(fn () => 'https://wa.me/' . preg_replace('/\D/', '', $this->record->customer_phone))
                ->openUrlInNewTab(),

            Action::make('updateStatus')
                ->label('Update Status')
                ->icon('heroicon-o-arrow-path')
                ->color('warning')
                ->schema([
                    Select::make('status')
                        ->label('New Status')
                        ->options(fn() => match($this->record->status) {
                            'pending', 'confirmed', 'paid' => [
                                'shipped'   => 'Hand to Logistics / Rider',
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
                    $newStatus = $data['status'];
                    $order     = $this->record;
                    $userId    = auth()->id();

                    if ($newStatus === 'shipped') {
                        // Trigger physical deduction for every item in this order
                        $order->load('items');
                        foreach ($order->items as $item) {
                            try {
                                app(DispatchStockAction::class)->execute(
                                    productId:   $item->product_id,
                                    quantity:    $item->quantity,
                                    userId:      $userId,
                                    reference:   $order->reference,
                                    description: 'Physical deduction — order handed to rider.',
                                );
                            } catch (\Exception $e) {
                                Log::error("Dispatch stock failed for order {$order->id}: " . $e->getMessage());
                            }
                        }
                    }

                    if ($newStatus === 'cancelled') {
                        // Release reserved stock so items go back on sale
                        $order->load('items');
                        foreach ($order->items as $item) {
                            try {
                                app(ReleaseReservationAction::class)->execute(
                                    productId:   $item->product_id,
                                    quantity:    $item->quantity,
                                    userId:      $userId,
                                    reference:   $order->reference,
                                    description: 'Reservation released — order cancelled.',
                                );
                            } catch (\Exception $e) {
                                Log::error("Release reservation failed for order {$order->id}: " . $e->getMessage());
                            }
                        }
                    }

                    $order->update(['status' => $newStatus]);
                    $this->refreshFormData(['status']);

                    Notification::make()
                        ->title(match($newStatus) {
                            'shipped'   => 'Order handed to rider — stock deducted',
                            'delivered' => 'Order marked as delivered',
                            'cancelled' => 'Order cancelled — stock released',
                            default     => 'Status updated',
                        })
                        ->success()
                        ->send();
                })
                ->visible(fn() => !in_array($this->record->status, ['delivered', 'cancelled', 'paid_but_failed_stock'])),
        ];
    }
}

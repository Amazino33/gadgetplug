<?php

namespace App\Notifications;

use App\Models\Order;
use App\Models\Vendor;
use Illuminate\Notifications\Notification;

class NewOrderNotification extends Notification
{
    public function __construct(
        public readonly Order  $order,
        public readonly Vendor $vendor,
        public readonly int    $itemCount,
        public readonly float  $vendorTotal,
    ) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toDatabase(object $notifiable): array
    {
        return [
            'title'        => 'New order received',
            'body'         => 'Order #' . $this->order->reference . ' — ' . $this->itemCount . ' item(s) worth ₦' . number_format($this->vendorTotal, 2),
            'order_id'     => $this->order->id,
            'reference'    => $this->order->reference,
            'vendor_id'    => $this->vendor->id,
            'vendor_total' => $this->vendorTotal,
            'icon'         => 'heroicon-o-shopping-bag',
            'color'        => 'success',
            'actions'      => [
                [
                    'name'  => 'view',
                    'label' => 'View Order',
                    'url'   => '/plug/' . $this->vendor->id . '/orders/' . $this->order->id,
                ],
            ],
        ];
    }
}

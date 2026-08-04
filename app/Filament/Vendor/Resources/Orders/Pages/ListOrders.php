<?php

namespace App\Filament\Vendor\Resources\Orders\Pages;

use App\Filament\Vendor\Resources\Orders\OrderResource;
use App\Models\DeliveryMessage;
use App\Models\MessageTemplate;
use App\Models\Order;
use App\Services\Messaging\MessagingService;
use App\Services\Messaging\TemplateRenderer;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;
use Livewire\Attributes\Url;

class ListOrders extends ListRecords
{
    protected static string $resource = OrderResource::class;

    // Custom Blade view for the same reason Products got one — a hand-designed
    // mobile tile layout and a default active-only filter don't fit Filament's
    // Table component cleanly. This page drives its own query/pagination.
    protected string $view = 'filament.vendor.pages.orders-list';

    #[Url(keep: true)]
    public string $search = '';

    // '' = active orders only (default). 'all' = everything. Anything else = that exact status.
    #[Url(as: 'status', keep: true)]
    public string $statusFilter = '';

    public function updatingSearch(): void
    {
        $this->resetLivewirePage('ordersPage');
    }

    public function updatingStatusFilter(): void
    {
        $this->resetLivewirePage('ordersPage');
    }

    public function getOrders(): LengthAwarePaginator
    {
        return OrderResource::getEloquentQuery()
            // deliveryPerson/logisticsCompany feed {{rider_line}} when the row's
            // message templates are pre-rendered for the send-message modal.
            ->with(['items.product', 'deliveryPerson', 'logisticsCompany'])
            ->when($this->search !== '', fn (Builder $query) => $query->where(
                fn (Builder $q) => $q->where('reference', 'like', "%{$this->search}%")
                    ->orWhere('customer_name', 'like', "%{$this->search}%")
                    ->orWhere('customer_phone', 'like', "%{$this->search}%")
            ))
            ->when($this->statusFilter === '', fn (Builder $query) => $query->whereNotIn('status', ['delivered', 'cancelled']))
            ->when(! in_array($this->statusFilter, ['', 'all'], true), fn (Builder $query) => $query->where('status', $this->statusFilter))
            ->latest()
            ->paginate(10, pageName: 'ordersPage');
    }

    /**
     * @return array<int, array<string, string>>
     */
    public function statusOptionsFor(Order $order): array
    {
        return match ($order->status) {
            'pending', 'confirmed', 'paid' => [
                ['value' => 'shipped', 'label' => 'Mark as Dispatched'],
                ['value' => 'cancelled', 'label' => 'Cancel Order'],
            ],
            'shipped' => [
                ['value' => 'delivered', 'label' => 'Mark as Delivered'],
                ['value' => 'cancelled', 'label' => 'Cancel Order'],
            ],
            default => [],
        };
    }

    // Templates for the row's send-message modal, pre-rendered against this order
    // so picking one fills the textarea with the exact text that will be sent,
    // with no extra round trip. Same source of truth as the order page's
    // Send Message action.
    /**
     * @return array<int, array<string, string>>
     */
    public function messageTemplatesFor(Order $order): array
    {
        $renderer = app(TemplateRenderer::class);
        $context  = TemplateRenderer::contextForOrder($order);

        return MessageTemplate::query()
            ->where('vendor_id', filament()->getTenant()->id)
            ->where('recipient_type', 'customer')
            ->where('is_active', true)
            ->orderBy('key')
            ->get()
            ->map(fn (MessageTemplate $template): array => [
                'key'     => $template->key,
                'label'   => Str::headline($template->key),
                'channel' => $template->channel,
                'body'    => $renderer->render($template->body, $context),
            ])
            ->values()
            ->all();
    }

    public function sendOrderMessage(int $orderId, ?string $body, string $channel = 'whatsapp'): void
    {
        $order = $this->findOwnedOrder($orderId);

        if (blank($body)) {
            Notification::make()->title('Write a message first.')->warning()->send();

            return;
        }

        if (blank($order->customer_phone)) {
            Notification::make()->title('This customer has no phone number on file.')->danger()->send();

            return;
        }

        if (! in_array($channel, ['whatsapp', 'sms'], true)) {
            $channel = 'whatsapp';
        }

        $message = DeliveryMessage::create([
            'vendor_id'      => filament()->getTenant()->id,
            'order_id'       => $order->id,
            'recipient_type' => 'customer',
            'channel'        => $channel,
            'to_number'      => $order->customer_phone,
            'body'           => $body,
            'status'         => 'queued',
            'sent_by'        => auth()->id(),
        ]);

        $result = app(MessagingService::class)->send($message);

        match ($result->status) {
            'sent' => Notification::make()->title('Message sent')->success()->send(),

            'failed' => Notification::make()
                ->title('Message failed to send')
                ->body($result->provider_response['error'] ?? 'Unknown error')
                ->danger()
                ->send(),

            'link_generated' => Notification::make()
                ->title('WhatsApp link ready — tap to send')
                ->body('No automated WhatsApp provider is configured, so this needs one manual tap.')
                ->warning()
                ->persistent()
                ->actions([
                    Action::make('open')
                        ->label('Open WhatsApp')
                        ->url($result->provider_response['url'] ?? '#')
                        ->openUrlInNewTab(),
                ])
                ->send(),

            default => null,
        };
    }

    public function updateOrderStatus(int $orderId, ?string $newStatus, ?string $note = null): void
    {
        $order = $this->findOwnedOrder($orderId);
        $statusChanged = false;

        if (filled($newStatus)) {
            if (! in_array($newStatus, array_column($this->statusOptionsFor($order), 'value'), true)) {
                Notification::make()->title('That status change is not available for this order.')->danger()->send();
                return;
            }

            $order->update(['status' => $newStatus]);
            $statusChanged = true;
        }

        if (filled($note)) {
            $order->notes()->create([
                'vendor_id' => $order->items()->value('vendor_id'),
                'user_id'   => auth()->id(),
                'body'      => $note,
            ]);
        }

        if (! $statusChanged && blank($note)) {
            Notification::make()->title('Nothing to update — add a note or pick a new status.')->warning()->send();
            return;
        }

        Notification::make()->title('Order updated.')->success()->send();
    }

    // These are public Livewire methods, so the order id arrives from the browser
    // and cannot be trusted. Resolving through the resource query keeps the
    // vendor scope applied — a plain Order::findOrFail() would let one vendor
    // act on another vendor's order by calling the method with any id.
    private function findOwnedOrder(int $orderId): Order
    {
        return OrderResource::getEloquentQuery()->whereKey($orderId)->firstOrFail();
    }
}

<?php

namespace App\Filament\Vendor\Resources\Orders\Pages;

use App\Filament\Vendor\Resources\Orders\OrderResource;
use App\Models\Order;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
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
            ->with('items.product')
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

    public function updateOrderStatus(int $orderId, ?string $newStatus, ?string $note = null): void
    {
        $order = Order::findOrFail($orderId);
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
}

<?php

declare(strict_types=1);

namespace App\Filament\Vendor\Widgets;

use App\Models\InventoryLedger;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use Filament\Facades\Filament;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Infolist;
use Filament\Tables;
use Filament\Actions\Action;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget as BaseWidget;
use Illuminate\Support\Carbon;
use Illuminate\Support\HtmlString;
use Livewire\Attributes\Reactive;

class InventoryTableWidget extends BaseWidget
{
    /** The branch InventoryPage is reporting on, null for the whole business. */
    #[Reactive]
    public ?int $storeFilter = null;

    protected static ?string $heading = 'Inventory Analysis — All Products';
    protected int|string|array $columnSpan = 'full';

    public function table(Table $table): Table
    {
        $vendorId = Filament::getTenant()?->id;

        $soldMap = InventoryLedger::query()
            ->where('vendor_id', $vendorId)
            ->whereIn('transaction_type', ['online_sale', 'pos_sale', 'dispatched'])
            ->where('created_at', '>=', Carbon::today()->subDays(30))
            ->selectRaw('product_id, ABS(SUM(quantity_change)) as units_sold')
            ->groupBy('product_id')
            ->pluck('units_sold', 'product_id');

        return $table
            ->query(
                Product::query()
                    // Home store, not "holds stock here": a branch's sold-out
                    // lines belong in its own inventory, and they are the ones
                    // worth reordering.
                    ->when($this->storeFilter, fn ($q) => $q->where('products.store_id', $this->storeFilter))
                    ->where('vendor_id', $vendorId)
                    ->with('category')
            )
            ->defaultSort('stock_quantity', 'asc')
            ->columns([

                Tables\Columns\TextColumn::make('name')
                    ->label('Product')
                    ->searchable()
                    ->sortable()
                    ->description(fn (Product $r): string => $r->brand ?? $r->category?->name ?? '')
                    ->wrap(),

                Tables\Columns\TextColumn::make('category.name')
                    ->label('Category')
                    ->sortable()
                    ->badge()
                    ->color('primary'),

                // ── 3-State Stock Column ────────────────────────────────────
                Tables\Columns\TextColumn::make('stock_quantity')
                    ->label('On Shelf')
                    ->sortable()
                    ->badge()
                    ->color(fn (int $state): string => match (true) {
                        $state === 0 => 'danger',
                        $state < 5   => 'warning',
                        default      => 'success',
                    })
                    ->tooltip('Physical units sitting in your shop'),

                Tables\Columns\TextColumn::make('reserved_stock')
                    ->label('Reserved')
                    ->sortable()
                    ->badge()
                    ->color(fn (int $state): string => $state > 0 ? 'warning' : 'gray')
                    ->tooltip('Paid orders awaiting dispatch — still physically in your shop'),

                Tables\Columns\TextColumn::make('available_stock')
                    ->label('Available to Sell')
                    ->sortable(query: fn ($query, $direction) =>
                        $query->orderByRaw("CAST(stock_quantity AS SIGNED) - CAST(reserved_stock AS SIGNED) {$direction}")
                    )
                    ->badge()
                    ->color(fn (int $state): string => match (true) {
                        $state <= 0  => 'danger',
                        $state < 5   => 'warning',
                        default      => 'success',
                    })
                    ->tooltip('What the storefront shows buyers = On Shelf − Reserved'),
                // ────────────────────────────────────────────────────────────

                Tables\Columns\TextColumn::make('cost_price')
                    ->label('Cost')
                    ->sortable()
                    ->formatStateUsing(fn ($state): string => '₦' . number_format((float) $state)),

                Tables\Columns\TextColumn::make('price')
                    ->label('Price')
                    ->sortable()
                    ->formatStateUsing(fn ($state): string => '₦' . number_format((float) $state)),

                Tables\Columns\TextColumn::make('margin_pct')
                    ->label('Margin')
                    ->getStateUsing(fn (Product $r): string => $r->price > 0
                        ? number_format((((float) $r->price - (float) $r->cost_price) / (float) $r->price) * 100, 1) . '%'
                        : '0.0%'
                    ),

                Tables\Columns\TextColumn::make('sold_30d')
                    ->label('Sold (30d)')
                    ->getStateUsing(fn (Product $r): int => (int) ($soldMap[$r->id] ?? 0))
                    ->badge()
                    ->color('info'),

                Tables\Columns\TextColumn::make('stock_status')
                    ->label('Status')
                    ->badge()
                    ->getStateUsing(fn (Product $r): string => match (true) {
                        $r->available_stock === 0 && $r->stock_quantity > 0 => 'All Reserved',
                        $r->available_stock === 0 => 'Out of Stock',
                        $r->available_stock < 5   => 'Low Stock',
                        default                    => 'Healthy',
                    })
                    ->color(fn (string $state): string => match ($state) {
                        'Out of Stock', 'All Reserved' => 'danger',
                        'Low Stock'                    => 'warning',
                        default                        => 'success',
                    }),
            ])
            // Clicking a row opens its movement history. It used to open the
            // reserved-orders modal, which is hidden unless a product has
            // reservations — so on most rows a click did nothing at all.
            ->recordAction('stockMovements')
            ->actions([
                Action::make('stockMovements')
                    ->label('Stock Movements')
                    ->icon('heroicon-o-arrows-up-down')
                    ->color('gray')
                    ->modalHeading(fn (Product $r) => "{$r->name} — Stock Movements")
                    ->modalDescription('Every recorded change to this product\'s stock: what happened, who did it, and when.')
                    ->modalContent(fn (Product $r) => $this->buildStockMovementsTable($r))
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('Close')
                    ->modalWidth('5xl'),

                Action::make('viewReservedOrders')
                    ->label('Reserved Orders')
                    ->icon('heroicon-o-shopping-bag')
                    ->color('warning')
                    ->visible(fn (Product $r) => $r->reserved_stock > 0)
                    ->modalHeading(fn (Product $r) => "{$r->name} — Reserved Orders ({$r->reserved_stock} units)")
                    ->modalContent(fn (Product $r) => $this->buildReservedOrdersTable($r))
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('Close'),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('stock_status')
                    ->label('Stock Status')
                    ->options([
                        'out'     => 'Out of Stock',
                        'low'     => 'Low Stock',
                        'healthy' => 'Healthy',
                    ])
                    ->query(fn (\Illuminate\Database\Eloquent\Builder $q, array $data) => match ($data['value'] ?? null) {
                        'out'     => $q->whereRaw('CAST(stock_quantity AS SIGNED) - CAST(reserved_stock AS SIGNED) <= 0'),
                        'low'     => $q->whereRaw('CAST(stock_quantity AS SIGNED) - CAST(reserved_stock AS SIGNED) > 0')
                                       ->whereRaw('CAST(stock_quantity AS SIGNED) - CAST(reserved_stock AS SIGNED) < 5'),
                        'healthy' => $q->whereRaw('CAST(stock_quantity AS SIGNED) - CAST(reserved_stock AS SIGNED) >= 5'),
                        default   => $q,
                    }),
            ])
            ->paginated([15, 25, 50]);
    }

    /** Human wording for a movement type — the "how" of each row. */
    private const MOVEMENT_LABELS = [
        'online_sale'          => 'Online Sale',
        'pos_sale'             => 'POS Sale',
        'pos_void'             => 'POS Sale Voided',
        'pos_return'           => 'POS Return',
        'restock'              => 'Restock',
        'audit_correction'     => 'Audit Correction',
        'refund'               => 'Refund',
        'reserved'             => 'Reserved',
        'dispatched'           => 'Dispatched',
        'reservation_released' => 'Reservation Released',
    ];

    /**
     * One product's stock history: what happened, who did it, and when.
     *
     * Read from inventory_ledgers, which every stock action writes to and
     * nothing edits afterwards, so this is the movement record itself rather
     * than a summary derived from it. Newest first, because the question being
     * asked at this point is almost always "what just happened to this?".
     *
     * Capped at 100 rows with the total stated, so a fast-moving product opens
     * a readable modal instead of thousands of rows — the full list, filterable
     * and sortable, lives on the Stock Movement page.
     */
    private function buildStockMovementsTable(Product $product): HtmlString
    {
        $limit = 100;

        $total = InventoryLedger::where('product_id', $product->id)->count();

        $movements = InventoryLedger::query()
            ->where('product_id', $product->id)
            ->with(['user:id,name', 'store:id,name'])
            ->latest('created_at')
            ->limit($limit)
            ->get();

        if ($movements->isEmpty()) {
            return new HtmlString(
                '<p style="font-size:13px;color:#6b7280;padding:16px 0;text-align:center">'
                . 'No stock movement has been recorded for this product yet.'
                . '</p>'
            );
        }

        $rows = $movements->map(function (InventoryLedger $entry) {
            $label = self::MOVEMENT_LABELS[$entry->transaction_type]
                ?? ucfirst(str_replace('_', ' ', (string) $entry->transaction_type));

            $typeColor = match ($entry->transaction_type) {
                'restock', 'reservation_released'       => '#068B03',
                'pos_sale', 'online_sale', 'dispatched' => '#F97316',
                'pos_void', 'pos_return', 'refund'      => '#0284C7',
                default                                 => '#6b7280',
            };

            $change      = (int) $entry->quantity_change;
            $changeText  = $change > 0 ? "+{$change}" : (string) $change;
            $changeColor = $change > 0 ? '#068B03' : ($change < 0 ? '#DC2626' : '#6b7280');

            // "System" rather than blank: an online sale deducts stock with no
            // staff member behind it, and an empty cell reads as missing data.
            $who    = e($entry->user?->name ?? 'System');
            $branch = $entry->store?->name ? e($entry->store->name) : '—';
            $note   = e($entry->description ?: ($entry->reference ?: '—'));
            $when   = $entry->created_at?->format('d M Y, g:ia') ?? '—';

            return "<tr style='border-bottom:1px solid #f3f4f6'>
                <td style='padding:10px 12px;font-size:12px;color:#6b7280;white-space:nowrap'>{$when}</td>
                <td style='padding:10px 12px'>
                    <span style='background:{$typeColor}22;color:{$typeColor};font-size:11px;font-weight:600;padding:2px 8px;border-radius:99px;white-space:nowrap'>
                        {$label}
                    </span>
                </td>
                <td style='padding:10px 12px;font-size:13px;font-weight:700;text-align:center;color:{$changeColor}'>{$changeText}</td>
                <td style='padding:10px 12px;font-size:13px'>{$who}</td>
                <td style='padding:10px 12px;font-size:12px;color:#6b7280'>{$branch}</td>
                <td style='padding:10px 12px;font-size:12px;color:#6b7280'>{$note}</td>
            </tr>";
        })->join('');

        $footer = $total > $limit
            ? "<p style='font-size:12px;color:#6b7280;padding:10px 2px 0'>Showing the most recent {$limit} of {$total} movements. The Stock Movement page under Inventory holds the full history.</p>"
            : "<p style='font-size:12px;color:#6b7280;padding:10px 2px 0'>{$total} " . ($total === 1 ? 'movement' : 'movements') . " recorded.</p>";

        return new HtmlString("
            <div style='overflow-x:auto'>
                <table style='width:100%;border-collapse:collapse;font-family:inherit'>
                    <thead>
                        <tr style='background:#f9fafb;border-bottom:2px solid #e5e7eb'>
                            <th style='padding:10px 12px;text-align:left;font-size:11px;font-weight:600;text-transform:uppercase;letter-spacing:.5px;color:#6b7280'>When</th>
                            <th style='padding:10px 12px;text-align:left;font-size:11px;font-weight:600;text-transform:uppercase;letter-spacing:.5px;color:#6b7280'>What Happened</th>
                            <th style='padding:10px 12px;text-align:center;font-size:11px;font-weight:600;text-transform:uppercase;letter-spacing:.5px;color:#6b7280'>Change</th>
                            <th style='padding:10px 12px;text-align:left;font-size:11px;font-weight:600;text-transform:uppercase;letter-spacing:.5px;color:#6b7280'>By Who</th>
                            <th style='padding:10px 12px;text-align:left;font-size:11px;font-weight:600;text-transform:uppercase;letter-spacing:.5px;color:#6b7280'>Branch</th>
                            <th style='padding:10px 12px;text-align:left;font-size:11px;font-weight:600;text-transform:uppercase;letter-spacing:.5px;color:#6b7280'>Reference / Note</th>
                        </tr>
                    </thead>
                    <tbody>{$rows}</tbody>
                </table>
                {$footer}
            </div>
        ");
    }

    private function buildReservedOrdersTable(Product $product): HtmlString
    {
        $items = OrderItem::query()
            ->where('product_id', $product->id)
            ->whereHas('order', fn ($q) => $q->whereIn('status', ['pending', 'confirmed', 'paid']))
            ->with('order')
            ->get();

        if ($items->isEmpty()) {
            return new HtmlString(
                '<p class="text-sm text-gray-500 dark:text-gray-400 py-4 text-center">No active reservations found.</p>'
            );
        }

        $rows = $items->map(function ($item) {
            $statusColor = match ($item->order->status) {
                'paid'      => '#068B03',
                'confirmed' => '#F97316',
                default     => '#6b7280',
            };
            $date = $item->order->created_at->format('d M Y, g:ia');

            return "<tr style='border-bottom:1px solid #f3f4f6'>
                <td style='padding:10px 12px;font-size:13px;font-weight:600'>{$item->order->reference}</td>
                <td style='padding:10px 12px;font-size:13px'>{$item->order->customer_name}</td>
                <td style='padding:10px 12px;font-size:13px;text-align:center'>{$item->quantity}</td>
                <td style='padding:10px 12px'>
                    <span style='background:{$statusColor}22;color:{$statusColor};font-size:11px;font-weight:600;padding:2px 8px;border-radius:99px;text-transform:uppercase'>
                        {$item->order->status}
                    </span>
                </td>
                <td style='padding:10px 12px;font-size:12px;color:#6b7280'>{$date}</td>
            </tr>";
        })->join('');

        $totalUnits = $items->sum('quantity');

        return new HtmlString("
            <div style='overflow-x:auto'>
                <table style='width:100%;border-collapse:collapse;font-family:inherit'>
                    <thead>
                        <tr style='background:#f9fafb;border-bottom:2px solid #e5e7eb'>
                            <th style='padding:10px 12px;text-align:left;font-size:11px;font-weight:600;text-transform:uppercase;letter-spacing:.5px;color:#6b7280'>Order Ref</th>
                            <th style='padding:10px 12px;text-align:left;font-size:11px;font-weight:600;text-transform:uppercase;letter-spacing:.5px;color:#6b7280'>Customer</th>
                            <th style='padding:10px 12px;text-align:center;font-size:11px;font-weight:600;text-transform:uppercase;letter-spacing:.5px;color:#6b7280'>Qty</th>
                            <th style='padding:10px 12px;text-align:left;font-size:11px;font-weight:600;text-transform:uppercase;letter-spacing:.5px;color:#6b7280'>Status</th>
                            <th style='padding:10px 12px;text-align:left;font-size:11px;font-weight:600;text-transform:uppercase;letter-spacing:.5px;color:#6b7280'>Ordered</th>
                        </tr>
                    </thead>
                    <tbody>{$rows}</tbody>
                    <tfoot>
                        <tr style='background:#fff8f0;border-top:2px solid #fed7aa'>
                            <td colspan='2' style='padding:10px 12px;font-size:13px;font-weight:700;color:#F97316'>Total Reserved</td>
                            <td style='padding:10px 12px;font-size:13px;font-weight:700;color:#F97316;text-align:center'>{$totalUnits}</td>
                            <td colspan='2'></td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        ");
    }
}

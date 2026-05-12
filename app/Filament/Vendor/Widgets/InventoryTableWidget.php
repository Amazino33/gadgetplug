<?php

declare(strict_types=1);

namespace App\Filament\Vendor\Widgets;

use App\Models\InventoryLedger;
use App\Models\Product;
use Filament\Facades\Filament;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget as BaseWidget;
use Illuminate\Support\Carbon;

class InventoryTableWidget extends BaseWidget
{
    protected static ?string $heading = 'Inventory Analysis — All Products';
    protected int|string|array $columnSpan = 'full';

    public function table(Table $table): Table
    {
        $vendorId = Filament::getTenant()?->id;

        // One query to get 30-day sales per product — avoids N+1
        $soldMap = InventoryLedger::query()
            ->where('vendor_id', $vendorId)
            ->whereIn('transaction_type', ['online_sale', 'pos_sale'])
            ->where('created_at', '>=', Carbon::today()->subDays(30))
            ->selectRaw('product_id, ABS(SUM(quantity_change)) as units_sold')
            ->groupBy('product_id')
            ->pluck('units_sold', 'product_id');

        return $table
            ->query(
                Product::query()
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

                Tables\Columns\TextColumn::make('stock_quantity')
                    ->label('Stock Qty')
                    ->sortable()
                    ->badge()
                    ->color(fn (int $state): string => match (true) {
                        $state === 0 => 'danger',
                        $state < 5   => 'warning',
                        default       => 'success',
                    }),

                Tables\Columns\TextColumn::make('cost_price')
                    ->label('Cost Price')
                    ->sortable()
                    ->formatStateUsing(fn ($state): string => '₦' . number_format((float) $state)),

                Tables\Columns\TextColumn::make('price')
                    ->label('Retail Price')
                    ->sortable()
                    ->formatStateUsing(fn ($state): string => '₦' . number_format((float) $state)),

                Tables\Columns\TextColumn::make('margin_pct')
                    ->label('Gross Margin')
                    ->getStateUsing(fn (Product $r): string => $r->price > 0
                        ? number_format((((float) $r->price - (float) $r->cost_price) / (float) $r->price) * 100, 1) . '%'
                        : '0.0%'
                    ),

                Tables\Columns\TextColumn::make('retail_stock_value')
                    ->label('Retail Stock Value')
                    ->getStateUsing(fn (Product $r): string =>
                        '₦' . number_format((float) $r->stock_quantity * (float) $r->price)
                    ),

                Tables\Columns\TextColumn::make('cost_stock_value')
                    ->label('Cost Stock Value')
                    ->getStateUsing(fn (Product $r): string =>
                        '₦' . number_format((float) $r->stock_quantity * (float) $r->cost_price)
                    )
                    ->toggleable(isToggledHiddenByDefault: true),

                Tables\Columns\TextColumn::make('profit_potential')
                    ->label('Profit Potential')
                    ->getStateUsing(fn (Product $r): string =>
                        '₦' . number_format((float) $r->stock_quantity * ((float) $r->price - (float) $r->cost_price))
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
                        $r->stock_quantity === 0 => 'Out of Stock',
                        $r->stock_quantity < 5   => 'Low Stock',
                        default                   => 'Healthy',
                    })
                    ->color(fn (string $state): string => match ($state) {
                        'Out of Stock' => 'danger',
                        'Low Stock'    => 'warning',
                        default        => 'success',
                    }),
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
                        'out'     => $q->where('stock_quantity', 0),
                        'low'     => $q->where('stock_quantity', '>', 0)->where('stock_quantity', '<', 5),
                        'healthy' => $q->where('stock_quantity', '>=', 5),
                        default   => $q,
                    }),
            ])
            ->paginated([15, 25, 50]);
    }
}

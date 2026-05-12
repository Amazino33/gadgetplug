<?php

declare(strict_types=1);

namespace App\Filament\Vendor\Widgets;

use App\Models\Order;
use Filament\Widgets\ChartWidget;
use Filament\Facades\Filament;
use Illuminate\Support\Carbon;

class SalesChannelChart extends ChartWidget
{
    protected null|string $heading = 'Sales Channel Breakdown (Today)';
    protected static ?int $sort = 2;
    protected int|string|array $columnSpan = 'full';

    protected function getType(): string
    {
        return 'pie';
    }

    protected function getData(): array
    {
        $vendorId = Filament::getTenant()?->id;

        // Query today's paid orders
        $todayOrders = Order::where('status', 'paid')
            // ->where('vendor_id', $vendorId) // Uncomment if orders has vendor_id
            ->whereDate('created_at', Carbon::today())
            ->get();

        // Separate by payment method. Paystack = Online, Cash/Transfer = POS.
        $onlineSales = $todayOrders->where('payment_method', 'paystack')->sum('total_amount');
        $posSales = $todayOrders->whereIn('payment_method', ['cash', 'transfer'])->sum('total_amount');

        return [
            'datasets' => [
                [
                    'label' => 'Revenue (₦)',
                    'data' => [$onlineSales, $posSales],
                    'backgroundColor' => [
                        '#3b82f6', // Tailwind Blue for Online
                        '#10b981', // Tailwind Green for POS
                    ],
                ],
            ],
            'labels' => ['Online App', 'Physical POS'],
        ];
    }
}
<?php

namespace App\Filament\Pages\Delivery;

use App\Filament\Pages\DeliverySettings;
use App\Models\DeliveryRule;
use App\Models\Lga;
use App\Models\Store;
use App\Support\Plural;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Livewire\Attributes\On;

/** The summary row at the top of Delivery settings. */
class DeliveryStats extends StatsOverviewWidget
{
    protected int|string|array $columnSpan = 'full';

    // Rendered with the page, not when scrolled to: a blank card where a table
    // belongs reads as broken.
    protected static bool $isLazy = false;

    protected ?string $pollingInterval = null;

    // Two by two on a phone, one row of four on a wide screen.
    protected int|array|null $columns = ['default' => 2, '@xl' => 4, '!@lg' => 4];

    public static function canView(): bool
    {
        return DeliverySettings::canAccess();
    }

    public function boot(): void
    {
        DeliveryRuleForm::authorize();
    }

    #[On('delivery-rules-changed')]
    public function refreshStats(): void
    {
        // Re-rendering recomputes getStats().
    }

    /** @return array{delivered: int, free: int, paid: int, total: int, pinned: int, stores: int} */
    public static function summary(): array
    {
        $modes = Lga::query()->with(['overrideRule', 'state.defaultRule'])->get()
            ->map(fn (Lga $lga) => $lga->effectiveRule()['mode'])
            ->countBy();

        $stores = Store::query()->where('is_active', true);

        return [
            'delivered' => ($modes[DeliveryRule::FREE] ?? 0) + ($modes[DeliveryRule::FEE] ?? 0),
            'free'      => $modes[DeliveryRule::FREE] ?? 0,
            'paid'      => $modes[DeliveryRule::FEE] ?? 0,
            'total'     => $modes->sum(),
            'pinned'    => (clone $stores)->whereNotNull('latitude')->whereNotNull('longitude')->count(),
            'stores'    => $stores->count(),
        ];
    }

    protected function getStats(): array
    {
        $s = static::summary();

        return [
            Stat::make('Areas we deliver to', $s['delivered'].' '.Plural::lgas($s['delivered']))
                ->description('of '.$s['total'].' in Nigeria')
                ->color($s['delivered'] > 0 ? 'success' : 'gray'),

            Stat::make('Free delivery', (string) $s['free'])
                ->description(Plural::lgas($s['free']))
                ->color('success'),

            Stat::make('Paid delivery', (string) $s['paid'])
                ->description(Plural::lgas($s['paid']))
                ->color('warning'),

            Stat::make('Hubs pinned', $s['pinned'].' of '.$s['stores'])
                ->description($s['pinned'] === 0 ? 'Pin a store so distances can be worked out' : 'stores on the map')
                ->color($s['pinned'] === 0 ? 'danger' : ($s['pinned'] < $s['stores'] ? 'warning' : 'success')),
        ];
    }

}

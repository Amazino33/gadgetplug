<?php

declare(strict_types=1);

namespace App\Filament\Vendor\Widgets;

use App\Models\BlindCountEntry;
use App\Models\BlindCountSession;
use Filament\Widgets\Widget;
use Illuminate\Support\Collection;

class BlindCountInProgressWidget extends Widget
{
    protected static bool $isLazy = false;

    protected string $view = 'filament.vendor.widgets.blind-count-in-progress';

    public function getSessions(): Collection
    {
        $vendor = filament()->getTenant();
        if (! $vendor) return collect();

        return BlindCountSession::where('vendor_id', $vendor->id)
            ->whereIn('status', ['a_counting', 'b_counting'])
            ->with(['storekeeperA', 'storekeeperB'])
            ->latest()
            ->get()
            ->map(function (BlindCountSession $session) {
                $session->counted_so_far = BlindCountEntry::where('blind_count_session_id', $session->id)
                    ->whereNotNull('count')
                    ->count();
                return $session;
            });
    }
}

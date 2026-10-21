<?php

namespace App\Filament\Widgets;

use App\Filament\Pages\MessagingSettings;
use App\Services\Messaging\WhatsAppGate;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * WhatsApp health on the admin dashboard: is the Wawp session sending or
 * paused, when it last succeeded, failures today, and today's sends against
 * the cap. Resume lives on the WhatsApp settings page it links to.
 */
class WhatsAppHealthWidget extends StatsOverviewWidget
{
    protected static ?int $sort = -10;

    public static function canView(): bool
    {
        return auth()->user()?->isSuperAdmin() ?? false;
    }

    protected function getStats(): array
    {
        $h = app(WhatsAppGate::class)->health();
        $settings = MessagingSettings::getUrl(panel: 'admin');

        return [
            Stat::make('WhatsApp', $h['paused'] ? 'Paused' : 'Connected')
                ->description($h['paused'] ? ($h['pause_reason'] ?? 'Paused') : 'Sending through Wawp')
                ->color($h['paused'] ? 'danger' : 'success')
                ->url($settings),

            Stat::make('Last success', $h['last_success']?->diffForHumans() ?? 'Never')
                ->description($h['failures_row'].' failed in a row')
                ->color($h['failures_row'] > 0 ? 'warning' : 'gray'),

            Stat::make('Failures today', (string) $h['failures_today'])
                ->color($h['failures_today'] > 0 ? 'warning' : 'gray'),

            Stat::make('Sent today', $h['sent_today'].' / '.$h['daily_cap'])
                ->description($h['warmup'] ? 'Warm-up cap' : 'Daily cap')
                ->color($h['sent_today'] >= $h['daily_cap'] ? 'danger' : 'gray'),
        ];
    }
}

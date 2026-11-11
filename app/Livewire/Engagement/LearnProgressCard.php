<?php

declare(strict_types=1);

namespace App\Livewire\Engagement;

use App\Filament\Vendor\Pages\LearnGadgetPlug;
use App\Models\TipEvent;
use App\Models\User;
use App\Models\Vendor;
use App\Support\Engagement\LearnCatalogue;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Lazy;
use Livewire\Component;

/**
 * The small "You've used X of Y features" card at the top of the vendor
 * dashboard, linking to Learn GadgetPlug.
 *
 * Lazy, so working out the progress never holds up the dashboard. Hidden for
 * good once dismissed (an append-only event, like everything else here), once
 * every feature is used, and for anyone who can't open the Learn page.
 */
#[Lazy]
class LearnProgressCard extends Component
{
    public bool $dismissed = false;

    public function placeholder(): string
    {
        return '<div></div>';
    }

    public function dismiss(): void
    {
        [$user, $vendor] = $this->who();

        if (! $user || ! $vendor || ! LearnGadgetPlug::canAccess()) {
            return;
        }

        $catalogue = app(LearnCatalogue::class);

        if (! $catalogue->cardDismissed($user, $vendor)) {
            $catalogue->record($user, $vendor, TipEvent::DISMISSED, featureKey: LearnCatalogue::CARD_KEY);
        }

        $this->dismissed = true;
    }

    public function render(): View
    {
        [$user, $vendor] = $this->who();
        $progress = null;

        if (! $this->dismissed && $user && $vendor && LearnGadgetPlug::canAccess()) {
            $catalogue = app(LearnCatalogue::class);

            if (! $catalogue->cardDismissed($user, $vendor)) {
                $progress = $catalogue->progress($user, $vendor);

                if ($progress['total'] === 0 || $progress['used'] >= $progress['total']) {
                    $progress = null;
                }
            }
        }

        return view('livewire.engagement.learn-progress-card', [
            'progress' => $progress,
            'unread' => $progress && $user && $vendor ? app(LearnCatalogue::class)->unreadCount($user, $vendor) : 0,
            'url' => $progress && $vendor ? LearnGadgetPlug::getUrl(tenant: $vendor) : null,
        ]);
    }

    /** @return array{0: User|null, 1: Vendor|null} */
    private function who(): array
    {
        $user = auth()->user();
        $vendor = filament()->getTenant();

        return [$user instanceof User ? $user : null, $vendor instanceof Vendor ? $vendor : null];
    }
}

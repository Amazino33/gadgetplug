<?php

declare(strict_types=1);

namespace App\Livewire\Engagement;

use App\Models\Tip;
use App\Models\TipEvent;
use App\Services\ActiveStore;
use App\Support\Engagement\TipCard;
use App\Support\Engagement\TipContext;
use App\Support\Engagement\TipEligibility;
use App\Support\Engagement\TipFeedback;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Lazy;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * The tip pop-up, lazily loaded at the end of every panel page so choosing a
 * tip never holds up the page itself.
 *
 * TipEligibility picks the tip on mount. Nothing is logged until the browser
 * actually shows it (markShown), which it does only after the tip's delay and
 * only when no announcement, tour or Filament modal is in the way. Every
 * later action logs one event and is a no-op without a tip that was shown.
 *
 * "Preview as me" (?gp_tip_preview={id} for tip managers) shows that tip
 * whatever its status or the caps, and logs nothing.
 */
#[Lazy]
class TipPopup extends Component
{
    #[Locked]
    public ?string $routeName = null;

    #[Locked]
    public ?int $tipId = null;

    #[Locked]
    public ?int $vendorId = null;

    #[Locked]
    public ?string $buttonUrl = null;

    #[Locked]
    public ?string $buttonEvent = null;

    #[Locked]
    public bool $preview = false;

    #[Locked]
    public bool $shown = false;

    #[Locked]
    public bool $played = false;

    public function mount(?string $routeName = null, ?int $previewTipId = null): void
    {
        $this->routeName = $routeName;
        $context = TipContext::current($routeName);

        if (! $context) {
            return;
        }

        $this->vendorId = $context->vendor?->id;
        $eligibility = app(TipEligibility::class);

        if ($previewTipId) {
            $tip = Tip::find($previewTipId);

            if ($tip && Gate::allows('preview', $tip)) {
                $button = $eligibility->button($tip, $context);
                $this->tipId = $tip->id;
                $this->buttonUrl = $button['url'];
                $this->buttonEvent = $button['event'];
                $this->preview = true;

                return;
            }
        }

        $decision = $eligibility->pick($context);

        if ($decision) {
            $this->tipId = $decision->tip->id;
            $this->buttonUrl = $decision->buttonUrl;
            $this->buttonEvent = $decision->buttonEvent;
        }
    }

    public function placeholder(): string
    {
        return '<div></div>';
    }

    /** The browser has put the tip on screen. Logged once. */
    #[Renderless]
    public function markShown(): void
    {
        if (! $this->tipId || $this->preview || $this->shown) {
            return;
        }

        $this->shown = true;
        $this->log(TipEvent::SHOWN);
    }

    /** The button: logged, then off to the destination (or the page event). */
    #[Renderless]
    public function pressButton(): void
    {
        if (! $this->tipId) {
            return;
        }

        $this->markShown();
        $this->log(TipEvent::CLICKED);

        if ($this->buttonUrl) {
            $this->redirect($this->buttonUrl);
        }
    }

    /** "Not now", the close button, the backdrop or a swipe down. */
    #[Renderless]
    public function notNow(): void
    {
        if (! $this->tipId) {
            return;
        }

        $this->markShown();
        $this->log(TipEvent::SNOOZED);
    }

    /** "Don't show again". */
    #[Renderless]
    public function dismiss(): void
    {
        if (! $this->tipId) {
            return;
        }

        $this->markShown();
        $this->log(TipEvent::DISMISSED);
    }

    #[Renderless]
    public function rate(string $answer): void
    {
        if (! $this->tipId || ! $this->shown || ! in_array($answer, ['yes', 'no'], true)) {
            return;
        }

        $this->log($answer === 'yes' ? TipEvent::HELPFUL_YES : TipEvent::HELPFUL_NO);
    }

    #[Renderless]
    public function videoPlayed(): void
    {
        if (! $this->tipId || ! $this->shown || $this->played) {
            return;
        }

        $this->played = true;
        $this->log(TipEvent::VIDEO_PLAYED);
    }

    public function render(): View
    {
        $tip = $this->tipId ? Tip::withTrashed()->find($this->tipId) : null;

        return view('livewire.engagement.tip-popup', [
            'card' => $tip ? TipCard::fromTip($tip, $this->buttonUrl !== null || $this->buttonEvent !== null) : null,
            'answered' => $tip && auth()->id() && ! $this->preview
                ? TipFeedback::answerOf((int) auth()->id(), $this->vendorId, $tip->id)
                : null,
        ]);
    }

    private function log(string $event): void
    {
        if ($this->preview || ! auth()->check()) {
            return;
        }

        TipEvent::create([
            'tip_id' => $this->tipId,
            'user_id' => auth()->id(),
            'vendor_id' => $this->vendorId,
            'store_id' => ActiveStore::currentId(),
            'event' => $event,
            'route' => $this->routeName,
        ]);
    }
}

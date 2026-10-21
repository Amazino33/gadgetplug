<?php

use Livewire\Attributes\Renderless;
use Livewire\Volt\Component;
use Livewire\WithFileUploads;
use Livewire\WithPagination;
use Illuminate\Support\Str;
use App\Models\Affiliate;
use App\Models\AffiliateClick;
use App\Models\AffiliateCommission;
use App\Models\AffiliateLevel;
use App\Models\AffiliateReachBand;
use App\Models\AffiliateSetting;
use App\Models\AffiliateTask;
use App\Models\AffiliateTaskSubmission;
use App\Models\PlugPointTransaction;
use App\Models\Product;
use App\Models\WalletTransaction;
use App\Services\Affiliate\AffiliateLevelProgressionService;
use App\Services\Affiliate\AffiliateTaskService;
use App\Services\Affiliate\DailySocialShareService;
use App\Services\Affiliate\MarketingMaterialService;
use App\Services\Affiliate\PlugPointService;
use App\Services\Affiliate\PointConversionService;
use App\Services\Affiliate\ProductLinkCatalogue;
use App\Services\Affiliate\QrCodeService;
use App\Services\Affiliate\WalletService;

new class extends Component {
    use WithFileUploads, WithPagination;

    public ?Affiliate $affiliate = null;

    // True only on the request that mounts the page — see productCatalogue.
    protected bool $withProductCatalogue = false;

    public ?int $submittingTaskId = null;
    public string $taskNotes = '';
    public $proofFile = null;

    public string $bankName = '';
    public string $accountNumber = '';
    public string $accountName = '';

    public string $pointsToConvert = '';
    public string $conversionNonce = '';

    public string $shareReach = '';
    public string $shareNotes = '';
    public $shareProof = null;

    public function mount(): void
    {
        $this->affiliate = auth()->user()->affiliate;
        $this->conversionNonce = (string) Str::uuid();
        $this->withProductCatalogue = true;

        if ($this->affiliate) {
            $this->bankName = $this->affiliate->bank_name ?? '';
            $this->accountNumber = $this->affiliate->account_number ?? '';
            $this->accountName = $this->affiliate->account_name ?? '';
        }
    }

    // ─── Bank details ───────────────────────────────────────────────────

    public function saveBankDetails(): void
    {
        $this->validate([
            'bankName'      => 'required|string|max:100',
            'accountNumber' => 'required|digits:10',
            'accountName'   => 'required|string|max:150',
        ]);

        $this->affiliate->update([
            'bank_name'      => $this->bankName,
            'account_number' => $this->accountNumber,
            'account_name'   => $this->accountName,
        ]);

        session()->flash('bankDetailsSaved', 'Bank details saved — you\'re set to receive weekly payouts.');
    }

    // ─── Product links ──────────────────────────────────────────────────

    /**
     * Every linkable product, for the page's in-browser search.
     *
     * Only on the first render: this is ~90KB, and every later request on the
     * page (a task submit, a page of commissions) re-renders the component —
     * the search lives under wire:ignore, so those renders never need it. Not a
     * public property either, or it would ride in every request's snapshot.
     */
    public function getProductCatalogueProperty(): array
    {
        return ($this->affiliate && $this->withProductCatalogue)
            ? app(ProductLinkCatalogue::class)->forBrowser()
            : [];
    }

    /**
     * One product's link + QR, fetched only when the affiliate asks for it.
     *
     * Renderless: the answer goes straight back to Alpine, so opening a QR
     * never re-renders the dashboard. Must be reachable on the storefront
     * (visibleOnline) — a link to anything else falls back to the homepage.
     */
    #[Renderless]
    public function productQr(int $productId): ?array
    {
        $product = $this->affiliate ? Product::visibleOnline()->find($productId) : null;

        if (! $product) {
            return null;
        }

        $qr = app(QrCodeService::class);

        return [
            'link' => $qr->productLinkUrl($this->affiliate, $product),
            'svg'  => $qr->productQrSvg($this->affiliate, $product),
        ];
    }

    public function getReferralLinkProperty(): ?string
    {
        return $this->affiliate ? app(QrCodeService::class)->referralLinkUrl($this->affiliate) : null;
    }

    public function getReferralQrSvgProperty(): ?string
    {
        return $this->affiliate ? app(QrCodeService::class)->referralQrSvg($this->affiliate) : null;
    }

    // ─── Wallet ─────────────────────────────────────────────────────────

    public function getPendingBalanceProperty(): float
    {
        return $this->affiliate ? app(WalletService::class)->pendingBalance($this->affiliate->id) : 0.0;
    }

    public function getAvailableBalanceProperty(): float
    {
        return $this->affiliate ? app(WalletService::class)->availableBalance($this->affiliate->id) : 0.0;
    }

    public function getWalletHistoryProperty()
    {
        return $this->affiliate
            ? WalletTransaction::where('affiliate_id', $this->affiliate->id)->latest()->paginate(8, ['*'], 'walletPage')
            : null;
    }

    // ─── Plug Points ────────────────────────────────────────────────────

    public function getPointsBalanceProperty(): int
    {
        return $this->affiliate ? app(PlugPointService::class)->balance($this->affiliate->id) : 0;
    }

    public function getPointsHistoryProperty()
    {
        return $this->affiliate
            ? PlugPointTransaction::where('affiliate_id', $this->affiliate->id)->latest()->paginate(6, ['*'], 'pointsPage')
            : null;
    }

    public function getConversionQuoteProperty(): float
    {
        return app(PointConversionService::class)->quote(max((int) $this->pointsToConvert, 0));
    }

    public function getMinConversionProperty(): int
    {
        return (int) AffiliateSetting::current()->min_points_conversion;
    }

    public function convertPoints(): void
    {
        $this->validate(['pointsToConvert' => 'required|integer|min:1']);

        try {
            // One nonce per conversion intent. Two requests that fire before
            // the first commits — a double-click, a retried request — carry the
            // same nonce and collapse into one conversion. A deliberate second
            // conversion happens after the nonce is rotated below, so it goes
            // through normally.
            $conversion = app(PointConversionService::class)
                ->convert($this->affiliate, (int) $this->pointsToConvert, $this->conversionNonce);

            $this->conversionNonce = (string) Str::uuid();
            $this->pointsToConvert = '';
            session()->flash('pointsConverted', 'Converted to ₦' . number_format((float) $conversion->amount, 2) . ' — it is in your available balance now.');
        } catch (\RuntimeException $e) {
            $this->addError('pointsToConvert', $e->getMessage());
        }
    }

    // ─── Daily social share ─────────────────────────────────────────────

    public function getShareTaskProperty(): ?AffiliateTask
    {
        return AffiliateTask::dailyShare()->where('is_active', true)->first();
    }

    public function getShareWindowOpenProperty(): bool
    {
        return app(DailySocialShareService::class)->windowIsOpen();
    }

    public function getShareWindowLabelProperty(): string
    {
        return app(DailySocialShareService::class)->windowDescription();
    }

    public function getCurrentStreakProperty(): int
    {
        return $this->affiliate ? app(DailySocialShareService::class)->currentStreak($this->affiliate->id) : 0;
    }

    public function getTodaysShareProperty(): ?AffiliateTaskSubmission
    {
        if (! $this->affiliate || ! $this->shareTask) {
            return null;
        }

        $today = app(DailySocialShareService::class)->shareDateFor()->toDateString();

        return AffiliateTaskSubmission::where('affiliate_id', $this->affiliate->id)
            ->where('affiliate_task_id', $this->shareTask->id)
            ->whereDate('share_date', $today)
            ->latest()
            ->first();
    }

    public function getReachBandsProperty()
    {
        return AffiliateReachBand::active()->orderBy('min_reach')->get();
    }

    public function submitShare(): void
    {
        $this->validate([
            'shareReach' => 'required|integer|min:0',
            'shareProof' => 'required|image|max:5120',
        ], [], ['shareReach' => 'reach', 'shareProof' => 'screenshot']);

        try {
            $submission = app(DailySocialShareService::class)
                ->submit($this->shareTask, $this->affiliate, (int) $this->shareReach, $this->shareNotes ?: null);

            $submission->addMedia($this->shareProof->getRealPath())
                ->usingFileName($this->shareProof->getClientOriginalName())
                ->toMediaCollection('proof');

            $this->shareReach = '';
            $this->shareNotes = '';
            $this->shareProof = null;

            session()->flash('shareSubmitted', 'Share submitted — an admin will review it and your points will land shortly.');
        } catch (\RuntimeException $e) {
            $this->addError('shareProof', $e->getMessage());
        }
    }

    // ─── Marketing material ─────────────────────────────────────────────

    public function getMaterialsProperty()
    {
        return $this->affiliate
            ? app(MarketingMaterialService::class)->forAffiliate($this->affiliate)
            : collect();
    }

    // ─── Traffic ────────────────────────────────────────────────────────

    /**
     * Visits are shown as a funnel — arrived, then engaged — because the gap
     * between the two is the number the affiliate can actually act on: it's
     * the share of their audience that bounced.
     */
    public function getTrafficStatsProperty(): array
    {
        if (! $this->affiliate) {
            return ['clicks' => 0, 'engaged' => 0, 'earned' => 0.0];
        }

        $clicks = AffiliateClick::where('affiliate_id', $this->affiliate->id);

        return [
            'clicks'  => (clone $clicks)->count(),
            'engaged' => (clone $clicks)->engaged()->count(),
            'earned'  => (float) (clone $clicks)->sum('reward_amount'),
        ];
    }

    /** Nothing clicked, earned or pending yet — Home leads with their link instead of a row of zeros. */
    public function getIsNewAffiliateProperty(): bool
    {
        return $this->trafficStats['clicks'] === 0
            && $this->availableBalance == 0.0
            && $this->pendingBalance == 0.0
            && $this->pointsBalance === 0;
    }

    // ─── Level & progress ───────────────────────────────────────────────

    public function getLifetimeValueProperty(): float
    {
        return $this->affiliate ? app(AffiliateLevelProgressionService::class)->totalProgressValue($this->affiliate->id) : 0.0;
    }

    public function getNextLevelProperty(): ?AffiliateLevel
    {
        if (! $this->affiliate) {
            return null;
        }

        $currentSortOrder = $this->affiliate->level?->sort_order ?? -1;

        return AffiliateLevel::where('is_active', true)
            ->where('sort_order', '>', $currentSortOrder)
            ->orderBy('sort_order')
            ->first();
    }

    // ─── Commissions ────────────────────────────────────────────────────

    public function getCommissionsProperty()
    {
        return $this->affiliate
            ? AffiliateCommission::where('affiliate_id', $this->affiliate->id)->with('order')->latest()->paginate(8, ['*'], 'commissionsPage')
            : null;
    }

    // ─── Tasks ──────────────────────────────────────────────────────────

    public function getAvailableTasksProperty()
    {
        return AffiliateTask::where('is_active', true)->orderByDesc('verification_type')->get();
    }

    public function getMySubmissionsProperty()
    {
        return $this->affiliate
            ? $this->affiliate->taskSubmissions()->with('task')->latest()->paginate(8, ['*'], 'submissionsPage')
            : null;
    }

    public function taskEligibility(AffiliateTask $task): bool
    {
        return $this->affiliate && app(AffiliateTaskService::class)->isEligible($task, $this->affiliate);
    }

    public function openTaskSubmission(int $taskId): void
    {
        $this->submittingTaskId = $taskId;
        $this->taskNotes = '';
        $this->proofFile = null;
        $this->resetErrorBag();
    }

    public function cancelTaskSubmission(): void
    {
        $this->submittingTaskId = null;
        $this->taskNotes = '';
        $this->proofFile = null;
    }

    public function submitTask(): void
    {
        $this->validate([
            'taskNotes' => 'nullable|string|max:1000',
            'proofFile' => 'nullable|image|max:5120',
        ]);

        $task = AffiliateTask::findOrFail($this->submittingTaskId);

        try {
            $submission = app(AffiliateTaskService::class)->submit($task, $this->affiliate, $this->taskNotes ?: null);

            if ($this->proofFile) {
                $submission->addMedia($this->proofFile->getRealPath())
                    ->usingFileName($this->proofFile->getClientOriginalName())
                    ->toMediaCollection('proof');
            }

            $this->cancelTaskSubmission();
            session()->flash('taskSubmitted', 'Submitted! An admin will review it soon.');
        } catch (\RuntimeException $e) {
            $this->addError('submission', $e->getMessage());
        }
    }
}; ?>

<div>
<x-layouts.account active="account.affiliate">

    @if (! $affiliate)
        <div class="bg-white dark:bg-[#162016] border border-brand-border dark:border-[#2a3a2a] rounded-2xl p-6 text-center">
            <div class="text-3xl mb-2">🔗</div>
            <h2 class="font-montserrat font-bold text-[15px] text-brand-dark dark:text-[#e8f5e9] mb-1">
                You're not registered as an affiliate yet
            </h2>
            <p class="text-[12px] text-brand-muted">
                Contact GadgetPlug support if you'd like to join the affiliate program.
            </p>
        </div>
    @else
        {{-- Four tabs, switched in the browser: every section renders once on
        load and changing tab never calls the server. The active tab survives
        Livewire re-renders (Alpine keeps its state through a morph) and a
        reload in the same browser tab ($persist on sessionStorage), so
        submitting a task never bounces the affiliate back to Home. --}}
        <div
            x-data="{
                tab: $persist('home').using(sessionStorage).as('gp-affiliate-tab'),
                headerOffset: 0,
                tabbarHeight: 0,

                init() {
                    if (! ['home', 'promote', 'earn', 'wallet'].includes(this.tab)) this.tab = 'home'
                    this.$nextTick(() => this.measure())
                },

                // The storefront header is sticky and its height varies with the
                // screen, so the tab strip pins itself under what it measures.
                measure() {
                    this.headerOffset = document.querySelector('header.sticky')?.offsetHeight ?? 0
                    this.tabbarHeight = this.$refs.tabbar?.offsetHeight ?? 0
                },

                get stickyTop() {
                    return this.headerOffset + this.tabbarHeight + 8
                },

                // Switch tab. With a target, bring that section into view under
                // the sticky strips; without one, only scroll back up if the
                // reader would otherwise be left below the end of a shorter tab.
                go(tab, target = null) {
                    this.tab = tab
                    this.$nextTick(() => {
                        let el = target ? document.getElementById(target) : this.$refs.panels
                        if (! el) return
                        let y = el.getBoundingClientRect().top + window.scrollY - this.stickyTop
                        if (target || window.scrollY > y) window.scrollTo({ top: Math.max(y, 0), behavior: 'smooth' })
                    })
                },

                // The Clipboard API where it works (HTTPS, permission granted),
                // the old select-and-copy where it doesn't. Resolves false when
                // neither worked, so the caller can show the link to copy by hand.
                async copyText(text) {
                    try {
                        if (navigator.clipboard && window.isSecureContext) {
                            await navigator.clipboard.writeText(text)
                            return true
                        }
                    } catch (e) {}

                    let field = document.createElement('textarea')
                    field.value = text
                    field.style.cssText = 'position:fixed;top:0;left:0;opacity:0;font-size:16px'
                    document.body.appendChild(field)
                    field.focus()
                    field.select()
                    field.setSelectionRange(0, text.length)
                    let ok = false
                    try { ok = document.execCommand('copy') } catch (e) {}
                    field.remove()
                    return ok
                },
            }"
            x-on:resize.window.debounce.150ms="measure()"
        >
            {{-- Tab strip --}}
            <div x-ref="tabbar" class="sticky z-[90] pb-3 bg-brand-bg dark:bg-[#0d1a0d]" :style="{ top: headerOffset + 'px' }">
                <div role="tablist" aria-label="Affiliate dashboard"
                     class="flex gap-1 p-1 bg-white dark:bg-[#162016] border border-brand-border dark:border-[#2a3a2a] rounded-2xl overflow-x-auto scrollbar-none">
                    @foreach (['home' => 'Home', 'promote' => 'Promote', 'earn' => 'Earn', 'wallet' => 'Wallet'] as $key => $label)
                    <button type="button" role="tab" aria-controls="tab-{{ $key }}"
                            x-on:click="go('{{ $key }}')"
                            :aria-selected="tab === '{{ $key }}'"
                            :class="tab === '{{ $key }}' ? 'bg-brand text-white' : 'text-[#444] dark:text-[#b0c8b0] hover:text-brand'"
                            class="flex-1 min-w-[72px] h-11 px-3 rounded-xl font-montserrat font-bold text-[13px] whitespace-nowrap transition-colors">
                        {{ $label }}
                    </button>
                    @endforeach
                </div>
            </div>

            <div x-ref="panels"></div>

            {{-- ═══ Home ═══ --}}
            <section id="tab-home" role="tabpanel" x-show="tab === 'home'" x-cloak class="space-y-5">

                @if ($this->isNewAffiliate)
                {{-- Nothing clicked or earned yet: lead with the one thing to do --}}
                <div x-data="{ copied: false }" class="bg-white dark:bg-[#162016] border border-brand-border dark:border-[#2a3a2a] rounded-2xl p-5 md:p-6">
                    <h2 class="font-montserrat font-bold text-[17px] text-brand-dark dark:text-[#e8f5e9] mb-1">Here's your link — go share it</h2>
                    <p class="text-[13px] text-brand-muted mb-4">
                        Post it on WhatsApp, Instagram, anywhere. Anyone who buys through it is tracked as yours, and your earnings show up here.
                    </p>
                    <div class="flex flex-col sm:flex-row gap-2">
                        <input x-ref="homeLink" readonly value="{{ $this->referralLink }}" aria-label="Your referral link"
                            x-on:focus="$el.select()"
                            class="w-full sm:flex-1 min-w-0 h-11 px-3.5 bg-brand-bg dark:bg-[#0d1a0d] border border-[#d0d9d2] dark:border-[#2a3a2a] rounded-xl text-[13px] text-[#111] dark:text-[#e8f5e9] focus:outline-none">
                        <button type="button"
                            x-on:click="copyText($refs.homeLink.value).then(ok => { if (ok) { copied = true; setTimeout(() => copied = false, 1800) } else { $refs.homeLink.focus() } })"
                            class="h-11 px-5 bg-brand hover:bg-[#055002] text-white font-montserrat font-bold text-[13px] rounded-xl transition-colors flex-shrink-0">
                            <span x-text="copied ? 'Copied!' : 'Copy link'">Copy link</span>
                        </button>
                    </div>
                </div>
                @else
                {{-- Balances --}}
                <div class="bg-white dark:bg-[#162016] border border-brand-border dark:border-[#2a3a2a] rounded-2xl p-5 md:p-6">
                    <h2 class="font-montserrat font-bold text-[15px] text-brand-dark dark:text-[#e8f5e9] mb-4">Balances</h2>
                    <div class="grid grid-cols-2 md:grid-cols-3 gap-4">
                        <div>
                            <div class="text-[12px] text-brand-muted mb-1">Available</div>
                            <div class="font-montserrat font-black text-[19px] md:text-[22px] leading-tight text-brand break-all">₦{{ number_format($this->availableBalance, 2) }}</div>
                        </div>
                        <div>
                            <div class="text-[12px] text-brand-muted mb-1">Pending</div>
                            <div class="font-montserrat font-black text-[19px] md:text-[22px] leading-tight text-brand-dark dark:text-[#e8f5e9] break-all">₦{{ number_format($this->pendingBalance, 2) }}</div>
                        </div>
                        <div class="col-span-2 md:col-span-1">
                            <div class="text-[12px] text-brand-muted mb-1">Plug Points</div>
                            <div class="font-montserrat font-black text-[19px] md:text-[22px] leading-tight text-brand-dark dark:text-[#e8f5e9]">{{ number_format($this->pointsBalance) }}</div>
                        </div>
                    </div>
                    <p class="text-[12px] text-brand-muted mt-3">
                        Available is what's actually payable — pending is still in its return-window hold. Points turn into cash on the Earn tab.
                    </p>
                </div>
                @endif

                {{-- The two things to do --}}
                @php $shareOpenToday = $this->shareTask && $this->shareWindowOpen && ! $this->todaysShare; @endphp
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                    <button type="button" x-on:click="go('promote', 'product-links'); $dispatch('focus-product-search')"
                        class="min-h-[64px] px-5 py-3 bg-brand hover:bg-[#055002] text-white rounded-2xl text-left transition-colors flex items-center gap-3">
                        <svg class="w-6 h-6 fill-none flex-shrink-0" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true">
                            <circle cx="18" cy="5" r="3"/><circle cx="6" cy="12" r="3"/><circle cx="18" cy="19" r="3"/>
                            <line x1="8.59" y1="13.51" x2="15.42" y2="17.49"/><line x1="15.41" y1="6.51" x2="8.59" y2="10.49"/>
                        </svg>
                        <span>
                            <span class="block font-montserrat font-bold text-[15px]">Share a product</span>
                            <span class="block text-[12px] text-white/80">Find it, copy your link, post it</span>
                        </span>
                    </button>
                    <button type="button" x-on:click="go('earn', '{{ $this->shareTask ? 'todays-share' : 'earn-tasks' }}')"
                        class="min-h-[64px] px-5 py-3 bg-white dark:bg-[#162016] border border-brand text-brand hover:bg-brand-bg dark:hover:bg-[#1a2a1a] rounded-2xl text-left transition-colors flex items-center gap-3">
                        <svg class="w-6 h-6 fill-none flex-shrink-0" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true">
                            <polyline points="9 11 12 14 22 4"/><path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"/>
                        </svg>
                        <span>
                            <span class="block font-montserrat font-bold text-[15px]">Today's task</span>
                            <span class="block text-[12px] text-brand-muted">{{ $shareOpenToday ? "Today's share is open — earn points" : 'Daily share, tasks and points' }}</span>
                        </span>
                    </button>
                </div>

                @if (! $affiliate->hasBankDetails())
                <button type="button" x-on:click="go('wallet', 'bank-details')"
                    class="w-full min-h-[44px] flex items-center justify-between gap-3 bg-amber-50 dark:bg-amber-900/20 border border-amber-200 dark:border-amber-800 text-amber-700 dark:text-amber-400 rounded-xl px-4 py-3 text-[13px] font-semibold text-left">
                    <span>Add your bank details so we can pay you.</span>
                    <span aria-hidden="true">→</span>
                </button>
                @endif

                {{-- Level --}}
                <div class="bg-white dark:bg-[#162016] border border-brand-border dark:border-[#2a3a2a] rounded-2xl p-5 md:p-6">
                    <h2 class="font-montserrat font-bold text-[15px] text-brand-dark dark:text-[#e8f5e9] mb-4">Level</h2>
                    @if ($affiliate->level)
                        <div class="flex items-center gap-2 mb-2">
                            <span class="inline-flex items-center px-2.5 py-1 rounded-full text-[12px] font-bold bg-[#e8f5e9] dark:bg-[#1a2a1a] text-brand">
                                {{ $affiliate->level->name }}
                            </span>
                        </div>
                    @else
                        <div class="text-[13px] text-brand-muted mb-2">No level yet</div>
                    @endif

                    @if ($this->nextLevel)
                        @php
                        $remaining = max((float) $this->nextLevel->target - $this->lifetimeValue, 0);
                        $target = max((float) $this->nextLevel->target, 1);
                        $progressPct = min(100, (int) (($this->lifetimeValue / $target) * 100));
                        @endphp
                        <p class="text-[11px] text-brand-muted mb-1.5">
                            ₦{{ number_format($remaining, 2) }} more to reach {{ $this->nextLevel->name }}
                        </p>
                        <div class="w-full h-1.5 bg-brand-bg dark:bg-[#0d1a0d] rounded-full overflow-hidden">
                            <div class="h-full bg-brand" style="width: {{ $progressPct }}%"></div>
                        </div>
                    @else
                        <p class="text-[11px] text-brand-muted">Highest level reached 🎉</p>
                    @endif
                </div>

            </section>

            {{-- ═══ Promote ═══ --}}
            <section id="tab-promote" role="tabpanel" x-show="tab === 'promote'" x-cloak class="space-y-5">

                {{-- Product links. The whole catalogue ships once with the page and is
                searched here in the browser — no request per keystroke, so nothing
                can race or wipe the list. wire:ignore keeps later re-renders (which
                don't carry the catalogue, see productCatalogue) from touching it.
                It matches on the name a customer sees, the internal name and the
                SKU; only the customer-facing name is shown. --}}
                <div id="product-links" wire:ignore
                    x-data="{
                        products: @js($this->productCatalogue),
                        linkBase: @js($this->referralLink),
                        q: '',
                        limit: 30,
                        copiedId: null,
                        copiedTimer: null,
                        manual: null,
                        qr: null,
                        qrLoadingId: null,
                        qrFailedId: null,

                        init() {
                            this.products.forEach(p => p.haystack = [p.displayName, p.name, p.sku].join(' ').toLowerCase())
                        },

                        // Every word typed must appear somewhere — so 'iphone 128'
                        // finds 'Apple iPhone 15 128GB' — case-insensitive.
                        get matches() {
                            let words = this.q.toLowerCase().split(/\s+/).filter(Boolean)
                            if (! words.length) return []
                            return this.products.filter(p => words.every(w => p.haystack.includes(w)))
                        },

                        // Same URL QrCodeService::productLinkUrl() builds: /r/{code}?to={slug}
                        link(p) {
                            return this.linkBase + '?to=' + encodeURIComponent(p.slug)
                        },

                        price(p) {
                            return '₦' + Math.round(p.price).toLocaleString('en-NG')
                        },

                        async copy(p) {
                            let link = this.link(p)
                            this.manual = null
                            if (await this.copyText(link)) {
                                this.copiedId = p.id
                                clearTimeout(this.copiedTimer)
                                this.copiedTimer = setTimeout(() => this.copiedId = null, 1800)
                            } else {
                                this.manual = { id: p.id, link }
                            }
                        },

                        // The QR is drawn (and cached) on the server, and only for
                        // the product asked for — never for every result.
                        async openQr(p) {
                            this.qrLoadingId = p.id
                            this.qrFailedId = null
                            try {
                                let result = await this.$wire.productQr(p.id)
                                if (result) this.qr = { product: p, ...result }
                                else this.qrFailedId = p.id
                            } catch (e) {
                                this.qrFailedId = p.id
                            }
                            this.qrLoadingId = null
                        },

                        // On a phone, lift the field to just under the sticky strips
                        // once the keyboard has opened, so results aren't under it.
                        lift() {
                            if (! window.matchMedia('(pointer: coarse)').matches) return
                            setTimeout(() => {
                                let y = this.$refs.search.getBoundingClientRect().top + window.scrollY - this.stickyTop
                                window.scrollTo({ top: Math.max(y, 0), behavior: 'smooth' })
                            }, 300)
                        },
                    }"
                    x-on:focus-product-search.window="setTimeout(() => $refs.search.focus({ preventScroll: true }), 50)"
                    class="bg-white dark:bg-[#162016] border border-brand-border dark:border-[#2a3a2a] rounded-2xl p-5 md:p-6"
                >
                    <h2 class="font-montserrat font-bold text-[15px] text-brand-dark dark:text-[#e8f5e9] mb-1">
                        Product Links
                    </h2>
                    <p class="text-[13px] text-brand-muted mb-4">
                        Find a product and copy its link — it lands right on that product page and still carries your code.
                    </p>

                    <template x-if="products.length">
                        <div>
                            <input x-ref="search" x-model="q" x-on:focus="lift()"
                                type="search" inputmode="search" enterkeyhint="search"
                                autocomplete="off" autocorrect="off" autocapitalize="off" spellcheck="false"
                                :placeholder="'Search ' + products.length + ' products…'"
                                aria-label="Search products"
                                class="w-full h-12 px-4 bg-brand-bg dark:bg-[#0d1a0d] border border-[#d0d9d2] dark:border-[#2a3a2a] rounded-xl text-[15px] text-[#111] dark:text-[#e8f5e9] focus:outline-none focus:border-brand transition-colors">

                            <p class="text-[12px] text-brand-muted mt-2" x-show="q.trim() && ! matches.length">
                                Nothing matches “<span x-text="q.trim()"></span>”. Try fewer words, or the name as it shows on the store.
                            </p>
                            <p class="text-[12px] text-brand-muted mt-2" x-show="matches.length > limit">
                                Showing the first <span x-text="limit"></span> of <span x-text="matches.length"></span> — keep typing to narrow it down.
                            </p>

                            <ul x-show="matches.length" class="mt-3 border border-brand-border dark:border-[#2a3a2a] rounded-xl divide-y divide-brand-border dark:divide-[#2a3a2a] overflow-hidden">
                                <template x-for="p in matches.slice(0, limit)" :key="p.id">
                                    <li class="px-3 py-3 sm:flex sm:flex-wrap sm:items-center sm:gap-3">
                                        <div class="flex items-center gap-3 flex-1 min-w-0">
                                            <div class="w-12 h-12 flex-shrink-0 rounded-lg bg-brand-bg dark:bg-[#0d1a0d] overflow-hidden flex items-center justify-center">
                                                <img x-show="p.thumb" :src="p.thumb || null" alt="" loading="lazy" x-on:error="p.thumb = ''" class="w-full h-full object-cover">
                                                <svg x-show="! p.thumb" class="w-5 h-5 fill-none text-brand-muted" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24" aria-hidden="true">
                                                    <rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/><polyline points="21 15 16 10 5 21"/>
                                                </svg>
                                            </div>
                                            <div class="min-w-0">
                                                <div class="text-[14px] font-semibold leading-snug text-[#111] dark:text-[#e8f5e9] line-clamp-2" x-text="p.displayName"></div>
                                                <div class="font-montserrat font-bold text-[13px] text-brand mt-0.5" x-text="price(p)"></div>
                                            </div>
                                        </div>

                                        <div class="flex gap-2 mt-3 sm:mt-0 sm:flex-shrink-0">
                                            <button type="button" x-on:click="copy(p)"
                                                :class="copiedId === p.id ? 'bg-[#055002]' : 'bg-brand hover:bg-[#055002]'"
                                                class="flex-1 sm:flex-none sm:min-w-[110px] h-11 px-4 text-white font-montserrat font-bold text-[13px] rounded-xl transition-colors">
                                                <span x-text="copiedId === p.id ? 'Copied!' : 'Copy link'">Copy link</span>
                                            </button>
                                            <button type="button" x-on:click="openQr(p)" :disabled="qrLoadingId === p.id"
                                                :aria-label="'QR code for ' + p.displayName"
                                                class="w-11 h-11 flex-shrink-0 border border-brand text-brand rounded-xl flex items-center justify-center disabled:opacity-50 transition-colors hover:bg-brand-bg dark:hover:bg-[#1a2a1a]">
                                                <svg x-show="qrLoadingId !== p.id" class="w-5 h-5 fill-none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true">
                                                    <rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/><path d="M14 14h3v3h-3zM20 14v.01M14 20h.01M17 17h4v4"/>
                                                </svg>
                                                <svg x-show="qrLoadingId === p.id" class="w-5 h-5 animate-spin fill-none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true">
                                                    <path d="M21 12a9 9 0 1 1-6.22-8.56"/>
                                                </svg>
                                            </button>
                                        </div>

                                        {{-- Neither clipboard route worked: hand them the link to copy themselves --}}
                                        <div x-show="manual && manual.id === p.id" class="w-full mt-2 sm:basis-full">
                                            <p class="text-[12px] text-brand-muted mb-1">Couldn't copy automatically — press and hold the link to copy it.</p>
                                            <input readonly :value="manual && manual.id === p.id ? manual.link : ''" x-on:focus="$el.select()"
                                                class="w-full h-11 px-3.5 bg-brand-bg dark:bg-[#0d1a0d] border border-[#d0d9d2] dark:border-[#2a3a2a] rounded-xl text-[13px] text-[#111] dark:text-[#e8f5e9] focus:outline-none">
                                        </div>
                                        <p x-show="qrFailedId === p.id" class="w-full mt-2 sm:basis-full text-[12px] text-red-600">
                                            Couldn't load the QR code — check your connection and try again.
                                        </p>
                                    </li>
                                </template>
                            </ul>
                        </div>
                    </template>

                    <template x-if="! products.length">
                        <div class="rounded-xl border border-brand-border dark:border-[#2a3a2a] px-4 py-6 text-center text-[13px] text-brand-muted">
                            No products are live on the store right now — your main referral link below still works.
                        </div>
                    </template>

                    {{-- One product's QR, on demand --}}
                    <div x-show="qr" x-cloak x-transition.opacity
                        x-on:click.self="qr = null" x-on:keydown.escape.window="qr = null"
                        role="dialog" aria-modal="true" aria-label="Product QR code"
                        class="fixed inset-0 z-[300] bg-black/40 flex items-end md:items-center justify-center md:p-6">
                        <div class="w-full md:max-w-[400px] max-h-[90vh] overflow-y-auto bg-white dark:bg-[#162016] rounded-t-2xl md:rounded-2xl p-5">
                            <div class="flex items-start justify-between gap-3 mb-4">
                                <h3 class="font-montserrat font-bold text-[15px] text-brand-dark dark:text-[#e8f5e9]" x-text="qr?.product.displayName"></h3>
                                <button type="button" x-on:click="qr = null" aria-label="Close"
                                    class="w-11 h-11 -mt-2 -mr-2 flex-shrink-0 flex items-center justify-center rounded-xl text-brand-muted hover:text-brand">
                                    <svg class="w-5 h-5 fill-none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true">
                                        <line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/>
                                    </svg>
                                </button>
                            </div>

                            <div class="bg-white p-3 rounded-xl border border-brand-border dark:border-[#2a3a2a] w-fit max-w-full mx-auto [&>svg]:max-w-full [&>svg]:h-auto" x-html="qr?.svg"></div>

                            <div class="mt-4 flex flex-col gap-2">
                                <input readonly :value="qr ? qr.link : ''" x-on:focus="$el.select()" aria-label="Product link"
                                    class="w-full h-11 px-3.5 bg-brand-bg dark:bg-[#0d1a0d] border border-[#d0d9d2] dark:border-[#2a3a2a] rounded-xl text-[13px] text-[#111] dark:text-[#e8f5e9] focus:outline-none">
                                <button type="button" x-on:click="copy(qr.product)"
                                    class="h-11 px-4 bg-brand hover:bg-[#055002] text-white font-montserrat font-bold text-[13px] rounded-xl transition-colors">
                                    <span x-text="copiedId === qr?.product.id ? 'Copied!' : 'Copy link'">Copy link</span>
                                </button>
                                <a :href="qr ? 'data:image/svg+xml;charset=utf-8,' + encodeURIComponent(qr.svg) : '#'" download="gadgetplug-product-qr.svg"
                                    class="h-11 px-4 border border-brand text-brand font-montserrat font-bold text-[13px] rounded-xl transition-colors flex items-center justify-center">
                                    Download QR (SVG)
                                </a>
                            </div>
                        </div>
                    </div>
                </div>

                {{-- Referral link + QR --}}
                <div class="bg-white dark:bg-[#162016] border border-brand-border dark:border-[#2a3a2a] rounded-2xl p-5 md:p-6">
                    <h2 class="font-montserrat font-bold text-[15px] text-brand-dark dark:text-[#e8f5e9] mb-1">
                        Your Referral Link
                    </h2>
                    <p class="text-[13px] text-brand-muted mb-4">
                        Share this link or QR code — anyone who buys through it is tracked as yours.
                    </p>

                    <div class="flex flex-col md:flex-row gap-5">
                        <div class="flex-shrink-0 bg-white p-3 rounded-xl border border-brand-border dark:border-[#2a3a2a] w-fit max-w-full mx-auto md:mx-0 [&>svg]:w-44 [&>svg]:h-auto md:[&>svg]:w-auto [&>svg]:max-w-full">
                            {!! $this->referralQrSvg !!}
                        </div>

                        <div class="flex-1 min-w-0 flex flex-col justify-center gap-3">
                            <div x-data="{ copied: false }" class="flex items-center gap-2">
                                <input x-ref="referralLink" readonly value="{{ $this->referralLink }}" id="referral-link-input" aria-label="Your referral link"
                                    x-on:focus="$el.select()"
                                    class="flex-1 min-w-0 h-11 px-3.5 bg-brand-bg dark:bg-[#0d1a0d] border border-[#d0d9d2] dark:border-[#2a3a2a] rounded-xl text-[13px] text-[#111] dark:text-[#e8f5e9] focus:outline-none">
                                <button type="button"
                                    x-on:click="copyText($refs.referralLink.value).then(ok => { if (ok) { copied = true; setTimeout(() => copied = false, 1800) } else { $refs.referralLink.focus() } })"
                                    class="h-11 px-4 bg-brand hover:bg-[#055002] text-white font-montserrat font-bold text-[13px] rounded-xl transition-colors flex-shrink-0">
                                    <span x-text="copied ? 'Copied!' : 'Copy'">Copy</span>
                                </button>
                            </div>

                            <a href="data:image/svg+xml;base64,{{ base64_encode($this->referralQrSvg) }}" download="gadgetplug-referral-qr.svg"
                                class="h-11 px-4 border border-brand text-brand font-montserrat font-bold text-[13px] rounded-xl transition-colors flex items-center justify-center gap-2 w-fit">
                                Download QR (SVG)
                            </a>
                        </div>
                    </div>
                </div>

                {{-- Marketing material --}}
                @if ($this->materials->count())
                <div class="bg-white dark:bg-[#162016] border border-brand-border dark:border-[#2a3a2a] rounded-2xl p-5 md:p-6">
                    <h2 class="font-montserrat font-bold text-[15px] text-brand-dark dark:text-[#e8f5e9] mb-1">Material to Share</h2>
                    <p class="text-[12px] text-brand-muted mb-4">Download the image and post it with the caption below — the caption carries your code, which is what makes the share count.</p>

                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        @foreach ($this->materials as $item)
                            <div class="border border-brand-border dark:border-[#2a3a2a] rounded-xl overflow-hidden">
                                @if ($item['thumb_url'])
                                    <img src="{{ $item['thumb_url'] }}" alt="{{ $item['material']->name }}" class="w-full h-40 object-contain bg-brand-bg dark:bg-[#0d1a0d]">
                                @endif
                                <div class="p-3">
                                    <div class="font-montserrat font-bold text-[13px] text-brand-dark dark:text-[#e8f5e9]">{{ $item['material']->name }}</div>
                                    @if ($item['material']->description)
                                        <p class="text-[11px] text-brand-muted mt-0.5">{{ $item['material']->description }}</p>
                                    @endif
                                    <div class="mt-2 bg-brand-bg dark:bg-[#0d1a0d] rounded-lg px-3 py-2 text-[11px] text-brand-dark dark:text-[#e8f5e9] break-words">
                                        {{ $item['caption'] }}
                                    </div>
                                    @if ($item['image_url'])
                                        <a href="{{ $item['image_url'] }}" download
                                           class="inline-flex items-center min-h-[44px] mt-1 text-[13px] font-bold text-brand">Download image ↓</a>
                                    @endif
                                </div>
                            </div>
                        @endforeach
                    </div>
                </div>
                @endif

            </section>

            {{-- ═══ Earn ═══ --}}
            <section id="tab-earn" role="tabpanel" x-show="tab === 'earn'" x-cloak class="space-y-5">

                @if (session('taskSubmitted'))
                <div class="bg-[#e8f5e9] dark:bg-[#1a2a1a] border border-[#c0e8c0] dark:border-[#2a3a2a] text-brand rounded-xl px-4 py-3 text-[12px] font-semibold">
                    {{ session('taskSubmitted') }}
                </div>
                @endif

                {{-- Daily social share --}}
                @if ($this->shareTask)
                <div id="todays-share" class="bg-white dark:bg-[#162016] border border-brand-border dark:border-[#2a3a2a] rounded-2xl p-5 md:p-6">
                    <h2 class="font-montserrat font-bold text-[15px] text-brand-dark dark:text-[#e8f5e9] mb-1">{{ $this->shareTask->name }}</h2>
                    <p class="text-[12px] text-brand-muted mb-4">
                        {{ $this->shareTask->description ?: 'Post today\'s material with your code visible, then send the screenshot.' }}
                        <span class="block mt-1">Window: <strong>{{ $this->shareWindowLabel }}</strong></span>
                    </p>

                    @if (session('shareSubmitted'))
                    <div class="bg-[#e8f5e9] dark:bg-[#1a2a1a] border border-[#c0e8c0] dark:border-[#2a3a2a] text-brand rounded-xl px-4 py-3 text-[12px] font-semibold mb-4">
                        {{ session('shareSubmitted') }}
                    </div>
                    @endif

                    {{-- The ladder, shown up front so the reward is never a surprise --}}
                    <div class="flex flex-wrap gap-2 mb-4">
                        @foreach ($this->reachBands as $band)
                            <span class="inline-flex items-center px-2.5 py-1 rounded-full text-[11px] font-semibold bg-brand-bg dark:bg-[#0d1a0d] text-brand-dark dark:text-[#e8f5e9]">
                                {{ $band->name }} · {{ $band->points }} pts
                            </span>
                        @endforeach
                    </div>

                    @if ($this->todaysShare)
                        <div class="rounded-xl border border-brand-border dark:border-[#2a3a2a] px-4 py-3 text-[12px]">
                            @if ($this->todaysShare->status === 'submitted')
                                <span class="font-semibold text-[#b45309]">Awaiting review</span> — you've sent today's share.
                            @elseif ($this->todaysShare->status === 'approved')
                                <span class="font-semibold text-brand">Approved</span> — {{ $this->todaysShare->points_awarded }} points credited
                                @if ($this->todaysShare->streak_bonus_points)
                                    (plus a {{ $this->todaysShare->streak_bonus_points }}-point streak bonus)
                                @endif.
                            @else
                                <span class="font-semibold text-red-600">Rejected</span> — {{ $this->todaysShare->rejected_reason }}
                            @endif
                        </div>
                    @elseif (! $this->shareWindowOpen)
                        <div class="rounded-xl border border-brand-border dark:border-[#2a3a2a] px-4 py-3 text-[12px] text-brand-muted">
                            The window is closed right now. Come back between {{ $this->shareWindowLabel }}.
                        </div>
                    @else
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                            <div>
                                <label class="block text-[11px] text-brand-muted mb-1">Views / reach on your post</label>
                                <input type="number" min="0" wire:model="shareReach"
                                       class="w-full border border-brand-border dark:border-[#2a3a2a] dark:bg-[#0d1a0d] dark:text-[#e8f5e9] rounded-xl px-3 h-11 text-[13px]"
                                       placeholder="e.g. 640">
                                @error('shareReach') <p class="text-[11px] text-red-600 mt-1">{{ $message }}</p> @enderror
                            </div>

                            <div>
                                <label class="block text-[11px] text-brand-muted mb-1">Screenshot (must show your code)</label>
                                <input type="file" wire:model="shareProof" accept="image/*"
                                       class="w-full text-[12px] text-brand-muted">
                                @error('shareProof') <p class="text-[11px] text-red-600 mt-1">{{ $message }}</p> @enderror
                            </div>

                            <div class="md:col-span-2">
                                <textarea wire:model="shareNotes" rows="2" placeholder="Anything the reviewer should know (optional)"
                                          class="w-full border border-brand-border dark:border-[#2a3a2a] dark:bg-[#0d1a0d] dark:text-[#e8f5e9] rounded-xl px-3 py-2 text-[13px]"></textarea>
                            </div>

                            <div class="md:col-span-2">
                                <button wire:click="submitShare" wire:loading.attr="disabled"
                                        class="h-11 bg-brand text-white rounded-xl px-5 text-[13px] font-bold disabled:opacity-50">
                                    Submit today's share
                                </button>
                            </div>
                        </div>
                    @endif
                </div>
                @endif

                {{-- Plug Points --}}
                <div class="bg-white dark:bg-[#162016] border border-brand-border dark:border-[#2a3a2a] rounded-2xl p-5 md:p-6">
                    <div class="flex items-start justify-between gap-3 mb-4">
                        <div>
                            <h2 class="font-montserrat font-bold text-[15px] text-brand-dark dark:text-[#e8f5e9]">Plug Points</h2>
                            <p class="text-[11px] text-brand-muted mt-0.5">Earned from tasks and daily shares. Points aren't cash until you convert them.</p>
                        </div>
                        @if ($this->currentStreak > 0)
                            <span class="shrink-0 inline-flex items-center gap-1 px-2.5 py-1 rounded-full text-[12px] font-bold bg-[#fff4e5] dark:bg-[#2a2416] text-[#b45309]">
                                🔥 {{ $this->currentStreak }}-day streak
                            </span>
                        @endif
                    </div>

                    @if (session('pointsConverted'))
                    <div class="bg-[#e8f5e9] dark:bg-[#1a2a1a] border border-[#c0e8c0] dark:border-[#2a3a2a] text-brand rounded-xl px-4 py-3 text-[12px] font-semibold mb-4">
                        {{ session('pointsConverted') }}
                    </div>
                    @endif

                    <div class="grid grid-cols-1 md:grid-cols-2 gap-5">
                        <div>
                            <div class="text-[11px] text-brand-muted mb-1">Points balance</div>
                            <div class="font-montserrat font-black text-[28px] text-brand">{{ number_format($this->pointsBalance) }}</div>
                            <p class="text-[11px] text-brand-muted mt-1">
                                Worth ₦{{ number_format($this->pointsBalance * (float) \App\Models\AffiliateSetting::current()->naira_per_point, 2) }} at today's rate.
                            </p>
                        </div>

                        <div>
                            <label class="block text-[11px] text-brand-muted mb-1">Convert to cash (min {{ number_format($this->minConversion) }} points)</label>
                            <div class="flex gap-2">
                                <input type="number" min="1" wire:model.live="pointsToConvert"
                                       class="w-full border border-brand-border dark:border-[#2a3a2a] dark:bg-[#0d1a0d] dark:text-[#e8f5e9] rounded-xl px-3 h-11 text-[13px]"
                                       placeholder="Points">
                                <button wire:click="convertPoints" wire:loading.attr="disabled"
                                        class="shrink-0 h-11 bg-brand text-white rounded-xl px-4 text-[13px] font-bold disabled:opacity-50">
                                    Convert
                                </button>
                            </div>
                            @if ((int) $this->pointsToConvert > 0)
                                <p class="text-[11px] text-brand-muted mt-1">You'd get ₦{{ number_format($this->conversionQuote, 2) }}.</p>
                            @endif
                            @error('pointsToConvert')
                                <p class="text-[11px] text-red-600 mt-1">{{ $message }}</p>
                            @enderror
                        </div>
                    </div>

                    @if ($this->pointsHistory && $this->pointsHistory->count())
                    <div class="mt-5 border-t border-brand-border dark:border-[#2a3a2a] pt-4 space-y-2">
                        @foreach ($this->pointsHistory as $row)
                            <div class="flex items-center justify-between gap-3 text-[12px]">
                                <span class="text-brand-muted truncate">{{ $row->description }}</span>
                                <span class="shrink-0 font-bold {{ $row->points >= 0 ? 'text-brand' : 'text-red-600' }}">
                                    {{ $row->points >= 0 ? '+' : '' }}{{ number_format($row->points) }}
                                </span>
                            </div>
                        @endforeach
                        <div class="pt-2">{{ $this->pointsHistory->links() }}</div>
                    </div>
                    @endif
                </div>

                {{-- Tasks --}}
                <div id="earn-tasks" class="bg-white dark:bg-[#162016] border border-brand-border dark:border-[#2a3a2a] rounded-2xl overflow-hidden">
                    <div class="px-5 md:px-6 py-4 border-b border-brand-border dark:border-[#2a3a2a]">
                        <h2 class="font-montserrat font-bold text-[15px] text-brand-dark dark:text-[#e8f5e9]">Tasks</h2>
                    </div>

                    @if ($this->availableTasks->isEmpty())
                        <div class="px-6 py-10 text-center text-[12px] text-brand-muted">No tasks right now — new ones show up here when they open.</div>
                    @else
                        <div class="divide-y divide-brand-border dark:divide-[#2a3a2a]">
                            @foreach ($this->availableTasks as $task)
                            <div class="px-5 md:px-6 py-4">
                                <div class="flex items-start justify-between gap-3 mb-1">
                                    <div>
                                        <div class="text-[13px] font-semibold text-brand-dark dark:text-[#e8f5e9]">{{ $task->name }}</div>
                                        @if ($task->description)
                                        <div class="text-[11px] text-brand-muted mt-0.5">{{ $task->description }}</div>
                                        @endif
                                    </div>
                                    <span class="font-montserrat font-bold text-[12px] text-brand flex-shrink-0">₦{{ number_format((float) $task->reward_amount, 2) }}</span>
                                </div>

                                @if ($task->verification_type === 'auto')
                                    <p class="text-[11px] text-brand-muted mt-2">Completes automatically — no action needed.</p>
                                @elseif ($submittingTaskId === $task->id)
                                    <form wire:submit="submitTask" class="mt-3 space-y-2.5">
                                        <textarea wire:model="taskNotes" rows="2" placeholder="Optional note (e.g. a link to your post)"
                                            class="w-full px-3 py-2 bg-brand-bg dark:bg-[#0d1a0d] border border-[#d0d9d2] dark:border-[#2a3a2a] rounded-xl text-[12px] text-[#111] dark:text-[#e8f5e9] focus:outline-none focus:border-brand resize-none"></textarea>
                                        <input type="file" wire:model="proofFile" accept="image/*"
                                            class="w-full text-[11px] text-brand-muted file:mr-3 file:py-1.5 file:px-3 file:rounded-lg file:border-0 file:bg-brand file:text-white file:text-[11px] file:font-semibold">
                                        @error('proofFile') <p class="text-red-500 text-[11px]">{{ $message }}</p> @enderror
                                        @error('submission') <p class="text-red-500 text-[11px]">{{ $message }}</p> @enderror
                                        <div class="flex gap-2">
                                            <button type="submit"
                                                class="h-11 px-5 bg-brand hover:bg-[#055002] text-white font-montserrat font-bold text-[13px] rounded-xl transition-colors">
                                                <span wire:loading.remove wire:target="submitTask">Submit</span>
                                                <span wire:loading wire:target="submitTask">Submitting…</span>
                                            </button>
                                            <button type="button" wire:click="cancelTaskSubmission"
                                                class="h-11 px-5 border border-brand-border dark:border-[#2a3a2a] text-brand-muted font-montserrat font-bold text-[13px] rounded-xl transition-colors">
                                                Cancel
                                            </button>
                                        </div>
                                    </form>
                                @elseif ($this->taskEligibility($task))
                                    <button wire:click="openTaskSubmission({{ $task->id }})"
                                        class="mt-2 h-11 px-5 border border-brand text-brand hover:bg-brand hover:text-white font-montserrat font-bold text-[13px] rounded-xl transition-colors">
                                        Submit
                                    </button>
                                @else
                                    <p class="text-[11px] text-brand-muted mt-2">Not eligible right now — already submitted, limit reached, or still in cooldown.</p>
                                @endif
                            </div>
                            @endforeach
                        </div>
                    @endif

                    {{-- My submission history --}}
                    @if (! $this->mySubmissions->isEmpty())
                    <div class="px-5 md:px-6 py-4 border-t border-brand-border dark:border-[#2a3a2a]">
                        <h3 class="text-[12px] font-bold text-brand-dark dark:text-[#e8f5e9] mb-3">Your Submissions</h3>
                        <div class="space-y-2.5">
                            @foreach ($this->mySubmissions as $submission)
                            @php
                            $subStatusLabel = match($submission->status) {
                                'submitted' => 'Pending Review',
                                'approved'  => 'Approved',
                                'rejected'  => 'Rejected',
                                default     => ucfirst($submission->status),
                            };
                            $subStatusClass = match($submission->status) {
                                'approved'  => 'bg-[#e8f5e9] text-brand dark:bg-[#1a2a1a] dark:text-brand-lime',
                                'rejected'  => 'bg-red-50 text-red-600 dark:bg-red-900/20 dark:text-red-400',
                                default     => 'bg-amber-50 text-amber-700 dark:bg-amber-900/20 dark:text-amber-400',
                            };
                            @endphp
                            <div class="flex items-center justify-between gap-3">
                                <div>
                                    <span class="text-[12px] text-[#111] dark:text-[#e8f5e9]">{{ $submission->task->name ?? 'Task' }}</span>
                                    @if ($submission->status === 'rejected' && $submission->rejected_reason)
                                    <div class="text-[10px] text-red-500 mt-0.5">{{ $submission->rejected_reason }}</div>
                                    @endif
                                </div>
                                <span class="inline-flex items-center px-2 py-0.5 rounded-full text-[10px] font-bold uppercase tracking-[0.4px] flex-shrink-0 {{ $subStatusClass }}">{{ $subStatusLabel }}</span>
                            </div>
                            @endforeach
                        </div>
                        @if ($this->mySubmissions->hasPages())
                        <div class="mt-3">{{ $this->mySubmissions->links() }}</div>
                        @endif
                    </div>
                    @endif
                </div>

            </section>

            {{-- ═══ Wallet ═══ --}}
            <section id="tab-wallet" role="tabpanel" x-show="tab === 'wallet'" x-cloak class="space-y-5">

                {{-- Commission history --}}
                <div class="bg-white dark:bg-[#162016] border border-brand-border dark:border-[#2a3a2a] rounded-2xl overflow-hidden">
                    <div class="px-5 md:px-6 py-4 border-b border-brand-border dark:border-[#2a3a2a]">
                        <h2 class="font-montserrat font-bold text-[15px] text-brand-dark dark:text-[#e8f5e9]">Commissions</h2>
                    </div>
                    @if ($this->commissions->isEmpty())
                        <div class="px-6 py-10 text-center text-[12px] text-brand-muted">No referred orders yet — every order placed through your link shows up here.</div>
                    @else
                        <div class="divide-y divide-brand-border dark:divide-[#2a3a2a]">
                            @foreach ($this->commissions as $commission)
                            @php
                            $statusLabel = match($commission->status) {
                                'pending'       => 'Pending',
                                'return_window' => 'In Return Window',
                                'available'     => 'Available',
                                'rejected'      => 'Rejected',
                                default         => ucfirst($commission->status),
                            };
                            $statusClass = match($commission->status) {
                                'available'     => 'bg-[#e8f5e9] text-brand dark:bg-[#1a2a1a] dark:text-brand-lime',
                                'return_window' => 'bg-amber-50 text-amber-700 dark:bg-amber-900/20 dark:text-amber-400',
                                'rejected'      => 'bg-red-50 text-red-600 dark:bg-red-900/20 dark:text-red-400',
                                default         => 'bg-gray-100 text-gray-600 dark:bg-[#1a2a1a] dark:text-[#b0c8b0]',
                            };
                            @endphp
                            <div class="px-5 md:px-6 py-3 flex items-center justify-between gap-3">
                                <div>
                                    <div class="text-[12px] font-medium text-[#111] dark:text-[#e8f5e9]">{{ $commission->order->reference ?? '—' }}</div>
                                    <span class="inline-flex items-center px-2 py-0.5 rounded-full text-[10px] font-bold uppercase tracking-[0.4px] {{ $statusClass }}">{{ $statusLabel }}</span>
                                </div>
                                <span class="font-montserrat font-bold text-[13px] text-brand">₦{{ number_format((float) $commission->amount, 2) }}</span>
                            </div>
                            @endforeach
                        </div>
                        @if ($this->commissions->hasPages())
                        <div class="px-5 md:px-6 py-4 border-t border-brand-border dark:border-[#2a3a2a]">
                            {{ $this->commissions->links() }}
                        </div>
                        @endif
                    @endif
                </div>

                {{-- Traffic --}}
                <div class="bg-white dark:bg-[#162016] border border-brand-border dark:border-[#2a3a2a] rounded-2xl p-5 md:p-6">
                    <h2 class="font-montserrat font-bold text-[15px] text-brand-dark dark:text-[#e8f5e9] mb-4">Your Traffic</h2>
                    @if ($this->trafficStats['clicks'] === 0)
                    <div class="rounded-xl border border-brand-border dark:border-[#2a3a2a] px-4 py-5 text-center">
                        <p class="text-[13px] text-brand-muted mb-3">No visits yet — share your link and every visit shows up here.</p>
                        <button type="button" x-on:click="go('promote')"
                            class="h-11 px-5 border border-brand text-brand hover:bg-brand-bg dark:hover:bg-[#1a2a1a] font-montserrat font-bold text-[13px] rounded-xl transition-colors">
                            Get your links
                        </button>
                    </div>
                    @else
                    <div class="grid grid-cols-3 gap-3">
                        <div>
                            <div class="text-[11px] text-brand-muted mb-1">Visits</div>
                            <div class="font-montserrat font-black text-[22px] text-brand-dark dark:text-[#e8f5e9]">{{ number_format($this->trafficStats['clicks']) }}</div>
                        </div>
                        <div>
                            <div class="text-[11px] text-brand-muted mb-1">Engaged</div>
                            <div class="font-montserrat font-black text-[22px] text-brand-dark dark:text-[#e8f5e9]">{{ number_format($this->trafficStats['engaged']) }}</div>
                        </div>
                        <div>
                            <div class="text-[11px] text-brand-muted mb-1">Earned from visits</div>
                            <div class="font-montserrat font-black text-[22px] text-brand">₦{{ number_format($this->trafficStats['earned'], 2) }}</div>
                        </div>
                    </div>
                    @endif
                    <p class="text-[11px] text-brand-muted mt-3">
                        A visit pays only once the person opens a second page — that's how we know they actually looked around instead of landing and leaving. When they buy, you earn the full commission on top.
                    </p>
                </div>

                {{-- Wallet history --}}
                <div class="bg-white dark:bg-[#162016] border border-brand-border dark:border-[#2a3a2a] rounded-2xl overflow-hidden">
                    <div class="px-5 md:px-6 py-4 border-b border-brand-border dark:border-[#2a3a2a]">
                        <h2 class="font-montserrat font-bold text-[15px] text-brand-dark dark:text-[#e8f5e9]">Wallet History</h2>
                    </div>
                    @if ($this->walletHistory->isEmpty())
                        <div class="px-6 py-10 text-center text-[12px] text-brand-muted">No money in or out yet. Commissions move here once a referred order clears its return window.</div>
                    @else
                        <div class="divide-y divide-brand-border dark:divide-[#2a3a2a]">
                            @foreach ($this->walletHistory as $tx)
                            <div class="px-5 md:px-6 py-3 flex items-center justify-between gap-3">
                                <div>
                                    <div class="text-[12px] font-medium text-[#111] dark:text-[#e8f5e9]">{{ $tx->description ?? ucfirst($tx->type) }}</div>
                                    <div class="text-[10px] text-brand-muted">{{ $tx->created_at->format('d M Y, g:ia') }}</div>
                                </div>
                                <span class="font-montserrat font-bold text-[13px] {{ (float) $tx->amount >= 0 ? 'text-brand' : 'text-red-500' }}">
                                    {{ (float) $tx->amount >= 0 ? '+' : '' }}₦{{ number_format((float) $tx->amount, 2) }}
                                </span>
                            </div>
                            @endforeach
                        </div>
                        @if ($this->walletHistory->hasPages())
                        <div class="px-5 md:px-6 py-4 border-t border-brand-border dark:border-[#2a3a2a]">
                            {{ $this->walletHistory->links() }}
                        </div>
                        @endif
                    @endif
                </div>

                {{-- Bank details --}}
                <div id="bank-details" class="bg-white dark:bg-[#162016] border border-brand-border dark:border-[#2a3a2a] rounded-2xl p-5 md:p-6">
                    <h2 class="font-montserrat font-bold text-[15px] text-brand-dark dark:text-[#e8f5e9] mb-1">Payout Bank Details</h2>
                    <p class="text-[12px] text-brand-muted mb-4">
                        Payouts run weekly, straight to this account. Keep it up to date.
                    </p>

                    @if (session('bankDetailsSaved'))
                    <div class="bg-[#e8f5e9] dark:bg-[#1a2a1a] border border-[#c0e8c0] dark:border-[#2a3a2a] text-brand rounded-xl px-4 py-3 text-[12px] font-semibold mb-4">
                        {{ session('bankDetailsSaved') }}
                    </div>
                    @endif

                    @if (! $affiliate->hasBankDetails())
                    <div class="bg-amber-50 dark:bg-amber-900/20 border border-amber-200 dark:border-amber-800 text-amber-700 dark:text-amber-400 rounded-xl px-4 py-3 text-[12px] font-semibold mb-4">
                        Add your bank details below so we can pay you.
                    </div>
                    @endif

                    <form wire:submit="saveBankDetails" class="grid grid-cols-1 md:grid-cols-3 gap-3">
                        <div>
                            <label class="block text-[11px] text-brand-muted mb-1">Bank Name</label>
                            <input wire:model="bankName" type="text" placeholder="e.g. GTBank"
                                class="w-full h-11 px-3.5 bg-brand-bg dark:bg-[#0d1a0d] border border-[#d0d9d2] dark:border-[#2a3a2a] rounded-xl text-[12px] text-[#111] dark:text-[#e8f5e9] focus:outline-none focus:border-brand transition-colors">
                            @error('bankName') <p class="text-red-500 text-[11px] mt-1">{{ $message }}</p> @enderror
                        </div>
                        <div>
                            <label class="block text-[11px] text-brand-muted mb-1">Account Number</label>
                            <input wire:model="accountNumber" type="text" inputmode="numeric" maxlength="10" placeholder="0123456789"
                                class="w-full h-11 px-3.5 bg-brand-bg dark:bg-[#0d1a0d] border border-[#d0d9d2] dark:border-[#2a3a2a] rounded-xl text-[12px] text-[#111] dark:text-[#e8f5e9] focus:outline-none focus:border-brand transition-colors">
                            @error('accountNumber') <p class="text-red-500 text-[11px] mt-1">{{ $message }}</p> @enderror
                        </div>
                        <div>
                            <label class="block text-[11px] text-brand-muted mb-1">Account Name</label>
                            <input wire:model="accountName" type="text" placeholder="As it appears on the account"
                                class="w-full h-11 px-3.5 bg-brand-bg dark:bg-[#0d1a0d] border border-[#d0d9d2] dark:border-[#2a3a2a] rounded-xl text-[12px] text-[#111] dark:text-[#e8f5e9] focus:outline-none focus:border-brand transition-colors">
                            @error('accountName') <p class="text-red-500 text-[11px] mt-1">{{ $message }}</p> @enderror
                        </div>
                        <div class="md:col-span-3">
                            <button type="submit"
                                class="h-11 px-5 bg-brand hover:bg-[#055002] text-white font-montserrat font-bold text-[13px] rounded-xl transition-colors">
                                <span wire:loading.remove wire:target="saveBankDetails">Save Bank Details</span>
                                <span wire:loading wire:target="saveBankDetails">Saving…</span>
                            </button>
                        </div>
                    </form>
                </div>

            </section>

        </div>
    @endif

</x-layouts.account>
</div>

<?php

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
use App\Services\Affiliate\QrCodeService;
use App\Services\Affiliate\WalletService;

new class extends Component {
    use WithFileUploads, WithPagination;

    public ?Affiliate $affiliate = null;
    public string $productSearch = '';
    public ?int $selectedProductId = null;

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

    public function getProductResultsProperty()
    {
        if (blank($this->productSearch) || ! $this->affiliate) {
            return collect();
        }

        // Must actually be reachable on the storefront (visibleOnline checks
        // status + show_online + the publish/unpublish date window) — a
        // product that matched the old plain status='published' check but
        // failed any of those would generate a link/QR that silently falls
        // back to the homepage instead of landing on that product.
        return Product::visibleOnline()
            ->where('name', 'like', '%' . $this->productSearch . '%')
            ->limit(8)
            ->get();
    }

    public function selectProduct(int $productId): void
    {
        $this->selectedProductId = $productId;
        $this->productSearch = '';
    }

    public function clearSelectedProduct(): void
    {
        $this->selectedProductId = null;
    }

    public function getSelectedProductProperty(): ?Product
    {
        return $this->selectedProductId ? Product::find($this->selectedProductId) : null;
    }

    public function getReferralLinkProperty(): ?string
    {
        return $this->affiliate ? app(QrCodeService::class)->referralLinkUrl($this->affiliate) : null;
    }

    public function getReferralQrSvgProperty(): ?string
    {
        return $this->affiliate ? app(QrCodeService::class)->referralQrSvg($this->affiliate) : null;
    }

    public function getProductLinkProperty(): ?string
    {
        return ($this->affiliate && $this->selectedProduct)
            ? app(QrCodeService::class)->productLinkUrl($this->affiliate, $this->selectedProduct)
            : null;
    }

    public function getProductQrSvgProperty(): ?string
    {
        return ($this->affiliate && $this->selectedProduct)
            ? app(QrCodeService::class)->productQrSvg($this->affiliate, $this->selectedProduct)
            : null;
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
        <div class="space-y-5">

            @if (session('taskSubmitted'))
            <div class="bg-[#e8f5e9] dark:bg-[#1a2a1a] border border-[#c0e8c0] dark:border-[#2a3a2a] text-brand rounded-xl px-4 py-3 text-[12px] font-semibold">
                {{ session('taskSubmitted') }}
            </div>
            @endif

            {{-- Wallet + Level summary --}}
            <div class="grid grid-cols-1 md:grid-cols-2 gap-5">

                {{-- Wallet --}}
                <div class="bg-white dark:bg-[#162016] border border-brand-border dark:border-[#2a3a2a] rounded-2xl p-5 md:p-6">
                    <h2 class="font-montserrat font-bold text-[15px] text-brand-dark dark:text-[#e8f5e9] mb-4">Wallet</h2>
                    <div class="grid grid-cols-2 gap-3">
                        <div>
                            <div class="text-[11px] text-brand-muted mb-1">Available</div>
                            <div class="font-montserrat font-black text-[22px] text-brand">₦{{ number_format($this->availableBalance, 2) }}</div>
                        </div>
                        <div>
                            <div class="text-[11px] text-brand-muted mb-1">Pending</div>
                            <div class="font-montserrat font-black text-[22px] text-brand-dark dark:text-[#e8f5e9]">₦{{ number_format($this->pendingBalance, 2) }}</div>
                        </div>
                    </div>
                    <p class="text-[11px] text-brand-muted mt-3">
                        Available is what's actually payable — pending is still in its return-window hold.
                    </p>
                </div>

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
            </div>

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
                                   class="w-full border border-brand-border dark:border-[#2a3a2a] dark:bg-[#0d1a0d] dark:text-[#e8f5e9] rounded-lg px-3 py-2 text-[13px]"
                                   placeholder="Points">
                            <button wire:click="convertPoints" wire:loading.attr="disabled"
                                    class="shrink-0 bg-brand text-white rounded-lg px-4 py-2 text-[13px] font-bold disabled:opacity-50">
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

            {{-- Daily social share --}}
            @if ($this->shareTask)
            <div class="bg-white dark:bg-[#162016] border border-brand-border dark:border-[#2a3a2a] rounded-2xl p-5 md:p-6">
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
                                   class="w-full border border-brand-border dark:border-[#2a3a2a] dark:bg-[#0d1a0d] dark:text-[#e8f5e9] rounded-lg px-3 py-2 text-[13px]"
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
                                      class="w-full border border-brand-border dark:border-[#2a3a2a] dark:bg-[#0d1a0d] dark:text-[#e8f5e9] rounded-lg px-3 py-2 text-[13px]"></textarea>
                        </div>

                        <div class="md:col-span-2">
                            <button wire:click="submitShare" wire:loading.attr="disabled"
                                    class="bg-brand text-white rounded-lg px-5 py-2.5 text-[13px] font-bold disabled:opacity-50">
                                Submit today's share
                            </button>
                        </div>
                    </div>
                @endif
            </div>
            @endif

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
                                       class="inline-block mt-2 text-[12px] font-bold text-brand">Download image ↓</a>
                                @endif
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>
            @endif

            {{-- Traffic --}}
            <div class="bg-white dark:bg-[#162016] border border-brand-border dark:border-[#2a3a2a] rounded-2xl p-5 md:p-6">
                <h2 class="font-montserrat font-bold text-[15px] text-brand-dark dark:text-[#e8f5e9] mb-4">Your Traffic</h2>
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
                <p class="text-[11px] text-brand-muted mt-3">
                    A visit pays only once the person opens a second page — that's how we know they actually looked around instead of landing and leaving. When they buy, you earn the full commission on top.
                </p>
            </div>

            {{-- Bank details --}}
            <div class="bg-white dark:bg-[#162016] border border-brand-border dark:border-[#2a3a2a] rounded-2xl p-5 md:p-6">
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
                            class="w-full h-10 px-3.5 bg-brand-bg dark:bg-[#0d1a0d] border border-[#d0d9d2] dark:border-[#2a3a2a] rounded-xl text-[12px] text-[#111] dark:text-[#e8f5e9] focus:outline-none focus:border-brand transition-colors">
                        @error('bankName') <p class="text-red-500 text-[11px] mt-1">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="block text-[11px] text-brand-muted mb-1">Account Number</label>
                        <input wire:model="accountNumber" type="text" inputmode="numeric" maxlength="10" placeholder="0123456789"
                            class="w-full h-10 px-3.5 bg-brand-bg dark:bg-[#0d1a0d] border border-[#d0d9d2] dark:border-[#2a3a2a] rounded-xl text-[12px] text-[#111] dark:text-[#e8f5e9] focus:outline-none focus:border-brand transition-colors">
                        @error('accountNumber') <p class="text-red-500 text-[11px] mt-1">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="block text-[11px] text-brand-muted mb-1">Account Name</label>
                        <input wire:model="accountName" type="text" placeholder="As it appears on the account"
                            class="w-full h-10 px-3.5 bg-brand-bg dark:bg-[#0d1a0d] border border-[#d0d9d2] dark:border-[#2a3a2a] rounded-xl text-[12px] text-[#111] dark:text-[#e8f5e9] focus:outline-none focus:border-brand transition-colors">
                        @error('accountName') <p class="text-red-500 text-[11px] mt-1">{{ $message }}</p> @enderror
                    </div>
                    <div class="md:col-span-3">
                        <button type="submit"
                            class="h-10 px-5 bg-brand hover:bg-[#055002] text-white font-montserrat font-bold text-[12px] rounded-xl transition-colors">
                            <span wire:loading.remove wire:target="saveBankDetails">Save Bank Details</span>
                            <span wire:loading wire:target="saveBankDetails">Saving…</span>
                        </button>
                    </div>
                </form>
            </div>

            {{-- Wallet history --}}
            <div class="bg-white dark:bg-[#162016] border border-brand-border dark:border-[#2a3a2a] rounded-2xl overflow-hidden">
                <div class="px-5 md:px-6 py-4 border-b border-brand-border dark:border-[#2a3a2a]">
                    <h2 class="font-montserrat font-bold text-[15px] text-brand-dark dark:text-[#e8f5e9]">Wallet History</h2>
                </div>
                @if ($this->walletHistory->isEmpty())
                    <div class="px-6 py-10 text-center text-[12px] text-brand-muted">Nothing here yet.</div>
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

            {{-- Commission history --}}
            <div class="bg-white dark:bg-[#162016] border border-brand-border dark:border-[#2a3a2a] rounded-2xl overflow-hidden">
                <div class="px-5 md:px-6 py-4 border-b border-brand-border dark:border-[#2a3a2a]">
                    <h2 class="font-montserrat font-bold text-[15px] text-brand-dark dark:text-[#e8f5e9]">Commissions</h2>
                </div>
                @if ($this->commissions->isEmpty())
                    <div class="px-6 py-10 text-center text-[12px] text-brand-muted">No referred orders yet.</div>
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

            {{-- Tasks --}}
            <div class="bg-white dark:bg-[#162016] border border-brand-border dark:border-[#2a3a2a] rounded-2xl overflow-hidden">
                <div class="px-5 md:px-6 py-4 border-b border-brand-border dark:border-[#2a3a2a]">
                    <h2 class="font-montserrat font-bold text-[15px] text-brand-dark dark:text-[#e8f5e9]">Tasks</h2>
                </div>

                @if ($this->availableTasks->isEmpty())
                    <div class="px-6 py-10 text-center text-[12px] text-brand-muted">No tasks available right now.</div>
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
                                            class="h-9 px-4 bg-brand hover:bg-[#055002] text-white font-montserrat font-bold text-[11px] rounded-xl transition-colors">
                                            <span wire:loading.remove wire:target="submitTask">Submit</span>
                                            <span wire:loading wire:target="submitTask">Submitting…</span>
                                        </button>
                                        <button type="button" wire:click="cancelTaskSubmission"
                                            class="h-9 px-4 border border-brand-border dark:border-[#2a3a2a] text-brand-muted font-montserrat font-bold text-[11px] rounded-xl transition-colors">
                                            Cancel
                                        </button>
                                    </div>
                                </form>
                            @elseif ($this->taskEligibility($task))
                                <button wire:click="openTaskSubmission({{ $task->id }})"
                                    class="mt-2 h-8 px-3.5 border border-brand text-brand hover:bg-brand hover:text-white font-montserrat font-bold text-[11px] rounded-lg transition-colors">
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

            {{-- Referral link + QR --}}
            <div class="bg-white dark:bg-[#162016] border border-brand-border dark:border-[#2a3a2a] rounded-2xl p-5 md:p-6">
                <h2 class="font-montserrat font-bold text-[15px] text-brand-dark dark:text-[#e8f5e9] mb-1">
                    Your Referral Link
                </h2>
                <p class="text-[12px] text-brand-muted mb-4">
                    Share this link or QR code — anyone who buys through it is tracked as yours.
                </p>

                <div class="flex flex-col md:flex-row gap-5">
                    <div class="flex-shrink-0 bg-white p-3 rounded-xl border border-brand-border dark:border-[#2a3a2a] w-fit mx-auto md:mx-0">
                        {!! $this->referralQrSvg !!}
                    </div>

                    <div class="flex-1 flex flex-col justify-center gap-3">
                        <div class="flex items-center gap-2">
                            <input readonly value="{{ $this->referralLink }}" id="referral-link-input"
                                class="flex-1 h-10 px-3.5 bg-brand-bg dark:bg-[#0d1a0d] border border-[#d0d9d2] dark:border-[#2a3a2a] rounded-xl text-[12px] text-[#111] dark:text-[#e8f5e9] focus:outline-none">
                            <button type="button"
                                onclick="navigator.clipboard.writeText(document.getElementById('referral-link-input').value); this.innerText='Copied!'; setTimeout(() => this.innerText='Copy', 1500)"
                                class="h-10 px-4 bg-brand hover:bg-[#055002] text-white font-montserrat font-bold text-[12px] rounded-xl transition-colors flex-shrink-0">
                                Copy
                            </button>
                        </div>

                        <a href="data:image/svg+xml;base64,{{ base64_encode($this->referralQrSvg) }}" download="gadgetplug-referral-qr.svg"
                            class="h-10 px-4 border border-brand text-brand font-montserrat font-bold text-[12px] rounded-xl transition-colors flex items-center justify-center gap-2 w-fit">
                            Download QR (SVG)
                        </a>
                    </div>
                </div>
            </div>

            {{-- Per-product link builder --}}
            <div class="bg-white dark:bg-[#162016] border border-brand-border dark:border-[#2a3a2a] rounded-2xl p-5 md:p-6">
                <h2 class="font-montserrat font-bold text-[15px] text-brand-dark dark:text-[#e8f5e9] mb-1">
                    Product Link + QR
                </h2>
                <p class="text-[12px] text-brand-muted mb-4">
                    Search for a product to get a link/QR that lands directly on that product page, still carrying your code.
                </p>

                @if (! $this->selectedProduct)
                    <input wire:model.live.debounce.300ms="productSearch" type="text" placeholder="Search products…"
                        class="w-full h-10 px-3.5 bg-brand-bg dark:bg-[#0d1a0d] border border-[#d0d9d2] dark:border-[#2a3a2a] rounded-xl text-[13px] text-[#111] dark:text-[#e8f5e9] focus:outline-none focus:border-brand transition-colors">

                    @if ($this->productResults->isNotEmpty())
                        <div class="mt-2 border border-brand-border dark:border-[#2a3a2a] rounded-xl divide-y divide-brand-border dark:divide-[#2a3a2a] overflow-hidden">
                            @foreach ($this->productResults as $product)
                                <button type="button" wire:click="selectProduct({{ $product->id }})"
                                    class="w-full text-left px-3.5 py-2.5 text-[12px] text-[#111] dark:text-[#e8f5e9] hover:bg-brand-bg dark:hover:bg-[#1a2a1a] transition-colors">
                                    {{ $product->name }}
                                </button>
                            @endforeach
                        </div>
                    @endif
                @else
                    <div class="flex items-center justify-between mb-4">
                        <span class="text-[13px] font-semibold text-brand-dark dark:text-[#e8f5e9]">{{ $this->selectedProduct->name }}</span>
                        <button type="button" wire:click="clearSelectedProduct" class="text-[11px] text-brand-muted hover:text-brand">
                            Change product
                        </button>
                    </div>

                    <div class="flex flex-col md:flex-row gap-5">
                        <div class="flex-shrink-0 bg-white p-3 rounded-xl border border-brand-border dark:border-[#2a3a2a] w-fit mx-auto md:mx-0">
                            {!! $this->productQrSvg !!}
                        </div>

                        <div class="flex-1 flex flex-col justify-center gap-3">
                            <div class="flex items-center gap-2">
                                <input readonly value="{{ $this->productLink }}" id="product-link-input"
                                    class="flex-1 h-10 px-3.5 bg-brand-bg dark:bg-[#0d1a0d] border border-[#d0d9d2] dark:border-[#2a3a2a] rounded-xl text-[12px] text-[#111] dark:text-[#e8f5e9] focus:outline-none">
                                <button type="button"
                                    onclick="navigator.clipboard.writeText(document.getElementById('product-link-input').value); this.innerText='Copied!'; setTimeout(() => this.innerText='Copy', 1500)"
                                    class="h-10 px-4 bg-brand hover:bg-[#055002] text-white font-montserrat font-bold text-[12px] rounded-xl transition-colors flex-shrink-0">
                                    Copy
                                </button>
                            </div>

                            <a href="data:image/svg+xml;base64,{{ base64_encode($this->productQrSvg) }}" download="gadgetplug-product-qr.svg"
                                class="h-10 px-4 border border-brand text-brand font-montserrat font-bold text-[12px] rounded-xl transition-colors flex items-center justify-center gap-2 w-fit">
                                Download QR (SVG)
                            </a>
                        </div>
                    </div>
                @endif
            </div>

        </div>
    @endif

</x-layouts.account>
</div>

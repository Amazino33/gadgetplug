{{--
    The review state of a free (self-signup) shop, across the top of every
    panel page. Paid vendors — every vendor that existed before self-signup —
    get nothing here.
--}}
@php
    /** @var \App\Models\Vendor|null $vendor */
    $show = $vendor instanceof \App\Models\Vendor && $vendor->isFreePlan();
@endphp

@if ($show)
    @if ($vendor->isAwaitingInfo())
        <div class="mb-6 w-full rounded-xl border border-amber-300 bg-amber-50 p-4 text-amber-900 dark:border-amber-700 dark:bg-amber-950/40 dark:text-amber-100 sm:p-5">
            <p class="text-sm font-bold">We need a bit more info to approve your shop</p>
            <p class="mt-2 whitespace-pre-line rounded-lg bg-white/70 p-3 text-sm dark:bg-black/20">{{ $vendor->review_note }}</p>
            <div class="mt-3 flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                <p class="text-xs">
                    <span class="font-semibold">{{ $vendor->shopLinkLabel() }}</span> — not live yet
                </p>
                <a href="{{ route('free-shop.application.edit') }}"
                   class="inline-flex min-h-[44px] items-center justify-center rounded-lg bg-amber-600 px-4 text-sm font-bold text-white hover:bg-amber-700">
                    Update application
                </a>
            </div>
        </div>
    @elseif ($vendor->hasStatus(\App\Models\Vendor::STATUS_PENDING))
        <div class="mb-6 w-full rounded-xl border border-amber-300 bg-amber-50 p-4 text-amber-900 dark:border-amber-700 dark:bg-amber-950/40 dark:text-amber-100 sm:p-5">
            <p class="text-sm font-bold">Your shop is under review.</p>
            <p class="mt-1 text-sm">
                We usually review within 24 hours. Add your products now so your shop is ready the moment it's approved.
            </p>
            <p class="mt-3 text-xs">
                <span class="font-semibold">{{ $vendor->shopLinkLabel() }}</span> — not live yet
            </p>
        </div>
    @elseif ($vendor->hasStatus(\App\Models\Vendor::STATUS_APPROVED))
        <div x-data="{ copied: false }"
             class="mb-6 flex w-full flex-col gap-3 rounded-xl border border-green-300 bg-green-50 p-4 text-green-900 dark:border-green-800 dark:bg-green-950/40 dark:text-green-100 sm:flex-row sm:items-center sm:justify-between sm:p-5">
            <div class="min-w-0">
                <p class="text-xs font-semibold uppercase tracking-wide">Your shop link is live</p>
                <a href="{{ $vendor->shopLinkUrl() }}" target="_blank" rel="noopener"
                   class="mt-1 block truncate text-base font-bold underline">{{ $vendor->shopLinkLabel() }}</a>
            </div>
            <button type="button"
                    x-on:click="navigator.clipboard?.writeText(@js($vendor->shopLinkUrl())); copied = true; setTimeout(() => copied = false, 2000)"
                    class="inline-flex min-h-[44px] items-center justify-center rounded-lg bg-green-700 px-4 text-sm font-bold text-white hover:bg-green-800">
                <span x-show="! copied">Copy link</span>
                <span x-show="copied" x-cloak>Copied ✓</span>
            </button>
        </div>
    @endif
@endif

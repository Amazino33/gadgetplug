{{--
    Step A: how would you like to pay?

    Rendered in two places, which is the whole reason it is a component:
      · over the product page, opened instantly by Buy Now
      · as step 1 of the checkout wizard, for anyone arriving from the cart
    Both drive it through a Livewire method, so the only thing that differs
    between them is the method's name — hence $action.

    Styled after the feed splash (components/feed/splash.blade.php): the same
    dark brand field, the same centred logo, the same one-decision-on-screen
    composition. A shopper who entered the store through that screen meets its
    twin at the moment of paying, which is the point — nothing else competes
    for attention here.
--}}
@props([
    'action',              // Livewire method taking a payment method string
    'total'   => null,     // shown when known; the product page knows it too
    'heading' => 'How would you like to pay?',
])

<div class="relative flex min-h-full w-full flex-col items-center justify-center bg-brand-dark px-6 py-10 text-center">

    @isset($dismiss)
        <div class="absolute right-4 top-4 z-10">{{ $dismiss }}</div>
    @endisset

    <img src="{{ asset('images/logo.svg') }}" alt="GadgetPlug" class="mb-6 h-12 w-auto" />

    <h2 class="font-montserrat text-[22px] font-black leading-tight text-white">
        {{ $heading }}
    </h2>

    @if (! is_null($total))
        <p class="mt-2 text-sm text-white/70">
            Total <span class="font-montserrat font-black text-brand-lime">₦{{ number_format($total) }}</span>
        </p>
    @endif

    <div class="mt-8 flex w-full max-w-xs flex-col gap-3">

        {{-- Pay on Delivery first and visually loudest. It is the option that
             answers the objection most of these shoppers actually have — "what
             if I pay and get nothing" — so it carries the splash's lime CTA. --}}
        <button type="button"
            wire:click="{{ $action }}('pay_on_delivery')"
            wire:loading.attr="disabled"
            wire:target="{{ $action }}"
            class="group flex w-full items-center gap-3 rounded-2xl bg-brand-lime px-5 py-4 text-left transition-transform active:scale-[0.98] disabled:opacity-60">
            <span class="flex h-11 w-11 shrink-0 items-center justify-center rounded-xl bg-brand-dark/10">
                <svg class="h-6 w-6 fill-none" style="stroke:#0a2d09;stroke-width:2" viewBox="0 0 24 24">
                    <rect x="1" y="3" width="15" height="13" rx="1"/>
                    <path d="M16 8h4l3 3v5h-7V8z"/>
                    <circle cx="5.5" cy="18.5" r="2.5"/>
                    <circle cx="18.5" cy="18.5" r="2.5"/>
                </svg>
            </span>
            <span class="min-w-0 flex-1">
                <span class="block font-montserrat text-[15px] font-black text-brand-dark">Pay on Delivery</span>
                <span class="block text-[11px] text-brand-dark/70">Inspect it first, then pay the rider cash</span>
            </span>
        </button>

        <button type="button"
            wire:click="{{ $action }}('paystack')"
            wire:loading.attr="disabled"
            wire:target="{{ $action }}"
            class="group flex w-full items-center gap-3 rounded-2xl bg-brand-orange px-5 py-4 text-left transition-transform active:scale-[0.98] disabled:opacity-60">
            <span class="flex h-11 w-11 shrink-0 items-center justify-center rounded-xl bg-white/15">
                <svg class="h-6 w-6 fill-none" style="stroke:#fff;stroke-width:2" viewBox="0 0 24 24">
                    <rect x="1" y="4" width="22" height="16" rx="2" ry="2"/>
                    <line x1="1" y1="10" x2="23" y2="10"/>
                </svg>
            </span>
            <span class="min-w-0 flex-1">
                <span class="block font-montserrat text-[15px] font-black text-white">Pay with Paystack</span>
                <span class="block text-[11px] text-white/80">Card, transfer or USSD — pay online now</span>
            </span>
        </button>
    </div>

    {{-- One indicator for the pair. Which button was pressed is already obvious
         from the press; what the shopper needs to know is that something is
         happening and they should not tap again. --}}
    <p class="mt-4 flex items-center gap-2 text-[12px] text-white/70"
       wire:loading wire:target="{{ $action }}">
        <svg class="h-4 w-4 animate-spin fill-none" style="stroke:#B1FF00;stroke-width:2" viewBox="0 0 24 24">
            <circle cx="12" cy="12" r="9" style="opacity:.3"/>
            <path d="M21 12a9 9 0 0 0-9-9" stroke-linecap="round"/>
        </svg>
        Getting your order ready…
    </p>

    <p class="mt-6 flex items-center gap-1.5 text-[11px] text-white/40">
        <svg class="h-3 w-3 fill-none" style="stroke:currentColor;stroke-width:2" viewBox="0 0 24 24">
            <rect x="3" y="11" width="18" height="11" rx="2" ry="2"/>
            <path d="M7 11V7a5 5 0 0 1 10 0v4"/>
        </svg>
        Secure checkout · Verified vendors only
    </p>
</div>

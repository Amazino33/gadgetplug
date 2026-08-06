<?php

use Livewire\Volt\Component;
use App\Models\Affiliate;
use App\Models\Product;
use App\Services\Affiliate\QrCodeService;

new class extends Component {
    public ?Affiliate $affiliate = null;
    public string $productSearch = '';
    public ?int $selectedProductId = null;

    public function mount(): void
    {
        $this->affiliate = auth()->user()->affiliate;
    }

    public function getProductResultsProperty()
    {
        if (blank($this->productSearch) || ! $this->affiliate) {
            return collect();
        }

        return Product::where('status', 'published')
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

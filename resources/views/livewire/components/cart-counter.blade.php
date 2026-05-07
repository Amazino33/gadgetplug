<?php

use Livewire\Volt\Component;
use Livewire\Attributes\On;
use Illuminate\Support\Facades\Session;

new class extends Component {
    public int $count = 0;

    public function mount(): void
    {
        $this->updateCount();
    }

    #[On('cart-updated')]
    public function updateCount(): void
    {
        $cart = Session::get('cart', []);
        $this->count = array_sum(array_column($cart, 'quantity'));
    }
}; ?>

<a href="{{ route('cart') }}" class="relative flex flex-col items-center gap-0.5 cursor-pointer text-[#444]">
    <svg class="w-[22px] h-[22px] fill-none" style="stroke:#333;stroke-width:1.7" viewBox="0 0 24 24">
        <path d="M6 2L3 6v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V6l-3-4z"/>
        <line x1="3" y1="6" x2="21" y2="6"/>
        <path d="M16 10a4 4 0 0 1-8 0"/>
    </svg>
    <span class="text-[10px] text-[#5a7a5c] font-inter">Cart</span>
    @if ($count > 0)
    <span class="absolute -top-1 -right-1.5 bg-brand-lime text-brand-dark text-[9px] font-bold font-montserrat w-4 h-4 rounded-full flex items-center justify-center border border-white">
        {{ $count }}
    </span>
    @endif
</a>

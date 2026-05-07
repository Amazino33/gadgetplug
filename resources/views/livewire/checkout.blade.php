<?php

use Livewire\Volt\Component;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Services\CartService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Str;

new class extends Component {
    public string $name    = '';
    public string $email   = '';
    public string $phone   = '';
    public string $address = '';

    public array $cartItems = [];
    public float $total = 0;

    public function mount(): void
    {
        $cart = Session::get('cart', []);
        if (empty($cart)) {
            $this->redirectRoute('home');
            return;
        }

        $productIds = array_keys($cart);
        $products   = Product::with('media')->whereIn('id', $productIds)->get()->keyBy('id');

        foreach ($cart as $productId => $item) {
            $product = $products->get($productId);
            if (!$product) continue;

            $this->total += $product->price * $item['quantity'];
            $this->cartItems[] = [
                'product'  => $product,
                'quantity' => $item['quantity'],
                'subtotal' => $product->price * $item['quantity'],
                'thumb'    => $product->getFirstMediaUrl('product-images', 'thumb'),
            ];
        }

        // Pre-fill from auth if available
        if (auth()->check()) {
            $this->name  = auth()->user()->name;
            $this->email = auth()->user()->email;
        }
    }

    public function processCheckout(): void
    {
        $this->validate([
            'name'    => 'required|string|max:255',
            'email'   => 'required|email|max:255',
            'phone'   => 'required|string|max:20',
            'address' => 'required|string|min:10',
        ]);

        $reference = 'GP-' . strtoupper(Str::random(10));

        $order = Order::create([
            'reference'        => $reference,
            'customer_name'    => $this->name,
            'customer_email'   => $this->email,
            'customer_phone'   => $this->phone,
            'shipping_address' => $this->address,
            'total_amount'     => $this->total,
            'status'           => 'pending',
            'payment_method'   => 'paystack',
        ]);

        foreach ($this->cartItems as $item) {
            OrderItem::create([
                'order_id'   => $order->id,
                'product_id' => $item['product']->id,
                'vendor_id'  => $item['product']->vendor_id,
                'quantity'   => $item['quantity'],
                'unit_price' => $item['product']->price,
            ]);
        }

        try {
            $paystackKey = config('services.paystack.secret_key');

            if (!$paystackKey) {
                session()->flash('error', 'Payment configuration error. Please contact support.');
                return;
            }

            $response = Http::withoutVerifying()
                ->timeout(30)
                ->withOptions([CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4])
                ->withToken($paystackKey)
                ->post('https://api.paystack.co/transaction/initialize', [
                    'amount'       => (int) ($this->total * 100),
                    'email'        => $this->email,
                    'reference'    => $reference,
                    'callback_url' => route('payment.callback'),
                ]);

            if ($response->successful() && $url = $response->json('data.authorization_url')) {
                $this->redirect($url);
                return;
            }

            \Log::error('Paystack error', ['status' => $response->status(), 'body' => $response->json()]);
            session()->flash('error', 'Could not connect to payment gateway. Please try again.');

        } catch (\Exception $e) {
            \Log::error('Paystack exception: ' . $e->getMessage());
            session()->flash('error', 'Payment system error. Please try again later.');
        }
    }
}; ?>

<div>
<x-layouts.storefront>

<div class="bg-[#f8fcf8] dark:bg-[#0d1a0d] min-h-screen">
<div class="px-4 md:px-6 py-7 max-w-[1000px] mx-auto">

    {{-- ─── PAGE HEADER ─────────────────────────────────────────────────────── --}}
    <div class="flex items-center gap-3 mb-7">
        <a href="{{ route('cart') }}" class="w-8 h-8 rounded-full bg-brand-bg dark:bg-[#1a2a1a] border border-brand-border dark:border-[#2a3a2a] flex items-center justify-center hover:border-brand transition-colors">
            <svg class="w-4 h-4 fill-none" style="stroke:#5a7a5c;stroke-width:2" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" d="M10 19l-7-7m0 0l7-7m-7 7h18"/>
            </svg>
        </a>
        <div>
            <h1 class="font-montserrat font-black text-[24px] md:text-[28px] text-brand-dark dark:text-[#e8f5e9]">Checkout</h1>
            <p class="text-[12px] text-brand-muted">Secure checkout powered by Paystack</p>
        </div>
    </div>

    {{-- ─── PROGRESS STEPS ──────────────────────────────────────────────────── --}}
    <div class="flex items-center gap-0 mb-8">
        @foreach([['1','Cart','done'],['2','Details','active'],['3','Payment','pending']] as [$n,$lbl,$state])
        <div class="flex items-center {{ !$loop->last ? 'flex-1' : '' }}">
            <div class="flex items-center gap-2">
                <div class="w-7 h-7 rounded-full flex items-center justify-center font-montserrat font-bold text-[11px] flex-shrink-0
                    {{ $state === 'done' ? 'bg-brand text-white' : ($state === 'active' ? 'bg-brand-orange text-white' : 'bg-brand-bg dark:bg-[#1a2a1a] border-2 border-[#d0d9d2] dark:border-[#2a3a2a] text-brand-muted') }}">
                    @if ($state === 'done')
                    <svg class="w-3.5 h-3.5 fill-none" style="stroke:#fff;stroke-width:2.5" viewBox="0 0 24 24">
                        <polyline points="20 6 9 17 4 12"/>
                    </svg>
                    @else
                    {{ $n }}
                    @endif
                </div>
                <span class="text-[12px] font-medium {{ $state === 'active' ? 'text-brand-orange font-semibold' : ($state === 'done' ? 'text-brand' : 'text-brand-muted') }}">
                    {{ $lbl }}
                </span>
            </div>
            @if (!$loop->last)
            <div class="flex-1 h-px bg-[#e0e8e1] dark:bg-[#2a3a2a] mx-3"></div>
            @endif
        </div>
        @endforeach
    </div>

    @if (session()->has('error'))
    <div class="bg-[#fce4ec] border border-[#f8bbd0] text-red-700 px-4 py-3 rounded-xl mb-5 text-[13px] flex items-start gap-2">
        <svg class="w-4 h-4 flex-shrink-0 mt-0.5 fill-none" style="stroke:currentColor;stroke-width:2" viewBox="0 0 24 24">
            <circle cx="12" cy="12" r="10"/>
            <line x1="12" y1="8" x2="12" y2="12"/>
            <line x1="12" y1="16" x2="12.01" y2="16"/>
        </svg>
        {{ session('error') }}
    </div>
    @endif

    <form wire:submit="processCheckout">
    <div class="flex flex-col lg:flex-row gap-6 items-start">

        {{-- ─── LEFT: FORM ──────────────────────────────────────────────────── --}}
        <div class="flex-1 min-w-0 space-y-5">

            {{-- Contact details --}}
            <div class="bg-white dark:bg-[#1a2a1a] rounded-2xl border border-brand-border dark:border-[#2a3a2a] overflow-hidden">
                <div class="px-5 py-4 border-b border-brand-border dark:border-[#2a3a2a] bg-gradient-to-br from-[#f0f8f0] to-[#e8f5e9] dark:from-[#1a2a1a] dark:to-[#162016]">
                    <h2 class="font-montserrat font-bold text-[14px] text-brand-dark dark:text-[#e8f5e9] flex items-center gap-2">
                        <svg class="w-4 h-4 fill-none" style="stroke:#068B03;stroke-width:2" viewBox="0 0 24 24">
                            <path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/>
                        </svg>
                        Contact Details
                    </h2>
                </div>
                <div class="p-5 grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div>
                        <label class="block text-[12px] font-semibold text-brand-dark dark:text-[#e8f5e9] mb-1.5">Full Name *</label>
                        <input type="text" wire:model="name" placeholder="John Doe"
                            class="w-full bg-brand-bg dark:bg-[#0d1a0d] border border-[#d0d9d2] dark:border-[#2a3a2a] rounded-xl px-3.5 py-2.5 text-[13px] text-[#111] dark:text-[#e8f5e9] outline-none focus:border-brand transition-colors placeholder-[#8a9e8c]">
                        @error('name') <p class="text-red-500 text-[11px] mt-1">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="block text-[12px] font-semibold text-brand-dark dark:text-[#e8f5e9] mb-1.5">Email Address *</label>
                        <input type="email" wire:model="email" placeholder="john@example.com"
                            class="w-full bg-brand-bg dark:bg-[#0d1a0d] border border-[#d0d9d2] dark:border-[#2a3a2a] rounded-xl px-3.5 py-2.5 text-[13px] text-[#111] dark:text-[#e8f5e9] outline-none focus:border-brand transition-colors placeholder-[#8a9e8c]">
                        @error('email') <p class="text-red-500 text-[11px] mt-1">{{ $message }}</p> @enderror
                    </div>
                    <div class="md:col-span-2">
                        <label class="block text-[12px] font-semibold text-brand-dark dark:text-[#e8f5e9] mb-1.5">Phone Number *</label>
                        <input type="tel" wire:model="phone" placeholder="08012345678"
                            class="w-full bg-brand-bg dark:bg-[#0d1a0d] border border-[#d0d9d2] dark:border-[#2a3a2a] rounded-xl px-3.5 py-2.5 text-[13px] text-[#111] dark:text-[#e8f5e9] outline-none focus:border-brand transition-colors placeholder-[#8a9e8c]">
                        @error('phone') <p class="text-red-500 text-[11px] mt-1">{{ $message }}</p> @enderror
                    </div>
                </div>
            </div>

            {{-- Delivery address --}}
            <div class="bg-white dark:bg-[#1a2a1a] rounded-2xl border border-brand-border dark:border-[#2a3a2a] overflow-hidden">
                <div class="px-5 py-4 border-b border-brand-border dark:border-[#2a3a2a] bg-gradient-to-br from-[#f0f8f0] to-[#e8f5e9] dark:from-[#1a2a1a] dark:to-[#162016]">
                    <h2 class="font-montserrat font-bold text-[14px] text-brand-dark dark:text-[#e8f5e9] flex items-center gap-2">
                        <svg class="w-4 h-4 fill-none" style="stroke:#068B03;stroke-width:2" viewBox="0 0 24 24">
                            <path d="M12 2C8.13 2 5 5.13 5 9c0 5.25 7 13 7 13s7-7.75 7-13c0-3.87-3.13-7-7-7zm0 9.5c-1.38 0-2.5-1.12-2.5-2.5s1.12-2.5 2.5-2.5 2.5 1.12 2.5 2.5-1.12 2.5-2.5 2.5z"/>
                        </svg>
                        Delivery Address
                    </h2>
                </div>
                <div class="p-5">
                    <label class="block text-[12px] font-semibold text-brand-dark dark:text-[#e8f5e9] mb-1.5">Full Delivery Address *</label>
                    <textarea wire:model="address" placeholder="Enter your street address, landmark, LGA, State…"
                        rows="4"
                        class="w-full bg-brand-bg dark:bg-[#0d1a0d] border border-[#d0d9d2] dark:border-[#2a3a2a] rounded-xl px-3.5 py-2.5 text-[13px] text-[#111] dark:text-[#e8f5e9] outline-none focus:border-brand transition-colors placeholder-[#8a9e8c] resize-none"></textarea>
                    @error('address') <p class="text-red-500 text-[11px] mt-1">{{ $message }}</p> @enderror
                    <p class="text-[11px] text-brand-muted mt-1.5">
                        💡 Our rider will bring the item to this address. You can inspect before paying.
                    </p>
                </div>
            </div>

            {{-- Payment section --}}
            <div class="bg-white dark:bg-[#1a2a1a] rounded-2xl border border-brand-border dark:border-[#2a3a2a] overflow-hidden">
                <div class="px-5 py-4 border-b border-brand-border dark:border-[#2a3a2a] bg-gradient-to-br from-[#f0f8f0] to-[#e8f5e9] dark:from-[#1a2a1a] dark:to-[#162016]">
                    <h2 class="font-montserrat font-bold text-[14px] text-brand-dark dark:text-[#e8f5e9] flex items-center gap-2">
                        <svg class="w-4 h-4 fill-none" style="stroke:#068B03;stroke-width:2" viewBox="0 0 24 24">
                            <rect x="1" y="4" width="22" height="16" rx="2" ry="2"/>
                            <line x1="1" y1="10" x2="23" y2="10"/>
                        </svg>
                        Payment Method
                    </h2>
                </div>
                <div class="p-5">
                    <div class="flex items-center gap-3 p-4 bg-[#f8fcf8] dark:bg-[#162016] rounded-xl border-2 border-brand">
                        <div class="w-10 h-10 bg-brand rounded-xl flex items-center justify-center flex-shrink-0">
                            <svg class="w-5 h-5 fill-brand-lime" viewBox="0 0 24 24">
                                <path d="M13 2L4 14h8l-1 8 9-12h-8z"/>
                            </svg>
                        </div>
                        <div class="flex-1">
                            <div class="font-montserrat font-bold text-[13px] text-brand-dark dark:text-[#e8f5e9]">Paystack</div>
                            <div class="text-[11px] text-brand-muted">Cards, bank transfer, USSD & more</div>
                        </div>
                        <div class="w-5 h-5 rounded-full bg-brand flex items-center justify-center">
                            <svg class="w-3 h-3 fill-none" style="stroke:#fff;stroke-width:2.5" viewBox="0 0 24 24">
                                <polyline points="20 6 9 17 4 12"/>
                            </svg>
                        </div>
                    </div>
                    <p class="text-[11px] text-brand-muted mt-2.5 text-center">
                        🔒 Your payment is secured by Paystack. We never store card details.
                    </p>
                </div>
            </div>
        </div>

        {{-- ─── RIGHT: ORDER SUMMARY ─────────────────────────────────────────── --}}
        <div class="w-full lg:w-[320px] flex-shrink-0 sticky top-[96px] space-y-4">

            {{-- Summary card --}}
            <div class="bg-white dark:bg-[#1a2a1a] rounded-2xl border border-brand-border dark:border-[#2a3a2a] overflow-hidden">
                <div class="px-5 py-4 border-b border-brand-border dark:border-[#2a3a2a] bg-gradient-to-br from-[#f0f8f0] to-[#e8f5e9] dark:from-[#1a2a1a] dark:to-[#162016]">
                    <h2 class="font-montserrat font-bold text-[14px] text-brand-dark dark:text-[#e8f5e9]">Order Summary</h2>
                </div>
                <div class="p-5 space-y-3">

                    {{-- Item list --}}
                    @foreach ($cartItems as $item)
                    <div class="flex gap-2.5 items-center">
                        <div class="w-10 h-10 rounded-lg bg-brand-bg dark:bg-[#0d1a0d] border border-brand-border dark:border-[#2a3a2a] flex items-center justify-center flex-shrink-0 overflow-hidden">
                            @if ($item['thumb'])
                                <img src="{{ $item['thumb'] }}" alt="{{ $item['product']->name }}" class="w-full h-full object-cover">
                            @else
                                <span class="text-lg">📦</span>
                            @endif
                        </div>
                        <div class="flex-1 min-w-0">
                            <div class="text-[11px] font-medium text-[#111] dark:text-[#e8f5e9] line-clamp-1">{{ $item['product']->name }}</div>
                            <div class="text-[10px] text-brand-muted">Qty: {{ $item['quantity'] }}</div>
                        </div>
                        <div class="font-montserrat font-bold text-[12px] text-brand flex-shrink-0">
                            ₦{{ number_format($item['subtotal']) }}
                        </div>
                    </div>
                    @endforeach

                    <div class="border-t border-brand-border dark:border-[#2a3a2a] pt-3 space-y-1.5">
                        <div class="flex justify-between text-[12px]">
                            <span class="text-brand-muted">Subtotal</span>
                            <span class="text-[#111] dark:text-[#e8f5e9] font-medium">₦{{ number_format($total) }}</span>
                        </div>
                        <div class="flex justify-between text-[12px]">
                            <span class="text-brand-muted">Delivery</span>
                            <span class="text-brand font-semibold">TBD at delivery</span>
                        </div>
                    </div>

                    <div class="border-t-2 border-brand-border dark:border-[#2a3a2a] pt-3 flex justify-between items-center">
                        <span class="font-montserrat font-bold text-[14px] text-brand-dark dark:text-[#e8f5e9]">Total</span>
                        <span class="font-montserrat font-black text-[22px] text-brand">₦{{ number_format($total) }}</span>
                    </div>
                </div>
            </div>

            {{-- Trust badges --}}
            <div class="bg-white dark:bg-[#1a2a1a] rounded-2xl border border-brand-border dark:border-[#2a3a2a] p-4 space-y-2.5">
                @foreach([
                    ['icon'=>'shield','color'=>'#068B03','text'=>'CAC-registered verified vendors only'],
                    ['icon'=>'eye','color'=>'#F97316','text'=>'Test before you pay — zero risk'],
                    ['icon'=>'lock','color'=>'#0a2d09','text'=>'SSL encrypted secure checkout'],
                ] as $t)
                <div class="flex items-center gap-2.5">
                    <div class="w-7 h-7 rounded-lg flex items-center justify-center flex-shrink-0" style="background:{{ $t['color'] }}10">
                        @if ($t['icon'] === 'shield')
                        <svg class="w-3.5 h-3.5 fill-none" style="stroke:{{ $t['color'] }};stroke-width:2" viewBox="0 0 24 24">
                            <path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/>
                        </svg>
                        @elseif ($t['icon'] === 'eye')
                        <svg class="w-3.5 h-3.5 fill-none" style="stroke:{{ $t['color'] }};stroke-width:2" viewBox="0 0 24 24">
                            <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/>
                            <circle cx="12" cy="12" r="3"/>
                        </svg>
                        @else
                        <svg class="w-3.5 h-3.5 fill-none" style="stroke:{{ $t['color'] }};stroke-width:2" viewBox="0 0 24 24">
                            <rect x="3" y="11" width="18" height="11" rx="2" ry="2"/>
                            <path d="M7 11V7a5 5 0 0 1 10 0v4"/>
                        </svg>
                        @endif
                    </div>
                    <span class="text-[11px] text-brand-muted">{{ $t['text'] }}</span>
                </div>
                @endforeach
            </div>

            {{-- PAY BUTTON --}}
            <button type="submit"
                class="w-full flex items-center justify-center gap-2 bg-brand-orange hover:bg-[#e06610] text-white font-montserrat font-bold text-[15px] py-4 rounded-xl border-0 cursor-pointer transition-all hover:-translate-y-px shadow-lg">
                <svg class="w-5 h-5 fill-none" style="stroke:currentColor;stroke-width:2" viewBox="0 0 24 24">
                    <rect x="1" y="4" width="22" height="16" rx="2" ry="2"/>
                    <line x1="1" y1="10" x2="23" y2="10"/>
                </svg>
                Pay ₦{{ number_format($total) }} with Paystack
            </button>

            <p class="text-center text-[10px] text-brand-muted">
                By completing your purchase you agree to our
                <a href="#" class="text-brand hover:underline">Terms of Service</a>
            </p>
        </div>
    </div>
    </form>
</div>
</div>

</x-layouts.storefront>
</div>

<?php

use Livewire\Volt\Component;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Services\Affiliate\AttributionService;
use App\Services\CartService;
use App\Services\Messaging\PhoneNumber;
use App\Services\Meta\MetaConversionsService;
use App\Actions\Inventory\ReserveStockAction;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Str;

new class extends Component {
    /**
     * How long two identical submissions are treated as the same checkout.
     *
     * Long enough to cover a double-tap, a retried request on a dropped mobile
     * connection, and a second tab; short enough that a customer who genuinely
     * wants the same cart again a few minutes later is not blocked.
     */
    private const DUPLICATE_WINDOW_SECONDS = 120;

    /**
     * Which of the three steps is on screen: payment, delivery, confirm.
     *
     * One component holds all three and all their state, so moving between
     * them is a property change rather than a page load, and going back cannot
     * wipe anything the customer has typed — the fields never left.
     */
    public int $step = 1;

    public const STEP_PAYMENT  = 1;
    public const STEP_DELIVERY = 2;
    public const STEP_CONFIRM  = 3;

    public string $name          = '';
    public string $email         = '';
    public string $phone         = '';
    public string $lga           = '';
    public string $address       = '';
    public string $paymentMethod = '';
    public string $referralCode  = '';

    public array $cartItems = [];
    public float $total = 0;

    public bool   $paid          = false;
    public string $paidReference = '';
    public string $paidMethod    = '';
    public float  $paidTotal     = 0.0;
    public string $paidName      = '';
    public array  $paidItems     = [];
    public array  $paidProductIds = [];

    // Pixel event_id for whichever event this page load fires — InitiateCheckout
    // on the form, Purchase on the success screen. Shared with the server-side
    // CAPI copy (InitiateCheckout: dispatched right here; Purchase: dispatched
    // from OrderObserver off the order's own reference) so Meta deduplicates
    // browser + server into one event instead of double-counting.
    public ?string $pixelEventId = null;

    // When the customer wants the order. Asked after checkout rather than during
    // it, so it never stands between them and paying.
    public string $deliveryUrgency = '';
    public string $deliveryDate    = '';
    public bool   $showDatePicker  = false;

    public function mount(): void
    {
        // Show success screen after Paystack or Pay-on-Delivery completion
        if ($ref = session()->pull('payment_success')) {
            $this->paid          = true;
            $this->paidReference = $ref;

            $order = Order::with('items.product.media')
                ->where('reference', $this->paidReference)
                ->first();

            if ($order) {
                $this->paidMethod = $order->payment_method;
                $this->paidTotal  = (float) $order->total_amount;
                $this->paidName   = $order->customer_name;
                foreach ($order->items as $item) {
                    $this->paidItems[] = [
                        'name'     => $item->product->name ?? 'Unknown',
                        'quantity' => $item->quantity,
                        'subtotal' => $item->unit_price * $item->quantity,
                        'thumb'    => $item->product?->getFirstMediaUrl('product-images', 'thumb') ?? '',
                    ];
                    if ($item->product) {
                        $this->paidProductIds[] = $item->product->id;
                    }
                }

                // Same event_id the server-side copy uses (dispatched from
                // OrderObserver off this same order, keyed on its reference) —
                // Meta dedupes browser + server into one Purchase.
                $this->pixelEventId = $order->reference;

                $this->deliveryUrgency = $order->delivery_urgency ?? '';
                $this->deliveryDate    = $order->preferred_delivery_date?->format('Y-m-d') ?? '';
            }
            return;
        }

        $cart = Session::get('cart', []);
        if (empty($cart)) {
            $this->redirectRoute('home');
            return;
        }

        $productIds = array_keys($cart);
        $products   = Product::with(['media', 'vendor'])->whereIn('id', $productIds)->get()->keyBy('id');
        $droppedZeroQty = false;
        $droppedUnavailableVendor = false;

        foreach ($cart as $productId => $item) {
            $product = $products->get($productId);
            if (!$product) continue;

            // A zero-quantity line should never exist (CartService::add() now
            // refuses to create one), but a stale session predating that fix,
            // or any other path into the cart array, must not be allowed to
            // silently produce a ₦0 order with a phantom item on it either.
            if ((int) $item['quantity'] <= 0) {
                unset($cart[$productId]);
                $droppedZeroQty = true;
                continue;
            }

            // A vendor disabled after this product was added to the session
            // cart — drop just their item, not the customer's whole cart.
            if (! $product->vendor?->canSellOnline()) {
                unset($cart[$productId]);
                $droppedUnavailableVendor = true;
                continue;
            }

            $this->total += $product->price * $item['quantity'];
            $this->cartItems[] = [
                'product'  => $product,
                'quantity' => $item['quantity'],
                'subtotal' => $product->price * $item['quantity'],
                'thumb'    => $product->getFirstMediaUrl('product-images', 'thumb'),
            ];
        }

        if ($droppedZeroQty || $droppedUnavailableVendor) {
            Session::put('cart', $cart);
        }

        if ($droppedUnavailableVendor) {
            session()->flash('error', 'Some items in your cart are no longer available and have been removed.');
        } elseif ($droppedZeroQty) {
            session()->flash('error', 'One or more items in your cart were out of stock and have been removed.');
        }

        if (empty($this->cartItems)) {
            $this->redirectRoute('cart');
            return;
        }

        if (auth()->check()) {
            $user          = auth()->user();
            $this->name    = $user->name;
            $this->email   = $user->email;
            $this->phone   = $user->phone   ?? '';
            $this->address = $user->address ?? '';
        }

        // Buy Now on a product page already showed the payment screen and
        // recorded the answer, so arriving here means step 1 is behind us —
        // open on the delivery form rather than asking the same question
        // twice. Read from the session, not the URL: what is asked for next
        // depends on it, so a link must not be able to set it.
        $chosen = session()->pull('checkout_payment_method');

        if (in_array($chosen, ['paystack', 'pay_on_delivery'], true)) {
            $this->paymentMethod = $chosen;
            $this->step          = self::STEP_DELIVERY;

            // InitiateCheckout and AddPaymentInfo were both fired on the
            // product page, where those steps actually happened. Firing them
            // again here would double-count the top of the funnel.
            return;
        }

        $this->pixelEventId = (string) Str::uuid();

        app(MetaConversionsService::class)->dispatchEvent(
            eventName: 'InitiateCheckout',
            eventId: $this->pixelEventId,
            eventSourceUrl: url()->current(),
            userData: [
                'email'      => $this->email ?: null,
                'phone'      => $this->phone ?: null,
                'name'       => $this->name ?: null,
                'fbp'        => request()->cookie('_fbp'),
                'fbc'        => request()->cookie('_fbc'),
                'client_ip'  => request()->ip(),
                'user_agent' => request()->userAgent(),
            ],
            customData: [
                'currency'     => 'NGN',
                'value'        => $this->total,
                'content_ids'  => array_map(fn (array $item) => $item['product']->id, $this->cartItems),
                'content_type' => 'product',
            ],
        );
    }

    /**
     * Step 1 → 2. A payment method was chosen here rather than on a product
     * page, which is what happens when the customer came from the cart.
     */
    public function choosePayment(string $method): void
    {
        if (! in_array($method, ['paystack', 'pay_on_delivery'], true)) {
            return;
        }

        $this->paymentMethod = $method;

        $this->fireAddPaymentInfo($method);

        $this->step = self::STEP_DELIVERY;
    }

    /**
     * AddPaymentInfo — browser and server copies under one event_id so Meta
     * folds them into a single event rather than counting two.
     */
    private function fireAddPaymentInfo(string $method): void
    {
        $eventId = (string) Str::uuid();

        app(MetaConversionsService::class)->dispatchEvent(
            eventName: 'AddPaymentInfo',
            eventId: $eventId,
            eventSourceUrl: url()->current(),
            userData: [
                'email'      => $this->email ?: null,
                'phone'      => $this->phone ?: null,
                'name'       => $this->name ?: null,
                'fbp'        => request()->cookie('_fbp'),
                'fbc'        => request()->cookie('_fbc'),
                'client_ip'  => request()->ip(),
                'user_agent' => request()->userAgent(),
            ],
            customData: [
                'currency'       => 'NGN',
                'value'          => $this->total,
                'content_ids'    => array_map(fn (array $item) => $item['product']->id, $this->cartItems),
                'content_type'   => 'product',
                'payment_method' => $method,
            ],
        );

        $this->dispatch('pixel-add-payment-info', eventId: $eventId, value: $this->total);
    }

    /**
     * Step 2 → 3. Everything is checked here rather than at the end, so a
     * mistyped number surfaces beside the field that has it and not on a
     * summary screen two taps later.
     */
    public function goToConfirm(): void
    {
        $this->validate($this->deliveryRules(), $this->deliveryMessages());

        $this->step = self::STEP_CONFIRM;
    }

    /**
     * Back, by tapping the progress bar or the back button on a step.
     *
     * Nothing is cleared on the way — the form fields are component state and
     * simply stay as they were, which is the whole reason the flow is one
     * component. A mistap costs a tap, not a retyped address.
     */
    public function goToStep(int $step): void
    {
        if ($step < self::STEP_PAYMENT || $step > self::STEP_CONFIRM) {
            return;
        }

        // Never forward past a step whose answers are still missing: without
        // this, the progress bar would be a way to reach Place Order with an
        // empty address.
        if ($step > $this->step) {
            return;
        }

        $this->resetErrorBag();
        $this->step = $step;
    }

    /**
     * When the customer wants it. Recorded as a tap on step 2 rather than
     * typed, and resolved to a real date only when the order is written.
     */
    public function setUrgency(string $urgency): void
    {
        if (! in_array($urgency, Order::URGENCIES, true)) {
            return;
        }

        $this->deliveryUrgency = $urgency;

        if ($urgency === Order::URGENCY_SCHEDULED) {
            $this->showDatePicker = true;
        } else {
            $this->showDatePicker = false;
            $this->deliveryDate   = '';
        }

        $this->resetErrorBag(['deliveryUrgency', 'deliveryDate']);
    }

    /**
     * What step 2 must have before the order can be written.
     *
     * Email is the one rule that moves. Paystack's transaction/initialize
     * refuses a transaction without an address, so paying online genuinely
     * requires one; pay-on-delivery has no such constraint, and there it is
     * captured if offered and never insisted on. Most of this traffic arrives
     * from an ad on a phone, and an email field standing between a shopper and
     * a cash-on-delivery order is a sale lost to a formality.
     */
    private function deliveryRules(): array
    {
        return [
            'name'  => 'required|string|max:255',
            'email' => $this->paymentMethod === 'paystack'
                ? 'required|email|max:255'
                : 'nullable|email|max:255',
            // Order updates are delivered to this number over WhatsApp, so a
            // typo here means the customer silently hears nothing. Validate the
            // shape at the door rather than discovering it at send time.
            'phone' => ['required', 'string', 'max:20', function ($attribute, $value, $fail) {
                $normalized = PhoneNumber::toNigerianInternational($value);

                if (strlen((string) $normalized) !== 13) {
                    $fail('Enter a valid Nigerian WhatsApp number, for example 08012345678.');
                }
            }],
            'lga'             => 'required|in:Uyo,Mkpat Enin,Eket',
            'address'         => 'required|string|min:10',
            'deliveryUrgency' => ['required', 'in:' . implode(',', Order::URGENCIES)],
            'deliveryDate'    => [
                // Implicit, so it still runs when the field is empty — a
                // closure alone would be skipped on an empty string and "Pick
                // a date" with no date would pass.
                'required_if:deliveryUrgency,' . Order::URGENCY_SCHEDULED,
                'nullable',
                function ($attribute, $value, $fail) {
                    if ($this->deliveryUrgency !== Order::URGENCY_SCHEDULED) {
                        return;
                    }

                    if ($this->parseScheduledDate($value) === null) {
                        $fail('Pick a date between today and ' . now()->addDays(Order::MAX_SCHEDULE_DAYS)->format('j M Y') . '.');
                    }
                },
            ],
        ];
    }

    private function deliveryMessages(): array
    {
        return [
            'name.required'            => 'We need a name to put on the delivery.',
            'email.required'           => 'Paystack sends your receipt here, so an email is needed to pay online.',
            'email.email'              => 'That email address does not look right.',
            'phone.required'           => 'We need a WhatsApp number to send your order updates to.',
            'lga.required'             => 'Choose the area we are delivering to.',
            'lga.in'                   => 'We only deliver to Uyo, Mkpat Enin and Eket right now.',
            'address.required'         => 'Where should the rider bring it?',
            'address.min'              => 'Add a bit more — a house number, street and a landmark nearby.',
            'deliveryUrgency.required' => 'Tell us when you want it.',
            'deliveryDate.required_if'  => 'Pick the date you want it delivered.',
        ];
    }

    /**
     * Validate one field as the customer leaves it, so an error appears beside
     * the thing that caused it while they are still looking at it — and never
     * while they are mid-word, because the inputs bind on blur.
     */
    public function updated(string $property): void
    {
        if ($this->step !== self::STEP_DELIVERY) {
            return;
        }

        $rules = $this->deliveryRules();

        if (! array_key_exists($property, $rules)) {
            return;
        }

        $this->validateOnly($property, $rules, $this->deliveryMessages());
    }

    /**
     * The chosen delivery day as a real date.
     *
     * Today and Tomorrow are relative to the moment the order is written, not
     * to when the page was opened — a cart left sitting overnight would
     * otherwise record yesterday as the delivery day.
     */
    private function resolvedDeliveryDate(): ?\Carbon\CarbonInterface
    {
        // CarbonInterface, not Carbon: this app runs on CarbonImmutable, so
        // now() returns one of those while parseScheduledDate() builds a
        // mutable Carbon. Both satisfy the interface; either concrete class
        // as the hint rejects the other half of this match.

        return match ($this->deliveryUrgency) {
            Order::URGENCY_TODAY     => now()->startOfDay(),
            Order::URGENCY_TOMORROW  => now()->addDay()->startOfDay(),
            Order::URGENCY_SCHEDULED => $this->parseScheduledDate($this->deliveryDate),
            default                  => null,
        };
    }

    // One tap records the choice — no separate save step, because anything the
    // customer has to confirm after paying, most of them simply won't.
    public function chooseDelivery(string $urgency, ?string $date = null): void
    {
        // Only ever writes the order this browser just paid for. $paidReference
        // comes from the server-side session in mount(), not from the request,
        // so a crafted call cannot retarget somebody else's order.
        if (! $this->paid || $this->paidReference === '') {
            return;
        }

        if (! in_array($urgency, Order::URGENCIES, true)) {
            return;
        }

        $resolved = match ($urgency) {
            Order::URGENCY_TODAY    => now()->startOfDay(),
            Order::URGENCY_TOMORROW => now()->addDay()->startOfDay(),
            // Falls back to the bound input, which is how the Confirm button
            // passes the picked date — wire:model ships it with this same call.
            default                 => $this->parseScheduledDate($date ?: $this->deliveryDate),
        };

        if ($resolved === null) {
            $this->addError('deliveryDate', 'Pick a date between today and ' . now()->addDays(Order::MAX_SCHEDULE_DAYS)->format('j M Y') . '.');
            return;
        }

        $this->resetErrorBag('deliveryDate');

        Order::where('reference', $this->paidReference)->update([
            'delivery_urgency'           => $urgency,
            'preferred_delivery_date'    => $resolved->toDateString(),
            'delivery_preference_set_at' => now(),
        ]);

        $this->deliveryUrgency = $urgency;
        $this->deliveryDate    = $resolved->toDateString();
        $this->showDatePicker  = false;
    }

    // Rejects anything outside today .. today + MAX_SCHEDULE_DAYS, including
    // unparseable input, rather than silently storing a nonsense date.
    private function parseScheduledDate(?string $date): ?\Illuminate\Support\Carbon
    {
        if (! $date) {
            return null;
        }

        try {
            $parsed = \Illuminate\Support\Carbon::createFromFormat('Y-m-d', $date)->startOfDay();
        } catch (\Throwable) {
            return null;
        }

        if ($parsed->lt(now()->startOfDay()) || $parsed->gt(now()->addDays(Order::MAX_SCHEDULE_DAYS)->startOfDay())) {
            return null;
        }

        return $parsed;
    }

    public function processCheckout(): void
    {
        // The same rules step 2 enforced, re-run here because this is the only
        // place they actually protect anything — a crafted request can call
        // this method without ever having passed through that step.
        $this->validate(
            $this->deliveryRules() + ['paymentMethod' => 'required|in:paystack,pay_on_delivery'],
            $this->deliveryMessages(),
        );

        // Final guard, right before anything is written — no order should
        // ever be created with no real items or a zero total.
        $this->cartItems = array_values(array_filter(
            $this->cartItems,
            fn (array $item) => (int) $item['quantity'] > 0,
        ));

        if (empty($this->cartItems) || $this->total <= 0) {
            session()->flash('error', 'Your cart is empty or out of stock. Please review it before checking out.');
            $this->redirectRoute('cart');
            return;
        }

        // Final guard against a vendor being disabled between page load and
        // submission — mount() already filtered this on load, so this should
        // essentially never trigger. If it does, don't silently complete a
        // partial order; send the customer back to review their cart.
        if (collect($this->cartItems)->contains(fn (array $item) => ! $item['product']->vendor?->canSellOnline())) {
            session()->flash('error', 'Some items in your cart became unavailable. Please review your cart and try again.');
            $this->redirectRoute('cart');
            return;
        }

        // ── One checkout, one order ──────────────────────────────────────────
        //
        // Two layers, because either alone leaves a hole. The disabled button
        // stops the ordinary double-tap but not a retried request or a second
        // tab; the pre-check below stops those but races with a genuinely
        // concurrent pair, which is what the unique index is for.
        $fingerprint = $this->checkoutFingerprint();

        if ($existing = $this->recentOrderFor($fingerprint)) {
            $this->resumeExistingOrder($existing);
            return;
        }

        $reference = 'GP-' . strtoupper(Str::random(10));

        try {
            $order = Order::create([
                'user_id'          => auth()->id(),
                'reference'        => $reference,
                'idempotency_key'  => $this->idempotencyKey($fingerprint),
                'customer_name'    => $this->name,
                // Null rather than '' when not given: the affiliate
                // self-referral check and the CAPI user_data both read this,
                // and an empty string is a value they would each have to
                // special-case. Always present on the Paystack path, which
                // requires it.
                'customer_email'   => $this->email ?: null,
                'customer_phone'   => $this->phone,
                'shipping_address' => $this->lga . ', Akwa Ibom State — ' . $this->address,
                'local_government' => $this->lga,
                'total_amount'     => $this->total,
                'status'           => 'pending',
                'payment_method'   => $this->paymentMethod,
                // Asked on step 2 now, so it is known before the order exists
                // and no longer has to be patched on afterwards.
                'delivery_urgency'           => $this->deliveryUrgency,
                'preferred_delivery_date'    => $this->resolvedDeliveryDate()?->toDateString(),
                'delivery_preference_set_at' => now(),
                // Captured here (shared by both payment paths) so the server-side
                // Purchase CAPI event — fired later from OrderObserver, possibly
                // disconnected from this request — can still include them.
                'fbp'              => request()->cookie('_fbp'),
                'fbc'              => request()->cookie('_fbc'),
            ]);
        } catch (QueryException $e) {
            // The other request won the race and took the key. Nothing is wrong
            // — the customer's order exists, it just isn't this one.
            if ($existing = $this->recentOrderFor($fingerprint)) {
                $this->resumeExistingOrder($existing);
                return;
            }

            throw $e;
        }

        // Keyed by product so the reservation loop below can tell each
        // reservation which line it belongs to — the allocation rows hang off
        // the line, not the product.
        $orderItemsByProduct = [];

        foreach ($this->cartItems as $item) {
            $orderItemsByProduct[$item['product']->id] = OrderItem::create([
                'order_id'   => $order->id,
                'product_id' => $item['product']->id,
                'vendor_id'  => $item['product']->vendor_id,
                'quantity'   => $item['quantity'],
                'unit_price' => $item['product']->price,
                // Cost as of this moment — a later restock must not rewrite
                // what this sale earned.
                //
                // Null for a resold listing: what it costs is what the supplier
                // charges on the day the goods are actually delivered, and that
                // is frozen onto the line then. Writing today's figure here
                // would freeze a cost for units that may never be delivered.
                'unit_cost'  => $item['product']->isLinked() ? null : $item['product']->cost_price,
            ]);
        }

        // Affiliate attribution — a code entered here beats the cookie from an
        // earlier click. Self-referral and idempotency are both handled inside
        // the service; a failure here must never block the order itself.
        try {
            app(AttributionService::class)->attributeOrder(
                $order,
                trim($this->referralCode) ?: null,
                request()->cookie(AttributionService::COOKIE_NAME),
            );
        } catch (\Exception $e) {
            \Log::error('Affiliate attribution failed for order ' . $order->id . ': ' . $e->getMessage());
        }

        if ($this->paymentMethod === 'pay_on_delivery') {
            $reserveStock = app(ReserveStockAction::class);

            try {
                foreach ($this->cartItems as $item) {
                    // A resold listing holds no stock of its own — the goods sit
                    // in the supplier's shop and are read, never written. Trying
                    // to reserve against zero throws "insufficient stock" and the
                    // catch below deletes the whole order, so every order
                    // containing one would fail. Skipped deliberately: the race
                    // where he sells the last unit first is accepted, and a
                    // pay-on-delivery order that cannot be filled simply cancels
                    // with nothing paid.
                    if ($item['product']->isLinked()) {
                        continue;
                    }

                    $reserveStock->execute(
                        productId:   $item['product']->id,
                        quantity:    $item['quantity'],
                        reference:   $reference,
                        description: 'Reserved on pay-on-delivery order placed.',
                        orderItemId: $orderItemsByProduct[$item['product']->id]->id,
                    );
                }
            } catch (\Exception $e) {
                $order->delete();
                session()->flash('error', 'One or more items went out of stock. Please review your cart.');
                return;
            }

            $order->update(['status' => 'confirmed']);
            Session::forget('cart');
            $this->dispatch('cart-updated');
            session()->put('payment_success', $reference);
            $this->redirectRoute('checkout');
            return;
        }

        $this->startPaystack($reference);
    }

    // Extracted so a duplicate submission can be sent back to the same Paystack
    // transaction rather than opening a second one.
    private function startPaystack(string $reference): void
    {
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

    // ── Duplicate-submission guard ───────────────────────────────────────────

    /**
     * What makes two submissions "the same checkout": the same browser session,
     * the same customer number, the same money, and the same basket.
     *
     * Deliberately excludes the reference (random per attempt) and the time —
     * the window is applied separately, so the boundary between two windows
     * cannot split a pair of near-simultaneous taps.
     */
    private function checkoutFingerprint(): string
    {
        $basket = collect($this->cartItems)
            ->map(fn (array $item) => $item['product']->id . 'x' . (int) $item['quantity'])
            ->sort()
            ->implode('|');

        return hash('sha256', implode('~', [
            Session::getId(),
            (string) PhoneNumber::toNigerianInternational($this->phone),
            number_format($this->total, 2, '.', ''),
            $this->paymentMethod,
            $basket,
        ]));
    }

    /**
     * The stored key for a fingerprint in a given window.
     *
     * The window number is folded in so the same cart can legitimately be
     * ordered again later — without it, a unique index would block a repeat
     * customer forever.
     */
    private function idempotencyKey(string $fingerprint, int $windowsAgo = 0): string
    {
        // now() rather than time() so the window moves with Carbon's clock —
        // otherwise this and the created_at check below could disagree.
        $window = intdiv(now()->getTimestamp(), self::DUPLICATE_WINDOW_SECONDS) - $windowsAgo;

        return hash('sha256', $fingerprint . '~' . $window);
    }

    /**
     * The order an identical submission already created, if there is one.
     *
     * Checks the previous window as well as the current one: two taps a second
     * apart can still land either side of a window boundary, and that pair is
     * exactly what this exists to catch.
     */
    private function recentOrderFor(string $fingerprint): ?Order
    {
        return Order::query()
            ->whereIn('idempotency_key', [
                $this->idempotencyKey($fingerprint),
                $this->idempotencyKey($fingerprint, 1),
            ])
            ->where('created_at', '>=', now()->subSeconds(self::DUPLICATE_WINDOW_SECONDS))
            ->latest('id')
            ->first();
    }

    /**
     * Send a duplicate submission to the order that already exists instead of
     * creating a second one. No new items, no second stock reservation, no
     * second affiliate attribution.
     */
    private function resumeExistingOrder(Order $order): void
    {
        if ($order->payment_method === 'pay_on_delivery') {
            Session::forget('cart');
            $this->dispatch('cart-updated');
            session()->put('payment_success', $order->reference);
            $this->redirectRoute('checkout');

            return;
        }

        // Paystack treats a repeated reference as the same transaction, so this
        // returns the customer to the payment page they already had.
        $this->startPaystack($order->reference);
    }
}; ?>

<div>
{{-- Focused only while the order is still being placed. Once it is confirmed
the customer is done deciding, and the footer and nav become useful again —
that screen is where they carry on shopping, not somewhere to be held. --}}
<x-layouts.storefront :focused="! $paid">

<div class="bg-[#f8fcf8] dark:bg-[#0d1a0d] min-h-screen">
<div class="px-4 md:px-6 py-7 max-w-[1000px] mx-auto">

    {{-- ─── PAGE HEADER ────────────────────────────────────────────────────── --}}
    <div class="flex items-center gap-3 mb-7">
        <a href="{{ $paid ? route('home') : route('cart') }}"
           class="w-8 h-8 rounded-full bg-brand-bg dark:bg-[#1a2a1a] border border-brand-border dark:border-[#2a3a2a] flex items-center justify-center hover:border-brand transition-colors">
            <svg class="w-4 h-4 fill-none" style="stroke:#5a7a5c;stroke-width:2" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" d="M10 19l-7-7m0 0l7-7m-7 7h18"/>
            </svg>
        </a>
        <div>
            <h1 class="font-montserrat font-black text-[24px] md:text-[28px] text-brand-dark dark:text-[#e8f5e9]">
                {{ $paid ? 'Order Confirmed' : 'Checkout' }}
            </h1>
            <p class="text-[12px] text-brand-muted">
                {{ $paid ? 'Thank you for shopping with GadgetPlug' : 'Secure checkout · Pay online or on delivery' }}
            </p>
        </div>
    </div>

    {{-- ─── PROGRESS ───────────────────────────────────────────────────────── --}}
    {{-- Three real steps now, not three labels over one long form. A step
    already completed is tappable: going back is a normal thing to want, and
    nothing is lost by it — the fields are component state and stay put. --}}
    @php $wizardLabels = [1 => 'Payment', 2 => 'Delivery', 3 => 'Confirm']; @endphp

    <div class="mb-8">
        <div class="flex items-baseline justify-between gap-3 mb-2.5">
            <span class="font-montserrat font-bold text-[12px] {{ $paid ? 'text-brand' : 'text-brand-orange' }}">
                {{ $paid ? 'All done' : 'Step ' . $step . ' of 3' }}
            </span>
            <span class="text-[11px] text-brand-muted">
                {{ $paid ? 'Order confirmed' : $wizardLabels[$step] }}
            </span>
        </div>

        <div class="flex items-center gap-0">
            @foreach($wizardLabels as $n => $lbl)
            @php
                $state = $paid ? 'done' : ($n < $step ? 'done' : ($n === $step ? 'active' : 'pending'));
            @endphp
            <div class="flex items-center {{ !$loop->last ? 'flex-1' : '' }}">
                <button type="button"
                    @if ($state === 'done' && ! $paid)
                        wire:click="goToStep({{ $n }})"
                        aria-label="Back to {{ $lbl }}"
                    @else
                        disabled
                    @endif
                    class="flex items-center gap-2 {{ $state === 'done' && ! $paid ? 'cursor-pointer' : 'cursor-default' }}">
                    <span class="w-7 h-7 rounded-full flex items-center justify-center font-montserrat font-bold text-[11px] flex-shrink-0 transition-colors
                        {{ $state === 'done' ? 'bg-brand text-white' : ($state === 'active' ? 'bg-brand-orange text-white' : 'bg-brand-bg dark:bg-[#1a2a1a] border-2 border-[#d0d9d2] dark:border-[#2a3a2a] text-brand-muted') }}">
                        @if ($state === 'done')
                        <svg class="w-3.5 h-3.5 fill-none" style="stroke:#fff;stroke-width:2.5" viewBox="0 0 24 24">
                            <polyline points="20 6 9 17 4 12"/>
                        </svg>
                        @else
                        {{ $n }}
                        @endif
                    </span>
                    <span class="text-[12px] font-medium {{ $state === 'active' ? 'text-brand-orange font-semibold' : ($state === 'done' ? 'text-brand' : 'text-brand-muted') }}">
                        {{ $lbl }}
                    </span>
                </button>
                @if (!$loop->last)
                <div class="flex-1 h-px mx-3 transition-colors duration-500 {{ $n < $step || $paid ? 'bg-brand' : 'bg-[#e0e8e1] dark:bg-[#2a3a2a]' }}"></div>
                @endif
            </div>
            @endforeach
        </div>
    </div>

    {{-- ════════════════════════════════════════════════════════════════════ --}}
    @if ($paid)
    {{-- ─── SUCCESS STATE ───────────────────────────────────────────────── --}}
    @if (config('services.meta.pixel_id') && $pixelEventId)
    <script>
    fbq('track', 'Purchase', {
        value: {{ $paidTotal }},
        currency: 'NGN',
        content_ids: @json($paidProductIds),
        content_type: 'product'
    }, {eventID: '{{ $pixelEventId }}'});
    </script>
    @endif
    <div class="flex flex-col items-center text-center">

        {{-- Animated checkmark circle --}}
        <div class="w-24 h-24 rounded-full bg-brand flex items-center justify-center mb-5 shadow-[0_8px_40px_rgba(6,139,3,0.35)]"
             style="animation: scaleIn .4s cubic-bezier(.175,.885,.32,1.275) both">
            <svg class="w-12 h-12 fill-none" style="stroke:#fff;stroke-width:2.5;stroke-linecap:round;stroke-linejoin:round" viewBox="0 0 24 24">
                <polyline points="20 6 9 17 4 12"/>
            </svg>
        </div>
        <style>
            @keyframes scaleIn { from { transform: scale(0); opacity: 0 } to { transform: scale(1); opacity: 1 } }
        </style>

        <h2 class="font-montserrat font-black text-[26px] md:text-[30px] text-brand-dark dark:text-[#e8f5e9] mb-1">
            {{ $paidMethod === 'pay_on_delivery' ? 'Order Placed!' : 'Payment Successful!' }}
        </h2>
        <p class="text-[14px] text-brand-muted mb-3">
            {{ $paidMethod === 'pay_on_delivery' ? 'Your order is confirmed. Our rider will be with you soon.' : 'Your payment was processed and your order is on its way.' }}
        </p>

        <div class="inline-flex items-center gap-2 bg-[#e8f5e9] dark:bg-[#1a2a1a] border border-[#c0e8c0] dark:border-[#2a3a2a] rounded-full px-4 py-2 mb-8">
            <span class="text-[11px] text-brand-muted font-medium">Order Reference:</span>
            <span class="font-montserrat font-black text-[12px] text-brand tracking-wide">{{ $paidReference }}</span>
        </div>

        <div class="w-full max-w-[620px] space-y-4 text-left">

            {{-- When do you want it? Asked only after payment, so it never sits
                 between the customer and the checkout button. One tap records. --}}
            @php
                $minDate = now()->format('Y-m-d');
                $maxDate = now()->addDays(\App\Models\Order::MAX_SCHEDULE_DAYS)->format('Y-m-d');
                $chosen  = $deliveryUrgency !== '';
            @endphp

            <div class="bg-white dark:bg-[#1a2a1a] rounded-2xl border border-brand-border dark:border-[#2a3a2a] overflow-hidden"
                 wire:key="delivery-preference">
                <div class="px-5 py-4 border-b border-brand-border dark:border-[#2a3a2a] bg-gradient-to-br from-[#f0f8f0] to-[#e8f5e9] dark:from-[#1a2a1a] dark:to-[#162016] flex items-center justify-between gap-3">
                    <h3 class="font-montserrat font-bold text-[14px] text-brand-dark dark:text-[#e8f5e9]">
                        When do you want it?
                    </h3>
                    @if($chosen)
                    <span class="inline-flex items-center gap-1.5 text-[11px] font-semibold text-brand shrink-0">
                        <svg class="w-3.5 h-3.5 fill-none" style="stroke:currentColor;stroke-width:3;stroke-linecap:round;stroke-linejoin:round" viewBox="0 0 24 24"><polyline points="20 6 9 17 4 12"/></svg>
                        Saved
                    </span>
                    @endif
                </div>

                <div class="p-5">
                    <p class="text-[12px] text-brand-muted mb-3">
                        {{ $chosen
                            ? 'We have noted this. Tap another option any time to change it.'
                            : 'Tap one so we know how urgently to get it to you. Optional.' }}
                    </p>

                    <div class="grid grid-cols-3 gap-2">
                        @foreach([
                            ['key' => \App\Models\Order::URGENCY_TODAY,    'label' => 'Today',      'sub' => now()->format('D, j M')],
                            ['key' => \App\Models\Order::URGENCY_TOMORROW, 'label' => 'Tomorrow',   'sub' => now()->addDay()->format('D, j M')],
                            ['key' => \App\Models\Order::URGENCY_SCHEDULED,'label' => 'Pick a date','sub' => 'Choose'],
                        ] as $option)
                            @php $active = $deliveryUrgency === $option['key']; @endphp
                            <button type="button"
                                wire:key="urgency-{{ $option['key'] }}"
                                @if($option['key'] === \App\Models\Order::URGENCY_SCHEDULED)
                                    wire:click="$set('showDatePicker', true)"
                                @else
                                    wire:click="chooseDelivery('{{ $option['key'] }}')"
                                @endif
                                wire:loading.attr="disabled"
                                aria-pressed="{{ $active ? 'true' : 'false' }}"
                                class="rounded-xl border px-2 py-3 text-center transition-colors focus:outline-none focus:ring-2 focus:ring-brand focus:ring-offset-1
                                    {{ $active
                                        ? 'border-brand bg-[#e8f5e9] dark:bg-[#1f3a1f] ring-1 ring-brand'
                                        : 'border-brand-border dark:border-[#2a3a2a] bg-white dark:bg-[#162016] hover:border-brand' }}">
                                <span class="block font-montserrat font-bold text-[13px] {{ $active ? 'text-brand' : 'text-brand-dark dark:text-[#e8f5e9]' }}">
                                    {{ $option['label'] }}
                                </span>
                                <span class="block text-[10px] mt-0.5 {{ $active ? 'text-brand' : 'text-brand-muted' }}">
                                    {{ $option['key'] === \App\Models\Order::URGENCY_SCHEDULED && $active && $deliveryDate
                                        ? \Illuminate\Support\Carbon::parse($deliveryDate)->format('D, j M')
                                        : $option['sub'] }}
                                </span>
                            </button>
                        @endforeach
                    </div>

                    @if($showDatePicker || ($deliveryUrgency === \App\Models\Order::URGENCY_SCHEDULED && $errors->has('deliveryDate')))
                    <div class="mt-3 flex flex-col sm:flex-row gap-2">
                        <input type="date"
                            wire:model="deliveryDate"
                            min="{{ $minDate }}"
                            max="{{ $maxDate }}"
                            aria-label="Preferred delivery date"
                            class="flex-1 rounded-xl border border-brand-border dark:border-[#2a3a2a] bg-white dark:bg-[#162016] text-brand-dark dark:text-[#e8f5e9] px-3 py-2.5 text-[13px] focus:outline-none focus:border-brand">
                        <button type="button"
                            wire:click="chooseDelivery('{{ \App\Models\Order::URGENCY_SCHEDULED }}')"
                            wire:loading.attr="disabled"
                            class="rounded-xl bg-brand hover:opacity-90 disabled:opacity-60 text-white font-montserrat font-bold text-[13px] px-5 py-2.5 transition-opacity">
                            Confirm date
                        </button>
                    </div>
                    @error('deliveryDate')
                        <p class="mt-2 text-[11px] text-red-600 dark:text-red-400">{{ $message }}</p>
                    @enderror
                    @endif
                </div>
            </div>

            {{-- What happens next timeline --}}
            <div class="bg-white dark:bg-[#1a2a1a] rounded-2xl border border-brand-border dark:border-[#2a3a2a] overflow-hidden">
                <div class="px-5 py-4 border-b border-brand-border dark:border-[#2a3a2a] bg-gradient-to-br from-[#f0f8f0] to-[#e8f5e9] dark:from-[#1a2a1a] dark:to-[#162016]">
                    <h3 class="font-montserrat font-bold text-[14px] text-brand-dark dark:text-[#e8f5e9]">What Happens Next?</h3>
                </div>
                <div class="p-5">
                    @php
                    $timeline = [
                        [
                            'title' => 'Order Confirmed',
                            'desc'  => 'Your order ' . $paidReference . ' is confirmed and being prepared for dispatch.',
                            'done'  => true,
                        ],
                        [
                            'title' => 'Rider Dispatched',
                            'desc'  => 'Our rider will pick up your item and head to your address within 2 hours.',
                            'done'  => false,
                        ],
                        [
                            'title' => 'Inspect Your Item',
                            'desc'  => 'When the rider arrives, take your time to inspect the item before accepting.',
                            'done'  => false,
                        ],
                        [
                            'title' => $paidMethod === 'pay_on_delivery' ? 'Pay Cash to Rider' : 'Enjoy Your Gadget',
                            'desc'  => $paidMethod === 'pay_on_delivery'
                                        ? 'Pay the exact amount in cash to the rider once you are satisfied with the item.'
                                        : 'Your payment is complete. Welcome to the GadgetPlug family!',
                            'done'  => false,
                        ],
                    ];
                    @endphp
                    <div class="space-y-0">
                        @foreach ($timeline as $i => $step)
                        <div class="flex gap-4 {{ !$loop->last ? 'pb-4' : '' }}">
                            {{-- Icon + connector line --}}
                            <div class="flex flex-col items-center flex-shrink-0">
                                <div class="w-8 h-8 rounded-full flex items-center justify-center
                                    {{ $step['done'] ? 'bg-brand shadow-[0_2px_10px_rgba(6,139,3,0.3)]' : 'bg-brand-bg dark:bg-[#0d1a0d] border-2 border-[#c0d4c2] dark:border-[#2a3a2a]' }}">
                                    @if ($step['done'])
                                    <svg class="w-4 h-4 fill-none" style="stroke:#fff;stroke-width:2.5" viewBox="0 0 24 24">
                                        <polyline points="20 6 9 17 4 12"/>
                                    </svg>
                                    @else
                                    <span class="text-[11px] font-bold font-montserrat text-brand-muted">{{ $i + 1 }}</span>
                                    @endif
                                </div>
                                @if (!$loop->last)
                                <div class="w-px flex-1 mt-1 {{ $step['done'] ? 'bg-brand' : 'bg-[#d0dcd2] dark:bg-[#2a3a2a]' }}"></div>
                                @endif
                            </div>
                            {{-- Text --}}
                            <div class="pt-0.5 {{ !$loop->last ? 'pb-4' : '' }}">
                                <div class="font-semibold text-[13px] text-brand-dark dark:text-[#e8f5e9]">{{ $step['title'] }}</div>
                                <div class="text-[11px] text-brand-muted mt-0.5 leading-relaxed">{{ $step['desc'] }}</div>
                            </div>
                        </div>
                        @endforeach
                    </div>
                </div>
            </div>

            {{-- Items in this order --}}
            @if (count($paidItems))
            <div class="bg-white dark:bg-[#1a2a1a] rounded-2xl border border-brand-border dark:border-[#2a3a2a] overflow-hidden">
                <div class="px-5 py-4 border-b border-brand-border dark:border-[#2a3a2a] bg-gradient-to-br from-[#f0f8f0] to-[#e8f5e9] dark:from-[#1a2a1a] dark:to-[#162016]">
                    <h3 class="font-montserrat font-bold text-[14px] text-brand-dark dark:text-[#e8f5e9]">Items in Your Order</h3>
                </div>
                <div class="p-5 space-y-3">
                    @foreach ($paidItems as $item)
                    <div class="flex gap-3 items-center">
                        <div class="w-11 h-11 rounded-lg bg-brand-bg dark:bg-[#0d1a0d] border border-brand-border dark:border-[#2a3a2a] flex items-center justify-center flex-shrink-0 overflow-hidden">
                            @if ($item['thumb'])
                            <img src="{{ $item['thumb'] }}" alt="{{ $item['name'] }}" class="w-full h-full object-cover">
                            @else
                            <x-gp-icon name="package" class="w-5 h-5 text-[#8a9e8c]" />
                            @endif
                        </div>
                        <div class="flex-1 min-w-0">
                            <div class="text-[12px] font-medium text-[#111] dark:text-[#e8f5e9] line-clamp-1">{{ $item['name'] }}</div>
                            <div class="text-[10px] text-brand-muted">Qty: {{ $item['quantity'] }}</div>
                        </div>
                        <div class="font-montserrat font-bold text-[13px] text-brand flex-shrink-0">
                            ₦{{ number_format($item['subtotal']) }}
                        </div>
                    </div>
                    @endforeach

                    <div class="border-t border-brand-border dark:border-[#2a3a2a] pt-3 flex justify-between items-center">
                        <span class="font-montserrat font-bold text-[14px] text-brand-dark dark:text-[#e8f5e9]">
                            {{ $paidMethod === 'pay_on_delivery' ? 'Total (Pay on Delivery)' : 'Total Paid' }}
                        </span>
                        <span class="font-montserrat font-black text-[20px] text-brand">₦{{ number_format($paidTotal) }}</span>
                    </div>
                </div>
            </div>
            @endif

            {{-- CTA buttons --}}
            <div class="flex flex-col sm:flex-row gap-3 pb-6">
                <a href="{{ route('home') }}"
                   class="flex-1 flex items-center justify-center gap-2 bg-brand hover:bg-[#055002] text-white font-montserrat font-bold text-[14px] py-3.5 rounded-xl transition-all hover:-translate-y-px shadow-md">
                    <svg class="w-4 h-4 fill-none" style="stroke:currentColor;stroke-width:2" viewBox="0 0 24 24">
                        <path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/>
                        <polyline points="9 22 9 12 15 12 15 22"/>
                    </svg>
                    Continue Shopping
                </a>
                @auth
                <a href="{{ route('account.orders') }}"
                   class="flex-1 flex items-center justify-center gap-2 bg-white dark:bg-[#1a2a1a] border-2 border-brand text-brand font-montserrat font-bold text-[14px] py-3.5 rounded-xl transition-all hover:bg-brand hover:text-white">
                    <svg class="w-4 h-4 fill-none" style="stroke:currentColor;stroke-width:2" viewBox="0 0 24 24">
                        <path d="M6 2L3 6v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V6l-3-4z"/>
                        <line x1="3" y1="6" x2="21" y2="6"/>
                        <path d="M16 10a4 4 0 0 1-8 0"/>
                    </svg>
                    View My Orders
                </a>
                @endauth
            </div>

        </div>
    </div>

    @else
    {{-- ─── THE WIZARD ──────────────────────────────────────────────────── --}}
    {{-- All three steps are rendered into the page at once and toggled by
    Alpine, so moving between them is a CSS transition rather than a request,
    and step 3's summary is already built while the customer is still typing
    their address. The step itself lives on the server ($step) because that is
    what validation gates — Alpine only decides which of the three is visible. --}}

    @if (config('services.meta.pixel_id') && $pixelEventId)
    {{-- Only rendered for a checkout that started here. Arriving from a
    product page's Buy Now means InitiateCheckout already fired there, and
    firing it again would count one shopper twice. --}}
    <script>
    fbq('track', 'InitiateCheckout', {
        value: {{ $total }},
        currency: 'NGN',
        content_ids: @json(collect($cartItems)->pluck('product.id')->values()->all()),
        content_type: 'product'
    }, {eventID: '{{ $pixelEventId }}'});
    </script>
    @endif

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

    {{-- A hairline, not a full-screen spinner. Step changes are quick and the
    content behind stays readable while one happens. --}}
    <div wire:loading.delay wire:target="choosePayment,goToConfirm,goToStep,processCheckout"
         class="fixed top-0 left-0 right-0 h-[3px] bg-brand-orange z-[300] animate-pulse"></div>

    <div x-data class="pb-28 md:pb-0">

    {{-- ══ STEP 1 · PAYMENT ═══════════════════════════════════════════════ --}}
    <div x-show="$wire.step === 1"
         x-cloak
         x-transition:enter="transition ease-out duration-250"
         x-transition:enter-start="opacity-0 translate-x-4"
         x-transition:enter-end="opacity-100 translate-x-0"
         class="rounded-2xl overflow-hidden border border-brand-border dark:border-[#2a3a2a] min-h-[460px] flex">
        <x-checkout.payment-choice action="choosePayment" :total="$total" />
    </div>

    {{-- ══ STEP 2 · DELIVERY ══════════════════════════════════════════════ --}}
    <div x-show="$wire.step === 2"
         x-cloak
         x-transition:enter="transition ease-out duration-250"
         x-transition:enter-start="opacity-0 translate-x-4"
         x-transition:enter-end="opacity-100 translate-x-0">

        <form wire:submit="goToConfirm">
        <div class="bg-white dark:bg-[#1a2a1a] rounded-2xl border border-brand-border dark:border-[#2a3a2a] overflow-hidden">
            <div class="px-5 py-5 border-b border-brand-border dark:border-[#2a3a2a] bg-gradient-to-br from-[#f0f8f0] to-[#e8f5e9] dark:from-[#1a2a1a] dark:to-[#162016]">
                <h2 class="font-montserrat font-black text-[19px] md:text-[22px] text-brand-dark dark:text-[#e8f5e9]">
                    Where should we deliver to?
                </h2>
                <p class="text-[12px] text-brand-muted mt-1">
                    Takes about a minute. Your rider uses exactly what you put here.
                </p>
            </div>

            <div class="p-5 space-y-4">

                {{-- Name --}}
                <div>
                    <label for="f-name" class="block text-[12px] font-semibold text-brand-dark dark:text-[#e8f5e9] mb-1.5">Your name</label>
                    <input id="f-name" type="text" wire:model.blur="name" placeholder="e.g. Aniekan Udo" autocomplete="name"
                        class="w-full min-h-[48px] bg-brand-bg dark:bg-[#0d1a0d] border rounded-xl px-3.5 py-2.5 text-[14px] text-[#111] dark:text-[#e8f5e9] outline-none transition-colors placeholder-[#8a9e8c]
                            {{ $errors->has('name') ? 'border-red-400' : 'border-[#d0d9d2] dark:border-[#2a3a2a] focus:border-brand' }}">
                    @error('name') <p class="text-red-500 text-[11px] mt-1">{{ $message }}</p> @enderror
                </div>

                {{-- WhatsApp — the required contact. Nothing else reaches the
                     customer after they close the tab. --}}
                <div>
                    <label for="f-phone" class="block text-[12px] font-semibold text-brand-dark dark:text-[#e8f5e9] mb-1.5">WhatsApp number</label>
                    <input id="f-phone" type="tel" inputmode="tel" wire:model.blur="phone" placeholder="08012345678" autocomplete="tel"
                        class="w-full min-h-[48px] bg-brand-bg dark:bg-[#0d1a0d] border rounded-xl px-3.5 py-2.5 text-[14px] text-[#111] dark:text-[#e8f5e9] outline-none transition-colors placeholder-[#8a9e8c]
                            {{ $errors->has('phone') ? 'border-red-400' : 'border-[#d0d9d2] dark:border-[#2a3a2a] focus:border-brand' }}">
                    <p class="flex items-center gap-1.5 text-[11px] text-brand-muted dark:text-[#6a8a6a] mt-1.5">
                        <svg class="w-3.5 h-3.5 shrink-0" fill="#25D366" viewBox="0 0 24 24">
                            <path d="M12.04 2c-5.46 0-9.91 4.45-9.91 9.91 0 1.75.46 3.45 1.32 4.95L2.05 22l5.25-1.38a9.87 9.87 0 004.74 1.21h.01c5.46 0 9.91-4.45 9.91-9.91 0-2.65-1.03-5.14-2.9-7.01A9.82 9.82 0 0012.04 2zm5.8 14.13c-.25.69-1.46 1.32-2 1.36-.51.04-1.16.06-1.87-.12-.43-.11-.98-.29-1.69-.6-2.97-1.28-4.91-4.27-5.06-4.47-.15-.2-1.21-1.61-1.21-3.07 0-1.46.77-2.18 1.04-2.48.27-.3.59-.37.79-.37.2 0 .39 0 .57.01.18.01.43-.07.67.51.25.6.84 2.06.91 2.21.07.15.12.32.02.52-.1.2-.15.32-.3.5-.15.17-.31.39-.44.52-.15.15-.3.31-.13.61.17.3.76 1.25 1.63 2.03 1.12 1 2.06 1.31 2.36 1.46.3.15.47.12.65-.07.17-.2.75-.87.95-1.17.2-.3.4-.25.67-.15.27.1 1.72.81 2.01.96.3.15.5.22.57.35.07.12.07.72-.18 1.41z"/>
                        </svg>
                        We send your order updates here.
                    </p>
                    @error('phone') <p class="text-red-500 text-[11px] mt-1">{{ $message }}</p> @enderror
                </div>

                {{-- Address --}}
                <div>
                    <label for="f-address" class="block text-[12px] font-semibold text-brand-dark dark:text-[#e8f5e9] mb-1.5">Delivery address</label>
                    <textarea id="f-address" wire:model.blur="address" rows="3" autocomplete="street-address"
                        placeholder="House number, street name, and the closest landmark…"
                        class="w-full bg-brand-bg dark:bg-[#0d1a0d] border rounded-xl px-3.5 py-2.5 text-[14px] text-[#111] dark:text-[#e8f5e9] outline-none transition-colors placeholder-[#8a9e8c] resize-none
                            {{ $errors->has('address') ? 'border-red-400' : 'border-[#d0d9d2] dark:border-[#2a3a2a] focus:border-brand' }}"></textarea>
                    @error('address') <p class="text-red-500 text-[11px] mt-1">{{ $message }}</p> @enderror
                </div>

                {{-- LGA. Required by the order itself — shipping_address is
                     composed from it — so it stays on this step. --}}
                <div>
                    <label for="f-lga" class="block text-[12px] font-semibold text-brand-dark dark:text-[#e8f5e9] mb-1.5">Area</label>
                    <select id="f-lga" wire:model.live="lga"
                        class="w-full min-h-[48px] bg-brand-bg dark:bg-[#0d1a0d] border rounded-xl px-3.5 py-2.5 text-[14px] text-[#111] dark:text-[#e8f5e9] outline-none transition-colors appearance-none cursor-pointer
                            {{ $errors->has('lga') ? 'border-red-400' : 'border-[#d0d9d2] dark:border-[#2a3a2a] focus:border-brand' }}">
                        <option value="">Select your area…</option>
                        <option value="Uyo">Uyo</option>
                        <option value="Mkpat Enin">Mkpat Enin</option>
                        <option value="Eket">Eket</option>
                    </select>
                    <p class="text-[11px] text-brand-muted mt-1">Akwa Ibom State — we deliver to these three areas today.</p>
                    @error('lga') <p class="text-red-500 text-[11px] mt-1">{{ $message }}</p> @enderror
                </div>

                {{-- When do you want it --}}
                @php
                    $minDate = now()->format('Y-m-d');
                    $maxDate = now()->addDays(\App\Models\Order::MAX_SCHEDULE_DAYS)->format('Y-m-d');
                @endphp
                <div>
                    <span class="block text-[12px] font-semibold text-brand-dark dark:text-[#e8f5e9] mb-1.5">When do you want it?</span>
                    <div class="grid grid-cols-3 gap-2" role="group" aria-label="Preferred delivery time">
                        @foreach([
                            ['key' => \App\Models\Order::URGENCY_TODAY,    'label' => 'Today',       'sub' => now()->format('D, j M')],
                            ['key' => \App\Models\Order::URGENCY_TOMORROW, 'label' => 'Tomorrow',    'sub' => now()->addDay()->format('D, j M')],
                            ['key' => \App\Models\Order::URGENCY_SCHEDULED,'label' => 'Pick a date', 'sub' => 'Choose'],
                        ] as $option)
                            @php $active = $deliveryUrgency === $option['key']; @endphp
                            <button type="button"
                                wire:key="step2-urgency-{{ $option['key'] }}"
                                wire:click="setUrgency('{{ $option['key'] }}')"
                                aria-pressed="{{ $active ? 'true' : 'false' }}"
                                class="rounded-xl border px-2 py-3 min-h-[60px] text-center transition-colors focus:outline-none focus:ring-2 focus:ring-brand focus:ring-offset-1
                                    {{ $active
                                        ? 'border-brand bg-[#e8f5e9] dark:bg-[#1f3a1f] ring-1 ring-brand'
                                        : ($errors->has('deliveryUrgency') ? 'border-red-400 bg-white dark:bg-[#162016]' : 'border-brand-border dark:border-[#2a3a2a] bg-white dark:bg-[#162016] hover:border-brand') }}">
                                <span class="block font-montserrat font-bold text-[13px] {{ $active ? 'text-brand' : 'text-brand-dark dark:text-[#e8f5e9]' }}">
                                    {{ $option['label'] }}
                                </span>
                                <span class="block text-[10px] mt-0.5 {{ $active ? 'text-brand' : 'text-brand-muted' }}">
                                    {{ $option['key'] === \App\Models\Order::URGENCY_SCHEDULED && $active && $deliveryDate
                                        ? \Illuminate\Support\Carbon::parse($deliveryDate)->format('D, j M')
                                        : $option['sub'] }}
                                </span>
                            </button>
                        @endforeach
                    </div>

                    @if ($showDatePicker || $deliveryUrgency === \App\Models\Order::URGENCY_SCHEDULED)
                    <input type="date"
                        wire:model.blur="deliveryDate"
                        min="{{ $minDate }}"
                        max="{{ $maxDate }}"
                        aria-label="Preferred delivery date"
                        class="mt-2 w-full min-h-[48px] rounded-xl border bg-white dark:bg-[#162016] text-brand-dark dark:text-[#e8f5e9] px-3.5 py-2.5 text-[14px] focus:outline-none
                            {{ $errors->has('deliveryDate') ? 'border-red-400' : 'border-brand-border dark:border-[#2a3a2a] focus:border-brand' }}">
                    @endif

                    @error('deliveryUrgency') <p class="text-red-500 text-[11px] mt-1">{{ $message }}</p> @enderror
                    @error('deliveryDate') <p class="text-red-500 text-[11px] mt-1">{{ $message }}</p> @enderror
                </div>

                {{-- Email. Required only to pay online, because Paystack will
                     not open a transaction without one. On the cash path it is
                     captured if offered and never insisted on. --}}
                @php $emailRequired = $paymentMethod === 'paystack'; @endphp
                <div>
                    <label for="f-email" class="block text-[12px] font-semibold text-brand-dark dark:text-[#e8f5e9] mb-1.5">
                        Email
                        @if (! $emailRequired)
                            <span class="font-normal text-brand-muted">(optional)</span>
                        @endif
                    </label>
                    <input id="f-email" type="email" inputmode="email" wire:model.blur="email" autocomplete="email"
                        placeholder="{{ $emailRequired ? 'you@example.com' : 'Only if you want a copy by email' }}"
                        class="w-full min-h-[48px] bg-brand-bg dark:bg-[#0d1a0d] border rounded-xl px-3.5 py-2.5 text-[14px] text-[#111] dark:text-[#e8f5e9] outline-none transition-colors placeholder-[#8a9e8c]
                            {{ $errors->has('email') ? 'border-red-400' : 'border-[#d0d9d2] dark:border-[#2a3a2a] focus:border-brand' }}">
                    <p class="text-[11px] text-brand-muted mt-1">
                        {{ $emailRequired
                            ? 'Paystack sends your payment receipt here.'
                            : 'Not needed — your WhatsApp number is how we reach you.' }}
                    </p>
                    @error('email') <p class="text-red-500 text-[11px] mt-1">{{ $message }}</p> @enderror
                </div>

                {{-- Referral code --}}
                <div>
                    <label for="f-ref" class="block text-[12px] font-semibold text-brand-dark dark:text-[#e8f5e9] mb-1.5">
                        Referral code <span class="font-normal text-brand-muted">(optional)</span>
                    </label>
                    <input id="f-ref" type="text" wire:model.blur="referralCode" placeholder="e.g. ABC12345"
                        class="w-full min-h-[48px] bg-brand-bg dark:bg-[#0d1a0d] border border-[#d0d9d2] dark:border-[#2a3a2a] rounded-xl px-3.5 py-2.5 text-[14px] text-[#111] dark:text-[#e8f5e9] outline-none focus:border-brand transition-colors placeholder-[#8a9e8c]">
                </div>
            </div>
        </div>

        {{-- Sticky on mobile, inline on desktop. Sits above the bottom nav. --}}
        <div class="fixed left-0 right-0 bottom-0 md:static bg-white dark:bg-[#1a2a1a] md:bg-transparent md:dark:bg-transparent border-t md:border-t-0 border-brand-border dark:border-[#2a3a2a] px-4 md:px-0 py-3 md:py-0 md:mt-5 z-50"
             style="padding-bottom: calc(0.75rem + env(safe-area-inset-bottom, 0px));">
            <button type="submit"
                wire:loading.attr="disabled"
                wire:target="goToConfirm"
                class="w-full flex items-center justify-center gap-2 min-h-[52px] bg-brand-orange hover:bg-[#e06610] disabled:opacity-60 text-white font-montserrat font-bold text-[15px] rounded-xl transition-all shadow-lg">
                <span wire:loading.remove wire:target="goToConfirm">Continue</span>
                <span wire:loading wire:target="goToConfirm">Checking…</span>
                <svg class="w-4 h-4 fill-none" style="stroke:currentColor;stroke-width:2.5" viewBox="0 0 24 24"
                     wire:loading.remove wire:target="goToConfirm">
                    <path d="M5 12h14M12 5l7 7-7 7"/>
                </svg>
            </button>
        </div>
        </form>
    </div>

    {{-- ══ STEP 3 · CONFIRM ═══════════════════════════════════════════════ --}}
    <div x-show="$wire.step === 3"
         x-cloak
         x-transition:enter="transition ease-out duration-250"
         x-transition:enter-start="opacity-0 translate-x-4"
         x-transition:enter-end="opacity-100 translate-x-0">

        <form wire:submit="processCheckout">
        <div class="space-y-4">

            {{-- Items --}}
            <div class="bg-white dark:bg-[#1a2a1a] rounded-2xl border border-brand-border dark:border-[#2a3a2a] overflow-hidden">
                <div class="px-5 py-4 border-b border-brand-border dark:border-[#2a3a2a] bg-gradient-to-br from-[#f0f8f0] to-[#e8f5e9] dark:from-[#1a2a1a] dark:to-[#162016]">
                    <h2 class="font-montserrat font-bold text-[14px] text-brand-dark dark:text-[#e8f5e9]">Your order</h2>
                </div>
                <div class="p-5 space-y-3">
                    @foreach ($cartItems as $item)
                    <div class="flex gap-2.5 items-center">
                        <div class="w-11 h-11 rounded-lg bg-brand-bg dark:bg-[#0d1a0d] border border-brand-border dark:border-[#2a3a2a] flex items-center justify-center flex-shrink-0 overflow-hidden">
                            @if ($item['thumb'])
                                <img src="{{ $item['thumb'] }}" alt="{{ $item['product']->name }}" loading="lazy" decoding="async" class="w-full h-full object-cover">
                            @else
                                <x-gp-icon name="package" class="w-5 h-5 text-[#8a9e8c]" />
                            @endif
                        </div>
                        <div class="flex-1 min-w-0">
                            <div class="text-[12px] font-medium text-[#111] dark:text-[#e8f5e9] line-clamp-1">{{ $item['product']->name }}</div>
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
                            <span class="text-brand font-semibold">Confirmed at delivery</span>
                        </div>
                    </div>

                    <div class="border-t-2 border-brand-border dark:border-[#2a3a2a] pt-3 flex justify-between items-center">
                        <span class="font-montserrat font-bold text-[14px] text-brand-dark dark:text-[#e8f5e9]">Total</span>
                        <span class="font-montserrat font-black text-[22px] text-brand">₦{{ number_format($total) }}</span>
                    </div>
                </div>
            </div>

            {{-- Paying with --}}
            <div class="bg-white dark:bg-[#1a2a1a] rounded-2xl border border-brand-border dark:border-[#2a3a2a] p-5">
                <div class="flex items-start justify-between gap-3">
                    <div class="min-w-0">
                        <p class="text-[11px] text-brand-muted mb-1">Paying with</p>
                        <p class="font-montserrat font-bold text-[14px] text-brand-dark dark:text-[#e8f5e9]">
                            {{ $paymentMethod === 'pay_on_delivery' ? 'Pay on Delivery' : 'Paystack' }}
                        </p>
                        <p class="text-[11px] text-brand-muted mt-0.5">
                            {{ $paymentMethod === 'pay_on_delivery'
                                ? 'Cash to the rider, after you have inspected it.'
                                : 'Card, transfer or USSD on Paystack\'s secure page.' }}
                        </p>
                    </div>
                    <button type="button" wire:click="goToStep(1)"
                        class="shrink-0 text-[12px] font-semibold text-brand hover:underline min-h-[44px] px-1">
                        Change
                    </button>
                </div>
            </div>

            {{-- Delivering to --}}
            <div class="bg-white dark:bg-[#1a2a1a] rounded-2xl border border-brand-border dark:border-[#2a3a2a] p-5">
                <div class="flex items-start justify-between gap-3">
                    <div class="min-w-0">
                        <p class="text-[11px] text-brand-muted mb-1">Delivering to</p>
                        <p class="font-montserrat font-bold text-[14px] text-brand-dark dark:text-[#e8f5e9]">{{ $name }}</p>
                        <p class="text-[12px] text-[#444] dark:text-[#b0c8b0] mt-0.5 break-words">{{ $address }}</p>
                        <p class="text-[12px] text-[#444] dark:text-[#b0c8b0]">{{ $lga }}, Akwa Ibom State</p>
                        <p class="text-[12px] text-brand-muted mt-1.5">{{ $phone }}</p>
                        @if ($email)
                        <p class="text-[12px] text-brand-muted">{{ $email }}</p>
                        @endif
                        @if ($deliveryUrgency)
                        <p class="mt-2 inline-flex items-center gap-1.5 rounded-full bg-[#e8f5e9] dark:bg-[#1f3a1f] px-2.5 py-1 text-[11px] font-semibold text-brand">
                            <svg class="w-3 h-3 fill-none" style="stroke:currentColor;stroke-width:2.5" viewBox="0 0 24 24">
                                <circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/>
                            </svg>
                            @php
                                // Order::deliveryPreferenceLabel() is an instance
                                // method and there is no order yet — this is still
                                // only what the customer tapped.
                                $urgencyLabels = [
                                    \App\Models\Order::URGENCY_TODAY     => 'Today',
                                    \App\Models\Order::URGENCY_TOMORROW  => 'Tomorrow',
                                    \App\Models\Order::URGENCY_SCHEDULED => 'Scheduled',
                                ];
                            @endphp
                            {{ $urgencyLabels[$deliveryUrgency] ?? ucfirst($deliveryUrgency) }}
                            @if ($deliveryUrgency === \App\Models\Order::URGENCY_SCHEDULED && $deliveryDate)
                                · {{ \Illuminate\Support\Carbon::parse($deliveryDate)->format('D, j M') }}
                            @endif
                        </p>
                        @endif
                    </div>
                    <button type="button" wire:click="goToStep(2)"
                        class="shrink-0 text-[12px] font-semibold text-brand hover:underline min-h-[44px] px-1">
                        Change
                    </button>
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
        </div>

        {{-- Place order. Disabled the moment it is pressed: without this a
             customer on a slow connection taps again, and the duplicate guard
             behind it has to do work the interface could have prevented. --}}
        <div class="fixed left-0 right-0 bottom-0 md:static bg-white dark:bg-[#1a2a1a] md:bg-transparent md:dark:bg-transparent border-t md:border-t-0 border-brand-border dark:border-[#2a3a2a] px-4 md:px-0 py-3 md:py-0 md:mt-5 z-50"
             style="padding-bottom: calc(0.75rem + env(safe-area-inset-bottom, 0px));">
            <button type="submit"
                wire:loading.attr="disabled"
                wire:target="processCheckout"
                class="w-full flex items-center justify-center gap-2 min-h-[52px] bg-brand-orange hover:bg-[#e06610] disabled:opacity-60 disabled:cursor-wait text-white font-montserrat font-bold text-[15px] rounded-xl transition-all shadow-lg">
                <svg class="w-5 h-5 animate-spin fill-none" style="stroke:currentColor;stroke-width:2" viewBox="0 0 24 24"
                     wire:loading wire:target="processCheckout">
                    <circle cx="12" cy="12" r="9" style="opacity:.3"/>
                    <path d="M21 12a9 9 0 0 0-9-9" stroke-linecap="round"/>
                </svg>
                @if ($paymentMethod === 'pay_on_delivery')
                    <span wire:loading.remove wire:target="processCheckout">Place order — ₦{{ number_format($total) }} on delivery</span>
                    <span wire:loading wire:target="processCheckout">Placing your order…</span>
                @else
                    <span wire:loading.remove wire:target="processCheckout">Pay ₦{{ number_format($total) }} with Paystack</span>
                    <span wire:loading wire:target="processCheckout">Taking you to Paystack…</span>
                @endif
            </button>
            <p class="text-center text-[10px] text-brand-muted mt-2 hidden md:block">
                By completing your purchase you agree to our
                <a href="{{ route('privacy-policy') }}" class="text-brand hover:underline">privacy policy</a>
            </p>
        </div>
        </form>
    </div>

    </div>
    @endif

</div>
</div>

</x-layouts.storefront>
</div>

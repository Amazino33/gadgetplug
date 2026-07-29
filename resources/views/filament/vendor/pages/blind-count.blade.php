<x-filament-panels::page>
<div class="min-h-[80vh] flex items-start justify-center">
<div class="w-full max-w-md mx-auto">

@php
    $session       = $this->getSession();
    $role          = $this->getRole();
    $total         = $this->getTotalProducts();
    $product       = $this->getCurrentProduct();
    $canCount      = $this->canCount();
    $canReset      = $this->canReset();
    $canCancel     = $this->canCancel();
    $isLastProduct = $total > 0 && $this->currentPosition >= $total;
    $isCounting    = $session && (
        ($session->status === 'a_counting' && $role === 'a') ||
        ($session->status === 'b_counting' && $role === 'b')
    );
    $singlePerson  = (filament()->getTenant()->pos_blind_count_participants ?? 2) === 1;
@endphp

{{-- ── NO SESSION ───────────────────────────────────────────────────────── --}}
@if (!$session)

@if ($canCount)
{{-- Storekeeper: show start form --}}
<div class="bg-[#0d1a0d] rounded-2xl border border-[#1a3a1a] p-6 space-y-6">
    <div class="text-center">
        <div class="w-14 h-14 bg-[#1a3a1a] rounded-full flex items-center justify-center mx-auto mb-3">
            <x-heroicon-o-eye-slash class="w-7 h-7 text-[#4caf50]"/>
        </div>
        <h2 class="text-white font-montserrat font-bold text-xl">Start Inventory Count</h2>
        <p class="text-[#5a7a5c] text-sm mt-1">Products will be served randomly. Count what you physically see.</p>
    </div>

    @php
        $nextDue    = $this->nextCountDue();
        $blocked    = $this->isBlockedByCadence();
        $authorized = $nextDue !== null && $this->hasRecountAuthorization();
    @endphp

    <div class="space-y-4">
        <div class="flex items-center justify-between bg-[#162016] border border-[#2a3a2a] rounded-xl px-4 py-3">
            <div>
                <p class="text-white text-sm font-medium">Count by Category</p>
                <p class="text-[#5a7a5c] text-xs">Finish one category before the next</p>
            </div>
            <button wire:click="$toggle('byCategory')"
                class="relative w-11 h-6 rounded-full transition-colors duration-200 focus:outline-none {{ $byCategory ? 'bg-[#4caf50]' : 'bg-[#2a3a2a]' }}">
                <span class="absolute top-0.5 left-0.5 w-5 h-5 bg-white rounded-full shadow transition-transform duration-200 {{ $byCategory ? 'translate-x-5' : 'translate-x-0' }}"></span>
            </button>
        </div>

        {{-- Cadence is a vendor setting, not a choice made here — see StoreProfile.
             Showing the due date turns a dead-end refusal into something the
             storekeeper can act on. --}}
        @if($blocked)
        <div class="bg-[#2a1a0d] border border-[#5a3a1a] rounded-xl px-4 py-3 space-y-1">
            <p class="text-amber-300 text-sm font-semibold">Next count not due yet</p>
            <p class="text-[#c9a06a] text-xs">
                You counted recently. Your next count is due
                <span class="font-semibold text-amber-200">{{ $nextDue->format('j M Y, g:ia') }}</span>
                ({{ $nextDue->diffForHumans() }}).
            </p>
            <p class="text-[#8a7a5c] text-xs pt-1">Ask a manager to authorise an earlier count.</p>
        </div>
        @elseif($authorized)
        <div class="bg-[#0d1a2a] border border-[#1a3a5a] rounded-xl px-4 py-3">
            <p class="text-sky-300 text-sm font-semibold">Early count authorised</p>
            <p class="text-[#6a9ac9] text-xs mt-0.5">A manager has cleared you to count ahead of schedule. Starting now uses up that authorisation.</p>
        </div>
        @endif
    </div>

    <button wire:click="startSession"
        @disabled($blocked)
        class="w-full bg-[#4caf50] hover:bg-[#43a047] disabled:bg-[#2a3a2a] disabled:text-[#5a7a5c] disabled:cursor-not-allowed text-white font-bold py-3.5 rounded-xl transition-colors font-montserrat">
        {{ $blocked ? 'Count Not Due Yet' : 'Begin Count Session' }}
    </button>
</div>

@else
{{-- Manager / Owner: no active session --}}
<div class="bg-[#0d1a0d] rounded-2xl border border-[#1a3a1a] p-8 text-center space-y-3">
    <div class="w-14 h-14 bg-[#1a3a1a] rounded-full flex items-center justify-center mx-auto">
        <x-heroicon-o-eye class="w-7 h-7 text-[#5a7a5c]"/>
    </div>
    <h2 class="text-white font-montserrat font-bold text-lg">No Active Inventory Count</h2>
    <p class="text-[#5a7a5c] text-sm">You can view counts here, but not record one. To let a team member count, give their role the <span class="text-[#4caf50] font-semibold">Perform Inventory Count</span> permission under Settings &rarr; Roles.</p>
</div>
@endif

{{-- Manager's re-count authorisation. Lives on the manager's own login on
     purpose: the cadence is worthless if the person it restricts can lift it. --}}
@php $blockedCounters = $this->getBlockedCounters(); @endphp
@if($blockedCounters->isNotEmpty())
<div class="mt-4 bg-[#0d1a0d] rounded-2xl border border-[#1a3a1a] p-5 space-y-3">
    <div>
        <h3 class="text-white font-montserrat font-bold text-sm">Counters waiting on the schedule</h3>
        <p class="text-[#5a7a5c] text-xs mt-0.5">Authorising lets one person start a single early count. It is used up as soon as they begin, and recorded against your name.</p>
    </div>

    <div class="space-y-2">
        @foreach($blockedCounters as $entry)
        <div class="flex items-center justify-between gap-3 bg-[#162016] border border-[#2a3a2a] rounded-xl px-4 py-3">
            <div class="min-w-0">
                <p class="text-white text-sm font-medium truncate">{{ $entry->user->name }}</p>
                <p class="text-[#5a7a5c] text-xs">Next count due {{ $entry->due->format('j M, g:ia') }}</p>
            </div>
            @if($entry->authorized)
            <span class="shrink-0 text-sky-300 text-xs font-semibold">Authorised ✓</span>
            @elseif($entry->user->id === auth()->id())
            <span class="shrink-0 text-[#5a7a5c] text-xs">You</span>
            @else
            <button wire:click="authorizeRecount({{ $entry->user->id }})"
                wire:confirm="Let {{ $entry->user->name }} run one early count? This will be recorded against your name."
                class="shrink-0 border border-[#4caf50] hover:bg-[#4caf50]/15 text-[#4caf50] text-xs font-semibold px-3 py-2 rounded-lg transition-colors focus:outline-none focus:ring-2 focus:ring-[#4caf50]">
                Authorise
            </button>
            @endif
        </div>
        @endforeach
    </div>
</div>
@endif

{{-- ── WAITING: A is still counting ────────────────────────────────────── --}}
@elseif($session->status === 'a_counting' && $role !== 'a')
<div class="bg-[#0d1a0d] rounded-2xl border border-[#1a3a1a] p-8 text-center space-y-4">
    <div class="w-14 h-14 bg-[#1a3a1a] rounded-full flex items-center justify-center mx-auto">
        <x-heroicon-o-clock class="w-7 h-7 text-amber-400"/>
    </div>
    <h2 class="text-white font-montserrat font-bold text-lg">Count in Progress</h2>
    <p class="text-[#5a7a5c] text-sm">
        <span class="text-white font-semibold">{{ $session->storekeeperA->name }}</span> is currently completing their count.
        @if($canCount) You will join as Storekeeper B when they finish. @endif
    </p>
    @php
        $countedSoFar = \App\Models\BlindCountEntry::where('blind_count_session_id', $session->id)->whereNotNull('count')->count();
    @endphp
    <div class="bg-[#162016] rounded-xl px-4 py-3 text-left space-y-1">
        <div class="flex justify-between text-xs">
            <span class="text-[#5a7a5c]">Products counted so far</span>
            <span class="text-white font-semibold">{{ $countedSoFar }} / {{ $total }}</span>
        </div>
        <div class="h-1.5 bg-[#1a3a1a] rounded-full overflow-hidden mt-1">
            <div class="h-full bg-amber-400 rounded-full transition-all"
                style="width: {{ $total > 0 ? round(($countedSoFar / $total) * 100) : 0 }}%"></div>
        </div>
    </div>
    @if($canReset)
    <button wire:click="resetSession"
        wire:confirm="This will delete all counts entered so far and reset the session to the beginning. Are you sure?"
        class="w-full mt-2 border border-red-800 hover:bg-red-900/30 text-red-400 text-sm font-semibold py-2.5 rounded-xl transition-colors">
        ↺ Clear All Counting
    </button>
    @endif
    @if($canCancel)
    {{-- Reset hands the session back to the same counter; cancel ends it so a
         different person can start. That is the difference worth spelling out. --}}
    <button wire:click="cancelSession"
        wire:confirm="Cancel this count session? Every count entered so far is discarded and nothing is written to stock. Anyone eligible can then start a fresh count."
        class="w-full border border-[#2a3a2a] hover:border-red-800 hover:bg-red-900/20 text-[#c96a6a] text-sm font-semibold py-2.5 rounded-xl transition-colors">
        ✕ Cancel Session &amp; Free the Store
    </button>
    @endif
</div>

{{-- ── WAITING: A submitted, B hasn't joined yet ───────────────────────── --}}
@elseif($session->status === 'b_counting' && $role === 'observer')
<div class="bg-[#0d1a0d] rounded-2xl border border-[#1a3a1a] p-8 text-center space-y-5">
    <div class="w-14 h-14 bg-[#1a3a1a] rounded-full flex items-center justify-center mx-auto">
        <x-heroicon-o-shield-check class="w-7 h-7 text-[#4caf50]"/>
    </div>
    <div>
        <h2 class="text-white font-montserrat font-bold text-lg">Awaiting Verification</h2>
        <p class="text-[#5a7a5c] text-sm mt-1">
            <span class="text-white font-semibold">{{ $session->storekeeperA->name }}</span> has finished their count.
            @if($canCount) Join as Storekeeper B to verify independently. @else Waiting for a storekeeper to join as Storekeeper B. @endif
        </p>
    </div>
    @if($canCount)
    <button wire:click="joinAsB"
        class="w-full bg-[#4caf50] hover:bg-[#43a047] text-white font-bold py-3.5 rounded-xl transition-colors font-montserrat">
        Join as Storekeeper B
    </button>
    @endif
    @if($canReset)
    <button wire:click="resetSession"
        wire:confirm="This will delete all counts entered so far and reset the session to the beginning. Are you sure?"
        class="w-full border border-red-800 hover:bg-red-900/30 text-red-400 text-sm font-semibold py-2.5 rounded-xl transition-colors">
        ↺ Clear All Counting
    </button>
    @endif
    @if($canCancel)
    <button wire:click="cancelSession"
        wire:confirm="Cancel this count session? Every count entered so far is discarded and nothing is written to stock. Anyone eligible can then start a fresh count."
        class="w-full border border-[#2a3a2a] hover:border-red-800 hover:bg-red-900/20 text-[#c96a6a] text-sm font-semibold py-2.5 rounded-xl transition-colors">
        ✕ Cancel Session &amp; Free the Store
    </button>
    @endif
</div>

{{-- ── WAITING: A submitted, waiting for B ─────────────────────────────── --}}
@elseif($session->status === 'b_counting' && $role === 'a')
<div class="bg-[#0d1a0d] rounded-2xl border border-[#1a3a1a] p-8 text-center space-y-4">
    <div class="w-14 h-14 bg-[#1a2a1a] rounded-full flex items-center justify-center mx-auto">
        <x-heroicon-o-check-circle class="w-7 h-7 text-[#4caf50]"/>
    </div>
    <h2 class="text-white font-montserrat font-bold text-lg">Your count is submitted</h2>
    <p class="text-[#5a7a5c] text-sm">Waiting for Storekeeper B to complete their independent verification.</p>
    @if($canReset)
    <button wire:click="resetSession"
        wire:confirm="This will delete all counts entered so far and reset the session to the beginning. Are you sure?"
        class="w-full border border-red-800 hover:bg-red-900/30 text-red-400 text-sm font-semibold py-2.5 rounded-xl transition-colors">
        ↺ Clear All Counting
    </button>
    @endif
    @if($canCancel)
    <button wire:click="cancelSession"
        wire:confirm="Cancel this count session? Every count entered so far is discarded and nothing is written to stock. Anyone eligible can then start a fresh count."
        class="w-full border border-[#2a3a2a] hover:border-red-800 hover:bg-red-900/20 text-[#c96a6a] text-sm font-semibold py-2.5 rounded-xl transition-colors">
        ✕ Cancel Session &amp; Free the Store
    </button>
    @endif
</div>

{{-- ── COMPLETED ─────────────────────────────────────────────────────────── --}}
@elseif($session->status === 'completed')
<div class="bg-[#0d1a0d] rounded-2xl border border-[#1a3a1a] p-8 text-center space-y-4">
    <div class="w-14 h-14 bg-[#1a2a1a] rounded-full flex items-center justify-center mx-auto">
        <x-heroicon-o-check-badge class="w-7 h-7 text-[#4caf50]"/>
    </div>
    <h2 class="text-white font-montserrat font-bold text-lg">Session Complete</h2>
    <p class="text-[#5a7a5c] text-sm">The inventory count has been processed. Check Audit Sessions for any discrepancies that need manager review.</p>
    <a href="{{ \App\Filament\Vendor\Resources\AuditSessions\AuditSessionResource::getUrl('index', tenant: filament()->getTenant()) }}"
        class="inline-block mt-2 text-[#4caf50] text-sm font-semibold hover:underline">
        View Audit Sessions →
    </a>
</div>

{{-- ── COUNTING UI ───────────────────────────────────────────────────────── --}}
@elseif($isCounting && $product)
@php
    $countedSoFar = \App\Models\BlindCountEntry::where('blind_count_session_id', $session->id)
        ->where('user_id', auth()->id())
        ->whereNotNull('count')
        ->count();
    $progressPct = $total > 0 ? round(($countedSoFar / $total) * 100) : 0;
    $imgUrl      = $product->getFirstMediaUrl('product-images', 'preview');
@endphp

{{-- Full-screen count console.

     LAYOUT CONTRACT — read before editing: this is a fixed-height flex column,
     not a document that flows downward. Every control is a fixed-height row, and
     the product image is the ONLY flexible element (flex-1 + min-h-0), so it
     absorbs whatever space is left over instead of dictating it. That inversion
     is what guarantees the number input and the Next button are on screen at
     every viewport size without scrolling. If you add a row here, give it a
     fixed height — anything that grows steals from the image, never from the
     controls.

     Direction flips on `landscape:` rather than a width breakpoint, because what
     matters is whether the viewport is short, not whether it is wide: stacked
     when tall (phone/tablet portrait), image-left/controls-right when wide and
     short (desktop, laptop, tablet and phone landscape).

     z-40 is deliberate: above Filament's sidebar (z-30) and topbar so the panel
     chrome is hidden, but below notifications (z-50) and the barcode scanner
     (z-[200]) so both still surface over the console. --}}
<div class="fixed top-0 left-0 right-0 z-40 flex flex-col bg-[#0a140a] overscroll-none"
    style="height: 100vh; height: 100dvh;"
    x-data="{
        count: $wire.entangle('count'),
        holdTimer: null, holdInterval: null,
        toastVisible: false, toastMessage: '', toastTimer: null,
        showNote: {{ $this->note !== '' ? 'true' : 'false' }},

        init() {
            // The console owns the whole viewport — stop the page behind it from
            // scrolling or rubber-banding under the fixed layer. Filament scrolls
            // on <html>, so locking <body> alone leaves the page still draggable.
            document.documentElement.style.overflow = 'hidden';
            document.body.style.overflow = 'hidden';
        },
        destroy() {
            document.documentElement.style.overflow = '';
            document.body.style.overflow = '';
        },

        startHold(dir) {
            this.step(dir);
            this.holdTimer = setTimeout(() => {
                this.holdInterval = setInterval(() => this.step(dir), 120);
            }, 400);
        },
        stopHold() {
            clearTimeout(this.holdTimer);
            clearInterval(this.holdInterval);
        },
        step(dir) {
            this.count = dir === 'inc' ? this.count + 1 : Math.max(0, this.count - 1);
        },
        clampCount() {
            if (this.count === '' || this.count < 0 || isNaN(this.count)) this.count = 0;
        },
        showToast(message) {
            this.toastMessage = message;
            this.toastVisible = true;
            clearTimeout(this.toastTimer);
            this.toastTimer = setTimeout(() => this.toastVisible = false, 4000);
        },
        onKeydown(e) {
            const tag = document.activeElement?.tagName;
            if (tag === 'TEXTAREA') return;
            if (e.key === 'ArrowRight') { e.preventDefault(); this.$refs.primaryActionBtn?.click(); }
            else if (e.key === 'ArrowLeft') { e.preventDefault(); this.$refs.previousBtn?.click(); }
            else if (e.key === '+' || e.key === '=') { e.preventDefault(); this.step('inc'); }
            else if (e.key === '-' || e.key === '_') { e.preventDefault(); this.step('dec'); }
            else if (e.key === 'Enter' && tag !== 'INPUT') { e.preventDefault(); this.$refs.primaryActionBtn?.click(); }
        },
    }"
    x-on:entry-saved.window="showToast('Saved ' + $event.detail.count + ' — ' + $event.detail.productName)"
    x-on:barcode-scanned.window="$wire.jumpToBarcode($event.detail.barcode)"
    x-on:keydown.window="onKeydown($event)"
>

    {{-- Row 1: exit · position · progress · search — one 48px line that replaces
         the old separate header and progress blocks (~112px before). --}}
    <div class="shrink-0 h-12 flex items-center gap-3 px-3 border-b border-[#1a3a1a]">
        <button wire:click="exitCount"
            aria-label="Exit count and return to dashboard"
            title="Exit count (your current entry is saved)"
            class="w-9 h-9 shrink-0 flex items-center justify-center rounded-lg text-[#7a9e7c] hover:text-white hover:bg-[#162016] transition-colors focus:outline-none focus:ring-2 focus:ring-[#4caf50]">
            <x-heroicon-o-x-mark class="w-5 h-5"/>
        </button>

        <div class="flex-1 min-w-0">
            <div class="flex items-baseline justify-between gap-2">
                <span class="text-[#7a9e7c] text-[11px] font-semibold uppercase tracking-wider">Item {{ $this->currentPosition }} of {{ $total }}</span>
                <span class="text-[#3a5a3c] text-[11px] shrink-0">{{ $countedSoFar }} counted</span>
            </div>
            <div class="h-1 bg-[#1a3a1a] rounded-full overflow-hidden mt-1">
                <div class="h-full bg-[#4caf50] rounded-full transition-all duration-300"
                    style="width: {{ $progressPct }}%"></div>
            </div>
        </div>

        <button wire:click="$toggle('showSearch')"
            aria-label="Search counted products"
            class="w-9 h-9 shrink-0 flex items-center justify-center rounded-lg text-[#7a9e7c] hover:text-white hover:bg-[#162016] transition-colors focus:outline-none focus:ring-2 focus:ring-[#4caf50]">
            <x-heroicon-o-magnifying-glass class="w-5 h-5"/>
        </button>

        @if($canCancel)
        {{-- Distinct from Exit: exit saves and leaves the session open, this ends
             it so somebody else can start. Behind a confirm — it discards counts. --}}
        <button wire:click="cancelSession"
            wire:confirm="Cancel this count session? Every count entered so far is discarded and nothing is written to stock. Anyone eligible can then start a fresh count."
            aria-label="Cancel this count session"
            title="Cancel session — discards all counts"
            class="w-9 h-9 shrink-0 flex items-center justify-center rounded-lg text-[#8a5a5a] hover:text-red-400 hover:bg-red-900/20 transition-colors focus:outline-none focus:ring-2 focus:ring-red-500">
            <x-heroicon-o-trash class="w-5 h-5"/>
        </button>
        @endif
    </div>

    {{-- Row 2: the elastic middle. Stacked when tall, side-by-side when wide. --}}
    <div class="flex-1 min-h-0 flex flex-col landscape:flex-row">

        {{-- Product image — no stock/reorder signal shown here: a blind count must
             never see system stock state while counting. --}}
        <div class="flex-1 min-h-0 min-w-0 p-3 landscape:p-5 flex items-center justify-center">
            <div class="w-full h-full max-w-md landscape:max-w-none bg-[#162016] rounded-xl overflow-hidden flex items-center justify-center">
                @if($imgUrl)
                    <img src="{{ $imgUrl }}" alt="{{ $product->name }}"
                        class="max-w-full max-h-full object-contain p-2">
                @else
                    <x-heroicon-o-photo class="w-16 h-16 text-[#2a3a2a]"/>
                @endif
            </div>
        </div>

        {{-- Controls — shrink-0 everywhere so they can never be squeezed. --}}
        <div class="shrink-0 px-4 pb-2 landscape:px-5 landscape:w-[360px] lg:w-[420px] landscape:border-l landscape:border-[#1a3a1a] landscape:flex landscape:flex-col landscape:justify-center landscape:overflow-y-auto">

            {{-- Identity is height-bounded on purpose: the name clamps at two lines
                 (with a hover tooltip for the rest) so a long product name can
                 never wrap indefinitely and eat the image's space. --}}
            <div class="mb-3 landscape:mb-5">
                <p class="text-white font-montserrat font-bold text-base landscape:text-xl leading-tight line-clamp-2"
                    title="{{ $product->name }}">{{ $product->name }}</p>
                @if($product->sku || $product->barcode)
                <p class="text-[#5a7a5c] text-[11px] font-mono mt-0.5 truncate">
                    @if($product->sku)SKU {{ $product->sku }}@endif
                    @if($product->sku && $product->barcode) &middot; @endif
                    @if($product->barcode){{ $product->barcode }}@endif
                </p>
                @endif
            </div>

            {{-- Counter --}}
            <div class="flex items-center gap-4 mb-3">
                <button
                    aria-label="Decrease count"
                    @mousedown="startHold('dec')" @mouseup="stopHold()" @mouseleave="stopHold()"
                    @touchstart.passive="startHold('dec')" @touchend="stopHold()"
                    class="w-16 h-16 shrink-0 bg-[#162016] border border-[#2a3a2a] hover:border-[#4caf50] active:bg-[#1a2a1a] text-white text-3xl font-bold rounded-2xl flex items-center justify-center transition-colors select-none focus:outline-none focus:ring-2 focus:ring-[#4caf50] focus:ring-offset-2 focus:ring-offset-[#0a140a]">
                    −
                </button>

                <div class="flex-1 min-w-0 text-center">
                    <input type="number"
                        inputmode="numeric"
                        aria-label="Counted quantity"
                        x-model="count"
                        @blur="clampCount()"
                        min="0"
                        class="w-full bg-transparent text-white text-5xl font-bold text-center border-none outline-none [appearance:textfield] [&::-webkit-outer-spin-button]:appearance-none [&::-webkit-inner-spin-button]:appearance-none focus:ring-2 focus:ring-[#4caf50] rounded-lg"
                        placeholder="0">
                    <p class="text-[#3a5a3c] text-[11px] mt-0.5">Tap to type exact number</p>
                </div>

                <button
                    aria-label="Increase count"
                    @mousedown="startHold('inc')" @mouseup="stopHold()" @mouseleave="stopHold()"
                    @touchstart.passive="startHold('inc')" @touchend="stopHold()"
                    class="w-16 h-16 shrink-0 bg-[#162016] border border-[#2a3a2a] hover:border-[#4caf50] active:bg-[#1a2a1a] text-white text-3xl font-bold rounded-2xl flex items-center justify-center transition-colors select-none focus:outline-none focus:ring-2 focus:ring-[#4caf50] focus:ring-offset-2 focus:ring-offset-[#0a140a]">
                    +
                </button>
            </div>

            {{-- Quick actions: not found, scan, note --}}
            <div class="flex items-center gap-2">
                <button wire:click="markNotFound"
                    wire:confirm="Mark this item as not found (count = 0)?"
                    class="flex-1 bg-[#162016] border border-[#2a3a2a] hover:border-red-800 text-[#c96a6a] text-xs font-semibold py-2.5 rounded-xl transition-colors focus:outline-none focus:ring-2 focus:ring-red-500">
                    Not found / 0
                </button>
                <button
                    aria-label="Scan barcode"
                    @click="window.dispatchEvent(new CustomEvent('open-barcode-scanner'))"
                    class="w-10 h-10 shrink-0 bg-[#162016] border border-[#2a3a2a] hover:border-[#4caf50] text-[#7a9e7c] rounded-xl flex items-center justify-center transition-colors focus:outline-none focus:ring-2 focus:ring-[#4caf50]">
                    <x-heroicon-o-qr-code class="w-4 h-4"/>
                </button>
                <button
                    aria-label="Toggle note field"
                    @click="showNote = !showNote"
                    class="w-10 h-10 shrink-0 bg-[#162016] border border-[#2a3a2a] hover:border-[#4caf50] text-[#7a9e7c] rounded-xl flex items-center justify-center transition-colors focus:outline-none focus:ring-2 focus:ring-[#4caf50]">
                    <x-heroicon-o-pencil-square class="w-4 h-4"/>
                </button>
            </div>

            {{-- Note stays inline on purpose: opening it borrows height from the
                 image (the flexible row), never from the controls below. --}}
            <div x-show="showNote" x-cloak class="mt-2">
                <textarea wire:model="note" rows="2" placeholder="Optional note (e.g. damaged, wrong location)…"
                    class="w-full bg-[#162016] border border-[#2a3a2a] text-white rounded-lg px-3 py-2 text-sm focus:outline-none focus:border-[#4caf50] placeholder-[#3a5a3c]"></textarea>
            </div>
        </div>
    </div>

    {{-- Toast: autosave confirmation + undo. Overlays the top of the image pane
         rather than the controls — in a fixed layout there is no free space, so
         a floating element must cover the one region with nothing to click. --}}
    <div x-show="toastVisible" x-cloak
        x-transition:enter="transition ease-out duration-200"
        x-transition:enter-start="opacity-0 -translate-y-2"
        x-transition:enter-end="opacity-100 translate-y-0"
        x-transition:leave="transition ease-in duration-150"
        x-transition:leave-start="opacity-100"
        x-transition:leave-end="opacity-0"
        class="absolute inset-x-0 top-[56px] px-4 z-30 pointer-events-none">
        <div class="bg-[#1a2a1a] border border-[#2a3a2a] rounded-xl px-4 py-3 flex items-center justify-between gap-3 shadow-lg pointer-events-auto">
            <span class="text-white text-xs" x-text="toastMessage"></span>
            @if($canUndo)
            <button wire:click="undoLast" @click="toastVisible = false"
                class="text-[#4caf50] text-xs font-bold shrink-0 focus:outline-none focus:ring-2 focus:ring-[#4caf50] rounded px-1">
                UNDO
            </button>
            @endif
        </div>
    </div>

    {{-- Row 3: action bar, pinned to the bottom for thumb reach --}}
    <div class="shrink-0 bg-[#0d1a0d] border-t border-[#1a3a1a] px-4 py-3 flex items-center gap-3"
        style="padding-bottom: max(0.75rem, env(safe-area-inset-bottom));">
        <button
            x-ref="previousBtn"
            aria-label="Previous item"
            wire:click="previous"
            @disabled($this->currentPosition <= 1)
            class="w-14 h-12 shrink-0 bg-[#162016] border border-[#2a3a2a] hover:border-[#4caf50] disabled:opacity-30 disabled:hover:border-[#2a3a2a] text-white rounded-xl flex items-center justify-center transition-colors focus:outline-none focus:ring-2 focus:ring-[#4caf50]">
            ←
        </button>

        @if($isLastProduct)
        <button
            x-ref="primaryActionBtn"
            wire:click="submitAll"
            wire:confirm="{{ $singlePerson
                ? 'This locks your count and reconciles it against live stock immediately — any mismatch (short or over) goes to a manager for review. This cannot be undone. Are you sure?'
                : 'This will lock your count and cannot be undone. Are you sure?' }}"
            class="flex-1 bg-[#4caf50] hover:bg-[#43a047] text-white font-bold py-3 rounded-xl transition-colors font-montserrat text-base focus:outline-none focus:ring-2 focus:ring-white focus:ring-offset-2 focus:ring-offset-[#0a140a]">
            Review &amp; Finish ✓
        </button>
        @else
        <button
            x-ref="primaryActionBtn"
            wire:click="next"
            class="flex-1 bg-[#e65c00] hover:bg-[#d35400] text-white font-bold py-3 rounded-xl transition-colors font-montserrat text-base flex items-center justify-center gap-2 focus:outline-none focus:ring-2 focus:ring-white focus:ring-offset-2 focus:ring-offset-[#0a140a]">
            Next <span aria-hidden="true">→</span>
        </button>
        @endif
    </div>

    {{-- Search overlay — covers the console instead of expanding inside it, so
         opening it can't push the counter off screen. --}}
    @if($showSearch)
    <div class="absolute inset-0 z-30 bg-[#0a140a] flex flex-col">
        <div class="shrink-0 h-12 flex items-center gap-3 px-3 border-b border-[#1a3a1a]">
            <button wire:click="$toggle('showSearch')"
                aria-label="Close search"
                class="w-9 h-9 shrink-0 flex items-center justify-center rounded-lg text-[#7a9e7c] hover:text-white hover:bg-[#162016] transition-colors focus:outline-none focus:ring-2 focus:ring-[#4caf50]">
                <x-heroicon-o-arrow-left class="w-5 h-5"/>
            </button>
            <span class="text-[#7a9e7c] text-[11px] font-semibold uppercase tracking-wider">Counted products</span>
        </div>

        <div class="shrink-0 p-3">
            <input type="text" wire:model.live.debounce.300ms="searchQuery"
                aria-label="Search counted products"
                placeholder="Search by name or SKU…"
                class="w-full bg-[#162016] border border-[#2a3a2a] text-white rounded-lg px-3 py-2.5 text-sm focus:outline-none focus:border-[#4caf50] placeholder-[#3a5a3c]">
        </div>

        <div class="flex-1 min-h-0 overflow-y-auto px-3 pb-3 space-y-1">
            @foreach($this->getCountedEntries() as $entry)
            <button wire:click="goToPosition({{ $entry->position }})"
                class="w-full text-left flex items-center justify-between gap-2 px-3 py-2.5 bg-[#162016] hover:bg-[#1a2a1a] rounded-lg transition-colors focus:outline-none focus:ring-2 focus:ring-[#4caf50]">
                <span class="text-white text-xs truncate">{{ $entry->product->name }}</span>
                <span class="text-[#4caf50] text-xs font-bold shrink-0">{{ $entry->count }}</span>
            </button>
            @endforeach
            @if($this->getCountedEntries()->isEmpty())
            <p class="text-[#3a5a3c] text-xs px-1 py-2">No counted products yet.</p>
            @endif
        </div>
    </div>
    @endif
</div>
@endif

</div>
</div>
</x-filament-panels::page>

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

    <div class="space-y-4">
        <div>
            <label class="text-[#7a9e7c] text-xs font-semibold uppercase tracking-wider mb-1.5 block">Frequency</label>
            <select wire:model="frequency"
                class="w-full bg-[#162016] border border-[#2a3a2a] text-white rounded-xl px-4 py-2.5 text-sm focus:outline-none focus:border-[#4caf50]">
                <option value="daily">Daily</option>
                <option value="weekly">Weekly</option>
                <option value="monthly">Monthly</option>
                <option value="custom">Custom</option>
            </select>
        </div>

        @if($frequency === 'custom')
        <div>
            <label class="text-[#7a9e7c] text-xs font-semibold uppercase tracking-wider mb-1.5 block">Every N Days</label>
            <input type="number" wire:model="customDays" min="1"
                class="w-full bg-[#162016] border border-[#2a3a2a] text-white rounded-xl px-4 py-2.5 text-sm focus:outline-none focus:border-[#4caf50]"
                placeholder="e.g. 3">
        </div>
        @endif

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
    </div>

    <button wire:click="startSession"
        class="w-full bg-[#4caf50] hover:bg-[#43a047] text-white font-bold py-3.5 rounded-xl transition-colors font-montserrat">
        Begin Count Session
    </button>
</div>

@else
{{-- Manager / Owner: no active session --}}
<div class="bg-[#0d1a0d] rounded-2xl border border-[#1a3a1a] p-8 text-center space-y-3">
    <div class="w-14 h-14 bg-[#1a3a1a] rounded-full flex items-center justify-center mx-auto">
        <x-heroicon-o-eye class="w-7 h-7 text-[#5a7a5c]"/>
    </div>
    <h2 class="text-white font-montserrat font-bold text-lg">No Active Inventory Count</h2>
    <p class="text-[#5a7a5c] text-sm">Inventory count sessions are started by storekeepers. Assign the <span class="text-[#4caf50] font-semibold">Storekeeper</span> role to a team member from the Team Members page.</p>
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
@endphp
<div class="bg-[#0d1a0d] rounded-2xl border border-[#1a3a1a]"
    x-data="{
        count: $wire.entangle('count'),
        holdTimer: null, holdInterval: null,
        toastVisible: false, toastMessage: '', toastTimer: null,
        showNote: {{ $this->note !== '' ? 'true' : 'false' }},

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

    {{-- Header --}}
    <div class="px-4 pt-4 pb-2 flex items-center justify-between">
        <span class="text-[#5a7a5c] text-xs font-semibold uppercase tracking-wider">Inventory Count</span>
        <span class="text-[#7a9e7c] text-xs font-semibold">ITEM {{ $this->currentPosition }} OF {{ $total }}</span>
    </div>

    {{-- Progress bar — reflects items actually counted, not just position --}}
    <div class="px-4 mb-4">
        <div class="h-1.5 bg-[#1a3a1a] rounded-full overflow-hidden">
            <div class="h-full bg-[#4caf50] rounded-full transition-all duration-300"
                style="width: {{ $total > 0 ? round(($countedSoFar / $total) * 100) : 0 }}%">
            </div>
        </div>
        <p class="text-[#3a5a3c] text-[11px] mt-1">{{ $countedSoFar }} of {{ $total }} counted</p>
    </div>

    {{-- Product Image — no stock/reorder signal shown here: a blind count must
         never see system stock state while counting. --}}
    <div class="relative mx-4 mb-4 bg-[#162016] rounded-xl overflow-hidden aspect-square">
        @php $imgUrl = $product->getFirstMediaUrl('product-images', 'preview'); @endphp
        @if($imgUrl)
            <img src="{{ $imgUrl }}" alt="{{ $product->name }}"
                class="w-full h-full object-contain p-4">
        @else
            <div class="w-full h-full flex items-center justify-center">
                <x-heroicon-o-photo class="w-16 h-16 text-[#2a3a2a]"/>
            </div>
        @endif
    </div>

    {{-- Product Info --}}
    <div class="px-4 mb-5 space-y-0.5">
        @if($product->sku)
        <p class="text-[#5a7a5c] text-[11px] font-semibold uppercase tracking-widest">SKU: {{ $product->sku }}</p>
        @endif
        <p class="text-white font-montserrat font-bold text-lg leading-tight break-words">{{ $product->name }}</p>
        @if($product->barcode)
        <p class="text-[#5a7a5c] text-xs font-mono">{{ $product->barcode }}</p>
        @endif
    </div>

    {{-- Counter --}}
    <div class="px-4 mb-4">
        <div class="flex items-center gap-4">
            <button
                aria-label="Decrease count"
                @mousedown="startHold('dec')" @mouseup="stopHold()" @mouseleave="stopHold()"
                @touchstart.passive="startHold('dec')" @touchend="stopHold()"
                class="w-16 h-16 shrink-0 bg-[#162016] border border-[#2a3a2a] hover:border-[#4caf50] active:bg-[#1a2a1a] text-white text-3xl font-bold rounded-2xl flex items-center justify-center transition-colors select-none focus:outline-none focus:ring-2 focus:ring-[#4caf50] focus:ring-offset-2 focus:ring-offset-[#0d1a0d]">
                −
            </button>

            <div class="flex-1 text-center">
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
                class="w-16 h-16 shrink-0 bg-[#162016] border border-[#2a3a2a] hover:border-[#4caf50] active:bg-[#1a2a1a] text-white text-3xl font-bold rounded-2xl flex items-center justify-center transition-colors select-none focus:outline-none focus:ring-2 focus:ring-[#4caf50] focus:ring-offset-2 focus:ring-offset-[#0d1a0d]">
                +
            </button>
        </div>
    </div>

    {{-- Quick actions: not found, scan, note --}}
    <div class="px-4 mb-4 flex items-center gap-2">
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

    <div x-show="showNote" x-cloak class="px-4 mb-4">
        <textarea wire:model="note" rows="2" placeholder="Optional note (e.g. damaged, wrong location)…"
            class="w-full bg-[#162016] border border-[#2a3a2a] text-white rounded-lg px-3 py-2 text-sm focus:outline-none focus:border-[#4caf50] placeholder-[#3a5a3c]"></textarea>
    </div>

    {{-- Search previously counted --}}
    <div class="px-4 mb-4">
        <button wire:click="$toggle('showSearch')"
            class="text-[#5a7a5c] hover:text-[#4caf50] text-xs flex items-center gap-1.5 transition-colors focus:outline-none focus:ring-2 focus:ring-[#4caf50] rounded">
            <x-heroicon-o-magnifying-glass class="w-3.5 h-3.5"/>
            {{ $showSearch ? 'Hide' : 'Search counted products' }}
        </button>

        @if($showSearch)
        <div class="mt-2 space-y-2">
            <input type="text" wire:model.live.debounce.300ms="searchQuery"
                aria-label="Search counted products"
                placeholder="Search by name or SKU…"
                class="w-full bg-[#162016] border border-[#2a3a2a] text-white rounded-lg px-3 py-2 text-sm focus:outline-none focus:border-[#4caf50] placeholder-[#3a5a3c]">

            <div class="space-y-1 max-h-40 overflow-y-auto">
                @foreach($this->getCountedEntries() as $entry)
                <button wire:click="goToPosition({{ $entry->position }})"
                    class="w-full text-left flex items-center justify-between px-3 py-2 bg-[#162016] hover:bg-[#1a2a1a] rounded-lg transition-colors focus:outline-none focus:ring-2 focus:ring-[#4caf50]">
                    <span class="text-white text-xs break-words">{{ $entry->product->name }}</span>
                    <span class="text-[#4caf50] text-xs font-bold shrink-0 ml-2">{{ $entry->count }}</span>
                </button>
                @endforeach
                @if($this->getCountedEntries()->isEmpty())
                <p class="text-[#3a5a3c] text-xs px-1">No counted products yet.</p>
                @endif
            </div>
        </div>
        @endif
    </div>

    {{-- Toast: autosave confirmation + undo --}}
    <div x-show="toastVisible" x-cloak
        x-transition:enter="transition ease-out duration-200"
        x-transition:enter-start="opacity-0 translate-y-2"
        x-transition:enter-end="opacity-100 translate-y-0"
        x-transition:leave="transition ease-in duration-150"
        x-transition:leave-start="opacity-100"
        x-transition:leave-end="opacity-0"
        class="sticky bottom-[88px] sm:bottom-4 mx-4 mb-2 z-10">
        <div class="bg-[#1a2a1a] border border-[#2a3a2a] rounded-xl px-4 py-3 flex items-center justify-between gap-3 shadow-lg">
            <span class="text-white text-xs" x-text="toastMessage"></span>
            @if($canUndo)
            <button wire:click="undoLast" @click="toastVisible = false"
                class="text-[#4caf50] text-xs font-bold shrink-0 focus:outline-none focus:ring-2 focus:ring-[#4caf50] rounded px-1">
                UNDO
            </button>
            @endif
        </div>
    </div>

    {{-- Sticky bottom action bar — thumb reach on mobile --}}
    <div class="sticky bottom-0 left-0 right-0 rounded-b-2xl bg-[#0d1a0d] border-t border-[#1a3a1a] px-4 py-3 flex items-center gap-3"
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
            class="flex-1 bg-[#4caf50] hover:bg-[#43a047] text-white font-bold py-3 rounded-xl transition-colors font-montserrat text-base focus:outline-none focus:ring-2 focus:ring-white focus:ring-offset-2 focus:ring-offset-[#0d1a0d]">
            Review &amp; Finish ✓
        </button>
        @else
        <button
            x-ref="primaryActionBtn"
            wire:click="next"
            class="flex-1 bg-[#e65c00] hover:bg-[#d35400] text-white font-bold py-3 rounded-xl transition-colors font-montserrat text-base flex items-center justify-center gap-2 focus:outline-none focus:ring-2 focus:ring-white focus:ring-offset-2 focus:ring-offset-[#0d1a0d]">
            Next <span aria-hidden="true">→</span>
        </button>
        @endif
    </div>
</div>
@endif

</div>
</div>
</x-filament-panels::page>

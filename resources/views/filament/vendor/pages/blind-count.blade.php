<x-filament-panels::page>
<div class="min-h-[80vh] flex items-start justify-center">
<div class="w-full max-w-md mx-auto">

@php
    $session       = $this->getSession();
    $role          = $this->getRole();
    $total         = $this->getTotalProducts();
    $canCount      = $this->canCount();
    $canReset      = $this->canReset();
    $canCancel     = $this->canCancel();
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
    {{-- No live progress here any more: the whole point of counting locally is
         that nothing reaches the server — including this page — until the
         counter finishes and submits, so a moving bar would just sit at
         empty. {{ $total }} products are on the sheet; that is all this
         screen can honestly say until they are done. --}}
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
@elseif($isCounting)
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
    {{-- Everything below lives in the browser until Finish ships it in one
         request — see BlindCount::finishCounting(). That is the whole point
         of this rewrite: a bad connection used to mean a stalled network call
         for every single Next/Previous/Not-found tap; now it means nothing
         until the very end, where one failed request just means the counter
         taps Finish again — the local draft (and localStorage backup below)
         is still sitting right there. --}}
    x-data="{
        products: @js($this->productsForCounting()),
        entries: {},
        position: 1,
        // Tied to the session's own updated_at, not just its id: a manager's
        // 'Clear All Counting' (resetSession) touches this same row on a
        // completely different device from the counter's — there is no way to
        // reach into that device's localStorage from here, so instead a reset
        // is made to change the key itself, which orphans the stale draft
        // automatically the next time this screen loads, rather than risking
        // a discarded count silently coming back from a phone's local cache.
        draftKey: 'gp-blindcount-draft-{{ $session->id }}-{{ $session->updated_at?->timestamp }}-{{ auth()->id() }}',
        enterSnapshot: null,
        lastUndo: null,
        holdTimer: null, holdInterval: null,
        toastVisible: false, toastMessage: '', toastTimer: null,
        showNote: false,
        showSearch: false,
        searchQuery: '',
        submitting: false,

        init() {
            this.products.forEach(p => { this.entries[p.id] = { count: 0, note: null }; });
            this.restoreDraft();
            this.enterSnapshot = { ...this.entries[this.currentProductId] };
            this.showNote = !!this.entries[this.currentProductId]?.note;

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

        get total() { return this.products.length; },
        get product() { return this.products[this.position - 1] || null; },
        get currentProductId() { return this.product?.id ?? null; },
        get progressPct() { return this.total > 0 ? Math.round((this.position / this.total) * 100) : 0; },
        get isLastProduct() { return this.total > 0 && this.position >= this.total; },
        get filteredResults() {
            const q = this.searchQuery.trim().toLowerCase();
            if (!q) return [];
            return this.products
                .filter(p => p.name.toLowerCase().includes(q) || (p.sku ?? '').toLowerCase().includes(q))
                .slice(0, 30);
        },

        persist() {
            try { localStorage.setItem(this.draftKey, JSON.stringify({ entries: this.entries, position: this.position })); } catch (e) {}
        },
        restoreDraft() {
            try {
                const raw = localStorage.getItem(this.draftKey);
                if (!raw) return;
                const draft = JSON.parse(raw);
                if (draft.entries) Object.assign(this.entries, draft.entries);
                if (draft.position) this.position = Math.max(1, Math.min(draft.position, this.total));
            } catch (e) {}
        },
        clearDraft() {
            try { localStorage.removeItem(this.draftKey); } catch (e) {}
        },

        step(dir) {
            const current = this.entries[this.currentProductId].count ?? 0;
            this.setCount(dir === 'inc' ? current + 1 : Math.max(0, current - 1));
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
        setCount(v) {
            if (!this.currentProductId) return;
            v = (v === '' || v === null || isNaN(v)) ? 0 : Math.max(0, Math.trunc(Number(v)));
            this.entries[this.currentProductId].count = v;
            this.persist();
        },
        clampCount() { this.setCount(this.entries[this.currentProductId]?.count); },

        showToast(message) {
            this.toastMessage = message;
            this.toastVisible = true;
            clearTimeout(this.toastTimer);
            this.toastTimer = setTimeout(() => this.toastVisible = false, 4000);
        },

        // Snapshots whichever entry is about to be left, so a genuine change
        // (not just re-visiting an untouched value) surfaces the undo toast —
        // the same 'skip the noise if nothing changed' rule saveCurrentEntry()
        // used to enforce server-side.
        leavePosition() {
            const id = this.currentProductId;
            if (!id || !this.enterSnapshot) return;
            const after = this.entries[id];
            if (this.enterSnapshot.count !== after.count || this.enterSnapshot.note !== after.note) {
                this.lastUndo = { position: this.position, productId: id, previous: { ...this.enterSnapshot } };
                this.showToast('Saved ' + (after.count ?? 0) + ' — ' + this.product.name);
            }
        },
        arriveAt(pos) {
            this.position = pos;
            this.enterSnapshot = { ...this.entries[this.currentProductId] };
            this.showNote = !!this.entries[this.currentProductId]?.note;
        },
        goTo(pos) {
            if (pos < 1 || pos > this.total) return;
            this.leavePosition();
            this.arriveAt(pos);
            this.showSearch = false;
            this.searchQuery = '';
            this.persist();
        },
        next() { this.goTo(this.position + 1 > this.total ? this.position : this.position + 1); },
        previous() { this.goTo(this.position - 1); },
        markNotFound() {
            if (!this.currentProductId) return;
            this.entries[this.currentProductId] = { count: 0, note: 'Not found' };
            this.persist();
            this.next();
        },
        undo() {
            if (!this.lastUndo) return;
            this.entries[this.lastUndo.productId] = this.lastUndo.previous;
            this.arriveAt(this.lastUndo.position);
            this.lastUndo = null;
            this.toastVisible = false;
            this.persist();
        },
        jumpToBarcode(code) {
            code = (code || '').trim();
            if (!code) return;
            const idx = this.products.findIndex(p => p.barcode === code || p.sku === code);
            if (idx === -1) { this.showToast('No product found: ' + code); return; }
            this.goTo(idx + 1);
        },

        async finish(singlePerson) {
            const message = singlePerson
                ? 'This locks your count and reconciles it against live stock immediately — any mismatch (short or over) goes to a manager for review. This cannot be undone. Are you sure?'
                : 'This will lock your count and cannot be undone. Are you sure?';
            if (!confirm(message)) return;

            this.leavePosition();
            this.submitting = true;

            const payload = {};
            this.products.forEach(p => { payload[p.id] = this.entries[p.id]; });

            try {
                await $wire.call('finishCounting', payload);
                this.clearDraft();
            } finally {
                this.submitting = false;
            }
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
    x-on:barcode-scanned.window="jumpToBarcode($event.detail.barcode)"
    x-on:keydown.window="onKeydown($event)"
>

    {{-- Row 1: exit · position · progress · search — one 48px line that replaces
         the old separate header and progress blocks (~112px before). --}}
    <div class="shrink-0 h-12 flex items-center gap-3 px-3 border-b border-[#1a3a1a]">
        <button wire:click="exitCount"
            aria-label="Exit count and return to dashboard"
            title="Exit count (your progress is kept on this device)"
            class="w-9 h-9 shrink-0 flex items-center justify-center rounded-lg text-[#7a9e7c] hover:text-white hover:bg-[#162016] transition-colors focus:outline-none focus:ring-2 focus:ring-[#4caf50]">
            <x-heroicon-o-x-mark class="w-5 h-5"/>
        </button>

        <div class="flex-1 min-w-0">
            <div class="flex items-baseline justify-between gap-2">
                <span class="text-[#7a9e7c] text-[11px] font-semibold uppercase tracking-wider" x-text="'Item ' + position + ' of ' + total"></span>
            </div>
            <div class="h-1 bg-[#1a3a1a] rounded-full overflow-hidden mt-1">
                <div class="h-full bg-[#4caf50] rounded-full transition-all duration-300"
                    :style="'width: ' + progressPct + '%'"></div>
            </div>
        </div>

        <button @click="showSearch = true"
            aria-label="Jump to a product"
            class="w-9 h-9 shrink-0 flex items-center justify-center rounded-lg text-[#7a9e7c] hover:text-white hover:bg-[#162016] transition-colors focus:outline-none focus:ring-2 focus:ring-[#4caf50]">
            <x-heroicon-o-magnifying-glass class="w-5 h-5"/>
        </button>

        @if($canCancel)
        {{-- Distinct from Exit: exit leaves the session open with the draft
             intact, this ends it so somebody else can start. Behind a
             confirm — it discards counts. --}}
        <button wire:click="cancelSession"
            wire:confirm="Cancel this count session? Every count entered so far is discarded and nothing is written to stock. Anyone eligible can then start a fresh count."
            @click="clearDraft()"
            aria-label="Cancel this count session"
            title="Cancel session — discards all counts"
            class="w-9 h-9 shrink-0 flex items-center justify-center rounded-lg text-[#8a5a5a] hover:text-red-400 hover:bg-red-900/20 transition-colors focus:outline-none focus:ring-2 focus:ring-red-500">
            <x-heroicon-o-trash class="w-5 h-5"/>
        </button>
        @endif
    </div>

    {{-- Row 2: the elastic middle. Stacked when tall, side-by-side when wide. --}}
    <div class="flex-1 min-h-0 flex flex-col landscape:flex-row" x-show="product">

        {{-- Product image — no stock/reorder signal shown here: a blind count must
             never see system stock state while counting. --}}
        <div class="flex-1 min-h-0 min-w-0 p-3 landscape:p-5 flex items-center justify-center">
            <div class="w-full h-full max-w-md landscape:max-w-none bg-[#162016] rounded-xl overflow-hidden flex items-center justify-center">
                <template x-if="product?.image">
                    <img :src="product.image" :alt="product.name"
                        class="max-w-full max-h-full object-contain p-2">
                </template>
                <template x-if="!product?.image">
                    <x-heroicon-o-photo class="w-16 h-16 text-[#2a3a2a]"/>
                </template>
            </div>
        </div>

        {{-- Controls — shrink-0 everywhere so they can never be squeezed. --}}
        <div class="shrink-0 px-4 pb-2 landscape:px-5 landscape:w-[360px] lg:w-[420px] landscape:border-l landscape:border-[#1a3a1a] landscape:flex landscape:flex-col landscape:justify-center landscape:overflow-y-auto">

            {{-- Identity is height-bounded on purpose: the name clamps at two lines
                 (with a hover tooltip for the rest) so a long product name can
                 never wrap indefinitely and eat the image's space. --}}
            <div class="mb-3 landscape:mb-5">
                <p class="text-white font-montserrat font-bold text-base landscape:text-xl leading-tight line-clamp-2"
                    :title="product?.name" x-text="product?.name"></p>
                <p class="text-[#5a7a5c] text-[11px] font-mono mt-0.5 truncate" x-show="product?.sku || product?.barcode">
                    <span x-show="product?.sku" x-text="'SKU ' + product?.sku"></span>
                    <span x-show="product?.sku && product?.barcode"> &middot; </span>
                    <span x-show="product?.barcode" x-text="product?.barcode"></span>
                </p>

                {{-- The shelf is genuinely short by this much, and the system
                     agrees: picked units left stock when they went out. Said
                     here so a counter does not report a shortage for goods
                     sitting in somebody else's shop. --}}
                <p class="mt-2 inline-flex items-center gap-1 rounded-md bg-amber-500/15 px-2 py-1 text-[11px] font-semibold text-amber-300"
                    x-show="product?.out_on_picking > 0"
                    x-text="product?.out_on_picking + (product?.out_on_picking === 1 ? ' more out on picking — do not count it' : ' more out on picking — do not count them')"></p>
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
                        x-model.number="entries[currentProductId].count"
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
                <button @click="if (confirm('Mark this item as not found (count = 0)?')) markNotFound()"
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
                <textarea x-model="entries[currentProductId].note" @input="persist()" rows="2" placeholder="Optional note (e.g. damaged, wrong location)…"
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
            <button x-show="lastUndo" @click="undo()"
                class="text-[#4caf50] text-xs font-bold shrink-0 focus:outline-none focus:ring-2 focus:ring-[#4caf50] rounded px-1">
                UNDO
            </button>
        </div>
    </div>

    {{-- Row 3: action bar, pinned to the bottom for thumb reach --}}
    <div class="shrink-0 bg-[#0d1a0d] border-t border-[#1a3a1a] px-4 py-3 flex items-center gap-3"
        style="padding-bottom: max(0.75rem, env(safe-area-inset-bottom));">
        <button
            x-ref="previousBtn"
            aria-label="Previous item"
            @click="previous()"
            :disabled="position <= 1"
            class="w-14 h-12 shrink-0 bg-[#162016] border border-[#2a3a2a] hover:border-[#4caf50] disabled:opacity-30 disabled:hover:border-[#2a3a2a] text-white rounded-xl flex items-center justify-center transition-colors focus:outline-none focus:ring-2 focus:ring-[#4caf50]">
            ←
        </button>

        <template x-if="isLastProduct">
            <button
                x-ref="primaryActionBtn"
                @click="finish({{ $singlePerson ? 'true' : 'false' }})"
                :disabled="submitting"
                class="flex-1 bg-[#4caf50] hover:bg-[#43a047] disabled:opacity-60 text-white font-bold py-3 rounded-xl transition-colors font-montserrat text-base focus:outline-none focus:ring-2 focus:ring-white focus:ring-offset-2 focus:ring-offset-[#0a140a]">
                <span x-show="!submitting">Review &amp; Finish ✓</span>
                <span x-show="submitting">Submitting…</span>
            </button>
        </template>
        <template x-if="!isLastProduct">
            <button
                x-ref="primaryActionBtn"
                @click="next()"
                class="flex-1 bg-[#e65c00] hover:bg-[#d35400] text-white font-bold py-3 rounded-xl transition-colors font-montserrat text-base flex items-center justify-center gap-2 focus:outline-none focus:ring-2 focus:ring-white focus:ring-offset-2 focus:ring-offset-[#0a140a]">
                Next <span aria-hidden="true">→</span>
            </button>
        </template>
    </div>

    {{-- Search overlay — covers the console instead of expanding inside it, so
         opening it can't push the counter off screen. Jumps to any product in
         the session, not just ones already visited — everything already has a
         value (defaulting to 0) the moment the session starts. --}}
    <div class="absolute inset-0 z-30 bg-[#0a140a] flex flex-col" x-show="showSearch" x-cloak>
        <div class="shrink-0 h-12 flex items-center gap-3 px-3 border-b border-[#1a3a1a]">
            <button @click="showSearch = false; searchQuery = ''"
                aria-label="Close search"
                class="w-9 h-9 shrink-0 flex items-center justify-center rounded-lg text-[#7a9e7c] hover:text-white hover:bg-[#162016] transition-colors focus:outline-none focus:ring-2 focus:ring-[#4caf50]">
                <x-heroicon-o-arrow-left class="w-5 h-5"/>
            </button>
            <span class="text-[#7a9e7c] text-[11px] font-semibold uppercase tracking-wider">Jump to product</span>
        </div>

        <div class="shrink-0 p-3">
            <input type="text" x-model="searchQuery"
                aria-label="Search products"
                placeholder="Search by name or SKU…"
                class="w-full bg-[#162016] border border-[#2a3a2a] text-white rounded-lg px-3 py-2.5 text-sm focus:outline-none focus:border-[#4caf50] placeholder-[#3a5a3c]">
        </div>

        <div class="flex-1 min-h-0 overflow-y-auto px-3 pb-3 space-y-1">
            <template x-for="p in filteredResults" :key="p.id">
                <button @click="goTo(products.findIndex(x => x.id === p.id) + 1)"
                    class="w-full text-left flex items-center justify-between gap-2 px-3 py-2.5 bg-[#162016] hover:bg-[#1a2a1a] rounded-lg transition-colors focus:outline-none focus:ring-2 focus:ring-[#4caf50]">
                    <span class="text-white text-xs truncate" x-text="p.name"></span>
                    <span class="text-[#4caf50] text-xs font-bold shrink-0" x-text="entries[p.id]?.count ?? 0"></span>
                </button>
            </template>
            <p class="text-[#3a5a3c] text-xs px-1 py-2" x-show="searchQuery.trim() && filteredResults.length === 0">No matching product.</p>
            <p class="text-[#3a5a3c] text-xs px-1 py-2" x-show="!searchQuery.trim()">Start typing a name or SKU.</p>
        </div>
    </div>
</div>
@endif

</div>
</div>
</x-filament-panels::page>

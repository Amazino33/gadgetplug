<x-filament-panels::page>
@php
    $data   = $this->deliveryData();
    $stores = $this->stores();
    $lgasByState = collect($data['lgas'])->groupBy('state_id');
    $modeLabel = fn ($m) => ['free' => 'Free', 'fee' => 'Fee', 'not_delivered' => 'Not delivered'][$m] ?? $m;
    $money = fn ($v) => $v === null ? '—' : '₦'.number_format((float) $v);
@endphp

{{-- Leaflet, pinned. OpenStreetMap tiles: free, no key, attribution shown,
     and loaded only as the map is viewed — never pre-fetched in bulk. --}}
<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css"
      integrity="sha256-p4NxAoJBhIIN+hmNHrzRCf9tD/miZyoHS5obTRR9BMY=" crossorigin="">
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"
        integrity="sha256-20nQCchB9co0qIjJZRGuk2/Z9VM+kNiyxNV1lvTlZBo=" crossorigin=""></script>

<script>
    window.deliveryMap = function (initial) {
        const COLORS = { free: '#16a34a', fee: '#d97706', not_delivered: '#9ca3af' };
        const RINGS_KM = [10, 25, 50, 100];

        return {
            data: initial,
            stateFilter: '',
            onlyDelivered: false,
            showRings: false,
            placingStoreId: '',
            map: null,
            lgaLayer: null,
            hubLayer: null,
            ringLayer: null,
            line: null,

            init() {
                this.map = L.map(this.$refs.map, { preferCanvas: true }).setView([9.08, 8.68], 6);
                L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                    maxZoom: 18,
                    attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors',
                }).addTo(this.map);

                this.lgaLayer = L.layerGroup().addTo(this.map);
                this.hubLayer = L.layerGroup().addTo(this.map);
                this.ringLayer = L.layerGroup();

                this.map.on('click', (e) => {
                    if (!this.placingStoreId) return;
                    this.$wire.setHub(Number(this.placingStoreId), e.latlng.lat.toFixed(6), e.latlng.lng.toFixed(6));
                    this.placingStoreId = '';
                });

                this.draw();
                this.$watch('stateFilter', () => { this.draw(); this.zoomToState(); });
                this.$watch('onlyDelivered', () => this.draw());
                this.$watch('showRings', () => this.drawRings());
            },

            refresh(data) {
                this.data = data;
                this.draw();
            },

            hub(id) {
                return this.data.hubs.find((h) => h.id === id);
            },

            label(l) {
                if (l.mode === 'free') return 'Free';
                if (l.mode === 'fee') return '₦' + Number(l.fee).toLocaleString();
                return 'Not delivered';
            },

            draw() {
                this.lgaLayer.clearLayers();
                this.hubLayer.clearLayers();

                this.data.lgas
                    .filter((l) => !this.stateFilter || l.state_id === Number(this.stateFilter))
                    .filter((l) => !this.onlyDelivered || l.mode !== 'not_delivered')
                    .forEach((l) => {
                        const marker = L.circleMarker([l.lat, l.lng], {
                            radius: 6, weight: 1, color: '#1f2937',
                            fillColor: COLORS[l.mode], fillOpacity: 0.85,
                        });
                        marker.bindTooltip(l.name + ' — ' + this.label(l));
                        marker.bindPopup(() => this.popup(l), { minWidth: 230 });
                        marker.on('popupopen', () => this.lineTo(l));
                        marker.on('popupclose', () => this.clearLine());
                        marker.addTo(this.lgaLayer);
                    });

                this.data.hubs.forEach((h) => {
                    L.marker([h.lat, h.lng], { title: h.name }).bindPopup(() => {
                        const el = document.createElement('div');
                        el.innerHTML = '<strong></strong><br><span class="text-xs">Delivery hub</span>';
                        el.querySelector('strong').textContent = h.name;
                        return el;
                    }).addTo(this.hubLayer);
                });

                this.drawRings();
            },

            drawRings() {
                this.ringLayer.clearLayers();
                if (!this.showRings) { this.map.removeLayer(this.ringLayer); return; }
                this.data.hubs.forEach((h) => RINGS_KM.forEach((km) => {
                    L.circle([h.lat, h.lng], { radius: km * 1000, color: '#2563eb', weight: 1, dashArray: '4 6', fill: false })
                        .bindTooltip(km + ' km (straight-line)').addTo(this.ringLayer);
                }));
                this.ringLayer.addTo(this.map);
            },

            zoomToState() {
                const points = this.data.lgas.filter((l) => l.state_id === Number(this.stateFilter)).map((l) => [l.lat, l.lng]);
                if (points.length) this.map.fitBounds(points, { padding: [30, 30] });
                else this.map.setView([9.08, 8.68], 6);
            },

            lineTo(l) {
                this.clearLine();
                const h = this.hub(l.hub_id);
                if (h) this.line = L.polyline([[h.lat, h.lng], [l.lat, l.lng]], { color: '#2563eb', weight: 2 }).addTo(this.map);
            },

            clearLine() {
                if (this.line) { this.map.removeLayer(this.line); this.line = null; }
            },

            // Built with DOM nodes, not HTML strings, so no name can inject markup.
            popup(l) {
                const state = this.data.states.find((s) => s.id === l.state_id);
                const hub = this.hub(l.hub_id);
                const el = document.createElement('div');
                el.className = 'space-y-1 text-xs';

                const line = (text, bold) => {
                    const p = document.createElement('div');
                    p.textContent = text;
                    if (bold) p.style.fontWeight = '700';
                    el.appendChild(p);
                };

                line(l.name + ', ' + (state ? state.name : ''), true);
                line(l.km === null ? 'No hub pinned yet' : l.km + ' km straight-line from ' + (hub ? hub.name : 'nearest hub') + ' (road is longer)');
                line('Now: ' + this.label(l) + (l.source === 'lga' ? ' — LGA override' : ' — state default'));

                const form = document.createElement('form');
                form.className = 'mt-2 space-y-1';
                form.innerHTML = `
                    <select name="mode" class="w-full rounded border px-1 py-0.5">
                        <option value="free">Free</option><option value="fee">Fee</option><option value="not_delivered">Not delivered</option>
                    </select>
                    <input name="fee" type="number" min="0" step="50" placeholder="Fee ₦" class="w-full rounded border px-1 py-0.5">
                    <input name="expected_cost" type="number" min="0" step="50" placeholder="Expected cost to us ₦" class="w-full rounded border px-1 py-0.5">
                    <input name="delivery_days" maxlength="60" placeholder="Days, e.g. 2–4 days" class="w-full rounded border px-1 py-0.5">
                    <div class="flex gap-1 pt-1">
                        <button type="submit" class="rounded bg-amber-600 px-2 py-1 text-white">Save for this LGA</button>
                    </div>`;
                form.mode.value = l.mode;
                form.fee.value = l.mode === 'fee' ? l.fee : '';
                form.expected_cost.value = l.expected_cost ?? '';
                form.delivery_days.value = l.days ?? '';
                form.addEventListener('submit', (e) => {
                    e.preventDefault();
                    this.$wire.saveLgaRule(l.id, {
                        mode: form.mode.value,
                        fee: form.fee.value || null,
                        expected_cost: form.expected_cost.value || null,
                        delivery_days: form.delivery_days.value || null,
                    });
                    this.map.closePopup();
                });

                if (l.source === 'lga') {
                    const clear = document.createElement('button');
                    clear.type = 'button';
                    clear.className = 'rounded border px-2 py-1';
                    clear.textContent = 'Use state default';
                    clear.addEventListener('click', () => { this.$wire.clearOverrides([l.id]); this.map.closePopup(); });
                    form.lastElementChild.appendChild(clear);
                }

                el.appendChild(form);
                return el;
            },
        };
    };
</script>

{{-- ── Map ─────────────────────────────────────────────────────────────── --}}
<div x-data="deliveryMap(@js($data))" x-on:delivery-data-updated.window="refresh($event.detail.data)"
     class="space-y-3">

    @if (empty($data['hubs']))
        <div role="alert" class="rounded-xl border border-amber-300 bg-amber-50 px-4 py-3 text-sm text-amber-800 dark:border-amber-700 dark:bg-amber-900/20 dark:text-amber-300">
            No store has a location yet, so distances can't be worked out. Pin at least one store below — choose it, then tap its spot on the map.
        </div>
    @endif

    <div class="flex flex-wrap items-end gap-3 text-sm">
        <label class="flex flex-col">
            <span class="text-xs text-gray-500">State</span>
            <select x-model="stateFilter" class="rounded-lg border-gray-300 text-sm dark:bg-gray-900 dark:border-white/10">
                <option value="">All of Nigeria</option>
                @foreach ($data['states'] as $state)
                    <option value="{{ $state['id'] }}">{{ $state['name'] }}</option>
                @endforeach
            </select>
        </label>
        <label class="flex items-center gap-2"><input type="checkbox" x-model="onlyDelivered" class="rounded"> Only areas we deliver to</label>
        <label class="flex items-center gap-2"><input type="checkbox" x-model="showRings" class="rounded"> Distance rings (10/25/50/100 km)</label>
        <label class="flex flex-col">
            <span class="text-xs text-gray-500">Pin a store as a hub</span>
            <select x-model="placingStoreId" class="rounded-lg border-gray-300 text-sm dark:bg-gray-900 dark:border-white/10">
                <option value="">— choose a store, then tap the map —</option>
                @foreach ($stores as $store)
                    <option value="{{ $store['id'] }}">{{ $store['label'] }}{{ $store['lat'] !== null ? ' (pinned)' : '' }}</option>
                @endforeach
            </select>
        </label>
        <span x-show="placingStoreId" class="text-xs font-semibold text-amber-700">Tap the map where this store is.</span>
    </div>

    <div class="flex flex-wrap gap-4 text-xs text-gray-600 dark:text-gray-400">
        <span><span class="inline-block h-3 w-3 rounded-full align-middle" style="background:#16a34a"></span> Free</span>
        <span><span class="inline-block h-3 w-3 rounded-full align-middle" style="background:#d97706"></span> Fee</span>
        <span><span class="inline-block h-3 w-3 rounded-full align-middle" style="background:#9ca3af"></span> Not delivered</span>
        <span>Distances are straight-line ("as the crow flies") from the nearest store; road distance is longer.</span>
    </div>

    <div wire:ignore x-ref="map" class="h-[60vh] min-h-[320px] w-full rounded-xl ring-1 ring-gray-950/5 dark:ring-white/10"></div>
</div>

{{-- ── Hubs, by typed coordinates ──────────────────────────────────────── --}}
<x-filament::section collapsible collapsed heading="Store locations (hubs)" description="Or type coordinates. A store with a location is a delivery hub.">
    <div class="space-y-2">
        @foreach ($stores as $store)
            <form wire:key="hub-{{ $store['id'] }}" class="flex flex-wrap items-center gap-2 text-sm"
                  x-data="{ lat: @js($store['lat']), lng: @js($store['lng']) }"
                  x-on:submit.prevent="$wire.setHub({{ $store['id'] }}, lat || null, lng || null)">
                <span class="min-w-[12rem] grow">{{ $store['label'] }}</span>
                <input x-model="lat" type="number" step="0.000001" placeholder="Latitude" class="w-32 rounded-lg border-gray-300 text-sm dark:bg-gray-900 dark:border-white/10">
                <input x-model="lng" type="number" step="0.000001" placeholder="Longitude" class="w-32 rounded-lg border-gray-300 text-sm dark:bg-gray-900 dark:border-white/10">
                <x-filament::button type="submit" size="sm" color="gray">Save</x-filament::button>
            </form>
        @endforeach
    </div>
</x-filament::section>

{{-- ── Table: state → LGAs, with bulk editing ──────────────────────────── --}}
<x-filament::section heading="Rules by state and LGA" description="A state's default applies to all its LGAs; an LGA override beats it.">
    <div x-data="{ selected: [], bulk: { mode: 'fee', fee: null, expected_cost: null, delivery_days: null } }" class="space-y-3">

        <div class="sticky top-0 z-10 flex flex-wrap items-end gap-2 rounded-lg bg-gray-50 p-2 text-sm dark:bg-white/5" x-show="selected.length" x-cloak>
            <span class="font-semibold" x-text="selected.length + ' LGA(s) selected'"></span>
            <select x-model="bulk.mode" class="rounded-lg border-gray-300 text-sm dark:bg-gray-900 dark:border-white/10">
                <option value="free">Free</option><option value="fee">Fee</option><option value="not_delivered">Not delivered</option>
            </select>
            <input x-model="bulk.fee" type="number" min="0" step="50" placeholder="Fee ₦" class="w-24 rounded-lg border-gray-300 text-sm dark:bg-gray-900 dark:border-white/10">
            <input x-model="bulk.expected_cost" type="number" min="0" step="50" placeholder="Cost ₦" class="w-24 rounded-lg border-gray-300 text-sm dark:bg-gray-900 dark:border-white/10">
            <input x-model="bulk.delivery_days" maxlength="60" placeholder="Days" class="w-28 rounded-lg border-gray-300 text-sm dark:bg-gray-900 dark:border-white/10">
            <x-filament::button size="sm" x-on:click="$wire.bulkSetLgaRules(selected, bulk).then(() => selected = [])">Set for selected</x-filament::button>
            <x-filament::button size="sm" color="gray" x-on:click="$wire.clearOverrides(selected).then(() => selected = [])">Clear overrides</x-filament::button>
        </div>

        @foreach ($data['states'] as $state)
            @php $stateLgas = $lgasByState->get($state['id'], collect()); @endphp
            <details wire:key="state-{{ $state['id'] }}" class="rounded-lg ring-1 ring-gray-950/5 dark:ring-white/10">
                <summary class="flex cursor-pointer flex-wrap items-center gap-x-4 gap-y-1 px-3 py-2 text-sm">
                    <span class="font-semibold">{{ $state['name'] }}</span>
                    <span class="text-gray-500">Default: {{ $modeLabel($state['mode']) }}{{ $state['mode'] === 'fee' ? ' '.$money($state['fee']) : '' }}</span>
                    <span class="text-gray-500">{{ $stateLgas->where('source', 'lga')->count() }} override(s) · {{ $stateLgas->where('mode', '!=', 'not_delivered')->count() }}/{{ $stateLgas->count() }} delivered</span>
                </summary>

                <div class="space-y-2 border-t border-gray-100 p-3 dark:border-white/5">
                    <form class="flex flex-wrap items-end gap-2 text-sm"
                          x-data="{ r: { mode: @js($state['mode']), fee: @js($state['mode'] === 'fee' ? $state['fee'] : null), expected_cost: @js($state['expected_cost']), delivery_days: @js($state['days']) } }"
                          x-on:submit.prevent="$wire.saveStateRule({{ $state['id'] }}, r)">
                        <span class="text-xs font-semibold text-gray-500">State default:</span>
                        <select x-model="r.mode" class="rounded-lg border-gray-300 text-sm dark:bg-gray-900 dark:border-white/10">
                            <option value="free">Free</option><option value="fee">Fee</option><option value="not_delivered">Not delivered</option>
                        </select>
                        <input x-model="r.fee" type="number" min="0" step="50" placeholder="Fee ₦" class="w-24 rounded-lg border-gray-300 text-sm dark:bg-gray-900 dark:border-white/10">
                        <input x-model="r.expected_cost" type="number" min="0" step="50" placeholder="Cost ₦" class="w-24 rounded-lg border-gray-300 text-sm dark:bg-gray-900 dark:border-white/10">
                        <input x-model="r.delivery_days" maxlength="60" placeholder="Days" class="w-28 rounded-lg border-gray-300 text-sm dark:bg-gray-900 dark:border-white/10">
                        <x-filament::button type="submit" size="sm" color="gray">Save default</x-filament::button>
                    </form>

                    <div class="overflow-x-auto">
                        <table class="w-full text-left text-sm">
                            <thead class="text-xs text-gray-500">
                                <tr>
                                    <th class="w-8"></th><th>LGA</th><th>Distance*</th><th>Rule</th><th>Fee</th><th>Expected cost</th><th>Days</th><th>Override</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($stateLgas as $lga)
                                    <tr wire:key="lga-{{ $lga['id'] }}" class="border-t border-gray-100 dark:border-white/5">
                                        <td><input type="checkbox" value="{{ $lga['id'] }}" x-model.number="selected" class="rounded"></td>
                                        <td>{{ $lga['name'] }}</td>
                                        <td>{{ $lga['km'] === null ? '—' : $lga['km'].' km' }}</td>
                                        <td>{{ $modeLabel($lga['mode']) }}</td>
                                        <td>{{ $lga['mode'] === 'fee' ? $money($lga['fee']) : '—' }}</td>
                                        <td>{{ $money($lga['expected_cost']) }}</td>
                                        <td>{{ $lga['days'] ?? '—' }}</td>
                                        <td>{{ $lga['source'] === 'lga' ? 'Yes' : 'No' }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                        <p class="mt-1 text-xs text-gray-500">* Straight-line from the nearest store; road distance is longer.</p>
                    </div>
                </div>
            </details>
        @endforeach
    </div>
</x-filament::section>

{{-- ── Price by distance ──────────────────────────────────────────────── --}}
<x-filament::section heading="Price by distance" description="Set bands, preview what would change, then apply. Applying writes ordinary LGA overrides you can still edit one by one — prices never move on their own.">
    <div class="space-y-3 text-sm">
        @foreach ($bands as $i => $band)
            <div wire:key="band-{{ $i }}" class="flex flex-wrap items-end gap-2">
                <label class="flex flex-col"><span class="text-xs text-gray-500">From km</span>
                    <input wire:model="bands.{{ $i }}.from" type="number" min="0" class="w-24 rounded-lg border-gray-300 text-sm dark:bg-gray-900 dark:border-white/10"></label>
                <label class="flex flex-col"><span class="text-xs text-gray-500">To km (blank = beyond)</span>
                    <input wire:model="bands.{{ $i }}.to" type="number" min="0" class="w-28 rounded-lg border-gray-300 text-sm dark:bg-gray-900 dark:border-white/10"></label>
                <label class="flex flex-col"><span class="text-xs text-gray-500">Rule</span>
                    <select wire:model="bands.{{ $i }}.mode" class="rounded-lg border-gray-300 text-sm dark:bg-gray-900 dark:border-white/10">
                        <option value="free">Free</option><option value="fee">Fee</option><option value="not_delivered">Not delivered</option>
                    </select></label>
                <label class="flex flex-col"><span class="text-xs text-gray-500">Fee ₦</span>
                    <input wire:model="bands.{{ $i }}.fee" type="number" min="0" step="50" class="w-24 rounded-lg border-gray-300 text-sm dark:bg-gray-900 dark:border-white/10"></label>
                <x-filament::button size="sm" color="gray" wire:click="removeBand({{ $i }})">Remove</x-filament::button>
            </div>
        @endforeach

        <div class="flex flex-wrap items-end gap-2">
            <x-filament::button size="sm" color="gray" wire:click="addBand">Add band</x-filament::button>
            <label class="flex flex-col"><span class="text-xs text-gray-500">Apply to</span>
                <select wire:model="bandScopeStateId" class="rounded-lg border-gray-300 text-sm dark:bg-gray-900 dark:border-white/10">
                    <option value="">All states</option>
                    @foreach ($data['states'] as $state)
                        <option value="{{ $state['id'] }}">{{ $state['name'] }}</option>
                    @endforeach
                </select></label>
            <x-filament::button size="sm" wire:click="previewBands">Preview</x-filament::button>
        </div>

        @if ($bandPreview !== [])
            <div class="rounded-lg ring-1 ring-gray-950/5 dark:ring-white/10">
                <div class="flex items-center justify-between px-3 py-2">
                    <span class="font-semibold">{{ count($bandPreview) }} LGA(s) would change</span>
                    <x-filament::button size="sm" color="warning" wire:click="applyBands" wire:confirm="Write these as LGA overrides?">Apply</x-filament::button>
                </div>
                <div class="max-h-80 overflow-auto">
                    <table class="w-full text-left text-sm">
                        <thead class="text-xs text-gray-500"><tr><th class="px-3">LGA</th><th>State</th><th>Distance*</th><th>Now</th><th>After</th></tr></thead>
                        <tbody>
                            @foreach ($bandPreview as $row)
                                <tr class="border-t border-gray-100 dark:border-white/5">
                                    <td class="px-3">{{ $row['lga'] }}</td>
                                    <td>{{ $row['state'] }}</td>
                                    <td>{{ $row['km'] }} km</td>
                                    <td>{{ $modeLabel($row['from_mode']) }}{{ $row['from_mode'] === 'fee' ? ' '.$money($row['from_fee']) : '' }}</td>
                                    <td>{{ $modeLabel($row['to_mode']) }}{{ $row['to_mode'] === 'fee' ? ' '.$money($row['to_fee']) : '' }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        @endif
    </div>
</x-filament::section>
</x-filament-panels::page>

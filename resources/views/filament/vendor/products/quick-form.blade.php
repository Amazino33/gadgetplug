{{--
    The product pop-up: New product, and the pencil on every row.

    All of it — Advanced settings included — is on the page from the first
    load and runs in the browser (resources/js/product-quick-form.js). The
    server is asked once, on Save. wire:ignore keeps the list's re-renders
    from resetting a form someone is halfway through.
--}}
@php
    $quickOptions = $this->getQuickFormOptions();

    $input = 'block w-full rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm text-gray-950 shadow-sm outline-none transition focus:border-primary-500 focus:ring-2 focus:ring-primary-500/30 disabled:cursor-not-allowed disabled:bg-gray-50 disabled:text-gray-500 read-only:bg-gray-50 dark:border-white/10 dark:bg-white/5 dark:text-white dark:read-only:bg-white/5';
    $money = $input.' pl-7 tabular-nums';
    $label = 'mb-1 block text-sm font-medium text-gray-950 dark:text-white';
    $hint = 'mt-1 text-xs text-gray-500 dark:text-gray-400';
    $error = 'mt-1 text-xs font-medium text-danger-600 dark:text-danger-400';
    $warn = 'mt-1 text-xs font-medium text-warning-600 dark:text-warning-400';
    $naira = 'pointer-events-none absolute inset-y-0 left-0 flex items-center pl-3 text-sm text-gray-400';
    $iconBtn = 'flex h-9 w-9 shrink-0 items-center justify-center rounded-lg border border-gray-300 text-gray-500 transition hover:bg-gray-50 hover:text-gray-700 dark:border-white/10 dark:hover:bg-white/5 dark:hover:text-white';
    $addBtn = 'inline-flex items-center gap-1.5 rounded-lg border border-dashed border-gray-300 px-3 py-2 text-sm font-semibold text-primary-600 transition hover:border-primary-400 hover:bg-primary-50 dark:border-white/15 dark:text-primary-400 dark:hover:bg-primary-950/30';
    $toggle = 'fi-checkbox-input shrink-0';
    $tabs = [
        'units' => ['Selling units', 'heroicon-o-scale'],
        'barcodes' => ['Barcodes', 'heroicon-o-qr-code'],
        'photos' => ['Photos', 'heroicon-o-photo'],
        'details' => ['Details', 'heroicon-o-document-text'],
        'stock' => ['Stock rules', 'heroicon-o-archive-box'],
        'pricing' => ['Pricing rules', 'heroicon-o-banknotes'],
        'channels' => ['Channels & schedule', 'heroicon-o-globe-alt'],
    ];
@endphp

<div wire:ignore x-data="productQuickForm(@js($quickOptions))" x-on:keydown.escape.window="if (open) { scanTarget ? (scanTarget = null) : close() }">
    <div x-show="open" x-cloak
        class="fixed inset-0 z-[100] flex items-stretch justify-center sm:items-start sm:p-4 sm:pt-[3vh]"
        role="dialog" aria-modal="true" aria-labelledby="pq-title">
        <div class="absolute inset-0 bg-gray-950/50 backdrop-blur-[1px]" x-show="open" x-transition.opacity x-on:click="close()"></div>

        <form x-show="open" x-transition:enter="transition duration-150 ease-out" x-transition:enter-start="opacity-0 translate-y-2" x-transition:enter-end="opacity-100 translate-y-0"
            x-on:submit.prevent="save()"
            x-on:keydown.enter="if (['INPUT', 'SELECT'].includes($event.target.tagName) && !$event.ctrlKey && !$event.metaKey) $event.preventDefault()"
            x-on:keydown.ctrl.enter.prevent="save()" x-on:keydown.meta.enter.prevent="save()"
            x-on:keydown.ctrl.s.prevent="save()" x-on:keydown.meta.s.prevent="save()"
            class="relative flex max-h-full w-full max-w-5xl flex-col overflow-hidden bg-white shadow-2xl ring-1 ring-gray-950/5 sm:max-h-[94vh] sm:rounded-2xl dark:bg-gray-900 dark:ring-white/10">

            {{-- Header --}}
            <div class="flex shrink-0 items-center gap-3 border-b border-gray-200 px-4 py-3 sm:px-6 dark:border-white/10">
                <div class="min-w-0 flex-1">
                    <h2 id="pq-title" class="truncate text-lg font-bold text-gray-950 dark:text-white"
                        x-text="mode === 'edit' ? (f.name ? 'Edit · ' + f.name : 'Edit product') : 'New product'"></h2>
                    <p class="truncate text-xs text-gray-500 dark:text-gray-400" x-show="mode !== 'limit'">
                        <span x-show="mode === 'edit' && f.sku" class="font-mono" x-text="f.sku"></span>
                        <span x-show="mode === 'create'">Saved to the server only when you press Save.</span>
                    </p>
                </div>
                <a x-show="mode === 'edit' && editUrl" :href="editUrl"
                    class="hidden items-center gap-1 rounded-lg px-2.5 py-1.5 text-xs font-semibold text-gray-600 hover:bg-gray-100 sm:inline-flex dark:text-gray-300 dark:hover:bg-white/5"
                    title="Enhance photos with AI, archive, or delete">
                    Open full page
                    <x-heroicon-m-arrow-top-right-on-square class="h-3.5 w-3.5"/>
                </a>
                <button type="button" x-on:click="close()" class="{{ $iconBtn }} border-transparent" aria-label="Close">
                    <x-heroicon-o-x-mark class="h-5 w-5"/>
                </button>
            </div>

            {{-- Body --}}
            <div class="relative min-h-0 flex-1 overflow-y-auto px-4 py-4 sm:px-6 sm:py-5">

                {{-- Free plan at its product cap --}}
                <div x-show="mode === 'limit'" class="rounded-xl bg-warning-50 p-4 text-sm text-warning-800 dark:bg-warning-950/40 dark:text-warning-200">
                    <p class="font-semibold" x-text="opts.limitMessage"></p>
                </div>

                {{-- Loading a product to edit --}}
                <div x-show="loading" class="absolute inset-0 z-10 flex items-center justify-center bg-white/70 dark:bg-gray-900/70">
                    <div class="flex items-center gap-2 text-sm font-medium text-gray-600 dark:text-gray-300">
                        <x-filament::loading-indicator class="h-5 w-5"/> Opening product…
                    </div>
                </div>
                <div x-show="loadError" class="mb-4 rounded-xl bg-danger-50 p-4 text-sm font-medium text-danger-700 dark:bg-danger-950/40 dark:text-danger-300" x-text="loadError"></div>

                <template x-if="mode !== 'limit' && !loadError">
                <div class="space-y-5">

                    {{-- What went wrong, all in one place --}}
                    <div x-ref="errorSummary" x-show="errorCount > 0" class="rounded-xl border border-danger-200 bg-danger-50 px-4 py-3 text-sm text-danger-700 dark:border-danger-900 dark:bg-danger-950/40 dark:text-danger-300">
                        <p class="font-semibold" x-text="errorCount === 1 ? 'Fix this, then save again:' : 'Fix these ' + errorCount + ' things, then save again:'"></p>
                        <ul class="mt-1 list-disc space-y-0.5 pl-5">
                            <template x-for="(messages, field) in errors" :key="field">
                                <li x-text="messages[0]"></li>
                            </template>
                        </ul>
                    </div>

                    {{-- ── THE PRODUCT ─────────────────────────────── --}}
                    <div class="grid grid-cols-1 gap-4 md:grid-cols-3">
                        <div class="md:col-span-2" data-tour="product-name" :data-error="!!err('name')">
                            <label for="pq-name" class="{{ $label }}">Product name <span class="text-danger-600">*</span></label>
                            <input id="pq-name" x-ref="name" type="text" x-model="f.name" x-on:blur="refreshSku()" autocomplete="off"
                                placeholder="What your staff would type at the till" class="{{ $input }}" maxlength="255">
                            <p class="{{ $error }}" x-show="err('name')" x-text="err('name')"></p>
                        </div>

                        <div :data-error="!!err('sku')">
                            <div class="mb-1 flex items-center justify-between gap-2">
                                <label for="pq-sku" class="text-sm font-medium text-gray-950 dark:text-white">SKU<span x-show="opts.skuManual" class="text-danger-600"> *</span></label>
                                <span class="truncate text-xs text-gray-400" x-text="opts.skuHint"></span>
                            </div>
                            <div class="flex gap-1.5">
                                <input id="pq-sku" type="text" x-model="f.sku" :readonly="skuLocked" maxlength="100"
                                    :placeholder="opts.skuManual ? 'Enter a SKU' : 'Generated when you save'" class="{{ $input }} font-mono">
                                <template x-if="!opts.skuManual">
                                    <div class="flex gap-1.5">
                                        <button type="button" class="{{ $iconBtn }}" title="Regenerate" aria-label="Regenerate SKU" x-on:click="refreshSku(true)">
                                            <x-heroicon-m-arrow-path class="h-4 w-4"/>
                                        </button>
                                        <button type="button" class="{{ $iconBtn }}" :class="f.sku_manual && 'border-primary-500 text-primary-600'" title="Edit manually" aria-label="Edit SKU manually"
                                            x-on:click="f.sku_manual = !f.sku_manual; f.sku_manual && $nextTick(() => document.getElementById('pq-sku').focus())">
                                            <x-heroicon-m-pencil-square class="h-4 w-4"/>
                                        </button>
                                    </div>
                                </template>
                            </div>
                            <p class="{{ $error }}" x-show="err('sku')" x-text="err('sku')"></p>
                        </div>
                    </div>

                    <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
                        <div :data-error="!!err('category_id')">
                            <label for="pq-category" class="{{ $label }}">Category <span class="text-danger-600">*</span></label>
                            <select id="pq-category" x-model="f.category_id" x-on:change="refreshSku()" class="{{ $input }}">
                                <option value="">Choose a category…</option>
                                <template x-for="c in opts.categories" :key="c.id">
                                    <option :value="String(c.id)" x-text="c.name" :selected="String(c.id) === f.category_id"></option>
                                </template>
                            </select>
                            <p class="{{ $error }}" x-show="err('category_id')" x-text="err('category_id')"></p>
                        </div>

                        <div x-show="opts.canChooseStore" :data-error="!!err('store_id')">
                            <label for="pq-store" class="{{ $label }}">Home store <span class="text-danger-600">*</span></label>
                            <select id="pq-store" x-model="f.store_id" class="{{ $input }}">
                                <template x-for="s in opts.stores" :key="s.id">
                                    <option :value="String(s.id)" x-text="s.name" :selected="String(s.id) === f.store_id"></option>
                                </template>
                            </select>
                            <p class="{{ $hint }}">Which branch stocks this. Moving it later moves its stock too.</p>
                            <p class="{{ $error }}" x-show="err('store_id')" x-text="err('store_id')"></p>
                        </div>
                    </div>

                    <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
                        <div x-show="opts.canSeeCost" :data-error="!!err('cost_price')">
                            <label for="pq-cost" class="{{ $label }}" x-text="'Cost price / ' + unitWord"></label>
                            <div class="relative">
                                <span class="{{ $naira }}">₦</span>
                                <input id="pq-cost" type="number" step="any" min="0" inputmode="decimal" x-model="f.cost_price" placeholder="Leave blank if unknown" class="{{ $money }}">
                            </div>
                            <p class="{{ $hint }}">What you pay. Optional — shown as "—" in reports until set.</p>
                            <p class="{{ $error }}" x-show="err('cost_price')" x-text="err('cost_price')"></p>
                        </div>

                        <div data-tour="product-cost-price" :data-error="!!err('price')">
                            <label for="pq-price" class="{{ $label }}"><span x-text="'Selling price / ' + unitWord"></span> <span class="text-danger-600">*</span></label>
                            <div class="flex gap-1.5">
                                <div class="relative flex-1">
                                    <span class="{{ $naira }}">₦</span>
                                    <input id="pq-price" type="number" step="any" min="0" inputmode="decimal" x-model="f.price" :disabled="priceLocked" class="{{ $money }}">
                                </div>
                                <button type="button" class="{{ $iconBtn }} w-auto gap-1 px-2.5 text-xs font-semibold" title="Add a selling unit, e.g. a crate" x-on:click="addUnit(true)">
                                    <x-heroicon-m-plus class="h-4 w-4"/> Unit
                                </button>
                            </div>
                            <p class="{{ $hint }}" x-text="priceLocked ? 'Calculated automatically. Turn on “Override linked price” under Pricing rules to change it.' : 'What the customer pays.'"></p>
                            <p class="{{ $error }}" x-show="err('price')" x-text="err('price')"></p>
                        </div>
                    </div>

                    {{-- Profit per unit, worked out as you type --}}
                    <div x-show="opts.canSeeCost">
                        <p x-show="chips.note" class="text-xs text-gray-400 dark:text-gray-500" x-text="chips.note"></p>
                        <div x-show="chips.list" class="flex flex-wrap gap-2">
                            <template x-for="chip in (chips.list ?? [])" :key="chip.name">
                                <div class="rounded-lg border px-3 py-1.5 text-sm"
                                    :class="chip.loss ? 'border-danger-300 bg-danger-50 text-danger-700 dark:border-danger-500/40 dark:bg-danger-950 dark:text-danger-300' : 'border-gray-200 bg-gray-50 text-gray-900 dark:border-white/10 dark:bg-gray-800 dark:text-white'">
                                    <span class="font-medium" x-text="chip.name"></span>
                                    <span class="font-bold" x-text="chip.profit"></span>
                                    <span class="text-xs opacity-75" x-text="chip.margin"></span>
                                </div>
                            </template>
                        </div>
                    </div>

                    <div class="flex flex-wrap items-end gap-x-8 gap-y-4">
                        <div x-show="currentStatus !== 'archived'" data-tour="product-status">
                            <span class="{{ $label }}">Availability</span>
                            <div class="inline-flex overflow-hidden rounded-lg border border-gray-300 dark:border-white/10" role="radiogroup" aria-label="Availability">
                                <button type="button" role="radio" :aria-checked="f.status === 'draft'" x-on:click="f.status = 'draft'"
                                    class="inline-flex items-center gap-1.5 px-3 py-2 text-sm font-semibold transition"
                                    :class="f.status === 'draft' ? 'bg-gray-700 text-white dark:bg-gray-600' : 'text-gray-600 hover:bg-gray-50 dark:text-gray-300 dark:hover:bg-white/5'">
                                    <x-heroicon-o-pencil-square class="h-4 w-4"/> Draft
                                </button>
                                <button type="button" role="radio" :aria-checked="f.status === 'published'" x-on:click="f.status = 'published'"
                                    class="inline-flex items-center gap-1.5 border-l border-gray-300 px-3 py-2 text-sm font-semibold transition dark:border-white/10"
                                    :class="f.status === 'published' ? 'bg-success-600 text-white' : 'text-gray-600 hover:bg-gray-50 dark:text-gray-300 dark:hover:bg-white/5'">
                                    <x-heroicon-o-check-circle class="h-4 w-4"/> Published
                                </button>
                            </div>
                        </div>
                        <p x-show="currentStatus === 'archived'" class="text-xs text-gray-500 dark:text-gray-400">
                            This product is archived — hidden from the storefront and the till. Use <span class="font-semibold">Open full page → Restore</span> to bring it back.
                        </p>

                        <label x-show="opts.freePlan" class="flex cursor-pointer items-start gap-3">
                            <input type="checkbox" x-model="f.shop_sold_out" class="{{ $toggle }} mt-0.5">
                            <span>
                                <span class="block text-sm font-medium text-gray-950 dark:text-white">Sold out</span>
                                <span class="{{ $hint }} block">Customers still see it on your shop page, marked Sold out, but cannot order it.</span>
                            </span>
                        </label>
                    </div>

                    {{-- ── ADVANCED SETTINGS ───────────────────────── --}}
                    <div class="rounded-xl border border-gray-200 dark:border-white/10">
                        <button type="button" x-on:click="advanced = !advanced" :aria-expanded="advanced"
                            class="flex w-full items-center gap-3 px-4 py-3 text-left">
                            <x-heroicon-o-adjustments-horizontal class="h-5 w-5 shrink-0 text-gray-400"/>
                            <span class="min-w-0 flex-1">
                                <span class="block text-sm font-semibold text-gray-950 dark:text-white">Advanced settings</span>
                                <span class="block truncate text-xs text-gray-500 dark:text-gray-400" x-text="summary"></span>
                            </span>
                            <span x-show="Object.keys(errors).some(k => k !== 'form' && !['name','sku','category_id','store_id','cost_price','price','status','shop_sold_out'].includes(k.split('.')[0]))"
                                class="h-2 w-2 rounded-full bg-danger-500" aria-label="Has errors"></span>
                            <x-heroicon-m-chevron-down class="h-5 w-5 shrink-0 text-gray-400 transition" x-bind:class="advanced && 'rotate-180'"/>
                        </button>

                        <div x-show="advanced" class="border-t border-gray-200 dark:border-white/10">
                            <div class="flex gap-1 overflow-x-auto border-b border-gray-200 px-2 dark:border-white/10" role="tablist">
                                @foreach ($tabs as $tabKey => [$tabLabel, $tabIcon])
                                    <button type="button" role="tab" x-on:click="tab = '{{ $tabKey }}'" :aria-selected="tab === '{{ $tabKey }}'"
                                        class="relative inline-flex shrink-0 items-center gap-1.5 border-b-2 px-3 py-2.5 text-sm font-medium transition"
                                        :class="tab === '{{ $tabKey }}' ? 'border-primary-600 text-primary-600 dark:text-primary-400' : 'border-transparent text-gray-500 hover:text-gray-800 dark:text-gray-400 dark:hover:text-gray-200'">
                                        <x-dynamic-component :component="$tabIcon" class="h-4 w-4"/>
                                        {{ $tabLabel }}
                                        <span x-show="tabHasError('{{ $tabKey }}')" class="h-1.5 w-1.5 rounded-full bg-danger-500"></span>
                                    </button>
                                @endforeach
                            </div>

                            <div class="p-4">
                                {{-- Selling units --}}
                                <div x-show="tab === 'units'" class="space-y-4">
                                    <div class="grid grid-cols-1 gap-4 md:grid-cols-3">
                                        <div :data-error="!!err('base_unit')">
                                            <label for="pq-base-unit" class="{{ $label }}">Base unit <span class="text-danger-600">*</span></label>
                                            <input id="pq-base-unit" type="text" list="pq-units-list" x-model="f.base_unit" maxlength="32" class="{{ $input }}" autocomplete="off">
                                            <datalist id="pq-units-list">
                                                <template x-for="u in opts.commonUnits" :key="u"><option :value="u"></option></template>
                                            </datalist>
                                            <p class="{{ $hint }}">The smallest unit you sell. Stock is counted in this.</p>
                                            <p class="{{ $error }}" x-show="err('base_unit')" x-text="err('base_unit')"></p>
                                        </div>
                                        <div :data-error="!!err('wholesale_price')">
                                            <label for="pq-wholesale" class="{{ $label }}" x-text="'Wholesale price / ' + unitWord"></label>
                                            <div class="relative">
                                                <span class="{{ $naira }}">₦</span>
                                                <input id="pq-wholesale" type="number" step="any" min="0" inputmode="decimal" x-model="f.wholesale_price" placeholder="Blank for retail only" class="{{ $money }}">
                                            </div>
                                            <p class="{{ $error }}" x-show="err('wholesale_price')" x-text="err('wholesale_price')"></p>
                                        </div>
                                        <div x-show="opts.halves" :data-error="!!err('half_price')">
                                            <label for="pq-half" class="{{ $label }}" x-text="'Half price / ½ ' + unitWord"></label>
                                            <div class="relative">
                                                <span class="{{ $naira }}">₦</span>
                                                <input id="pq-half" type="number" step="any" min="0" inputmode="decimal" x-model="f.half_price" :placeholder="halfPlaceholder(f.price)" class="{{ $money }}">
                                            </div>
                                            <p class="{{ $hint }}">Leave blank to charge exactly half.</p>
                                        </div>
                                    </div>

                                    <div>
                                        <span class="{{ $label }}">Default unit at POS</span>
                                        <div class="flex flex-wrap gap-2">
                                            <template x-for="choice in unitChoices" :key="choice.value">
                                                <label class="inline-flex cursor-pointer items-center gap-2 rounded-lg border px-3 py-1.5 text-sm"
                                                    :class="f.default_unit_choice === choice.value ? 'border-primary-500 bg-primary-50 text-primary-700 dark:bg-primary-950/40 dark:text-primary-300' : 'border-gray-300 text-gray-700 dark:border-white/10 dark:text-gray-300'">
                                                    <input type="radio" class="sr-only" :value="choice.value" x-model="f.default_unit_choice">
                                                    <span x-text="choice.label"></span>
                                                </label>
                                            </template>
                                        </div>
                                        <p class="{{ $hint }}">The unit this product goes into the cart as when a cashier rings it up without choosing one.</p>
                                    </div>

                                    <div class="space-y-3">
                                        <span class="{{ $label }}">Other units</span>
                                        <template x-for="(u, ui) in f.extraUnits" :key="u._key">
                                            <div class="rounded-xl border border-gray-200 bg-gray-50/60 p-3 dark:border-white/10 dark:bg-white/[0.02]">
                                                <div class="grid grid-cols-2 gap-3 md:grid-cols-[1.4fr_1fr_1fr_1fr_auto]">
                                                    <div class="col-span-2 md:col-span-1" :data-error="!!err(`extraUnits.${ui}.name`)">
                                                        <label :for="`pq-unit-${ui}-name`" class="mb-1 block text-xs font-medium text-gray-600 dark:text-gray-400">Unit name *</label>
                                                        <input :id="`pq-unit-${ui}-name`" type="text" list="pq-units-list" x-model="u.name" placeholder="e.g. Crate, Half crate" maxlength="64" class="{{ $input }}" autocomplete="off">
                                                        <p class="{{ $error }}" x-show="err(`extraUnits.${ui}.name`)" x-text="err(`extraUnits.${ui}.name`)"></p>
                                                    </div>
                                                    <div :data-error="!!err(`extraUnits.${ui}.conversion_factor`)">
                                                        <label class="mb-1 block text-xs font-medium text-gray-600 dark:text-gray-400" x-text="'Contains (' + unitWord + 's) *'"></label>
                                                        <input type="number" min="1" step="1" inputmode="numeric" x-model="u.conversion_factor" class="{{ $input }} tabular-nums">
                                                        <p class="{{ $error }}" x-show="err(`extraUnits.${ui}.conversion_factor`)" x-text="err(`extraUnits.${ui}.conversion_factor`)"></p>
                                                    </div>
                                                    <div :data-error="!!err(`extraUnits.${ui}.price`)">
                                                        <label class="mb-1 block text-xs font-medium text-gray-600 dark:text-gray-400">Retail *</label>
                                                        <div class="relative">
                                                            <span class="{{ $naira }}">₦</span>
                                                            <input type="number" step="any" min="0" inputmode="decimal" x-model="u.price" class="{{ $money }}">
                                                        </div>
                                                        <p class="{{ $error }}" x-show="err(`extraUnits.${ui}.price`)" x-text="err(`extraUnits.${ui}.price`)"></p>
                                                    </div>
                                                    <div>
                                                        <label class="mb-1 block text-xs font-medium text-gray-600 dark:text-gray-400">Wholesale</label>
                                                        <div class="relative">
                                                            <span class="{{ $naira }}">₦</span>
                                                            <input type="number" step="any" min="0" inputmode="decimal" x-model="u.wholesale_price" placeholder="Retail only" class="{{ $money }}">
                                                        </div>
                                                    </div>
                                                    <div class="col-span-2 flex items-end justify-between gap-3 md:col-span-1 md:justify-end">
                                                        <label class="flex cursor-pointer items-center gap-2 pb-2 text-xs font-medium text-gray-600 dark:text-gray-400" title="On sale">
                                                            <input type="checkbox" x-model="u.is_active" class="{{ $toggle }}"> On sale
                                                        </label>
                                                        <button type="button" class="{{ $iconBtn }} hover:border-danger-300 hover:text-danger-600" x-on:click="removeUnit(ui)" :aria-label="'Remove ' + (u.name || 'unit')">
                                                            <x-heroicon-o-trash class="h-4 w-4"/>
                                                        </button>
                                                    </div>
                                                </div>

                                                <div class="mt-2 flex flex-wrap items-center gap-x-4 gap-y-1 text-xs">
                                                    <span :class="{ 'text-gray-500 dark:text-gray-400': perSingle(u).tone === 'muted', 'font-semibold text-success-600 dark:text-success-400': perSingle(u).tone === 'good', 'font-semibold text-danger-600 dark:text-danger-400': perSingle(u).tone === 'bad' }"
                                                        x-text="'Per single: ' + perSingle(u).text"></span>
                                                    <span x-show="u.sku" class="font-mono text-gray-400" x-text="'SKU ' + u.sku"></span>
                                                    <span x-show="!u.sku" class="text-gray-400">SKU made when saved</span>
                                                    <div x-show="opts.halves" class="flex items-center gap-2">
                                                        <span class="text-gray-500">Half:</span>
                                                        <input type="number" step="any" min="0" inputmode="decimal" x-model="u.half_price" :placeholder="halfPlaceholder(u.price)" class="{{ $input }} w-36 py-1 text-xs">
                                                    </div>
                                                </div>

                                                {{-- This unit's own barcodes --}}
                                                <div class="mt-3 space-y-2">
                                                    <template x-for="(code, bi) in u.barcodes" :key="bi">
                                                        <div :data-error="!!err(`extraUnits.${ui}.barcodes.${bi}`)">
                                                            <div class="flex gap-1.5">
                                                                <input :id="`pq-unit-${ui}-code-${bi}`" type="text" x-model="u.barcodes[bi]" maxlength="100" placeholder="Scan or type this unit's barcode" class="{{ $input }} font-mono" autocomplete="off"
                                                                    x-on:keydown.enter.prevent="barcodeEnter(u.barcodes, bi, (i) => `pq-unit-${ui}-code-${i}`)"
                                                                    x-on:blur="checkBarcode(`extraUnits.${ui}.barcodes.${bi}`, u.barcodes[bi])">
                                                                <button type="button" class="{{ $iconBtn }}" x-on:click="u.barcodes.splice(bi, 1)" aria-label="Remove barcode"><x-heroicon-o-x-mark class="h-4 w-4"/></button>
                                                            </div>
                                                            <p class="{{ $error }}" x-show="err(`extraUnits.${ui}.barcodes.${bi}`)" x-text="err(`extraUnits.${ui}.barcodes.${bi}`)"></p>
                                                            <p class="{{ $warn }}" x-show="!err(`extraUnits.${ui}.barcodes.${bi}`) && warnings[`extraUnits.${ui}.barcodes.${bi}`]" x-text="warnings[`extraUnits.${ui}.barcodes.${bi}`]"></p>
                                                        </div>
                                                    </template>
                                                    <div class="flex flex-wrap gap-2">
                                                        <button type="button" class="{{ $addBtn }} py-1.5 text-xs" x-on:click="addBarcode(u.barcodes, (i) => `pq-unit-${ui}-code-${i}`)">
                                                            <x-heroicon-m-plus class="h-3.5 w-3.5"/> <span x-text="'Barcode for ' + (u.name || 'this unit')"></span>
                                                        </button>
                                                        <button type="button" class="{{ $addBtn }} py-1.5 text-xs" x-on:click="scan('unit:' + ui)">
                                                            <x-heroicon-o-camera class="h-3.5 w-3.5"/> Scan
                                                        </button>
                                                    </div>
                                                </div>
                                            </div>
                                        </template>
                                        <button type="button" class="{{ $addBtn }}" x-on:click="addUnit()">
                                            <x-heroicon-m-plus class="h-4 w-4"/> Add unit
                                        </button>
                                    </div>
                                </div>

                                {{-- Barcodes --}}
                                <div x-show="tab === 'barcodes'" class="grid grid-cols-1 gap-6 md:grid-cols-2">
                                    <div :data-error="!!err('barcode')">
                                        <label for="pq-barcode" class="{{ $label }}">Barcode</label>
                                        <div class="flex gap-1.5">
                                            <input id="pq-barcode" type="text" x-model="f.barcode" maxlength="100" placeholder="e.g. 0123456789012" class="{{ $input }} font-mono" autocomplete="off"
                                                x-on:keydown.enter.prevent="f.extraBaseBarcodes.length ? document.getElementById('pq-alt-0')?.focus() : null"
                                                x-on:blur="checkBarcode('barcode', f.barcode)">
                                            <button type="button" class="{{ $iconBtn }}" title="Scan with camera" aria-label="Scan barcode with camera" x-on:click="scan('barcode')">
                                                <x-heroicon-o-qr-code class="h-4 w-4"/>
                                            </button>
                                        </div>
                                        <p class="{{ $error }}" x-show="err('barcode')" x-text="err('barcode')"></p>
                                        <p class="{{ $warn }}" x-show="!err('barcode') && warnings.barcode" x-text="warnings.barcode"></p>
                                    </div>

                                    <div class="space-y-2">
                                        <span class="{{ $label }}">Alternative barcodes</span>
                                        <p class="{{ $hint }} -mt-1">Other codes for the same single item — another manufacturer, an old label. Any of them scans as this product. Press Enter after each to add the next.</p>
                                        <template x-for="(code, ai) in f.extraBaseBarcodes" :key="ai">
                                            <div :data-error="!!err(`extraBaseBarcodes.${ai}`)">
                                                <div class="flex gap-1.5">
                                                    <input :id="`pq-alt-${ai}`" type="text" x-model="f.extraBaseBarcodes[ai]" maxlength="100" placeholder="Scan or type a barcode" class="{{ $input }} font-mono" autocomplete="off"
                                                        x-on:keydown.enter.prevent="barcodeEnter(f.extraBaseBarcodes, ai, (i) => `pq-alt-${i}`)"
                                                        x-on:blur="checkBarcode(`extraBaseBarcodes.${ai}`, f.extraBaseBarcodes[ai])">
                                                    <button type="button" class="{{ $iconBtn }}" x-on:click="f.extraBaseBarcodes.splice(ai, 1)" aria-label="Remove barcode"><x-heroicon-o-x-mark class="h-4 w-4"/></button>
                                                </div>
                                                <p class="{{ $error }}" x-show="err(`extraBaseBarcodes.${ai}`)" x-text="err(`extraBaseBarcodes.${ai}`)"></p>
                                                <p class="{{ $warn }}" x-show="!err(`extraBaseBarcodes.${ai}`) && warnings[`extraBaseBarcodes.${ai}`]" x-text="warnings[`extraBaseBarcodes.${ai}`]"></p>
                                            </div>
                                        </template>
                                        <div class="flex flex-wrap gap-2">
                                            <button type="button" class="{{ $addBtn }}" x-on:click="addBarcode(f.extraBaseBarcodes, (i) => `pq-alt-${i}`)">
                                                <x-heroicon-m-plus class="h-4 w-4"/> Add alternative barcode
                                            </button>
                                            <button type="button" class="{{ $addBtn }}" x-on:click="scan('alt')">
                                                <x-heroicon-o-camera class="h-4 w-4"/> Scan
                                            </button>
                                        </div>
                                        <p class="{{ $hint }}">Codes for a bigger unit (a crate) go on that unit under Selling units.</p>
                                    </div>
                                </div>

                                {{-- Photos --}}
                                <p x-show="tab === 'photos' && linked" class="{{ $hint }}">Photos come from your supplier's product and update automatically when they change theirs.</p>
                                <div x-show="tab === 'photos' && !linked" class="space-y-3" :data-error="!!err('photos')">
                                    <div class="grid grid-cols-3 gap-3 sm:grid-cols-4 lg:grid-cols-6">
                                        <template x-for="(p, pi) in f.photos" :key="p._key">
                                            <div class="group relative aspect-square overflow-hidden rounded-xl border border-gray-200 bg-gray-100 dark:border-white/10 dark:bg-gray-800">
                                                <img :src="p.url" alt="" class="h-full w-full object-cover">
                                                <span x-show="pi === 0" class="absolute left-1.5 top-1.5 rounded bg-gray-950/70 px-1.5 py-0.5 text-[10px] font-semibold text-white">Main</span>
                                                <span x-show="p.tmp !== undefined" class="absolute bottom-1.5 left-1.5 rounded bg-primary-600/90 px-1.5 py-0.5 text-[10px] font-semibold text-white">New</span>
                                                <div class="absolute right-1.5 top-1.5 flex gap-1">
                                                    <button type="button" x-show="pi !== 0" x-on:click="makeMain(pi)" title="Make main photo" aria-label="Make main photo"
                                                        class="flex h-7 w-7 items-center justify-center rounded-full bg-white/90 text-gray-700 shadow hover:bg-white"><x-heroicon-m-star class="h-4 w-4"/></button>
                                                    <button type="button" x-on:click="removePhoto(pi)" title="Remove photo" aria-label="Remove photo"
                                                        class="flex h-7 w-7 items-center justify-center rounded-full bg-white/90 text-danger-600 shadow hover:bg-white"><x-heroicon-m-x-mark class="h-4 w-4"/></button>
                                                </div>
                                            </div>
                                        </template>
                                        <label x-show="f.photos.length < opts.maxPhotos"
                                            class="flex aspect-square cursor-pointer flex-col items-center justify-center gap-1 rounded-xl border-2 border-dashed border-gray-300 text-center text-xs font-semibold text-gray-500 transition hover:border-primary-400 hover:text-primary-600 dark:border-white/15">
                                            <x-heroicon-o-photo class="h-6 w-6"/>
                                            Add photos
                                            <input type="file" accept="image/*" multiple class="sr-only" x-on:change="pickPhotos($event)">
                                        </label>
                                    </div>
                                    <p class="{{ $error }}" x-show="err('photos')" x-text="err('photos')"></p>
                                    <p class="{{ $hint }}">
                                        Up to <span x-text="opts.maxPhotos"></span> photos. The first is the main one. Photos upload when you press Save, shrunk first so they go up quickly on mobile data.
                                        <span x-show="mode === 'edit' && editUrl">To remove a background or get AI suggestions, use <a :href="editUrl" class="font-semibold text-primary-600 underline">Enhance with AI on the full page</a>.</span>
                                    </p>
                                </div>

                                {{-- Details --}}
                                <div x-show="tab === 'details'" class="grid grid-cols-1 gap-4 md:grid-cols-2">
                                    <div>
                                        <label for="pq-brand" class="{{ $label }}">Brand</label>
                                        <input id="pq-brand" type="text" x-model="f.brand" maxlength="255" placeholder="e.g. Nigerian Breweries, Golden Penny" class="{{ $input }}">
                                    </div>
                                    <div :data-error="!!err('supplier_id') || !!err('new_supplier.name')">
                                        <label for="pq-supplier" class="{{ $label }}">Supplier</label>
                                        <div x-show="!f.new_supplier" class="flex gap-1.5">
                                            <select id="pq-supplier" x-model="f.supplier_id" class="{{ $input }}">
                                                <option value="">None</option>
                                                <template x-for="s in opts.suppliers" :key="s.id">
                                                    <option :value="String(s.id)" x-text="s.name" :selected="String(s.id) === f.supplier_id"></option>
                                                </template>
                                            </select>
                                            <button type="button" class="{{ $iconBtn }} w-auto gap-1 px-2.5 text-xs font-semibold" x-on:click="f.new_supplier = { name: '', phone: '' }; $nextTick(() => document.getElementById('pq-new-supplier').focus())">
                                                <x-heroicon-m-plus class="h-4 w-4"/> New
                                            </button>
                                        </div>
                                        <template x-if="f.new_supplier">
                                            <div class="grid grid-cols-[1fr_8rem_auto] gap-1.5">
                                                <input id="pq-new-supplier" type="text" x-model="f.new_supplier.name" placeholder="New supplier's name" class="{{ $input }}">
                                                <input type="tel" x-model="f.new_supplier.phone" placeholder="Phone" class="{{ $input }}">
                                                <button type="button" class="{{ $iconBtn }}" x-on:click="f.new_supplier = null" aria-label="Choose an existing supplier instead"><x-heroicon-o-x-mark class="h-4 w-4"/></button>
                                            </div>
                                        </template>
                                        <p class="{{ $hint }}" x-show="f.new_supplier">Added to your suppliers when you save.</p>
                                        <p class="{{ $error }}" x-show="err('supplier_id') || err('new_supplier.name')" x-text="err('supplier_id') || err('new_supplier.name')"></p>
                                    </div>
                                    <div class="md:col-span-2" :data-error="!!err('search_keywords')">
                                        <label for="pq-keywords" class="{{ $label }}">Search keywords</label>
                                        <input id="pq-keywords" type="text" x-model="f.search_keywords" maxlength="500" placeholder="e.g. coke, coca" class="{{ $input }}">
                                        <p class="{{ $hint }}">Other names staff use for this product, separated by commas. The till finds it by these too.</p>
                                        <p class="{{ $error }}" x-show="err('search_keywords')" x-text="err('search_keywords')"></p>
                                    </div>
                                    <div class="md:col-span-2" x-show="mode === 'edit'">
                                        <span class="{{ $label }}">Learned from staff searches</span>
                                        <div class="flex flex-wrap gap-1.5" x-show="f.learned_words.length">
                                            <template x-for="(w, wi) in f.learned_words" :key="w.term">
                                                <span class="inline-flex items-center gap-1 rounded-md bg-gray-100 px-2 py-0.5 text-xs font-semibold text-gray-700 dark:bg-white/10 dark:text-gray-200"
                                                    :class="w.hits < 2 && 'opacity-60'" :title="w.hits < 2 ? 'Seen once — used from the second time' : 'Used by search'">
                                                    <span x-text="w.term"></span><span class="font-normal opacity-60" x-text="'×' + w.hits"></span>
                                                    <button type="button" x-on:click="forgetWord(wi)" :aria-label="'Forget ' + w.term">×</button>
                                                </span>
                                            </template>
                                        </div>
                                        <p class="{{ $hint }}" x-show="!f.learned_words.length">None yet. When staff search a word that finds nothing and then pick this product, the word is learned.</p>
                                        <p class="{{ $hint }}" x-show="f.forget_words.length" x-text="'Forgotten when you save: ' + f.forget_words.join(', ')"></p>
                                    </div>
                                    <div class="md:col-span-2">
                                        <label for="pq-description" class="{{ $label }}">Description</label>
                                        <textarea id="pq-description" rows="3" x-model="f.description" class="{{ $input }}"></textarea>
                                    </div>
                                    <div class="md:col-span-2">
                                        <label for="pq-tag" class="{{ $label }}">Tags</label>
                                        <div class="flex flex-wrap items-center gap-1.5 rounded-lg border border-gray-300 bg-white px-2 py-1.5 dark:border-white/10 dark:bg-white/5">
                                            <template x-for="(t, ti) in f.tags" :key="'t' + t">
                                                <span class="inline-flex items-center gap-1 rounded-md bg-primary-50 px-2 py-0.5 text-xs font-semibold text-primary-700 dark:bg-primary-950/40 dark:text-primary-300">
                                                    <span x-text="tagName(t)"></span>
                                                    <button type="button" x-on:click="f.tags.splice(ti, 1)" aria-label="Remove tag">×</button>
                                                </span>
                                            </template>
                                            <template x-for="(t, ti) in f.new_tags" :key="'n' + t">
                                                <span class="inline-flex items-center gap-1 rounded-md bg-gray-100 px-2 py-0.5 text-xs font-semibold text-gray-700 dark:bg-white/10 dark:text-gray-200">
                                                    <span x-text="t"></span><span class="font-normal opacity-60">(new)</span>
                                                    <button type="button" x-on:click="f.new_tags.splice(ti, 1)" aria-label="Remove tag">×</button>
                                                </span>
                                            </template>
                                            <input id="pq-tag" type="text" list="pq-tags-list" x-model="tagInput" placeholder="Type a tag, press Enter" autocomplete="off"
                                                x-on:keydown.enter.prevent="addTag()" x-on:keydown.comma.prevent="addTag()" x-on:change="addTag()"
                                                class="min-w-[10rem] flex-1 border-0 bg-transparent px-1 py-1 text-sm text-gray-950 outline-none focus:ring-0 dark:text-white">
                                            <datalist id="pq-tags-list">
                                                <template x-for="t in opts.tags" :key="t.id"><option :value="t.name"></option></template>
                                            </datalist>
                                        </div>
                                    </div>
                                    <div x-show="opts.gadget" class="space-y-2 md:col-span-2">
                                        <span class="{{ $label }}">Specifications</span>
                                        <template x-for="(s, si) in f.specifications" :key="s._key">
                                            <div class="grid grid-cols-[1fr_1fr_auto] gap-1.5">
                                                <input type="text" x-model="s.key" placeholder="Spec name (e.g. RAM)" class="{{ $input }}">
                                                <input type="text" x-model="s.value" placeholder="Value (e.g. 8GB)" class="{{ $input }}">
                                                <button type="button" class="{{ $iconBtn }}" x-on:click="f.specifications.splice(si, 1)" aria-label="Remove specification"><x-heroicon-o-x-mark class="h-4 w-4"/></button>
                                            </div>
                                        </template>
                                        <button type="button" class="{{ $addBtn }}" x-on:click="f.specifications.push({ _key: 's' + Date.now(), key: '', value: '' })">
                                            <x-heroicon-m-plus class="h-4 w-4"/> Add specification
                                        </button>
                                    </div>
                                </div>

                                {{-- Stock rules --}}
                                <div x-show="tab === 'stock'" class="grid grid-cols-1 gap-4 md:grid-cols-2">
                                    <div :data-error="!!err('low_stock_threshold')">
                                        <label for="pq-low" class="{{ $label }}">Low stock alert <span class="text-danger-600">*</span></label>
                                        <input id="pq-low" type="number" min="0" step="any" inputmode="decimal" x-model="f.low_stock_threshold" class="{{ $input }} tabular-nums">
                                        <p class="{{ $hint }}" x-text="'In ' + unitWord + 's. Flagged as “low stock” once available units fall below this.'"></p>
                                        <p class="{{ $error }}" x-show="err('low_stock_threshold')" x-text="err('low_stock_threshold')"></p>
                                    </div>
                                    <div>
                                        <label for="pq-reorder" class="{{ $label }}">Reorder point</label>
                                        <input id="pq-reorder" type="number" min="0" step="1" inputmode="numeric" x-model="f.reorder_point" class="{{ $input }} tabular-nums">
                                        <p class="{{ $hint }}">The level at which you intend to reorder. Distinct from the alert, which only warns.</p>
                                    </div>
                                    <div>
                                        <label for="pq-preferred" class="{{ $label }}">Preferred order qty</label>
                                        <input id="pq-preferred" type="number" min="0" step="1" inputmode="numeric" x-model="f.preferred_quantity" class="{{ $input }} tabular-nums">
                                        <p class="{{ $hint }}">How much you usually buy when restocking this.</p>
                                    </div>
                                    <div>
                                        <label for="pq-negative" class="{{ $label }}">Selling below zero stock</label>
                                        <select id="pq-negative" x-model="f.negative_stock_override" class="{{ $input }}">
                                            <option value="">Follow shop setting</option>
                                            <option value="1">Allow for this product</option>
                                            <option value="0">Block for this product</option>
                                        </select>
                                        <p class="{{ $hint }}">Shop setting is under Store Profile → Sales Point Settings.</p>
                                    </div>
                                    <label class="flex cursor-pointer items-start gap-3">
                                        <input type="checkbox" x-model="f.is_service" class="{{ $toggle }} mt-0.5">
                                        <span>
                                            <span class="block text-sm font-medium text-gray-950 dark:text-white">This is a service, not a physical item</span>
                                            <span class="{{ $hint }} block">Services hold no stock and are left out of inventory counts.</span>
                                        </span>
                                    </label>
                                    <label x-show="opts.gadget" class="flex cursor-pointer items-start gap-3">
                                        <input type="checkbox" x-model="f.track_serial" class="{{ $toggle }} mt-0.5">
                                        <span>
                                            <span class="block text-sm font-medium text-gray-950 dark:text-white">Track serial / IMEI numbers</span>
                                            <span class="{{ $hint }} block">Receiving this product in Purchases then asks for one serial or IMEI per unit.</span>
                                        </span>
                                    </label>
                                </div>

                                {{-- Pricing rules --}}
                                <div x-show="tab === 'pricing'" class="space-y-4">
                                    <label x-show="linked" class="flex cursor-pointer items-start gap-3">
                                        <input type="checkbox" x-model="f.has_custom_price" class="{{ $toggle }} mt-0.5">
                                        <span>
                                            <span class="block text-sm font-medium text-gray-950 dark:text-white">Override linked price</span>
                                            <span class="{{ $hint }} block">Turn on to set a custom selling price, ignoring the general percentage markup from the supplier.</span>
                                        </span>
                                    </label>
                                    <label class="flex items-start gap-3" :class="f.cost_price === '' && opts.canSeeCost ? 'opacity-70' : 'cursor-pointer'">
                                        <input type="checkbox" x-model="f.allow_pos_price_override" :disabled="opts.canSeeCost && f.cost_price === ''" class="{{ $toggle }} mt-0.5">
                                        <span>
                                            <span class="block text-sm font-medium text-gray-950 dark:text-white">Allow price negotiation at the till</span>
                                            <span class="{{ $hint }} block" x-text="opts.canSeeCost && f.cost_price === '' ? 'Needs a cost price before this can take effect — without one there is no way to tell what selling at a loss would mean.' : 'Cashiers can haggle on this product, but never below your minimum margin. Turn off to fix the price.'"></span>
                                        </span>
                                    </label>
                                </div>

                                {{-- Channels & schedule --}}
                                <div x-show="tab === 'channels'" class="grid grid-cols-1 gap-4 md:grid-cols-2">
                                    <label class="flex cursor-pointer items-start gap-3">
                                        <input type="checkbox" x-model="f.show_in_pos" class="{{ $toggle }} mt-0.5">
                                        <span>
                                            <span class="block text-sm font-medium text-gray-950 dark:text-white">Offline store (Sales Point)</span>
                                            <span class="{{ $hint }} block">Available for in-person sales at the Sales Point.</span>
                                        </span>
                                    </label>
                                    <label class="flex cursor-pointer items-start gap-3">
                                        <input type="checkbox" x-model="f.show_online" class="{{ $toggle }} mt-0.5">
                                        <span>
                                            <span class="block text-sm font-medium text-gray-950 dark:text-white">Online store</span>
                                            <span class="{{ $hint }} block">Show on the public storefront. Also needs at least one photo.</span>
                                        </span>
                                    </label>
                                    <div x-show="f.status === 'published'">
                                        <label for="pq-publish-at" class="{{ $label }}">Publish date</label>
                                        <input id="pq-publish-at" type="datetime-local" x-model="f.published_at" class="{{ $input }}">
                                        <p class="{{ $hint }}">Blank publishes immediately.</p>
                                    </div>
                                    <div x-show="f.status === 'published'" :data-error="!!err('unpublish_at')">
                                        <label for="pq-unpublish-at" class="{{ $label }}">Unpublish date</label>
                                        <input id="pq-unpublish-at" type="datetime-local" x-model="f.unpublish_at" class="{{ $input }}">
                                        <p class="{{ $hint }}">Blank stays live.</p>
                                        <p class="{{ $error }}" x-show="err('unpublish_at')" x-text="err('unpublish_at')"></p>
                                    </div>
                                    <p x-show="f.status !== 'published'" class="{{ $hint }} md:col-span-2">A publish schedule can be set once the product is Published.</p>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                </template>
            </div>

            {{-- Footer: always in reach --}}
            <div class="flex shrink-0 flex-wrap items-center gap-2 border-t border-gray-200 bg-white px-4 py-3 sm:px-6 dark:border-white/10 dark:bg-gray-900">
                <template x-if="mode !== 'limit'">
                    <div class="flex flex-1 flex-wrap items-center gap-2">
                        <x-filament::button type="submit" x-bind:disabled="saving || loading || !!loadError">
                            <span x-show="!saving">Save</span>
                            <span x-show="saving" x-text="savingLabel || 'Saving…'"></span>
                        </x-filament::button>
                        <x-filament::button type="button" color="gray" x-show="mode === 'create'" x-on:click="save(true)" x-bind:disabled="saving">
                            Save &amp; add another
                        </x-filament::button>
                        <x-filament::button type="button" color="gray" x-on:click="close()" x-bind:disabled="saving">
                            Cancel
                        </x-filament::button>
                        <span class="ml-auto hidden text-xs text-gray-400 md:inline">Ctrl + Enter saves · Esc closes</span>
                    </div>
                </template>
                <template x-if="mode === 'limit'">
                    <x-filament::button type="button" color="gray" x-on:click="close(true)">Close</x-filament::button>
                </template>
            </div>
        </form>
    </div>
</div>

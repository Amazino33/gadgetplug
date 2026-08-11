<x-filament-panels::page>
    @php
        $orders = $this->getOrders();
    @endphp

    <div class="space-y-4"
        x-data="{
            open: false,
            orderId: null,
            options: [],
            newStatus: '',
            note: '',
            needsChannel: false,
            paymentChannel: '',
            openModal(id, options, needsChannel = false) {
                this.orderId = id;
                this.options = options;
                this.newStatus = '';
                this.note = '';
                this.needsChannel = needsChannel;
                this.paymentChannel = '';
                this.open = true;
            },

            msgOpen: false,
            msgOrderId: null,
            msgCustomer: '',
            msgPhone: '',
            msgTemplates: [],
            msgTemplateKey: '',
            msgChannel: 'whatsapp',
            msgBody: '',
            msgSending: false,
            openMessageModal(detail) {
                this.msgOrderId = detail.id;
                this.msgCustomer = detail.customer;
                this.msgPhone = detail.phone;
                this.msgTemplates = detail.templates;
                this.msgTemplateKey = '';
                this.msgChannel = 'whatsapp';
                this.msgBody = '';
                this.msgSending = false;
                this.msgOpen = true;
            },
            applyTemplate() {
                const chosen = this.msgTemplates.find(t => t.key === this.msgTemplateKey);
                if (chosen) {
                    this.msgBody = chosen.body;
                    this.msgChannel = chosen.channel;
                }
            },
            async sendMessage() {
                if (! this.msgBody.trim() || this.msgSending) return;
                this.msgSending = true;
                await $wire.sendOrderMessage(this.msgOrderId, this.msgBody, this.msgChannel);
                this.msgOpen = false;
                this.msgSending = false;
            }
        }"
        x-on:open-update-status.window="openModal($event.detail.id, $event.detail.options, $event.detail.needsChannel)"
        x-on:open-send-message.window="openMessageModal($event.detail)"
    >
        {{-- Toolbar --}}
        <div class="flex flex-col sm:flex-row sm:items-center gap-3 bg-white dark:bg-gray-900 border border-gray-200 dark:border-white/10 rounded-xl p-4">
            <div class="relative flex-1 sm:max-w-xs">
                <div class="pointer-events-none absolute inset-y-0 left-0 flex items-center pl-3">
                    <x-heroicon-o-magnifying-glass class="h-4 w-4 text-gray-400"/>
                </div>
                <input type="text" wire:model.live.debounce.300ms="search"
                    aria-label="Search orders by reference, customer name, or phone"
                    placeholder="Search orders…"
                    class="w-full rounded-lg border border-gray-300 dark:border-gray-700 bg-gray-50 dark:bg-gray-800 py-2 pl-9 pr-3 text-sm focus:outline-none focus:ring-2 focus:ring-primary-500 focus:border-primary-500">
            </div>

            <select wire:model.live="statusFilter"
                aria-label="Filter by status"
                class="rounded-lg border border-gray-300 dark:border-gray-700 bg-gray-50 dark:bg-gray-800 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-primary-500">
                <option value="">Active Orders</option>
                <option value="all">All Orders</option>
                <option value="pending">Pending</option>
                <option value="confirmed">Confirmed</option>
                <option value="paid">Paid</option>
                <option value="shipped">Dispatched</option>
                <option value="delivered">Delivered</option>
                <option value="cancelled">Cancelled</option>
                <option value="paid_but_failed_stock">Stock Issue</option>
            </select>
        </div>

        {{-- DESKTOP TABLE --}}
        <div class="hidden md:block overflow-hidden rounded-xl border border-gray-200 dark:border-white/10 bg-white dark:bg-gray-900">
            <table class="w-full text-left [table-layout:fixed]">
                <thead>
                    <tr class="border-b border-gray-200 dark:border-white/10 bg-gray-50 dark:bg-gray-800">
                        <th class="w-[14%] py-3 px-4 text-xs font-semibold uppercase tracking-wider text-gray-500">Order Ref</th>
                        <th class="w-[18%] py-3 px-4 text-xs font-semibold uppercase tracking-wider text-gray-500">Customer</th>
                        <th class="w-[12%] py-3 px-4 text-xs font-semibold uppercase tracking-wider text-gray-500">Payment</th>
                        <th class="w-[12%] py-3 px-4 text-center text-xs font-semibold uppercase tracking-wider text-gray-500">Status</th>
                        <th class="w-[12%] py-3 px-4 text-right text-xs font-semibold uppercase tracking-wider text-gray-500">Total</th>
                        <th class="w-[14%] py-3 px-4 text-xs font-semibold uppercase tracking-wider text-gray-500">Placed</th>
                        <th class="w-32 py-3 px-4"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100 dark:divide-white/5">
                    @forelse($orders as $order)
                        @include('filament.vendor.orders.table-row', ['order' => $order])
                    @empty
                        <tr><td colspan="7" class="py-12 text-center text-sm text-gray-400">No orders found.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        {{-- MOBILE TILES --}}
        <div class="md:hidden space-y-3">
            @forelse($orders as $order)
                @include('filament.vendor.orders.mobile-card', ['order' => $order])
            @empty
                <p class="py-12 text-center text-sm text-gray-400">No orders found.</p>
            @endforelse
        </div>

        <div>{{ $orders->links() }}</div>

        {{-- Shared Update Status + Note modal --}}
        <div x-show="open" x-cloak
            class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4"
            x-on:keydown.escape.window="open = false">
            <div class="w-full max-w-sm rounded-xl bg-white dark:bg-gray-900 p-5 shadow-xl" x-on:click.outside="open = false">
                <h3 class="mb-3 text-sm font-semibold text-gray-900 dark:text-white">Update Order</h3>

                <template x-if="options.length">
                    <div class="mb-4">
                        <label class="mb-1 block text-xs font-medium text-gray-500 dark:text-gray-400">Status</label>
                        <select x-model="newStatus"
                            class="w-full rounded-lg border border-gray-300 dark:border-gray-700 bg-gray-50 dark:bg-gray-800 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-primary-500">
                            <option value="">No change</option>
                            <template x-for="option in options" :key="option.value">
                                <option :value="option.value" x-text="option.label"></option>
                            </template>
                        </select>
                    </div>
                </template>

                {{-- Only for a pay-on-delivery order being marked delivered: this
                     is what puts the money into an account and onto the Financial
                     Report. Without it the sale is counted as revenue nowhere. --}}
                <template x-if="needsChannel && newStatus === 'delivered'">
                    <div class="mb-4">
                        <label class="mb-1 block text-xs font-medium text-gray-500 dark:text-gray-400">How did the customer pay?</label>
                        <select x-model="paymentChannel"
                            class="w-full rounded-lg border border-gray-300 dark:border-gray-700 bg-gray-50 dark:bg-gray-800 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-primary-500">
                            <option value="">Choose one</option>
                            <option value="cash">Cash</option>
                            <option value="bank_transfer">Bank Transfer</option>
                        </select>
                        <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                            The full order amount is added to that account as money received — once, right now.
                        </p>
                    </div>
                </template>

                <div class="mb-4">
                    <label class="mb-1 block text-xs font-medium text-gray-500 dark:text-gray-400">Note (optional)</label>
                    <textarea x-model="note" rows="3"
                        placeholder="e.g. Tried to call, no answer. Customer asked for evening delivery."
                        class="w-full rounded-lg border border-gray-300 dark:border-gray-700 bg-gray-50 dark:bg-gray-800 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-primary-500"></textarea>
                </div>

                <div class="flex justify-end gap-2">
                    <button type="button" x-on:click="open = false"
                        class="rounded-lg px-3 py-2 text-sm font-medium text-gray-600 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-white/10">
                        Cancel
                    </button>
                    <button type="button"
                        x-on:click="$wire.updateOrderStatus(orderId, newStatus || null, note || null, paymentChannel || null); open = false"
                        class="rounded-lg bg-primary-600 px-4 py-2 text-sm font-semibold text-white hover:bg-primary-700">
                        Save
                    </button>
                </div>
            </div>
        </div>

        {{-- Shared Send Message modal — same templates and send path as the
             order page's Send Message action, so a message raised from the list
             is logged and delivered identically. --}}
        <div x-show="msgOpen" x-cloak
            class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4"
            x-on:keydown.escape.window="msgOpen = false">
            <div class="w-full max-w-lg rounded-xl bg-white dark:bg-gray-900 p-5 shadow-xl" x-on:click.outside="msgOpen = false">
                <h3 class="text-sm font-semibold text-gray-900 dark:text-white">Send Message</h3>
                <p class="mb-4 mt-0.5 text-xs text-gray-500 dark:text-gray-400">
                    To <span class="font-medium text-gray-700 dark:text-gray-200" x-text="msgCustomer"></span>
                    <span x-text="msgPhone ? '· ' + msgPhone : ''"></span>
                </p>

                <template x-if="msgTemplates.length">
                    <div class="mb-4">
                        <label class="mb-1 block text-xs font-medium text-gray-500 dark:text-gray-400">Template</label>
                        <select x-model="msgTemplateKey" x-on:change="applyTemplate()"
                            class="w-full rounded-lg border border-gray-300 dark:border-gray-700 bg-gray-50 dark:bg-gray-800 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-primary-500">
                            <option value="">Write my own…</option>
                            <template x-for="option in msgTemplates" :key="option.key">
                                <option :value="option.key" x-text="option.label"></option>
                            </template>
                        </select>
                    </div>
                </template>

                <div class="mb-4">
                    <label class="mb-1 block text-xs font-medium text-gray-500 dark:text-gray-400">Message</label>
                    <textarea x-model="msgBody" rows="8"
                        placeholder="Pick a template above, or type your message here."
                        class="w-full rounded-lg border border-gray-300 dark:border-gray-700 bg-gray-50 dark:bg-gray-800 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-primary-500"></textarea>
                </div>

                <div class="mb-4">
                    <label class="mb-1 block text-xs font-medium text-gray-500 dark:text-gray-400">Channel</label>
                    <select x-model="msgChannel"
                        class="w-full rounded-lg border border-gray-300 dark:border-gray-700 bg-gray-50 dark:bg-gray-800 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-primary-500">
                        <option value="whatsapp">WhatsApp</option>
                        <option value="sms">SMS</option>
                    </select>
                </div>

                <div class="flex justify-end gap-2">
                    <button type="button" x-on:click="msgOpen = false"
                        class="rounded-lg px-3 py-2 text-sm font-medium text-gray-600 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-white/10">
                        Cancel
                    </button>
                    <button type="button"
                        x-on:click="sendMessage()"
                        x-bind:disabled="! msgBody.trim() || msgSending"
                        class="rounded-lg bg-primary-600 px-4 py-2 text-sm font-semibold text-white hover:bg-primary-700 disabled:cursor-not-allowed disabled:opacity-50">
                        <span x-text="msgSending ? 'Sending…' : 'Send'"></span>
                    </button>
                </div>
            </div>
        </div>
    </div>
</x-filament-panels::page>

<?php

use Livewire\Volt\Component;
use App\Models\VendorApplication;

new class extends Component {
    public string $storeName    = '';
    public string $businessType = '';
    public string $description  = '';
    public string $whatsapp     = '';

    public ?VendorApplication $application = null;
    public bool $submitted = false;

    public function mount(): void
    {
        $this->application = VendorApplication::where('user_id', auth()->id())->first();
        if ($this->application) {
            $this->storeName    = $this->application->store_name;
            $this->businessType = $this->application->business_type ?? '';
            $this->description  = $this->application->description  ?? '';
            $this->whatsapp     = $this->application->whatsapp      ?? '';
        }
    }

    public function submit(): void
    {
        $this->validate([
            'storeName'    => 'required|string|max:100',
            'businessType' => 'required|string|max:100',
            'description'  => 'required|string|min:30|max:1000',
            'whatsapp'     => 'required|string|max:20',
        ]);

        VendorApplication::updateOrCreate(
            ['user_id' => auth()->id()],
            [
                'store_name'    => $this->storeName,
                'business_type' => $this->businessType,
                'description'   => $this->description,
                'whatsapp'      => $this->whatsapp,
                'status'        => 'pending',
            ]
        );

        $this->application = VendorApplication::where('user_id', auth()->id())->first();
        $this->submitted   = true;
    }
}; ?>

<div>
<x-layouts.account active="account.vendor-apply">

    <div class="space-y-5">

        {{-- Status banner if already applied --}}
        @if ($application)
        <div class="rounded-2xl p-5 border
            {{ match($application->status) {
                'approved' => 'bg-[#e8f5e8] dark:bg-[#1a2a1a] border-brand/40',
                'rejected' => 'bg-red-50 dark:bg-red-900/10 border-red-200 dark:border-red-800',
                default    => 'bg-amber-50 dark:bg-amber-900/10 border-amber-200 dark:border-amber-800',
            } }}">
            <div class="flex items-start gap-3">
                <div class="text-2xl flex-shrink-0">
                    {{ match($application->status) { 'approved' => '🎉', 'rejected' => '❌', default => '⏳' } }}
                </div>
                <div>
                    <h3 class="font-montserrat font-bold text-[14px]
                        {{ match($application->status) { 'approved' => 'text-brand', 'rejected' => 'text-red-600 dark:text-red-400', default => 'text-amber-700 dark:text-amber-400' } }}">
                        @if ($application->status === 'approved') Application Approved — Welcome, Plug! 🔌
                        @elseif ($application->status === 'rejected') Application Not Approved
                        @else Application Under Review
                        @endif
                    </h3>
                    <p class="text-[12px] mt-1
                        {{ match($application->status) { 'approved' => 'text-brand', 'rejected' => 'text-red-500', default => 'text-amber-600 dark:text-amber-500' } }}">
                        @if ($application->status === 'approved')
                            Your vendor account is ready. Head to your <a href="/plug" class="font-bold underline">Plug Dashboard</a> to start listing products.
                        @elseif ($application->status === 'rejected')
                            {{ $application->admin_notes ?? 'Your application did not meet our current requirements. You may reapply after 30 days.' }}
                        @else
                            We received your application for <strong>{{ $application->store_name }}</strong>. We typically review within 2–3 business days.
                        @endif
                    </p>
                </div>
            </div>
        </div>
        @endif

        {{-- Form --}}
        @if (!$application || $application->status === 'rejected')
        <div class="bg-white dark:bg-[#162016] border border-brand-border dark:border-[#2a3a2a] rounded-2xl p-5 md:p-6">

            <div class="mb-5">
                <h2 class="font-montserrat font-bold text-[15px] text-brand-dark dark:text-[#e8f5e9]">
                    {{ $application ? 'Reapply as a Plug' : 'Become a GadgetPlug Vendor' }}
                </h2>
                <p class="text-[12px] text-brand-muted mt-1">
                    Join hundreds of verified plugs selling to customers across Nigeria. Fill in the details below and our team will review your application.
                </p>
            </div>

            @if ($submitted)
            <div class="mb-4 bg-[#f0f8f0] dark:bg-[#1a2a1a] border border-brand/30 rounded-xl px-4 py-3 text-[12px] text-brand font-semibold flex items-center gap-2">
                <svg class="w-4 h-4 flex-shrink-0" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/></svg>
                Application submitted! We'll review it and get back to you within 2–3 business days.
            </div>
            @endif

            <form wire:submit="submit" class="space-y-4">
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div>
                        <label class="block text-[11px] font-semibold text-brand-muted uppercase tracking-[0.6px] mb-1.5">Store / Business Name <span class="text-red-400">*</span></label>
                        <input wire:model="storeName" type="text" placeholder="e.g. TechPlug Lagos"
                            class="w-full h-10 px-3.5 bg-brand-bg dark:bg-[#0d1a0d] border border-[#d0d9d2] dark:border-[#2a3a2a] rounded-xl text-[13px] text-[#111] dark:text-[#e8f5e9] focus:outline-none focus:border-brand transition-colors @error('storeName') border-red-400 @enderror">
                        @error('storeName')<p class="text-red-500 text-[11px] mt-1">{{ $message }}</p>@enderror
                    </div>
                    <div>
                        <label class="block text-[11px] font-semibold text-brand-muted uppercase tracking-[0.6px] mb-1.5">Business Type <span class="text-red-400">*</span></label>
                        <select wire:model="businessType"
                            class="w-full h-10 px-3.5 bg-brand-bg dark:bg-[#0d1a0d] border border-[#d0d9d2] dark:border-[#2a3a2a] rounded-xl text-[13px] text-[#111] dark:text-[#e8f5e9] focus:outline-none focus:border-brand transition-colors @error('businessType') border-red-400 @enderror">
                            <option value="">Select type…</option>
                            <option value="Individual Reseller">Individual Reseller</option>
                            <option value="Retail Store">Retail Store</option>
                            <option value="Wholesale / Distributor">Wholesale / Distributor</option>
                            <option value="Brand / Manufacturer">Brand / Manufacturer</option>
                            <option value="Repair & Accessories">Repair &amp; Accessories</option>
                        </select>
                        @error('businessType')<p class="text-red-500 text-[11px] mt-1">{{ $message }}</p>@enderror
                    </div>
                    <div class="md:col-span-2">
                        <label class="block text-[11px] font-semibold text-brand-muted uppercase tracking-[0.6px] mb-1.5">WhatsApp Number <span class="text-red-400">*</span></label>
                        <input wire:model="whatsapp" type="tel" placeholder="+234 800 000 0000"
                            class="w-full h-10 px-3.5 bg-brand-bg dark:bg-[#0d1a0d] border border-[#d0d9d2] dark:border-[#2a3a2a] rounded-xl text-[13px] text-[#111] dark:text-[#e8f5e9] focus:outline-none focus:border-brand transition-colors @error('whatsapp') border-red-400 @enderror">
                        @error('whatsapp')<p class="text-red-500 text-[11px] mt-1">{{ $message }}</p>@enderror
                    </div>
                    <div class="md:col-span-2">
                        <label class="block text-[11px] font-semibold text-brand-muted uppercase tracking-[0.6px] mb-1.5">Tell us about your business <span class="text-red-400">*</span></label>
                        <textarea wire:model="description" rows="4"
                            placeholder="What products do you sell? How long have you been in business? Where are you located?"
                            class="w-full px-3.5 py-2.5 bg-brand-bg dark:bg-[#0d1a0d] border border-[#d0d9d2] dark:border-[#2a3a2a] rounded-xl text-[13px] text-[#111] dark:text-[#e8f5e9] focus:outline-none focus:border-brand transition-colors resize-none @error('description') border-red-400 @enderror"></textarea>
                        <p class="text-[10px] text-brand-muted mt-1">{{ strlen($description) }} / 1000 characters (min 30)</p>
                        @error('description')<p class="text-red-500 text-[11px] mt-1">{{ $message }}</p>@enderror
                    </div>
                </div>

                <div class="flex justify-end pt-1">
                    <button type="submit"
                        class="h-10 px-6 bg-brand hover:bg-[#055002] text-white font-montserrat font-bold text-[12px] rounded-xl transition-colors flex items-center gap-2">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M13 10V3L4 14h7v7l9-11h-7z"/>
                        </svg>
                        <span wire:loading.remove wire:target="submit">Submit Application</span>
                        <span wire:loading wire:target="submit">Submitting…</span>
                    </button>
                </div>
            </form>
        </div>
        @endif

        {{-- Why become a plug --}}
        @if (!$application || $application->status !== 'approved')
        <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
            @foreach([
                ['🏪', 'Your Own Storefront', 'Get a verified plug dashboard to list unlimited products and manage your inventory.'],
                ['💳', 'Fast Payouts', 'Receive your earnings directly to your bank account within 24 hours of a sale.'],
                ['📈', 'Grow Your Business', 'Access analytics, customer insights, and marketing tools built for Nigerian sellers.'],
            ] as [$icon, $title, $desc])
            <div class="bg-white dark:bg-[#162016] border border-brand-border dark:border-[#2a3a2a] rounded-2xl p-4 text-center">
                <div class="text-3xl mb-2">{{ $icon }}</div>
                <h4 class="font-montserrat font-bold text-[12px] text-brand-dark dark:text-[#e8f5e9] mb-1">{{ $title }}</h4>
                <p class="text-[11px] text-brand-muted leading-relaxed">{{ $desc }}</p>
            </div>
            @endforeach
        </div>
        @endif

    </div>

</x-layouts.account>
</div>

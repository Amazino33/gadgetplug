<?php

use App\Models\AffiliateApplication;
use App\Services\Messaging\PhoneNumber;
use Illuminate\Validation\Rule;
use Livewire\Volt\Component;

new class extends Component {
    public string $category      = '';
    public string $school        = '';
    public string $whatsapp      = '';
    public array $platforms      = [];
    public string $audience_size = '';
    public string $reason        = '';
    public bool $consent         = false;

    public ?AffiliateApplication $application = null;
    public bool $submitted = false;

    public function mount(): void
    {
        // Already an affiliate (however that happened — approved application,
        // or created directly by an admin before this flow existed) — nothing
        // to apply for, send them straight to their dashboard.
        if (auth()->user()->affiliate) {
            $this->redirectRoute('account.affiliate');
            return;
        }

        $this->application = AffiliateApplication::where('user_id', auth()->id())->latest()->first();

        if ($this->application) {
            $this->category      = (string) ($this->application->category ?? '');
            $this->school        = (string) ($this->application->school ?? '');
            $this->whatsapp      = (string) ($this->application->whatsapp ?? '');
            $this->platforms     = (array) ($this->application->platforms ?? []);
            $this->audience_size = (string) ($this->application->audience_size ?? '');
            $this->reason        = (string) ($this->application->reason ?? '');
            $this->consent       = (bool) ($this->application->consent ?? false);
        }
    }

    public function submit(): void
    {
        $this->validate([
            'category'      => ['required', 'string', Rule::in([
                ...array_keys(AffiliateApplication::CATEGORIES),
                ...array_values(AffiliateApplication::CATEGORIES),
            ])],
            'school'        => [
                Rule::requiredIf(fn () => in_array($this->category, ['student', 'University student'], true)),
                'nullable',
                'string',
                'max:255',
            ],
            'whatsapp'      => ['required', 'string', 'max:25', function ($attribute, $value, $fail) {
                $normalized = PhoneNumber::toNigerianInternational($value);
                if (strlen((string) $normalized) !== 13) {
                    $fail('Enter a valid Nigerian WhatsApp number, for example 08012345678.');
                }
            }],
            'platforms'     => ['required', 'array', 'min:1'],
            'platforms.*'   => ['string'],
            'audience_size' => ['required', 'string', Rule::in([
                ...array_keys(AffiliateApplication::AUDIENCE_SIZES),
                ...array_values(AffiliateApplication::AUDIENCE_SIZES),
                '5000+',
                '100-500',
                '500-1000',
                '1000-5000',
            ])],
            'reason'        => ['required', 'string', 'min:30', 'max:1000'],
            'consent'       => ['accepted'],
        ], [
            'category.required'      => 'Please select your category.',
            'category.in'            => 'Please select a valid category.',
            'school.required'        => 'Please enter your school or university.',
            'whatsapp.required'      => 'Please enter your WhatsApp number.',
            'platforms.required'     => 'Select at least one platform you post on.',
            'platforms.min'          => 'Select at least one platform you post on.',
            'audience_size.required' => 'Select your main platform audience size.',
            'audience_size.in'       => 'Please select a valid audience size band.',
            'reason.required'        => 'Please tell us how you plan to promote GadgetPlug.',
            'reason.min'             => 'Please provide a bit more detail (minimum 30 characters).',
            'reason.max'             => 'Your answer must not exceed 1000 characters.',
            'consent.accepted'       => 'You must agree to receive affiliate updates and materials to apply.',
        ]);

        $normalizedPhone = PhoneNumber::toE164($this->whatsapp);

        AffiliateApplication::create([
            'user_id'       => auth()->id(),
            'category'      => $this->category,
            'school'        => in_array($this->category, ['student', 'University student'], true) ? $this->school : null,
            'whatsapp'      => $normalizedPhone,
            'platforms'     => array_values($this->platforms),
            'audience_size' => $this->audience_size,
            'reason'        => $this->reason,
            'consent'       => true,
            'consented_at'  => now(),
            'status'        => 'pending',
        ]);

        $this->application = AffiliateApplication::where('user_id', auth()->id())->latest()->first();
        $this->submitted   = true;
    }
}; ?>

<div>
<x-layouts.account active="account.affiliate-apply">

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
                        @if ($application->status === 'approved') Application Approved 🎉
                        @elseif ($application->status === 'rejected') Application Not Approved
                        @else Application Under Review
                        @endif
                    </h3>
                    <p class="text-[12px] mt-1
                        {{ match($application->status) { 'approved' => 'text-brand', 'rejected' => 'text-red-500', default => 'text-amber-600 dark:text-amber-500' } }}">
                        @if ($application->status === 'approved')
                            You're an affiliate! Head to your <a href="{{ route('account.affiliate') }}" class="font-bold underline">Affiliate Dashboard</a> to grab your referral link.
                        @elseif ($application->status === 'rejected')
                            {{ $application->admin_notes ?? 'Your application did not meet our current requirements. Feel free to reapply.' }}
                        @else
                            We received your application. We typically review within 2–3 business days.
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
                    {{ $application ? 'Reapply to Become an Affiliate' : 'Become a GadgetPlug Affiliate' }}
                </h2>
                <p class="text-[12px] text-brand-muted mt-1">
                    Earn commission by sharing your own referral link. Tell us a bit about who you are and how you'll promote GadgetPlug.
                </p>
            </div>

            @if ($submitted)
            <div class="mb-4 bg-[#f0f8f0] dark:bg-[#1a2a1a] border border-brand/30 rounded-xl px-4 py-3 text-[12px] text-brand font-semibold flex items-center gap-2">
                <svg class="w-4 h-4 flex-shrink-0" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/></svg>
                Application submitted! We'll review it and get back to you within 2–3 business days.
            </div>
            @endif

            <form wire:submit="submit" class="space-y-4" x-data="{ category: @js($category) }">

                {{-- Category --}}
                <div>
                    <label for="category" class="block text-[11px] font-semibold text-brand-muted uppercase tracking-[0.6px] mb-1.5">
                        Category <span class="text-red-400">*</span>
                    </label>
                    <select id="category" wire:model="category" @change="category = $event.target.value"
                        class="w-full h-11 px-3.5 bg-brand-bg dark:bg-[#0d1a0d] border border-[#d0d9d2] dark:border-[#2a3a2a] rounded-xl text-[13px] text-[#111] dark:text-[#e8f5e9] focus:outline-none focus:border-brand transition-colors @error('category') border-red-400 @enderror">
                        <option value="">Select your category…</option>
                        @foreach (\App\Models\AffiliateApplication::CATEGORIES as $key => $label)
                            <option value="{{ $key }}">{{ $label }}</option>
                        @endforeach
                    </select>
                    @error('category')<p class="text-red-500 text-[11px] mt-1">{{ $message }}</p>@enderror
                </div>

                {{-- School (conditional on category = student) --}}
                <div x-show="category === 'student' || category === 'University student'" x-cloak class="transition-all">
                    <label for="school" class="block text-[11px] font-semibold text-brand-muted uppercase tracking-[0.6px] mb-1.5">
                        School <span class="text-red-400">*</span>
                    </label>
                    <input id="school" wire:model="school" type="text" placeholder="e.g. AKSU, UniUyo"
                        class="w-full h-11 px-3.5 bg-brand-bg dark:bg-[#0d1a0d] border border-[#d0d9d2] dark:border-[#2a3a2a] rounded-xl text-[13px] text-[#111] dark:text-[#e8f5e9] focus:outline-none focus:border-brand transition-colors @error('school') border-red-400 @enderror">
                    @error('school')<p class="text-red-500 text-[11px] mt-1">{{ $message }}</p>@enderror
                </div>

                {{-- WhatsApp Number --}}
                <div>
                    <label for="whatsapp" class="block text-[11px] font-semibold text-brand-muted uppercase tracking-[0.6px] mb-1.5">
                        WhatsApp Number <span class="text-red-400">*</span>
                    </label>
                    <input id="whatsapp" wire:model="whatsapp" type="tel" placeholder="+234 800 000 0000"
                        class="w-full h-11 px-3.5 bg-brand-bg dark:bg-[#0d1a0d] border border-[#d0d9d2] dark:border-[#2a3a2a] rounded-xl text-[13px] text-[#111] dark:text-[#e8f5e9] focus:outline-none focus:border-brand transition-colors @error('whatsapp') border-red-400 @enderror">
                    @error('whatsapp')<p class="text-red-500 text-[11px] mt-1">{{ $message }}</p>@enderror
                </div>

                {{-- Platforms you post on --}}
                <div>
                    <label class="block text-[11px] font-semibold text-brand-muted uppercase tracking-[0.6px] mb-1.5">
                        Platforms you post on <span class="text-red-400">*</span>
                    </label>
                    <div class="grid grid-cols-2 sm:grid-cols-3 gap-2.5">
                        @foreach (\App\Models\AffiliateApplication::PLATFORMS as $key => $label)
                        <label class="min-h-[44px] flex items-center gap-2.5 px-3 py-2 bg-brand-bg dark:bg-[#0d1a0d] border border-[#d0d9d2] dark:border-[#2a3a2a] rounded-xl cursor-pointer hover:border-brand/60 transition-colors select-none text-[12px] font-medium text-[#111] dark:text-[#e8f5e9]">
                            <input type="checkbox" wire:model="platforms" value="{{ $key }}"
                                class="w-4 h-4 rounded text-brand focus:ring-brand border-[#d0d9d2] dark:border-[#2a3a2a]">
                            <span>{{ $label }}</span>
                        </label>
                        @endforeach
                    </div>
                    @error('platforms')<p class="text-red-500 text-[11px] mt-1">{{ $message }}</p>@enderror
                </div>

                {{-- Main platform audience size --}}
                <div>
                    <label for="audience_size" class="block text-[11px] font-semibold text-brand-muted uppercase tracking-[0.6px] mb-1.5">
                        Main Platform Audience Size <span class="text-red-400">*</span>
                    </label>
                    <select id="audience_size" wire:model="audience_size"
                        class="w-full h-11 px-3.5 bg-brand-bg dark:bg-[#0d1a0d] border border-[#d0d9d2] dark:border-[#2a3a2a] rounded-xl text-[13px] text-[#111] dark:text-[#e8f5e9] focus:outline-none focus:border-brand transition-colors @error('audience_size') border-red-400 @enderror">
                        <option value="">Select audience size band…</option>
                        @foreach (\App\Models\AffiliateApplication::AUDIENCE_SIZES as $key => $label)
                            <option value="{{ $key }}">{{ $label }}</option>
                        @endforeach
                    </select>
                    @error('audience_size')<p class="text-red-500 text-[11px] mt-1">{{ $message }}</p>@enderror
                </div>

                {{-- Promotion Reason / Pitch --}}
                <div>
                    <label for="reason" class="block text-[11px] font-semibold text-brand-muted uppercase tracking-[0.6px] mb-1.5">
                        How will you promote GadgetPlug? <span class="text-red-400">*</span>
                    </label>
                    <textarea id="reason" wire:model="reason" rows="3"
                        placeholder="e.g. I run a tech review page on Instagram with 5,000 followers, or I share deals in my community WhatsApp groups…"
                        class="w-full px-3.5 py-2.5 bg-brand-bg dark:bg-[#0d1a0d] border border-[#d0d9d2] dark:border-[#2a3a2a] rounded-xl text-[13px] text-[#111] dark:text-[#e8f5e9] focus:outline-none focus:border-brand transition-colors resize-none @error('reason') border-red-400 @enderror"></textarea>
                    <p class="text-[10px] text-brand-muted mt-1">{{ strlen($reason) }} / 1000 characters (min 30)</p>
                    @error('reason')<p class="text-red-500 text-[11px] mt-1">{{ $message }}</p>@enderror
                </div>

                {{-- Consent Checkbox --}}
                <div>
                    <label class="min-h-[44px] flex items-start gap-3 p-3 bg-brand-bg/50 dark:bg-[#0d1a0d]/50 border border-[#d0d9d2] dark:border-[#2a3a2a] rounded-xl cursor-pointer hover:border-brand/60 transition-colors select-none">
                        <input type="checkbox" wire:model="consent"
                            class="mt-0.5 w-5 h-5 rounded text-brand focus:ring-brand border-[#d0d9d2] dark:border-[#2a3a2a] flex-shrink-0">
                        <span class="text-[12px] text-brand-dark dark:text-[#e8f5e9] leading-snug">
                            I agree to receive affiliate updates and materials from GadgetPlug by WhatsApp and email. <span class="text-red-400">*</span>
                        </span>
                    </label>
                    @error('consent')<p class="text-red-500 text-[11px] mt-1">{{ $message }}</p>@enderror
                </div>

                {{-- Submit button --}}
                <div class="flex justify-end pt-2">
                    <button type="submit"
                        class="min-h-[44px] h-11 px-6 bg-brand hover:bg-[#055002] text-white font-montserrat font-bold text-[12px] rounded-xl transition-colors flex items-center justify-center gap-2 w-full sm:w-auto">
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

        {{-- Why become an affiliate --}}
        @if (!$application || $application->status !== 'approved')
        <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
            @foreach([
                ['🔗', 'Your Own Referral Link', 'Share a personal link or QR code — every purchase made through it is tracked as yours.'],
                ['💰', 'Real Commission', 'Earn a percentage on every sale, with level-ups for your best-performing months.'],
                ['🎯', 'Bonus Tasks', 'Complete simple tasks for extra rewards on top of your regular commission.'],
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

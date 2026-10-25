<?php

use App\Models\AffiliateApplication;
use App\Models\Lga;
use App\Models\State;
use App\Services\Messaging\PhoneNumber;
use Illuminate\Validation\Rule;
use Livewire\Volt\Component;

new class extends Component {
    public string $category      = '';
    public string $school        = '';
    public string $whatsapp      = '';
    public ?int   $stateId       = null;
    public ?int   $lgaId         = null;
    public string $address       = '';
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
            $this->stateId       = $this->application->state_id ? (int) $this->application->state_id : null;
            $this->lgaId         = $this->application->lga_id ? (int) $this->application->lga_id : null;
            $this->address       = (string) ($this->application->address ?? '');
            $this->platforms     = (array) ($this->application->platforms ?? []);
            $this->audience_size = (string) ($this->application->audience_size ?? '');
            $this->reason        = (string) ($this->application->reason ?? '');
            $this->consent       = (bool) ($this->application->consent ?? false);
        }
    }

    public function updatedStateId(): void
    {
        $this->lgaId = null;
    }

    public function states(): \Illuminate\Support\Collection
    {
        return State::orderBy('name')->get(['id', 'name']);
    }

    public function lgasForState(): \Illuminate\Support\Collection
    {
        return $this->stateId
            ? Lga::where('state_id', $this->stateId)->orderBy('name')->get(['id', 'name'])
            : collect();
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
            'stateId'       => ['required', 'integer', Rule::exists('states', 'id')],
            'lgaId'         => ['required', 'integer', Rule::exists('lgas', 'id')->where('state_id', $this->stateId)],
            'address'       => ['required', 'string', 'max:500'],
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
            'stateId.required'       => 'Please select your state.',
            'stateId.exists'         => 'Please select a valid state.',
            'lgaId.required'         => 'Please choose your local government area.',
            'lgaId.exists'           => 'Please choose a local government area in that state.',
            'address.required'       => 'Please enter your address.',
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
            'state_id'      => $this->stateId,
            'lga_id'        => $this->lgaId,
            'address'       => $this->address,
            'platforms'     => array_values($this->platforms),
            'audience_size' => $this->audience_size,
            'reason'        => $this->reason,
            'consent'       => true,
            'consented_at'  => now(),
            'status'        => 'pending',
        ]);

        $this->application = AffiliateApplication::where('user_id', auth()->id())->latest()->first();
        $this->submitted   = true;

        $this->dispatch('affiliate-application-submitted');
    }
}; ?>

<div>
<x-layouts.account active="account.affiliate-apply">

    {{-- Meta Pixel: Track CompleteRegistration on successful submission --}}
    <div x-data x-on:affiliate-application-submitted.window="if (typeof window.fbq === 'function') { window.fbq('track', 'CompleteRegistration'); }"></div>

    <div class="space-y-5">

        {{-- Status banner if already applied --}}
        @if ($application)
        <div class="rounded-2xl p-5 border shadow-sm transition-all
            {{ match($application->status) {
                'approved' => 'bg-emerald-500/5 dark:bg-emerald-950/20 border-emerald-500/30',
                'rejected' => 'bg-rose-500/5 dark:bg-rose-950/20 border-rose-500/30',
                default    => 'bg-amber-500/5 dark:bg-amber-950/20 border-amber-500/30',
            } }}">
            <div class="flex items-start gap-4">
                {{-- Modern Dual-tone SVG Status Icon --}}
                <div class="w-12 h-12 rounded-2xl flex items-center justify-center flex-shrink-0
                    {{ match($application->status) {
                        'approved' => 'bg-emerald-500/15 dark:bg-emerald-500/25 border border-emerald-500/30 text-brand dark:text-emerald-400 shadow-[0_2px_10px_rgba(6,139,3,0.15)]',
                        'rejected' => 'bg-rose-500/15 dark:bg-rose-500/25 border border-rose-500/30 text-rose-600 dark:text-rose-400 shadow-sm',
                        default    => 'bg-amber-500/15 dark:bg-amber-500/25 border border-amber-500/30 text-amber-600 dark:text-amber-400 shadow-sm',
                    } }}">
                    @if ($application->status === 'approved')
                        <svg class="w-6 h-6" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75L11.25 15 15 9.75M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                        </svg>
                    @elseif ($application->status === 'rejected')
                        <svg class="w-6 h-6" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m9-.75a9 9 0 11-18 0 9 9 0 0118 0zm-9 3.75h.008v.008H12v-.008z" />
                        </svg>
                    @else
                        <svg class="w-6 h-6" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M12 6v6h4.5m4.5 0a9 9 0 11-18 0 9 9 0 0118 0z" />
                        </svg>
                    @endif
                </div>

                <div class="flex-1 min-w-0">
                    <div class="flex flex-wrap items-center gap-2 mb-1">
                        <h3 class="font-montserrat font-bold text-[15px]
                            {{ match($application->status) { 'approved' => 'text-brand dark:text-emerald-400', 'rejected' => 'text-rose-600 dark:text-rose-400', default => 'text-amber-700 dark:text-amber-300' } }}">
                            @if ($application->status === 'approved')
                                Application Approved
                            @elseif ($application->status === 'rejected')
                                Application Not Approved
                            @else
                                Application Under Review
                            @endif
                        </h3>

                        {{-- Modern Status Pill --}}
                        <span class="inline-flex items-center gap-1.5 px-2.5 py-0.5 rounded-full text-[10px] font-bold uppercase tracking-wider
                            {{ match($application->status) {
                                'approved' => 'bg-emerald-500/15 text-brand dark:text-emerald-300 border border-emerald-500/25',
                                'rejected' => 'bg-rose-500/15 text-rose-700 dark:text-rose-300 border border-rose-500/25',
                                default    => 'bg-amber-500/15 text-amber-700 dark:text-amber-300 border border-amber-500/25',
                            } }}">
                            <span class="w-1.5 h-1.5 rounded-full
                                {{ match($application->status) {
                                    'approved' => 'bg-brand dark:bg-emerald-400',
                                    'rejected' => 'bg-rose-600 dark:bg-rose-400',
                                    default    => 'bg-amber-600 dark:bg-amber-400 animate-pulse',
                                } }}"></span>
                            {{ match($application->status) { 'approved' => 'Approved', 'rejected' => 'Declined', default => 'In Review' } }}
                        </span>
                    </div>

                    <p class="text-[12px] leading-relaxed
                        {{ match($application->status) { 'approved' => 'text-brand-dark dark:text-emerald-200/90', 'rejected' => 'text-rose-700 dark:text-rose-300/90', default => 'text-amber-800 dark:text-amber-200/90' } }}">
                        @if ($application->status === 'approved')
                            You're an affiliate! Head to your <a href="{{ route('account.affiliate') }}" class="font-bold underline hover:opacity-80">Affiliate Dashboard</a> to grab your referral link.
                        @elseif ($application->status === 'rejected')
                            {{ $application->admin_notes ?? 'Your application did not meet our current requirements. Feel free to reapply.' }}
                        @else
                            We received your application. We typically review within 2–3 business days.
                        @endif
                    </p>

                    @if ($application->status === 'approved')
                    <div class="mt-3">
                        <a href="{{ route('account.affiliate') }}"
                           class="inline-flex items-center gap-1.5 px-4 py-2 rounded-xl bg-brand hover:bg-[#055002] text-white font-montserrat font-bold text-[12px] transition-colors shadow-sm">
                            <span>Open Affiliate Dashboard</span>
                            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M13.5 4.5L21 12m0 0l-7.5 7.5M21 12H3"/>
                            </svg>
                        </a>
                    </div>
                    @endif
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

                {{-- State --}}
                <div>
                    <label for="stateId" class="block text-[11px] font-semibold text-brand-muted uppercase tracking-[0.6px] mb-1.5">
                        State <span class="text-red-400">*</span>
                    </label>
                    <select id="stateId" wire:model.live="stateId"
                        class="w-full h-11 px-3.5 bg-brand-bg dark:bg-[#0d1a0d] border border-[#d0d9d2] dark:border-[#2a3a2a] rounded-xl text-[13px] text-[#111] dark:text-[#e8f5e9] focus:outline-none focus:border-brand transition-colors @error('stateId') border-red-400 @enderror">
                        <option value="">Select your state…</option>
                        @foreach ($this->states() as $state)
                            <option value="{{ $state->id }}">{{ $state->name }}</option>
                        @endforeach
                    </select>
                    @error('stateId')<p class="text-red-500 text-[11px] mt-1">{{ $message }}</p>@enderror
                </div>

                {{-- Local Government Area (LGA) --}}
                <div x-data="{ term: '' }">
                    <label for="lgaId" class="block text-[11px] font-semibold text-brand-muted uppercase tracking-[0.6px] mb-1.5">
                        Local Government Area (LGA) <span class="text-red-400">*</span>
                    </label>
                    @php $lgaOptions = $this->lgasForState(); @endphp
                    @if ($lgaOptions->count() > 12)
                    <input type="search" x-model="term" placeholder="Type to find your LGA…" aria-label="Find your LGA"
                        class="w-full mb-2 h-10 px-3.5 bg-brand-bg dark:bg-[#0d1a0d] border border-[#d0d9d2] dark:border-[#2a3a2a] rounded-xl text-[12px] text-[#111] dark:text-[#e8f5e9] outline-none focus:border-brand">
                    @endif
                    <select id="lgaId" wire:model.live="lgaId" @disabled($lgaOptions->isEmpty())
                        class="w-full h-11 px-3.5 bg-brand-bg dark:bg-[#0d1a0d] border border-[#d0d9d2] dark:border-[#2a3a2a] rounded-xl text-[13px] text-[#111] dark:text-[#e8f5e9] focus:outline-none focus:border-brand transition-colors @error('lgaId') border-red-400 @enderror">
                        <option value="">{{ $lgaOptions->isEmpty() ? 'Choose a state first' : 'Select your LGA…' }}</option>
                        @foreach ($lgaOptions as $option)
                            <option value="{{ $option->id }}" x-show="! term || @js(mb_strtolower($option->name)).includes(term.toLowerCase())">{{ $option->name }}</option>
                        @endforeach
                    </select>
                    @error('lgaId')<p class="text-red-500 text-[11px] mt-1">{{ $message }}</p>@enderror
                </div>

                {{-- Address --}}
                <div>
                    <label for="address" class="block text-[11px] font-semibold text-brand-muted uppercase tracking-[0.6px] mb-1.5">
                        Address <span class="text-red-400">*</span>
                    </label>
                    <input id="address" wire:model="address" type="text"
                        placeholder="Street name, house number, area or landmark…"
                        class="w-full h-11 px-3.5 bg-brand-bg dark:bg-[#0d1a0d] border border-[#d0d9d2] dark:border-[#2a3a2a] rounded-xl text-[13px] text-[#111] dark:text-[#e8f5e9] focus:outline-none focus:border-brand transition-colors @error('address') border-red-400 @enderror">
                    @error('address')<p class="text-red-500 text-[11px] mt-1">{{ $message }}</p>@enderror
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

                            @if ($key === 'whatsapp')
                                <svg class="w-4 h-4 text-[#25D366] flex-shrink-0" fill="currentColor" viewBox="0 0 24 24">
                                    <path d="M12.04 2c-5.46 0-9.91 4.45-9.91 9.91 0 1.75.46 3.45 1.32 4.95L2.05 22l5.25-1.38c1.45.79 3.08 1.21 4.74 1.21 5.46 0 9.91-4.45 9.91-9.91 0-2.65-1.03-5.14-2.9-7.01A9.816 9.816 0 0012.04 2zm.01 1.67c4.54 0 8.24 3.7 8.24 8.24 0 2.2-.86 4.27-2.42 5.82-1.56 1.56-3.63 2.42-5.82 2.42-1.44 0-2.86-.38-4.11-1.11l-.29-.17-3.11.82.83-3.03-.19-.31a8.216 8.216 0 01-1.26-4.39c0-4.54 3.7-8.24 8.24-8.24zm4.52 11.64c-.25-.13-1.47-.72-1.7-.81-.23-.08-.39-.13-.56.13-.17.25-.64.81-.79.97-.14.17-.29.19-.54.06-.25-.13-1.07-.39-2.03-1.25-.75-.67-1.26-1.5-1.41-1.75-.14-.25-.02-.39.11-.51.11-.11.25-.29.38-.44.13-.14.17-.25.25-.42.08-.17.04-.31-.02-.44-.06-.13-.56-1.35-.77-1.85-.2-.49-.41-.42-.56-.43h-.48c-.17 0-.44.06-.67.31-.23.25-.88.86-.88 2.1 0 1.24.9 2.44 1.03 2.61.13.17 1.78 2.71 4.3 3.8 2.53 1.08 2.53.72 2.99.68.45-.04 1.47-.6 1.68-1.18.21-.59.21-1.09.15-1.19-.06-.11-.22-.17-.47-.3z"/>
                                </svg>
                            @elseif ($key === 'instagram')
                                <svg class="w-4 h-4 text-[#E4405F] flex-shrink-0" fill="currentColor" viewBox="0 0 24 24">
                                    <path d="M12 2.163c3.204 0 3.584.012 4.85.07 3.252.148 4.771 1.691 4.919 4.919.058 1.265.069 1.645.069 4.849 0 3.205-.012 3.584-.069 4.849-.149 3.225-1.664 4.771-4.919 4.919-1.266.058-1.644.07-4.85.07-3.204 0-3.584-.012-4.849-.07-3.26-.149-4.771-1.699-4.919-4.92-.058-1.265-.07-1.644-.07-4.849 0-3.204.013-3.583.07-4.849.149-3.227 1.664-4.771 4.919-4.919 1.266-.057 1.645-.069 4.849-.069zm0-2.163c-3.259 0-3.667.014-4.947.072-4.358.2-6.78 2.618-6.98 6.98-.059 1.281-.073 1.689-.073 4.948 0 3.259.014 3.668.072 4.948.2 4.358 2.618 6.78 6.98 6.98 1.281.058 1.689.072 4.948.072 3.259 0 3.668-.014 4.948-.072 4.354-.2 6.782-2.618 6.979-6.98.059-1.28.073-1.689.073-4.948 0-3.259-.014-3.667-.072-4.947-.196-4.354-2.617-6.78-6.979-6.98-1.281-.059-1.69-.073-4.949-.073zm0 5.838c-3.403 0-6.162 2.759-6.162 6.162s2.759 6.163 6.162 6.163 6.162-2.759 6.162-6.163c0-3.403-2.759-6.162-6.162-6.162zm0 10.162c-2.209 0-4-1.79-4-4 0-2.209 1.791-4 4-4s4 1.791 4 4c0 2.21-1.791 4-4 4zm6.406-11.845c-.796 0-1.441.645-1.441 1.44s.645 1.44 1.441 1.44c.795 0 1.439-.645 1.439-1.44s-.644-1.44-1.439-1.44z"/>
                                </svg>
                            @elseif ($key === 'facebook')
                                <svg class="w-4 h-4 text-[#1877F2] flex-shrink-0" fill="currentColor" viewBox="0 0 24 24">
                                    <path d="M24 12.073c0-6.627-5.373-12-12-12s-12 5.373-12 12c0 5.99 4.388 10.954 10.125 11.854v-8.385H7.078v-3.47h3.047V9.43c0-3.007 1.792-4.669 4.533-4.669 1.312 0 2.686.235 2.686.235v2.953H15.83c-1.491 0-1.956.925-1.956 1.874v2.25h3.328l-.532 3.47h-2.796v8.385C19.612 23.027 24 18.062 24 12.073z"/>
                                </svg>
                            @elseif ($key === 'tiktok')
                                <svg class="w-4 h-4 text-black dark:text-white flex-shrink-0" fill="currentColor" viewBox="0 0 24 24">
                                    <path d="M12.525.02c1.31-.02 2.61-.01 3.91-.02.08 1.53.63 3.09 1.75 4.17 1.12 1.11 2.7 1.62 4.24 1.79v4.03c-1.44-.05-2.89-.35-4.2-.97-.57-.26-1.1-.59-1.62-.93-.01 2.92.01 5.84-.02 8.75-.08 1.4-.54 2.79-1.35 3.94-1.31 1.92-3.58 3.17-5.91 3.21-1.43.08-2.86-.31-4.08-1.03-2.02-1.19-3.44-3.37-3.65-5.71-.02-.5-.03-1-.01-1.49.18-1.9 1.12-3.72 2.58-4.96 1.66-1.44 3.98-2.13 6.15-1.72.02 1.48-.04 2.96-.04 4.44-.99-.32-2.15-.23-3.02.37-.63.41-1.11 1.04-1.36 1.75-.21.51-.24 1.07-.14 1.61.24 1.64 1.82 3.02 3.5 2.87 1.12-.01 2.19-.66 2.77-1.61.19-.33.4-.67.41-1.06.1-1.79.06-3.57.07-5.36.01-4.03-.01-8.05.02-12.07z"/>
                                </svg>
                            @elseif ($key === 'x')
                                <svg class="w-4 h-4 text-black dark:text-white flex-shrink-0" fill="currentColor" viewBox="0 0 24 24">
                                    <path d="M18.244 2.25h3.308l-7.227 8.26 8.502 11.24H16.17l-5.214-6.817L4.99 21.75H1.68l7.73-8.835L1.254 2.25H8.08l4.713 6.231zm-1.161 17.52h1.833L7.084 4.126H5.117z"/>
                                </svg>
                            @elseif ($key === 'snapchat')
                                <svg class="w-4 h-4 text-amber-400 flex-shrink-0" fill="currentColor" viewBox="0 0 24 24">
                                    <path d="M12.001 2c-3.64 0-6.15 2.78-6.15 6.2 0 1.58.62 3.65 1.17 4.75.24.47.1.66-.22.91-.49.38-1.28.89-2.3 1.25-.43.15-.65.41-.65.78 0 .49.44.81.99.96 1.34.37 2.67-.14 3.06-.32.22-.1.41.05.44.25.1.75.76 1.63 2.01 1.95.4.1.76.14 1.13.14.38 0 .74-.04 1.14-.14 1.25-.32 1.91-1.2 2.01-1.95.03-.2.22-.35.44-.25.39.18 1.72.69 3.06.32.55-.15.99-.47.99-.96 0-.37-.22-.63-.65-.78-1.02-.36-1.81-.87-2.3-1.25-.32-.25-.46-.44-.22-.91.55-1.1 1.17-3.17 1.17-4.75 0-3.42-2.51-6.2-6.15-6.2z"/>
                                </svg>
                            @endif

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
            {{-- Benefit 1 --}}
            <div class="bg-white dark:bg-[#162016] border border-brand-border dark:border-[#2a3a2a] rounded-2xl p-4 text-center group hover:border-brand/40 transition-colors">
                <div class="w-10 h-10 rounded-xl bg-emerald-500/10 dark:bg-emerald-500/20 border border-emerald-500/25 text-brand dark:text-emerald-400 flex items-center justify-center mx-auto mb-2.5 shadow-sm">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M13.828 10.172a4 4 0 00-5.656 0l-4 4a4 4 0 105.656 5.656l1.102-1.101m-.758-4.899a4 4 0 005.656 0l4-4a4 4 0 00-5.656-5.656l-1.1 1.1"/>
                    </svg>
                </div>
                <h4 class="font-montserrat font-bold text-[12px] text-brand-dark dark:text-[#e8f5e9] mb-1">Your Own Referral Link</h4>
                <p class="text-[11px] text-brand-muted leading-relaxed">Share a personal link or QR code — every purchase made through it is tracked as yours.</p>
            </div>

            {{-- Benefit 2 --}}
            <div class="bg-white dark:bg-[#162016] border border-brand-border dark:border-[#2a3a2a] rounded-2xl p-4 text-center group hover:border-brand/40 transition-colors">
                <div class="w-10 h-10 rounded-xl bg-emerald-500/10 dark:bg-emerald-500/20 border border-emerald-500/25 text-brand dark:text-emerald-400 flex items-center justify-center mx-auto mb-2.5 shadow-sm">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 6v12m-3-2.818l.879.659c1.171.879 3.07.879 4.242 0 1.172-.879 1.172-2.303 0-3.182C13.536 12.219 12.768 12 12 12c-.725 0-1.45-.22-2.003-.659-1.106-.879-1.106-2.303 0-3.182s2.9-.879 4.006 0l.415.33M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
                    </svg>
                </div>
                <h4 class="font-montserrat font-bold text-[12px] text-brand-dark dark:text-[#e8f5e9] mb-1">Real Commission</h4>
                <p class="text-[11px] text-brand-muted leading-relaxed">Earn a percentage on every sale, with level-ups for your best-performing months.</p>
            </div>

            {{-- Benefit 3 --}}
            <div class="bg-white dark:bg-[#162016] border border-brand-border dark:border-[#2a3a2a] rounded-2xl p-4 text-center group hover:border-brand/40 transition-colors">
                <div class="w-10 h-10 rounded-xl bg-emerald-500/10 dark:bg-emerald-500/20 border border-emerald-500/25 text-brand dark:text-emerald-400 flex items-center justify-center mx-auto mb-2.5 shadow-sm">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M9.813 15.904L9 18.75l-.813-2.846a4.5 4.5 0 00-3.09-3.09L2.25 12l2.846-.813a4.5 4.5 0 003.09-3.09L9 5.25l.813 2.846a4.5 4.5 0 003.09 3.09L15.75 12l-2.846.813a4.5 4.5 0 00-3.09 3.09zM18.259 8.715L18 9.75l-.259-1.035a3.375 3.375 0 00-2.455-2.456L14.25 6l1.036-.259a3.375 3.375 0 002.455-2.456L18 2.25l.259 1.035a3.375 3.375 0 002.456 2.456L21.75 6l-1.035.259a3.375 3.375 0 00-2.456 2.456z"/>
                    </svg>
                </div>
                <h4 class="font-montserrat font-bold text-[12px] text-brand-dark dark:text-[#e8f5e9] mb-1">Bonus Tasks</h4>
                <p class="text-[11px] text-brand-muted leading-relaxed">Complete simple tasks for extra rewards on top of your regular commission.</p>
            </div>
        </div>
        @endif

    </div>

</x-layouts.account>
</div>

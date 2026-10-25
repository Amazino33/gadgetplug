<x-layouts.storefront title="{{ $policy->title }} — GadgetPlug">

    <div class="min-h-screen bg-brand-bg dark:bg-[#0d1a0d] py-8 sm:py-12">
        <div class="max-w-[1100px] mx-auto px-4 sm:px-6">

            {{-- Breadcrumb & Back button --}}
            <div class="mb-6 flex flex-wrap items-center justify-between gap-3">
                <nav class="flex items-center gap-2 text-[12px] text-brand-muted">
                    <a href="{{ route('home') }}" class="hover:text-brand transition-colors">Home</a>
                    <span>/</span>
                    <span>Legal</span>
                    <span>/</span>
                    <span class="text-brand-dark dark:text-white font-medium">{{ $policy->title }}</span>
                </nav>

                <span class="text-[11px] text-brand-muted">
                    Last updated: {{ $policy->updated_at->format('F d, Y') }}
                </span>
            </div>

            {{-- Policy Tabs Navigation --}}
            <div class="flex gap-2 overflow-x-auto scrollbar-none pb-2 mb-6 border-b border-brand-border dark:border-[#2a3a2a]">
                @foreach ($allPolicies as $item)
                <a href="{{ route('legal.show', $item->slug) }}"
                   class="flex-shrink-0 px-4 py-2 rounded-xl text-[12px] font-semibold transition-all whitespace-nowrap
                       {{ $item->slug === $policy->slug
                           ? 'bg-brand text-white shadow-sm'
                           : 'bg-white dark:bg-[#162016] text-[#444] dark:text-[#b0c8b0] hover:text-brand border border-brand-border dark:border-[#2a3a2a]' }}">
                    {{ $item->title }}
                </a>
                @endforeach
            </div>

            {{-- Main Layout: Policy Reader --}}
            <div class="bg-white dark:bg-[#162016] border border-brand-border dark:border-[#2a3a2a] rounded-2xl p-6 sm:p-10 shadow-sm">

                {{-- Header --}}
                <div class="border-b border-zinc-100 dark:border-[#223322] pb-6 mb-6">
                    <span class="inline-flex items-center gap-1.5 px-3 py-1 rounded-full text-[10px] font-bold uppercase tracking-wider bg-emerald-500/10 text-brand dark:text-brand-lime border border-emerald-500/20 mb-3">
                        Official Policy
                    </span>
                    <h1 class="font-montserrat font-black text-[22px] sm:text-[28px] text-brand-dark dark:text-white tracking-tight leading-tight">
                        {{ $policy->title }}
                    </h1>
                    @if ($policy->summary)
                    <p class="text-[13px] text-brand-muted mt-2 leading-relaxed">
                        {{ $policy->summary }}
                    </p>
                    @endif
                </div>

                {{-- Policy HTML Content --}}
                <div class="space-y-4 text-[#222] dark:text-[#d0e0d0] leading-relaxed
                            [&_h2]:font-montserrat [&_h2]:font-bold [&_h2]:text-[16px] sm:[&_h2]:text-[18px] [&_h2]:text-brand-dark dark:[&_h2]:text-white [&_h2]:mt-7 [&_h2]:mb-2.5
                            [&_h3]:font-montserrat [&_h3]:font-semibold [&_h3]:text-[14px] [&_h3]:text-brand-dark dark:[&_h3]:text-white [&_h3]:mt-5 [&_h3]:mb-2
                            [&_p]:text-[13px] [&_p]:leading-relaxed [&_p]:mb-3.5
                            [&_ul]:list-disc [&_ul]:pl-5 [&_ul]:space-y-2 [&_ul]:mb-4 [&_ul]:text-[13px]
                            [&_ol]:list-decimal [&_ol]:pl-5 [&_ol]:space-y-2 [&_ol]:mb-4 [&_ol]:text-[13px]
                            [&_li]:leading-relaxed
                            [&_strong]:font-semibold [&_strong]:text-brand-dark dark:[&_strong]:text-white
                            [&_a]:text-brand dark:[&_a]:text-brand-lime [&_a]:underline hover:[&_a]:opacity-80">
                    {!! $policy->content !!}
                </div>

                {{-- Bottom Help Callout --}}
                <div class="mt-10 pt-6 border-t border-zinc-100 dark:border-[#223322] flex flex-col sm:flex-row items-start sm:items-center justify-between gap-4 bg-brand-bg/60 dark:bg-[#0e170e]/60 p-4 rounded-xl border border-brand-border/60 dark:border-[#223322]">
                    <div class="flex items-center gap-3">
                        <div class="w-10 h-10 rounded-xl bg-brand/10 text-brand dark:text-brand-lime flex items-center justify-center flex-shrink-0">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M9.879 7.519c1.171-1.025 3.071-1.025 4.242 0 1.172 1.025 1.172 2.687 0 3.712-.203.179-.43.326-.67.442-.745.361-1.45.999-1.45 1.827v.75M21 12a9 9 0 11-18 0 9 9 0 0118 0zm-9 5.25h.008v.008H12v-.008z"/>
                            </svg>
                        </div>
                        <div>
                            <h4 class="text-[13px] font-bold text-brand-dark dark:text-white">Questions about this policy?</h4>
                            <p class="text-[11px] text-brand-muted">Our compliance and support desk is available to assist you.</p>
                        </div>
                    </div>

                    <a href="mailto:support@gadgetplug.com.ng"
                       class="px-4 py-2 rounded-xl bg-brand hover:bg-[#055002] text-white text-[12px] font-semibold transition-colors flex-shrink-0">
                        Contact Support
                    </a>
                </div>

            </div>

        </div>
    </div>

</x-layouts.storefront>

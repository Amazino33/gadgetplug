<x-layouts.storefront title="Privacy Policy — GadgetPlug">

<style>
[data-custom-class='body'], [data-custom-class='body'] * { background: transparent !important; }
[data-custom-class='title'], [data-custom-class='title'] * { font-family: inherit !important; font-size: 2rem !important; color: inherit !important; }
[data-custom-class='subtitle'], [data-custom-class='subtitle'] * { font-family: inherit !important; color: #6b7280 !important; font-size: 0.875rem !important; }
[data-custom-class='heading_1'], [data-custom-class='heading_1'] * { font-family: inherit !important; font-size: 1.25rem !important; color: inherit !important; }
[data-custom-class='heading_2'], [data-custom-class='heading_2'] * { font-family: inherit !important; font-size: 1.1rem !important; color: inherit !important; }
[data-custom-class='body_text'], [data-custom-class='body_text'] * { color: #374151 !important; font-size: 0.9375rem !important; font-family: inherit !important; }
[data-custom-class='link'], [data-custom-class='link'] * { color: #2563eb !important; font-size: 0.9375rem !important; font-family: inherit !important; word-break: break-word !important; }
bdt { display: none; }
</style>

<div class="min-h-screen bg-gray-50">
    <div class="max-w-3xl mx-auto px-4 sm:px-6 py-12 sm:py-16">

        {{-- Back link --}}
        <a href="{{ route('home') }}"
            class="inline-flex items-center gap-2 text-sm text-gray-500 hover:text-gray-800 mb-8 transition-colors">
            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                <path stroke-linecap="round" stroke-linejoin="round" d="M15 19l-7-7 7-7"/>
            </svg>
            Back to GadgetPlug
        </a>

        {{-- Content card --}}
        <div class="bg-white rounded-2xl shadow-sm border border-gray-100 px-6 sm:px-10 py-10"
             data-custom-class="body">

            {{-- Paste the full HTML from your Termly privacy policy below this line --}}
            @include('pages.privacy-policy-content')

        </div>

        {{-- Footer note --}}
        <p class="text-center text-xs text-gray-400 mt-8">
            &copy; {{ date('Y') }} GadgetPlug / Lukratif Enterprise. All rights reserved.
        </p>

    </div>
</div>

</x-layouts.storefront>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $title ?? 'Procurement' }} — {{ config('app.name') }}</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600&family=Montserrat:wght@600;700&display=swap" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:wght,FILL@100..700,0..1&display=swap" rel="stylesheet">
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @livewireStyles
</head>
<body class="bg-[#f8f9fa] text-[#191c1d] min-h-screen antialiased" style="font-family: 'Inter', sans-serif;">

    {{-- Minimal top bar --}}
    <header class="bg-white border-b border-[#e1e3e4] px-6 py-3 flex items-center justify-between shadow-[0px_2px_10px_rgba(0,0,0,0.04)]">
        <div class="flex items-center gap-4">
            <a href="/plug" class="flex items-center gap-1.5 text-sm text-[#6f7b68] hover:text-[#016c00] transition-colors">
                <span class="material-symbols-outlined text-[18px]">arrow_back</span>
                Dashboard
            </a>
            <span class="text-[#becab5]">|</span>
            <h1 class="text-base font-bold text-[#016c00]" style="font-family:'Montserrat',sans-serif;">GadgetPlug</h1>
        </div>
        <div class="flex items-center gap-3">
            <span class="text-sm text-[#6f7b68]">{{ auth()->user()->name ?? '' }}</span>
            <div class="w-8 h-8 rounded-full bg-[#016c00] text-white flex items-center justify-center text-sm font-bold">
                {{ strtoupper(substr(auth()->user()->name ?? 'U', 0, 1)) }}
            </div>
        </div>
    </header>

    <main class="max-w-5xl mx-auto px-4 py-6">
        {{ $slot }}
    </main>

    @livewireScripts
</body>
</html>

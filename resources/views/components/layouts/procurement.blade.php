<!DOCTYPE html>
<html lang="en" x-data="{ dark: localStorage.getItem('darkMode') === 'true' }" x-init="$watch('dark', val => { localStorage.setItem('darkMode', val) })" :class="{ 'dark': dark }">
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
<body class="bg-zinc-50 dark:bg-zinc-900 text-zinc-900 dark:text-zinc-100 min-h-screen antialiased" style="font-family: 'Inter', sans-serif;">

    {{-- Minimal top bar --}}
    <header class="bg-white dark:bg-zinc-800 border-b border-[#e1e3e4] dark:border-zinc-700 px-6 py-3 flex items-center justify-between shadow-[0px_2px_10px_rgba(0,0,0,0.04)]">
        <div class="flex items-center gap-4">
            <a href="/plug" class="flex items-center gap-1.5 text-sm text-[#6f7b68] dark:text-zinc-400 hover:text-[#016c00] dark:hover:text-green-400 transition-colors">
                <span class="material-symbols-outlined text-[18px]">arrow_back</span>
                Dashboard
            </a>
            <span class="text-[#becab5] dark:text-zinc-600">|</span>
            <h1 class="text-base font-bold text-[#016c00] dark:text-green-400" style="font-family:'Montserrat',sans-serif;">GadgetPlug</h1>
        </div>
        <div class="flex items-center gap-3">
            <button @click="dark = !dark" class="w-8 h-8 rounded-lg flex items-center justify-center text-[#6f7b68] dark:text-zinc-400 hover:bg-[#f3f4f5] dark:hover:bg-zinc-700 transition-colors">
                <span x-show="!dark" class="material-symbols-outlined text-[20px]">dark_mode</span>
                <span x-show="dark" class="material-symbols-outlined text-[20px]">light_mode</span>
            </button>
            <span class="text-sm text-[#6f7b68] dark:text-zinc-400">{{ auth()->user()->name ?? '' }}</span>
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

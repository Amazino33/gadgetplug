@props(['title' => 'GadgetPlug'])
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1">
    <title>{{ $title }} — GadgetPlug</title>
    <link rel="icon" type="image/svg+xml" href="/images/logo.svg">
    <script>
        (function() {
            if (localStorage.getItem('gp-theme') === 'dark') {
                document.documentElement.classList.add('dark');
            }
        })();
    </script>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;600;700;900&family=Inter:wght@400;500;600&display=swap" rel="stylesheet">
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @livewireStyles
</head>
<body class="min-h-screen bg-[#0d1f0d] font-inter text-[#111] antialiased flex flex-col items-center justify-center px-4 py-10">

    {{-- Logo --}}
    <a href="{{ route('home') }}" class="mb-8 flex-shrink-0">
        <img src="/images/logo.svg" alt="GadgetPlug" class="h-12 w-auto brightness-0 invert">
    </a>

    {{-- Card --}}
    <div class="w-full max-w-[420px] bg-white dark:bg-[#162016] rounded-2xl shadow-[0_20px_60px_rgba(0,0,0,0.4)] border border-[#1a3a1a] dark:border-[#2a3a2a] p-7 md:p-8">
        {{ $slot }}
    </div>

    {{-- Footer note --}}
    <p class="mt-6 text-[11px] text-[#4a6a4c] text-center">
        © {{ date('Y') }} GadgetPlug Nigeria Ltd. &nbsp;·&nbsp;
        <a href="{{ route('home') }}" class="hover:text-[#7a9e7c] transition-colors">Back to store</a>
    </p>

    @livewireScripts
</body>
</html>

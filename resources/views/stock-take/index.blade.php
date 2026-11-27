<!DOCTYPE html>
<html lang="en" class="">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover" />
    <meta name="csrf-token" content="{{ csrf_token() }}" />
    <title>{{ $config['vendorName'] }} — Stock Take</title>
    <meta name="theme-color" content="#0F766E">
    {{-- On the home screen as "GadgetPlug Count" (release 2). Its worker controls /stock-take/ only. --}}
    <link rel="manifest" href="{{ url('/stock-take/' . $config['vendorSlug'] . '/manifest.webmanifest') }}">
    <link rel="apple-touch-icon" href="/apple-touch-icon.png">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-title" content="GadgetPlug Count">
    <link rel="preconnect" href="https://fonts.googleapis.com" />
    {{-- Fonts load without holding up the first paint: on a slow or absent
         network the app draws at once in the phone's own font. --}}
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Montserrat:wght@700;800;900&display=swap" rel="stylesheet" media="print" onload="this.media='all'" />
    <script>
        window.STOCK_TAKE_CONFIG = @json($config);
    </script>
    <script>if (localStorage.getItem('darkMode') === 'true') document.documentElement.classList.add('dark');</script>
    @viteReactRefresh
    @vite(['resources/js/stock-take/main.jsx'])
</head>
<body class="overflow-hidden">
    <div id="stock-take-root"></div>
</body>
</html>

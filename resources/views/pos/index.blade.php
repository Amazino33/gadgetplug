<!DOCTYPE html>
<html lang="en" class="">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <meta name="csrf-token" content="{{ csrf_token() }}" />
    <title>{{ $vendorName ? $vendorName . ' — POS' : 'GadgetPlug POS' }}</title>
    <link rel="manifest" href="/pos.webmanifest">
    <link rel="apple-touch-icon" sizes="180x180" href="/apple-touch-icon.png">
    <meta name="theme-color" content="#068B03">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <meta name="apple-mobile-web-app-title" content="GP POS">
    <link rel="preconnect" href="https://fonts.googleapis.com" />
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Montserrat:wght@700;800;900&display=swap" rel="stylesheet" />
    <script>
        window.POS_CONFIG = {
            vendorId:   @json($vendorId),
            vendorSlug: @json($vendorSlug),
            vendorName: @json($vendorName),
            panelUrl:   @json($panelUrl),
        };
    </script>
    <script>if (localStorage.getItem('darkMode') === 'true') document.documentElement.classList.add('dark');</script>
    @viteReactRefresh
    @vite(['resources/js/pos/main.jsx', 'resources/js/pwa.js'])
</head>
<body class="overflow-hidden">
    <div id="pos-root"></div>
</body>
</html>

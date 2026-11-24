<!DOCTYPE html>
<html lang="en" class="">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <meta name="csrf-token" content="{{ csrf_token() }}" />
    <title>{{ $config['vendorName'] }} — Purchases</title>
    <meta name="theme-color" content="#EA580C">
    <link rel="preconnect" href="https://fonts.googleapis.com" />
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Montserrat:wght@700;800;900&display=swap" rel="stylesheet" />
    <script>
        window.PURCHASES_CONFIG = @json($config);
    </script>
    <script>if (localStorage.getItem('darkMode') === 'true') document.documentElement.classList.add('dark');</script>
    @viteReactRefresh
    @vite(['resources/js/purchases/main.jsx'])
</head>
<body class="overflow-hidden">
    <div id="purchases-root"></div>
</body>
</html>

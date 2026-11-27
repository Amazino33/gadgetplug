<div class="space-y-4 text-center">
    <p class="text-sm text-gray-600 dark:text-gray-300">
        Print this and stick it up where counters can scan it. They scan it with their phone's camera, pick their name and type their 6-digit code.
    </p>

    <div class="mx-auto w-72 max-w-full rounded-xl bg-white p-3 shadow-sm ring-1 ring-gray-200">
        {!! $svg !!}
    </div>

    <p class="break-all rounded-lg bg-gray-50 p-3 text-xs text-gray-700 dark:bg-gray-800 dark:text-gray-200" x-data x-on:click="navigator.clipboard?.writeText(@js($url))">
        {{ $url }}
    </p>

    <button type="button" onclick="window.print()" class="text-sm font-semibold text-primary-600">Print this page</button>
</div>

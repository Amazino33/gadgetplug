{{-- While today's figures load (HomeDashboard is Lazy): the same shape as the
     real tiles, shimmering, so the page is usable at once and nothing jumps. --}}
<div class="space-y-6" aria-busy="true" aria-label="Loading today's figures">
    <div class="grid grid-cols-3 gap-4">
        @foreach (range(1, 3) as $i)
            <div class="rounded-xl border border-gray-200 bg-white p-4 dark:border-white/10 dark:bg-gray-900">
                <div class="gp-shimmer h-3 w-20"></div>
                <div class="gp-shimmer mt-3 h-6 w-24"></div>
            </div>
        @endforeach
    </div>

    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
        @foreach (range(1, 2) as $i)
            <div class="rounded-xl border border-gray-200 bg-white p-5 dark:border-white/10 dark:bg-gray-900">
                <div class="gp-shimmer h-3 w-28"></div>
                <div class="gp-shimmer mt-4 h-9 w-40"></div>
                <div class="gp-shimmer mt-3 h-3 w-32"></div>
            </div>
        @endforeach
    </div>

    <div class="grid grid-cols-2 gap-4">
        @foreach (range(1, 4) as $i)
            <div class="rounded-xl border border-gray-200 bg-white p-4 dark:border-white/10 dark:bg-gray-900">
                <div class="gp-shimmer h-3 w-24"></div>
                <div class="gp-shimmer mt-3 h-7 w-20"></div>
            </div>
        @endforeach
    </div>
</div>

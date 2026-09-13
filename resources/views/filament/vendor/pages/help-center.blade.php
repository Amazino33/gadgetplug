@php
    $article     = $this->getArticle();
    $category    = $this->getCategory();
    $searching   = $this->isSearching();
    $articleTour = $this->getArticleTour();
@endphp

<x-filament-panels::page>

    {{-- Search. Always at the top, in every state: someone who opened a guide
         and found it was the wrong one should not have to go back first. --}}
    <div class="mb-6">
        <div class="relative">
            <x-filament::icon
                icon="heroicon-o-magnifying-glass"
                class="pointer-events-none absolute start-3 top-1/2 h-5 w-5 -translate-y-1/2 text-gray-400"
            />

            <input
                type="search"
                wire:model.live.debounce.400ms="search"
                placeholder="Search the guides — try &quot;procurement&quot; or &quot;add a product&quot;"
                class="w-full rounded-xl border border-gray-300 bg-white py-3 ps-11 pe-4 text-sm text-gray-900 shadow-sm outline-none transition focus:border-primary-500 focus:ring-1 focus:ring-primary-500 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100"
            />
        </div>
    </div>

    {{-- Where you are. Only rendered once you have gone somewhere, so the
         landing page is not topped by a breadcrumb pointing at itself. --}}
    @if ($searching || $category || $article)
        <nav class="mb-4 flex flex-wrap items-center gap-2 text-sm text-gray-500 dark:text-gray-400">
            <button
                type="button"
                wire:click="$set('search', ''); $set('categorySlug', null); $set('articleSlug', null)"
                class="font-medium text-primary-600 hover:underline dark:text-primary-400"
            >
                All guides
            </button>

            @if ($searching)
                <span>/</span>
                <span>Results for &ldquo;{{ $this->search }}&rdquo;</span>
            @elseif ($article?->category)
                <span>/</span>
                <button
                    type="button"
                    wire:click="$set('articleSlug', null); $set('categorySlug', '{{ $article->category->slug }}')"
                    class="font-medium text-primary-600 hover:underline dark:text-primary-400"
                >
                    {{ $article->category->name }}
                </button>
                <span>/</span>
                <span class="truncate">{{ $article->title }}</span>
            @elseif ($category)
                <span>/</span>
                <span>{{ $category->name }}</span>
            @endif
        </nav>
    @endif

    {{-- ─────────────────────────────── Article ─────────────────────────── --}}
    @if ($article)
        <article class="rounded-xl border border-gray-200 bg-white p-6 shadow-sm dark:border-gray-700 dark:bg-gray-900">
            <h1 class="text-2xl font-bold text-gray-900 dark:text-white">{{ $article->title }}</h1>

            @if ($article->excerpt)
                <p class="mt-2 text-sm text-gray-500 dark:text-gray-400">{{ $article->excerpt }}</p>
            @endif

            @if ($articleTour)
                <div class="mt-5 flex flex-col gap-3 rounded-lg border border-primary-200 bg-primary-50 p-4 sm:flex-row sm:items-center sm:justify-between dark:border-primary-900/50 dark:bg-primary-950/30">
                    <div>
                        <p class="text-sm font-semibold text-gray-900 dark:text-white">
                            Rather be shown than told?
                        </p>
                        <p class="text-sm text-gray-600 dark:text-gray-400">
                            {{ $articleTour['description'] }}
                        </p>
                    </div>

                    <x-filament::button
                        tag="button"
                        type="button"
                        icon="heroicon-o-play"
                        x-on:click="window.gpTours?.start(@js($articleTour['key']))"
                        class="shrink-0"
                    >
                        Show me on the real screens
                    </x-filament::button>
                </div>
            @endif

            {{-- Author-written HTML. Images inside carry loading="lazy", stamped
                 on by HelpArticle::renderedBody(). --}}
            <div class="gp-help-body mt-6">
                {!! $article->renderedBody() !!}
            </div>
        </article>

    {{-- ──────────────────────── Search results / a category ─────────────── --}}
    @elseif ($searching || $category)
        @php $articles = $this->getArticles(); @endphp

        @if ($articles->isEmpty())
            <div class="rounded-xl border border-dashed border-gray-300 p-10 text-center dark:border-gray-700">
                <x-filament::icon icon="heroicon-o-magnifying-glass" class="mx-auto h-8 w-8 text-gray-400" />
                <p class="mt-3 font-semibold text-gray-900 dark:text-white">Nothing matches that yet</p>
                <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                    Try a single word — &ldquo;supplier&rdquo;, &ldquo;stock&rdquo;, &ldquo;receipt&rdquo;.
                    If the guide you need is missing, tell us and we will write it.
                </p>
            </div>
        @else
            <ul class="divide-y divide-gray-200 overflow-hidden rounded-xl border border-gray-200 bg-white dark:divide-gray-800 dark:border-gray-700 dark:bg-gray-900">
                @foreach ($articles as $item)
                    <li>
                        <button
                            type="button"
                            wire:click="$set('articleSlug', '{{ $item->slug }}')"
                            class="flex w-full items-start gap-3 p-4 text-start transition hover:bg-gray-50 dark:hover:bg-gray-800/60"
                        >
                            <x-filament::icon icon="heroicon-o-document-text" class="mt-0.5 h-5 w-5 shrink-0 text-gray-400" />

                            <span class="min-w-0 flex-1">
                                <span class="block font-semibold text-gray-900 dark:text-white">{{ $item->title }}</span>

                                @if ($item->excerpt)
                                    <span class="mt-0.5 block text-sm text-gray-500 dark:text-gray-400">{{ $item->excerpt }}</span>
                                @endif

                                @if ($searching && $item->category)
                                    <span class="mt-1 inline-block text-xs font-medium uppercase tracking-wide text-gray-400">
                                        {{ $item->category->name }}
                                    </span>
                                @endif
                            </span>

                            @if ($item->tour_key)
                                <x-filament::badge color="info" class="shrink-0">Tour</x-filament::badge>
                            @endif
                        </button>
                    </li>
                @endforeach
            </ul>
        @endif

    {{-- ─────────────────────────────── Landing ─────────────────────────── --}}
    @else
        @php $categories = $this->getCategories(); @endphp

        @if ($categories->isEmpty())
            <div class="rounded-xl border border-dashed border-gray-300 p-10 text-center dark:border-gray-700">
                <x-filament::icon icon="heroicon-o-book-open" class="mx-auto h-8 w-8 text-gray-400" />
                <p class="mt-3 font-semibold text-gray-900 dark:text-white">No guides published yet</p>
                <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                    They are on their way. The tours below already work.
                </p>
            </div>
        @else
            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-3">
                @foreach ($categories as $item)
                    <button
                        type="button"
                        wire:click="$set('categorySlug', '{{ $item->slug }}')"
                        class="flex items-start gap-3 rounded-xl border border-gray-200 bg-white p-5 text-start shadow-sm transition hover:-translate-y-0.5 hover:shadow-md dark:border-gray-700 dark:bg-gray-900"
                    >
                        <x-filament::icon
                            :icon="$item->icon ?: 'heroicon-o-book-open'"
                            class="h-6 w-6 shrink-0 text-primary-600 dark:text-primary-400"
                        />

                        <span>
                            <span class="block font-semibold text-gray-900 dark:text-white">{{ $item->name }}</span>
                            <span class="mt-0.5 block text-sm text-gray-500 dark:text-gray-400">
                                {{ $item->published_articles_count }}
                                {{ \Illuminate\Support\Str::plural('guide', $item->published_articles_count) }}
                            </span>
                        </span>
                    </button>
                @endforeach
            </div>
        @endif

        {{-- Tours. Below the articles rather than above them: someone who came
             here with a question wants the answer, and only some questions have
             a tour. Every tour stays startable here forever, including ones
             this person has already dismissed elsewhere. --}}
        <section class="mt-8">
            <h2 class="text-base font-semibold text-gray-900 dark:text-white">Guided tours</h2>
            <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                These highlight the real buttons on the real screens and walk you through, one tap at a time.
            </p>

            <div class="mt-4 grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-3">
                @foreach ($this->getTours() as $tour)
                    <div class="flex flex-col rounded-xl border border-gray-200 bg-white p-5 shadow-sm dark:border-gray-700 dark:bg-gray-900">
                        <div class="flex items-start gap-3">
                            <x-filament::icon
                                :icon="$tour['icon']"
                                class="h-6 w-6 shrink-0 text-primary-600 dark:text-primary-400"
                            />

                            <div class="min-w-0">
                                <p class="font-semibold text-gray-900 dark:text-white">{{ $tour['title'] }}</p>
                                <p class="mt-0.5 text-sm text-gray-500 dark:text-gray-400">{{ $tour['description'] }}</p>
                            </div>
                        </div>

                        <div class="mt-4 flex items-center gap-2">
                            <x-filament::button
                                tag="button"
                                type="button"
                                size="sm"
                                icon="heroicon-o-play"
                                x-on:click="window.gpTours?.start(@js($tour['key']))"
                            >
                                {{ $tour['seen'] ? 'Run it again' : 'Start' }}
                            </x-filament::button>

                            @if ($tour['seen'])
                                <span class="text-xs text-gray-400">Already taken</span>
                            @endif
                        </div>
                    </div>
                @endforeach
            </div>
        </section>
    @endif

</x-filament-panels::page>

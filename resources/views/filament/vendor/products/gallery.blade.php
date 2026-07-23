@php
    $media = $record->getMedia('product-images');
    $images = $media->map(fn ($m) => [
        'preview' => $m->getUrl('preview'),
        'thumb'   => $m->getUrl('thumb'),
    ])->values();
@endphp

<div x-data="{
        images: {{ Illuminate\Support\Js::from($images) }},
        active: 0,
    }"
    class="space-y-3">

    <div class="aspect-square w-full overflow-hidden rounded-xl border border-gray-200 bg-gray-100 dark:border-white/10 dark:bg-gray-800">
        <template x-if="images.length > 0">
            <img :src="images[active].preview" :alt="{{ Illuminate\Support\Js::from($record->name) }}"
                class="h-full w-full object-contain p-4">
        </template>
        <template x-if="images.length === 0">
            <div class="flex h-full w-full items-center justify-center">
                <x-heroicon-o-photo class="h-16 w-16 text-gray-300 dark:text-gray-600"/>
            </div>
        </template>
    </div>

    <div x-show="images.length > 1" class="flex gap-2 overflow-x-auto pb-1">
        <template x-for="(image, index) in images" :key="index">
            <button type="button"
                @click="active = index"
                :aria-label="'View image ' + (index + 1)"
                :aria-current="active === index"
                class="h-16 w-16 shrink-0 overflow-hidden rounded-lg border-2 transition focus:outline-none focus-visible:ring-2 focus-visible:ring-primary-500"
                :class="active === index ? 'border-primary-500' : 'border-gray-200 dark:border-white/10 hover:border-gray-300'">
                <img :src="image.thumb" class="h-full w-full object-cover" alt="">
            </button>
        </template>
    </div>
</div>

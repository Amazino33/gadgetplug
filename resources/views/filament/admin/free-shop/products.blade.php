{{-- Thumbnails of what the shop has added so far, newest first. Inline styles: see verification-photo. --}}
@php
    $products = $getRecord()->products()->with('media')->latest('id')->limit(12)->get();
@endphp

@if ($products->isEmpty())
    <p style="font-size:14px;opacity:.6;">No products yet.</p>
@else
    <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(72px,1fr));gap:8px;">
        @foreach ($products as $product)
            @php
                $media = $product->getFirstMedia('product-images');
                $thumb = $media ? ($media->hasGeneratedConversion('thumb') ? $media->getUrl('thumb') : $media->getUrl()) : null;
            @endphp
            <div title="{{ $product->name }}" style="text-align:center;">
                @if ($thumb)
                    <img src="{{ $thumb }}" alt="" style="width:100%;aspect-ratio:1;object-fit:cover;border-radius:8px;">
                @else
                    <div style="width:100%;aspect-ratio:1;border-radius:8px;background:rgba(127,127,127,.15);display:flex;align-items:center;justify-content:center;font-size:11px;opacity:.6;">No photo</div>
                @endif
                <p style="font-size:11px;margin-top:4px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">{{ $product->name }}</p>
            </div>
        @endforeach
    </div>
@endif

<x-mail::message>
# {{ $heading }}

@foreach ($lines as $line)
{{ $line }}

@endforeach
@if ($actionText && $actionUrl)
<x-mail::button :url="$actionUrl">
{{ $actionText }}
</x-mail::button>
@endif

Thanks,<br>
{{ config('app.name') }}
</x-mail::message>

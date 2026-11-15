@php
    use App\Filament\Resources\Users\Pages\EditUser;

    /** @var \App\Models\User $record */
    $record = $getRecord();
    $roles = $record->resolvedRolesWithContext();
    $stores = $record->resolvedStores();
    $vendors = $record->vendors();

    $editUrl = EditUser::getUrl(['record' => $record]);
@endphp

<div class="gp-card">
    {{-- Card Header: User Initials, Name, and Primary Role Badge --}}
    <div class="gp-dispatch-card-head">
        <div class="flex items-center gap-2.5 min-w-0">
            <div class="flex-shrink-0 flex items-center justify-center w-9 h-9 rounded-full bg-primary-100 text-primary-700 dark:bg-primary-950 dark:text-primary-300 font-semibold text-xs border border-primary-200 dark:border-primary-800">
                {{ $record->initials() ?: 'U' }}
            </div>
            <div class="min-w-0">
                <a href="{{ $editUrl }}" class="gp-card-title font-semibold hover:underline block truncate text-sm text-gray-900 dark:text-white">
                    {{ $record->name }}
                </a>
                @if ($record->created_at)
                    <span class="text-xs text-gray-500 dark:text-gray-400">
                        Joined {{ $record->created_at->format('M Y') }}
                    </span>
                @endif
            </div>
        </div>

        <div class="flex flex-wrap items-center gap-1 justify-end flex-shrink-0">
            @foreach ($roles->take(2) as $role)
                <x-filament::badge :color="$role['color']" size="sm">
                    {{ $role['name'] }}
                </x-filament::badge>
            @endforeach
        </div>
    </div>

    {{-- Card Body: Store & Vendor, Contact Information --}}
    <div class="gp-card-body space-y-1.5 pt-1">
        {{-- Store Location / Business --}}
        @if ($stores->isNotEmpty() || $vendors->isNotEmpty())
            <div class="flex items-start gap-1.5 text-xs text-gray-700 dark:text-gray-300">
                <x-filament::icon icon="heroicon-m-building-storefront" class="w-4 h-4 text-gray-400 flex-shrink-0 mt-0.5" />
                <div class="min-w-0">
                    @if ($stores->isNotEmpty())
                        <span class="font-medium text-gray-900 dark:text-gray-100">
                            {{ $stores->pluck('name')->join(', ') }}
                        </span>
                    @endif
                    @if ($vendors->isNotEmpty())
                        <span class="text-gray-500 dark:text-gray-400">
                            ({{ $vendors->pluck('name')->join(', ') }})
                        </span>
                    @endif
                </div>
            </div>
        @else
            <div class="flex items-center gap-1.5 text-xs text-gray-500 dark:text-gray-400">
                <x-filament::icon icon="heroicon-m-user" class="w-4 h-4 text-gray-400 flex-shrink-0" />
                <span>Customer (No store)</span>
            </div>
        @endif

        {{-- Email --}}
        <div class="flex items-center gap-1.5 text-xs text-gray-600 dark:text-gray-400">
            <x-filament::icon icon="heroicon-m-envelope" class="w-4 h-4 text-gray-400 flex-shrink-0" />
            <a href="mailto:{{ $record->email }}" class="hover:underline truncate text-primary-600 dark:text-primary-400">
                {{ $record->email }}
            </a>
        </div>

        {{-- Phone --}}
        @if (filled($record->phone))
            <div class="flex items-center gap-1.5 text-xs text-gray-600 dark:text-gray-400">
                <x-filament::icon icon="heroicon-m-phone" class="w-4 h-4 text-gray-400 flex-shrink-0" />
                <a href="tel:{{ $record->phone }}" class="hover:underline text-gray-800 dark:text-gray-200">
                    {{ $record->phone }}
                </a>
            </div>
        @endif
    </div>

    {{-- Card Actions --}}
    <div class="gp-card-actions pt-2 flex items-center justify-end">
        <x-filament::button
            tag="a"
            :href="$editUrl"
            size="sm"
            color="gray"
            icon="heroicon-m-pencil-square"
        >
            Edit
        </x-filament::button>
    </div>
</div>

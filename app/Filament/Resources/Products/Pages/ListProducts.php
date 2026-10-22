<?php

namespace App\Filament\Resources\Products\Pages;

use App\Filament\Resources\Products\ProductResource;
use Filament\Resources\Pages\ListRecords;

class ListProducts extends ListRecords
{
    protected static string $resource = ProductResource::class;

    /**
     * The two filters the catalogue editor works through, as chips beside the
     * search (resources/views/filament/admin/products/quick-filters.blade.php).
     * Each is the table's own toggle filter of the same name, so a chip and the
     * filter panel always agree.
     */
    public const QUICK_FILTERS = [
        'no_photo'       => 'No photo',
        'no_online_name' => 'No online name',
    ];

    public function toggleQuickFilter(string $filter): void
    {
        if (! array_key_exists($filter, self::QUICK_FILTERS)) {
            return;
        }

        $this->tableFilters[$filter]['isActive'] = ! ($this->tableFilters[$filter]['isActive'] ?? false);

        // What Livewire calls when the filter form itself changes: keeps the
        // deferred filter form in step and goes back to page one.
        $this->updatedTableFilters();
    }
}

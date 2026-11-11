<?php

declare(strict_types=1);

namespace App\Filament\Resources\AppFeatures\Pages;

use App\Filament\Resources\AppFeatures\AppFeatureResource;
use Filament\Resources\Pages\ListRecords;

class ListAppFeatures extends ListRecords
{
    protected static string $resource = AppFeatureResource::class;

    public function getSubheading(): ?string
    {
        return 'Drag to set the Learn page order. New features appear here after a deploy runs `php artisan features:sync`.';
    }
}

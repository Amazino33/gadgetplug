<?php

declare(strict_types=1);

namespace App\Filament\Resources\AssistantMessages\Pages;

use App\Filament\Resources\AssistantMessages\AssistantMessageResource;
use Filament\Resources\Pages\ListRecords;

class ListAssistantMessages extends ListRecords
{
    protected static string $resource = AssistantMessageResource::class;

    protected function getHeaderActions(): array
    {
        return [
            // Strictly no create action
        ];
    }
}

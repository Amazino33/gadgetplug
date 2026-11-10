<?php

declare(strict_types=1);

namespace App\Filament\Resources\AssistantUnansweredQuestions\Pages;

use App\Filament\Resources\AssistantUnansweredQuestions\AssistantUnansweredQuestionResource;
use Filament\Resources\Pages\ListRecords;

class ListAssistantUnansweredQuestions extends ListRecords
{
    protected static string $resource = AssistantUnansweredQuestionResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}

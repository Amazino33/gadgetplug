<?php

namespace App\Filament\Resources\HelpArticles\Pages;

use App\Filament\Resources\HelpArticles\HelpArticleResource;
use Filament\Resources\Pages\CreateRecord;

class CreateHelpArticle extends CreateRecord
{
    protected static string $resource = HelpArticleResource::class;

    // Straight to the editor after saving. Pictures can only attach to a saved
    // article, so the first save is always followed by going back in to add
    // them -- landing on the list would just be a click in the way.
    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('edit', ['record' => $this->getRecord()]);
    }
}

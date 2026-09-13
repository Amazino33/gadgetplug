<?php

namespace App\Filament\Resources\HelpArticles\Pages;

use App\Filament\Resources\HelpArticles\HelpArticleResource;
use App\Filament\Vendor\Pages\HelpCenter;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditHelpArticle extends EditRecord
{
    protected static string $resource = HelpArticleResource::class;

    protected function getHeaderActions(): array
    {
        return [
            // Where the vendor will land. Worth one click to check a screenshot
            // came out the right size before telling anyone the guide exists.
            Action::make('preview')
                ->label('View as a vendor')
                ->icon('heroicon-o-arrow-top-right-on-square')
                ->color('gray')
                ->url(fn (): string => HelpCenter::previewUrlFor($this->getRecord()))
                ->openUrlInNewTab()
                ->visible(fn (): bool => HelpCenter::previewUrlFor($this->getRecord()) !== ''),

            DeleteAction::make(),
        ];
    }
}

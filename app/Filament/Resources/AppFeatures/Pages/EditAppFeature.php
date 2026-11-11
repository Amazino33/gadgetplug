<?php

declare(strict_types=1);

namespace App\Filament\Resources\AppFeatures\Pages;

use App\Filament\Resources\AppFeatures\AppFeatureResource;
use App\Models\AppFeature;
use App\Support\Engagement\TipMedia;
use Filament\Resources\Pages\EditRecord;

class EditAppFeature extends EditRecord
{
    protected static string $resource = AppFeatureResource::class;

    protected function afterSave(): void
    {
        /** @var AppFeature $feature */
        $feature = $this->getRecord();

        TipMedia::ensureGifPoster($feature, AppFeature::VIDEO_COLLECTION, AppFeature::POSTER_COLLECTION);
    }
}

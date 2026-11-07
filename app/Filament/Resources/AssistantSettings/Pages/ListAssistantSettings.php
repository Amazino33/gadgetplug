<?php

declare(strict_types=1);

namespace App\Filament\Resources\AssistantSettings\Pages;

use App\Filament\Resources\AssistantSettings\AssistantSettingResource;
use App\Models\AssistantSetting;
use App\Models\User;
use Filament\Resources\Pages\ListRecords;

class ListAssistantSettings extends ListRecords
{
    protected static string $resource = AssistantSettingResource::class;

    public function mount(): void
    {
        parent::mount();

        // Ensure all vendor users have a settings row so administrators can toggle them
        $vendorUserIds = User::query()
            ->where(function ($query) {
                $query->whereHas('ownedVendors')
                    ->orWhereHas('memberVendors');
            })
            ->pluck('id');

        $existingIds = AssistantSetting::query()
            ->whereIn('user_id', $vendorUserIds)
            ->pluck('user_id');

        $missingIds = $vendorUserIds->diff($existingIds);

        if ($missingIds->isNotEmpty()) {
            $now = now();
            $records = $missingIds->map(fn ($id) => [
                'user_id' => $id,
                'admin_disabled' => false,
                'vendor_disabled' => false,
                'created_at' => $now,
                'updated_at' => $now,
            ])->all();

            AssistantSetting::insert($records);
        }
    }
}

<?php

namespace App\Filament\Resources\AffiliateTaskSubmissions\Pages;

use App\Filament\Resources\AffiliateTaskSubmissions\AffiliateTaskSubmissionResource;
use App\Models\AffiliateTaskSubmission;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;

class ListAffiliateTaskSubmissions extends ListRecords
{
    protected static string $resource = AffiliateTaskSubmissionResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }

    public function getTabs(): array
    {
        return [
            'pending' => Tab::make('Pending Review')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', 'submitted'))
                ->badge(AffiliateTaskSubmission::where('status', 'submitted')->count()),
            'approved' => Tab::make('Approved')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', 'approved')),
            'rejected' => Tab::make('Rejected')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', 'rejected')),
            'all' => Tab::make('All'),
        ];
    }
}

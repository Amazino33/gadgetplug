<?php

declare(strict_types=1);

namespace App\Support\Engagement\Features;

use Illuminate\Support\Facades\DB;

class TeamMembersFeature extends Feature
{
    public function key(): string
    {
        return 'team_members';
    }

    public function defaultTitle(): string
    {
        return 'Add your staff';
    }

    public function defaultDescription(): string
    {
        return 'Give each staff member their own login, so you can see who sold what.';
    }

    public function destinationRoute(): ?string
    {
        return 'filament.vendor.pages.team-members';
    }

    protected function usageSources(): array
    {
        return [
            // The owner is sometimes on vendor_users too; only other people
            // count. Who sent the invite is not recorded: business-wide only.
            new UsageSource(
                query: fn () => DB::table('vendor_users')
                    ->join('vendors', 'vendors.id', '=', 'vendor_users.vendor_id')
                    ->whereColumn('vendor_users.user_id', '!=', 'vendors.user_id'),
                vendorColumn: 'vendor_users.vendor_id',
                usedAtColumn: 'vendor_users.created_at',
            ),
        ];
    }
}

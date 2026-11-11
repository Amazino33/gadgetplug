<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\AppFeature;
use App\Support\Engagement\FeatureRegistry;
use Illuminate\Console\Command;

/**
 * Brings app_features in line with FeatureRegistry.
 *
 * New features get a row with their default copy, hidden-by-default never: an
 * admin can untick it. Existing rows keep whatever an admin wrote — only the
 * registered flag is touched. Rows whose class is gone are marked unregistered,
 * not deleted, so old tip events still name something.
 *
 * Safe to run on every deploy.
 */
class SyncAppFeaturesCommand extends Command
{
    protected $signature = 'features:sync';

    protected $description = 'Create or refresh an app_features row for every feature in the registry';

    public function handle(FeatureRegistry $registry): int
    {
        $created = 0;
        $order = 0;

        foreach ($registry->all() as $key => $feature) {
            $order += 10;

            $row = AppFeature::query()->firstOrNew(['key' => $key]);

            if (! $row->exists) {
                $row->fill([
                    'title' => $feature->defaultTitle(),
                    'description' => $feature->defaultDescription(),
                    'sort_order' => $order,
                    'is_visible' => true,
                ]);
                $created++;
            }

            $row->is_registered = true;
            $row->save();
        }

        $retired = AppFeature::query()
            ->whereNotIn('key', $registry->keys())
            ->where('is_registered', true)
            ->update(['is_registered' => false]);

        $this->info(sprintf(
            '%d feature(s) in the registry: %d new, %d no longer registered.',
            count($registry->all()),
            $created,
            $retired,
        ));

        return self::SUCCESS;
    }
}

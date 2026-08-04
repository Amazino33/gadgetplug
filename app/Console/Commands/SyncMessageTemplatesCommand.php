<?php

namespace App\Console\Commands;

use App\Models\MessageTemplate;
use App\Models\Vendor;
use Database\Seeders\MessageTemplateSeeder;
use Illuminate\Console\Command;

// MessageTemplateSeeder uses firstOrCreate so it never disturbs wording a vendor
// has edited — which also means shipping improved defaults does nothing for
// vendors already seeded. This command is the deliberate opt-in that rewrites
// them, kept separate precisely because it destroys customisations.
class SyncMessageTemplatesCommand extends Command
{
    protected $signature = 'messages:sync-templates
                            {--vendor= : Restrict to one vendor id}
                            {--missing-only : Only create templates that do not exist yet, never overwrite}';

    protected $description = 'Rewrite vendor WhatsApp/SMS message templates to the current defaults';

    public function handle(): int
    {
        $vendors = Vendor::query()
            ->when($this->option('vendor'), fn ($query, $id) => $query->whereKey($id))
            ->get();

        if ($vendors->isEmpty()) {
            $this->error('No vendors matched.');

            return self::FAILURE;
        }

        $missingOnly = (bool) $this->option('missing-only');

        if (! $missingOnly && ! $this->confirmOverwrite($vendors->count())) {
            $this->warn('Aborted. Re-run with --missing-only to add new templates without touching existing wording.');

            return self::SUCCESS;
        }

        $created = 0;
        $updated = 0;

        foreach ($vendors as $vendor) {
            foreach (MessageTemplateSeeder::defaults() as $default) {
                $existing = MessageTemplate::query()
                    ->where('vendor_id', $vendor->id)
                    ->where('key', $default['key'])
                    ->first();

                if ($existing && $missingOnly) {
                    continue;
                }

                if ($existing) {
                    // is_active is left alone: a vendor who switched a message off
                    // meant it, and a wording refresh is no reason to turn it back on.
                    $existing->update([
                        'recipient_type' => $default['recipient_type'],
                        'channel'        => $default['channel'],
                        'body'           => $default['body'],
                    ]);
                    $updated++;

                    continue;
                }

                MessageTemplate::create([
                    'vendor_id'      => $vendor->id,
                    'key'            => $default['key'],
                    'recipient_type' => $default['recipient_type'],
                    'channel'        => $default['channel'],
                    'body'           => $default['body'],
                ]);
                $created++;
            }
        }

        $this->info("Done. {$created} created, {$updated} updated, across {$vendors->count()} vendor(s).");

        return self::SUCCESS;
    }

    private function confirmOverwrite(int $vendorCount): bool
    {
        $this->warn("This REPLACES the message wording for {$vendorCount} vendor(s).");
        $this->warn('Any template text edited by a vendor will be lost.');

        return $this->confirm('Continue?', false);
    }
}

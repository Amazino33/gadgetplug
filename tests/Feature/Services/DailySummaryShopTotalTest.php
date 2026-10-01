<?php

// The daily summary's "POS total" line became "Shop total" in October 2026:
// "POS" is the card machine in Nigeria, and the till is now the Sales Point.

use App\Models\MessageTemplate;
use App\Models\User;
use App\Models\Vendor;
use Database\Seeders\MessageTemplateSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function summaryTemplateFor(Vendor $vendor): MessageTemplate
{
    return MessageTemplate::where('vendor_id', $vendor->id)->where('key', 'vendor_daily_summary')->firstOrFail();
}

function renamePosTotalMigration(): object
{
    return require database_path('migrations/2026_10_01_100000_rename_pos_total_in_daily_summaries.php');
}

beforeEach(function () {
    $this->vendor = Vendor::create(['user_id' => User::factory()->create()->id, 'name' => 'Summary Store']);
    MessageTemplateSeeder::forVendor($this->vendor);
});

test('a new vendor gets the shop total wording', function () {
    expect(summaryTemplateFor($this->vendor)->body)
        ->toContain('• Shop total: {{pos_taken}}')
        ->not->toContain('POS total');
});

test('an existing summary has only that line changed, and the vendor edits survive', function () {
    // A vendor seeded before the rename, who has also reworded the heading.
    $template = summaryTemplateFor($this->vendor);
    $template->update(['body' => str_replace(
        ['• Shop total: {{pos_taken}}', 'Daily Summary'],
        ['• POS total: {{pos_taken}}', 'End of Day Report'],
        $template->body,
    )]);

    renamePosTotalMigration()->up();

    expect(summaryTemplateFor($this->vendor)->body)
        ->toContain('• Shop total: {{pos_taken}}')
        ->toContain('End of Day Report')
        ->not->toContain('POS total');
});

test('a vendor who already reworded the line is left alone', function () {
    $template = summaryTemplateFor($this->vendor);
    $template->update(['body' => str_replace('• Shop total: {{pos_taken}}', '• Till total: {{pos_taken}}', $template->body)]);

    renamePosTotalMigration()->up();

    expect(summaryTemplateFor($this->vendor)->body)->toContain('• Till total: {{pos_taken}}');
});

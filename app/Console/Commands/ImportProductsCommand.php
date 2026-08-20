<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Vendor;
use App\Services\Import\ColumnMapper;
use App\Services\Import\ImportPreparer;
use App\Services\Import\ParsedRow;
use App\Services\Import\ProductImporter;
use App\Services\Import\SpreadsheetReader;
use Illuminate\Console\Command;
use Throwable;

/**
 * The same import, driven from the terminal.
 *
 * The wizard is the normal route, but a browser hides failures behind a
 * Livewire request that can return 200 having done nothing, and on shared
 * hosting the person hitting it cannot read the log. This runs the identical
 * services - same mapping, same validation, same matching, same transaction -
 * and prints whatever goes wrong in full.
 *
 * It also has no request timeout, so a catalogue too large for one web request
 * imports in a single pass here.
 */
class ImportProductsCommand extends Command
{
    protected $signature = 'products:import
                            {file : Path to the CSV or XLSX file}
                            {--vendor= : Vendor id to import into}
                            {--dry-run : Show what would happen and write nothing}';

    protected $description = 'Import a product catalogue from a CSV or Excel file';

    public function handle(): int
    {
        $path = $this->argument('file');

        if (! is_readable($path)) {
            $this->error("Cannot read {$path}");

            return self::FAILURE;
        }

        $vendor = $this->resolveVendor();

        if ($vendor === null) {
            return self::FAILURE;
        }

        $this->line("Vendor: <info>{$vendor->name}</info> (#{$vendor->id})");

        // There is no active store outside the panel, so ProductObserver homes
        // new products in this vendor's default branch. Stated rather than left
        // to be discovered, since it decides which branch's till and count
        // sheet they appear on.
        $this->line("New products are homed in this vendor's default branch.");

        try {
            $headers = app(SpreadsheetReader::class)->headers($path);
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $mapping = app(ColumnMapper::class)->guess($headers);

        $this->newLine();
        $this->line('Columns matched:');

        foreach ($headers as $header) {
            $field = $mapping[$header] ?? null;

            $this->line(sprintf(
                '  %-28s %s',
                $header,
                $field === null ? '<comment>ignored</comment>' : "<info>{$field}</info>",
            ));
        }

        if (! in_array('name', $mapping, true)) {
            $this->newLine();
            $this->error('No column matched Name. Every product needs one, so nothing can be imported.');

            return self::FAILURE;
        }

        $this->newLine();

        try {
            $rows = app(ImportPreparer::class)->prepare($path, $mapping, $vendor);
        } catch (Throwable $e) {
            $this->reportFailure($e);

            return self::FAILURE;
        }

        $summary = app(ImportPreparer::class)->summarise($rows);

        $this->line(sprintf(
            'Would create <info>%d</info>, update <comment>%d</comment>, skip <fg=red>%d</> of %d row(s).',
            $summary['create'],
            $summary['update'],
            $summary['skip'],
            $summary['total'],
        ));

        $problems = $rows->filter(fn (ParsedRow $row) => $row->errors !== []);

        foreach ($problems->take(20) as $row) {
            $this->line("  <fg=red>line {$row->line}</> {$row->name()}: ".implode(' ', $row->errors));
        }

        if ($problems->count() > 20) {
            $this->line('  … and '.($problems->count() - 20).' more.');
        }

        if ($this->option('dry-run')) {
            $this->newLine();
            $this->info('Dry run - nothing was written.');

            return self::SUCCESS;
        }

        if ($summary['create'] + $summary['update'] === 0) {
            $this->newLine();
            $this->error('Every row has an error. Nothing to import.');

            return self::FAILURE;
        }

        $this->newLine();

        $bar = $this->output->createProgressBar($summary['create'] + $summary['update']);
        $bar->start();

        try {
            $log = app(ProductImporter::class)->commit(
                $rows,
                $vendor,
                null,
                basename($path),
                fn (int $done) => $bar->setProgress($done),
            );
        } catch (Throwable $e) {
            $bar->finish();
            $this->newLine(2);
            $this->reportFailure($e);

            return self::FAILURE;
        }

        $bar->finish();
        $this->newLine(2);

        $this->info($log->summary().'.');

        if ($log->snapshot_path) {
            $this->line("A copy of the catalogue before this import: {$log->snapshot_path}");
        }

        return self::SUCCESS;
    }

    private function resolveVendor(): ?Vendor
    {
        if ($id = $this->option('vendor')) {
            $vendor = Vendor::find($id);

            if ($vendor === null) {
                $this->error("No vendor with id {$id}.");
            }

            return $vendor;
        }

        $this->error('Pass --vendor=<id>. Available:');

        foreach (Vendor::orderBy('id')->get(['id', 'name']) as $vendor) {
            $this->line("  {$vendor->id}  {$vendor->name}");
        }

        return null;
    }

    /** The whole truth, since the point of running this here is to see it. */
    private function reportFailure(Throwable $e): void
    {
        $this->error(class_basename($e).': '.$e->getMessage());
        $this->line('  at '.$e->getFile().' line '.$e->getLine());
        $this->newLine();
        $this->line('<comment>First frames:</comment>');

        foreach (array_slice(explode("\n", $e->getTraceAsString()), 0, 8) as $frame) {
            $this->line('  '.$frame);
        }
    }
}

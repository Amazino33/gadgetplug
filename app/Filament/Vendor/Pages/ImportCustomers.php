<?php

declare(strict_types=1);

namespace App\Filament\Vendor\Pages;

use App\Services\ActiveStore;
use App\Services\Import\CustomerImporter;
use App\Services\Import\SpreadsheetReader;
use BackedEnum;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;
use UnitEnum;

/**
 * Upload, match columns, check, import — the same four steps as Import
 * Products, for a shop's existing customer list and what each customer owes.
 *
 * Nothing is written before the final confirm. Writing happens a batch per
 * request, polled by the page, so a list of thousands never runs past a
 * shared host's time limit.
 */
class ImportCustomers extends Page
{
    use WithFileUploads;

    protected static string|null|BackedEnum $navigationIcon = 'heroicon-o-user-plus';

    protected static string|null|UnitEnum $navigationGroup = 'Sales Point';

    protected static ?string $navigationLabel = 'Import Customers';

    protected static ?string $title = 'Import Customers';

    protected static ?int $navigationSort = 20;

    protected string $view = 'filament.vendor.pages.import-customers';

    public const STEP_UPLOAD    = 'upload';
    public const STEP_MAP       = 'map';
    public const STEP_PREVIEW   = 'preview';
    public const STEP_IMPORTING = 'importing';
    public const STEP_DONE      = 'done';

    public string $step = self::STEP_UPLOAD;

    public $upload = null;

    public ?string $storedPath = null;

    public ?string $originalName = null;

    /** @var array<int, string> */
    public array $headers = [];

    /** @var array<int, string|null>  positional, aligned with $headers */
    public array $columnMap = [];

    /** @var array<string, int> */
    public array $summary = ['total' => 0, 'create' => 0, 'update' => 0, 'skip' => 0, 'balances' => 0];

    /** @var array<int, array<string, mixed>> */
    public array $previewRows = [];

    public int $processed = 0;

    public int $toProcess = 0;

    /** @var array<string, int> */
    public array $result = ['created' => 0, 'updated' => 0, 'balances' => 0, 'skipped' => 0];

    /** Every row not imported, and every warning, by row number — for the report. @var array<int, array> */
    public array $messages = [];

    public static function canAccess(): bool
    {
        $vendor = filament()->getTenant();
        $user   = auth()->user();

        return $vendor && $user && (
            $user->isSuperAdmin()
            || $vendor->isOwner($user)
            || $user->hasVendorPermission($vendor->id, 'edit_vendor')
        );
    }

    // ── Step 1: the file ──────────────────────────────────────────────────────

    public function loadFile(): void
    {
        $this->validate([
            'upload' => ['required', 'file', 'mimes:csv,txt,xlsx', 'max:20480'],
        ], [
            'upload.mimes' => 'Only CSV and Excel (.xlsx) files can be imported. An older .xls file must be saved as .xlsx first.',
        ]);

        /** @var TemporaryUploadedFile $upload */
        $upload = $this->upload;
        $this->originalName = $upload->getClientOriginalName();
        $this->storedPath = $upload->storeAs(
            'imports/'.filament()->getTenant()->id,
            now()->format('YmdHis').'-customers-'.$upload->getClientOriginalName(),
            'local',
        );

        try {
            $this->headers = app(SpreadsheetReader::class)->headers($this->absolutePath());
        } catch (Throwable $e) {
            $this->reset(['storedPath', 'headers']);
            Notification::make()->title($e->getMessage())->danger()->send();

            return;
        }

        $guess = app(CustomerImporter::class)->guessMapping($this->headers);
        $this->columnMap = collect($this->headers)->map(fn ($h) => $guess[$h] ?? null)->all();
        $this->step = self::STEP_MAP;
    }

    /** @return array<string, string> */
    public function fieldOptions(): array
    {
        return collect(CustomerImporter::FIELDS)->map(fn ($meta) => $meta['label'])->all();
    }

    // ── Step 2–3: what would happen ───────────────────────────────────────────

    public function buildPreview(): void
    {
        if (! in_array('name', $this->headerMapping(), true)) {
            Notification::make()->title('Match a column to Name before continuing.')->danger()->send();

            return;
        }

        try {
            $rows = $this->preparedRows();
        } catch (Throwable $e) {
            Notification::make()->title($e->getMessage())->danger()->send();

            return;
        }

        $this->summary = [
            'total'    => $rows->count(),
            'create'   => $rows->where('action', 'create')->count(),
            'update'   => $rows->where('action', 'update')->count(),
            'skip'     => $rows->where('action', 'skip')->count(),
            'balances' => $rows->where('action', 'create')->filter(fn ($r) => ($r['values']['opening_balance'] ?? 0) > 0)->count(),
        ];

        $this->previewRows = $rows->take(20)->map(fn ($r) => [
            'line'     => $r['line'],
            'name'     => $r['values']['name'] ?? '(no name)',
            'phone'    => $r['values']['phone'] ?? '',
            'balance'  => $r['values']['opening_balance'] ?? null,
            'action'   => $r['action'],
            'errors'   => $r['errors'],
            'warnings' => $r['warnings'],
        ])->values()->all();

        $this->step = self::STEP_PREVIEW;
    }

    // ── Step 4: write, a batch per request ────────────────────────────────────

    public function runImport(): void
    {
        $rows = $this->preparedRows();

        // What the check already knew: rows that cannot go in, and warnings.
        $this->messages = $rows->flatMap(fn ($r) => [
            ...array_map(fn ($m) => ['line' => $r['line'], 'name' => $r['values']['name'] ?? '', 'type' => 'Not imported', 'message' => $m], $r['errors']),
            ...array_map(fn ($m) => ['line' => $r['line'], 'name' => $r['values']['name'] ?? '', 'type' => 'Warning', 'message' => $m], $r['warnings']),
        ])->values()->all();

        $this->result    = ['created' => 0, 'updated' => 0, 'balances' => 0, 'skipped' => $rows->where('action', 'skip')->count()];
        $this->processed = 0;
        $this->toProcess = $rows->where('action', '!=', 'skip')->count();
        $this->step      = $this->toProcess > 0 ? self::STEP_IMPORTING : self::STEP_DONE;
    }

    public function processBatch(): void
    {
        if ($this->step !== self::STEP_IMPORTING) {
            return;
        }

        try {
            $batch = $this->preparedRows()
                ->where('action', '!=', 'skip')
                ->values()
                ->slice($this->processed, CustomerImporter::BATCH);

            if ($batch->isEmpty()) {
                $this->step = self::STEP_DONE;

                return;
            }

            $counts = app(CustomerImporter::class)->applyChunk(
                $batch,
                filament()->getTenant(),
                auth()->id(),
                ActiveStore::currentId(),
            );
        } catch (Throwable $e) {
            report($e);
            Notification::make()->title('The import stopped.')->body($e->getMessage())->danger()->persistent()->send();
            $this->step = self::STEP_PREVIEW;

            return;
        }

        $this->result['created']  += $counts['created'];
        $this->result['updated']  += $counts['updated'];
        $this->result['balances'] += $counts['balances'];
        $this->result['skipped']  += count($counts['failures']);

        foreach ($counts['failures'] as $f) {
            $this->messages[] = ['line' => $f['line'], 'name' => $f['name'], 'type' => 'Not imported', 'message' => implode(' ', $f['errors'])];
        }

        $this->processed += $batch->count();

        if ($this->processed >= $this->toProcess) {
            $this->step = self::STEP_DONE;
            Notification::make()->title($this->resultSummary())->success()->send();
        }
    }

    public function progressPercent(): int
    {
        return $this->toProcess === 0 ? 0 : (int) round($this->processed / $this->toProcess * 100);
    }

    public function resultSummary(): string
    {
        return sprintf(
            '%d new, %d updated, %d skipped · %d opening balance(s) recorded',
            $this->result['created'], $this->result['updated'], $this->result['skipped'], $this->result['balances'],
        );
    }

    public function downloadReport(): StreamedResponse
    {
        $lines = collect($this->messages)->sortBy('line')->values();
        $summary = $this->resultSummary();

        return response()->streamDownload(function () use ($lines, $summary) {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['Summary', $summary]);
            fputcsv($out, ['Row', 'Customer', 'Type', 'Message']);

            foreach ($lines as $l) {
                fputcsv($out, [$l['line'], $l['name'], $l['type'], $l['message']]);
            }

            fclose($out);
        }, 'customer-import-report.csv', ['Content-Type' => 'text/csv']);
    }

    public function downloadTemplate(): BinaryFileResponse
    {
        return response()->download(app(CustomerImporter::class)->template())->deleteFileAfterSend();
    }

    public function startOver(): void
    {
        $this->reset(['step', 'upload', 'storedPath', 'originalName', 'headers', 'columnMap', 'summary', 'previewRows', 'processed', 'toProcess', 'result', 'messages']);
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function preparedRows(): Collection
    {
        return app(CustomerImporter::class)->prepare($this->absolutePath(), $this->headerMapping(), filament()->getTenant());
    }

    /** @return array<string, string> header => field */
    private function headerMapping(): array
    {
        $mapping = [];

        foreach ($this->headers as $index => $header) {
            if (filled($this->columnMap[$index] ?? null)) {
                $mapping[$header] = (string) $this->columnMap[$index];
            }
        }

        return $mapping;
    }

    private function absolutePath(): string
    {
        return Storage::disk('local')->path((string) $this->storedPath);
    }
}

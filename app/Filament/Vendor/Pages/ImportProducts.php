<?php

declare(strict_types=1);

namespace App\Filament\Vendor\Pages;

use App\Models\ImportLog;
use App\Models\ImportMappingTemplate;
use App\Services\Export\ProductExporter;
use App\Services\Import\ColumnMapper;
use App\Services\Import\ImportPreparer;
use App\Services\Import\ParsedRow;
use App\Services\Import\ProductImporter;
use App\Services\Import\SpreadsheetReader;
use App\Support\Import\ProductField;
use BackedEnum;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Collection;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Throwable;
use UnitEnum;

/**
 * Upload, map, check, confirm.
 *
 * The steps exist because an import is the one action that can rewrite an entire
 * catalogue at once. Every one of them is reversible up to the final confirm,
 * and nothing touches the database before it - a vendor must be able to see that
 * their file would archive two hundred products before it archives them.
 */
class ImportProducts extends Page
{
    use WithFileUploads;

    protected static string|null|BackedEnum $navigationIcon = 'heroicon-o-arrow-up-tray';

    protected static string|null|UnitEnum $navigationGroup = 'Products';

    protected static ?string $navigationLabel = 'Import Products';

    protected static ?string $title = 'Import Products';

    protected static ?int $navigationSort = 9;

    protected string $view = 'filament.vendor.pages.import-products';

    public const STEP_UPLOAD  = 'upload';
    public const STEP_MAP     = 'map';
    public const STEP_PREVIEW = 'preview';
    public const STEP_DONE    = 'done';

    public string $step = self::STEP_UPLOAD;

    public $upload = null;

    /** Where the uploaded file was parked, so it survives between steps. */
    public ?string $storedPath = null;

    public ?string $originalName = null;

    /** @var array<int, string> */
    public array $headers = [];

    /** @var array<int, string|null>  positional, aligned with $headers */
    public array $columnMap = [];

    public ?int $templateId = null;

    public string $newTemplateName = '';

    /** @var array<string, int> */
    public array $summary = ['total' => 0, 'create' => 0, 'update' => 0, 'skip' => 0];

    /** @var array<int, array<string, mixed>> */
    public array $previewRows = [];

    /** @var array<int, array<string, mixed>> */
    public array $problems = [];

    public ?int $resultLogId = null;

    public static function canAccess(): bool
    {
        $vendor = filament()->getTenant();

        return $vendor !== null
            && auth()->user()?->hasVendorPermission($vendor->id, 'import_products') === true;
    }

    // ── Step 1: the file ─────────────────────────────────────────────────────

    public function loadFile(): void
    {
        $this->validate([
            'upload' => ['required', 'file', 'mimes:csv,txt,xlsx', 'max:20480'],
        ], [
            'upload.mimes' => 'Only CSV and Excel (.xlsx) files can be imported. An older .xls file must be saved as .xlsx first.',
            'upload.max'   => 'That file is larger than 20MB. Split it into smaller files and import them one at a time.',
        ]);

        /** @var TemporaryUploadedFile $upload */
        $upload = $this->upload;

        $this->originalName = $upload->getClientOriginalName();

        // Parked on disk rather than kept in the component: the temporary upload
        // is cleaned up between requests, and the preview and the commit are two
        // separate requests reading the same file.
        $this->storedPath = $upload->storeAs(
            'imports/'.filament()->getTenant()->id,
            now()->format('YmdHis').'-'.$upload->getClientOriginalName(),
            'local',
        );

        try {
            $this->headers = app(SpreadsheetReader::class)->headers($this->absolutePath());
        } catch (Throwable $e) {
            $this->reset(['storedPath', 'headers']);

            Notification::make()->title($e->getMessage())->danger()->send();

            return;
        }

        $guess = app(ColumnMapper::class)->guess($this->headers);

        $this->columnMap = collect($this->headers)
            ->map(fn (string $header) => $guess[$header] ?? null)
            ->all();

        $this->step = self::STEP_MAP;
    }

    // ── Step 2: the mapping ──────────────────────────────────────────────────

    /** @return array<string, string> */
    public function fieldOptions(): array
    {
        return collect(ProductField::importable())
            ->mapWithKeys(fn (ProductField $f) => [$f->value => $f->label()])
            ->all();
    }

    public function hintFor(?string $field): ?string
    {
        return $field === null ? null : ProductField::tryFrom($field)?->hint();
    }

    /** Fields the vendor has not mapped to anything, so the gap is visible. */
    public function unmappedFields(): Collection
    {
        $used = collect($this->columnMap)->filter()->values();

        return collect(ProductField::importable())
            ->reject(fn (ProductField $f) => $used->contains($f->value));
    }

    public function savedTemplates(): Collection
    {
        return ImportMappingTemplate::where('vendor_id', filament()->getTenant()->id)
            ->orderBy('name')
            ->get();
    }

    public function applyTemplate(): void
    {
        $template = ImportMappingTemplate::where('vendor_id', filament()->getTenant()->id)
            ->find($this->templateId);

        if ($template === null) {
            return;
        }

        $mapping = $template->mapping ?? [];

        $this->columnMap = collect($this->headers)
            ->map(fn (string $header) => $mapping[$header] ?? null)
            ->all();

        $coverage = $template->coverageOf($this->headers);

        Notification::make()
            ->title("Applied \"{$template->name}\" — it covers {$coverage}% of this file's columns.")
            ->{$coverage >= 60 ? 'success' : 'warning'}()
            ->send();
    }

    public function saveTemplate(): void
    {
        $name = trim($this->newTemplateName);

        if ($name === '') {
            Notification::make()->title('Give the template a name first.')->warning()->send();

            return;
        }

        ImportMappingTemplate::updateOrCreate(
            ['vendor_id' => filament()->getTenant()->id, 'name' => $name],
            ['user_id' => auth()->id(), 'mapping' => $this->headerMapping()],
        );

        $this->newTemplateName = '';

        Notification::make()->title("Saved as \"{$name}\". Your next import from this source will map itself.")->success()->send();
    }

    // ── Step 3: what would happen ────────────────────────────────────────────

    public function buildPreview(): void
    {
        $mapping = $this->headerMapping();

        if (! in_array(ProductField::Name->value, $mapping, true)) {
            Notification::make()
                ->title('Map a column to Name before continuing.')
                ->body('Every product needs a name, so nothing can be imported without it.')
                ->danger()
                ->send();

            return;
        }

        try {
            $rows = app(ImportPreparer::class)->prepare(
                $this->absolutePath(),
                $mapping,
                filament()->getTenant(),
            );
        } catch (Throwable $e) {
            Notification::make()->title($e->getMessage())->danger()->send();

            return;
        }

        $this->summary = app(ImportPreparer::class)->summarise($rows);

        $this->previewRows = $rows->take(20)->map(fn (ParsedRow $row) => [
            'line'     => $row->line,
            'name'     => $row->name(),
            'sku'      => (string) ($row->value('sku') ?? ''),
            'price'    => $row->value('price'),
            'cost'     => $row->value('cost_price'),
            'action'   => $row->action(),
            'errors'   => $row->errors,
            'warnings' => $row->warnings,
        ])->values()->all();

        // Every problem row, not just the first twenty — a vendor fixing their
        // file needs the whole list, and it is the one thing they cannot get by
        // scrolling the preview.
        $this->problems = $rows
            ->filter(fn (ParsedRow $row) => $row->errors !== [])
            ->take(200)
            ->map(fn (ParsedRow $row) => [
                'line'   => $row->line,
                'name'   => $row->name(),
                'errors' => $row->errors,
            ])->values()->all();

        $this->step = self::STEP_PREVIEW;
    }

    // ── Step 4: commit ───────────────────────────────────────────────────────

    public function commit(): void
    {
        if ($this->summary['create'] + $this->summary['update'] === 0) {
            Notification::make()
                ->title('Nothing to import.')
                ->body('Every row in this file has an error. Fix them and upload it again.')
                ->warning()
                ->send();

            return;
        }

        // Chunked inserts keep memory flat, but the run is still synchronous —
        // this host has no queue worker, so a background job would simply never
        // start. A few thousand rows is seconds; the cap in SpreadsheetReader is
        // what stops anything pathological reaching here.
        @set_time_limit(300);

        try {
            $rows = app(ImportPreparer::class)->prepare(
                $this->absolutePath(),
                $this->headerMapping(),
                filament()->getTenant(),
            );

            $log = app(ProductImporter::class)->commit(
                $rows,
                filament()->getTenant(),
                auth()->id(),
                $this->originalName ?? 'import.csv',
            );
        } catch (Throwable $e) {
            report($e);

            Notification::make()
                ->title('The import failed and nothing was changed.')
                ->body($e->getMessage())
                ->danger()
                ->persistent()
                ->send();

            return;
        }

        $this->resultLogId = $log->id;
        $this->step        = self::STEP_DONE;

        Notification::make()->title($log->summary())->success()->send();
    }

    public function result(): ?ImportLog
    {
        return $this->resultLogId === null ? null : ImportLog::find($this->resultLogId);
    }

    public function downloadSnapshot(): ?BinaryFileResponse
    {
        $log = $this->result();

        if ($log === null || ! $log->hasSnapshot()) {
            Notification::make()->title('There is no snapshot for this import.')->warning()->send();

            return null;
        }

        return response()->download($log->snapshot_path);
    }

    public function startOver(): void
    {
        $this->reset(['step', 'upload', 'storedPath', 'originalName', 'headers', 'columnMap', 'summary', 'previewRows', 'problems', 'resultLogId', 'templateId']);
    }

    public function downloadTemplate(string $format = 'csv'): BinaryFileResponse
    {
        return response()->download(app(ProductExporter::class)->template($format))->deleteFileAfterSend();
    }

    /**
     * The positional selects, turned back into the header => field shape the
     * preparer and the saved templates both speak.
     *
     * Positional in the form because a header like "Cost (N)" cannot be a
     * wire:model path; keyed here because a saved template has to survive the
     * columns moving in the next export.
     *
     * @return array<string, string>
     */
    private function headerMapping(): array
    {
        $mapping = [];

        foreach ($this->headers as $index => $header) {
            $field = $this->columnMap[$index] ?? null;

            if (filled($field)) {
                $mapping[$header] = (string) $field;
            }
        }

        return $mapping;
    }

    private function absolutePath(): string
    {
        return \Illuminate\Support\Facades\Storage::disk('local')->path((string) $this->storedPath);
    }
}

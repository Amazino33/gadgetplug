<?php

namespace App\Http\Controllers\StockTake;

use App\Http\Controllers\Controller;
use App\Models\Aisle;
use App\Models\Product;
use App\Models\StockTakeEntry;
use App\Models\StockTakeTask;
use App\Models\Vendor;
use App\Services\StockTake\StockTakeAccess;
use App\Services\StockTake\StockTakeCatalogue;
use App\Services\StockTake\StockTakeCounter;
use App\Services\StockTake\StockTakeDevice;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Counting on the phone (step 5): a count or recount task's aisles, scans,
 * saving each product's count as it is entered, and handing the task in.
 *
 * Blind: no response here carries a stock, cost or price figure. Every task,
 * aisle and entry in the URL or body is checked against the vendor in the URL.
 */
class StockTakeCountController extends Controller
{
    public function __construct(
        private readonly StockTakeCounter $counter,
        private readonly StockTakeCatalogue $catalogue,
    ) {}

    /**
     * The task pack: everything the phone needs to count this task with no
     * network (see StockTakePack). Downloaded once; counting then never waits
     * on the server.
     */
    public function pack(Request $request, Vendor $vendor, int $task): JsonResponse
    {
        $data = $request->validate(['device' => ['required', 'string', 'max:64']]);
        $task = $this->workable($request, $vendor, $task);

        // One phone per task (A7): the first to download it holds it.
        app(StockTakeDevice::class)->hold($task, $data['device'], StockTakeDevice::labelFrom($request->userAgent()));

        return response()->json(app(\App\Services\StockTake\StockTakePack::class)->build($task));
    }

    /**
     * What the phone counted offline, in order (see StockTakeSync). Every
     * operation is answered: synced, or rejected with the reason. A batch sent
     * again is answered again, and nothing in it happens twice.
     */
    public function sync(Request $request, Vendor $vendor, int $task): JsonResponse
    {
        $data = $request->validate([
            'device'                  => ['required', 'string', 'max:64'],
            'waiting'                 => ['nullable', 'integer', 'min:0'],
            'ops'                     => ['present', 'array', 'max:200'],
            'ops.*.id'                => ['required', 'uuid'],
            'ops.*.type'              => ['required', 'in:set,remove'],
            'ops.*.aisle_id'          => ['required', 'integer'],
            'ops.*.product_id'        => ['required', 'integer'],
            'ops.*.lines'             => ['nullable', 'array'],
            'ops.*.lines.*.unit_id'   => ['nullable', 'integer'],
            'ops.*.lines.*.qty'       => ['nullable'],
            'ops.*.damaged'           => ['nullable', 'numeric', 'min:0', 'max:1000000'],
            'ops.*.expired'           => ['nullable', 'numeric', 'min:0', 'max:1000000'],
            'ops.*.counted_at'        => ['nullable', 'string', 'max:40'],
            'ops.*.device_counted_at' => ['nullable', 'string', 'max:40'],
        ]);

        $found = StockTakeTask::query()->where('vendor_id', $vendor->id)->findOrFail($task);
        abort_if($found->type === StockTakeTask::TYPE_MAPPING, 404);
        // Not open any more is answered per operation (rejected, with why),
        // so the phone can show it; someone else's task is refused outright.
        abort_unless((int) $found->assigned_to === (int) $request->user()->id, 403, 'This task is not yours.');

        // The phone holding it, and what it will still have waiting once this
        // batch is answered: what a manager would lose by releasing it.
        $pending = max(0, (int) ($data['waiting'] ?? count($data['ops'])) - count($data['ops']));
        app(StockTakeDevice::class)->hold($found, $data['device'], StockTakeDevice::labelFrom($request->userAgent()), $pending);

        return response()->json([
            'server_time' => now()->toIso8601ZuluString('millisecond'),
            'results'     => app(\App\Services\StockTake\StockTakeSync::class)->apply($request->user(), $found, $data['ops']),
        ]);
    }

    /** The task: its aisles (count) or its products (recount), and what is counted so far. */
    public function show(Request $request, Vendor $vendor, int $task): JsonResponse
    {
        $task = $this->workable($request, $vendor, $task);

        $entries = $task->entries()->get();
        $branchAisles = Aisle::query()
            ->where('vendor_id', $vendor->id)
            ->where('store_id', $task->store_id)
            ->active()
            ->listed()
            ->ordered()
            ->get();

        $aisles = $task->isRecount()
            ? []
            : $task->aisles()->ordered()->get()->map(fn (Aisle $a) => [
                'id'      => $a->id,
                'label'   => $a->label(),
                // A Quick count's own area: the phone goes straight to counting.
                'quick'   => (bool) $a->is_quick,
                'counted' => $entries->where('aisle_id', $a->id)->count(),
            ])->values();

        $products = $task->isRecount()
            ? $task->products()->with('units')->orderBy('name')->get()->map(fn (Product $p) => [
                ...$this->catalogue->format($p),
                'mapped_aisle_ids' => DB::table('aisle_products')->where('product_id', $p->id)->pluck('aisle_id')->map(fn ($id) => (int) $id)->values(),
                'entries'          => $entries->where('product_id', $p->id)->map(fn (StockTakeEntry $e) => StockTakeCounter::entryJson($e))->values(),
            ])->values()
            : [];

        return response()->json([
            'task' => [
                'id'         => $task->id,
                'type'       => $task->type,
                'status'     => $task->status,
                'due_date'   => $task->due_date?->toDateString(),
                'stock_take' => $task->stockTake ? ['reference' => $task->stockTake->reference, 'name' => $task->stockTake->name] : null,
            ],
            'aisles'        => $aisles,
            'products'      => $products,
            // A recount asks where each product was found: any active aisle.
            'branch_aisles' => $task->isRecount()
                ? $branchAisles->map(fn (Aisle $a) => ['id' => $a->id, 'label' => $a->label()])->values()
                : [],
            'allow_halves'  => (bool) $vendor->allow_half_quantities,
        ]);
    }

    /** What has been counted in one aisle of the task, editable until submit. */
    public function entries(Request $request, Vendor $vendor, int $task): JsonResponse
    {
        $task  = $this->workable($request, $vendor, $task);
        $aisle = (int) $request->query('aisle_id');

        $entries = $task->entries()
            ->when($aisle, fn ($q) => $q->where('aisle_id', $aisle))
            ->with('product.units')
            ->latest('counted_at')
            ->get();

        return response()->json([
            'entries' => $entries->map(fn (StockTakeEntry $e) => [
                ...StockTakeCounter::entryJson($e),
                'product' => $this->catalogue->format($e->product),
            ])->values(),
        ]);
    }

    public function scan(Request $request, Vendor $vendor, int $task): JsonResponse
    {
        $data = $request->validate([
            'aisle_id' => ['required', 'integer'],
            'code'     => ['required', 'string', 'max:100'],
        ]);
        $task = $this->workable($request, $vendor, $task);

        return $this->run(fn () => response()->json(
            $this->counter->scan($request->user(), $task, $this->aisleOf($vendor, $data['aisle_id']), $data['code'])
        ));
    }

    /** A product picked by name, or from a recount's list. */
    public function pick(Request $request, Vendor $vendor, int $task): JsonResponse
    {
        $data = $request->validate([
            'aisle_id'   => ['required', 'integer'],
            'product_id' => ['required', 'integer'],
        ]);
        $task = $this->workable($request, $vendor, $task);

        return $this->run(fn () => response()->json($this->counter->pick(
            $request->user(),
            $task,
            $this->aisleOf($vendor, $data['aisle_id']),
            Product::query()->where('vendor_id', $vendor->id)->findOrFail($data['product_id']),
        )));
    }

    public function save(Request $request, Vendor $vendor, int $task): JsonResponse
    {
        $data = $request->validate([
            'aisle_id'        => ['required', 'integer'],
            'product_id'      => ['required', 'integer'],
            'mode'            => ['nullable', 'in:new,add,replace'],
            'lines'           => ['required', 'array', 'min:1'],
            'lines.*.unit_id' => ['nullable', 'integer'],
            'lines.*.qty'     => ['nullable'],
            // Made up by the phone per Save, and sent again on Retry.
            'save_id'         => ['nullable', 'uuid'],
        ]);
        $task = $this->workable($request, $vendor, $task);

        return $this->run(function () use ($request, $vendor, $task, $data) {
            $entry = $this->counter->save(
                $request->user(),
                $task,
                $this->aisleOf($vendor, $data['aisle_id']),
                Product::query()->where('vendor_id', $vendor->id)->findOrFail($data['product_id']),
                $data['lines'],
                $data['mode'] ?? StockTakeCounter::MODE_NEW,
                $data['save_id'] ?? null,
            );

            return response()->json(['entry' => StockTakeCounter::entryJson($entry->fresh())], 201);
        });
    }

    public function destroy(Request $request, Vendor $vendor, int $task, int $entry): JsonResponse
    {
        $task  = $this->workable($request, $vendor, $task);
        $entry = StockTakeEntry::query()->where('vendor_id', $vendor->id)->where('stock_take_task_id', $task->id)->find($entry);

        // Already gone: a retry of a removal that landed.
        if (! $entry) {
            return response()->json(['status' => 'removed']);
        }

        return $this->run(function () use ($request, $task, $entry) {
            $this->counter->remove($request->user(), $task, $entry);

            return response()->json(['status' => 'removed']);
        });
    }

    public function storeUnknown(Request $request, Vendor $vendor, int $task): JsonResponse
    {
        $data = $request->validate([
            'aisle_id' => ['required', 'integer'],
            'barcode'  => ['nullable', 'string', 'max:64'],
            'name'     => ['required', 'string', 'max:160'],
            'photo'    => ['nullable', 'image', 'max:8192'],
            'qty'      => ['nullable', 'numeric', 'min:0'],
            'unit'     => ['nullable', 'string', 'max:64'],
            'save_id'  => ['nullable', 'uuid'],
        ]);
        $task = $this->workable($request, $vendor, $task);

        return $this->run(function () use ($request, $vendor, $task, $data) {
            $item = $this->counter->recordUnknown(
                $request->user(),
                $task,
                $this->aisleOf($vendor, $data['aisle_id']),
                $data['name'],
                $data['barcode'] ?? null,
                $request->file('photo'),
                $data['qty'] ?? null,
                $data['unit'] ?? null,
                $data['save_id'] ?? null,
            );

            return response()->json(['status' => 'recorded', 'id' => $item->id], 201);
        });
    }

    /** Products the task should have found that have no count yet. */
    public function missing(Request $request, Vendor $vendor, int $task): JsonResponse
    {
        $task = $this->workable($request, $vendor, $task);

        return response()->json([
            'missing' => $this->counter->missing($task)->map(fn (Product $p) => $this->catalogue->format($p))->values(),
        ]);
    }

    public function submit(Request $request, Vendor $vendor, int $task): JsonResponse
    {
        $data = $request->validate([
            'device'      => ['required', 'string', 'max:64'],
            'decisions'   => ['nullable', 'array'],
            'decisions.*' => ['in:not_on_shelf,skip'],
        ]);
        $found = StockTakeTask::query()->where('vendor_id', $vendor->id)->findOrFail($task);

        // A retry of a hand-in that landed: it is handed in.
        if ((int) $found->assigned_to === (int) $request->user()->id
            && in_array($found->status, \App\Services\StockTake\StockTakeScope::HANDED_IN, true)) {
            return response()->json(['status' => 'submitted']);
        }

        $task = $this->workable($request, $vendor, $task);
        app(StockTakeDevice::class)->hold($task, $data['device'], StockTakeDevice::labelFrom($request->userAgent()), 0);

        return $this->run(function () use ($request, $task, $data) {
            $this->counter->submit($request->user(), $task, $data['decisions'] ?? []);

            return response()->json(['status' => 'submitted']);
        });
    }

    /** A count or recount task of this vendor that this person may work on now. */
    /**
     * A Quick count reaches an aisle nobody set up: the counter names it and
     * carries on. The same name in this branch (any case) is that aisle, not a
     * second one. Comes back in the pack's shape, so the phone adds it as is.
     */
    public function addAisle(Request $request, Vendor $vendor, int $task): JsonResponse
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:80']]);
        $task = $this->workable($request, $vendor, $task);

        abort_unless($task->isQuick() && $task->isOpen(), 422, 'Only a Quick count can add aisles from the phone.');

        $name  = preg_replace('/\s+/', ' ', trim($data['name']));
        $aisle = Aisle::query()
            ->where('store_id', $task->store_id)
            ->where('is_quick', false)
            ->whereRaw('LOWER(name) = ?', [mb_strtolower($name)])
            ->first();

        if ($aisle && ! $aisle->is_active) {
            $aisle->update(['is_active' => true]);
        }

        $aisle ??= Aisle::create([
            'vendor_id'  => $task->vendor_id,
            'store_id'   => $task->store_id,
            'name'       => $name,
            'is_active'  => true,
            'is_quick'   => false,
            'sort_order' => (int) Aisle::query()->where('store_id', $task->store_id)->max('sort_order') + 1,
        ]);

        $holder = $task->aisleHeldByOther($aisle->id);

        return response()->json(['aisle' => [
            'id'       => $aisle->id,
            'name'     => $aisle->name,
            'label'    => $aisle->label(),
            'quick'    => false,
            'held_by'  => $holder,
            'expected' => [],
        ]], $aisle->wasRecentlyCreated ? 201 : 200);
    }

    private function workable(Request $request, Vendor $vendor, int $id): StockTakeTask
    {
        $task = StockTakeTask::query()->where('vendor_id', $vendor->id)->findOrFail($id);

        abort_if($task->type === StockTakeTask::TYPE_MAPPING, 404);
        abort_unless(StockTakeAccess::canWorkOn($request->user(), $task), 403, 'This task is not open for you.');

        return $task;
    }

    private function aisleOf(Vendor $vendor, int $id): Aisle
    {
        return Aisle::query()->where('vendor_id', $vendor->id)->findOrFail($id);
    }

    private function run(callable $call): JsonResponse
    {
        try {
            return $call();
        } catch (AuthorizationException $e) {
            abort(403, $e->getMessage());
        }
    }
}

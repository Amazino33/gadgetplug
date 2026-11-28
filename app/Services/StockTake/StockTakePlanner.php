<?php

declare(strict_types=1);

namespace App\Services\StockTake;

use App\Models\Aisle;
use App\Models\AisleProduct;
use App\Models\Product;
use App\Models\ProductBarcode;
use App\Models\StockTake;
use App\Models\StockTakeResult;
use App\Models\StockTakeTask;
use App\Models\StockTakeUnknownItem;
use App\Models\Store;
use App\Models\User;
use App\Services\Purchases\PurchaseCatalogue;
use Carbon\Carbon;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Running a stock take: opening it, handing out count tasks, closing it,
 * carrying what was left over into another, and dealing with barcodes the
 * system did not know.
 *
 * Every method checks StockTakeAccess itself, so a screen cannot forget to.
 */
final class StockTakePlanner
{
    public function __construct(private readonly PurchaseCatalogue $catalogue) {}

    /**
     * @param  array{name: string, start_date: string, end_date: string, variance_threshold_percent?: float|int|null, variance_threshold_value?: float|int|null}  $data
     *
     * @throws AuthorizationException|ValidationException
     */
    public function open(User $user, Store $store, array $data, ?StockTake $carriedFrom = null, ?array $carriedProductIds = null): StockTake
    {
        $this->mustManage($user, (int) $store->vendor_id, (int) $store->id);

        if (($data['end_date'] ?? null) < ($data['start_date'] ?? null)) {
            throw ValidationException::withMessages(['end_date' => 'The end date cannot be before the start date.']);
        }

        $open = StockTake::query()->where('store_id', $store->id)->open()->first();
        if ($open) {
            throw ValidationException::withMessages([
                'name' => "{$open->reference} ({$open->name}) is still open at this branch. Close it first.",
            ]);
        }

        try {
            return StockTake::create([
                'vendor_id'                  => $store->vendor_id,
                'store_id'                   => $store->id,
                'name'                       => trim($data['name']),
                'start_date'                 => $data['start_date'],
                'end_date'                   => $data['end_date'],
                'variance_threshold_percent' => $data['variance_threshold_percent'] ?? 10,
                'variance_threshold_value'   => $data['variance_threshold_value'] ?? 5000,
                'carried_from_id'            => $carriedFrom?->id,
                'carried_product_ids'        => $carriedProductIds,
                'created_by'                 => $user->id,
            ]);
        } catch (UniqueConstraintViolationException) {
            // Someone else opened one in the same moment.
            throw ValidationException::withMessages(['name' => 'Another stock take was just opened at this branch.']);
        }
    }

    /**
     * Give one person a set of aisles to count.
     *
     * @param  array<int, int>  $aisleIds
     *
     * @throws AuthorizationException|ValidationException
     */
    public function assignCount(User $by, StockTake $stockTake, array $aisleIds, User $assignee, ?string $dueDate): StockTakeTask
    {
        $this->mustManage($by, (int) $stockTake->vendor_id, (int) $stockTake->store_id);

        if (! $stockTake->isOpen()) {
            throw ValidationException::withMessages(['aisle_ids' => 'This stock take is closed.']);
        }

        if (! StockTakeAccess::canPerform($assignee, (int) $stockTake->vendor_id, (int) $stockTake->store_id)) {
            throw ValidationException::withMessages(['assigned_to' => "{$assignee->name} cannot count at this branch (they need Perform stock takes)."]);
        }

        $aisles = Aisle::query()
            ->where('vendor_id', $stockTake->vendor_id)
            ->where('store_id', $stockTake->store_id)
            ->active()
            ->whereIn('id', $aisleIds)
            ->get();

        if ($aisles->isEmpty() || $aisles->count() !== collect($aisleIds)->unique()->count()) {
            throw ValidationException::withMessages(['aisle_ids' => 'Pick aisles in use at this branch.']);
        }

        $held = array_intersect_key(StockTakeScope::aisleHolders($stockTake), array_flip($aisles->modelKeys()));
        if ($held !== []) {
            throw ValidationException::withMessages(['aisle_ids' => $this->heldMessage($aisles, $held)]);
        }

        try {
            return DB::transaction(function () use ($by, $stockTake, $aisles, $assignee, $dueDate) {
                $task = StockTakeTask::create([
                    'vendor_id'     => $stockTake->vendor_id,
                    'store_id'      => $stockTake->store_id,
                    'stock_take_id' => $stockTake->id,
                    'type'          => StockTakeTask::TYPE_COUNT,
                    'assigned_to'   => $assignee->id,
                    'due_date'      => $dueDate,
                    'created_by'    => $by->id,
                ]);

                $task->attachAisles($aisles->modelKeys());

                if ($stockTake->status === StockTake::STATUS_DRAFT) {
                    $stockTake->update(['status' => StockTake::STATUS_ACTIVE]);
                }

                return $task;
            });
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages([
                'aisle_ids' => $this->heldMessage($aisles, StockTakeScope::aisleHolders($stockTake)),
            ]);
        }
    }

    /**
     * Quick count: a count task with no aisles to set up first. The task gets
     * a private area of its own (named after the person and the day), so two
     * people can count at once without sharing an aisle. They scan whatever
     * they find; nothing is "expected", so there is nothing to answer for at
     * hand-in, and what nobody counted shows in the stock take's Uncounted
     * products (the whole branch is in scope once a stock take has a quick
     * task: StockTakeScope::inScope).
     *
     * @throws AuthorizationException|ValidationException
     */
    public function assignQuickCount(User $by, StockTake $stockTake, User $assignee, ?string $dueDate): StockTakeTask
    {
        $this->mustManage($by, (int) $stockTake->vendor_id, (int) $stockTake->store_id);

        if (! $stockTake->isOpen()) {
            throw ValidationException::withMessages(['assigned_to' => 'This stock take is closed.']);
        }

        $day  = ($dueDate ? Carbon::parse($dueDate) : now())->format('j M');
        $base = trim(Str::before($assignee->name, ' ')) . ' · ' . $day;
        $same = Aisle::query()
            ->where('store_id', $stockTake->store_id)
            ->where('is_quick', true)
            ->where('name', 'like', $base . '%')
            ->count();

        $aisle = Aisle::create([
            'vendor_id'  => $stockTake->vendor_id,
            'store_id'   => $stockTake->store_id,
            'name'       => $same === 0 ? $base : $base . ' (' . ($same + 1) . ')',
            'is_active'  => true,
            'is_quick'   => true,
            'sort_order' => 0,
        ]);

        return $this->assignCount($by, $stockTake, [$aisle->id], $assignee, $dueDate);
    }

    /** @throws AuthorizationException|ValidationException */
    public function cancelTask(User $by, StockTakeTask $task): void
    {
        $this->mustManage($by, (int) $task->vendor_id, (int) $task->store_id);

        if (! $task->isOpen()) {
            throw ValidationException::withMessages(['task' => 'Only a task not yet submitted can be cancelled.']);
        }

        // Entries already saved stay on record; the task just ends, and its
        // aisles are free for another task.
        DB::transaction(function () use ($task) {
            $task->update(['status' => StockTakeTask::STATUS_CANCELLED, 'cancelled_at' => now()]);

            // A recount called off: what it was to recount is back to be decided.
            if ($task->isRecount()) {
                $originals = StockTakeResult::query()
                    ->where('recount_task_id', $task->id)
                    ->where('status', StockTakeResult::STATUS_SENT_TO_RECOUNT)
                    ->get();

                foreach ($originals as $original) {
                    $original->update(['status' => StockTakeResult::STATUS_PENDING]);
                }

                foreach ($originals->pluck('stock_take_task_id')->unique() as $taskId) {
                    StockTakeResults::settle(StockTakeTask::findOrFail($taskId));
                }
            }
        });
    }

    /**
     * Close the stock take. Refused while any count is still with a counter or
     * any result is waiting on a decision — closing must not strand either.
     *
     * @throws AuthorizationException|ValidationException
     */
    public function close(User $by, StockTake $stockTake): void
    {
        $this->mustManage($by, (int) $stockTake->vendor_id, (int) $stockTake->store_id);

        if (! $stockTake->isOpen()) {
            return;
        }

        $openTasks = $stockTake->tasks()
            ->whereIn('type', [StockTakeTask::TYPE_COUNT, StockTakeTask::TYPE_RECOUNT])
            ->open()
            ->with('assignee:id,name')
            ->get();

        if ($openTasks->isNotEmpty()) {
            throw ValidationException::withMessages([
                'close' => 'Still with counters: ' . $openTasks->map(fn ($t) => $t->assignee?->name)->filter()->unique()->implode(', ')
                    . '. Wait for them to submit, or cancel those tasks.',
            ]);
        }

        $pending = StockTakeResult::query()->where('stock_take_id', $stockTake->id)->pending()->count();
        if ($pending > 0) {
            throw ValidationException::withMessages([
                'close' => "{$pending} counted product" . ($pending === 1 ? ' is' : 's are') . ' waiting for approval. Approve, reject or recount them first.',
            ]);
        }

        $stockTake->update([
            'status'    => StockTake::STATUS_CLOSED,
            'closed_by' => $by->id,
            'closed_at' => now(),
        ]);
    }

    /**
     * A new stock take that counts only what a closed one left uncounted.
     *
     * @throws AuthorizationException|ValidationException
     */
    public function carryOver(User $by, StockTake $from, array $data): StockTake
    {
        if ($from->isOpen()) {
            throw ValidationException::withMessages(['name' => 'Close this stock take before carrying anything over.']);
        }

        $uncounted = StockTakeScope::uncounted($from)->all();

        if ($uncounted === []) {
            throw ValidationException::withMessages(['name' => 'Nothing was left uncounted.']);
        }

        return $this->open($by, $from->store, $data, $from, $uncounted);
    }

    /**
     * An unknown barcode was really an existing product: give that product
     * the barcode, put it on the aisle it was found in, and close the item.
     *
     * A product that already has a barcode gets this one as another (a second
     * variant sold the same, say), unless $asMain: then this becomes its main
     * barcode and the old one stays on as another. Both scan either way.
     *
     * @throws AuthorizationException|ValidationException
     */
    public function linkUnknown(User $by, StockTakeUnknownItem $item, Product $product, bool $asMain = false): void
    {
        $this->mustManage($by, (int) $item->vendor_id, (int) $item->store_id);
        $this->mustBeOpen($item);

        if ((int) $product->vendor_id !== (int) $item->vendor_id) {
            throw ValidationException::withMessages(['product_id' => 'That product is not one of this shop\'s.']);
        }

        DB::transaction(function () use ($by, $item, $product, $asMain) {
            if ($item->barcode) {
                $this->giveBarcode($product, $item->barcode, $asMain);
            }

            $this->resolve($by, $item, $product);
        });
    }

    /**
     * An unknown barcode was a product the shop never entered: create it
     * (published, on the till, priced as given) and close the item.
     *
     * @param  array{name: string, price: float|int|string, base_unit?: string|null}  $data
     *
     * @throws AuthorizationException|ValidationException
     */
    public function createFromUnknown(User $by, StockTakeUnknownItem $item, array $data): Product
    {
        $this->mustManage($by, (int) $item->vendor_id, (int) $item->store_id);
        $this->mustBeOpen($item);

        if ($item->barcode && $this->barcodeTaken((int) $item->vendor_id, $item->barcode)) {
            throw ValidationException::withMessages(['name' => "The barcode {$item->barcode} now belongs to a product. Link it instead."]);
        }

        return DB::transaction(function () use ($by, $item, $data) {
            $product = $this->catalogue->quickCreate($item->vendor, $item->store, [
                'name'      => $data['name'],
                'barcode'   => $item->barcode,
                'base_unit' => $data['base_unit'] ?? null,
                'price'     => $data['price'],
            ]);

            $this->resolve($by, $item, $product);

            return $product;
        });
    }

    /** @throws AuthorizationException|ValidationException */
    public function ignoreUnknown(User $by, StockTakeUnknownItem $item): void
    {
        $this->mustManage($by, (int) $item->vendor_id, (int) $item->store_id);
        $this->mustBeOpen($item);

        $item->update([
            'status'      => StockTakeUnknownItem::STATUS_IGNORED,
            'resolved_by' => $by->id,
            'resolved_at' => now(),
        ]);
    }

    private function resolve(User $by, StockTakeUnknownItem $item, Product $product): void
    {
        $item->update([
            'status'              => StockTakeUnknownItem::STATUS_RESOLVED,
            'resolved_product_id' => $product->id,
            'resolved_by'         => $by->id,
            'resolved_at'         => now(),
        ]);

        // It was found on that aisle, so that is where it sits.
        if ($item->aisle_id && ! DB::table('aisle_products')->where('aisle_id', $item->aisle_id)->where('product_id', $product->id)->exists()) {
            DB::table('aisle_products')->insert([
                'vendor_id'  => $item->vendor_id,
                'aisle_id'   => $item->aisle_id,
                'product_id' => $product->id,
                'source'     => AisleProduct::SOURCE_MAPPING,
                'mapped_by'  => $item->created_by,
                'mapped_at'  => $item->created_at ?? now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    /** The product's main barcode if it has none; otherwise an extra one for its base unit. */
    private function giveBarcode(Product $product, string $barcode, bool $asMain = false): void
    {
        $owner = Product::query()->where('vendor_id', $product->vendor_id)->where('barcode', $barcode)->value('id')
            ?? ProductBarcode::query()->where('vendor_id', $product->vendor_id)->where('barcode', $barcode)->value('product_id');

        if ($owner !== null) {
            if ((int) $owner === (int) $product->id) {
                return;
            }

            throw ValidationException::withMessages(['product_id' => "The barcode {$barcode} already belongs to another product."]);
        }

        if (blank($product->barcode)) {
            $product->update(['barcode' => $barcode]);

            return;
        }

        if ($asMain) {
            // The new code becomes the main one (the product's own save moves
            // the main code's lookup row), and the old one stays as another.
            $old = (string) $product->barcode;
            $product->update(['barcode' => $barcode]);

            ProductBarcode::firstOrCreate(
                ['vendor_id' => $product->vendor_id, 'barcode' => $old],
                ['product_id' => $product->id, 'product_unit_id' => null],
            );

            return;
        }

        ProductBarcode::create([
            'vendor_id'       => $product->vendor_id,
            'product_id'      => $product->id,
            'product_unit_id' => null,
            'barcode'         => $barcode,
        ]);
    }

    private function barcodeTaken(int $vendorId, string $barcode): bool
    {
        return Product::query()->where('vendor_id', $vendorId)->where('barcode', $barcode)->exists()
            || ProductBarcode::query()->where('vendor_id', $vendorId)->where('barcode', $barcode)->exists();
    }

    private function mustBeOpen(StockTakeUnknownItem $item): void
    {
        if ($item->status !== StockTakeUnknownItem::STATUS_OPEN) {
            throw ValidationException::withMessages(['item' => 'This item has already been dealt with.']);
        }
    }

    /** @throws AuthorizationException */
    private function mustManage(User $user, int $vendorId, int $storeId): void
    {
        if (! StockTakeAccess::canManage($user, $vendorId, $storeId)) {
            throw new AuthorizationException('You cannot run stock takes at this branch.');
        }
    }

    /** @param  array<int, string>  $held  aisle id => who holds it */
    private function heldMessage($aisles, array $held): string
    {
        return $aisles->filter(fn (Aisle $a) => isset($held[$a->id]))
            ->map(fn (Aisle $a) => "{$a->label()} is already in {$held[$a->id]}'s task")
            ->implode('; ') . '.';
    }
}

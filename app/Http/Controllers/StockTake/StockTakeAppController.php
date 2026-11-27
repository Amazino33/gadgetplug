<?php

namespace App\Http\Controllers\StockTake;

use App\Http\Controllers\Controller;
use App\Models\Aisle;
use App\Models\Product;
use App\Models\StockTakeTask;
use App\Models\Store;
use App\Models\User;
use App\Models\Vendor;
use App\Services\ActiveStore;
use App\Services\StockTake\AisleMapper;
use App\Services\StockTake\StockTakeAccess;
use App\Services\StockTake\StockTakeCatalogue;
use App\Services\StockTake\StockTakePack;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

/**
 * The Stock Take phone app: My Tasks and aisle mapping (step 3). Counting
 * arrives in step 5.
 *
 * Session-authenticated JSON under /stock-take/{vendor}, behind
 * EnsureCanUseStockTake. Every aisle and task taken from the URL is checked
 * against the vendor in the URL, and every action against StockTakeAccess.
 * No response carries a stock figure.
 */
class StockTakeAppController extends Controller
{
    public function __construct(
        private readonly StockTakeCatalogue $catalogue,
        private readonly AisleMapper $mapper,
    ) {}

    public function show(Request $request, Vendor $vendor)
    {
        $store = ActiveStore::get($vendor, $request->user());

        return view('stock-take.index', [
            'config' => [
                'vendorSlug' => $vendor->slug,
                'vendorName' => $vendor->name,
                'storeName'  => $store?->name,
                'userName'   => $request->user()->name,
                'panelUrl'   => url("/plug/{$vendor->slug}"),
                'apiBase'    => url("/stock-take/{$vendor->slug}/api"),
                // A counter has no panel: the phone app gives them Sign out, and
                // remembers where to sign in again if the session ends.
                'isCounter'  => $request->user()->isCounter(),
                'signOutUrl' => url("/stock-take/{$vendor->slug}/sign-out"),
                'signInUrl'  => $request->user()->isCounter() ? app(\App\Services\StockTake\StockTakeCounters::class)->joinUrl($vendor) : null,
            ],
        ]);
    }

    /**
     * A fresh CSRF token. The app's page can be opened from the phone's cache
     * (release 2), carrying a token from an earlier visit; the app asks for
     * this when a save is refused for an old token, and tries again.
     */
    public function csrf(Vendor $vendor): JsonResponse
    {
        return response()->json(['token' => csrf_token()]);
    }

    /** What this person has to do here: their open tasks, and whether they may map freely. */
    public function home(Request $request, Vendor $vendor): JsonResponse
    {
        $user  = $request->user();
        $store = ActiveStore::get($vendor, $user);

        $tasks = StockTakeTask::query()
            ->where('vendor_id', $vendor->id)
            ->where('assigned_to', $user->id)
            ->open()
            ->with(['aisles' => fn ($q) => $q->ordered(), 'stockTake:id,reference,name', 'store:id,name'])
            ->withCount('products')
            ->orderByRaw('due_date IS NULL, due_date')
            ->orderBy('id')
            ->get()
            ->filter(fn (StockTakeTask $task) => StockTakeAccess::canWorkOn($user, $task))
            ->map(fn (StockTakeTask $task) => $this->taskSummary($task))
            ->values();

        return response()->json([
            'tasks'      => $tasks,
            'canMapAll'  => $store !== null && StockTakeAccess::canMap($user, $vendor->id, $store->id),
            'store'      => $store ? ['id' => $store->id, 'name' => $store->name] : null,
        ]);
    }

    /** Every active aisle at the branch, for someone who holds map_aisles. */
    public function aisles(Request $request, Vendor $vendor): JsonResponse
    {
        $store = ActiveStore::get($vendor, $request->user());

        abort_unless($store && StockTakeAccess::canMap($request->user(), $vendor->id, $store->id), 403, 'You cannot map aisles at this branch.');

        return response()->json([
            'aisles' => Aisle::query()
                ->where('vendor_id', $vendor->id)
                ->where('store_id', $store->id)
                ->active()
                ->listed()
                ->ordered()
                ->withCount('products')
                ->get()
                ->map(fn (Aisle $aisle) => $this->aisleSummary($aisle))
                ->values(),
        ]);
    }

    /** One aisle and the products mapped to it. */
    public function aisle(Request $request, Vendor $vendor, int $aisle): JsonResponse
    {
        $aisle = $this->aisleOf($vendor, $aisle);
        $this->mayMap($request->user(), $aisle);

        $products = $aisle->products()
            ->with('units')
            ->orderByPivot('mapped_at', 'desc')
            ->get();

        $mappers = User::query()
            ->whereIn('id', $products->pluck('pivot.mapped_by')->filter()->unique())
            ->pluck('name', 'id');

        return response()->json([
            'aisle'    => $this->aisleSummary($aisle),
            'products' => $products->map(fn (Product $product) => [
                ...$this->catalogue->format($product),
                'source'    => $product->pivot->source,
                'mapped_at' => $product->pivot->mapped_at,
                'mapped_by' => $mappers[$product->pivot->mapped_by] ?? null,
            ])->values(),
        ]);
    }

    /** A scanned code: added, already there, or not a product of this shop. */
    public function scan(Request $request, Vendor $vendor, int $aisle): JsonResponse
    {
        $data  = $request->validate([
            'code'    => ['required', 'string', 'max:100'],
            'task_id' => ['nullable', 'integer'],
        ]);
        $aisle = $this->aisleOf($vendor, $aisle);
        $found = $this->catalogue->lookup($vendor, $data['code']);

        if (! $found) {
            $this->mayMap($request->user(), $aisle);

            return response()->json(['status' => 'unknown', 'code' => trim($data['code'])]);
        }

        [$product, $unitId] = $found;
        $added = $this->mapOrRefuse($request->user(), $aisle, $product, $this->taskOf($vendor, $data['task_id'] ?? null));

        return response()->json([
            'status'  => $added ? 'added' : 'already',
            'product' => $this->catalogue->format($product, $unitId),
        ]);
    }

    /**
     * The shop's products for mapping, so the phone finds a scan itself and
     * says "added" at once. Same compact rows as the task pack; no stock,
     * cost or price. With ?v= equal to the current version, just "same".
     */
    public function mappingCatalogue(Request $request, Vendor $vendor, StockTakePack $pack): JsonResponse
    {
        $version = $pack->productsVersion($vendor);

        if ($request->query('v') === $version) {
            return response()->json(['same' => true, 'v' => $version]);
        }

        $products = Cache::remember("stock-take.map-catalogue.{$vendor->id}.{$version}", now()->addHours(6), fn () => $pack->products($vendor));

        return response()->json(['v' => $version, 'products' => $products])
            ->header('Cache-Control', 'private, no-store');
    }

    /**
     * Products the phone has already put on the aisle's map, saved together.
     * Each is added or was already there; one this shop does not have is
     * listed as missing. Not allowed to map this aisle: refused as a whole.
     */
    public function mapMany(Request $request, Vendor $vendor, int $aisle): JsonResponse
    {
        $data  = $request->validate([
            'product_ids'   => ['required', 'array', 'max:500'],
            'product_ids.*' => ['integer'],
            'task_id'       => ['nullable', 'integer'],
        ]);
        $aisle = $this->aisleOf($vendor, $aisle);
        $this->mayMap($request->user(), $aisle);

        $task     = $this->taskOf($vendor, $data['task_id'] ?? null);
        $ids      = array_values(array_unique(array_map('intval', $data['product_ids'])));
        $products = Product::query()->where('vendor_id', $vendor->id)->whereIn('id', $ids)->get()->keyBy('id');
        $result   = ['added' => [], 'already' => [], 'missing' => []];

        foreach ($ids as $id) {
            if (! $products->has($id)) {
                $result['missing'][] = $id;
                continue;
            }

            $added = $this->mapOrRefuse($request->user(), $aisle, $products[$id], $task);
            $result[$added ? 'added' : 'already'][] = $id;
        }

        return response()->json($result);
    }

    /** Added from a name search rather than a scan. */
    public function addProduct(Request $request, Vendor $vendor, int $aisle): JsonResponse
    {
        $data    = $request->validate([
            'product_id' => ['required', 'integer'],
            'task_id'    => ['nullable', 'integer'],
        ]);
        $aisle   = $this->aisleOf($vendor, $aisle);
        $product = Product::query()->where('vendor_id', $vendor->id)->findOrFail($data['product_id']);
        $added   = $this->mapOrRefuse($request->user(), $aisle, $product, $this->taskOf($vendor, $data['task_id'] ?? null));

        return response()->json([
            'status'  => $added ? 'added' : 'already',
            'product' => $this->catalogue->format($product),
        ]);
    }

    public function removeProduct(Request $request, Vendor $vendor, int $aisle, int $product): JsonResponse
    {
        $aisle   = $this->aisleOf($vendor, $aisle);
        $product = Product::query()->where('vendor_id', $vendor->id)->findOrFail($product);

        try {
            $this->mapper->unmap($request->user(), $aisle, $product);
        } catch (AuthorizationException $e) {
            abort(403, $e->getMessage());
        }

        return response()->json(['status' => 'removed']);
    }

    public function search(Request $request, Vendor $vendor): JsonResponse
    {
        return response()->json([
            'products' => $this->catalogue->search($vendor, (string) $request->query('q', ''))
                ->map(fn (Product $product) => $this->catalogue->format($product))
                ->values(),
        ]);
    }

    /** A barcode the system does not know: name and photo, for a manager to resolve. */
    public function storeUnknown(Request $request, Vendor $vendor): JsonResponse
    {
        $data  = $request->validate([
            'aisle_id' => ['required', 'integer'],
            'task_id'  => ['nullable', 'integer'],
            'barcode'  => ['nullable', 'string', 'max:64'],
            'name'     => ['required', 'string', 'max:160'],
            'photo'    => ['nullable', 'image', 'max:8192'],
        ]);
        $aisle = $this->aisleOf($vendor, (int) $data['aisle_id']);

        try {
            $item = $this->mapper->recordUnknown(
                $request->user(),
                $aisle,
                $data['name'],
                $data['barcode'] ?? null,
                $request->file('photo'),
                $this->taskOf($vendor, $data['task_id'] ?? null),
            );
        } catch (AuthorizationException $e) {
            abort(403, $e->getMessage());
        }

        return response()->json(['status' => 'recorded', 'id' => $item->id], 201);
    }

    /** The assignee marks their mapping task done. */
    public function finishTask(Request $request, Vendor $vendor, int $task): JsonResponse
    {
        $task = $this->taskOf($vendor, $task) ?? abort(404);

        try {
            $this->mapper->finish($request->user(), $task);
        } catch (AuthorizationException $e) {
            abort(403, $e->getMessage());
        }

        return response()->json(['status' => 'submitted']);
    }

    private function mapOrRefuse(User $user, Aisle $aisle, Product $product, ?StockTakeTask $task): bool
    {
        try {
            return $this->mapper->map($user, $aisle, $product, $task);
        } catch (AuthorizationException $e) {
            abort(403, $e->getMessage());
        }
    }

    private function mayMap(User $user, Aisle $aisle): void
    {
        abort_unless($aisle->is_active && StockTakeAccess::canMapAisle($user, $aisle), 403, 'You cannot map this aisle.');
    }

    /** An aisle of this vendor, or 404 — never another shop's. */
    private function aisleOf(Vendor $vendor, int $id): Aisle
    {
        return Aisle::query()->where('vendor_id', $vendor->id)->findOrFail($id);
    }

    private function taskOf(Vendor $vendor, ?int $id): ?StockTakeTask
    {
        return $id ? StockTakeTask::query()->where('vendor_id', $vendor->id)->find($id) : null;
    }

    private function aisleSummary(Aisle $aisle): array
    {
        return [
            'id'             => $aisle->id,
            'name'           => $aisle->name,
            'code'           => $aisle->code,
            'label'          => $aisle->label(),
            'quick'          => (bool) $aisle->is_quick,
            'products_count' => $aisle->products_count ?? $aisle->products()->count(),
        ];
    }

    private function taskSummary(StockTakeTask $task): array
    {
        return [
            'id'             => $task->id,
            'type'           => $task->type,
            'status'         => $task->status,
            'due_date'       => $task->due_date?->toDateString(),
            'overdue'        => $task->due_date !== null && $task->due_date->isBefore(\App\Support\Pos\BusinessDate::today()),
            'store'          => $task->store?->name,
            'stock_take'     => $task->stockTake ? ['reference' => $task->stockTake->reference, 'name' => $task->stockTake->name] : null,
            'aisles'         => $task->aisles->map(fn (Aisle $aisle) => $this->aisleSummary($aisle))->values(),
            'products_count' => $task->products_count,
        ];
    }
}

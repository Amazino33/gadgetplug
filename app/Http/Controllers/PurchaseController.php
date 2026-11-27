<?php

namespace App\Http\Controllers;

use App\Rules\QuantityStep;
use App\Models\Procurement;
use App\Models\PurchasePayment;
use App\Models\Product;
use App\Models\Store;
use App\Models\Supplier;
use App\Models\Vendor;
use App\Services\ActiveStore;
use App\Services\Purchases\PurchaseCatalogue;
use App\Services\Purchases\PurchaseHistory;
use App\Services\Purchases\PurchaseRecorder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * Purchases: the Purchase Terminal and Purchase History, at /purchases/{vendor}.
 *
 * Session-authenticated rather than on the till's PIN token: receiving stock is
 * back-office work done from the panel, with no shift or cash drawer behind it.
 * Every route sits behind EnsureCanRecordPurchases.
 */
class PurchaseController extends Controller
{
    public function __construct(
        private readonly PurchaseCatalogue $catalogue,
        private readonly PurchaseHistory $history,
    ) {}

    public function show(Request $request, Vendor $vendor)
    {
        $store = $this->branch($request, $vendor);

        return view('purchases.index', [
            'config' => [
                'vendorSlug' => $vendor->slug,
                'vendorName' => $vendor->name,
                'storeName'  => $store->name,
                'userName'   => $request->user()->name,
                // The purchase being typed is kept on this device per person and branch.
                'userId'     => $request->user()->id,
                'storeId'    => $store->id,
                'panelUrl'   => url("/plug/{$vendor->slug}"),
                'apiBase'    => url("/purchases/{$vendor->slug}/api"),
                'statuses'   => PurchaseHistory::STATUS_LABELS,
                'payments'   => Procurement::PAYMENT_LABELS,
                'methods'    => PurchasePayment::METHODS,
                // Selling prices worked out from a % change round to this.
                'roundingStep' => max(0, (int) ($vendor->price_rounding_step ?? 50)),
                // False: this person's purchases go to an approver instead of
                // straight into stock.
                'canApprove' => $this->canApprove($request, $vendor),
                // 10½ crates: a shop that sells halves buys in halves too.
                'allowHalves' => (bool) $vendor->allow_half_quantities,
            ],
        ]);
    }

    /** One page of the terminal's in-memory catalogue (see PurchaseCatalogue::page). */
    public function catalogue(Request $request, Vendor $vendor): JsonResponse
    {
        $request->validate([
            'after_id' => ['nullable', 'integer', 'min:0'],
            'limit'    => ['nullable', 'integer', 'min:1', 'max:1000'],
        ]);

        return response()->json($this->catalogue->page(
            $vendor,
            $this->branch($request, $vendor)->id,
            (int) $request->input('after_id', 0),
            (int) $request->input('limit', 500),
        ));
    }

    public function lookup(Request $request, Vendor $vendor): JsonResponse
    {
        $code  = (string) $request->query('code', '');
        $found = $this->catalogue->lookup($vendor, $code);

        if (! $found) {
            return response()->json(['found' => false, 'code' => trim($code)]);
        }

        return response()->json([
            'found'   => true,
            'product' => $this->catalogue->format($found[0], $found[1], $this->branch($request, $vendor)->id),
        ]);
    }

    public function search(Request $request, Vendor $vendor): JsonResponse
    {
        $products = $this->catalogue->search($vendor, (string) $request->query('q', ''));
        $storeId  = $this->branch($request, $vendor)->id;
        $stock    = $this->catalogue->stockAt($storeId, $products->modelKeys());

        return response()->json([
            'products' => $products->map(fn (Product $p) => $this->catalogue->format($p, null, $storeId, $stock))->values(),
        ]);
    }

    public function storeProduct(Request $request, Vendor $vendor): JsonResponse
    {
        $data = $request->validate([
            'name'      => ['required', 'string', 'max:255'],
            'barcode'   => ['nullable', 'string', 'max:100',
                Rule::unique('products', 'barcode')->where('vendor_id', $vendor->id),
                Rule::unique('product_barcodes', 'barcode')->where('vendor_id', $vendor->id)],
            'base_unit' => ['nullable', 'string', 'max:32'],
            'price'     => ['required', 'numeric', 'min:0'],
        ], [
            'barcode.unique' => 'That barcode is already used by another product in this shop.',
        ]);

        $store   = $this->branch($request, $vendor);
        $product = $this->catalogue->quickCreate($vendor, $store, $data);

        return response()->json(['product' => $this->catalogue->format($product, null, $store->id)], 201);
    }

    /** A word someone searched with before picking this product (see SearchShortcuts). */
    public function learnSearch(Request $request, Vendor $vendor): JsonResponse
    {
        $data = $request->validate([
            'product_id' => ['required', 'integer'],
            'term'       => ['required', 'string', 'max:100'],
        ]);

        app(\App\Services\Products\SearchShortcuts::class)->record($vendor->id, (int) $data['product_id'], $data['term']);

        return response()->json(['ok' => true]);
    }

    public function suppliers(Vendor $vendor): JsonResponse
    {
        // What is owed each supplier: the unpaid balance of every purchase
        // made from them, drafts aside (nothing is owed on a list).
        $owed = Procurement::query()
            ->selectRaw('COALESCE(SUM(total_cost - amount_paid), 0)')
            ->whereColumn('procurements.supplier_id', 'suppliers.id')
            ->whereNotIn('procurements.status', [Procurement::STATUS_DRAFT, Procurement::STATUS_VOIDED]);

        return response()->json([
            'suppliers' => Supplier::where('vendor_id', $vendor->id)
                ->select(['id', 'name', 'phone'])
                ->selectSub($owed, 'balance')
                ->orderBy('name')
                ->get()
                ->map(fn (Supplier $s) => [
                    'id'      => $s->id,
                    'name'    => $s->name,
                    'phone'   => $s->phone,
                    'balance' => round(max(0, (float) $s->balance), 2),
                ]),
        ]);
    }

    public function storeSupplier(Request $request, Vendor $vendor): JsonResponse
    {
        $data = $request->validate([
            'name'  => ['required', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:20'],
        ]);

        $supplier = Supplier::create(['vendor_id' => $vendor->id] + $data);

        return response()->json(['supplier' => $supplier->only(['id', 'name', 'phone'])], 201);
    }

    public function index(Request $request, Vendor $vendor): JsonResponse
    {
        $filters = $request->validate([
            'from'        => ['nullable', 'date'],
            'to'          => ['nullable', 'date'],
            'supplier_id' => ['nullable', 'integer'],
            'status'      => ['nullable', Rule::in(array_keys(PurchaseHistory::STATUS_LABELS))],
            'payment'     => ['nullable', Rule::in(array_keys(Procurement::PAYMENT_LABELS))],
            'q'           => ['nullable', 'string', 'max:100'],
        ]);

        $page       = $this->history->list($vendor, $request->user(), $filters);
        $canApprove = $this->canApprove($request, $vendor);

        return response()->json([
            'data' => collect($page->items())->map(fn (Procurement $p) => $this->history->row($p, $canApprove))->values(),
            'meta' => [
                'current_page' => $page->currentPage(),
                'last_page'    => $page->lastPage(),
                'total'        => $page->total(),
            ],
        ]);
    }

    public function detail(Request $request, Vendor $vendor, int $purchase): JsonResponse
    {
        $procurement = $this->history->reachable($vendor, $request->user())->findOrFail($purchase);

        return response()->json(['purchase' => $this->history->detail($vendor, $procurement, $this->canApprove($request, $vendor))]);
    }

    public function store(Request $request, Vendor $vendor, PurchaseRecorder $recorder): JsonResponse
    {
        $data = $request->validate([
            'action'                  => ['required', Rule::in([PurchaseRecorder::ACTION_DRAFT, PurchaseRecorder::ACTION_RECEIVE, PurchaseRecorder::ACTION_SUBMIT])],
            'id'                      => ['nullable', 'integer'],
            'supplier_id'             => ['required', 'integer'],
            'invoice_number'          => ['nullable', 'string', 'max:100'],
            'purchased_on'            => ['nullable', 'date', 'before_or_equal:today'],
            'notes'                   => ['nullable', 'string', 'max:2000'],
            'payment'                 => ['nullable', 'array'],
            'payment.option'          => ['nullable', Rule::in(['full', 'part', 'none'])],
            'payment.amount'          => ['nullable', 'numeric', 'min:0'],
            'payment.method'          => ['nullable', Rule::in(array_keys(PurchasePayment::METHODS))],
            'payment.note'            => ['nullable', 'string', 'max:500'],
            'lines'                   => ['required', 'array', 'min:1'],
            'lines.*.product_id'      => ['required', 'integer'],
            'lines.*.product_unit_id' => ['nullable', 'integer'],
            // 10½ crates for a shop that sells halves; whole for everyone else.
            'lines.*.quantity'        => ['required', 'numeric', new QuantityStep($vendor)],
            'lines.*.unit_cost'       => ['required', 'numeric', 'min:0'],
            'lines.*.selling_price'   => ['required', 'numeric', 'min:0'],
            'lines.*.unit_prices'           => ['nullable', 'array'],
            'lines.*.unit_prices.*.unit_id' => ['required', 'integer'],
            'lines.*.unit_prices.*.price'   => ['required', 'numeric', 'min:0'],
            'lines.*.serials'         => ['nullable', 'array'],
            'lines.*.serials.*'       => ['nullable', 'string', 'max:100'],
        ], [
            'supplier_id.required' => 'Choose the supplier.',
            'lines.required'       => 'Scan or add at least one product.',
        ]);

        $canApprove = $this->canApprove($request, $vendor);

        // Receiving is the approver's step. Everyone else submits.
        if ($data['action'] === PurchaseRecorder::ACTION_RECEIVE && ! $canApprove) {
            return response()->json([
                'message' => 'You can submit this purchase for approval. A manager receives it into stock.',
            ], 403);
        }

        // A draft is continued only by someone who could open it in History.
        if (filled($data['id'] ?? null) && ! $this->history->reachable($vendor, $request->user())->whereKey($data['id'])->exists()) {
            throw ValidationException::withMessages(['id' => 'That draft is no longer available.']);
        }

        try {
            $procurement = $recorder->save($vendor, $request->user(), $this->branch($request, $vendor), $data, $data['action'], $canApprove);
        } catch (ValidationException $e) {
            throw $e;
        } catch (RuntimeException $e) {
            // The approval action's own refusals (a product stocked at another
            // branch), already written for the person reading them.
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $procurement = $this->history->reachable($vendor, $request->user())->findOrFail($procurement->id);

        return response()->json(['purchase' => $this->history->detail($vendor, $procurement, $this->canApprove($request, $vendor))]);
    }

    /** An approver receives a purchase someone submitted, choosing how it was paid. */
    public function receiveSubmitted(Request $request, Vendor $vendor, int $purchase, PurchaseRecorder $recorder): JsonResponse
    {
        abort_unless($this->canApprove($request, $vendor), 403, 'Only someone who can approve purchases can receive this one.');

        $procurement = $this->history->reachable($vendor, $request->user())->findOrFail($purchase);

        $data = $request->validate([
            'payment'        => ['required', 'array'],
            'payment.option' => ['required', Rule::in(['full', 'part', 'none'])],
            'payment.amount' => ['nullable', 'numeric', 'min:0'],
            'payment.method' => ['nullable', Rule::in(array_keys(PurchasePayment::METHODS))],
            'payment.note'   => ['nullable', 'string', 'max:500'],
        ], [
            'payment.required'        => 'Choose how this purchase was paid before receiving it.',
            'payment.option.required' => 'Choose how this purchase was paid before receiving it.',
        ]);

        try {
            $recorder->receiveSubmitted($procurement, $request->user(), $this->branch($request, $vendor), $data['payment']);
        } catch (ValidationException $e) {
            throw $e;
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $procurement = $this->history->reachable($vendor, $request->user())->findOrFail($purchase);

        return response()->json(['purchase' => $this->history->detail($vendor, $procurement, true)]);
    }

    /**
     * Whether this person receives stock themselves or submits for approval.
     * The owner always may (hasVendorPermission says so for them).
     */
    private function canApprove(Request $request, Vendor $vendor): bool
    {
        $user = $request->user();

        return $user->isSuperAdmin() || $user->hasVendorPermission($vendor->id, 'approve_procurement');
    }

    /** Pay a supplier later, from Purchase History. */
    public function storePayment(Request $request, Vendor $vendor, int $purchase, PurchaseRecorder $recorder): JsonResponse
    {
        $procurement = $this->history->reachable($vendor, $request->user())->findOrFail($purchase);

        $data = $request->validate([
            'amount' => ['required', 'numeric', 'min:0.01'],
            'method' => ['required', Rule::in(array_keys(PurchasePayment::METHODS))],
            'note'   => ['nullable', 'string', 'max:500'],
        ], [
            'method.required' => 'Choose how it was paid.',
        ]);

        $recorder->recordPayment($procurement, $request->user(), $data);

        $procurement = $this->history->reachable($vendor, $request->user())->findOrFail($purchase);

        return response()->json(['purchase' => $this->history->detail($vendor, $procurement, $this->canApprove($request, $vendor))]);
    }

    /** The branch this person is working in, the same one the panel shows. */
    private function branch(Request $request, Vendor $vendor): Store
    {
        $store = ActiveStore::get($vendor, $request->user());

        abort_unless($store !== null, 403, 'You are not assigned to any branch of this shop.');

        return $store;
    }
}

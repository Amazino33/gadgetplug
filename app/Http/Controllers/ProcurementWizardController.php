<?php

namespace App\Http\Controllers;

use App\Models\ProductStoreStock;
use App\Models\Store;
use App\Models\Supplier;
use App\Models\Product;
use App\Models\Procurement;
use App\Models\ProcurementItem;
use App\Models\ProcurementLogisticsLeg;
use App\Models\Vendor;
use Illuminate\Http\Request;
use App\Services\ActiveStore;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class ProcurementWizardController extends Controller
{
    // The wizard writes real Procurement/ProcurementItem rows and shows every
    // product's cost price, so it needs the same manage_procurement gate as
    // ProcurementResource in the Filament panel — not just "is logged in".
    // hasVendorPermission() already short-circuits true for the vendor's
    // owner, so this one call covers both cases.
    private function resolveAuthorizedVendor(Request $request): Vendor
    {
        $user = $request->user();
        $vendor = $user->ownedVendors()->first() ?? $user->memberVendors()->first();

        abort_unless(
            $vendor && $user->hasVendorPermission($vendor->id, 'manage_procurement'),
            403,
            'You are not authorized to run procurement for this store.'
        );

        return $vendor;
    }

    public function create(Request $request)
    {
        $vendor = $this->resolveAuthorizedVendor($request);

        $suppliers = Supplier::where('vendor_id', $vendor->id)
            ->orderByDesc('rating')
            ->get();

        $selectedSupplier = session('procurement.supplier_id');
        $receiptImage = session('procurement.receipt_image');

        // Only branches this user can actually reach. A storekeeper assigned to
        // one branch has no business receiving goods into another.
        $stores = ActiveStore::accessibleFor($vendor, $request->user());
        $selectedStore = session('procurement.store_id')
            ?? ActiveStore::currentId()
            ?? $stores->firstWhere('is_default', true)?->id
            ?? $stores->first()?->id;

        return view('procurement.create', compact('vendor', 'suppliers', 'selectedSupplier', 'receiptImage', 'stores', 'selectedStore'));
    }

    public function storeSupplier(Request $request)
    {
        $vendor = $this->resolveAuthorizedVendor($request);

        $request->validate([
            'supplier_id'   => 'required|exists:suppliers,id',
            // Checked against the branches this user can reach rather than
            // exists:stores,id, so a posted id cannot name another vendor's
            // branch or one this user was never assigned to.
            'store_id'      => ['required', Rule::in(ActiveStore::accessibleFor($vendor, $request->user())->pluck('id'))],
            'receipt_image'  => 'nullable|image|max:5120',
        ], [
            'store_id.required' => 'Choose which branch these goods are being delivered to.',
            'store_id.in'       => 'You cannot receive goods into that branch.',
        ]);

        session([
            'procurement.supplier_id' => $request->supplier_id,
            'procurement.store_id'    => (int) $request->store_id,
        ]);

        if ($request->hasFile('receipt_image')) {
            $path = $request->file('receipt_image')->store('receipts', 'public');
            session(['procurement.receipt_image' => $path]);
        }

        return redirect()->route('procurement.items');
    }

    public function items(Request $request)
    {
        if (! session('procurement.supplier_id')) {
            return redirect()->route('procurement.create');
        }

        $vendor = $this->resolveAuthorizedVendor($request);

        if (! session('procurement.store_id')) {
            return redirect()->route('procurement.create');
        }

        $destination = Store::where('vendor_id', $vendor->id)
            ->findOrFail(session('procurement.store_id'));

        $supplier = Supplier::findOrFail(session('procurement.supplier_id'));
        $products = Product::where('vendor_id', $vendor->id)->orderBy('name')->get();

        // Which products already hold stock somewhere other than the branch
        // being delivered to. Those are the ones approval will refuse, because
        // a product lives in exactly one branch and receiving here would strand
        // the units it holds there. Flagged while the order is being built
        // rather than left to fail at approval, when the goods have arrived and
        // it is too late to do anything about it.
        $heldElsewhere = ProductStoreStock::whereIn('product_id', $products->pluck('id'))
            ->where('store_id', '!=', $destination->id)
            ->where(fn ($q) => $q->where('quantity', '>', 0)->orWhere('reserved', '>', 0))
            ->pluck('product_id')
            ->flip();

        $storeNames = Store::where('vendor_id', $vendor->id)->pluck('name', 'id');

        $productsJson = $products->map(fn($p) => [
            'id' => $p->id, 'name' => $p->name,
            'price' => $p->price ?? 0, 'cost_price' => $p->cost_price ?? 0,
            // Receivable here if it is already homed at this branch, or holds
            // nothing anywhere — an empty product is re-homed on approval.
            'receivable' => (int) $p->store_id === (int) $destination->id
                || ! $heldElsewhere->has($p->id),
            'held_at' => $storeNames[$p->store_id] ?? null,
        ])->values();
        $items    = session('procurement.items', []);

        return view('procurement.items', compact('vendor', 'supplier', 'products', 'productsJson', 'items', 'destination'));
    }

    public function storeItems(Request $request)
    {
        $request->validate([
            'items'                  => 'required|array|min:1',
            'items.*.product_id'     => 'required|exists:products,id',
            'items.*.barcode'        => 'nullable|string',
            'items.*.quantity'       => 'required|integer|min:1',
            'items.*.unit_cost'      => 'required|numeric|min:0',
            'items.*.selling_price'  => 'required|numeric|min:0',
        ]);

        session(['procurement.items' => $request->items]);

        return redirect()->route('procurement.logistics');
    }

    public function logistics(Request $request)
    {
        if (! session('procurement.items')) {
            return redirect()->route('procurement.items');
        }

        $vendor = $this->resolveAuthorizedVendor($request);

        $supplier = Supplier::findOrFail(session('procurement.supplier_id'));
        $legs     = session('procurement.logistics', []);

        return view('procurement.logistics', compact('vendor', 'supplier', 'legs'));
    }

    public function storeLogistics(Request $request)
    {
        $request->validate([
            'legs'                 => 'nullable|array',
            'legs.*.route_label'   => 'required|string|max:255',
            'legs.*.amount'        => 'required|numeric|min:0',
        ]);

        session(['procurement.logistics' => $request->legs ?? []]);

        return redirect()->route('procurement.financials');
    }

    public function financials(Request $request)
    {
        if (! session('procurement.items')) {
            return redirect()->route('procurement.items');
        }

        $vendor = $this->resolveAuthorizedVendor($request);

        $supplier   = Supplier::findOrFail(session('procurement.supplier_id'));
        $items      = session('procurement.items', []);
        $products   = Product::whereIn('id', array_column($items, 'product_id'))->get()->keyBy('id');
        $financials = session('procurement.financials', []);

        $subtotal       = collect($items)->sum(fn($i) => $i['quantity'] * $i['unit_cost']);
        $logisticsTotal = collect(session('procurement.logistics', []))->sum(fn($l) => (float) $l['amount']);

        return view('procurement.financials', compact('vendor', 'supplier', 'items', 'products', 'subtotal', 'financials', 'logisticsTotal'));
    }

    public function storeFinancials(Request $request)
    {
        $request->validate([
            'payment_method'   => 'required|in:bank_transfer,cash,credit',
            'amount_paid'      => 'required|numeric|min:0',
            'reference_number' => 'nullable|string|max:255',
        ]);

        session(['procurement.financials' => $request->only('payment_method', 'amount_paid', 'reference_number')]);

        return redirect()->route('procurement.confirm');
    }

    public function confirm(Request $request)
    {
        if (! session('procurement.financials')) {
            return redirect()->route('procurement.financials');
        }

        $vendor = $this->resolveAuthorizedVendor($request);
        $supplier   = Supplier::findOrFail(session('procurement.supplier_id'));
        $destination = Store::find(session('procurement.store_id'));
        $items      = session('procurement.items', []);
        $financials = session('procurement.financials');
        $products   = Product::whereIn('id', array_column($items, 'product_id'))->get()->keyBy('id');
        $subtotal   = collect($items)->sum(fn($i) => $i['quantity'] * $i['unit_cost']);
        $legs       = session('procurement.logistics', []);
        $logisticsTotal = collect($legs)->sum(fn($l) => (float) $l['amount']);

        return view('procurement.confirm', compact('vendor', 'supplier', 'destination', 'items', 'products', 'financials', 'subtotal', 'legs', 'logisticsTotal'));
    }

    public function submit(Request $request)
    {
        $vendor = $this->resolveAuthorizedVendor($request);
        $financials = session('procurement.financials');
        $items      = session('procurement.items', []);
        $subtotal   = collect($items)->sum(fn($i) => $i['quantity'] * $i['unit_cost']);

        $amountPaid = (float) str_replace(',', '', $financials['amount_paid']);

        $paymentStatus = match (true) {
            $amountPaid <= 0             => 'credit',
            $amountPaid >= $subtotal     => 'full',
            default                      => 'part_payment',
        };

        $legs = session('procurement.logistics', []);

        DB::transaction(function () use ($vendor, $financials, $items, $subtotal, $amountPaid, $paymentStatus, $legs) {
            $procurement = Procurement::create([
                'vendor_id'      => $vendor->id,
                'store_id'       => session('procurement.store_id'),
                'supplier_id'    => session('procurement.supplier_id'),
                'created_by'     => auth()->id(),
                'total_cost'     => $subtotal,
                'amount_paid'    => $amountPaid,
                'payment_status' => $paymentStatus,
                'payment_method' => $financials['payment_method'],
                'notes'          => $financials['reference_number'] ?? null,
                'status'         => 'pending',
                'waybill_image'  => session('procurement.receipt_image'),
            ]);


            foreach ($items as $item) {
                ProcurementItem::create([
                    'procurement_id' => $procurement->id,
                    'product_id'     => $item['product_id'],
                    'barcode'        => $item['barcode'] ?? null,
                    'quantity'       => $item['quantity'],
                    'unit_cost'      => $item['unit_cost'],
                    'selling_price'  => $item['selling_price'],
                ]);
            }

            foreach ($legs as $index => $leg) {
                ProcurementLogisticsLeg::create([
                    'procurement_id' => $procurement->id,
                    'route_label'    => $leg['route_label'],
                    'amount'         => $leg['amount'],
                    'sort_order'     => $index,
                ]);
            }
        });

        session()->forget(['procurement.supplier_id', 'procurement.store_id', 'procurement.items', 'procurement.logistics', 'procurement.financials', 'procurement.receipt_image']);

        return redirect()->route('procurement.create')
            ->with('success', 'Procurement submitted successfully and is pending approval.');
    }
}

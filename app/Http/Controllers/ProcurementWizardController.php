<?php

namespace App\Http\Controllers;

use App\Models\Supplier;
use App\Models\Product;
use App\Models\Procurement;
use App\Models\ProcurementItem;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ProcurementWizardController extends Controller
{
    public function create(Request $request)
    {
        $vendor = $request->user()->ownedVendors()->first()
            ?? $request->user()->memberVendors()->first()
            ?? (\App\Models\Vendor::first());

        if (! $vendor) {
            abort(403, 'No vendor associated with your account. Please log in as a vendor user.');
        }

        $suppliers = Supplier::where('vendor_id', $vendor->id)
            ->orderByDesc('rating')
            ->get();

        $selectedSupplier = session('procurement.supplier_id');
        $receiptImage = session('procurement.receipt_image');

        return view('procurement.create', compact('vendor', 'suppliers', 'selectedSupplier', 'receiptImage'));
    }

    public function storeSupplier(Request $request)
    {
        $request->validate([
            'supplier_id'   => 'required|exists:suppliers,id',
            'receipt_image'  => 'nullable|image|max:5120',
        ]);

        session(['procurement.supplier_id' => $request->supplier_id]);

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

        $vendor = $request->user()->ownedVendors()->first()
            ?? $request->user()->memberVendors()->first()
            ?? (\App\Models\Vendor::first());

        if (! $vendor) {
            abort(403, 'No vendor associated with your account. Please log in as a vendor user.');
        }

        $supplier = Supplier::findOrFail(session('procurement.supplier_id'));
        $products = Product::where('vendor_id', $vendor->id)->orderBy('name')->get();
        $productsJson = $products->map(fn($p) => [
            'id' => $p->id, 'name' => $p->name,
            'price' => $p->price ?? 0, 'cost_price' => $p->cost_price ?? 0,
        ])->values();
        $items    = session('procurement.items', []);

        return view('procurement.items', compact('vendor', 'supplier', 'products', 'productsJson', 'items'));
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

        return redirect()->route('procurement.financials');
    }

    public function financials(Request $request)
    {
        if (! session('procurement.items')) {
            return redirect()->route('procurement.items');
        }

        $vendor = $request->user()->ownedVendors()->first()
            ?? $request->user()->memberVendors()->first()
            ?? (\App\Models\Vendor::first());

        if (! $vendor) {
            abort(403, 'No vendor associated with your account. Please log in as a vendor user.');
        }

        $supplier   = Supplier::findOrFail(session('procurement.supplier_id'));
        $items      = session('procurement.items', []);
        $products   = Product::whereIn('id', array_column($items, 'product_id'))->get()->keyBy('id');
        $financials = session('procurement.financials', []);

        $subtotal = collect($items)->sum(fn($i) => $i['quantity'] * $i['unit_cost']);

        return view('procurement.financials', compact('vendor', 'supplier', 'items', 'products', 'subtotal', 'financials'));
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

        $vendor = $request->user()->ownedVendors()->first()
            ?? $request->user()->memberVendors()->first()
            ?? (\App\Models\Vendor::first());
        $supplier   = Supplier::findOrFail(session('procurement.supplier_id'));
        $items      = session('procurement.items', []);
        $financials = session('procurement.financials');
        $products   = Product::whereIn('id', array_column($items, 'product_id'))->get()->keyBy('id');
        $subtotal   = collect($items)->sum(fn($i) => $i['quantity'] * $i['unit_cost']);

        return view('procurement.confirm', compact('vendor', 'supplier', 'items', 'products', 'financials', 'subtotal'));
    }

    public function submit(Request $request)
    {
        $vendor = $request->user()->ownedVendors()->first()
            ?? $request->user()->memberVendors()->first()
            ?? (\App\Models\Vendor::first());
        $financials = session('procurement.financials');
        $items      = session('procurement.items', []);
        $subtotal   = collect($items)->sum(fn($i) => $i['quantity'] * $i['unit_cost']);

        $amountPaid = (float) str_replace(',', '', $financials['amount_paid']);

        $paymentStatus = match (true) {
            $amountPaid <= 0             => 'credit',
            $amountPaid >= $subtotal     => 'full',
            default                      => 'part_payment',
        };

        DB::transaction(function () use ($vendor, $financials, $items, $subtotal, $amountPaid, $paymentStatus) {
            $procurement = Procurement::create([
                'vendor_id'      => $vendor->id,
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
        });

        session()->forget(['procurement.supplier_id', 'procurement.items', 'procurement.financials', 'procurement.receipt_image']);

        return redirect()->route('procurement.create')
            ->with('success', 'Procurement submitted successfully and is pending approval.');
    }
}

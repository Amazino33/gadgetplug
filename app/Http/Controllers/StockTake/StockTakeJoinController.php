<?php

namespace App\Http\Controllers\StockTake;

use App\Http\Controllers\Controller;
use App\Models\StockTakeCounter;
use App\Models\Vendor;
use App\Services\StockTake\StockTakeCounters;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cookie;
use Illuminate\View\View;

/**
 * Where counters come in: the page behind the shop's QR code. They pick their
 * name from the shop's counters and type their 6-digit code, and land on My
 * Tasks. No email, no password, no account to find.
 *
 * The page is only there for someone holding the QR's secret (the `k` in the
 * address) and only while Stock Take is on for the shop. Wrong codes are
 * counted per counter and per address (StockTakeCounters).
 */
class StockTakeJoinController extends Controller
{
    public function __construct(private readonly StockTakeCounters $counters) {}

    public function show(Request $request, Vendor $vendor): View|RedirectResponse
    {
        $this->mustHoldKey($request, $vendor);

        $user = $request->user();
        if ($user && $user->isCounter() && (int) $user->counterProfile->vendor_id === (int) $vendor->id && $user->counterProfile->is_active) {
            return redirect()->route('stock-take.index', ['vendor' => $vendor->slug]);
        }

        return $this->page($vendor, $request->query('k'));
    }

    public function join(Request $request, Vendor $vendor): RedirectResponse|View
    {
        $this->mustHoldKey($request, $vendor);

        $data = $request->validate([
            'counter' => ['required', 'integer'],
            'code'    => ['required', 'digits:6'],
        ], [
            'counter.required' => 'Pick your name.',
            'code.required'    => 'Type your 6-digit code.',
            'code.digits'      => 'Your code is 6 digits.',
        ]);

        $user = $this->counters->attempt($vendor, (int) $data['counter'], $data['code']);

        Auth::login($user);
        $request->session()->regenerate();

        // Remembered on this phone, so a session that ends sends them back here.
        Cookie::queue(cookie('stock_take_join', $this->counters->joinUrl($vendor), 60 * 24 * 60, '/stock-take'));

        return redirect()->route('stock-take.index', ['vendor' => $vendor->slug]);
    }

    /** Sign out: back to this page, ready for the next counter on the phone. */
    public function signOut(Request $request, Vendor $vendor): RedirectResponse
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect($this->counters->joinUrl($vendor));
    }

    private function page(Vendor $vendor, ?string $key): View
    {
        return view('stock-take.join', [
            'vendor'   => $vendor,
            'key'      => $key,
            'counters' => StockTakeCounter::query()
                ->where('vendor_id', $vendor->id)
                ->where('is_active', true)
                ->orderBy('name')
                ->get(['id', 'name']),
        ]);
    }

    private function mustHoldKey(Request $request, Vendor $vendor): void
    {
        $real = (string) $vendor->stock_take_join_key;
        $sent = (string) ($request->query('k') ?? $request->input('k'));

        abort_unless($vendor->hasStockTake() && $real !== '' && hash_equals($real, $sent), 404);
    }
}

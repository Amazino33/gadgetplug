<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\StoreAccountClose;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * The account close, on screen and on paper.
 *
 * Both render the same view from the same frozen payload, so the copy discussed
 * on a laptop and the copy signed in a shop cannot say different things. The
 * same arrangement the settlement statement already uses, and for the same
 * reason.
 */
class StoreAccountCloseController extends Controller
{
    public function show(Request $request, StoreAccountClose $close)
    {
        $this->authorizeFor($request, $close);

        return view('settlements.close', $this->payload($close));
    }

    public function pdf(Request $request, StoreAccountClose $close)
    {
        $this->authorizeFor($request, $close);

        return Pdf::loadView('settlements.close', $this->payload($close))
            ->setPaper('a4', 'portrait')
            ->download($close->reference . '.pdf');
    }

    /** @return array<string, mixed> */
    private function payload(StoreAccountClose $close): array
    {
        $close->load(['store', 'closedBy', 'previousClose']);

        return ['close' => $close];
    }

    /**
     * Who may read a close.
     *
     * Belonging to the business is not enough. A close names people and says
     * what a branch is short, so it is gated on the same permission as the
     * settlement it sits on top of — checking only vendor membership here would
     * let anyone on the team read past the page guard by holding a link.
     *
     * Reading is deliberately NOT gated on close_store_period. Closing a period
     * and reading what was closed are different powers, and the people who have
     * to act on a shortage are rarely the ones allowed to declare it settled.
     */
    private function authorizeFor(Request $request, StoreAccountClose $close): void
    {
        $user = $request->user();
        $vendorId = (int) $close->vendor_id;

        if ($user->isSuperAdmin() || $user->ownedVendors()->where('id', $vendorId)->exists()) {
            return;
        }

        if (! $user->vendors()->contains(fn ($vendor) => (int) $vendor->id === $vendorId)) {
            throw new AccessDeniedHttpException('That close belongs to another business.');
        }

        if (! $user->hasVendorPermission($vendorId, 'view_store_settlement')) {
            throw new AccessDeniedHttpException('You are not permitted to read settlement documents.');
        }
    }
}

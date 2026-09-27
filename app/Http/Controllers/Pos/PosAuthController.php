<?php

namespace App\Http\Controllers\Pos;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\ActiveStore;
use App\Services\Inventory\TillStore;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class PosAuthController extends Controller
{
    public function login(Request $request): JsonResponse
    {
        $request->validate([
            'vendor_id' => 'required|integer',
            'pin'       => 'required|digits:4',
            'store_id'  => 'nullable|integer',
        ]);

        // Find users who belong to this vendor and have a POS PIN
        $user = User::whereNotNull('pos_pin')
            ->whereHas('memberVendors', fn ($q) => $q->where('vendors.id', $request->vendor_id))
            ->orWhereHas('ownedVendors', fn ($q) => $q->where('vendors.id', $request->vendor_id))
            ->whereNotNull('pos_pin')
            ->get()
            ->first(fn ($u) => Hash::check($request->pin, $u->pos_pin));

        if (! $user) {
            return response()->json(['message' => 'Invalid PIN.'], 401);
        }

        $vendor = \App\Models\Vendor::find($request->vendor_id);

        // Blocked over payment or terms: no token at all, so a cashier cannot
        // ring up a sale the platform has already told the owner it will not
        // honour. The reason is returned verbatim — the person at the till
        // needs to know it is the account and not their PIN.
        if ($vendor?->isDashboardBlocked() && ! $user->isSuperAdmin()) {
            return response()->json([
                'message' => $vendor->dashboardBlockMessage(),
                'blocked' => true,
            ], 403);
        }

        // The branch this till is standing in, settled before a token exists.
        // It is written onto the token, so every request the till makes — and
        // every sale it rings — belongs to that branch without the server
        // having to guess. See TillStore for what guessing once cost.
        $stores = ActiveStore::accessibleFor($vendor, $user);

        if ($stores->isEmpty()) {
            return response()->json([
                'message' => 'You are not assigned to any branch of this business. Ask a manager to assign you to your branch.',
                'code'    => 'no_store',
            ], 422);
        }

        $store = $request->filled('store_id')
            ? $stores->firstWhere('id', (int) $request->store_id)
            : ($stores->count() === 1 ? $stores->first() : null);

        if (! $store) {
            // Either they named a branch they do not work in, or they work in
            // several and have not said which. Both are answered with the list
            // they may choose from; the till shows it and asks again.
            return response()->json([
                'message' => $request->filled('store_id')
                    ? 'You are not assigned to that branch.'
                    : 'Choose the branch you are in.',
                'code'    => 'choose_store',
                'stores'  => $stores->map(fn ($s) => ['id' => $s->id, 'name' => $s->name])->values(),
            ], 422);
        }

        $issued = $user->createToken('pos-terminal', ['pos', TillStore::ability($store->id)]);

        // So TillProfile below resolves the branch from this token, exactly as
        // every later request will.
        $user->withAccessToken($issued->accessToken);

        return response()->json([
            'token'  => $issued->plainTextToken,
            'user'   => [
                'id'   => $user->id,
                'name' => $user->name,
            ],
            'store'  => [
                'id'   => $store->id,
                'name' => $store->name,
            ],
            // Everything the till needs to print a receipt without asking
            // the server for one. See TillProfile — it is sent again when a
            // session is opened, so a layout change does not wait for a logout.
            'vendor' => \App\Support\Pos\TillProfile::for($user, $vendor),
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json(['message' => 'Logged out.']);
    }
}

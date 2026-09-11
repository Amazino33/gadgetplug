<?php

namespace App\Http\Controllers\Pos;

use App\Http\Controllers\Controller;
use App\Models\User;
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

        $token  = $user->createToken('pos-terminal', ['pos'])->plainTextToken;
        $vendor = \App\Models\Vendor::find($request->vendor_id);

        return response()->json([
            'token'  => $token,
            'user'   => [
                'id'   => $user->id,
                'name' => $user->name,
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

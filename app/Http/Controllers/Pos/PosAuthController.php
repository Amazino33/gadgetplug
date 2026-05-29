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
            'pin'       => 'required|string|min:4|max:6',
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
            'vendor' => [
                'vat_enabled' => (bool) ($vendor->pos_vat_enabled ?? true),
                'vat_rate'    => (float) ($vendor->pos_vat_rate ?? 7.5),
            ],
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json(['message' => 'Logged out.']);
    }
}

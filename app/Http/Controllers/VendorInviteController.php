<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\Vendor;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;

class VendorInviteController extends Controller
{
    // Show accept page
    public function accept(string $token)
    {
        $invite = cache()->get("vendor_invite_{$token}");

        if (!$invite) {
            abort(404, 'Invite link is invalid or has expired.');
        }

        $userExists = User::where('email', $invite['email'])->exists();

        return view('vendor-invite.accept', [
            'token'      => $token,
            'email'      => $invite['email'],
            'vendorId'   => $invite['vendor_id'],
            'userExists' => $userExists,
        ]);
    }

    // Handle form submission
    public function store(Request $request, string $token)
    {
        $invite = cache()->get("vendor_invite_{$token}");

        if (!$invite) {
            abort(404, 'Invite link is invalid or has expired.');
        }

        $vendor = Vendor::findOrFail($invite['vendor_id']);
        $user = User::where('email', $invite['email'])->first();

        if (!$user) {
            // New user — validate and create
            $request->validate([
                'name'     => 'required|string|max:255',
                'password' => 'required|min:8|confirmed',
            ]);

            $user = User::create([
                'name'     => $request->name,
                'email'    => $invite['email'],
                'password' => Hash::make($request->password),
            ]);
        }

        // Attach to vendor
        if (!$vendor->users()->where('user_id', $user->id)->exists()) {
            $vendor->users()->attach($user->id, ['role' => 'member']);
        }

        // Assign permissions scoped to this vendor
        setPermissionsTeamId($vendor->id);
        $user->givePermissionTo($invite['permissions']);

        // Clear the invite
        cache()->forget("vendor_invite_{$token}");

        // Log them in
        Auth::login($user);

        return redirect()->route('filament.vendor.home', ['tenant' => $vendor]);
    }
}
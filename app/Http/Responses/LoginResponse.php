<?php

namespace App\Http\Responses;

use App\Filament\Pages\Dashboard;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Http\Request;
use Laravel\Fortify\Contracts\LoginResponse as LoginResponseContract;
use Symfony\Component\HttpFoundation\Response;

class LoginResponse implements LoginResponseContract
{
    /**
     * Create an HTTP response that represents the object.
     *
     * @param  Request  $request
     * @return Response
     */
    public function toResponse($request)
    {
        $url = self::resolveDashboardUrl($request->user());

        return $request->wantsJson()
            ? response()->json(['two_factor' => false])
            : redirect()->intended($url);
    }

    /**
     * Resolves the correct dashboard URL based on the user's roles.
     */
    public static function resolveDashboardUrl(User $user): string
    {
        if ($user->isSuperAdmin()) {
            return Filament::getPanel('admin')->getUrl();
        }

        // Single-job admin staff (catalogue editor, delivery settings).
        if ($home = Dashboard::homeFor($user)) {
            return $home;
        }

        if ($user->vendors()->isNotEmpty()) {
            return Filament::getPanel('vendor')->getUrl();
        }

        // Customer
        return route('home');
    }
}

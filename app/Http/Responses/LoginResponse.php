<?php

namespace App\Http\Responses;

use App\Models\User;
use Filament\Facades\Filament;
use Laravel\Fortify\Contracts\LoginResponse as LoginResponseContract;

class LoginResponse implements LoginResponseContract
{
    /**
     * Create an HTTP response that represents the object.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Symfony\Component\HttpFoundation\Response
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
     *
     * @param User $user
     * @return string
     */
    public static function resolveDashboardUrl(User $user): string
    {
        if ($user->isSuperAdmin()) {
            return Filament::getPanel('admin')->getUrl();
        }

        if ($user->vendors()->isNotEmpty()) {
            return Filament::getPanel('vendor')->getUrl();
        }

        // Customer
        return route('home');
    }
}

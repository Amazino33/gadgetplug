<?php

namespace App\Http\Controllers;

use App\Concerns\PasswordValidationRules;
use App\Http\Responses\LoginResponse;
use App\Models\Vendor;
use App\Services\FreePlan\FreeShopApplications;
use App\Services\FreePlan\FreeShopForm;
use App\Support\NigerianStates;
use App\Support\ShopSlug;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * /become-a-vendor — a small business signs itself up for a free shop.
 *
 * The shop is created at once (plan free, status pending), the owner is
 * signed in and lands in their panel, where they can start adding products
 * while the admin reviews the application.
 */
class FreeShopSignupController extends Controller
{
    use PasswordValidationRules;

    public function create(Request $request): View|RedirectResponse
    {
        $user = $request->user();

        // Already has a shop: nothing to sign up for.
        if ($user && ($user->isSuperAdmin() || $user->vendors()->isNotEmpty())) {
            return redirect(LoginResponse::resolveDashboardUrl($user));
        }

        return view('pages.free-shop.signup', [
            'mode'       => 'create',
            'vendor'     => null,
            'categories' => Vendor::BUSINESS_CATEGORIES,
            'states'     => NigerianStates::ALL,
            'prefix'     => Vendor::shopLinkPrefix(),
            'signedInAs' => $user,
        ]);
    }

    public function store(Request $request, FreeShopApplications $applications): RedirectResponse
    {
        // Honeypot: a field no person can see. A bot that fills it gets the
        // form back with nothing created and no hint as to why.
        if (filled($request->input(FreeShopForm::HONEYPOT))) {
            return redirect()->route('free-shop.create');
        }

        if ($request->user()) {
            return redirect()->route('free-shop.create');
        }

        $input = FreeShopForm::prepare($request->all());

        $validated = Validator::make($input, [
            ...FreeShopForm::rules(),
            'owner_name' => ['required', 'string', 'max:100'],
            'email'      => ['nullable', 'email', 'max:255', Rule::unique('users', 'email')],
            'password'   => $this->passwordRules(),
            'terms'      => ['accepted'],
        ], FreeShopForm::messages(), FreeShopForm::attributes())->validate();

        ['user' => $user] = $applications->register(
            $validated,
            $request->file('logo'),
            $request->file('verification_photo'),
        );

        Auth::login($user);
        $request->session()->regenerate();

        return redirect(LoginResponse::resolveDashboardUrl($user));
    }

    /** The live "available ✓ / taken ✗" check under the shop link field. */
    public function checkSlug(Request $request): JsonResponse
    {
        $slug = strtolower(trim((string) $request->query('slug')));

        // A shop editing its own application keeps its current link.
        $ignore = null;
        if ($user = $request->user()) {
            $own = $user->ownedVendors()->where('plan', Vendor::PLAN_FREE)->first();
            $ignore = $own?->canEditApplication() ? $own->id : null;
        }

        $problem = ShopSlug::problem($slug, $ignore);

        return response()->json([
            'slug'      => $slug,
            'available' => $problem === null,
            'message'   => $problem ?? 'Available',
        ]);
    }
}

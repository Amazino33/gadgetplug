<?php

namespace App\Http\Controllers;

use App\Models\Vendor;
use App\Services\FreePlan\FreeShopApplications;
use App\Services\FreePlan\FreeShopForm;
use App\Support\NigerianStates;
use App\Support\WhatsAppNumber;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\View\View;

/**
 * The owner's edit of their own free-shop application: answering a "more
 * info" request, or fixing a rejected one and resubmitting it. The same
 * fields as signup, prefilled, without the password.
 *
 * Outside the panel on purpose — a rejected shop is kept out of the panel
 * entirely, and this is the one page it is sent to.
 */
class FreeShopApplicationController extends Controller
{
    public function edit(Request $request): View|RedirectResponse
    {
        $vendor = $this->ownShop($request);

        if (! $vendor) {
            return redirect()->route('home');
        }

        if (! $vendor->canEditApplication()) {
            return redirect()->to(route('filament.vendor.pages.dashboard', ['tenant' => $vendor]));
        }

        return view('pages.free-shop.signup', [
            'mode'       => 'edit',
            'vendor'     => $vendor,
            'categories' => Vendor::BUSINESS_CATEGORIES,
            'states'     => NigerianStates::ALL,
            'prefix'     => Vendor::shopLinkPrefix(),
            'signedInAs' => null,
            'prefill'    => [
                'business_name' => $vendor->name,
                'owner_name'    => $request->user()->name,
                'whatsapp'      => WhatsAppNumber::local($vendor->whatsapp),
                'category'      => $vendor->category,
                'state'         => $vendor->state,
                'city_area'     => $vendor->city,
                'slug'          => $vendor->slug,
                'description'   => $vendor->description,
                'social_link'   => $vendor->social_link,
            ],
        ]);
    }

    public function update(Request $request, FreeShopApplications $applications): RedirectResponse
    {
        $vendor = $this->ownShop($request);

        abort_unless($vendor && $vendor->canEditApplication(), 403);

        $validated = Validator::make(FreeShopForm::prepare($request->all()), [
            ...FreeShopForm::rules($vendor),
            'owner_name' => ['required', 'string', 'max:100'],
        ], FreeShopForm::messages(), FreeShopForm::attributes())->validate();

        $user = $request->user();

        $saved = $applications->updateApplication(
            $vendor,
            $validated,
            $request->file('logo'),
            $request->file('verification_photo'),
            $user,
        );

        if (! $saved) {
            // Reviewed in the meantime (approved, or the request withdrawn).
            return redirect()->to(route('filament.vendor.pages.dashboard', ['tenant' => $vendor]));
        }

        if ($user->name !== $validated['owner_name']) {
            $user->forceFill(['name' => $validated['owner_name']])->save();
        }

        return redirect()
            ->to(route('filament.vendor.pages.dashboard', ['tenant' => $vendor]))
            ->with('status', 'Thanks — your application has been sent for review.');
    }

    /** The free shop this person owns. A free shop has exactly one owner and one shop. */
    private function ownShop(Request $request): ?Vendor
    {
        return $request->user()?->ownedVendors()->where('plan', Vendor::PLAN_FREE)->first();
    }
}

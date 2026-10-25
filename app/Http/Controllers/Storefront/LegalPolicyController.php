<?php

namespace App\Http\Controllers\Storefront;

use App\Http\Controllers\Controller;
use App\Models\LegalPolicy;
use Illuminate\View\View;

class LegalPolicyController extends Controller
{
    public function show(string $slug): View
    {
        $policy = LegalPolicy::published()->where('slug', $slug)->firstOrFail();
        $allPolicies = LegalPolicy::published()->orderBy('sort_order')->get(['id', 'title', 'slug', 'summary']);

        return view('pages.legal-policy', [
            'policy'      => $policy,
            'allPolicies' => $allPolicies,
        ]);
    }

    public function privacy(): View
    {
        return $this->show('privacy-policy');
    }

    public function terms(): View
    {
        return $this->show('terms-of-service');
    }

    public function affiliateTerms(): View
    {
        return $this->show('affiliate-terms');
    }

    public function refundPolicy(): View
    {
        return $this->show('refund-policy');
    }
}

<?php

namespace App\Http\Controllers\Storefront;

use App\Http\Controllers\Controller;
use App\Models\LegalPolicy;
use Database\Seeders\LegalPolicySeeder;
use Illuminate\View\View;

class LegalPolicyController extends Controller
{
    public function show(string $slug): View
    {
        $policy = LegalPolicy::published()->where('slug', $slug)->first();

        // Self-heal: if policy not found or table is empty, seed default policies on the fly
        if (! $policy) {
            (new LegalPolicySeeder())->run();
            $policy = LegalPolicy::published()->where('slug', $slug)->first();
        }

        abort_if(! $policy, 404);

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

<?php

namespace App\Services\Affiliate;

use App\Models\Affiliate;
use App\Models\AffiliateClick;
use App\Models\AffiliateSetting;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * The first tier of the two-tier affiliate payout.
 *
 * Tier 1 (here): a referred visitor who lands *and then goes somewhere else*
 * earns the affiliate a small fixed amount. The second page load is the whole
 * point — one pageview only proves the link opened, which a bot, a bounce, or
 * a misclick all produce. Two proves a person looked at the page and chose
 * something on it. Tier 2 is unchanged: CommissionService still pays the full
 * percentage when that visitor actually buys, and the two stack.
 *
 * Credits go through the same primitive AffiliateTaskService and
 * ClearAffiliateHoldsJob use — a relation-scoped ->create(['type' => 'credit'])
 * on WalletTransaction — never a second money path. Unlike a sale commission
 * there is no return window to hold against: nothing about an engaged visit can
 * be reversed later, so the credit is immediately available.
 */
class ClickRewardService
{
    /** Points at the click row the current session is browsing under. */
    public const SESSION_KEY = 'gp_ref_click';

    /** Pageviews needed before a click pays — the "second click" rule. */
    public const QUALIFYING_PAGE_VIEWS = 2;

    /**
     * Obvious non-humans. Deliberately short: this catches the crawlers and
     * link-preview fetchers that would otherwise bank a reward for a WhatsApp
     * share, not every possible bot. The per-IP cap is the real backstop.
     */
    private const BOT_SIGNATURES = [
        'bot', 'crawl', 'spider', 'slurp', 'facebookexternalhit', 'whatsapp',
        'telegram', 'preview', 'headless', 'python-requests', 'curl', 'wget',
        'lighthouse', 'pingdom', 'monitor',
    ];

    public function recordLanding(Affiliate $affiliate, Request $request): AffiliateClick
    {
        $click = $affiliate->clicks()->create([
            'ip_address'  => $request->ip(),
            'user_agent'  => $request->userAgent(),
            'landing_url' => $request->query('to'),
            'session_id'  => $request->hasSession() ? $request->session()->getId() : null,
            // Zero, not one: the redirect target this click is about to send
            // them to is itself counted by the middleware, as pageview 1.
            'page_views'  => 0,
        ]);

        if ($request->hasSession()) {
            $request->session()->put(self::SESSION_KEY, $click->id);
        }

        return $click;
    }

    /**
     * Called once per successful storefront page load. Counts the view against
     * whichever click the session is browsing under and resolves that click the
     * moment it hits the qualifying threshold.
     */
    public function registerPageView(Request $request): void
    {
        if (! $request->hasSession()) {
            return;
        }

        $clickId = $request->session()->get(self::SESSION_KEY);

        if (! $clickId) {
            return;
        }

        $click = AffiliateClick::find($clickId);

        // Row is gone, or somehow already resolved — stop tracking so we don't
        // write to the DB on every remaining pageview of this session.
        if (! $click || $click->qualified_at) {
            $request->session()->forget(self::SESSION_KEY);

            return;
        }

        $click->increment('page_views');

        if ($click->page_views < self::QUALIFYING_PAGE_VIEWS) {
            return;
        }

        $this->resolve($click, $request);

        // Resolved either way — paid or capped. The pointer has done its job.
        $request->session()->forget(self::SESSION_KEY);
    }

    /**
     * Stamps the click as resolved and credits whatever it earned. Always
     * stamps, even when the payable amount is zero, so a capped or disallowed
     * click is settled once rather than retried on every later request.
     */
    public function resolve(AffiliateClick $click, Request $request): void
    {
        DB::transaction(function () use ($click, $request) {
            // Re-read under a lock: two near-simultaneous pageviews (a page and
            // its prefetch, say) could both pass the threshold check above
            // before either write lands, and this reward must be paid once.
            $locked = AffiliateClick::where('id', $click->id)
                ->whereNull('qualified_at')
                ->lockForUpdate()
                ->first();

            if (! $locked) {
                return;
            }

            $amount = $this->payableAmount($locked, $request);

            $locked->update([
                'qualified_at'  => now(),
                'reward_amount' => $amount,
            ]);

            if ($amount <= 0) {
                return;
            }

            $locked->walletTransactions()->create([
                'affiliate_id' => $locked->affiliate_id,
                'type'         => 'credit',
                'amount'       => $amount,
                'description'  => "Engaged visit reward — referral click #{$locked->id}.",
            ]);
        });
    }

    /**
     * What this engaged click is actually worth right now, after every guard.
     * Returns 0.00 rather than throwing — a click that earns nothing is a
     * normal outcome, not an error.
     */
    public function payableAmount(AffiliateClick $click, Request $request): float
    {
        $settings = AffiliateSetting::current();

        if (! $settings->click_rewards_enabled) {
            return 0.0;
        }

        $amount = (float) $settings->click_reward_amount;

        if ($amount <= 0) {
            return 0.0;
        }

        $affiliate = $click->affiliate;

        if (! $affiliate || ! $affiliate->is_active) {
            return 0.0;
        }

        // An affiliate browsing under their own link is not traffic they
        // brought — same principle as AttributionService::isSelfReferral().
        if (auth()->check() && auth()->id() === $affiliate->user_id) {
            return 0.0;
        }

        if ($this->looksLikeBot($click->user_agent ?: $request->userAgent())) {
            return 0.0;
        }

        if ($this->ipLimitReached($click, (int) $settings->click_reward_daily_ip_limit)) {
            return 0.0;
        }

        // Pay the remainder when the day's cap lands mid-click rather than
        // overshooting it — the cap is a spend ceiling, not a rounding target.
        $remaining = (float) $settings->click_reward_daily_cap - $this->paidToday($click->affiliate_id);

        if ($remaining <= 0) {
            return 0.0;
        }

        return round(min($amount, $remaining), 2);
    }

    public function paidToday(int $affiliateId): float
    {
        return (float) AffiliateClick::where('affiliate_id', $affiliateId)
            ->whereDate('qualified_at', today())
            ->sum('reward_amount');
    }

    private function ipLimitReached(AffiliateClick $click, int $limit): bool
    {
        if (blank($click->ip_address)) {
            return false;
        }

        $rewardedFromThisIp = AffiliateClick::where('affiliate_id', $click->affiliate_id)
            ->where('ip_address', $click->ip_address)
            ->where('reward_amount', '>', 0)
            ->whereDate('qualified_at', today())
            ->count();

        return $rewardedFromThisIp >= $limit;
    }

    private function looksLikeBot(?string $userAgent): bool
    {
        if (blank($userAgent)) {
            return true;
        }

        $userAgent = strtolower($userAgent);

        foreach (self::BOT_SIGNATURES as $signature) {
            if (str_contains($userAgent, $signature)) {
                return true;
            }
        }

        return false;
    }
}

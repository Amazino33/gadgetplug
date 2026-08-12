<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\AffiliateCommission;
use App\Models\AffiliateSetting;
use App\Models\Order;
use App\Models\WalletTransaction;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

// Read-only. "An affiliate's link made this sale but their balance never moved"
// has half a dozen possible causes spread across attribution, the order's own
// status, the hold, and the queue worker — and from the outside they all look
// identical. This walks that whole chain for one order and says which link
// actually broke, instead of leaving it to be guessed at.
class DiagnoseAffiliateOrderCommand extends Command
{
    protected $signature = 'affiliate:diagnose {order : Order reference (e.g. GP-A1B2C3D4E5) or numeric id}';

    protected $description = 'Trace one order through affiliate attribution, the hold, and the wallet, and report where it stopped';

    public function handle(): int
    {
        $needle = (string) $this->argument('order');

        $order = ctype_digit($needle)
            ? Order::find((int) $needle)
            : Order::where('reference', $needle)->first();

        if (! $order) {
            $this->error("No order matching '{$needle}'.");

            return self::FAILURE;
        }

        $this->line("<options=bold>Order {$order->reference}</>  ·  ₦".number_format((float) $order->total_amount, 2));
        $this->line("  status: {$order->status}   ·   payment: {$order->payment_method}   ·   placed: {$order->created_at}");
        $this->newLine();

        $commission = AffiliateCommission::with('affiliate.user', 'items')
            ->where('order_id', $order->id)
            ->first();

        if (! $commission) {
            return $this->reportNoCommission($order);
        }

        return $this->reportCommission($order, $commission);
    }

    // No commission row at all means attribution never happened — the order was
    // never linked to an affiliate in the first place, so nothing downstream
    // could have paid out. These are the only four ways that occurs.
    private function reportNoCommission(Order $order): int
    {
        $this->error('No commission was ever created for this order — attribution did not happen.');
        $this->newLine();
        $this->line('  Reasons, most likely first:');
        $this->line('  1. The customer did not arrive through /r/{code}, or lost the cookie before checking out');
        $this->line('     (different browser, incognito closed, cleared cookies, or more than the cookie window ago),');
        $this->line('     and did not type the code into the referral box at checkout.');
        $this->line("     Cookie window is currently ".AffiliateSetting::current()->cookie_window_days.' days.');
        $this->line('  2. A later affiliate link overwrote the cookie — attribution is last-click, not first-click.');
        $this->line('  3. The affiliate is deactivated (is_active = false); an inactive code attributes nothing.');
        $this->line('  4. Self-referral — the affiliate\'s own account or email placed the order. Checked below.');
        $this->newLine();

        $selfReferrers = DB::table('affiliates')
            ->join('users', 'users.id', '=', 'affiliates.user_id')
            ->where(fn ($q) => $q->where('users.email', $order->customer_email)
                ->when($order->user_id, fn ($q) => $q->orWhere('affiliates.user_id', $order->user_id)))
            ->select('affiliates.code', 'users.email')
            ->get();

        if ($selfReferrers->isNotEmpty()) {
            foreach ($selfReferrers as $row) {
                $this->warn("  → Self-referral: {$order->customer_email} owns affiliate {$row->code}.");
                $this->line('     A commission on your own purchase is refused by design.');
            }
        } else {
            $this->line("  Not a self-referral — {$order->customer_email} owns no affiliate account.");
        }

        $this->newLine();
        $this->line('  Check the attribution log for this order:');
        $this->line("    grep 'Affiliate attribution failed for order {$order->id}' storage/logs/laravel.log");

        return self::SUCCESS;
    }

    private function reportCommission(Order $order, AffiliateCommission $commission): int
    {
        $affiliate = $commission->affiliate;
        $settings = AffiliateSetting::current();

        $this->info("Commission #{$commission->id} exists — attribution worked.");
        $this->line("  affiliate: {$affiliate->code} ({$affiliate->user?->email})   ·   active: ".($affiliate->is_active ? 'yes' : 'NO'));
        $this->line('  amount: ₦'.number_format((float) $commission->amount, 2)."   ·   status: {$commission->status}");
        $this->newLine();

        if ((float) $commission->amount <= 0) {
            $this->warn('  The commission is worth ₦0.00 — it exists but can never pay anything.');
            $this->line('  Per-line breakdown:');

            foreach ($commission->items as $line) {
                $this->line("    line #{$line->order_item_id}: rate {$line->rate}%  on base ₦"
                    .number_format((float) $line->base_amount, 2).'  →  ₦'.number_format((float) $line->amount, 2));
            }

            $this->newLine();
            $this->line('  A zero line is usually one of:');
            $this->line('   · the product/category rate resolves to 0%, or');
            $this->line("   · the affiliate has a level, and the margin cap ({$settings->margin_cap_fraction} of margin) clamped it —");
            $this->line('     if selling price barely exceeds cost price, the cap is near zero no matter the rate.');
            $this->newLine();
        }

        match ($commission->status) {
            'pending'       => $this->explainPending($order),
            'return_window' => $this->explainReturnWindow($commission, (int) $settings->return_window_days),
            'available'     => $this->explainAvailable($commission),
            'rejected'      => $this->explainRejected($commission),
            default         => $this->warn("  Unrecognized status '{$commission->status}'."),
        };

        $this->newLine();
        $balance = (float) WalletTransaction::where('affiliate_id', $affiliate->id)->sum('amount');
        $held = (float) AffiliateCommission::where('affiliate_id', $affiliate->id)
            ->whereIn('status', ['pending', 'return_window'])->sum('amount');

        $this->line('<options=bold>Affiliate totals</>');
        $this->line('  spendable wallet balance: ₦'.number_format($balance, 2));
        $this->line('  still on hold (not yet funds): ₦'.number_format($held, 2));

        return self::SUCCESS;
    }

    private function explainPending(Order $order): void
    {
        $this->warn('  → Still PENDING. The referral is fine; the hold has not started.');
        $this->line("     The hold starts only when the order becomes 'delivered'.");
        $this->line("     This order is currently '{$order->status}'.");
        $this->line('     Mark the order delivered and the clock starts.');
    }

    private function explainReturnWindow(AffiliateCommission $commission, int $windowDays): void
    {
        $started = $commission->return_window_started_at;
        $clearsAt = $started?->copy()->addDays($windowDays);

        $this->warn('  → In the RETURN WINDOW. Earned, but deliberately not spendable yet.');
        $this->line("     started: {$started}   ·   window: {$windowDays} days   ·   clears: {$clearsAt}");

        if ($clearsAt && $clearsAt->isFuture()) {
            $this->line('     Nothing is broken — it clears '.$clearsAt->diffForHumans().'.');

            return;
        }

        $this->newLine();
        $this->error('     OVERDUE — this should have cleared by now.');
        $this->line('     ClearAffiliateHoldsJob has not run.');
        $this->line('     It is queued hourly, so it needs BOTH of these alive on the server:');
        $this->line('       · a cron entry running:  php artisan schedule:run   (every minute)');
        $this->line('       · a queue worker running: php artisan queue:work    (QUEUE_CONNECTION is '.config('queue.default').')');
        $this->newLine();

        $pendingJobs = $this->queuedJobCount();

        if ($pendingJobs !== null) {
            $this->line("     Jobs currently waiting in the queue table: {$pendingJobs}");
            $this->line($pendingJobs > 0
                ? '     A backlog means the scheduler IS firing but no worker is consuming it.'
                : '     An empty queue with an overdue hold means the scheduler itself is not firing.');
        }

        $this->newLine();
        $this->line('     To settle everything due right now, without waiting for the schedule:');
        $this->line('       php artisan queue:work --once --stop-when-empty');
        $this->line('     or run the clearing directly:');
        $this->line('       php artisan tinker --execute="(new \\App\\Jobs\\ClearAffiliateHoldsJob)->handle();"');
    }

    private function explainAvailable(AffiliateCommission $commission): void
    {
        $credits = $commission->walletTransactions()->get();

        $this->info('  → AVAILABLE — the hold cleared and the wallet was credited.');
        $this->line("     cleared at: {$commission->available_at}");

        foreach ($credits as $tx) {
            $this->line("     ledger: {$tx->type}  ₦".number_format((float) $tx->amount, 2)."  ({$tx->created_at})");
        }

        if ($credits->isEmpty()) {
            $this->error('     But there is NO wallet transaction for it.');
            $this->line('     Status and ledger disagree — escalate this one.');
        }
    }

    private function explainRejected(AffiliateCommission $commission): void
    {
        $this->error('  → REJECTED. This commission will never pay.');
        $this->line("     reason: {$commission->rejected_reason}   ·   at: {$commission->rejected_at}");
        $this->line("     'order_cancelled' means the order was cancelled after the referral — rejection is by design.");
    }

    // Null when the queue isn't database-backed, since there is then no table to
    // count and a number here would be a guess.
    private function queuedJobCount(): ?int
    {
        if (config('queue.default') !== 'database') {
            return null;
        }

        try {
            return (int) DB::table('jobs')->count();
        } catch (\Throwable) {
            return null;
        }
    }
}

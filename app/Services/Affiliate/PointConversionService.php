<?php

namespace App\Services\Affiliate;

use App\Models\Affiliate;
use App\Models\AffiliatePointConversion;
use App\Models\AffiliateSetting;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * The ONLY bridge between the two economies.
 *
 * A conversion is one atomic unit: a Points debit, a conversion record
 * freezing the rate, and a wallet credit written through the existing wallet
 * primitive — a relation-scoped ->create(['type' => 'credit', ...]) on
 * WalletTransaction, exactly as AffiliateTaskService and ClearAffiliateHoldsJob
 * already do. There is no second money path, and cash never enters the system
 * anywhere else in this feature.
 */
class PointConversionService
{
    public function __construct(private PlugPointService $points) {}

    /**
     * Converts `$points` into wallet cash for this affiliate.
     *
     * `$idempotencyKey` makes a double-submitted action safe: the second
     * attempt collides on the unique (affiliate_id, idempotency_key) index and
     * returns the original conversion rather than spending the points twice.
     *
     * @throws RuntimeException when below the configured minimum, or when the
     *                          balance cannot cover the request.
     */
    public function convert(Affiliate $affiliate, int $points, string $idempotencyKey): AffiliatePointConversion
    {
        $settings = AffiliateSetting::current();
        $minimum  = (int) $settings->min_points_conversion;

        if ($points <= 0) {
            throw new RuntimeException('Enter how many points you want to convert.');
        }

        if ($points < $minimum) {
            throw new RuntimeException("You need at least {$minimum} points to convert.");
        }

        // Fast path out before opening a transaction — an existing key means
        // this exact intent already succeeded.
        $existing = AffiliatePointConversion::where('affiliate_id', $affiliate->id)
            ->where('idempotency_key', $idempotencyKey)
            ->first();

        if ($existing) {
            return $existing;
        }

        try {
            return DB::transaction(function () use ($affiliate, $points, $idempotencyKey, $settings) {
                // Row lock as a mutex over this affiliate's points balance:
                // two convert clicks landing together must not both read the
                // same balance and both pass the overdraw check.
                $locked = Affiliate::where('id', $affiliate->id)->lockForUpdate()->first();

                $balance = $this->points->balance($locked->id);

                if ($points > $balance) {
                    throw new RuntimeException("You only have {$balance} points available.");
                }

                $rate   = (float) $settings->naira_per_point;
                $amount = round($points * $rate, 2);

                $conversion = AffiliatePointConversion::create([
                    'affiliate_id'    => $locked->id,
                    'points_spent'    => $points,
                    'naira_per_point' => $rate,
                    'amount'          => $amount,
                    'idempotency_key' => $idempotencyKey,
                ]);

                // Points leave the points ledger...
                $locked->plugPointTransactions()->create([
                    'type'        => 'debit',
                    'points'      => -$points,
                    'source'      => 'conversion',
                    'description' => "Converted {$points} points to ₦" . number_format($amount, 2) . " (conversion #{$conversion->id}).",
                ]);

                // ...and arrive as cash through the existing wallet primitive.
                // Straight to available: these points were already earned and
                // settled, so there is nothing left to hold against.
                $locked->walletTransactions()->create([
                    'type'        => 'credit',
                    'amount'      => $amount,
                    'description' => "Plug Points conversion — {$points} points (conversion #{$conversion->id}).",
                ]);

                return $conversion;
            });
        } catch (QueryException $e) {
            // Lost the race on the unique index: the other attempt committed
            // the conversion, so return that rather than surfacing a clash.
            $winner = AffiliatePointConversion::where('affiliate_id', $affiliate->id)
                ->where('idempotency_key', $idempotencyKey)
                ->first();

            if ($winner) {
                return $winner;
            }

            throw $e;
        }
    }

    /**
     * What `$points` would be worth right now — used by the UI to show the
     * amount before the affiliate commits. Reads the live rate, which is
     * exactly why the rate is frozen onto the conversion row at commit time.
     */
    public function quote(int $points): float
    {
        return round($points * (float) AffiliateSetting::current()->naira_per_point, 2);
    }

    public function canConvert(int $affiliateId): bool
    {
        return $this->points->balance($affiliateId) >= (int) AffiliateSetting::current()->min_points_conversion;
    }
}

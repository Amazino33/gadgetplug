<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use LogicException;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Models\Activity;
use Spatie\Activitylog\Traits\LogsActivity;

class Order extends Model
{
    use LogsActivity;

    protected $guarded = [];

    // Transient, in-memory only — never persisted. Lets a Filament action opt an
    // update out of OrderObserver's automatic customer notification (the "notify
    // silently / skip" toggle) without affecting any other caller of ->update().
    public bool $skipCustomerNotification = false;

    protected static function booted(): void
    {
        // Only delivery_cost is guarded here — unlike a procurement, an order
        // is genuinely still editable after this (status changes, etc.), so
        // this can't be a blanket "no updates once posted" guard the way
        // ProcurementLogisticsLeg's is. Once posted_at is set, the recorded
        // cost is frozen; financial_account_id/posted_at themselves stay
        // writable since setting them together is the posting action itself.
        static::updating(function (self $order) {
            // getOriginal(), not the live attribute — the posting action itself
            // sets posted_at in the same update() call as financial_account_id
            // (never delivery_cost), but a guard reading the live value would
            // also wrongly block a hypothetical simultaneous first-time set of
            // delivery_cost + posted_at. Only a delivery_cost change on a row
            // that was ALREADY posted before this update began is refused.
            if ($order->getOriginal('posted_at') !== null && $order->isDirty('delivery_cost')) {
                throw new LogicException('Delivery cost cannot be changed after it has been posted to the ledger. Use a manual ledger correction instead.');
            }

            // Same getOriginal() reasoning — refuses a channel change on a
            // row already recognized before this update began, not the
            // simultaneous first-time set of payment_channel + revenue_recognized_at
            // that recognition itself performs.
            if ($order->getOriginal('revenue_recognized_at') !== null && $order->isDirty('payment_channel')) {
                throw new LogicException('Payment channel cannot be changed after revenue has been recognized. Use a manual ledger correction instead.');
            }
        });
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['status', 'payment_status', 'logistics_company_id', 'delivery_person_id', 'delivery_cost', 'payment_channel', 'revenue_recognized_at'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }

    public function tapActivity(Activity $activity, string $eventName): void
    {
        // Order has no direct vendor_id column — resolve it via its items instead
        // (this previously read $this->vendor_id, which never existed and always logged null).
        // Query fresh rather than through the `items` relation property: on Order::create(),
        // items don't exist yet, and caching that empty collection on $this would poison any
        // later activity logged on the same in-memory instance within the same request.
        $activity->vendor_id = $this->items()->value('vendor_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function logisticsCompany(): BelongsTo
    {
        return $this->belongsTo(LogisticsCompany::class);
    }

    public function deliveryPerson(): BelongsTo
    {
        return $this->belongsTo(DeliveryPerson::class);
    }

    public function financialAccount(): BelongsTo
    {
        return $this->belongsTo(FinancialAccount::class);
    }

    public function isDeliveryCostPosted(): bool
    {
        return $this->posted_at !== null;
    }

    public function isRevenueRecognized(): bool
    {
        return $this->revenue_recognized_at !== null;
    }

    public function deliveryMessages(): HasMany
    {
        return $this->hasMany(DeliveryMessage::class);
    }

    public function notes(): HasMany
    {
        // ->latest('id') rather than plain ->latest() (created_at) — two notes
        // added within the same second would otherwise tie and fall back to
        // insertion order instead of newest-first.
        return $this->hasMany(OrderNote::class)->latest('id');
    }

    // Overrides LogsActivity's default (unordered) activities() relation so the
    // "Activity History" infolist section shows the most recent change first.
    public function activities(): MorphMany
    {
        return $this->morphMany(Activity::class, 'subject')->latest('id');
    }

    public function affiliateCommission(): HasOne
    {
        return $this->hasOne(AffiliateCommission::class);
    }

    public function walletDebit(): HasOne
    {
        return $this->hasOne(WalletTransaction::class)->where('type', 'debit');
    }
}

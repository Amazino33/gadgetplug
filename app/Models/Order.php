<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphMany;
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

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['status', 'payment_status', 'logistics_company_id', 'delivery_person_id'])
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

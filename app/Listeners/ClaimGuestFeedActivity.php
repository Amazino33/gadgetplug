<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Http\Middleware\EnsureDeviceToken;
use App\Models\Product;
use App\Models\User;
use App\Services\Feed\ProductInteractions;
use Illuminate\Auth\Events\Login;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * When someone signs in, give them what they did as a guest.
 *
 * Two jobs, both of which exist so signing in never loses work:
 *
 *  - Likes made on this device are reassigned to the account. Without it, a
 *    visitor who liked a dozen posts and then logged in would watch every
 *    heart go grey.
 *
 *  - A save that was interrupted by the login gate is replayed. Tapping Save as
 *    a guest stashes the intent and sends them to log in; landing back on the
 *    feed with the product still unsaved would make the gate feel like a
 *    failure rather than a step.
 *
 * Never allowed to break a login. Somebody with the right password gets in
 * whatever happens here — a lost like is recoverable, a locked-out customer is
 * not.
 */
class ClaimGuestFeedActivity
{
    /** Where a guest's interrupted save waits while they sign in. */
    public const PENDING_SAVE = 'feed.pending_save';

    public function __construct(private readonly ProductInteractions $interactions)
    {
    }

    public function handle(Login $event): void
    {
        $user = $event->user;

        if (! $user instanceof User) {
            return;
        }

        try {
            $this->interactions->mergeDeviceInto($user, EnsureDeviceToken::current());
        } catch (Throwable $e) {
            Log::error('Feed like merge failed on login: '.$e->getMessage());
        }

        try {
            $this->replayPendingSave($user);
        } catch (Throwable $e) {
            Log::error('Feed save replay failed on login: '.$e->getMessage());
        }
    }

    private function replayPendingSave(User $user): void
    {
        $pending = session()->pull(self::PENDING_SAVE);

        if (! is_array($pending) || ($pending['action'] ?? null) !== 'save') {
            return;
        }

        $product = Product::find($pending['product_id'] ?? null);

        // Gone between the tap and the login. Nothing to save and nothing worth
        // telling them about.
        if (! $product) {
            return;
        }

        $this->interactions->save($product, $user);
    }
}

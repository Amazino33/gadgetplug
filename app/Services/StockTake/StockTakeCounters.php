<?php

namespace App\Services\StockTake;

use App\Models\StockTakeCounter;
use App\Models\User;
use App\Models\Vendor;
use App\Services\VendorRoles;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Counters: people who count stock but have no use for the site.
 *
 * A counter is a real user underneath, because tasks, counts, approvals and
 * the audit trail are all about a user. But the user has a made-up address
 * nobody can mail and a password nobody knows, a role that can only count,
 * and no way into the panels (User::canAccessPanel). They come in at the
 * shop's QR page with their name and a 6-digit code.
 *
 * The code is kept hashed and shown once, when it is made. Five wrong codes
 * lock that counter for five minutes.
 */
class StockTakeCounters
{
    public const ROLE = 'counter';

    public const MAX_ATTEMPTS = 5;
    public const LOCK_MINUTES = 5;

    /** The same words for a wrong name and a wrong code: nothing to learn by guessing. */
    public const WRONG = 'That name and code do not match. Check with your manager.';

    /**
     * Add a counter. Returns the counter and the code, which is not kept anywhere
     * and cannot be shown again.
     *
     * @param  array<int, int>  $storeIds  the branches they may count at
     * @return array{counter: StockTakeCounter, code: string}
     *
     * @throws AuthorizationException|ValidationException
     */
    public function add(User $by, Vendor $vendor, string $name, ?string $phone, array $storeIds): array
    {
        $this->mustManage($by, $vendor);

        $name = trim($name);
        if ($name === '') {
            throw ValidationException::withMessages(['name' => 'Give the counter a name.']);
        }

        if (StockTakeCounter::where('vendor_id', $vendor->id)->where('is_active', true)->whereRaw('lower(name) = ?', [mb_strtolower($name)])->exists()) {
            throw ValidationException::withMessages(['name' => 'Someone with that name is already a counter. Add a surname or initial so they can be told apart.']);
        }

        $stores = $vendor->stores()->whereIn('id', $storeIds ?: [$vendor->defaultStore?->id])->pluck('id')->all();
        if ($stores === []) {
            throw ValidationException::withMessages(['stores' => 'Pick at least one branch.']);
        }

        $code = $this->newCode();

        $counter = DB::transaction(function () use ($by, $vendor, $name, $phone, $stores, $code) {
            $user = User::create([
                'name'              => $name,
                // Not a real address: nothing can be sent to it, and nobody can sign in with it.
                'email'             => 'counter-' . Str::uuid() . '@counters.invalid',
                'password'          => Str::random(48),
                'email_verified_at' => now(),
            ]);

            $vendor->users()->syncWithoutDetaching([$user->id]);
            $user->stores()->syncWithoutDetaching($stores);

            VendorRoles::ensureCounterRole($vendor);
            $team = getPermissionsTeamId();
            setPermissionsTeamId($vendor->id);
            $user->assignRole(self::ROLE);
            setPermissionsTeamId($team);

            return StockTakeCounter::create([
                'vendor_id'  => $vendor->id,
                'user_id'    => $user->id,
                'name'       => $name,
                'phone'      => $phone ? trim($phone) : null,
                'code_hash'  => Hash::make($code),
                'created_by' => $by->id,
            ]);
        });

        return ['counter' => $counter, 'code' => $code];
    }

    /**
     * A new code: the old one stops working at once, and a lock is lifted.
     *
     * @return string the new code, shown once
     *
     * @throws AuthorizationException
     */
    public function resetCode(User $by, StockTakeCounter $counter): string
    {
        $this->mustManage($by, $counter->vendor);

        $code = $this->newCode();
        $counter->update(['code_hash' => Hash::make($code), 'failed_attempts' => 0, 'locked_until' => null]);
        $this->signOut($counter);

        return $code;
    }

    /** Turn a counter off (or on): off, they are signed out and cannot come back in. */
    public function setActive(User $by, StockTakeCounter $counter, bool $active): void
    {
        $this->mustManage($by, $counter->vendor);

        $counter->update(['is_active' => $active, 'failed_attempts' => 0, 'locked_until' => null]);

        if (! $active) {
            $this->signOut($counter);
        }
    }

    /**
     * Check a name and a code. Returns the user to sign in.
     *
     * @throws ValidationException
     */
    public function attempt(Vendor $vendor, int $counterId, string $code): User
    {
        $counter = StockTakeCounter::query()
            ->where('vendor_id', $vendor->id)
            ->where('is_active', true)
            ->with('user')
            ->find($counterId);

        if (! $counter || ! $counter->user) {
            throw ValidationException::withMessages(['code' => self::WRONG]);
        }

        if ($counter->isLocked()) {
            $minutes = max(1, (int) ceil(now()->diffInSeconds($counter->locked_until) / 60));

            throw ValidationException::withMessages([
                'code' => "Too many wrong codes. Try again in {$minutes} minute" . ($minutes === 1 ? '' : 's') . ', or ask your manager.',
            ]);
        }

        if (! Hash::check($code, $counter->code_hash)) {
            $failed = $counter->failed_attempts + 1;

            $counter->update($failed >= self::MAX_ATTEMPTS
                ? ['failed_attempts' => 0, 'locked_until' => now()->addMinutes(self::LOCK_MINUTES)]
                : ['failed_attempts' => $failed]);

            throw ValidationException::withMessages(['code' => self::WRONG]);
        }

        $counter->update(['failed_attempts' => 0, 'locked_until' => null, 'last_signed_in_at' => now()]);

        return $counter->user;
    }

    /** The secret in the shop's QR code, made the first time it is needed. */
    public function joinKey(Vendor $vendor): string
    {
        if (! $vendor->stock_take_join_key) {
            $vendor->forceFill(['stock_take_join_key' => Str::random(32)])->save();
        }

        return $vendor->stock_take_join_key;
    }

    /** A new QR code: the printed one stops working. */
    public function rotateJoinKey(User $by, Vendor $vendor): string
    {
        $this->mustManage($by, $vendor);
        $vendor->forceFill(['stock_take_join_key' => Str::random(32)])->save();

        return $vendor->stock_take_join_key;
    }

    public function joinUrl(Vendor $vendor): string
    {
        return route('stock-take.join', ['vendor' => $vendor->slug, 'k' => $this->joinKey($vendor)]);
    }

    /** Six digits, leading zeros kept ("004217"). */
    private function newCode(): string
    {
        return str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    }

    /** End every session this counter has open, so a new code or turning them off bites at once. */
    private function signOut(StockTakeCounter $counter): void
    {
        if (config('session.driver') === 'database') {
            DB::table(config('session.table', 'sessions'))->where('user_id', $counter->user_id)->delete();
        }
    }

    private function mustManage(User $by, Vendor $vendor): void
    {
        $storeId = $vendor->defaultStore?->id;

        if (! $storeId || ! StockTakeAccess::canManageCounters($by, (int) $vendor->id, (int) $storeId)) {
            throw new AuthorizationException('You cannot manage counters for this shop.');
        }
    }
}

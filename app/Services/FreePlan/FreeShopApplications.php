<?php

namespace App\Services\FreePlan;

use App\Mail\FreeShopMail;
use App\Models\FreePlanSetting;
use App\Models\User;
use App\Models\Vendor;
use App\Models\VendorStatusLog;
use App\Support\WhatsAppNumber;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Throwable;

/**
 * Everything that changes a free shop's review status, in one place.
 *
 * Each transition locks the vendor row, re-checks the status it is allowed
 * from (so a double-tap or two admins at once cannot approve twice or reject
 * an approved shop), writes the change and its vendor_status_logs row in one
 * transaction, and only then sends mail. Mail is best effort: a failure is
 * logged and never undoes the change or breaks the screen that asked for it.
 */
class FreeShopApplications
{
    /**
     * users.email cannot be null; a shop that gave no email gets
     * "{user id}@noemail.gadgetplug.invalid". `.invalid` is reserved (RFC 2606)
     * and can never resolve, and PlaceholderEmailGuard drops it from any
     * message before it is sent.
     */
    public const PLACEHOLDER_EMAIL_DOMAIN = 'noemail.gadgetplug.invalid';

    /**
     * The temporary password's alphabet. No look-alikes (0/O, 1/l/I) for a
     * password read off a phone, and none of * _ ~ ` which WhatsApp turns
     * into bold, italics and strikethrough.
     */
    private const TEMP_UPPER  = 'ABCDEFGHJKMNPQRSTUVWXYZ';
    private const TEMP_LOWER  = 'abcdefghjkmnpqrstuvwxyz';
    private const TEMP_DIGITS = '23456789';
    private const TEMP_SYMBOL = '@#%+=?!';

    public function __construct(private ShopImageStore $images) {}

    // ── Signup ────────────────────────────────────────────────────────────

    /**
     * @param  array{business_name: string, owner_name: string, whatsapp: string, email: ?string,
     *               password: string, category: string, state: string, city_area: string,
     *               slug: string, description: ?string, social_link: ?string}  $data
     * @return array{user: User, vendor: Vendor}
     */
    public function register(array $data, ?UploadedFile $logo, ?UploadedFile $photo): array
    {
        // Saved before the transaction (the disk is not transactional) and
        // removed again if the database half fails.
        $logoPath  = $logo ? $this->images->storeLogo($logo) : null;
        $photoPath = $photo ? $this->images->storeVerificationPhoto($photo) : null;

        try {
            [$user, $vendor] = DB::transaction(function () use ($data, $logoPath, $photoPath) {
                $hasEmail = filled($data['email'] ?? null);

                $user = User::create([
                    'name'     => $data['owner_name'],
                    // Without an email, a unique stand-in until the row has
                    // an id to name the placeholder after (set just below).
                    'email'    => $hasEmail
                        ? strtolower($data['email'])
                        : 'pending-'.Str::uuid().'@'.self::PLACEHOLDER_EMAIL_DOMAIN,
                    'password' => $data['password'],
                    'phone'    => $data['whatsapp'],
                ]);

                if (! $hasEmail) {
                    $user->forceFill(['email' => self::placeholderEmailFor($user)])->save();
                }

                // Vendor::created seeds the shop's roles, money accounts and
                // default store, exactly as for a vendor the admin creates.
                $vendor = Vendor::create([
                    'user_id'            => $user->id,
                    'name'               => $data['business_name'],
                    'slug'               => $data['slug'],
                    'whatsapp'           => $data['whatsapp'],
                    'category'           => $data['category'],
                    'state'              => $data['state'],
                    'city'               => $data['city_area'],
                    'description'        => $data['description'] ?? null,
                    'social_link'        => $data['social_link'] ?? null,
                    'logo'               => $logoPath,
                    'verification_photo' => $photoPath,
                    'plan'               => Vendor::PLAN_FREE,
                    'status'             => Vendor::STATUS_PENDING,
                    'applied_at'         => now(),
                ]);

                // Ownership is vendors.user_id; the membership row is what the
                // panel's tenant list reads. Same pair the old approval made.
                $vendor->users()->syncWithoutDetaching([$user->id]);

                $this->log($vendor, null, Vendor::STATUS_PENDING, 'Application submitted.', $user);

                return [$user, $vendor];
            });
        } catch (Throwable $e) {
            $this->images->delete($logoPath, ShopImageStore::PUBLIC_DISK);
            $this->images->delete($photoPath, ShopImageStore::PRIVATE_DISK);

            throw $e;
        }

        $this->mailAdmin(
            "New vendor application: {$vendor->name}",
            'New free shop application',
            [
                "{$vendor->name} ({$vendor->category}, {$vendor->city}, {$vendor->state}) has applied for a free shop.",
                "Owner: {$user->name} · WhatsApp: ".WhatsAppNumber::local($vendor->whatsapp),
            ],
            $vendor,
        );

        return ['user' => $user, 'vendor' => $vendor];
    }

    /**
     * The owner's edit of a pending (more-info) or rejected application.
     * Rejected goes back to pending; a more-info answer stays pending and
     * clears the request.
     *
     * @param  array<string, mixed>  $data  same keys as register(), minus the account fields
     */
    public function updateApplication(Vendor $vendor, array $data, ?UploadedFile $logo, ?UploadedFile $photo, User $by): bool
    {
        $newLogo  = $logo ? $this->images->storeLogo($logo) : null;
        $newPhoto = $photo ? $this->images->storeVerificationPhoto($photo) : null;

        $oldLogo  = $vendor->logo;
        $oldPhoto = $vendor->verification_photo;

        try {
            $result = DB::transaction(function () use ($vendor, $data, $newLogo, $newPhoto, $by) {
                $locked = Vendor::whereKey($vendor->id)->lockForUpdate()->first();

                if (! $locked || ! $locked->canEditApplication()) {
                    return null;
                }

                $wasRejected = $locked->hasStatus(Vendor::STATUS_REJECTED);

                $locked->fill([
                    'name'        => $data['business_name'],
                    'slug'        => $data['slug'],
                    'whatsapp'    => $data['whatsapp'],
                    'category'    => $data['category'],
                    'state'       => $data['state'],
                    'city'        => $data['city_area'],
                    'description' => $data['description'] ?? null,
                    'social_link' => $data['social_link'] ?? null,
                    'status'         => Vendor::STATUS_PENDING,
                    'info_requested' => false,
                ]);

                if ($newLogo) {
                    $locked->logo = $newLogo;
                }
                if ($newPhoto) {
                    $locked->verification_photo = $newPhoto;
                }

                $locked->save();

                $this->log(
                    $locked,
                    $wasRejected ? Vendor::STATUS_REJECTED : Vendor::STATUS_PENDING,
                    Vendor::STATUS_PENDING,
                    $wasRejected ? 'Application edited and resubmitted.' : 'Application updated with the requested info.',
                    $by,
                );

                return ['vendor' => $locked, 'resubmitted' => $wasRejected];
            });
        } catch (Throwable $e) {
            $this->images->delete($newLogo, ShopImageStore::PUBLIC_DISK);
            $this->images->delete($newPhoto, ShopImageStore::PRIVATE_DISK);

            throw $e;
        }

        if (! $result) {
            $this->images->delete($newLogo, ShopImageStore::PUBLIC_DISK);
            $this->images->delete($newPhoto, ShopImageStore::PRIVATE_DISK);

            return false;
        }

        // Replaced files are only removed once the new ones are committed.
        if ($newLogo) {
            $this->images->delete($oldLogo, ShopImageStore::PUBLIC_DISK);
        }
        if ($newPhoto) {
            $this->images->delete($oldPhoto, ShopImageStore::PRIVATE_DISK);
        }

        $updated = $result['vendor'];
        $vendor->setRawAttributes($updated->getAttributes(), true);

        $this->mailAdmin(
            $result['resubmitted'] ? "Application resubmitted: {$updated->name}" : "Application updated: {$updated->name}",
            $result['resubmitted'] ? 'A rejected shop has resubmitted' : 'Application updated',
            [$result['resubmitted']
                ? "{$updated->name} edited their application after it was rejected and sent it back for review."
                : "{$updated->name} updated their application with the info you asked for."],
            $updated,
        );

        return true;
    }

    // ── Admin actions ─────────────────────────────────────────────────────

    public function approve(Vendor $vendor, User $by): bool
    {
        $done = $this->transition($vendor, [Vendor::STATUS_PENDING, Vendor::STATUS_REJECTED], Vendor::STATUS_APPROVED, null, $by, [
            'approved_at'    => now(),
            'approved_by'    => $by->id,
            'info_requested' => false,
            'review_note'    => null,
        ]);

        if ($done) {
            $this->mailVendor($vendor, 'Your GadgetPlug shop is live', 'Your shop is approved', [
                "Great news! {$vendor->name} is now live at {$vendor->shopLinkLabel()}.",
                'Share this link with your customers: '.$vendor->shopLinkUrl(),
            ], 'Open your dashboard', route('login'));
        }

        return $done;
    }

    public function reject(Vendor $vendor, string $reason, User $by): bool
    {
        $done = $this->transition($vendor, [Vendor::STATUS_PENDING], Vendor::STATUS_REJECTED, $reason, $by, [
            'review_note'    => $reason,
            'info_requested' => false,
        ]);

        if ($done) {
            $this->mailVendor($vendor, 'Your GadgetPlug shop application', 'Your application was not approved', [
                "Reason: {$reason}",
                'You can log in, edit your details and resubmit.',
            ], 'Log in to resubmit', route('login'));
        }

        return $done;
    }

    public function requestInfo(Vendor $vendor, string $message, User $by): bool
    {
        // Status stays pending; the log row records the request itself.
        $done = $this->transition($vendor, [Vendor::STATUS_PENDING], Vendor::STATUS_PENDING, 'More info requested: '.$message, $by, [
            'review_note'    => $message,
            'info_requested' => true,
        ]);

        if ($done) {
            $this->mailVendor($vendor, 'We need a bit more info about your GadgetPlug shop', 'A little more info, please', [
                $message,
                'Please log in and update your application.',
            ], 'Update application', route('login'));
        }

        return $done;
    }

    public function suspend(Vendor $vendor, string $reason, User $by): bool
    {
        return $this->transition($vendor, [Vendor::STATUS_APPROVED], Vendor::STATUS_SUSPENDED, $reason, $by, [
            'review_note' => $reason,
        ]);
    }

    public function reinstate(Vendor $vendor, User $by): bool
    {
        return $this->transition($vendor, [Vendor::STATUS_SUSPENDED], Vendor::STATUS_APPROVED, 'Reinstated.', $by, [
            'review_note' => null,
        ]);
    }

    // ── WhatsApp ──────────────────────────────────────────────────────────

    /** The prefilled message for the "Message on WhatsApp" button after an action. */
    public static function whatsAppMessage(Vendor $vendor, string $kind): string
    {
        $owner = $vendor->user?->name ?? 'there';
        $note  = trim((string) $vendor->review_note);

        return match ($kind) {
            'approved'  => "Hi {$owner}, great news! Your GadgetPlug shop {$vendor->name} is now live: {$vendor->shopLinkUrl()} — share this link with your customers.",
            'rejected'  => "Hi {$owner}, your GadgetPlug shop application wasn't approved. Reason: {$note}. You can log in, edit your details and resubmit.",
            'more_info' => "Hi {$owner}, we need a bit more info to approve your GadgetPlug shop: {$note}. Please log in and update your application.",
            'suspended' => "Hi {$owner}, your GadgetPlug shop has been suspended. Reason: {$note}. Contact us for help.",
            default     => "Hi {$owner}, ",
        };
    }

    /** Which of the messages above fits the shop's current state. */
    public static function whatsAppKindFor(Vendor $vendor): string
    {
        return match (true) {
            $vendor->hasStatus(Vendor::STATUS_APPROVED)  => 'approved',
            $vendor->hasStatus(Vendor::STATUS_REJECTED)  => 'rejected',
            $vendor->hasStatus(Vendor::STATUS_SUSPENDED) => 'suspended',
            $vendor->isAwaitingInfo()                    => 'more_info',
            default                                      => 'other',
        };
    }

    public static function whatsAppLink(Vendor $vendor, ?string $kind = null): string
    {
        return WhatsAppNumber::link($vendor->whatsapp, self::whatsAppMessage($vendor, $kind ?? self::whatsAppKindFor($vendor)));
    }

    // ── Shared checks ─────────────────────────────────────────────────────

    /**
     * Whether another vendor already uses this WhatsApp number.
     *
     * Compared after normalising both sides: shops created by the admin typed
     * their numbers in every format ("0803…", "+234 803…"), and those count
     * as the same number. The vendors table is small enough to read whole.
     */
    public static function whatsAppInUse(string $normalised, ?int $ignoreVendorId = null): bool
    {
        return Vendor::query()
            ->whereNotNull('whatsapp')
            ->when($ignoreVendorId, fn ($q) => $q->whereKeyNot($ignoreVendorId))
            ->pluck('whatsapp')
            ->contains(fn ($number) => WhatsAppNumber::normalise($number) === $normalised);
    }

    // ── Passwords ─────────────────────────────────────────────────────────

    /**
     * The admin's reset for a shop owner who has lost their password — most
     * of these shops have no email, so the usual reset link cannot reach
     * them. Returns the temporary password so the admin can pass it on once;
     * only its hash is kept, and the log records that a reset happened, never
     * what the password was.
     */
    public function resetPassword(Vendor $vendor, User $by): ?string
    {
        $owner = $vendor->user;

        if (! $owner || ! $vendor->isFreePlan()) {
            return null;
        }

        $temporary = self::temporaryPassword();

        DB::transaction(function () use ($vendor, $owner, $temporary, $by) {
            // A new remember token too, so a "remember me" cookie on a lost
            // or shared phone stops working along with the old password.
            $owner->forceFill([
                'password'       => $temporary,
                'remember_token' => Str::random(60),
            ])->save();

            $this->log($vendor, $vendor->status, $vendor->status, 'Password reset by admin.', $by);
        });

        return $temporary;
    }

    /**
     * Eight characters meeting the app's password rules (mixed case, a
     * number, a symbol), so the owner can keep it if they choose.
     */
    public static function temporaryPassword(): string
    {
        $pick = fn (string $set) => $set[random_int(0, strlen($set) - 1)];
        $all  = self::TEMP_UPPER.self::TEMP_LOWER.self::TEMP_DIGITS;

        $chars = [
            $pick(self::TEMP_UPPER), $pick(self::TEMP_LOWER),
            $pick(self::TEMP_DIGITS), $pick(self::TEMP_SYMBOL),
        ];

        while (count($chars) < 8) {
            $chars[] = $pick($all);
        }

        // Fisher–Yates with random_int, so the guaranteed four are not
        // always at the front.
        for ($i = count($chars) - 1; $i > 0; $i--) {
            $j = random_int(0, $i);
            [$chars[$i], $chars[$j]] = [$chars[$j], $chars[$i]];
        }

        return implode('', $chars);
    }

    public static function passwordResetWhatsAppLink(Vendor $vendor, string $temporary): string
    {
        $owner = $vendor->user?->name ?? 'there';

        return WhatsAppNumber::link(
            $vendor->whatsapp,
            "Hi {$owner}, your new GadgetPlug login password is {$temporary}. Log in with your WhatsApp number and change it under Shop Profile.",
        );
    }

    // ── Placeholder emails ────────────────────────────────────────────────

    public static function placeholderEmailFor(User $user): string
    {
        return $user->getKey().'@'.self::PLACEHOLDER_EMAIL_DOMAIN;
    }

    public static function isPlaceholderEmail(?string $email): bool
    {
        return $email === null || $email === ''
            || str_ends_with(strtolower(trim($email)), '@'.self::PLACEHOLDER_EMAIL_DOMAIN);
    }

    // ── Internals ─────────────────────────────────────────────────────────

    /**
     * @param  list<string>  $from  statuses this change is allowed from
     * @param  array<string, mixed>  $extra  other columns written with it
     */
    private function transition(Vendor $vendor, array $from, string $to, ?string $note, User $by, array $extra = []): bool
    {
        $updated = DB::transaction(function () use ($vendor, $from, $to, $note, $by, $extra) {
            $locked = Vendor::whereKey($vendor->id)->lockForUpdate()->first();

            if (! $locked || ! $locked->isFreePlan() || ! in_array($locked->status, $from, true)) {
                return null;
            }

            $previous = $locked->status;

            $locked->fill(['status' => $to, ...$extra])->save();

            $this->log($locked, $previous, $to, $note, $by);

            return $locked;
        });

        if (! $updated) {
            return false;
        }

        // The caller's instance reflects the change, so its next render (and
        // the WhatsApp message built from it) reads the new note and status.
        $vendor->setRawAttributes($updated->getAttributes(), true);

        return true;
    }

    private function log(Vendor $vendor, ?string $from, string $to, ?string $note, ?User $by): void
    {
        VendorStatusLog::create([
            'vendor_id'   => $vendor->id,
            'from_status' => $from,
            'to_status'   => $to,
            'note'        => $note,
            'changed_by'  => $by?->id,
        ]);
    }

    /** @param list<string> $lines */
    private function mailAdmin(string $subject, string $heading, array $lines, Vendor $vendor): void
    {
        $to = FreePlanSetting::adminEmail();

        if (! $to) {
            Log::info('Free shop application mail skipped: no admin_notification_email set.', ['vendor_id' => $vendor->id]);

            return;
        }

        $this->send($to, new FreeShopMail(
            $subject,
            $heading,
            $lines,
            'Review application',
            // By id: the resource is keyed on id, so the link survives the
            // shop changing its link name while the application is open.
            rescue(fn () => route('filament.admin.resources.free-shop-applications.view', ['record' => $vendor->id]), url('/admin'), false),
        ), $vendor);
    }

    /** @param list<string> $lines */
    private function mailVendor(Vendor $vendor, string $subject, string $heading, array $lines, ?string $actionText, ?string $actionUrl): void
    {
        $email = $vendor->user?->email;

        if (self::isPlaceholderEmail($email)) {
            return;
        }

        $owner = $vendor->user->name;

        $this->send($email, new FreeShopMail($subject, $heading, ["Hi {$owner},", ...$lines], $actionText, $actionUrl), $vendor);
    }

    private function send(string $to, FreeShopMail $mail, Vendor $vendor): void
    {
        try {
            Mail::to($to)->queue($mail);
        } catch (Throwable $e) {
            Log::warning('Free shop mail failed to send.', [
                'vendor_id' => $vendor->id,
                'subject'   => $mail->subjectLine,
                'error'     => $e->getMessage(),
            ]);
        }
    }
}

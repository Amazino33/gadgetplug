<?php

namespace App\Services\StockTake;

use App\Models\StockTakeTask;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * One phone per task (release 2, A7).
 *
 * The first phone to download a task holds it. Another phone opening it is
 * refused (409, "being counted on another phone") until a manager releases it.
 * Every download, upload and hand-in says which phone it comes from, and how
 * many counts that phone still has waiting, so the manager can see what a
 * release would lose.
 */
class StockTakeDevice
{
    public const OTHER_PHONE = 'This task is being counted on another phone. Ask the manager to release it if you need to carry on here.';

    /**
     * The phone `$device` may work on the task: it holds it, or nobody does
     * (and now it does). Records when it was seen and what it has waiting.
     *
     * @throws HttpResponseException 409 when another phone holds the task
     */
    public function hold(StockTakeTask $task, string $device, ?string $label = null, ?int $pending = null): void
    {
        DB::transaction(function () use ($task, $device, $label, $pending) {
            $locked = StockTakeTask::query()->whereKey($task->id)->lockForUpdate()->firstOrFail();

            if ($locked->device_id !== null && $locked->device_id !== $device) {
                // Say which phone and browser has it, so the counter knows where to go.
                $where = $locked->device_label
                    ? ' It is open on ' . $locked->device_label . ($locked->device_seen_at ? ', last used ' . $locked->device_seen_at->timezone('Africa/Lagos')->format('D g:ia') : '') . '.'
                    : '';

                throw new HttpResponseException(response()->json([
                    'message' => self::OTHER_PHONE . $where,
                    'code'    => 'other_phone',
                    'holder'  => $locked->device_label,
                ], 409));
            }

            $locked->forceFill([
                'device_id'      => $device,
                'device_label'   => $label !== null ? mb_substr($label, 0, 80) : $locked->device_label,
                'device_seen_at' => now(),
                'device_pending' => $pending ?? $locked->device_pending ?? 0,
            ])->save();

            $task->setRawAttributes($locked->getAttributes(), true);
        });
    }

    /**
     * Free the task for another phone. If the phone holding it last reported
     * counts not yet uploaded, the manager must say they accept losing them.
     *
     * @throws AuthorizationException|ValidationException
     */
    public function release(User $by, StockTakeTask $task, bool $acceptLoss = false): void
    {
        if (! StockTakeAccess::canManage($by, (int) $task->vendor_id, (int) $task->store_id)) {
            throw new AuthorizationException('You cannot run stock takes at this branch.');
        }

        if (! $task->isOpen()) {
            throw ValidationException::withMessages(['task' => 'Only a task not yet submitted can be moved to another phone.']);
        }

        if ((int) $task->device_pending > 0 && ! $acceptLoss) {
            throw ValidationException::withMessages([
                'task' => "That phone still had {$task->device_pending} count(s) not uploaded. They will be lost unless it gets signal first.",
            ]);
        }

        $task->forceFill([
            'device_id'      => null,
            'device_label'   => null,
            'device_seen_at' => null,
            'device_pending' => null,
        ])->save();
    }

    /** "Android · Chrome", from the browser's own description of itself. */
    public static function labelFrom(?string $userAgent): ?string
    {
        if (! $userAgent) {
            return null;
        }

        $os = match (true) {
            str_contains($userAgent, 'iPhone')  => 'iPhone',
            str_contains($userAgent, 'iPad')    => 'iPad',
            str_contains($userAgent, 'Android') => 'Android',
            str_contains($userAgent, 'Windows') => 'Windows',
            str_contains($userAgent, 'Mac OS')  => 'Mac',
            default                             => 'Other',
        };

        $browser = match (true) {
            str_contains($userAgent, 'Edg/')                                     => 'Edge',
            str_contains($userAgent, 'SamsungBrowser')                           => 'Samsung Internet',
            str_contains($userAgent, 'CriOS'), str_contains($userAgent, 'Chrome') => 'Chrome',
            str_contains($userAgent, 'Firefox'), str_contains($userAgent, 'FxiOS') => 'Firefox',
            str_contains($userAgent, 'Safari')                                   => 'Safari',
            default                                                              => 'browser',
        };

        return "{$os} · {$browser}";
    }
}

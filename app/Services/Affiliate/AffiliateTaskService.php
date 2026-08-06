<?php

namespace App\Services\Affiliate;

use App\Models\Affiliate;
use App\Models\AffiliateCommission;
use App\Models\AffiliateTask;
use App\Models\AffiliateTaskSubmission;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

// Manual submissions and auto-completions both credit the wallet through the
// exact same primitive ClearAffiliateHoldsJob already uses — a relation-scoped
// ->create(['type' => 'credit', ...]) on WalletTransaction — never a second
// money path. Approval and auto-completion both re-check state under a row
// lock before crediting, so a double-click or an overlapping job run can
// never double-credit the same submission.
class AffiliateTaskService
{
    public function submit(AffiliateTask $task, Affiliate $affiliate, ?string $notes = null): AffiliateTaskSubmission
    {
        if ($task->verification_type !== 'manual') {
            throw new RuntimeException('Only manual tasks accept a direct submission — auto tasks complete themselves.');
        }

        if (! $task->is_active) {
            throw new RuntimeException('This task is no longer active.');
        }

        if (! $this->isEligible($task, $affiliate)) {
            throw new RuntimeException('This affiliate is not currently eligible to submit this task (a submission is already pending review, the completion limit has been reached, or the cooldown has not elapsed).');
        }

        return AffiliateTaskSubmission::create([
            'affiliate_task_id' => $task->id,
            'affiliate_id'      => $affiliate->id,
            'status'            => 'submitted',
            'notes'             => $notes,
            'submitted_at'      => now(),
        ]);
    }

    public function approve(AffiliateTaskSubmission $submission, User $reviewer): void
    {
        DB::transaction(function () use ($submission, $reviewer) {
            $locked = AffiliateTaskSubmission::where('id', $submission->id)
                ->where('status', 'submitted')
                ->lockForUpdate()
                ->first();

            if (! $locked) {
                return;
            }

            $task = $locked->task;

            $locked->update([
                'status'                => 'approved',
                'reviewed_at'           => now(),
                'reviewed_by'           => $reviewer->id,
                'level_progress_value'  => $task->counts_toward_level ? $task->level_progress_value : null,
            ]);

            $locked->walletTransactions()->create([
                'affiliate_id' => $locked->affiliate_id,
                'type'         => 'credit',
                'amount'       => $task->reward_amount,
                'description'  => "Task reward — {$task->name} (submission #{$locked->id}).",
            ]);

            if ($task->counts_toward_level) {
                app(AffiliateLevelProgressionService::class)->recompute($locked->affiliate);
            }

            activity()
                ->causedBy($reviewer)
                ->performedOn($locked)
                ->withProperties(['task' => $task->name])
                ->log('Task submission approved');
        });
    }

    public function reject(AffiliateTaskSubmission $submission, User $reviewer, string $reason): void
    {
        DB::transaction(function () use ($submission, $reviewer, $reason) {
            $locked = AffiliateTaskSubmission::where('id', $submission->id)
                ->where('status', 'submitted')
                ->lockForUpdate()
                ->first();

            if (! $locked) {
                return;
            }

            $locked->update([
                'status'           => 'rejected',
                'reviewed_at'      => now(),
                'reviewed_by'      => $reviewer->id,
                'rejected_reason'  => $reason,
            ]);

            activity()
                ->causedBy($reviewer)
                ->performedOn($locked)
                ->withProperties(['reason' => $reason])
                ->log('Task submission rejected');
        });
    }

    /**
     * Checks every active auto task against this affiliate's current metrics
     * and completes (pre-approved, no human step) any newly-qualifying one.
     * Called from the same transaction that clears a commission to
     * 'available' — that's the only moment a sales-based metric can change.
     */
    public function evaluateAuto(Affiliate $affiliate): void
    {
        // Row lock as a mutex: without it, two commissions for the same
        // affiliate clearing in close succession (or an overlapping job run,
        // since ClearAffiliateHoldsJob isn't withoutOverlapping()) could both
        // pass the eligibility check before either write lands.
        $lockedAffiliate = Affiliate::where('id', $affiliate->id)->lockForUpdate()->first();

        AffiliateTask::where('is_active', true)
            ->where('verification_type', 'auto')
            ->get()
            ->each(function (AffiliateTask $task) use ($lockedAffiliate) {
                if (! $this->metricMeetsTarget($task, $lockedAffiliate)) {
                    return;
                }

                if (! $this->isEligible($task, $lockedAffiliate)) {
                    return;
                }

                $submission = AffiliateTaskSubmission::create([
                    'affiliate_task_id'     => $task->id,
                    'affiliate_id'          => $lockedAffiliate->id,
                    'status'                => 'approved',
                    'submitted_at'          => now(),
                    'reviewed_at'           => now(),
                    'level_progress_value'  => $task->counts_toward_level ? $task->level_progress_value : null,
                ]);

                $submission->walletTransactions()->create([
                    'affiliate_id' => $lockedAffiliate->id,
                    'type'         => 'credit',
                    'amount'       => $task->reward_amount,
                    'description'  => "Task reward — {$task->name} (auto-completed, submission #{$submission->id}).",
                ]);

                if ($task->counts_toward_level) {
                    app(AffiliateLevelProgressionService::class)->recompute($lockedAffiliate);
                }
            });
    }

    private function metricMeetsTarget(AffiliateTask $task, Affiliate $affiliate): bool
    {
        $value = match ($task->auto_metric) {
            'cleared_sales_value'   => app(AffiliateLevelProgressionService::class)->lifetimeSalesValue($affiliate->id),
            'completed_sales_count' => AffiliateCommission::where('affiliate_id', $affiliate->id)->where('status', 'available')->count(),
            default                 => 0,
        };

        return $value >= (float) $task->auto_target;
    }

    private function isEligible(AffiliateTask $task, Affiliate $affiliate): bool
    {
        $hasPendingSubmission = AffiliateTaskSubmission::where('affiliate_task_id', $task->id)
            ->where('affiliate_id', $affiliate->id)
            ->where('status', 'submitted')
            ->exists();

        if ($hasPendingSubmission) {
            return false;
        }

        $approvedQuery = AffiliateTaskSubmission::where('affiliate_task_id', $task->id)
            ->where('affiliate_id', $affiliate->id)
            ->where('status', 'approved');

        if ($task->max_completions_per_affiliate !== null
            && (clone $approvedQuery)->count() >= $task->max_completions_per_affiliate) {
            return false;
        }

        if ($task->cooldown_days !== null) {
            $lastApprovedAt = (clone $approvedQuery)->max('reviewed_at');

            if ($lastApprovedAt && now()->lt(Carbon::parse($lastApprovedAt)->addDays($task->cooldown_days))) {
                return false;
            }
        }

        return true;
    }
}

<?php

namespace App\Console\Commands;

use App\Models\AuditSession;
use App\Models\BlindCountSession;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

// Files historic counted lines under the count that produced them.
//
// audit_sessions only started recording blind_count_session_id in
// 2026_08_12_100000, so counts taken before that show as having no lines even
// though the lines exist. This reconnects them.
//
// It is not guesswork. A session records product_order — the exact product ids
// it covered — and its submission timestamp, and the lines are written seconds
// later in the same request. Matching on vendor + counter + "this product was
// in that count's list" + a tight time window is a factual join, not an
// inference. Anything that could belong to two counts is left alone and
// reported rather than assigned to whichever was found first.
class LinkCountLinesCommand extends Command
{
    protected $signature = 'counts:link-lines
                            {--force : Actually write the links. Without this the command only reports.}
                            {--window=30 : Minutes either side of submission a line may have been written.}';

    protected $description = 'Attach historic counted lines to the count session that produced them';

    public function handle(): int
    {
        $windowMinutes = (int) $this->option('window');

        $sessions = BlindCountSession::query()
            ->whereDoesntHave('auditLines')
            ->orderBy('id')
            ->get();

        if ($sessions->isEmpty()) {
            $this->info('Every count already has its lines attached.');

            return self::SUCCESS;
        }

        $this->line("Checking {$sessions->count()} count(s) with no lines attached, ±{$windowMinutes} min around submission.");
        $this->newLine();

        /** @var array<int, array<int, int>> $claims  line id => session ids that could own it */
        $claims   = [];
        $proposed = [];

        foreach ($sessions as $session) {
            $productIds = $session->product_order ?? [];

            if ($productIds === []) {
                continue;
            }

            // The lines are written immediately after the session is stamped
            // submitted, so that is the anchor rather than the session's own
            // created_at — a count can be open for hours before it is finished.
            $anchor = $session->b_submitted_at ?? $session->a_submitted_at ?? $session->created_at;

            $candidates = AuditSession::query()
                ->whereNull('blind_count_session_id')
                ->where('vendor_id', $session->vendor_id)
                ->where('storekeeper_a_id', $session->storekeeper_a_id)
                ->whereIn('product_id', $productIds)
                ->whereBetween('created_at', [
                    $anchor->copy()->subMinutes($windowMinutes),
                    $anchor->copy()->addMinutes($windowMinutes),
                ])
                ->pluck('id')
                ->all();

            $proposed[$session->id] = $candidates;

            foreach ($candidates as $lineId) {
                $claims[$lineId][] = $session->id;
            }
        }

        // A line inside two counts' windows cannot be assigned safely — the
        // whole point of this command is not to file someone's count under the
        // wrong session.
        $contested = array_keys(array_filter($claims, fn (array $owners) => count($owners) > 1));

        $linkable = 0;

        foreach ($sessions as $session) {
            $lines = array_values(array_diff($proposed[$session->id] ?? [], $contested));

            $label = $session->created_at->format('d M Y, g:ia').' — '.($session->storekeeperA?->name ?? 'unknown');

            if ($lines === []) {
                $this->line("  <fg=yellow>skip</>   {$label}: nothing matched");

                continue;
            }

            $expected = count($session->product_order ?? []);
            $this->line("  <fg=green>link</>   {$label}: ".count($lines)." of {$expected} product(s)");

            $proposed[$session->id] = $lines;
            $linkable += count($lines);
        }

        if ($contested !== []) {
            $this->newLine();
            $this->warn(count($contested).' line(s) fall inside more than one count\'s window and were left unassigned.');
            $this->line('Re-run with a smaller --window to separate them.');
        }

        $this->newLine();

        if ($linkable === 0) {
            $this->info('Nothing safe to link.');

            return self::SUCCESS;
        }

        if (! $this->option('force')) {
            $this->warn("{$linkable} line(s) would be attached.");
            $this->line('Re-run with --force to apply.');

            return self::SUCCESS;
        }

        DB::transaction(function () use ($proposed) {
            foreach ($proposed as $sessionId => $lineIds) {
                if ($lineIds === []) {
                    continue;
                }

                AuditSession::whereIn('id', $lineIds)->update(['blind_count_session_id' => $sessionId]);
            }
        });

        $this->info("Attached {$linkable} line(s).");

        return self::SUCCESS;
    }
}

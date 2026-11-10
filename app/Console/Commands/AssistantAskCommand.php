<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\Assistant\CreateThreadAction;
use App\Actions\Assistant\PreflightCheckAction;
use App\Actions\Assistant\SendMessageAction;
use App\Actions\Assistant\SetThreadStoreAction;
use App\Exceptions\Assistant\UnauthorizedStoreAccess;
use App\Models\AssistantQueryRun;
use App\Models\AssistantThread;
use App\Models\AssistantVendorDailyUsage;
use App\Models\Store;
use App\Models\User;
use App\Models\Vendor;
use App\Services\Assistant\AssistantScope;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

/**
 * Ask the assistant from the terminal, as a given user in a given business.
 *
 * The same path as the chat bubble (SendMessageAction): the same access
 * checks, store scope, caps and logging. The question counts toward the
 * business's daily cap and is logged with channel "cli".
 *
 *   php artisan assistant:ask "how much did we sell today?" --user=owner@example.com
 *   php artisan assistant:ask "and yesterday?" --user=12 --vendor=supreme-gadgets --thread=40
 */
class AssistantAskCommand extends Command
{
    protected $signature = 'assistant:ask
        {question : What to ask, in quotes}
        {--user= : The asker: user id or email}
        {--vendor= : The business: vendor id or slug (needed only if the user belongs to more than one)}
        {--thread= : Continue this conversation (id from a previous run)}
        {--store= : Store id to answer for, when the user has several}';

    protected $description = 'Ask Ask GadgetPlug a question as a user, and print the answer, tools called, tokens, cache, cost and latency';

    public function handle(SendMessageAction $send): int
    {
        $user = $this->resolveUser();
        if (! $user) {
            return self::FAILURE;
        }

        $vendor = $this->resolveVendor($user);
        if (! $vendor) {
            return self::FAILURE;
        }

        $thread = null;
        if ($this->option('thread') !== null) {
            $thread = AssistantThread::query()->find((int) $this->option('thread'));

            if (! $thread) {
                $this->error('No conversation with id '.$this->option('thread').'.');

                return self::FAILURE;
            }
        }

        // As in the panel: the asker is the signed-in user.
        Auth::setUser($user);

        if ($thread !== null && ((int) $thread->user_id !== (int) $user->id || ($thread->vendor_id !== null && (int) $thread->vendor_id !== (int) $vendor->id))) {
            $this->error('Refused: that conversation belongs to someone else or another business.');

            return self::FAILURE;
        }

        // --store does what picking a branch in the chat bubble does: it sets
        // the conversation's store. SendMessageAction answers a business with
        // several branches only once its conversation has one.
        if ($this->option('store') !== null) {
            $store = Store::query()->find((int) $this->option('store'));

            if (! $store) {
                $this->error('No store with id '.$this->option('store').'.');

                return self::FAILURE;
            }

            // Checked before anything is created, so a refusal leaves no trace.
            if (! AssistantScope::for($user, $vendor)->allows((int) $store->id)) {
                $this->error("Refused: {$user->email} cannot see that store in {$vendor->name}.");

                return self::FAILURE;
            }

            $thread ??= app(CreateThreadAction::class)->execute($user, $vendor);

            if ((int) $thread->store_id !== (int) $store->id) {
                try {
                    app(SetThreadStoreAction::class)->execute($thread, $store, 'cli');
                } catch (UnauthorizedStoreAccess) {
                    $this->error("Refused: {$user->email} cannot see that store in {$vendor->name}.");

                    return self::FAILURE;
                }

                $thread = $thread->fresh();
            }
        }

        try {
            $result = $send->execute(
                $user,
                $thread,
                (string) $this->argument('question'),
                $this->option('store') !== null ? (int) $this->option('store') : null,
                $vendor,
                'cli',
            );
        } catch (AuthorizationException $e) {
            $this->error('Refused: '.$e->getMessage());

            return self::FAILURE;
        } catch (ValidationException $e) {
            $this->error(collect($e->errors())->flatten()->first() ?? $e->getMessage());

            return self::FAILURE;
        }

        $answer = $result['assistant_message']->fresh();
        $meta = $answer->ai_meta ?? [];

        $this->newLine();
        $this->line('<options=bold>Answer</>');
        $this->line((string) $answer->content);
        $this->newLine();

        $runs = AssistantQueryRun::query()->where('message_id', $answer->id)->orderBy('id')->get();

        if ($runs->isEmpty()) {
            $this->line('Tools called: none');
        } else {
            $this->line('<options=bold>Tools called</>');
            $this->table(
                ['Tool', 'Scope', 'From', 'To', 'Result', 'ms'],
                $runs->map(fn (AssistantQueryRun $r) => [
                    $r->query_key,
                    json_encode($r->scope_json),
                    $r->date_from?->toDateString(),
                    $r->date_to?->toDateString(),
                    ! $r->success ? 'error: '.mb_strimwidth((string) $r->error_message, 0, 60, '...') : ($r->has_data ? 'data' : 'no data ('.$r->failure_reason.')'),
                    $r->duration_ms,
                ])->all(),
            );
        }

        $cap = PreflightCheckAction::vendorCap($vendor);
        $cost = isset($meta['cost_micros']) ? '$'.number_format(((int) $meta['cost_micros']) / 1_000_000, 6).' ('.($meta['cost_source'] ?? 'rate_table').')' : '-';

        $this->table(['', ''], [
            ['Status', $answer->status?->value ?? '-'],
            ['Model', $meta['model'] ?? '-'],
            ['Tokens in / out', ($meta['tokens_in'] ?? 0).' / '.($meta['tokens_out'] ?? 0)],
            ['Cache read / write', ($meta['cache_read_tokens'] ?? 0).' / '.($meta['cache_write_tokens'] ?? 0)],
            ['Model calls', (string) ($meta['rounds'] ?? 0)],
            ['Cost', $cost],
            ['Latency (whole question / model)', ($answer->latency_ms ?? '-').' ms / '.($meta['model_ms'] ?? '-').' ms'],
            ['Business questions today', AssistantVendorDailyUsage::questionsToday((int) $vendor->id).($cap > 0 ? " of {$cap}" : ' (no limit)')],
            ['Conversation', $result['thread']->id.'  (follow up with --thread='.$result['thread']->id.')'],
        ]);

        return self::SUCCESS;
    }

    private function resolveUser(): ?User
    {
        $ref = trim((string) $this->option('user'));

        if ($ref === '') {
            $this->error('Pass --user=<id or email>.');

            return null;
        }

        $user = ctype_digit($ref) ? User::query()->find((int) $ref) : User::query()->where('email', $ref)->first();

        if (! $user) {
            $this->error("No user {$ref}.");
        }

        return $user;
    }

    private function resolveVendor(User $user): ?Vendor
    {
        $tenants = $user->getTenants(filament()->getPanel('vendor'));
        $ref = trim((string) $this->option('vendor'));

        if ($ref === '') {
            if ($tenants->count() === 1) {
                return $tenants->first();
            }

            $this->error($tenants->isEmpty()
                ? 'That user belongs to no business.'
                : 'That user belongs to more than one business; pass --vendor=<id or slug>: '.$tenants->take(10)->map(fn (Vendor $v) => "{$v->id} ({$v->slug})")->implode(', '));

            return null;
        }

        $vendor = ctype_digit($ref) ? Vendor::query()->find((int) $ref) : Vendor::query()->where('slug', $ref)->first();

        if (! $vendor) {
            $this->error("No business {$ref}.");

            return null;
        }

        if (! $tenants->contains('id', $vendor->id)) {
            $this->error("That user is not part of {$vendor->name}.");

            return null;
        }

        return $vendor;
    }
}

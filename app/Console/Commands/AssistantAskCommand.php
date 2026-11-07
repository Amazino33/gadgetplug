<?php

namespace App\Console\Commands;

use App\Models\Assistant\AssistantConversation;
use App\Models\User;
use App\Models\Vendor;
use App\Services\Assistant\AssistantService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Console\Command;
use InvalidArgumentException;

/**
 * Ask the assistant a question from the terminal, as a real user.
 *
 * Calls AssistantService::ask() — the one entry point the panel chat and
 * WhatsApp use — so the question passes the same use_ai_assistant check,
 * daily cap, store and cost guards, and is logged exactly like any other.
 * The only difference is the channel label ("cli") on the log rows. It
 * counts toward the vendor's daily allowance.
 *
 *   php artisan assistant:ask "how much did we sell today?" --user=12
 *   php artisan assistant:ask "and Ikeja?" --user=12 --conversation=40
 */
class AssistantAskCommand extends Command
{
    protected $signature = 'assistant:ask
                            {question : The question, in quotes}
                            {--user= : User id or email to ask as (required)}
                            {--vendor= : Vendor id or slug — only needed when the user belongs to more than one}
                            {--conversation= : Continue an earlier conversation by id (printed after each answer)}';

    protected $description = 'Ask Ask GadgetPlug a question as a given user, through the same path as the chat panel';

    public function handle(AssistantService $assistant): int
    {
        $user = $this->resolveUser();

        if (! $user) {
            return self::FAILURE;
        }

        $vendor = $this->resolveVendor($user);

        if (! $vendor) {
            return self::FAILURE;
        }

        $conversation = null;

        if ($id = $this->option('conversation')) {
            $conversation = AssistantConversation::find($id);

            if (! $conversation) {
                $this->error("No conversation #{$id}.");

                return self::FAILURE;
            }
        }

        try {
            $reply = $assistant->ask($user, $vendor, (string) $this->argument('question'), $conversation, 'cli');
        } catch (AuthorizationException $e) {
            $this->error("Conversation #{$conversation->id} does not belong to {$user->email} at {$vendor->name}.");

            return self::FAILURE;
        } catch (InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $q = $reply->question;

        $this->newLine();
        $this->line($reply->text);
        $this->newLine();

        $this->line('<comment>Asked as:</comment>     '.$user->name.' <'.$user->email.'> at '.$vendor->name.' (vendor #'.$vendor->id.')');
        $this->line('<comment>Status:</comment>       '.$reply->status);
        $this->line('<comment>Tools called:</comment> '.(implode(', ', $q?->tools_called ?? []) ?: 'none'));
        $this->line(sprintf(
            '<comment>Tokens:</comment>       input %s (cache read %s, cache write %s) · output %s',
            number_format($q?->input_tokens ?? 0),
            number_format($q?->cache_read_tokens ?? 0),
            number_format($q?->cache_write_tokens ?? 0),
            number_format($q?->output_tokens ?? 0),
        ));
        $this->line(sprintf('<comment>Model calls:</comment>  %d · %s ms · model %s', $q?->rounds ?? 0, $q?->latency_ms ?? 0, $q?->model ?? '-'));

        if ($q?->toolCalls()->exists()) {
            foreach ($q->toolCalls()->orderBy('id')->get() as $call) {
                $this->line(sprintf(
                    '  · %s %s → %s%s',
                    $call->tool,
                    json_encode($call->input ?: (object) [], JSON_UNESCAPED_UNICODE),
                    $call->status,
                    $call->dropped_input ? ' (dropped: '.implode(', ', array_keys($call->dropped_input)).')' : '',
                ));
            }
        }

        $this->line('<comment>Logged:</comment>       question #'.($q?->id ?? '-').($reply->conversation ? ' · conversation #'.$reply->conversation->id.' (continue with --conversation='.$reply->conversation->id.')' : ''));

        return $reply->answered() ? self::SUCCESS : self::FAILURE;
    }

    private function resolveUser(): ?User
    {
        $key = $this->option('user');

        if (blank($key)) {
            $this->error('Pass --user=<id or email> — the assistant always answers as a real user, with their permissions.');

            return null;
        }

        $user = User::where(is_numeric($key) ? 'id' : 'email', $key)->first();

        if (! $user) {
            $this->error("No user {$key}.");
        }

        return $user;
    }

    /**
     * The vendor the question is about. Taken from --vendor, or from the
     * user's only business. Never guessed when they have several: asking as
     * the wrong business is exactly the mistake the guards exist to prevent.
     */
    private function resolveVendor(User $user): ?Vendor
    {
        if ($key = $this->option('vendor')) {
            $vendor = Vendor::where(is_numeric($key) ? 'id' : 'slug', $key)->first();

            if (! $vendor) {
                $this->error("No vendor {$key}.");

                return null;
            }

            if (! $user->canAccessTenant($vendor)) {
                $this->error("{$user->email} is not a member of {$vendor->name}.");

                return null;
            }

            return $vendor;
        }

        $vendors = $user->vendors();

        if ($vendors->count() === 1) {
            return $vendors->first();
        }

        $this->error($vendors->isEmpty()
            ? "{$user->email} belongs to no vendor. Pass --vendor=<id or slug>."
            : "{$user->email} belongs to several vendors — pass --vendor= one of: ".$vendors->pluck('slug')->implode(', '));

        return null;
    }
}

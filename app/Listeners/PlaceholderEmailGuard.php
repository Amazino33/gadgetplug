<?php

namespace App\Listeners;

use App\Services\FreePlan\FreeShopApplications;
use Illuminate\Mail\Events\MessageSending;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

/**
 * The last line against mailing a free shop's placeholder address
 * ("{id}@noemail.gadgetplug.invalid"). Runs for every message the app sends — the
 * free-shop mails, Fortify's reset link, invites, anything added later — so
 * no caller has to remember to check.
 *
 * Placeholder recipients are taken off the message; if nobody real is left,
 * returning false cancels the send altogether.
 */
class PlaceholderEmailGuard
{
    public function handle(MessageSending $event): ?bool
    {
        $message = $event->message;

        if (! $message instanceof Email) {
            return null;
        }

        $dropped = [];
        $keep = function (array $addresses) use (&$dropped): array {
            return array_values(array_filter($addresses, function (Address $address) use (&$dropped) {
                if (FreeShopApplications::isPlaceholderEmail($address->getAddress())) {
                    $dropped[] = $address->getAddress();

                    return false;
                }

                return true;
            }));
        };

        $to  = $keep($message->getTo());
        $cc  = $keep($message->getCc());
        $bcc = $keep($message->getBcc());

        if ($dropped === []) {
            return null;
        }

        Log::info('Mail to a placeholder address was not sent.', [
            'subject'    => $message->getSubject(),
            'recipients' => $dropped,
        ]);

        if ($to === [] && $cc === [] && $bcc === []) {
            return false;
        }

        // Rebuilt from what is left. A message whose only "to" was the
        // placeholder but which still has a cc goes to the cc alone.
        $message->getHeaders()->remove('To');
        $message->getHeaders()->remove('Cc');
        $message->getHeaders()->remove('Bcc');

        if ($to !== []) {
            $message->to(...$to);
        }
        if ($cc !== []) {
            $message->cc(...$cc);
        }
        if ($bcc !== []) {
            $message->bcc(...$bcc);
        }

        return null;
    }
}

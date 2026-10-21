<?php

namespace App\Console\Commands;

use App\Models\DeliveryMessage;
use App\Models\Order;
use App\Models\PlatformMessagingSetting;
use App\Services\Messaging\MessagingService;
use App\Services\Orders\DispatchMessages;
use Filament\Notifications\Notification;
use Illuminate\Console\Command;

/**
 * Orders the store has packed but nobody has collected: GadgetPlug's job (a
 * logistics company has to be sent), so the reminder goes to admin dispatch —
 * the panel bell of everyone who runs dispatch, and the ops WhatsApp — not to
 * the storekeeper, who has done their part.
 *
 * One digest listing every such order, at most once per interval.
 */
class RemindPackedOrdersCommand extends Command
{
    protected $signature = 'dispatch:remind-packed';

    protected $description = 'Remind admin dispatch about orders packed but not handed to a rider';

    public function handle(): int
    {
        $after = (int) config('messaging.packed_reminder.after_hours', 2);
        $every = (int) config('messaging.packed_reminder.every_hours', 3);

        $orders = Order::query()
            ->where('status', 'packed')
            ->where('packed_at', '<=', now()->subHours($after))
            ->with('items.vendor', 'logisticsCompany')
            ->orderBy('packed_at')
            ->get();

        if ($orders->isEmpty()) {
            $this->info('No packed orders waiting.');

            return self::SUCCESS;
        }

        $settings = PlatformMessagingSetting::current();

        if ($settings->packed_reminder_sent_at && $settings->packed_reminder_sent_at->gt(now()->subHours($every))) {
            $this->info('Reminder already sent recently.');

            return self::SUCCESS;
        }

        $lines = $orders->map(fn (Order $order) => '• '.$order->reference.' — '
            .($order->items->first()?->vendor?->name ?? 'store')
            .' · packed '.$order->packed_at->diffForHumans(null, true).' ago'
            .($order->logisticsCompany ? ' · '.$order->logisticsCompany->name : ' · no company assigned'))
            ->implode("\n");

        $title = $orders->count().' packed '.str('order')->plural($orders->count()).' waiting for a rider';

        foreach (DispatchMessages::dispatchStaff() as $user) {
            Notification::make()
                ->title($title)
                ->body($lines)
                ->icon('heroicon-o-clock')
                ->warning()
                ->sendToDatabase($user);
        }

        if (filled($to = config('messaging.ops_number'))) {
            $message = DeliveryMessage::create([
                'vendor_id'      => null,
                'recipient_type' => 'ops',
                'channel'        => 'whatsapp',
                'to_number'      => $to,
                'body'           => "*{$title}*\n\n{$lines}\n\nAssign a logistics company from the dispatch board.",
                'status'         => 'queued',
                'template_key'   => 'dispatch_packed_reminder',
            ]);

            app(MessagingService::class)->dispatch($message);
        }

        $settings->update(['packed_reminder_sent_at' => now()]);

        $this->info($title.'.');

        return self::SUCCESS;
    }
}

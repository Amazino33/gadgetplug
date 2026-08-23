<?php

namespace App\Services\Messaging;

use App\Models\DeliveryMessage;
use App\Models\MessageTemplate;
use App\Models\Vendor;
use App\Models\VendorNotificationSetting;
use App\Services\Reporting\VendorDailySummary;
use Carbon\CarbonInterface;

// Sends the owner's daily trading summary over WhatsApp.
//
// Separate from StorekeeperNotifier because the two answer to different people
// and different switches: the storekeeper gets work to do, the owner gets money
// figures. Sharing one class would mean one toggle and one number governing
// both, which is precisely the coupling that would put takings and margins on
// the shop-floor phone.
class DailySummaryNotifier
{
    public const TEMPLATE_KEY = 'vendor_daily_summary';

    public function __construct(
        private MessagingService $messaging,
        private VendorDailySummary $summary,
    ) {}

    public function send(Vendor $vendor, CarbonInterface $date, bool $force = false): ?DeliveryMessage
    {
        $settings = VendorNotificationSetting::forVendor($vendor);

        if (! $settings->notify_daily_summary || ! $settings->hasOwnerNumber()) {
            return null;
        }

        $data = $this->summary->build($vendor, $date);

        // A day with no trading, no spending and no purchasing has nothing to
        // report. Sending "₦0.00" six times teaches the owner to ignore the
        // message, which costs more than the missing day. --force overrides so a
        // test send still proves the number works on a quiet day.
        if ($data['is_empty'] && ! $force) {
            return null;
        }

        $template = MessageTemplate::query()
            ->where('vendor_id', $vendor->id)
            ->where('key', self::TEMPLATE_KEY)
            ->where('is_active', true)
            ->first();

        if (! $template) {
            return null;
        }

        $message = DeliveryMessage::create([
            'vendor_id'      => $vendor->id,
            'order_id'       => null,
            'recipient_type' => 'owner',
            'channel'        => $template->channel,
            'to_number'      => $settings->owner_whatsapp,
            'body'           => app(TemplateRenderer::class)->render(
                $template->body,
                $this->context($data),
            ),
            'status'         => 'queued',
        ]);

        return $this->messaging->send($message);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, string>
     */
    private function context(array $data): array
    {
        $pnl = $data['pnl'];

        return [
            'store_name'            => $data['vendor']->name,
            'summary_date'          => $data['date']->format('D, j M Y'),

            'store_lines'           => $this->storeLines($data),
            'total_orders'          => (string) $data['totals']['orders'],
            'total_units'           => (string) $data['totals']['units'],

            'cash_taken'            => self::naira($data['payments']['cash']),
            'card_taken'            => self::naira($data['payments']['card']),
            'transfer_taken'        => self::naira($data['payments']['bank_transfer']),
            'pos_taken'             => self::naira($data['payments']['total']),

            'revenue'               => self::naira($pnl['revenue']),
            'product_cost'          => self::naira($pnl['product_cost']),
            'net_profit'            => self::naira($pnl['net_profit']),
            // The P&L leans on today's cost price when a sold line predates cost
            // snapshotting. Saying so beats presenting an estimate as exact.
            'profit_note'           => $pnl['cost_is_estimated']
                ? "\n_Profit is approximate — some sold items have no recorded cost price._"
                : '',

            'expense_lines'         => $this->expenseLines($data['expenses']),
            'expenses_total'        => self::naira($data['expenses']['total']),

            'procurement_count'     => (string) $data['procurement']['count'],
            'procurement_total'     => self::naira($data['procurement']['total_cost']),
            'procurement_paid'      => self::naira($data['procurement']['amount_paid']),
            'procurement_owing'     => self::naira($data['procurement']['outstanding']),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function storeLines(array $data): string
    {
        $lines = [];

        foreach ($data['stores'] as $store) {
            $lines[] = '• '.$store['name'].': '.self::naira($store['revenue'])
                .' ('.$store['orders'].' sale(s), '.$store['units'].' item(s))';
        }

        if ($lines === []) {
            $lines[] = '• No stores set up yet.';
        }

        // Named rather than silently dropped — see VendorDailySummary::build().
        if ($data['unattributed'] > 0.005) {
            $lines[] = '• Not tied to a branch: '.self::naira($data['unattributed']);
        }

        return implode("\n", $lines);
    }

    /**
     * @param  array{total: float, by_category: array<string, float>}  $expenses
     */
    private function expenseLines(array $expenses): string
    {
        if ($expenses['by_category'] === []) {
            return '• None recorded';
        }

        $labels = [
            'advertising'     => 'Advertising',
            'logistics_other' => 'Logistics (other)',
            'other'           => 'Other',
        ];

        $lines = [];

        foreach ($expenses['by_category'] as $category => $amount) {
            $lines[] = '• '.($labels[$category] ?? ucfirst((string) $category)).': '.self::naira($amount);
        }

        return implode("\n", $lines);
    }

    private static function naira(mixed $amount): string
    {
        return '₦'.number_format((float) $amount, 2);
    }
}

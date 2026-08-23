<?php

namespace Database\Seeders;

use App\Models\MessageTemplate;
use App\Models\Vendor;
use Illuminate\Database\Seeder;

class MessageTemplateSeeder extends Seeder
{
    public function run(): void
    {
        Vendor::all()->each(fn (Vendor $vendor) => self::forVendor($vendor));
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public static function defaults(): array
    {
        return [
            [
                'key'            => 'customer_received',
                'recipient_type' => 'customer',
                'channel'        => 'whatsapp',
                'body'           => "Hello {{customer_name}} 👋\n\nThank you for shopping with GadgetPlug! We have received your order *{{order_number}}*.\n\n*Your items*\n{{order_items}}\n\n*Total:* {{total}}\n*Payment:* {{payment_method}}\n*Deliver to:* {{delivery_address}}\n\n{{rider_line}}\n\nWe truly appreciate your business 💚\n— GadgetPlug",
            ],
            [
                'key'            => 'customer_confirmed',
                'recipient_type' => 'customer',
                'channel'        => 'whatsapp',
                'body'           => "Hello {{customer_name}} 👋\n\nGood news — your order *{{order_number}}* is confirmed.\n\n*Your items*\n{{order_items}}\n\n*Total:* {{total}}\n*Deliver to:* {{delivery_address}}\n\nWe will message you again as soon as it is on its way.\n\nThank you for choosing GadgetPlug 💚",
            ],
            [
                'key'            => 'customer_dispatched',
                'recipient_type' => 'customer',
                'channel'        => 'whatsapp',
                'body'           => "Hello {{customer_name}} 👋\n\nYour order *{{order_number}}* is on its way!\n\n*Your items*\n{{order_items}}\n\n*Total:* {{total}}\n*Payment:* {{payment_method}}\n*Deliver to:* {{delivery_address}}\n\n{{rider_line}}\nPlease keep your phone nearby.\n\nThank you for shopping with GadgetPlug 💚",
            ],
            [
                'key'            => 'customer_out_for_delivery',
                'recipient_type' => 'customer',
                'channel'        => 'whatsapp',
                'body'           => "Hello {{customer_name}} 👋\n\nYour order *{{order_number}}* is out for delivery today.\n\n*Your items*\n{{order_items}}\n\n*Total:* {{total}}\n*Deliver to:* {{delivery_address}}\n\n{{rider_line}}\nPlease have someone available to receive it.\n\nThank you for choosing GadgetPlug 💚",
            ],
            [
                'key'            => 'customer_delivered',
                'recipient_type' => 'customer',
                'channel'        => 'whatsapp',
                'body'           => "Hello {{customer_name}} 👋\n\nYour order *{{order_number}}* has been delivered.\n\n*Your items*\n{{order_items}}\n\n*Total:* {{total}}\n\nWe hope you enjoy your purchase. Thank you for shopping with GadgetPlug — we truly appreciate you 💚\n\nWe look forward to serving you again!",
            ],
            // Storekeeper alerts are internal, so they lead with what needs doing
            // rather than a greeting, and carry no customer-facing pleasantries.
            [
                'key'            => 'storekeeper_new_order',
                'recipient_type' => 'storekeeper',
                'channel'        => 'whatsapp',
                'body'           => "🛒 *New order to pack — {{order_number}}*\n\n*Items ({{item_count}})*\n{{order_items}}\n\n*Customer:* {{customer_name}} ({{customer_phone}})\n*Deliver to:* {{delivery_address}}\n*Total:* {{total}}\n*Payment:* {{payment_method}}\n\nPlease pack this and hand it to a rider.\n— {{store_name}}",
            ],
            [
                'key'            => 'storekeeper_undispatched',
                'recipient_type' => 'storekeeper',
                'channel'        => 'whatsapp',
                'body'           => "⏰ *{{order_count}} order(s) still awaiting dispatch*\n\n{{order_list}}\n\nThe oldest has been waiting {{oldest_wait}}.\nPlease follow these up.\n— {{store_name}}",
            ],
            [
                'key'            => 'storekeeper_low_stock',
                'recipient_type' => 'storekeeper',
                'channel'        => 'whatsapp',
                'body'           => "📉 *Low stock — {{product_count}} product(s)*\n\n{{product_list}}\n\nPlease restock or raise a purchase order.\n— {{store_name}}",
            ],
            [
                'key'            => 'storekeeper_cancelled',
                'recipient_type' => 'storekeeper',
                'channel'        => 'whatsapp',
                'body'           => "❌ *Order cancelled — {{order_number}}*\n\n*Items ({{item_count}})*\n{{order_items}}\n\nThis order was cancelled after payment. If it was already packed, please unpack it and return the items to the shelf.\n— {{store_name}}",
            ],
            // Owner-facing, not staff-facing: this carries takings, cost of
            // goods and margin, so it goes to owner_whatsapp and nowhere else.
            [
                'key'            => 'vendor_daily_summary',
                'recipient_type' => 'owner',
                'channel'        => 'whatsapp',
                'body'           => "📊 *Daily Summary — {{summary_date}}*\n{{store_name}}\n\n*Sales by store*\n{{store_lines}}\n\n*Money taken (incl. VAT)*\n• Cash: {{cash_taken}}\n• Card: {{card_taken}}\n• Transfer: {{transfer_taken}}\n• POS total: {{pos_taken}}\n\n*Profit*\n• Revenue (excl. VAT): {{revenue}}\n• Cost of goods: {{product_cost}}\n• Net profit: {{net_profit}}{{profit_note}}\n\n*Expenses recorded*\n{{expense_lines}}\n• Total: {{expenses_total}}\n\n*Procurement recorded*\n• {{procurement_count}} purchase(s), {{procurement_total}}\n• Paid: {{procurement_paid}} · Outstanding: {{procurement_owing}}\n\n— GadgetPlug",
            ],
            [
                'key'            => 'rider_assignment',
                'recipient_type' => 'rider',
                'channel'        => 'whatsapp',
                'body'           => "Hello {{rider_name}} 👋\n\nYou have a new delivery from GadgetPlug.\n\n*Order:* {{order_number}}\n*Customer:* {{customer_name}}\n*Phone:* {{customer_phone}}\n*Deliver to:* {{delivery_address}}\n\n*Items ({{item_count}})*\n{{order_items}}\n\n*Total:* {{total}}\n*Payment:* {{payment_method}}\n\nThank you 💚",
            ],
        ];
    }

    public static function forVendor(Vendor $vendor): void
    {
        foreach (self::defaults() as $template) {
            MessageTemplate::firstOrCreate(
                ['vendor_id' => $vendor->id, 'key' => $template['key']],
                [
                    'recipient_type' => $template['recipient_type'],
                    'channel'        => $template['channel'],
                    'body'           => $template['body'],
                ],
            );
        }
    }
}

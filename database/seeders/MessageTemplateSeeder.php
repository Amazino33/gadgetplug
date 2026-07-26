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
                'key'            => 'customer_confirmed',
                'recipient_type' => 'customer',
                'channel'        => 'whatsapp',
                'body'           => 'Hi {{customer_name}}, your order {{order_number}} ({{total}}) has been confirmed. We will notify you again once it is on its way.',
            ],
            [
                'key'            => 'customer_dispatched',
                'recipient_type' => 'customer',
                'channel'        => 'whatsapp',
                'body'           => 'Hi {{customer_name}}, your order {{order_number}} has been dispatched with {{company_name}}. Your rider {{rider_name}} ({{rider_phone}}) will be in touch.',
            ],
            [
                'key'            => 'customer_out_for_delivery',
                'recipient_type' => 'customer',
                'channel'        => 'whatsapp',
                'body'           => 'Hi {{customer_name}}, your order {{order_number}} is out for delivery to {{delivery_address}}. Please have someone available to receive it.',
            ],
            [
                'key'            => 'customer_delivered',
                'recipient_type' => 'customer',
                'channel'        => 'whatsapp',
                'body'           => 'Hi {{customer_name}}, your order {{order_number}} has been delivered. Thank you for shopping with us!',
            ],
            [
                'key'            => 'rider_assignment',
                'recipient_type' => 'rider',
                'channel'        => 'whatsapp',
                'body'           => 'Hi {{rider_name}}, you have a new delivery: order {{order_number}} for {{customer_name}}, to be delivered to {{delivery_address}}. Order total: {{total}}.',
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

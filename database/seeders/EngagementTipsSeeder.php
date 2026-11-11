<?php

namespace Database\Seeders;

use App\Models\Tip;
use App\Support\Engagement\Moments\CashSalesTodayReached;
use App\Support\Engagement\Moments\DebtorsPageEmptyVisit;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Artisan;

/**
 * Starter tips, ALL AS DRAFTS: nothing shows to anyone until an admin opens
 * Help Centre → In-App Tips and activates it.
 *
 *     php artisan db:seed --class=EngagementTipsSeeder
 *
 * Safe to run again: matched on the internal name, never overwrites a tip an
 * admin has since edited or activated.
 */
class EngagementTipsSeeder extends Seeder
{
    public function run(): void
    {
        Artisan::call('features:sync');

        $tips = [
            [
                'name' => 'Debts: buy on credit',
                'feature_key' => 'customer_debts',
                'title' => 'Did you know customers can buy on credit?',
                'message' => 'Choose Debt (Owing) at checkout in Sales Point, then see exactly who owes you here.',
                'button_label' => 'See who owes you',
                'priority' => 10,
            ],
            [
                'name' => 'Cash submissions: see every handover',
                'feature_key' => 'cash_submissions',
                'title' => 'Did you know you can see every cash submission?',
                'message' => 'Check how much each storekeeper has submitted and what’s still pending.',
                'button_label' => 'View submissions',
                'roles' => [Tip::ROLE_OWNER, 'store_admin', 'inventory_manager'],
                'priority' => 5,
            ],
            [
                'name' => 'Moment: credit after 5 cash sales today',
                'feature_key' => 'customer_debts',
                'title' => 'Did you know customers can buy on credit?',
                'message' => 'Choose Debt (Owing) at checkout in Sales Point, then see exactly who owes you here.',
                'button_label' => 'See who owes you',
                'moment' => CashSalesTodayReached::KEY,
                'moment_threshold' => 5,
                'priority' => 10,
            ],
            [
                'name' => 'Moment: empty debts page, how to record the first',
                'feature_key' => 'customer_debts',
                'title' => 'No debts yet? Here’s how to record one',
                // The POS checkout labels this tender "Debt (Owing)" (PaymentModal.jsx);
                // every seeded tip uses that exact label.
                'message' => 'Ring up the sale in Sales Point and pick the customer. At checkout choose “Debt (Owing)”, and what they owe appears on this page.',
                'button_label' => 'Open Sales Point',
                'button_route' => 'pos.vendor',
                'moment' => DebtorsPageEmptyVisit::KEY,
                'delay_seconds' => 2,
                'priority' => 20,
            ],
            [
                'name' => 'What’s new: Ask GadgetPlug',
                'type' => Tip::TYPE_ANNOUNCEMENT,
                'feature_key' => 'ai_assistant',
                'title' => 'New: ask GadgetPlug about your shop',
                'message' => 'Tap the chat bubble and ask about your sales, stock or debts in plain English.',
                'button_label' => 'Try it now',
            ],
        ];

        foreach ($tips as $attributes) {
            Tip::query()->withTrashed()->firstOrCreate(
                ['name' => $attributes['name']],
                [...$attributes, 'status' => Tip::STATUS_DRAFT, 'panels' => ['vendor']],
            );
        }
    }
}

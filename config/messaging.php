<?php

// How GadgetPlug's automated WhatsApp goes out while it rides a Wawp session
// (an ordinary WhatsApp number linked by QR). Nothing here is hard-coded in
// the sending code: every gap, cap and window can be tuned from .env.
//
// The throttle and brake apply only when the WhatsApp driver in use is Wawp.
// SMS and the other drivers send as before.

return [

    // Our automation WhatsApp number: the checkout success page offers a
    // "Get updates on WhatsApp" chat with it.
    'automation_whatsapp' => env('WHATSAPP_AUTOMATION_NUMBER'),

    // Where operational alerts go by SMS (the WhatsApp brake) and the
    // "packed but not handed over" reminders by WhatsApp.
    'ops_number' => env('MESSAGING_OPS_NUMBER'),

    'throttle' => [
        'enabled' => (bool) env('WHATSAPP_THROTTLE_ENABLED', true),

        // Seconds since the last WhatsApp send before the next may go, picked
        // at random within the range for each message — never a fixed interval.
        'lanes' => [
            1 => [(int) env('WHATSAPP_P1_GAP_MIN', 3), (int) env('WHATSAPP_P1_GAP_MAX', 8)],
            2 => [(int) env('WHATSAPP_P2_GAP_MIN', 10), (int) env('WHATSAPP_P2_GAP_MAX', 25)],
            3 => [(int) env('WHATSAPP_P3_GAP_MIN', 30), (int) env('WHATSAPP_P3_GAP_MAX', 60)],
        ],

        // At least this long between two messages to the same number.
        'per_recipient_gap' => (int) env('WHATSAPP_PER_RECIPIENT_GAP', 30),

        // P3 only goes out inside these hours (business clock).
        'p3_window' => [env('WHATSAPP_P3_FROM', '08:00'), env('WHATSAPP_P3_UNTIL', '20:00')],
        'timezone'  => env('MESSAGING_TIMEZONE', 'Africa/Lagos'),

        // Caps on WhatsApp sends. At a cap, P2 and P3 wait for the next
        // hour/day; P1 is never held back — it goes by SMS instead.
        'hourly_cap' => (int) env('WHATSAPP_HOURLY_CAP', 60),
        'daily_cap'  => (int) env('WHATSAPP_DAILY_CAP', 400),

        // Warm-up: a new number sends less while WhatsApp gets used to it. On
        // by default; raise WHATSAPP_WARMUP_DAILY_CAP over the first weeks,
        // then turn warm-up off to use daily_cap.
        'warmup'           => (bool) env('WHATSAPP_WARMUP', true),
        'warmup_daily_cap' => (int) env('WHATSAPP_WARMUP_DAILY_CAP', 80),

        // Non-urgent messages (P2, P3) to the same number within this window
        // are combined into one.
        'digest_window' => (int) env('WHATSAPP_DIGEST_WINDOW', 120),
    ],

    'brake' => [
        // This many WhatsApp failures in a row pause all WhatsApp sending.
        'consecutive_failures' => (int) env('WHATSAPP_BRAKE_FAILURES', 5),

        // Provider errors that mean the session itself is gone (re-scan needed):
        // the first one pauses sending at once.
        'session_errors' => ['invalid_session', 'session_not_found', 'not_connected'],
    ],

    // Which lane each message goes in, by template key. Anything not listed is P2.
    'lanes' => [
        // P1 — the customer is waiting on it.
        'customer_delivery_code' => 1,   // handed to the rider: the delivery code
        'customer_received'      => 1,   // order confirmation
        'logistics_dispatch'     => 1,   // the rider link

        // P2 — staff alerts and delivered updates (the default).
        'storekeeper_new_order'  => 2,
        'storekeeper_cancelled'  => 2,
        'vendor_new_order'       => 2,
        'customer_delivered'     => 2,

        // P3 — reminders and summaries.
        'storekeeper_undispatched'    => 3,
        'storekeeper_low_stock'       => 3,
        'storekeeper_unremitted_cash' => 3,
        'vendor_daily_summary'        => 3,
        'dispatch_packed_reminder'    => 3,
    ],

    // Admin dispatch: packed orders not handed over for this long are listed
    // in a reminder to dispatch staff, at most once per interval.
    'packed_reminder' => [
        'after_hours' => (int) env('DISPATCH_PACKED_REMINDER_AFTER_HOURS', 2),
        'every_hours' => (int) env('DISPATCH_PACKED_REMINDER_EVERY_HOURS', 3),
    ],
];

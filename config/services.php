<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'paystack' => [
        'public_key' => env('PAYSTACK_PUBLIC_KEY'),
        'secret_key' => env('PAYSTACK_SECRET_KEY'),
    ],

    'telegram' => [
        'token' => env('TELEGRAM_BOT_TOKEN'),
        'chat_id' => env('TELEGRAM_CHAT_ID'),
    ],

    'anthropic' => [
        'key' => env('ANTHROPIC_API_KEY'),
    ],

    'replicate' => [
        'token' => env('REPLICATE_API_TOKEN'),
    ],

    'messaging' => [
        // Blank = auto-select based on which provider below has real credentials.
        'whatsapp_driver' => env('MESSAGING_WHATSAPP_DRIVER'),
        'sms_driver'      => env('MESSAGING_SMS_DRIVER'),

        // The clock the vendor-facing schedule times are read in — quiet hours and
        // the daily summary send time. app.timezone is UTC, so without this a
        // Lagos shopkeeper setting "07:00" would be sent at 08:00 local. Kept
        // separate from app.timezone deliberately: changing that would move every
        // stored timestamp and report boundary in the app, which is a far larger
        // decision than what hour a WhatsApp goes out.
        'timezone' => env('MESSAGING_TIMEZONE', 'Africa/Lagos'),

        // Test-mode safety valve. When set, EVERY delivery message is diverted to
        // this number instead of the real recipient. Leave blank in normal
        // operation — while it is set, no customer receives anything.
        'redirect_all_to' => env('MESSAGING_REDIRECT_ALL_TO'),
    ],

    'termii' => [
        'api_key'   => env('TERMII_API_KEY'),
        'sender_id' => env('TERMII_SENDER_ID'),
    ],

    'whatsapp_cloud' => [
        'token'           => env('WHATSAPP_CLOUD_TOKEN'),
        'phone_number_id' => env('WHATSAPP_CLOUD_PHONE_NUMBER_ID'),
        'api_version'     => env('WHATSAPP_CLOUD_API_VERSION', 'v21.0'),
    ],

    'wawp' => [
        'instance_id'  => env('WAWP_INSTANCE_ID'),
        'access_token' => env('WAWP_ACCESS_TOKEN'),
    ],

    'meta' => [
        'pixel_id'          => env('META_PIXEL_ID'),
        'capi_access_token' => env('META_CAPI_ACCESS_TOKEN'),
        // Set only in Events Manager → Test Events while verifying. Leave
        // unset in production — every event sent with it attached is
        // diverted to the Test Events tab instead of counting toward the
        // real dataset.
        'test_event_code'  => env('META_TEST_EVENT_CODE'),
        'graph_version'    => env('META_GRAPH_VERSION', 'v26.0'),
    ],

];

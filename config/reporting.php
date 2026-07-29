<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Reporting timezone
    |--------------------------------------------------------------------------
    |
    | Timestamps are stored in the app timezone (UTC), but a shopkeeper's
    | "today" is their own wall clock. Nigeria is UTC+1, so without this a sale
    | rung up at 00:30 local would be filed under yesterday and today's total
    | would look wrong for the first hour of every day.
    |
    | Only the boundaries of a reporting period are built in this timezone; the
    | values handed to the database are still converted to the app timezone.
    |
    */

    'timezone' => env('REPORTING_TIMEZONE', 'Africa/Lagos'),

];

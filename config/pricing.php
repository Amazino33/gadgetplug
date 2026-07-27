<?php

return [

    // Global naira cap on profit per unit — engine never suggests a price
    // whose raw profit exceeds this, before rounding.
    'profit_cap' => 50000,

    // Suggested prices round to the nearest multiple of this.
    'rounding_step' => 500,

    // Used when a category has no markup set (categories.markup is null).
    'fallback_markup' => 0.50,

    // Seed values for category markups, keyed by category slug.
    // Categories not listed here keep markup = null and use the fallback.
    'category_markups' => [
        'accessories' => 0.60,  // cables, cases, chargers, screen protectors
        'powerbanks' => 0.58,
        'earphones' => 0.58,
        'audio' => 0.55,  // headphones, speakers, earbuds
        'wearables' => 0.50,  // smartwatches
        'tablets' => 0.48,
        'phones' => 0.45,
        'laptops' => 0.45,
    ],

];

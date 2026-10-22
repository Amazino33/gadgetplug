<?php

return [

    /*
    | A customer's shared location further than this (straight-line, km) from
    | the reference point of the LGA they chose is flagged on the order page
    | and the dispatch board as "Location doesn't match LGA". The fee is never
    | changed by it — that stays the LGA's.
    */
    'location_mismatch_km' => (float) env('DELIVERY_LOCATION_MISMATCH_KM', 15),

];

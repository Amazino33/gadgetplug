<?php

namespace App\Support;

/**
 * Nigeria's 36 states and the FCT — one list for every place a customer or
 * vendor picks a state, so "Akwa Ibom" in an order and in a store profile are
 * the same string.
 */
final class NigerianStates
{
    public const ALL = [
        'Abia', 'Adamawa', 'Akwa Ibom', 'Anambra', 'Bauchi', 'Bayelsa', 'Benue',
        'Borno', 'Cross River', 'Delta', 'Ebonyi', 'Edo', 'Ekiti', 'Enugu',
        'FCT - Abuja', 'Gombe', 'Imo', 'Jigawa', 'Kaduna', 'Kano', 'Katsina',
        'Kebbi', 'Kogi', 'Kwara', 'Lagos', 'Nasarawa', 'Niger', 'Ogun', 'Ondo',
        'Osun', 'Oyo', 'Plateau', 'Rivers', 'Sokoto', 'Taraba', 'Yobe', 'Zamfara',
    ];

    /** "Lagos" reads as "Lagos State"; the FCT is not a state and keeps its name. */
    public static function label(string $state): string
    {
        return str_starts_with($state, 'FCT') ? $state : $state . ' State';
    }
}

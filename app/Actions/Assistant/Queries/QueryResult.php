<?php

declare(strict_types=1);

namespace App\Actions\Assistant\Queries;

use InvalidArgumentException;

class QueryResult
{
    /**
     * Fixed vocabulary of refusal and failure reasons across all assistant queries.
     *
     * @var list<string>
     */
    public const REASONS = [
        'no_cash_ups_yet',
        'store_too_new',
        'no_procurements',
        'no_payouts',
        'no_commissions',
        'no_debts',
        'no_pickers',
        'not_configured',
        'internal_error',
        'no_sales_yet',
        // The user's role does not include this data (BaseAssistantQuery::
        // mayBeAskedBy). Says nothing about whether the data exists.
        'no_access',
    ];

    /**
     * Constructs a query response indicating real data exists.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public static function withData(array $payload): array
    {
        return array_merge($payload, [
            'has_data' => true,
            'reason' => null,
        ]);
    }

    /**
     * Constructs a structured refusal response with a validated reason code.
     *
     * @return array{has_data: false, reason: string}
     *
     * @throws InvalidArgumentException
     */
    public static function empty(string $reason): array
    {
        if (! in_array($reason, self::REASONS, true)) {
            throw new InvalidArgumentException("Invalid query failure reason [{$reason}]. Must be one of: ".implode(', ', self::REASONS));
        }

        return [
            'has_data' => false,
            'reason' => $reason,
        ];
    }
}

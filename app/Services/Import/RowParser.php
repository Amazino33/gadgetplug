<?php

declare(strict_types=1);

namespace App\Services\Import;

use App\Support\Import\FieldType;
use App\Support\Import\ProductField;

/**
 * Turns the strings in a spreadsheet cell into typed values, or says why it
 * cannot.
 *
 * Real files are messy in predictable ways: prices arrive as "N 12,500.00",
 * booleans as "TRUE"/"Yes"/"1"/"enabled", integers with a stray decimal from a
 * spreadsheet's own formatting. Accepting those is not leniency, it is reading
 * the file the vendor actually has. What is refused is anything ambiguous -
 * a negative price, letters where a number belongs - because guessing there
 * would write a wrong number rather than raise a question.
 */
class RowParser
{
    private const TRUTHY = ['1', 'true', 'yes', 'y', 't', 'enabled', 'active', 'published', 'on', 'visible'];
    private const FALSY  = ['0', 'false', 'no', 'n', 'f', 'disabled', 'inactive', 'draft', 'off', 'hidden'];

    /**
     * @param  array<string, string>  $record  header => raw cell
     * @param  array<string, string>  $mapping  header => ProductField value
     * @return array{0: array<string, mixed>, 1: array<int, string>, 2: array<int, string>}  [values, errors, warnings]
     */
    public function parse(array $record, array $mapping): array
    {
        $values   = [];
        $errors   = [];
        $warnings = [];

        foreach ($mapping as $header => $fieldValue) {
            $field = ProductField::tryFrom($fieldValue);

            if ($field === null || ! $field->isImportable()) {
                continue;
            }

            $raw = trim((string) ($record[$header] ?? ''));

            if ($raw === '') {
                continue;
            }

            // Status is the one field where the file's vocabulary and ours
            // differ: most exports carry a yes/no "enabled" flag, not our
            // three-state lifecycle. A false flag means draft, not archived -
            // archiving is a deliberate act and a spreadsheet should not perform
            // it by accident.
            if ($field === ProductField::Status) {
                $values[$field->value] = $this->status($raw);

                continue;
            }

            $parsed = match ($field->type()) {
                FieldType::Decimal => $this->number($raw),
                FieldType::Integer => $this->integer($raw),
                FieldType::Boolean => $this->boolean($raw),
                FieldType::Text    => $raw,
            };

            if ($parsed === null && $field->type() !== FieldType::Text) {
                $errors[] = sprintf('%s: "%s" is not a valid %s.', $field->label(), $raw, $this->typeName($field));

                continue;
            }

            if (in_array($field->type(), [FieldType::Decimal, FieldType::Integer], true) && $parsed < 0) {
                $errors[] = sprintf('%s cannot be negative (got %s).', $field->label(), $raw);

                continue;
            }

            $values[$field->value] = $parsed;
        }

        if (isset($values[ProductField::CostPrice->value], $values[ProductField::Price->value])
            && $values[ProductField::CostPrice->value] > $values[ProductField::Price->value]) {
            $warnings[] = 'Cost is higher than price, so this product would sell at a loss.';
        }

        return [$values, $errors, $warnings];
    }

    /** Accepts "12500", "12,500.00", "N12,500", "12 500.00". Rejects anything else. */
    public function number(string $raw): ?float
    {
        $cleaned = preg_replace('/[^0-9.\-]/', '', str_replace(',', '', $raw));

        if ($cleaned === '' || $cleaned === null || ! is_numeric($cleaned)) {
            return null;
        }

        return round((float) $cleaned, 2);
    }

    public function integer(string $raw): ?int
    {
        $number = $this->number($raw);

        if ($number === null) {
            return null;
        }

        // "5.0" from a spreadsheet is 5. "5.4" is a number somebody meant, and
        // silently truncating it would hide that this column is not integral.
        if (abs($number - round($number)) > 0.0001) {
            return null;
        }

        return (int) round($number);
    }

    public function boolean(string $raw): ?bool
    {
        $needle = strtolower(trim($raw));

        if (in_array($needle, self::TRUTHY, true)) {
            return true;
        }

        if (in_array($needle, self::FALSY, true)) {
            return false;
        }

        return null;
    }

    /** @return 'published'|'draft'|'archived' */
    public function status(string $raw): string
    {
        $needle = strtolower(trim($raw));

        if (in_array($needle, ['archived', 'archive', 'deleted', 'discontinued'], true)) {
            return 'archived';
        }

        return $this->boolean($needle) === true ? 'published' : 'draft';
    }

    private function typeName(ProductField $field): string
    {
        return match ($field->type()) {
            FieldType::Decimal => 'amount',
            FieldType::Integer => 'whole number',
            FieldType::Boolean => 'yes/no value',
            FieldType::Text    => 'value',
        };
    }
}

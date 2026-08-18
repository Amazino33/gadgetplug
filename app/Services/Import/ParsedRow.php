<?php

declare(strict_types=1);

namespace App\Services\Import;

/**
 * One row of the uploaded file, after mapping, casting and validation, with the
 * decision about what it will do.
 *
 * Deliberately inert: building one touches nothing, so the whole file can be
 * prepared and shown to the vendor before anything is written.
 */
class ParsedRow
{
    public const ACTION_CREATE = 'create';
    public const ACTION_UPDATE = 'update';
    public const ACTION_SKIP   = 'skip';

    /**
     * @param  int  $line  1-based line in the source file, header included, so it matches what the vendor sees in Excel
     * @param  array<string, mixed>  $values  ProductField value => cast value
     * @param  array<int, string>  $errors  reasons this row cannot be imported
     * @param  array<int, string>  $warnings  things worth knowing that do not block it
     */
    public function __construct(
        public readonly int $line,
        public readonly array $values,
        public readonly array $errors = [],
        public readonly array $warnings = [],
        public readonly ?int $matchedProductId = null,
        public readonly array $raw = [],
    ) {
    }

    public function action(): string
    {
        if ($this->errors !== []) {
            return self::ACTION_SKIP;
        }

        return $this->matchedProductId === null ? self::ACTION_CREATE : self::ACTION_UPDATE;
    }

    public function isImportable(): bool
    {
        return $this->errors === [];
    }

    public function value(string $field): mixed
    {
        return $this->values[$field] ?? null;
    }

    public function name(): string
    {
        return (string) ($this->values['name'] ?? $this->raw['name'] ?? '(no name)');
    }

    public function withError(string $error): self
    {
        return new self(
            $this->line,
            $this->values,
            [...$this->errors, $error],
            $this->warnings,
            $this->matchedProductId,
            $this->raw,
        );
    }
}

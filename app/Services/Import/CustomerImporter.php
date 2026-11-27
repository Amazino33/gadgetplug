<?php

declare(strict_types=1);

namespace App\Services\Import;

use App\Models\PosCustomer;
use App\Models\PosCustomerLedgerEntry;
use App\Models\Vendor;
use App\Services\Messaging\PhoneNumber;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * Brings a shop's existing customer list in from a spreadsheet.
 *
 * Matching, so the same file can be imported twice without duplicating
 * anyone: by phone first (written the same however it is typed — 0803…,
 * +234803…, 234 803…), then by email, then — only for a row with neither — by
 * exact name. A match is updated, never duplicated.
 *
 * An opening balance (what the customer already owes the shop) is recorded
 * for NEW customers only, as an "Opening balance — import" charge on their
 * account, so it shows under Customer Debts and is paid off like any other
 * debt. An existing customer's balance is never changed by an import.
 */
class CustomerImporter
{
    public const FIELDS = [
        'name'            => ['label' => 'Name',            'aliases' => ['name', 'customer', 'customer name', 'full name', 'client', 'client name', 'company']],
        'phone'           => ['label' => 'Phone',           'aliases' => ['phone', 'phone number', 'mobile', 'mobile number', 'telephone', 'tel', 'whatsapp', 'contact', 'gsm']],
        'email'           => ['label' => 'Email',           'aliases' => ['email', 'e-mail', 'email address', 'mail']],
        'address'         => ['label' => 'Address',         'aliases' => ['address', 'street', 'home address', 'delivery address', 'bill to', 'billing address']],
        'shop_location'   => ['label' => 'Shop / Area',     'aliases' => ['shop location', 'shop', 'area', 'location', 'market', 'city', 'town']],
        'notes'           => ['label' => 'Notes',           'aliases' => ['notes', 'note', 'memo', 'comment', 'comments', 'remarks']],
        'opening_balance' => ['label' => 'Opening Balance', 'aliases' => ['opening balance', 'balance', 'balance due', 'amount owed', 'owed', 'debt', 'outstanding', 'receivable', 'open balance']],
    ];

    /** Rows per write batch: one request each, small enough for shared hosting. */
    public const BATCH = 200;

    public function __construct(
        private readonly SpreadsheetReader $reader,
        private readonly RowParser $parser,
    ) {
    }

    /** @return array<string, string> header => field, for headers it recognises */
    public function guessMapping(array $headers): array
    {
        $mapping = [];
        $taken   = [];

        foreach ($headers as $header) {
            $needle = ColumnMapper::normalise($header);

            foreach (self::FIELDS as $field => $meta) {
                if (in_array($field, $taken, true)) {
                    continue;
                }

                $aliases = array_map(ColumnMapper::normalise(...), [$meta['label'], ...$meta['aliases']]);

                if (in_array($needle, $aliases, true)) {
                    $mapping[$header] = $field;
                    $taken[] = $field;
                    break;
                }
            }
        }

        return $mapping;
    }

    /**
     * Reads the whole file and decides, for every row, what will happen —
     * touching nothing.
     *
     * @param  array<string, string>  $mapping  header => field
     * @return Collection<int, array{line: int, values: array, action: string, customer_id: ?int, errors: array, warnings: array}>
     */
    public function prepare(string $path, array $mapping, Vendor $vendor): Collection
    {
        $mapping = array_filter($mapping, fn ($field) => isset(self::FIELDS[$field]));

        $existing = PosCustomer::where('vendor_id', $vendor->id)->get(['id', 'name', 'phone', 'email']);
        $byPhone = [];
        $byEmail = [];
        $byName  = [];

        foreach ($existing as $c) {
            if ($key = self::phoneKey($c->phone)) $byPhone[$key] ??= $c->id;
            if (filled($c->email)) $byEmail[Str::lower(trim($c->email))] ??= $c->id;
            $byName[Str::lower(Str::squish($c->name))] ??= $c->id;
        }

        $seenPhone = [];
        $rows = collect();

        foreach ($this->reader->records($path) as $line => $record) {
            $values   = [];
            $errors   = [];
            $warnings = [];

            foreach ($mapping as $header => $field) {
                $raw = trim((string) ($record[$header] ?? ''));

                if ($raw !== '') {
                    $values[$field] = $raw;
                }
            }

            $name = Str::squish($values['name'] ?? '');

            if ($name === '') {
                $errors[] = 'Name is missing. Every customer needs one.';
            } else {
                $values['name'] = mb_substr($name, 0, 100);
            }

            if (isset($values['phone'])) {
                // Excel turns long numbers into 2.348E+12; the digits are gone.
                if (preg_match('/^\d+(\.\d+)?e[+-]?\d+$/i', $values['phone'])) {
                    $warnings[] = sprintf('Phone "%s" was shortened by Excel, so it was left out. Format the column as Text and re-enter it.', $values['phone']);
                    unset($values['phone']);
                } else {
                    $values['phone'] = mb_substr(preg_replace('/\.0+$/', '', $values['phone']), 0, 20);
                }
            }

            if (isset($values['email']) && ! filter_var($values['email'], FILTER_VALIDATE_EMAIL)) {
                $warnings[] = sprintf('Email "%s" does not look right, so it was left out.', $values['email']);
                unset($values['email']);
            }

            if (isset($values['opening_balance'])) {
                $amount = $this->parser->number($values['opening_balance']);

                if ($amount === null) {
                    $errors[] = sprintf('Opening Balance "%s" is not an amount.', $values['opening_balance']);
                } elseif ($amount < 0) {
                    $warnings[] = 'A negative opening balance (money held for the customer) is not recorded; only amounts the customer owes are.';
                    unset($values['opening_balance']);
                } else {
                    $values['opening_balance'] = $amount;
                }
            }

            // Matched: phone, then email, then — only with neither — name.
            $phoneKey = self::phoneKey($values['phone'] ?? null);
            $emailKey = isset($values['email']) ? Str::lower($values['email']) : null;

            $matchedId = match (true) {
                $phoneKey !== null && isset($byPhone[$phoneKey]) => $byPhone[$phoneKey],
                $emailKey !== null && isset($byEmail[$emailKey]) => $byEmail[$emailKey],
                $phoneKey === null && $emailKey === null && $name !== '' && isset($byName[Str::lower($name)]) => $byName[Str::lower($name)],
                default => null,
            };

            if ($phoneKey !== null && isset($seenPhone[$phoneKey])) {
                $errors[] = sprintf('Phone "%s" is already on line %d of this file.', $values['phone'], $seenPhone[$phoneKey]);
            } elseif ($phoneKey !== null) {
                $seenPhone[$phoneKey] = $line;
            }

            if ($matchedId !== null && isset($values['opening_balance']) && $values['opening_balance'] > 0) {
                $warnings[] = 'This customer already exists, so the opening balance was not added — an existing balance is never changed by an import.';
            }

            $rows->push([
                'line'        => $line,
                'values'      => $values,
                'customer_id' => $matchedId,
                'action'      => $errors !== [] ? 'skip' : ($matchedId ? 'update' : 'create'),
                'errors'      => $errors,
                'warnings'    => $warnings,
            ]);
        }

        return $rows;
    }

    /**
     * Writes one batch. Each row on its own, so one bad row is named by line
     * and the rest still go in.
     *
     * @return array{created: int, updated: int, balances: int, failures: array}
     */
    public function applyChunk(Collection $rows, Vendor $vendor, ?int $userId, ?int $storeId): array
    {
        $totals = ['created' => 0, 'updated' => 0, 'balances' => 0, 'failures' => []];

        foreach ($rows as $row) {
            if ($row['action'] === 'skip') {
                continue;
            }

            try {
                DB::transaction(function () use ($row, $vendor, $userId, $storeId, &$totals) {
                    $values = $row['values'];
                    $attributes = array_intersect_key($values, array_flip(['name', 'phone', 'email', 'address', 'shop_location', 'notes']));

                    if ($row['action'] === 'update') {
                        $customer = PosCustomer::where('vendor_id', $vendor->id)->find($row['customer_id']);

                        if ($customer) {
                            $customer->fill($attributes)->save();
                            $totals['updated']++;
                        }

                        return;
                    }

                    $customer = PosCustomer::create(['vendor_id' => $vendor->id] + $attributes);
                    $totals['created']++;

                    $balance = (float) ($values['opening_balance'] ?? 0);

                    if ($balance > 0) {
                        PosCustomerLedgerEntry::create([
                            'pos_customer_id' => $customer->id,
                            'vendor_id'       => $vendor->id,
                            'direction'       => PosCustomerLedgerEntry::DIRECTION_CHARGE,
                            'amount'          => round($balance, 2),
                            'source_type'     => 'import',
                            'store_id'        => $storeId,
                            'created_by'      => $userId,
                            'occurred_at'     => \App\Support\Pos\BusinessDate::today(),
                            'description'     => 'Opening balance — import',
                        ]);
                        $totals['balances']++;
                    }
                });
            } catch (Throwable $e) {
                $totals['failures'][] = [
                    'line'   => $row['line'],
                    'name'   => $row['values']['name'] ?? '(no name)',
                    'errors' => ['Could not be saved: '.$e->getMessage()],
                ];
            }
        }

        return $totals;
    }

    /** A phone as one comparable key: "0803 123 4567" and "+2348031234567" match. */
    public static function phoneKey(?string $phone): ?string
    {
        if (blank($phone)) {
            return null;
        }

        // "n/a" or "-" has no digits at all: no key, so it never matches
        // another blank phone.
        $key = PhoneNumber::toNigerianInternational($phone);

        return filled($key) ? $key : null;
    }

    /** The blank template: headers and one example row. */
    public function template(): string
    {
        $directory = storage_path('app/exports');

        if (! is_dir($directory)) {
            mkdir($directory, 0755, true);
        }

        $path = $directory.'/gadgetplug-customer-template-'.now()->format('Y-m-d-His').'.csv';
        $out  = fopen($path, 'w');
        fputcsv($out, array_column(self::FIELDS, 'label'));
        fputcsv($out, ['Mama Nkechi', '08031234567', 'nkechi@example.com', '12 Aka Road, Uyo', 'Itam Market', 'Buys on credit, pays Fridays', '15000']);
        fclose($out);

        return $path;
    }
}

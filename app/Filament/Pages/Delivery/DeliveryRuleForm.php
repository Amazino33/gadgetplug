<?php

namespace App\Filament\Pages\Delivery;

use App\Filament\Pages\DeliverySettings;
use App\Models\DeliveryRule;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Utilities\Get;
use Illuminate\Support\Facades\Validator;

/**
 * The rule fields — rule, fee, expected cost, delivery days — used everywhere a
 * delivery rule is edited (the map's LGA sheet, an LGA row, bulk edit, a
 * state's default), and the one validation every write goes through.
 */
final class DeliveryRuleForm
{
    /** @return array<int, \Filament\Schemas\Components\Component> */
    public static function fields(): array
    {
        return [
            Select::make('mode')
                ->label('Rule')
                ->options(DeliveryRule::LABELS)
                ->required()
                ->selectablePlaceholder(false)
                ->native(false)
                ->live(),

            TextInput::make('fee')
                ->label('Delivery fee')
                ->prefix('₦')
                ->numeric()
                ->minValue(1)
                ->step('any')
                ->required(fn (Get $get): bool => $get('mode') === DeliveryRule::FEE)
                ->visible(fn (Get $get): bool => $get('mode') === DeliveryRule::FEE),

            TextInput::make('expected_cost')
                ->label('What delivery costs us')
                ->helperText('Optional. Used to show fee vs cost on orders.')
                ->prefix('₦')
                ->numeric()
                ->minValue(0)
                ->step('any'),

            TextInput::make('delivery_days')
                ->label('Delivery time')
                ->placeholder('e.g. 2–4 days')
                ->maxLength(60),
        ];
    }

    /**
     * Values as the form holds them, ready for DeliveryRule::setForLga/State.
     *
     * @return array<string, mixed>
     */
    public static function validate(array $values): array
    {
        return Validator::make($values, [
            'mode'          => ['required', 'in:'.implode(',', DeliveryRule::MODES)],
            // A fee on a free / not-delivered rule is ignored (DeliveryRule zeroes it).
            'fee'           => ['nullable', 'numeric', 'min:0', 'required_if:mode,fee'],
            'expected_cost' => ['nullable', 'numeric', 'min:0'],
            'delivery_days' => ['nullable', 'string', 'max:60'],
        ], [
            'fee.required_if' => 'A fee rule needs a fee.',
        ])->after(function ($validator) use ($values) {
            if (($values['mode'] ?? null) === DeliveryRule::FEE && (float) ($values['fee'] ?? 0) <= 0) {
                $validator->errors()->add('fee', 'A fee rule needs a fee above ₦0.');
            }
        })->validate();
    }

    /** A rule as the form fills it. */
    public static function fill(array $rule): array
    {
        return [
            'mode'          => $rule['mode'],
            'fee'           => $rule['mode'] === DeliveryRule::FEE ? $rule['fee'] : null,
            'expected_cost' => $rule['expected_cost'],
            'delivery_days' => $rule['delivery_days'] ?? $rule['days'] ?? null,
        ];
    }

    /** Every delivery write checks the page's own gate, not the button. */
    public static function authorize(): void
    {
        abort_unless(DeliverySettings::canAccess(), 403);
    }
}

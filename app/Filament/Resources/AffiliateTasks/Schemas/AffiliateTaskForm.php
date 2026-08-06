<?php

namespace App\Filament\Resources\AffiliateTasks\Schemas;

use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;

class AffiliateTaskForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Task')
                ->schema([
                    TextInput::make('name')
                        ->required()
                        ->maxLength(255)
                        ->columnSpanFull(),

                    Textarea::make('description')
                        ->rows(2)
                        ->helperText('Shown to the affiliate — explain what to do.')
                        ->columnSpanFull(),

                    Select::make('verification_type')
                        ->options(['auto' => 'Auto (system-verified)', 'manual' => 'Manual (affiliate submits proof)'])
                        ->required()
                        ->live()
                        ->helperText('Auto tasks are checked automatically whenever a sale clears. Manual tasks need an admin to approve or reject each submission.'),

                    Toggle::make('is_active')
                        ->default(true)
                        ->helperText('Inactive tasks accept no new submissions and are skipped by auto-evaluation.'),
                ])
                ->columns(2),

            Section::make('Auto Verification')
                ->schema([
                    Select::make('auto_metric')
                        ->options([
                            'cleared_sales_value'   => 'Lifetime cleared sales value (₦)',
                            'completed_sales_count' => 'Completed sales count',
                        ])
                        ->required(),

                    TextInput::make('auto_target')
                        ->label('Target')
                        ->numeric()
                        ->minValue(0)
                        ->required()
                        ->helperText('The metric must reach this value for the task to auto-complete.'),
                ])
                ->columns(2)
                ->visible(fn (Get $get) => $get('verification_type') === 'auto'),

            Section::make('Reward & Repeatability')
                ->schema([
                    TextInput::make('reward_amount')
                        ->label('Wallet Reward (₦)')
                        ->numeric()
                        ->minValue(0)
                        ->prefix('₦')
                        ->required()
                        ->helperText('Credited to the affiliate\'s available balance immediately on approval/completion — no hold.'),

                    TextInput::make('max_completions_per_affiliate')
                        ->label('Max Completions per Affiliate')
                        ->numeric()
                        ->integer()
                        ->minValue(1)
                        ->placeholder('Unlimited')
                        ->helperText('Leave blank for unlimited repeats.'),

                    TextInput::make('cooldown_days')
                        ->label('Cooldown Between Completions (days)')
                        ->numeric()
                        ->integer()
                        ->minValue(1)
                        ->placeholder('No cooldown')
                        ->helperText('Leave blank if repeats can happen back-to-back, subject only to the completion cap above.'),

                    Toggle::make('counts_toward_level')
                        ->label('Counts Toward Level Progress')
                        ->live()
                        ->helperText('If enabled, completing this task also contributes toward the affiliate\'s level progression.'),

                    TextInput::make('level_progress_value')
                        ->label('Level Progress Value (₦-equivalent)')
                        ->numeric()
                        ->minValue(0)
                        ->prefix('₦')
                        ->required()
                        ->visible(fn (Get $get) => $get('counts_toward_level'))
                        ->helperText('How much this task is "worth" toward the level target, in the same unit as lifetime sales value.'),
                ])
                ->columns(2),
        ]);
    }
}

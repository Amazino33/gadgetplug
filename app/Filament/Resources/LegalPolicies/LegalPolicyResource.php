<?php

namespace App\Filament\Resources\LegalPolicies;

use App\Filament\Resources\LegalPolicies\Pages\CreateLegalPolicy;
use App\Filament\Resources\LegalPolicies\Pages\EditLegalPolicy;
use App\Filament\Resources\LegalPolicies\Pages\ListLegalPolicies;
use App\Models\LegalPolicy;
use BackedEnum;
use Filament\Forms\Components\RichEditor;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class LegalPolicyResource extends Resource
{
    protected static ?string $model = LegalPolicy::class;

    protected static string|null|BackedEnum $navigationIcon = 'heroicon-o-scale';

    protected static ?string $navigationLabel = 'Legal Policies';

    protected static ?int $navigationSort = 15;

    protected static ?string $recordTitleAttribute = 'title';

    public static function getNavigationGroup(): ?string
    {
        return 'Settings';
    }

    public static function canAccess(): bool
    {
        return auth()->user()?->hasRole('super_admin') ?? false;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Policy Details')
                    ->schema([
                        TextInput::make('title')
                            ->required()
                            ->maxLength(255)
                            ->live(onBlur: true)
                            ->afterStateUpdated(function (string $operation, $state, callable $set) {
                                if ($operation === 'create') {
                                    $set('slug', str((string) $state)->slug());
                                }
                            }),

                        TextInput::make('slug')
                            ->required()
                            ->unique(ignoreRecord: true)
                            ->maxLength(255)
                            ->helperText('Used in URL: /legal/{slug} (e.g. privacy-policy, terms-of-service)'),

                        TextInput::make('summary')
                            ->maxLength(255)
                            ->columnSpanFull()
                            ->helperText('A brief summary used for page subtitles and SEO descriptions.'),
                    ])
                    ->columns(2),

                Section::make('Policy Content')
                    ->schema([
                        RichEditor::make('content')
                            ->hiddenLabel()
                            ->required()
                            ->columnSpanFull()
                            ->toolbarButtons([
                                'bold',
                                'italic',
                                'underline',
                                'strike',
                                'link',
                                'h2',
                                'h3',
                                'bulletList',
                                'orderedList',
                                'blockquote',
                                'undo',
                                'redo',
                            ]),
                    ]),

                Section::make('Visibility & Order')
                    ->schema([
                        Toggle::make('is_published')
                            ->label('Published on website')
                            ->default(true)
                            ->helperText('When disabled, this policy will be hidden from visitors.'),

                        TextInput::make('sort_order')
                            ->label('Sort Order')
                            ->numeric()
                            ->integer()
                            ->default(0)
                            ->helperText('Display priority in the website footer (lower numbers appear first).'),
                    ])
                    ->columns(2),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index'  => ListLegalPolicies::route('/'),
            'create' => CreateLegalPolicy::route('/create'),
            'edit'   => EditLegalPolicy::route('/{record}/edit'),
        ];
    }
}

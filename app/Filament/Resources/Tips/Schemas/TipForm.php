<?php

declare(strict_types=1);

namespace App\Filament\Resources\Tips\Schemas;

use App\Models\AppFeature;
use App\Models\Tip;
use App\Support\Engagement\Conditions\ConditionRegistry;
use App\Support\Engagement\FeatureRegistry;
use App\Support\Engagement\PanelDestinations;
use App\Support\Engagement\TipCard;
use App\Support\Engagement\TipMedia;
use Closure;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\RichEditor;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\SpatieMediaLibraryFileUpload;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Spatie\Permission\Models\Role;

class TipForm
{
    /** Form fields the phone preview reads. */
    private const PREVIEW_FIELDS = ['type', 'title', 'message', 'button_label', 'display_style', 'show_not_now', 'delay_seconds'];

    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Grid::make(['default' => 1, 'lg' => 3])
                ->columnSpanFull()
                ->schema([
                    Grid::make(1)
                        ->columnSpan(['default' => 1, 'lg' => 2])
                        ->schema([
                            self::basics(),
                            self::content(),
                            self::button(),
                            self::media(),
                            self::targeting(),
                            self::schedule(),
                        ]),

                    Section::make('Phone preview')
                        ->description('How it looks on a vendor’s phone. New uploads appear here after saving.')
                        ->columnSpan(1)
                        ->extraAttributes(['class' => 'lg:sticky lg:top-24'])
                        ->schema([
                            View::make('filament.admin.tips.phone-preview')
                                ->viewData(fn (Get $get, ?Tip $record): array => [
                                    'card' => TipCard::fromState(
                                        collect(self::PREVIEW_FIELDS)->mapWithKeys(fn (string $f) => [$f => $get($f)])->all(),
                                        $record,
                                    ),
                                ]),
                        ]),
                ]),
        ]);
    }

    private static function basics(): Section
    {
        return Section::make('What it is')
            ->columns(2)
            ->schema([
                TextInput::make('name')
                    ->label('Internal name')
                    ->required()
                    ->maxLength(255)
                    ->helperText('Only admins see this.'),

                Radio::make('type')
                    ->options([
                        Tip::TYPE_TIP => 'Tip',
                        Tip::TYPE_ANNOUNCEMENT => 'What’s new announcement',
                    ])
                    ->descriptions([
                        Tip::TYPE_TIP => 'Teaches something. Hidden from people already using the feature.',
                        Tip::TYPE_ANNOUNCEMENT => 'Shown once to everyone targeted, in the centre, with a “New” badge.',
                    ])
                    ->default(Tip::TYPE_TIP)
                    ->required()
                    ->live(),

                Select::make('feature_key')
                    ->label('Linked feature')
                    ->options(fn (): array => self::featureOptions())
                    ->searchable()
                    ->placeholder('None')
                    ->live()
                    ->helperText('Used to hide the tip from people already using it, to pick the button’s page, and to count who went on to use it.'),

                Toggle::make('hide_if_feature_used')
                    ->label('Only show to people who haven’t used it yet')
                    ->default(true)
                    ->visible(fn (Get $get): bool => filled($get('feature_key')) && $get('type') !== Tip::TYPE_ANNOUNCEMENT),
            ]);
    }

    private static function content(): Section
    {
        return Section::make('What it says')
            ->schema([
                TextInput::make('title')
                    ->required()
                    ->maxLength(120)
                    ->live(onBlur: true)
                    ->helperText('Ask a question: “Did you know you can record debts?”'),

                RichEditor::make('message')
                    ->toolbarButtons(['bold', 'link'])
                    ->live(onBlur: true)
                    ->helperText('One sentence. Bold and links only.'),
            ]);
    }

    private static function button(): Section
    {
        return Section::make('Button')
            ->columns(2)
            ->schema([
                TextInput::make('button_label')
                    ->maxLength(40)
                    ->live(onBlur: true)
                    ->helperText('Say where it goes: “See who owes you”. Leave empty for no button.'),

                Radio::make('button_target')
                    ->label('Goes to')
                    ->options([
                        'feature' => 'The linked feature’s page',
                        'page' => 'A panel page',
                        'url' => 'A custom link',
                    ])
                    ->default('feature')
                    ->live()
                    ->afterStateHydrated(function (Radio $component, ?Tip $record): void {
                        $component->state(match (true) {
                            filled($record?->button_route) => 'page',
                            filled($record?->button_url) => 'url',
                            default => 'feature',
                        });
                    })
                    ->rule(fn (Get $get): Closure => function (string $attribute, $value, Closure $fail) use ($get): void {
                        if ($value === 'feature' && filled($get('button_label')) && blank($get('feature_key'))) {
                            $fail('Link a feature, or pick a page for the button.');
                        }
                    }),

                Select::make('button_route')
                    ->label('Page')
                    ->options(fn (): array => app(PanelDestinations::class)->options())
                    ->searchable()
                    ->visible(fn (Get $get): bool => $get('button_target') === 'page')
                    ->required(fn (Get $get): bool => $get('button_target') === 'page')
                    ->in(fn (): array => collect(app(PanelDestinations::class)->options())->flatMap(fn ($pages) => array_keys($pages))->all())
                    ->helperText('The vendor’s own copy of this page. Anyone who can’t open it never sees the tip.'),

                TextInput::make('button_url')
                    ->label('Link')
                    ->visible(fn (Get $get): bool => $get('button_target') === 'url')
                    ->required(fn (Get $get): bool => $get('button_target') === 'url')
                    ->maxLength(500)
                    ->regex('#^(https://|/(?!/))#')
                    ->validationMessages(['regex' => 'Use an https:// link or a path starting with /.'])
                    ->helperText('Only when no panel page fits. Prefer a page: a link can’t be checked against what the person may open.'),

                Toggle::make('show_not_now')
                    ->label('Show “Not now”')
                    ->default(true)
                    ->live(),
            ]);
    }

    private static function media(): Section
    {
        return Section::make('Picture or video')
            ->description('Most vendors are on mobile data. The poster shows first; the clip loads only when on screen.')
            ->collapsible()
            ->columns(3)
            ->schema([
                SpatieMediaLibraryFileUpload::make('image')
                    ->collection(Tip::IMAGE_COLLECTION)
                    ->image()
                    ->maxSize(1024)
                    ->helperText('Optional. Up to 1 MB.'),

                SpatieMediaLibraryFileUpload::make('video')
                    ->label('Video or GIF')
                    ->collection(Tip::VIDEO_COLLECTION)
                    ->acceptedFileTypes(['video/mp4', 'image/gif'])
                    ->maxSize(TipMedia::MP4_MAX_KB)
                    ->rule(fn (): Closure => function (string $attribute, $value, Closure $fail): void {
                        if ($value instanceof TemporaryUploadedFile
                            && ($problem = TipMedia::videoProblem($value->getMimeType(), (int) $value->getSize()))) {
                            $fail($problem);
                        }
                    })
                    ->helperText('MP4 up to 5 MB (preferred) or GIF up to 3 MB.'),

                SpatieMediaLibraryFileUpload::make('poster')
                    ->collection(Tip::POSTER_COLLECTION)
                    ->image()
                    ->maxSize(512)
                    ->required(fn (Get $get, ?Tip $record): bool => TipMedia::stateHasMp4($get('video'), $record))
                    ->validationMessages(['required' => 'An MP4 needs a poster image (there is no tool on the server to make one).'])
                    ->helperText('Needed for an MP4. A GIF gets one made from its first frame.'),
            ]);
    }

    private static function targeting(): Section
    {
        $conditions = app(ConditionRegistry::class);
        $moments = app(FeatureRegistry::class)->moments();

        return Section::make('Who and when')
            ->columns(2)
            ->schema([
                CheckboxList::make('panels')
                    ->options(['vendor' => 'Vendor panel (owners and staff)', 'admin' => 'Admin panel (for testing)'])
                    ->default(['vendor'])
                    ->required(),

                Select::make('roles')
                    ->multiple()
                    ->options(fn (): array => self::roleOptions())
                    ->placeholder('Everyone')
                    ->helperText('Leave empty for everyone in the panel.'),

                Select::make('condition')
                    ->options($conditions->options())
                    ->default(ConditionRegistry::ALWAYS)
                    ->required()
                    ->live(),

                TextInput::make('condition_value')
                    ->label('N')
                    ->numeric()
                    ->integer()
                    ->minValue(1)
                    ->visible(fn (Get $get): bool => (bool) $conditions->find($get('condition'))?->needsValue())
                    ->required(fn (Get $get): bool => (bool) $conditions->find($get('condition'))?->needsValue()),

                Select::make('moment')
                    ->label('Show right after')
                    ->options(collect($moments)->map(fn ($m) => $m->label())->all())
                    ->placeholder('Any page load (not a moment tip)')
                    ->live()
                    ->visible(fn (Get $get): bool => $get('type') !== Tip::TYPE_ANNOUNCEMENT)
                    ->helperText('A moment tip only shows right after the person does this, and comes before ordinary tips.'),

                TextInput::make('moment_threshold')
                    ->label('N')
                    ->numeric()
                    ->integer()
                    ->minValue(1)
                    ->visible(fn (Get $get): bool => (bool) ($moments[$get('moment')] ?? null)?->hasThreshold())
                    ->required(fn (Get $get): bool => (bool) ($moments[$get('moment')] ?? null)?->hasThreshold()),
            ]);
    }

    private static function schedule(): Section
    {
        return Section::make('Status and frequency')
            ->columns(3)
            ->schema([
                Select::make('status')
                    ->options([
                        Tip::STATUS_DRAFT => 'Draft',
                        Tip::STATUS_ACTIVE => 'Active',
                        Tip::STATUS_PAUSED => 'Paused',
                    ])
                    ->default(Tip::STATUS_DRAFT)
                    ->required(),

                DateTimePicker::make('starts_at')->label('Start')->seconds(false),
                DateTimePicker::make('ends_at')->label('End')->seconds(false)->after('starts_at'),

                TextInput::make('priority')
                    ->numeric()->integer()->default(0)->required()
                    ->helperText('Higher wins when two tips could show.'),

                TextInput::make('delay_seconds')
                    ->label('Delay (seconds)')
                    ->numeric()->integer()->minValue(1)->maxValue(60)->default(4)->required()
                    ->live(onBlur: true),

                Select::make('display_style')
                    ->label('Style')
                    ->options([
                        Tip::STYLE_SHEET => 'Bottom sheet (phone) / corner card (desktop)',
                        Tip::STYLE_MODAL => 'Centre modal',
                    ])
                    ->placeholder('Default for the type')
                    ->live(),

                Select::make('frequency')
                    ->options([
                        Tip::FREQUENCY_ONCE => 'Once',
                        Tip::FREQUENCY_TIMES => 'Up to N times',
                        Tip::FREQUENCY_UNTIL_CLICKED => 'Until they tap it',
                    ])
                    ->default(Tip::FREQUENCY_ONCE)
                    ->required()
                    ->live()
                    ->visible(fn (Get $get): bool => $get('type') !== Tip::TYPE_ANNOUNCEMENT),

                TextInput::make('max_views')
                    ->label('N times')
                    ->numeric()->integer()->minValue(1)
                    ->visible(fn (Get $get): bool => $get('frequency') === Tip::FREQUENCY_TIMES && $get('type') !== Tip::TYPE_ANNOUNCEMENT)
                    ->required(fn (Get $get): bool => $get('frequency') === Tip::FREQUENCY_TIMES && $get('type') !== Tip::TYPE_ANNOUNCEMENT),

                TextInput::make('cooldown_days')
                    ->label('Days between repeats')
                    ->numeric()->integer()->minValue(0)
                    ->placeholder('Platform default')
                    ->visible(fn (Get $get): bool => $get('type') !== Tip::TYPE_ANNOUNCEMENT),

                TextInput::make('adoption_window_days')
                    ->label('Counts as “used it” within (days)')
                    ->numeric()->integer()->minValue(1)
                    ->placeholder('Platform default'),

                Toggle::make('show_on_form_pages')
                    ->label('Allow on create/edit screens')
                    ->default(false),
            ]);
    }

    /** @return array<string, string> */
    private static function featureOptions(): array
    {
        $titles = AppFeature::query()->where('is_registered', true)->pluck('title', 'key');

        return collect(app(FeatureRegistry::class)->all())
            ->map(fn ($feature, string $key) => $titles[$key] ?? $feature->defaultTitle())
            ->all();
    }

    /** @return array<string, string> */
    private static function roleOptions(): array
    {
        $names = Role::query()->whereNotNull('team_id')->distinct()->orderBy('name')->pluck('name');

        return [Tip::ROLE_OWNER => 'Owner']
            + $names->mapWithKeys(fn (string $name) => [$name => str($name)->replace('_', ' ')->ucfirst()->toString()])->all();
    }
}

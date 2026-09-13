<?php

namespace App\Filament\Resources\HelpArticles;

use App\Filament\Resources\HelpArticles\Pages\CreateHelpArticle;
use App\Filament\Resources\HelpArticles\Pages\EditHelpArticle;
use App\Filament\Resources\HelpArticles\Pages\ListHelpArticles;
use App\Models\HelpArticle;
use App\Models\HelpCategory;
use App\Support\Tours\TourRegistry;
use BackedEnum;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\RichEditor;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Callout;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Schema;

// The guides vendors read. Written here, never in the repo, so a wording fix
// after a support call does not need a deploy.
class HelpArticleResource extends Resource
{
    protected static ?string $model = HelpArticle::class;

    protected static string|null|BackedEnum $navigationIcon = 'heroicon-o-book-open';

    protected static ?string $navigationLabel = 'Help Articles';

    protected static ?int $navigationSort = 1;

    protected static ?string $recordTitleAttribute = 'title';

    public static function getNavigationGroup(): ?string
    {
        return 'Help Centre';
    }

    public static function canAccess(): bool
    {
        return auth()->user()?->hasRole('super_admin') ?? false;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Callout::make('Write it the way you would say it over the phone')
                    ->info()
                    ->schema([
                        Text::make(
                            'Number every step. One instruction per line. Put a screenshot under any step where '
                            .'the vendor has to find something on screen, and a short GIF where they have to do '
                            .'something in sequence. Vendors read these on mobile data, so keep GIFs under a '
                            .'second or two and prefer a screenshot where one will do. If a walls-of-text '
                            .'paragraph is forming, it is probably three numbered steps.'
                        ),
                    ])
                    ->columnSpanFull(),

                Section::make('Article')
                    ->schema([
                        TextInput::make('title')
                            ->required()
                            ->maxLength(255)
                            ->columnSpanFull()
                            ->helperText('Phrase it as the question a vendor would ask, e.g. "How do I record a procurement?".'),

                        Select::make('help_category_id')
                            ->label('Category')
                            ->relationship('category', 'name')
                            ->required()
                            ->searchable()
                            ->preload()
                            ->createOptionForm([
                                TextInput::make('name')->required()->maxLength(255),
                                TextInput::make('sort_order')->numeric()->integer()->default(0),
                            ])
                            ->createOptionUsing(fn (array $data): int => HelpCategory::create($data)->getKey()),

                        Select::make('tour_key')
                            ->label('Linked tour')
                            ->options(TourRegistry::options())
                            ->searchable()
                            ->placeholder('None')
                            ->helperText('Puts a "Start tour" button on this article, which walks the vendor through the real screens.'),

                        Textarea::make('excerpt')
                            ->rows(2)
                            ->maxLength(500)
                            ->columnSpanFull()
                            ->helperText('One sentence, shown under the title in the article list. Skip it and the list shows nothing.'),
                    ])
                    ->columns(2),

                Section::make('Body')
                    ->schema([
                        RichEditor::make('body')
                            ->hiddenLabel()
                            ->columnSpanFull()
                            ->helperText(
                                fn (?HelpArticle $record): string => $record?->exists
                                    ? 'Drop screenshots and GIFs straight into the text. They are stored with the article and load lazily for the vendor.'
                                    : 'Save the article once and the image button turns on. Pictures attach to a saved article, so there has to be one first.'
                            ),
                    ]),

                Section::make('Publishing')
                    ->schema([
                        Toggle::make('is_published')
                            ->label('Published')
                            ->live()
                            ->default(false)
                            ->helperText('Off means draft: only you can see it. Vendors see nothing until this is on.'),

                        DateTimePicker::make('published_at')
                            ->label('Publish from')
                            ->seconds(false)
                            ->helperText('Optional. A future time keeps it hidden until then; blank means as soon as it is published.'),

                        TextInput::make('sort_order')
                            ->numeric()
                            ->integer()
                            ->default(0)
                            ->required()
                            ->helperText('Position within its category. Lower comes first.'),
                    ])
                    ->columns(3),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListHelpArticles::route('/'),
            'create' => CreateHelpArticle::route('/create'),
            'edit' => EditHelpArticle::route('/{record}/edit'),
        ];
    }
}

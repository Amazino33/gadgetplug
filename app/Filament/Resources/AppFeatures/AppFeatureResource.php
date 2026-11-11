<?php

declare(strict_types=1);

namespace App\Filament\Resources\AppFeatures;

use App\Filament\Resources\AppFeatures\Pages\EditAppFeature;
use App\Filament\Resources\AppFeatures\Pages\ListAppFeatures;
use App\Models\AppFeature;
use App\Models\HelpArticle;
use App\Policies\TipPolicy;
use App\Support\Engagement\TipFeedback;
use App\Support\Engagement\TipMedia;
use App\Support\Tours\TourRegistry;
use BackedEnum;
use Closure;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\SpatieMediaLibraryFileUpload;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;

/**
 * What vendors read about each feature on the Learn page and in tips. The
 * rows come from the code registry (`php artisan features:sync`); only the
 * words, media, order and visibility are edited here.
 */
class AppFeatureResource extends Resource
{
    protected static ?string $model = AppFeature::class;

    protected static string|null|BackedEnum $navigationIcon = 'heroicon-o-academic-cap';

    protected static ?string $navigationLabel = 'App Features';

    protected static ?string $modelLabel = 'app feature';

    protected static ?int $navigationSort = 11;

    protected static ?string $recordTitleAttribute = 'title';

    public static function getNavigationGroup(): ?string
    {
        return 'Help Centre';
    }

    public static function canAccess(): bool
    {
        return TipPolicy::canManage(auth()->user());
    }

    public static function shouldRegisterNavigation(): bool
    {
        return static::canAccess();
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->where('is_registered', true);
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('What vendors read')
                ->schema([
                    TextInput::make('title')->required()->maxLength(120),
                    Textarea::make('description')
                        ->rows(3)
                        ->maxLength(300)
                        ->helperText('One or two short sentences, in plain English.'),
                    Toggle::make('is_visible')
                        ->label('Listed on the Learn page')
                        ->default(true),
                ]),

            Section::make('Video or GIF')
                ->description('Most vendors are on mobile data. The poster shows first; the clip loads only when on screen.')
                ->columns(2)
                ->schema([
                    SpatieMediaLibraryFileUpload::make('video')
                        ->label('Video or GIF')
                        ->collection(AppFeature::VIDEO_COLLECTION)
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
                        ->collection(AppFeature::POSTER_COLLECTION)
                        ->image()
                        ->maxSize(512)
                        ->required(fn (Get $get, ?AppFeature $record): bool => TipMedia::stateHasMp4($get('video'), $record))
                        ->validationMessages(['required' => 'An MP4 needs a poster image (there is no tool on the server to make one).'])
                        ->helperText('Needed for an MP4. A GIF gets one made from its first frame.'),
                ]),

            Section::make('Links into Help & Guides')
                ->columns(2)
                ->schema([
                    Select::make('help_article_slug')
                        ->label('Guide')
                        ->options(fn (): array => HelpArticle::query()->published()->orderBy('title')->pluck('title', 'slug')->all())
                        ->searchable()
                        ->placeholder('None'),
                    Select::make('tour_key')
                        ->label('Tour')
                        ->options(TourRegistry::options())
                        ->placeholder('None'),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('sort_order')
            ->reorderable('sort_order')
            ->paginated(false)
            ->columns([
                TextColumn::make('title')
                    ->description(fn (AppFeature $record): ?string => $record->description)
                    ->wrap(),
                TextColumn::make('key')->color('gray')->fontFamily('mono')->toggleable(),
                ToggleColumn::make('is_visible')->label('Listed'),
                TextColumn::make('media')
                    ->label('Video')
                    ->state(fn (AppFeature $record): string => $record->getFirstMedia(AppFeature::VIDEO_COLLECTION) ? 'Yes' : '–'),
                TextColumn::make('helpful')
                    ->label('👍')
                    ->state(function (AppFeature $record): string {
                        $f = TipFeedback::forFeature($record->key);

                        return $f['percent'] === null ? '–' : "{$f['percent']}% ({$f['yes']}/".($f['yes'] + $f['no']).')';
                    }),
            ])
            ->recordActions([
                EditAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListAppFeatures::route('/'),
            'edit' => EditAppFeature::route('/{record}/edit'),
        ];
    }
}

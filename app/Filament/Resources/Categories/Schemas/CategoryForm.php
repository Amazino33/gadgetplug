<?php

namespace App\Filament\Resources\Categories\Schemas;

use Filament\Schemas\Schema;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Toggle;
use Illuminate\Support\Str;
use Filament\Schemas\Components\Utilities\Set;
use App\Models\Category;

class CategoryForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->schema([
            TextInput::make('name')
                ->required()
                ->live(onBlur: true)
                ->afterStateUpdated(fn (string $operation, $state, Set $set) => $operation === 'create' ? $set('slug', Str::slug($state)) : null),            TextInput::make('slug')
                ->required()
                ->unique(Category::class, 'slug', ignoreRecord: true),
            Textarea::make('description')
                ->columnSpanFull(),
            Toggle::make('is_active')
                ->default(true),
        ]);
    }
}

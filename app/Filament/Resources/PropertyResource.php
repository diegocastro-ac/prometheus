<?php

namespace App\Filament\Resources;

use App\Contracts\CurrentUserContextInterface;
use App\Filament\Resources\PropertyResource\Pages;
use App\Filament\Resources\PropertyResource\RelationManagers;
use App\Models\Property;
use Filament\Forms;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Section;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Infolist;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Illuminate\Validation\Rule;

class PropertyResource extends Resource
{
    protected static ?string $model = Property::class;

    protected static ?string $slug = 'administration/properties';

    protected static ?int $navigationSort = 0;

    protected static ?string $navigationIcon = 'heroicon-o-rectangle-stack';

    private static ?CurrentUserContextInterface $userContext = null;

    public static function getUserContext(): CurrentUserContextInterface
    {
        if (self::$userContext === null) {
            self::$userContext = app(CurrentUserContextInterface::class);
        }
        return self::$userContext;
    }

    public static function getNavigationGroup(): ?string
    {
        return __('property.navigation.group');
    }

    public static function getNavigationLabel(): string
    {
        return __('property.navigation.labels.plural');
    }

    public static function getModelLabel(): string
    {
        return __('property.navigation.labels.singular');
    }

    public static function getPluralModelLabel(): string
    {
        return __('property.navigation.labels.plural');
    }

    public static function form(Form $form): Form
    {
        return $form
            ->schema([

                Forms\Components\TextInput::make('name')
                    ->label(__('property.form.name'))
                    ->required()
                    ->maxLength(50),
                Forms\Components\TextInput::make('address')
                    ->label(__('property.form.address'))
                    ->required()
                    ->maxLength(50)
                    ->rule(
                        fn(Get $get) => Rule::unique('properties', 'address')
                            ->where(fn($query) => $query->where('user_id', self::getUserContext()->id()))
                            ->ignore($get('id'))
                    ),
                Forms\Components\Textarea::make('description')
                    ->label(__('property.form.description'))
                    ->columnSpan('full')
                    ->autosize()
                    ->maxLength(255),
                Hidden::make('user_id')
                    ->default(fn() => self::getUserContext()->id()),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->label(__('property.table.columns.name'))
                    ->searchable()
                    ->sortable()
                    ->limit(15),
                Tables\Columns\TextColumn::make('address')
                    ->label(__('property.table.columns.address'))
                    ->searchable()
                    ->sortable()
                    ->limit(15),
                Tables\Columns\TextColumn::make('description')
                    ->label(__('property.table.columns.description'))
                    ->searchable()
                    ->limit(30),
                Tables\Columns\TextColumn::make('created_at')
                    ->label(__('property.table.columns.created_at'))
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('updated_at')
                    ->label(__('property.table.columns.updated_at'))
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                //
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist
            ->schema([
                TextEntry::make('name')
                    ->label(__('property.infolist.name')),
                TextEntry::make('address')
                    ->label(__('property.infolist.address')),
                TextEntry::make('description')
                    ->label(__('property.infolist.description')),
                TextEntry::make('created_at')
                    ->label(__('property.infolist.created_at'))
                    ->dateTime(),
                TextEntry::make('updated_at')
                    ->label(__('property.infolist.updated_at'))
                    ->dateTime(),
            ])
            ->columns(1)
            ->inlineLabel();
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ManageProperties::route('/'),
        ];
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->where('user_id', self::getUserContext()->id());
    }
}

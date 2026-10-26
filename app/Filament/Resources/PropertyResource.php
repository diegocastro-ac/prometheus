<?php

namespace App\Filament\Resources;

use App\Contracts\CurrentUserContextInterface;
use App\Filament\Resources\PropertyResource\Pages;
use App\Models\Geography;
use App\Models\Property;
use App\Settings\AppSettings;
use Filament\Forms;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Infolist;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\Rule;

class PropertyResource extends Resource
{
    protected static ?string $model = Property::class;

    protected static ?string $slug = 'administration/properties';

    protected static ?int $navigationSort = 0;

    protected static ?string $navigationIcon = 'heroicon-o-rectangle-stack';

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
                        fn (Get $get) => Rule::unique('properties', 'address')
                            ->where(fn ($query) => $query->where('user_id', app(CurrentUserContextInterface::class)->id()))
                            ->ignore($get('id'))
                    ),
                Forms\Components\Textarea::make('description')
                    ->label(__('property.form.description'))
                    ->columnSpan('full')
                    ->autosize()
                    ->maxLength(255),

                // La ciudad es el unico dato de la propiedad que alimenta el
                // calculo de los reajustes. Por eso es required y por eso
                // ofrece el catalogo COMPLETO, no solo las 23 ciudades con IPC.
                Forms\Components\Select::make('geography_code')
                    ->label(__('property.form.geography_code'))
                    ->helperText(__('property.form.geography_code_help'))
                    ->options(fn (): array => Geography::locationOptions())
                    // Buscable porque el catalogo es del orden de mil entradas
                    // y nadie las recorre una a una.
                    ->searchable()
                    ->native(false)
                    ->required()
                    // Precarga la ciudad predeterminada de Ajustes. Si no hay
                    // ninguna, el campo queda vacio: el sistema no adivina la
                    // ubicacion de un inmueble.
                    ->default(fn (): ?string => app(AppSettings::class)->defaultCityCode())
                    ->columnSpanFull(),

                Hidden::make('user_id')
                    ->default(fn () => app(CurrentUserContextInterface::class)->id()),
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
                Tables\Columns\TextColumn::make('geography.name')
                    ->label(__('property.table.columns.geography'))
                    ->placeholder(__('property.table.columns.geography_none'))
                    ->badge()
                    ->color(fn (?string $state): string => $state === null ? 'gray' : 'primary')
                    ->sortable(),
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
                TextEntry::make('geography.label')
                    ->label(__('property.infolist.geography'))
                    ->placeholder(__('property.table.columns.geography_none')),
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
            ->where('user_id', app(CurrentUserContextInterface::class)->id());
    }
}

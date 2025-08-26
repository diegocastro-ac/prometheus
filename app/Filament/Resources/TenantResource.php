<?php

namespace App\Filament\Resources;

use App\Filament\Resources\TenantResource\Pages;
use App\Filament\Resources\TenantResource\RelationManagers;
use App\Models\Tenant;
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
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

class TenantResource extends Resource
{
    protected static ?string $model = Tenant::class;

    protected static ?string $slug = 'administration/tenants';

    protected static ?int $navigationSort = 1;

    protected static ?string $navigationIcon = 'heroicon-o-users';

    public static function getNavigationGroup(): ?string
    {
        return __('tenant.navigation.group');
    }

    public static function getNavigationLabel(): string
    {
        return __('tenant.navigation.labels.plural');
    }

    public static function getModelLabel(): string
    {
        return __('tenant.navigation.labels.singular');
    }

    public static function getPluralModelLabel(): string
    {
        return __('tenant.navigation.labels.plural');
    }

    public static function form(Form $form): Form
    {
        return $form
            ->schema([

                Forms\Components\TextInput::make('document')
                    ->label(__('tenant.form.document'))
                    ->required()
                    ->maxLength(15)
                    ->rule(
                        fn(Get $get) => Rule::unique('tenants', 'document')
                            ->where(fn($query) => $query->where('user_id', Auth::id()))
                            ->ignore($get('id'))
                    ),
                Forms\Components\TextInput::make('name')
                    ->label(__('tenant.form.name'))
                    ->required()
                    ->maxLength(50),
                Forms\Components\TextInput::make('phone_number')
                    ->label(__('tenant.form.phone_number'))
                    ->required()
                    ->maxLength(15)
                    ->rule(
                        fn(Get $get) => Rule::unique('tenants', 'phone_number')
                            ->where(fn($query) => $query->where('user_id', Auth::id()))
                            ->ignore($get('id'))
                    ),
                Forms\Components\TextInput::make('email')
                    ->label(__('tenant.form.email'))
                    ->maxLength(100)
                    ->rule(
                        fn(Get $get) => Rule::unique('tenants', 'email')
                            ->where(fn($query) => $query->where('user_id', Auth::id()))
                            ->ignore($get('id'))
                    ),
                Hidden::make('user_id')
                    ->default(fn() => Auth::id()),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('document')
                    ->label(__('tenant.table.columns.document'))
                    ->searchable()
                    ->sortable()
                    ->limit(15),
                Tables\Columns\TextColumn::make('name')
                    ->label(__('tenant.table.columns.name'))
                    ->searchable()
                    ->sortable()
                    ->limit(15),
                Tables\Columns\TextColumn::make('phone_number')
                    ->label(__('tenant.table.columns.phone_number'))
                    ->searchable()
                    ->limit(15),
                Tables\Columns\TextColumn::make('email')
                    ->label(__('tenant.table.columns.email'))
                    ->searchable()
                    ->sortable()
                    ->limit(30),
                Tables\Columns\TextColumn::make('created_at')
                    ->label(__('tenant.table.columns.created_at'))
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('updated_at')
                    ->label(__('tenant.table.columns.updated_at'))
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
                TextEntry::make('document')
                    ->label(__('tenant.infolist.document')),
                TextEntry::make('name')
                    ->label(__('tenant.infolist.name')),
                TextEntry::make('phone_number')
                    ->label(__('tenant.infolist.phone_number')),
                TextEntry::make('email')
                    ->label(__('tenant.infolist.email')),
                TextEntry::make('created_at')
                    ->label(__('tenant.infolist.created_at'))
                    ->dateTime(),
                TextEntry::make('updated_at')
                    ->label(__('tenant.infolist.updated_at'))
                    ->dateTime(),
            ])
            ->columns(1)
            ->inlineLabel();
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ManageTenants::route('/'),
        ];
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->where('user_id', Auth::id());
    }
}

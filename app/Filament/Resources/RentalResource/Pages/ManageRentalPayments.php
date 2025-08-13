<?php

namespace App\Filament\Resources\RentalResource\Pages;

use App\Filament\Resources\RentalResource;
use Filament\Actions;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Pages\ManageRelatedRecords;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Infolists\Infolist;
use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\TextEntry;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Illuminate\Support\Facades\Auth;
use Filament\Forms\Components\Hidden;
use Illuminate\Contracts\Support\Htmlable;

class ManageRentalPayments extends ManageRelatedRecords
{
    protected static string $resource = RentalResource::class;

    protected static string $relationship = 'payments';

    protected static ?string $navigationIcon = 'heroicon-o-banknotes';

    public function getTitle(): string | Htmlable
    {
        $recordTitle = $this->getRecordTitle();

        $recordTitle = $recordTitle instanceof Htmlable ? $recordTitle->toHtml() : $recordTitle;

        return "Manage {$recordTitle} Payments";
    }

    public function getBreadcrumb(): string
    {
        return 'Payments';
    }

    public static function getNavigationLabel(): string
    {
        return 'Manage Payments';
    }

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\DatePicker::make('date')
                    ->required(),
                Forms\Components\TextInput::make('amount')
                    ->required()
                    ->numeric()
                    ->rules(['numeric', 'min:0']),
                Forms\Components\Toggle::make('is_rent_paid')
                    ->default(false)
                    ->required(),
                Forms\Components\Toggle::make('is_water_paid')
                    ->default(false)
                    ->required(),
                Forms\Components\Toggle::make('is_energy_paid')
                    ->default(false)
                    ->required(),
                Forms\Components\Toggle::make('is_gas_paid')
                    ->default(false)
                    ->required(),
                Hidden::make('user_id')
                    ->default(fn() => Auth::id()),
            ])
            ->columns(2);
    }

    public function infolist(Infolist $infolist): Infolist
    {
        return $infolist
            ->schema([
                TextEntry::make('date')
                    ->date(),
                TextEntry::make('amount')
                    ->numeric(),
                IconEntry::make('is_rent_paid')
                    ->boolean(),
                IconEntry::make('is_water_paid')
                    ->boolean(),
                IconEntry::make('is_energy_paid')
                    ->boolean(),
                IconEntry::make('is_gas_paid')
                    ->boolean(),
                TextEntry::make('rental.name'),
                TextEntry::make('created_at')
                    ->dateTime(),
                TextEntry::make('updated_at')
                    ->dateTime(),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('date')
            ->columns([
                Tables\Columns\TextColumn::make('date')
                    ->date()
                    ->sortable(),
                Tables\Columns\TextColumn::make('amount')
                    ->numeric()
                    ->sortable(),
                Tables\Columns\IconColumn::make('is_rent_paid')
                    ->boolean(),
                Tables\Columns\IconColumn::make('is_water_paid')
                    ->boolean(),
                Tables\Columns\IconColumn::make('is_energy_paid')
                    ->boolean(),
                Tables\Columns\IconColumn::make('is_gas_paid')
                    ->boolean(),
                Tables\Columns\TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('updated_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                //
            ])
            ->headerActions([
                Tables\Actions\CreateAction::make(),
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

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->where('user_id', Auth::id());
    }
}

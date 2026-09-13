<?php

namespace App\Filament\Resources\RentalResource\Pages;

use App\Enums\PaymentStatus;
use App\Filament\Resources\RentalResource;
use App\Models\Payment;
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

        return __('payments.navigation.pages.manage_title', ['title' => $recordTitle]);
    }

    public function getBreadcrumb(): string
    {
        return __('payments.navigation.pages.breadcrumb');
    }

    public static function getNavigationLabel(): string
    {
        return __('payments.navigation.manage');
    }

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\DatePicker::make('date')
                    ->label(__('payments.form.date'))
                    ->required(),
                Forms\Components\TextInput::make('amount')
                    ->label(__('payments.form.amount'))
                    ->required()
                    ->numeric()
                    ->rules(['numeric', 'min:0']),
                Forms\Components\Toggle::make('is_rent_paid')
                    ->label(__('payments.form.is_rent_paid'))
                    ->default(false)
                    ->required(),
                Forms\Components\Toggle::make('is_water_paid')
                    ->label(__('payments.form.is_water_paid'))
                    ->default(false)
                    ->required(),
                Forms\Components\Toggle::make('is_energy_paid')
                    ->label(__('payments.form.is_energy_paid'))
                    ->default(false)
                    ->required(),
                Forms\Components\Toggle::make('is_gas_paid')
                    ->label(__('payments.form.is_gas_paid'))
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
                TextEntry::make('status')
                    ->label(__('payments.infolist.status'))
                    ->badge()
                    ->state(fn(Payment $record) => $record->status()->value)
                    ->color(fn(string $state): string => match ($state) {
                        PaymentStatus::PAID->value => 'success',
                        PaymentStatus::PARTIAL->value => 'info',
                        PaymentStatus::PENDING->value => 'warning',
                        PaymentStatus::OVERDUE->value => 'danger',
                        default => 'gray',
                    })
                    ->formatStateUsing(fn(string $state): string => match ($state) {
                        PaymentStatus::PAID->value => __('payments.status.paid'),
                        PaymentStatus::PARTIAL->value => __('payments.status.partial'),
                        PaymentStatus::PENDING->value => __('payments.status.pending'),
                        PaymentStatus::OVERDUE->value => __('payments.status.overdue'),
                        default => $state,
                    }),
                TextEntry::make('date')
                    ->label(__('payments.infolist.date'))
                    ->date(),
                TextEntry::make('amount')
                    ->label(__('payments.infolist.amount'))
                    ->numeric(),
                IconEntry::make('is_rent_paid')
                    ->label(__('payments.infolist.is_rent_paid'))
                    ->boolean(),
                IconEntry::make('is_water_paid')
                    ->label(__('payments.infolist.is_water_paid'))
                    ->boolean(),
                IconEntry::make('is_energy_paid')
                    ->label(__('payments.infolist.is_energy_paid'))
                    ->boolean(),
                IconEntry::make('is_gas_paid')
                    ->label(__('payments.infolist.is_gas_paid'))
                    ->boolean(),
                TextEntry::make('rental.name')
                    ->label(__('payments.infolist.rental')),

                TextEntry::make('created_at')
                    ->label(__('payments.infolist.created_at'))
                    ->dateTime(),
                TextEntry::make('updated_at')
                    ->label(__('payments.infolist.updated_at'))
                    ->dateTime(),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('date')
            ->columns([
                Tables\Columns\TextColumn::make('date')
                    ->label(__('payments.table.date'))
                    ->date()
                    ->sortable(),
                Tables\Columns\TextColumn::make('status')
                    ->label(__('payments.table.status'))
                    ->badge()
                    ->state(fn(Payment $record) => $record->status()->value)
                    ->color(fn(string $state): string => match ($state) {
                        PaymentStatus::PAID->value => 'success',
                        PaymentStatus::PARTIAL->value => 'info',
                        PaymentStatus::PENDING->value => 'warning',
                        PaymentStatus::OVERDUE->value => 'danger',
                        default => 'gray',
                    })
                    ->formatStateUsing(fn(string $state): string => match ($state) {
                        PaymentStatus::PAID->value => __('payments.status.paid'),
                        PaymentStatus::PARTIAL->value => __('payments.status.partial'),
                        PaymentStatus::PENDING->value => __('payments.status.pending'),
                        PaymentStatus::OVERDUE->value => __('payments.status.overdue'),
                        default => $state,
                    }),
                Tables\Columns\TextColumn::make('amount')
                    ->label(__('payments.table.amount'))
                    ->numeric()
                    ->sortable(),
                Tables\Columns\IconColumn::make('is_rent_paid')
                    ->label(__('payments.table.is_rent_paid'))
                    ->boolean(),
                Tables\Columns\IconColumn::make('is_water_paid')
                    ->label(__('payments.table.is_water_paid'))
                    ->boolean(),
                Tables\Columns\IconColumn::make('is_energy_paid')
                    ->label(__('payments.table.is_energy_paid'))
                    ->boolean(),
                Tables\Columns\IconColumn::make('is_gas_paid')
                    ->label(__('payments.table.is_gas_paid'))
                    ->boolean(),
                Tables\Columns\TextColumn::make('created_at')
                    ->label(__('payments.table.created_at'))
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('updated_at')
                    ->label(__('payments.table.updated_at'))
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                //
            ])
            ->headerActions([
                Tables\Actions\CreateAction::make()
                    ->label(__('payments.navigation.actions.create')),
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

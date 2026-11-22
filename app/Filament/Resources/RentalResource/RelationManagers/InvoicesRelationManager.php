<?php

namespace App\Filament\Resources\RentalResource\RelationManagers;

use App\Enums\InvoiceStatus;
use App\Models\Invoice;
use App\Settings\AppSettings;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

class InvoicesRelationManager extends RelationManager
{
    protected static string $relationship = 'invoices';

    protected static ?string $recordTitleAttribute = 'number';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('invoice.navigation.labels.plural');
    }

    /**
     * Las facturas las genera el sistema de facturacion y sus acciones
     * (pagar, anular, descargar) viven en el recurso de facturas: aqui la
     * tabla es de solo consulta.
     */
    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('number')
            ->columns([
                Tables\Columns\TextColumn::make('number')
                    ->label(__('invoice.table.number'))
                    ->searchable()
                    ->sortable()
                    ->copyable(),

                Tables\Columns\TextColumn::make('period')
                    ->label(__('invoice.table.period'))
                    ->badge()
                    ->color('gray')
                    ->sortable(),

                Tables\Columns\TextColumn::make('concept')
                    ->label(__('invoice.table.concept'))
                    ->formatStateUsing(fn (string $state): string => (string) __('invoice.concepts.'.$state))
                    ->limit(40),

                Tables\Columns\TextColumn::make('amount')
                    ->label(__('invoice.table.amount'))
                    ->money(fn (): string => app(AppSettings::class)->currency())
                    ->sortable(),

                Tables\Columns\TextColumn::make('balance')
                    ->label(__('invoice.table.balance'))
                    ->money(fn (): string => app(AppSettings::class)->currency())
                    ->state(fn (Invoice $record): float => $record->balance())
                    ->color(fn (Invoice $record): string => $record->balance() > 0 ? 'danger' : 'success'),

                Tables\Columns\TextColumn::make('due_at')
                    ->label(__('invoice.table.due_at'))
                    ->date()
                    ->sortable(),

                Tables\Columns\TextColumn::make('status')
                    ->label(__('invoice.table.status'))
                    ->badge()
                    ->formatStateUsing(fn (InvoiceStatus $state): string => $state->label())
                    ->color(fn (InvoiceStatus $state): string => $state->color()),
            ])
            ->defaultSort('issued_at', 'desc');
    }
}

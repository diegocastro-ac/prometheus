<?php

namespace App\Filament\Widgets;

use App\Contracts\CurrentUserContextInterface;
use App\Enums\InvoiceStatus;
use App\Models\Invoice;
use App\Settings\AppSettings;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget as BaseWidget;
use Illuminate\Database\Eloquent\Builder;

/**
 * Antes esta tabla listaba pagos sin pagar, filtrando por is_rent_paid. Como
 * ese indicador ya no existe, la tabla muestra facturas: lo que se debe es un
 * dato de la factura, no del pago.
 */
class OverduePaymentsTable extends BaseWidget
{
    protected int|string|array $columnSpan = 'full';

    protected static ?int $sort = 3;

    public function table(Table $table): Table
    {
        return $table
            ->heading(__('dashboard.table.title'))
            ->query($this->getQuery())
            ->columns([
                Tables\Columns\TextColumn::make('number')
                    ->label(__('invoice.table.number'))
                    ->searchable()
                    ->copyable(),

                Tables\Columns\TextColumn::make('rental.name')
                    ->label(__('dashboard.table.rental'))
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('rental.tenant.name')
                    ->label(__('dashboard.table.tenant'))
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('due_at')
                    ->label(__('dashboard.table.due_date'))
                    ->date()
                    ->sortable(),

                Tables\Columns\TextColumn::make('balance')
                    ->label(__('dashboard.table.amount'))
                    ->money(fn (): string => app(AppSettings::class)->currency())
                    ->state(fn (Invoice $record): float => $record->balance())
                    ->sortable(),

                Tables\Columns\TextColumn::make('status')
                    ->label(__('dashboard.table.status'))
                    ->badge()
                    ->state(fn (Invoice $record): string => match (true) {
                        $record->isOverdue() => __('dashboard.status.expired'),
                        $record->due_at->lte(today()->addDays(7)) => __('dashboard.status.expiring'),
                        default => __('dashboard.status.paid'),
                    })
                    ->color(fn (string $state): string => match ($state) {
                        __('dashboard.status.expired') => 'danger',
                        __('dashboard.status.expiring') => 'warning',
                        default => 'success',
                    }),
            ])
            ->defaultSort('due_at', 'asc')
            ->paginated([10]);
    }

    /**
     * Solo facturas con saldo: las emitidas que vencen esta semana y las que ya
     * vencieron. No hace falta calcular el saldo en SQL porque el propio estado
     * de la factura ya lo implica: refreshStatus solo deja EMITIDA o VENCIDA
     * cuando el saldo es mayor que cero.
     */
    private function getQuery(): Builder
    {
        return Invoice::query()
            ->whereHas('rental', function ($q) {
                $q->where('user_id', app(CurrentUserContextInterface::class)->id())
                    ->where('is_active', true);
            })
            ->whereIn('status', [InvoiceStatus::EMITIDA, InvoiceStatus::VENCIDA])
            ->where('due_at', '<=', today()->addDays(7))
            ->orderBy('due_at');
    }
}

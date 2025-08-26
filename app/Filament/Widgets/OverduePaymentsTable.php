<?php

namespace App\Filament\Widgets;

use App\Models\Payment;
use Carbon\Carbon;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget as BaseWidget;
use Illuminate\Support\Facades\Auth;
use Illuminate\Database\Eloquent\Builder;

class OverduePaymentsTable extends BaseWidget
{

    protected int | string | array $columnSpan = 'full';

    protected static ?int $sort = 3;

    public function table(Table $table): Table
    {
        return $table
            ->heading(__('dashboard.table.title'))
            ->query($this->getQuery())
            ->columns([
                Tables\Columns\TextColumn::make('rental.name')
                    ->label(__('dashboard.table.rental'))
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('rental.tenant.name')
                    ->label(__('dashboard.table.tenant'))
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('rental.property.name')
                    ->label(__('dashboard.table.property'))
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('date')
                    ->label(__('dashboard.table.due_date'))
                    ->date()
                    ->sortable(),
                Tables\Columns\TextColumn::make('amount')
                    ->label(__('dashboard.table.amount'))
                    ->money('COP')
                    ->sortable(),
                Tables\Columns\TextColumn::make('status')
                    ->label(__('dashboard.table.status'))
                    ->badge()
                    ->state(function ($record) {
                        if (!$record->is_paid && $record->date < \Carbon\Carbon::today()) {
                            return __('dashboard.status.expired');
                        }
                        if (!$record->is_paid && $record->date <= \Carbon\Carbon::today()->addDays(7)) {
                            return __('dashboard.status.expiring');
                        }
                        return __('dashboard.status.paid');
                    })
                    ->color(fn(string $state): string => match ($state) {
                        __('dashboard.status.expired') => 'danger',
                        __('dashboard.status.expiring') => 'warning',
                        __('dashboard.status.paid') => 'success',
                        default => 'gray',
                    }),
            ])
            ->defaultSort('date', 'asc')
            ->paginated([10]);
    }

    private function getQuery(): Builder
    {
        return Payment::query()
            ->whereHas('rental', function ($q) {
                $q->where('user_id', Auth::id())
                    ->where('is_active', true);
            })
            ->where(function ($q) {
                $q->where('is_rent_paid', false)
                    ->where(function ($q2) {
                        $q2->where('date', '<', Carbon::today())
                            ->orWhereBetween('date', [Carbon::today(), Carbon::today()->addDays(7)]);
                    });
            });
    }
}

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
    protected static ?string $heading = 'Past due or upcoming payments on active rentals';

    protected int | string | array $columnSpan = 'full';

    protected static ?int $sort = 3;

    public function table(Table $table): Table
    {
        return $table
            ->query($this->getQuery())
            ->columns([
                Tables\Columns\TextColumn::make('rental.name')
                    ->label('Rental')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('rental.tenant.name')
                    ->label('Tenant')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('rental.property.name')
                    ->label('Property')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('date')
                    ->label('Due date')
                    ->date()
                    ->sortable(),
                Tables\Columns\TextColumn::make('amount')
                    ->label('Amount')
                    ->money('COP')
                    ->sortable(),
                Tables\Columns\TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->state(function ($record) {
                        if (!$record->is_paid && $record->date < \Carbon\Carbon::today()) {
                            return 'Expired';
                        }
                        if (!$record->is_paid && $record->date <= \Carbon\Carbon::today()->addDays(7)) {
                            return 'Expiring';
                        }
                        return 'Paid';
                    })
                    ->color(fn(string $state): string => match ($state) {
                        'Expired' => 'danger',
                        'Expiring' => 'warning',
                        'Paid' => 'success',
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

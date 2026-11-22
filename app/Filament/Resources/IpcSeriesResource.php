<?php

namespace App\Filament\Resources;

use App\Dto\Geography;
use App\Filament\Resources\IpcSeriesResource\Pages;
use App\Models\IpcSeries;
use App\Services\Ipc\IpcSeriesRepository;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * La serie del IPC que hay en la base de datos local.
 *
 * Es una pantalla de solo lectura y a proposito. Su trabajo es que el arrendador
 * pueda ver de donde sale la cifra que el sistema usa para reajustar, en lugar
 * de tener que confiar en ella. Ver el IPC de diciembre de 2025 con su variacion
 * y su fecha de sincronizacion es la diferencia entre aceptar una cifra y
 * verificarla.
 *
 * Tambien es el lugar donde se nota si la sincronizacion fallo: la fecha de la
 * ultima descarga delata una serie vieja, y un filtro por geografia vacio delata
 * que el CDA cambio.
 */
class IpcSeriesResource extends Resource
{
    protected static ?string $model = IpcSeries::class;

    protected static ?string $slug = 'administration/ipc-series';

    protected static ?int $navigationSort = 3;

    protected static ?string $navigationIcon = 'heroicon-o-chart-bar';

    public static function getNavigationGroup(): ?string
    {
        return __('ipc.navigation.group');
    }

    public static function getNavigationLabel(): string
    {
        return __('ipc.navigation.label');
    }

    public static function getModelLabel(): string
    {
        return __('ipc.model.singular');
    }

    public static function getPluralModelLabel(): string
    {
        return __('ipc.model.plural');
    }

    public static function canCreate(): bool
    {
        // Los datos vienen del DANE. Permitir escribirlos a mano seria abrir la
        // puerta a que alguien "corrija" un IPC y cambie el tope legal de todos
        // los reajustes.
        return false;
    }

    /**
     * Tampoco se edita, por la misma razon.
     *
     * Si el DANE publica un dato corregido, la unica via de entrada es la
     * sincronizacion, que deja rastro de la fecha y del origen. Corregir una
     * fila desde la pantalla dejaria una cifra sin fecha de descarga y sin
     * forma de volver a contrastarla.
     */
    public static function canEdit($record): bool
    {
        return false;
    }

    public static function form(Form $form): Form
    {
        return $form->schema([]);
    }

    public static function table(Table $table): Table
    {
        $repository = app(IpcSeriesRepository::class);

        return $table
            ->columns([
                Tables\Columns\TextColumn::make('geography_name')
                    ->label(__('ipc.table.geography'))
                    ->description(fn (IpcSeries $record): string => $record->geography_code)
                    ->searchable()
                    ->sortable(),

                /*
                | El período es una columna VIRTUAL: no existe en la tabla, se
                | compone con año y mes.
                |
                | Ordenarla por dos columnas a la vez es lo que hace falta: orden
                | solo por año deja todos los meses de ese año en el orden que
                | devuelva la base, que no es un orden. Por eso `sortable` recibe
                | la lista de columnas y una consulta propia.
                |
                | Y es ademas la columna de orden por defecto. Estaba en
                | `getEloquentQuery()` como un `orderBy` fijo, y eso rompía TODAS
                | las demás columnas ordenables: el `orderBy` del usuario se
                | añade después del forzado, de modo queyear siempre ganaba y
                | pulsar cualquier otra cabecera no cambiaba nada visible. La
                | tabla parecía ordenable y no lo era.
                |
                | Con el desempate declarado aquí, cada columna ordena por lo
                | suyo, y el período ordena por año y mes a la vez.
                */
                Tables\Columns\TextColumn::make('periodKey')
                    ->label(__('ipc.table.period'))
                    ->state(fn (IpcSeries $record): string => $record->periodKey())
                    ->searchable()
                    ->sortable(
                        ['year', 'month'],
                        fn (Builder $query, string $direction): Builder => $query
                            ->orderBy('year', $direction)
                            ->orderBy('month', $direction),
                    ),

                Tables\Columns\TextColumn::make('index_value')
                    ->label(__('ipc.table.index'))
                    ->formatStateUsing(fn (float $state): string => number_format($state, 2, ',', '.'))
                    ->sortable(),

                Tables\Columns\TextColumn::make('variation_monthly')
                    ->label(__('ipc.table.variation_monthly'))
                    ->formatStateUsing(self::percent())
                    ->sortable(),

                Tables\Columns\TextColumn::make('variation_yoy')
                    ->label(__('ipc.table.variation_yoy'))
                    ->formatStateUsing(self::percent())
                    ->sortable(),

                // Es la que el artículo 20 usa: la variación acumulada del año
                // calendario. En diciembre es la cifra que sale en la carta, asi
                // que se ordena por defecto y se deja visible.
                Tables\Columns\TextColumn::make('variation_year')
                    ->label(__('ipc.table.variation_year'))
                    ->formatStateUsing(self::percent())
                    ->description(__('ipc.table.variation_year_hint'))
                    ->sortable(),

                Tables\Columns\TextColumn::make('synced_at')
                    ->label(__('ipc.table.synced_at'))
                    ->since()
                    ->dateTimeTooltip()
                    ->sortable(),

                /*
                | La fuente, y con ella el enlace al documento original.
                |
                | Esta pantalla existe para que el arrendador pueda VERIFICAR la
                | cifra, no solo verla. La columna se guardaba desde el principio
                | (source_name y source_url) pero no se mostraba: la fecha de
                | sincronización dice cuándo se descargó, no de dónde salió, y son
                | dos datos distintos. Un DANE que cambia la methodology de
                | publicación deja la fecha igual de nueva.
                |
                | Va plegada por defecto porque ocupa y porque casi siempre dice
                | "DANE", que es lo esperado. Se despliega cuando hace falta
                | revisar el origen de una cifra concreta.
                */
                Tables\Columns\TextColumn::make('source_name')
                    ->label(__('ipc.table.source'))
                    ->description(fn (?string $state): ?string => $state === 'DANE' ? null : $state)
                    ->badge()
                    ->color('gray')
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->sortable(),

                Tables\Columns\TextColumn::make('source_url')
                    ->label(__('ipc.table.source_url'))
                    ->formatStateUsing(fn (?string $state): string => __('ipc.table.source_url'))
                    ->url(fn (?string $state): ?string => $state)
                    ->openUrlInNewTab()
                    ->icon('heroicon-m-arrow-top-right-on-square')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('periodKey', 'desc')
            ->filters([
                // "Todos" primero, despues el total nacional, despues las ciudades en orden
                // alfabetico. El nacional va primero porque es la base que fija el
                // articulo 20 y porque, siendo el caso normal, no debería obligar
                // a buscarlo en medio de la lista.
                //
                // Las opciones salen de la propia tabla, no de una lista escrita en
                // el codigo: si el DANE publica una ciudad nueva, aparece sola.
                SelectFilter::make('geography_code')
                    ->label(__('ipc.table.geography'))
                    // Filament ya añade su opción vacía ("sin filtro"). El
                    // nombre de ESA opción se define aquí, en el placeholder:
                    // ponerla también en filterOptions() la duplicaba.
                    ->placeholder(__('ipc.filters.all'))
                    ->options(fn (): array => \App\Models\Geography::filterOptions())
                    ->default(Geography::nationalCode()),

                SelectFilter::make('year')
                    ->label(__('ipc.table.year'))
                    ->options(fn (): array => collect($repository->availableYears())
                        ->mapWithKeys(fn (int $year): array => [$year => (string) $year])
                        ->all()),
            ])
            ->emptyStateHeading(__('ipc.table.empty'));
    }

    public static function getEloquentQuery(): Builder
    {
        /*
        | SIN orden forzado aquí, a propósito.
        |
        | Filament añade el `orderBy` de la columna pulsada DESPUÉS de lo que
        | devuelva este método. Un `orderBy('year')` fijo, por tanto, quedaba
        | primero y anulaba cualquier otro orden: la tabla mostraba cabeceras
        | ordenables que no ordenaban nada.
        |
        | El orden por defecto —período descendente— se declara en
        | `->defaultSort('periodKey', 'desc')`, que es donde Filament lo aplica
        | solo cuando el usuario no ha elegido ninguna columna.
        */
        return parent::getEloquentQuery();
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListIpcSeries::route('/'),
        ];
    }

    /**
     * Formatea un porcentaje del DANE, que ya viene en tanto por ciento.
     */
    private static function percent(): callable
    {
        return fn (?float $state): string => $state === null
            ? '—'
            : number_format($state, 2, ',', '.').' %';
    }
}

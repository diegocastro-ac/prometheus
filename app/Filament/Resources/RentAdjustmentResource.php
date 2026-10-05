<?php

namespace App\Filament\Resources;

use App\Contracts\CurrentUserContextInterface;
use App\Documents\Contracts\Document;
use App\Documents\DocumentService;
use App\Filament\Resources\RentAdjustmentResource\Pages;
use App\Filament\Support\DocumentAction;
use App\Models\IpcRate;
use App\Models\RentAdjustment;
use App\Models\Rental;
use App\Services\RentAdjustmentService;
use App\Settings\AppSettings;
use Filament\Forms;
use Filament\Forms\Get;
use Filament\Forms\Form;
use Filament\Forms\Set;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use RuntimeException;

/**
 * Reajustes del canon.
 *
 * La pantalla expone el calculo y la carta, pero el tope legal no se decide
 * aqui: lo decide RentAdjustmentService y queda congelado en el registro. Esta
 * pantalla solo lo muestra y avisa cuando el incremento lo excede.
 */
class RentAdjustmentResource extends Resource
{
    protected static ?string $model = RentAdjustment::class;

    protected static ?string $slug = 'administration/rent-adjustments';

    protected static ?int $navigationSort = 4;

    protected static ?string $navigationIcon = 'heroicon-o-arrow-trending-up';

    public static function getNavigationGroup(): ?string
    {
        return __('rent_adjustment.navigation.group');
    }

    public static function getNavigationLabel(): string
    {
        return __('rent_adjustment.navigation_label');
    }

    public static function getModelLabel(): string
    {
        return __('rent_adjustment.navigation.labels.singular');
    }

    public static function getPluralModelLabel(): string
    {
        return __('rent_adjustment.navigation.labels.plural');
    }

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make(__('rent_adjustment.sections.general'))
                    ->description(__('rent_adjustment.descriptions.legal'))
                    ->schema([
                        Forms\Components\Select::make('rental_id')
                            ->label(__('rent_adjustment.form.rental_id'))
                            ->relationship(
                                'rental',
                                'name',
                                modifyQueryUsing: fn ($query) => $query
                                    ->where('user_id', app(CurrentUserContextInterface::class)->id())
                                    ->orderBy('name'),
                            )
                            ->required()
                            ->live()
                            ->afterStateUpdated(function ($state, Forms\Set $set): void {
                                if ($state === null) {
                                    return;
                                }

                                $rental = Rental::find($state);

                                if ($rental !== null) {
                                    // Sugiere el tope, no el incremento: el
                                    // canon reajustado que el DANE respalda es el
                                    // que multiplica por el IPC. El arrendador
                                    // puede pasarse, y el sistema lo va a
                                    // avisar.
                                    $previousYear = (int) now()->subYear()->format('Y');
                                    $ipc = IpcRate::query()->where('year', $previousYear)->first();

                                    $set('new_rent', $ipc === null
                                        ? $rental->monthly_amount
                                        : round($rental->monthly_amount * (1 + $ipc->percentage / 100)));
                                }
                            }),

                        Forms\Components\TextInput::make('new_rent')
                            ->label(__('rent_adjustment.form.new_rent'))
                            ->numeric()
                            ->prefix(fn (): string => app(AppSettings::class)->currency())
                            ->minValue(1)
                            ->required(),

                        Forms\Components\DatePicker::make('effective_from')
                            ->label(__('rent_adjustment.form.effective_from'))
                            ->default(fn (): ?string => self::effectiveFromForIpcYear(
                                IpcRate::query()->max('year'),
                            ))
                            // Un reajuste aplica al canon futuro. Permitirlo en
                            // el pasado es un error de captura que despues
                            // descuadra las facturas del periodo.
                            ->minDate(today())
                            ->required(),

                        Forms\Components\Select::make('ipc_year')
                            ->label(__('rent_adjustment.form.ipc_year'))
                            ->options(fn (): array => IpcRate::query()
                                ->orderByDesc('year')
                                ->pluck('year', 'year')
                                ->map(fn ($year): string => (string) $year)
                                ->all())
                            // El ano del IPC gobierna la vigencia. El par tiene
                            // que ser coherente, porque la ley ata el IPC al ano
                            // anterior al de vigencia: cambiar el ano recalcula
                            // la fecha en vez de dejarlos contradichorios.
                            ->live()
                            ->afterStateUpdated(function (Set $set, mixed $state): void {
                                $set('effective_from', self::effectiveFromForIpcYear($state));
                            })
                            ->default(fn (): ?int => IpcRate::query()->max('year'))
                            ->helperText(__('rent_adjustment.descriptions.ipc'))
                            // El año del IPC no es libre: sale de los datos
                            // registrados, para que nadie cite un año que no
                            // existe.
                            ->required(),

                        Forms\Components\Checkbox::make('ipc_outside_statutory_year')
                            ->label(__('rent_adjustment.form.ipc_outside_statutory_year'))
                            ->helperText(__('rent_adjustment.form.ipc_outside_statutory_year_help'))
                            // Aparece solo cuando la combinacion elegida se
                            // aparta de la ley. Marcarlo es la unica forma de
                            // guardar un IPC que no corresponde, y exige
                            // dejar el motivo escrito.
                            ->visible(fn (Get $get): bool => self::deviatesFromStatutoryYear(
                                $get('ipc_year'),
                                $get('effective_from'),
                            ))
                            ->dehydrated(fn (Get $get): bool => self::deviatesFromStatutoryYear(
                                $get('ipc_year'),
                                $get('effective_from'),
                            )),

                        Forms\Components\Textarea::make('notes')
                            ->label(__('rent_adjustment.form.notes'))
                            ->placeholder(__('rent_adjustment.placeholders.notes'))
                            ->helperText(__('rent_adjustment.form.notes_help'))
                            ->rows(3)
                            ->columnSpanFull(),
                    ])
                    ->columns(2),
            ]);
    }

    /**
     * Vigencia coherente con el ano de IPC elegido.
     *
     * El articulo 20 ata el IPC al ano calendario anterior al de vigencia, de
     * modo que a un IPC del ano N le corresponde rigorizar el 1 de enero de N+1.
     *
     * Si esa fecha ya paso, se propone el dia de hoy. El par sigue siendo legal
     * porque hoy pertenece al mismo ano N+1, asi que el ano anterior continua
     * siendo N. Esa es la razon de=max(): sin ella, proponer el ultimo IPC
     * disponible con una vigencia fija del año entrante dejaria el IPC y la
     * fecha contradichorios, que es justo lo que la regla quiere evitar.
     */
private static function effectiveFromForIpcYear(mixed $ipcYear): ?string
    {
        if ($ipcYear === null || $ipcYear === '') {
            return null;
        }

        $statutoryStart = Carbon::create((int) $ipcYear + 1, 1, 1);

        return $statutoryStart->isFuture()
            ? $statutoryStart->toDateString()
            : today()->toDateString();
    }

    /**
     * Si el par ano de IPC y vigencia se aparta del articulo 20.
     *
     * Lo consulta el formulario para mostrar la confirmacion, y el servicio
     * vuelve a comprobarlo al guardar. Que dos lugares compartan la regla es
     * correcto: el formulario la anticipa y el servicio la impone, porque una
     * pantalla nunca es la garantia de nada.
     */
    private static function deviatesFromStatutoryYear(mixed $ipcYear, mixed $effectiveFrom): bool
    {
        if ($ipcYear === null || $ipcYear === '' || $effectiveFrom === null || $effectiveFrom === '') {
            return false;
        }

        return (int) $ipcYear !== ((int) Carbon::parse((string) $effectiveFrom)->format('Y') - 1);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('rental.name')
                    ->label(__('rent_adjustment.table.rental'))
                    ->badge()
                    ->color('gray')
                    ->searchable(),

                Tables\Columns\TextColumn::make('previous_rent')
                    ->label(__('rent_adjustment.table.previous_rent'))
                    ->money(fn (): string => app(AppSettings::class)->currency()),

                Tables\Columns\TextColumn::make('new_rent')
                    ->label(__('rent_adjustment.table.new_rent'))
                    ->money(fn (): string => app(AppSettings::class)->currency())
                    ->weight('bold'),

                Tables\Columns\TextColumn::make('ipc_percentage')
                    ->label(__('rent_adjustment.table.ipc'))
                    ->formatStateUsing(fn (?float $state): string => $state === null ? '—' : number_format($state, 2, ',', '.').' %')
                    ->suffix(fn (RentAdjustment $record): string => $record->ipc_year === null ? '' : ' ('.$record->ipc_year.')')
                    ->sortable(),

                Tables\Columns\TextColumn::make('legal_cap_rent')
                    ->label(__('rent_adjustment.table.legal_cap'))
                    ->money(fn (): string => app(AppSettings::class)->currency()),

                Tables\Columns\TextColumn::make('status')
                    ->label(__('rent_adjustment.table.status'))
                    ->badge()
                    ->state(fn (RentAdjustment $record): string => $record->exceedsCap() ? 'exceeds_cap' : 'within_cap')
                    ->formatStateUsing(fn (string $state): string => __('rent_adjustment.statuses.'.$state))
                    ->color(fn (string $state): string => $state === 'exceeds_cap' ? 'danger' : 'success'),

                Tables\Columns\TextColumn::make('effective_from')
                    ->label(__('rent_adjustment.table.effective_from'))
                    ->date()
                    ->sortable(),

                Tables\Columns\TextColumn::make('notified_on')
                    ->label(__('rent_adjustment.table.notified_on'))
                    ->date()
                    ->placeholder('—')
                    ->sortable(),
            ])
            ->defaultSort('effective_from', 'desc')
            ->actions([
                Tables\Actions\ActionGroup::make([
                    ...self::letterActions(),
                    self::applyAction(),
                    self::markNotifiedAction(),
                ])
                ->label(__('rent_adjustment.buttons.actions'))
                ->icon('heroicon-o-ellipsis-horizontal')
                ->color('gray'),
            ]);
    }

    /**
     * Carta de reajuste, en las dos familias.
     *
     * @return array<int, Tables\Actions\Action>
     */
    private static function letterActions(): array
    {
        return [
            DocumentAction::pdf(
                name: 'letter_pdf',
                label: __('rent_adjustment.buttons.letter'),
                builder: fn (RentAdjustment $record, string $format): Document => app(DocumentService::class)
                    ->adjustmentLetterFor($record, $format),
            ),

            DocumentAction::text(
                name: 'letter_text',
                label: __('invoice.buttons.copy_document_text'),
                builder: fn (RentAdjustment $record, string $format): Document => app(DocumentService::class)
                    ->adjustmentLetterFor($record, $format),
            ),
        ];
    }

    /**
     * Aplica el canon reajustado al alquiler.
     *
     * El servicio rechaza el reajuste que excede el tope sin acuerdo escrito en
     * las observaciones, y la excepcion se muestra como aviso en vez de
     * dejar fallar la peticion.
     */
    private static function applyAction(): Tables\Actions\Action
    {
        return Tables\Actions\Action::make('apply')
            ->label(__('rent_adjustment.buttons.apply'))
            ->icon('heroicon-o-check-circle')
            ->color('primary')
            ->requiresConfirmation()
            ->action(function (RentAdjustment $record): void {
                try {
                    app(RentAdjustmentService::class)->applyToRental($record);
                } catch (RuntimeException $exception) {
                    Notification::make()
                        ->warning()
                        ->title(__('rent_adjustment.notifications.needs_agreement'))
                        ->body($exception->getMessage())
                        ->persistent()
                        ->send();

                    return;
                }

                Notification::make()
                    ->success()
                    ->title(__('rent_adjustment.notifications.applied'))
                    ->send();
            });
    }

    /**
     * Marca la carta como comunicada, que es la constancia del deber de
     * comunicacion del articulo 20.
     */
    private static function markNotifiedAction(): Tables\Actions\Action
    {
        return Tables\Actions\Action::make('mark_notified')
            ->label(__('rent_adjustment.buttons.mark_notified'))
            ->icon('heroicon-o-paper-airplane')
            ->color('gray')
            ->visible(fn (RentAdjustment $record): bool => $record->notified_on === null)
            ->requiresConfirmation()
            ->action(function (RentAdjustment $record): void {
                app(RentAdjustmentService::class)->markNotified($record, today());

                Notification::make()
                    ->success()
                    ->title(__('rent_adjustment.notifications.notified'))
                    ->send();
            });
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->where('user_id', app(CurrentUserContextInterface::class)->id());
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListRentAdjustments::route('/'),
            'create' => Pages\CreateRentAdjustment::route('/create'),
        ];
    }
}

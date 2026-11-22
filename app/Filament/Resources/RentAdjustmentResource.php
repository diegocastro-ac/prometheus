<?php

namespace App\Filament\Resources;

use App\Contracts\CurrentUserContextInterface;
use App\Documents\Contracts\Document;
use App\Enums\IpcBasis;
use App\Enums\NotificationMethod;
use App\Filament\Resources\RentAdjustmentResource\Pages;
use App\Filament\Support\AdjustmentCalculation;
use App\Filament\Support\AdjustmentCalculationTable;
use App\Filament\Support\DocumentAction;
use App\Filament\Support\LegalChecksPanel;
use App\Models\RentAdjustment;
use App\Models\Rental;
use App\Services\Ipc\IpcSeriesRepository;
use App\Services\RentalFacade;
use App\Settings\AppSettings;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Forms\Set;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\HtmlString;
use RuntimeException;

/**
 * Reajustes del canon.
 *
 * La pantalla expone el calculo y la carta, pero el tope legal no se decide aqui:
 * lo decide RentAdjustmentService y queda congelado en el registro. Esta pantalla
 * solo lo muestra y avisa cuando algo se aparta de la norma.
 *
 * Tres decisiones que visibles desde el formulario:
 *
 * 1. La fecha de vigencia NO se impone al 1 de enero. El articulo 20 cuenta los
 *    doce meses "bajo un mismo precio", asi que la fecha propuesta es el
 *    aniversario del ultimo reajuste. La version anterior de esta pantalla fijaba
 *    enero, lo que era una lectura equivocada de la norma y ademas obligaba al
 *    usuario a corregir a mano la fecha que el sistema le habia puesto.
 *
 * 2. El ano del IPC y el selector de ciudad se llenan con lo que hay sincronizado,
 *    no con listas escritas en el codigo. Desaparecen cuando no hay datos, con el
 *    comando que los trae.
 *
 * 3. Los avisos legales se muestran mientras el usuario elige, no al guardar.
 *    Los produce la cadena de reglas, la misma que corre al guardar, de modo que
 *    lo que se anticipa y lo que se exige no pueden divergir.
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
        // Las closures del esquema no conservan $this: Filament las evalua en
        // las vistas, donde no hay instancia del resource. Por eso todo lo que
        // depende de estado vive en metodos estaticos.
        return $form
            ->schema([
                /*
                |--------------------------------------------------------------------
                | Sección 1 — QUÉ y CUÁNDO
                |--------------------------------------------------------------------
                |
                | Va primero porque son las dos únicas decisiones que el sistema no
                | puede tomar por el usuario. Elegido el alquiler y la fecha, todo lo
                | demás se deriva: el año del IPC sale de la fecha, el canon sale del
                | IPC y el tope sale del canon anterior.
                |
                | El párrafo legal largo que abría el formulario se quitó de aquí. No
                | aparecía en todas las pantallas y era ruido para leer; su lugar es
                | el panel de comprobaciones, que dice lo mismo pero solo cuando
                | concierne a lo que se está haciendo.
                */
                Forms\Components\Section::make(__('rent_adjustment.sections.which_and_when'))
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
                            ->afterStateUpdated(function (mixed $state, Set $set, Get $get): void {
                                $rental = $state === null ? null : Rental::find($state);

                                if ($rental === null) {
                                    return;
                                }

                                // Elegir el alquiler propone todo lo demas.
                                $set('effective_from', self::suggestedDateFor($rental)->toDateString());
                                $set('ipc_year', self::statutoryYearFor(self::suggestedDateFor($rental)));

                                /*
                                | La ciudad se propone solo si la base es ciudad.
                                |
                                | Va aquí y no en un default() porque la ciudad depende de
                                | la propiedad de este alquiler, y un default() se
                                | evalúa al construir el formulario, cuando todavía no
                                | hay alquiler. Solo se propone si la propiedad tiene una
                                | ciudad CON serie de IPC: proponer una ciudad sin datos
                                | dejaría al usuario sin salida desde este formulario.
                                */
                                if ($get('ipc_basis') === IpcBasis::City->value) {
                                    $city = self::propertyCityFor($state);

                                    if ($city !== null) {
                                        $set('geography_code', $city);
                                    }
                                }

                                self::suggestRent($set, $get, $rental);
                            }),

                        Forms\Components\DatePicker::make('effective_from')
                            ->label(__('rent_adjustment.form.effective_from'))
                            ->helperText(fn (Get $get): string => self::effectiveFromHelp($get))
                            ->default(fn (): string => today()->toDateString())
                            ->minDate(today())
                            ->required()
                            ->live(onBlur: true)
                            ->afterStateUpdated(function (Set $set, Get $get): void {
                                // El año del IPC DELEGA en la fecha: es el año
                                // calendario anterior, que es lo que manda el
                                // artículo 20. Dejarlo suelto era la causa de que el
                                // formulario naciera con una pareja incoherente y el
                                // usuario tuviera que corregirla a mano.
                                $set('ipc_year', self::statutoryYearFor(
                                    \Carbon\Carbon::parse($get('effective_from') ?: today()->toDateString()),
                                ));

                                self::refreshSuggestions($set, $get);
                            }),
                    ])
                    ->columns(2),

                /*
                |--------------------------------------------------------------------
                | Sección 2 — CON QUÉ CIFRA, y debajo el resultado
                |--------------------------------------------------------------------
                |
                | Los campos van de a dos por fila (columns(2)) y la tabla del cálculo
                | ocupa la fila completa debajo de ellos. En pantallas estrechas todo
                | pasa a una columna.
                |
                | El resultado va pegado a los campos porque cambia cada vez que se toca
                | cualquiera de ellos. Ponerlo al final obligaría a subir y bajar la
                | pantalla para ver el efecto de lo que se acaba de escribir, que es
                | justo lo que hace que una vista previa se lea.
                |
                | EL ORDEN DENTRO DE LA SECCIÓN IMPORTA
                |
                | La base del IPC (nacional o ciudad) DECIDE qué porcentajes se ofrecen,
                | y el año del IPC DECIDE el tope. Preguntar la base después de que el año
                | ya esté elegido muestra un porcentaje que después cambia sin avisar. Por
                | eso base y ciudad van primero, y el año, que depende de ellas, después.
                |
                | REJILLA PLANA, SIN SUB-SECCIONES ANIDADAS
                |
                | Los campos cuelgan directo de la sección y fluyen en el grid de dos
                | columnas. Cuando la base es nacional el campo ciudad se oculta y los
                | demás se reacomodan solos: no queda una columna vacía porque no hay
                | una rejilla interna rígida que deje el hueco.
                */
                Forms\Components\Section::make(__('rent_adjustment.sections.with_which_number'))
                    ->columns(2)
                    ->schema([
                        /*
                        |----------------------------------------
                        | Los campos, de a dos por fila
                        |----------------------------------------
                        |
                        | Van directos en la sección, sin sub-sección que los envuelva.
                        | La sección trae columns(2), así que cada campo ocupa media
                        | anchura y caben dos por fila. Cuando la base es nacional la
                        | ciudad se oculta y los demás se reacomodan solos en el grid.
                        |
                        | La tabla del cálculo va aparte, a ancho completo, para no
                        | competir por el espacio con estos campos.
                        */
                        Forms\Components\Select::make('ipc_basis')
                            ->label(__('rent_adjustment.form.ipc_basis'))
                            ->helperText(__('rent_adjustment.form.ipc_basis_help'))
                            ->options(IpcBasis::options())
                            ->default(IpcBasis::National->value)
                            ->live()
                            ->afterStateUpdated(function (Set $set, Get $get): void {
                                // Al cambiar la base hay que volver a resolver el
                                // año y TAMBIÉN el porcentaje: el que era válido
                                // para el total nacional cambia al pasar a una
                                // ciudad concreta, y viceversa. El año se resuelve
                                // primero porque puede no existir para la ciudad,
                                // y el porcentaje se recalcula con el año ya
                                // resuelto, no con el que estaba en el campo.
                                $resolvedYear = self::statutoryYearFor(
                                    \Carbon\Carbon::parse($get('effective_from') ?: today()->toDateString()),
                                );

                                $set('ipc_year', $resolvedYear);

                                /*
                                        | Este es el orden en que ocurre de verdad: se elige el
                                        | alquiler con la base nacional, y DESPUÉS se cambia a
                                        | ciudad. Por eso la ciudad se propone desde los dos
                                        | lados: aquí, al activar la base ciudad, y en
                                        | rental_id, al elegir el alquiler. Con la propuesta
                                        | en un solo lado, cambiar la base después dejaba el
                                        | selector vacío aunque la propiedad tuviera ciudad.
                                        */
                                if ($get('ipc_basis') === IpcBasis::City->value) {
                                    $city = self::propertyCityFor($get('rental_id'));

                                    if ($city !== null) {
                                        $set('geography_code', $city);
                                    }
                                }

                                self::refreshPactado($set, $get, $resolvedYear);
                                self::refreshSuggestions($set, $get);
                            }),

                        Forms\Components\Select::make('geography_code')
                            ->label(__('rent_adjustment.form.geography_code'))
                            // Solo cuando la base es ciudad. Oculto evita que
                            // quede un valor guardado que no aplica.
                            ->visible(fn (Get $get): bool => $get('ipc_basis') === IpcBasis::City->value)
                            ->options(fn (): array => self::cityOptions())
                            /*
                                    | La ciudad NO se propone con default().
                                    |
                                    | Un default() se evalúa al construir el formulario, que
                                    | es cuando todavía no hay alquiler elegido. La ciudad
                                    | depende de la propiedad de ESE alquiler, así que un
                                    | default() siempre devolvería vacío. Por eso se propone
                                    | desde el afterStateUpdated de rental_id, que sí corre con
                                    | el alquiler ya conocido.
                                    |
                                    | Solo cuando la base es ciudad: con base nacional el campo
                                    | está oculto y guardar un valor ahí sería un dato sin uso.
                                    */
                            ->helperText(fn (Get $get): string => self::cityHelper($get))
                            ->searchable()
                            ->live()
                            ->afterStateUpdated(function (Set $set, Get $get): void {
                                // Cambiar de ciudad cambia la serie del IPC, así
                                // que el porcentaje se recalcula con la ciudad
                                // recién elegida antes de proponer el canon.
                                self::refreshPactado($set, $get);
                                self::refreshSuggestions($set, $get);
                            })
                            ->required(fn (Get $get): bool => $get('ipc_basis') === IpcBasis::City->value),

                        Forms\Components\Grid::make(12)
                            ->schema([
                                Forms\Components\Select::make('ipc_year')
                                    ->label(__('rent_adjustment.form.ipc_year'))
                                    ->options(fn (Get $get): array => app(IpcSeriesRepository::class)
                                        ->yearOptions(self::geographyCodeFor($get)))
                                    ->default(fn (): ?int => self::defaultIpcYear())
                                    ->live()
                                    ->afterStateUpdated(function ($state, Set $set, Get $get): void {
                                        /*
                                         * El «Porcentaje pactado» queda atado al año del
                                         * IPC: al elegir o mover el año se actualiza al %
                                         * de ese año, que es el valor que propone el
                                         * artículo 20. Si el usuario quiere apartarse,
                                         * edita el % después de elegir el año.
                                         */
                                        $ipcYear = (int) $state;

                                        if ($ipcYear > 0) {
                                            $pct = self::percentageForYear(
                                                geography: self::geographyCodeFor($get),
                                                year: $ipcYear,
                                            );

                                            if ($pct !== null) {
                                                $set('pactado_pct', round((float) $pct, 2));
                                            }
                                        }

                                        // Recalcular el canon propuesto con el nuevo
                                        // pactado ya en su sitio.
                                        self::refreshSuggestions($set, $get);
                                    })
                                    ->required()
                                    ->columnSpan(3),

                                Forms\Components\TextInput::make('pactado_pct')
                                    ->label(__('rent_adjustment.form.pactado_pct'))
                                    ->numeric()
                                    ->minValue(0)
                                    ->maxValue(100)
                                    ->step(0.01)
                                    ->suffix('%')
                                    // En la carga inicial el campo arranca con el % del
                                    // año del IPC que corresponde, igual que cuando se
                                    // cambia el año después. Usa el año elegido si ya
                                    // está resuelto y, si no, el que propone la ley.
                                    ->default(function (Get $get): ?float {
                                        $year = $get('ipc_year');

                                        $year = ($year === null || $year === '')
                                            ? self::defaultIpcYear()
                                            : (int) $year;

                                        if ($year === null) {
                                            return null;
                                        }

                                        $pct = self::percentageForYear(self::geographyCodeFor($get), $year);

                                        return $pct === null ? null : round((float) $pct, 2);
                                    })
                                    ->live(onBlur: true)
                                    ->afterStateUpdated(function (Set $set, Get $get): void {
                                        self::refreshSuggestions($set, $get);
                                    })
                                    ->columnSpan(3),

                                Forms\Components\TextInput::make('new_rent')
                                    ->label(__('rent_adjustment.form.new_rent'))
                                    ->helperText(fn (Get $get): string => self::newRentHelp($get))
                                    ->numeric()
                                    ->prefix(fn (): string => app(AppSettings::class)->currency())
                                    ->minValue(1)
                                    ->required()
                                    ->live(onBlur: true)
                                    ->columnSpan(6),

                                /*
                                 * La explicación del «Porcentaje pactado» va aquí,
                                 * a todo el ancho y debajo de los inputs, en lugar de
                                 * como helper pegado al campo. Es una sola aclaración
                                 * para los tres números de la fila, no una nota de un
                                 * input concreto, y así se lee sin partirse en columnas.
                                 */
                                Forms\Components\Placeholder::make('pactado_pct_help')
                                    ->hiddenLabel()
                                    ->content(new HtmlString(
                                        '<p class="ajuste-pactado-help">'.e(__('rent_adjustment.form.pactado_pct_help')).'</p>',
                                    ))
                                    ->columnSpanFull(),
                            ]),

                        /*
                        |----------------------------------------
                        | La tabla del cálculo, a ancho completo
                        |----------------------------------------
                        |
                        | Es una sección propia DEBAJO de los campos, con el título de
                        | la vista previa como encabezado. Va a ancho completo
                        | (columnSpanFull) porque una cuenta con tres columnas necesita
                        | su espacio; apretada a media anchura junto a los campos se
                        | partiría el importe, que es justo la cifra que hay que leer.
                        |
                        | Sigue pegada a los campos, y no al final del formulario, por
                        | una razón de uso: la tabla cambia cada vez que se toca
                        | cualquiera de ellos. Ver el efecto de lo que se acaba de
                        | escribir sin bajar la pantalla es lo que hace que una vista
                        | previa se lea.
                        */
                        Forms\Components\Section::make(__('rent_adjustment.preview.title'))
                            ->compact()
                            ->columnSpanFull()
                            // Sin alquiler elegido no hay canon vigente que mostrar,
                            // así que la tabla ni aparece: mejor que un recuadro
                            // vacío colgando del formulario.
                            ->visible(fn (Get $get): bool => self::calculationFor($get) !== null)
                            ->schema([
                                Forms\Components\Placeholder::make('rent_preview')
                                    ->label('')
                                    ->content(fn (Get $get): HtmlString => new HtmlString(self::rentPreview($get))),
                            ]),
                    ])
                    ->columnSpanFull(),

                /*
                |--------------------------------------------------------------------
                | Sección 3 — LO QUE LAS PARTES DECIDEN
                |--------------------------------------------------------------------
                |
                | Completamente dinámico: la sección aparece y desaparece con las
                | selecciones, y no con un estado guardado.
                |
                | Antes se usaba un booleano, deviates(), que solo podía contestar
                | sí o no. Con eso la sección aparecía sin poder explicar por qué, y
                | aparecía en casos que no eran separaciones: escribir un porcentaje
                | pactado la abría aunque la combinación del IPC fuera exactamente la
                | que fija la ley.
                |
                | Ahora hay tres estados distintos y cada uno hace falta:
                |
                |   1. No hay nada que decidir  → la sección no existe.
                |   2. Hay algo que decidir      → la sección aparece ABIERTA y lista
                |      los motivos, y el usuario puede aceptarlos o cambiar algo.
                | LA SECCIÓN YA NO SE PLIEGA SOLA
                |
                | Antes, al marcar la confirmación, la sección se plegaba sola y eso
                | provocaba un salto de tamaño en el formulario cada vez que se tocaba
                | el interruptor: el contenido desaparecía de golpe y las secciones de
                | abajo subían. Ahora la sección se queda abierta y muestra los motivos
                | aceptados; el alto ya no cambia y el formulario deja de moverse
                | mientras se rellena.
                |
                | ACEPTAR ES LO QUE SE GUARDA
                |
                | La casilla de confirmación no es decorativa: es lo que la cadena de
                | reglas lee para permitir un registro que se aparta. Por eso solo se
                | ofrece cuando hay motivos, y por eso las observaciones se exigen en
                | cuanto se marca.
                */
                Forms\Components\Section::make(__('rent_adjustment.sections.party_decisions'))
                    ->description(fn (Get $get): string => self::partyDecisionsDescription($get))
                    ->visible(fn (Get $get): bool => self::needsPartyDecision($get))
                    ->schema([
                        /*
                        | Los motivos se listan dentro de la sección, no en la
                        | descripción de arriba.
                        |
                        | La descripción dice de qué se trata la sección; los motivos son
                        | datos que cambian con cada selección, y mezclarlos hace que la
                        | descripción salte de texto cada vez que se toca un campo.
                        */
                        Forms\Components\Placeholder::make('deviation_reasons')
                            ->label('')
                            ->content(fn (Get $get): HtmlString => new HtmlString(self::deviationReasonsHtml($get)))
                            ->columnSpanFull(),

                        Forms\Components\Toggle::make('ipc_outside_statutory_year')
                            ->label(__('rent_adjustment.form.ipc_outside_statutory_year'))
                            ->helperText(__('rent_adjustment.form.ipc_outside_statutory_year_help'))
                            ->live()
                            ->columnSpanFull(),

                        Forms\Components\Textarea::make('notes')
                            ->label(__('rent_adjustment.form.notes'))
                            ->placeholder(__('rent_adjustment.placeholders.notes'))
                            ->helperText(fn (Get $get): string => self::notesHelp($get))
                            ->rows(3)
                            // Las observaciones solo importan cuando hay algo que
                            // confirmar o cuando el usuario dejó algo escrito.
                            // En el caso corriente ni se enseñan.
                            ->visible(fn (Get $get): bool => self::needsPartyDecision($get)
                                || ($get('notes') !== null && $get('notes') !== ''))
                            // Obligatorio, con su asterisco, en cuanto haya algo
                            // que confirmar: el motivo escrito es lo que sostiene
                            // el reajuste si el arrendatario lo impugna. Cuando el
                            // campo solo se ve porque había texto previo, no se exige.
                            ->required(fn (Get $get): bool => self::needsPartyDecision($get))
                            ->columnSpanFull(),
                    ])
                    ->columns(2),

                /*
                |--------------------------------------------------------------------
                | Las comprobaciones
                |--------------------------------------------------------------------
                |
                | Ahora SÍ es una sección, con su título y su descripción. Va
                | compacta para no robar altura, y solo aparece cuando hay algo que
                | decir. Como sección se lee como una parte más del formulario con
                | marco propio, en lugar de una lista suelta flotando entre campos
                | sin saber a qué pertenece.
                |
                | Ocupa la fila completa porque un aviso partido en dos columnas se
                | lee peor que uno entero: el texto legal necesita su ancho para que
                | la columna del artículo no quede en un interlineado estrecho.
                */
                Forms\Components\Section::make(__('rent_adjustment.sections.legal_checks'))
                    ->description(__('rent_adjustment.sections.legal_checks_help'))
                    ->compact()
                    ->schema([
                        Forms\Components\Placeholder::make('legal_checks')
                            ->label('')
                            // El contenido lleva HTML porque cada aviso se pinta con su
                            // propio color y su propio rótulo. Un Placeholder normal
                            // escapa el texto y las clases se verían literales.
                            ->content(fn (Get $get): HtmlString => new HtmlString(self::legalPanel($get))),
                    ])
                    ->visible(fn (Get $get): bool => self::legalPanel($get) !== '')
                    ->columnSpanFull(),
            ]);
    }

    /**
     * La fecha de vigencia que se propone para un alquiler.
     *
     * El artículo 20 cumple los doce meses desde el ÚLTIMO cambio de canon, no desde
     * el inicio del contrato. Después, si esa fecha cae en un día no hábil, se propone
     * el siguiente día hábil usando el mismo BusinessCalendar de las facturas.
     *
     * Mover la fecha no cambia el plazo legal: cambia el día en que se puede
     * comunicar el reajuste, que es lo que el parágrafo 2 del mismo artículo exige.
     */
    private static function suggestedDateFor(Rental $rental): \DateTimeInterface
    {
        $legal = $rental->nextAdjustmentDueDate();

        $proposer = app(RentalFacade::class);

        return max($proposer->suggestedAdjustmentDate($legal), today())->startOfDay();
    }

    /*
    | El año del IPC que manda el artículo 20 para una vigencia.
    |
    | Es el año calendario ANTERIOR, y no el último disponible. Esa distinción es
    | la que hace que el formulario nazca coherente en vez de tener que corregirse
    | a mano.
    |
    | El filtro por geografía NO se aplica aquí a propósito. Este método responde
    | "¿qué año corresponde por ley?", y la respuesta es la misma para el total
    | nacional que para cualquier ciudad: el artículo 20 habla de años, no de
    | zonas. Si una ciudad no tiene ese año, el problema no es que el año esté mal
    | sino que falta el dato de esa ciudad, y eso lo informa otra regla con un
    | mensaje propio.
    */
    private static function statutoryYearFor(\DateTimeInterface $effectiveFrom): ?int
    {
        $year = (int) $effectiveFrom->format('Y') - 1;

        $available = app(IpcSeriesRepository::class)->availableYears();

        // Si el año que toca todavía no está publicado, se propone el último que sí.
        // El panel de comprobaciones avisa del desvío, con el artículo a la vista.
        return in_array($year, $available, true) ? $year : ($available[0] ?? null);
    }

    /**
     * El código de geografía que corresponde a lo elegido en el formulario.
     *
     * Es el que decide de dónde salen los porcentajes del IPC. Para la base
     * nacional devuelve null, que es como el repositorio pide "el total
     * nacional" y no una ciudad llamada "nacional".
     */
    private static function geographyCodeFor(Get $get): ?string
    {
        if ($get('ipc_basis') !== IpcBasis::City->value) {
            return null;
        }

        $code = $get('geography_code');

        return $code === null || $code === '' ? null : (string) $code;
    }

    /**
     * La ciudad de la propiedad del alquiler elegido, si tiene.
     *
     * Se propone como valor por defecto del selector cuando la base es ciudad.
     * Es el mismo dato que el usuario ya registró al crear la propiedad, y
     * pedirlo otra vez no agrega información: solo la posibilidad de que se
     * escriba distinto al original.
     *
     * Solo se propone si tiene IPC. Ofrecer una ciudad sin serie como valor
     * predeterminado pondría al usuario en una situación que no puede resolver
     * desde el formulario del reajuste.
     */
    private static function propertyCityFor(mixed $rentalId): ?string
    {
        if ($rentalId === null || $rentalId === '') {
            return null;
        }

        $rental = Rental::with('property.geography')->find($rentalId);

        $geography = $rental?->property?->geography;

        return $geography?->has_ipc === true ? $geography->code : null;
    }

    private static function defaultIpcYear(): ?int
    {
        $available = app(IpcSeriesRepository::class)->availableYears();

        return $available[0] ?? null;
    }

    /**
     * El porcentaje de variación anual del IPC para un año y geografía dados.
     *
     * Se usa para auto-completar el «Porcentaje pactado» con el valor real del
     * año del IPC elegido, de modo que el usuario vea de inmediato qué cifra
     * aplica el artículo 20 y solo tenga que escribir otra si pacta distinto.
     *
     * Devuelve null cuando no hay dato, en lugar de fallar: el panel de
     * comprobaciones ya informa de la ausencia con un mensaje propio.
     */
    private static function percentageForYear(?string $geography, int $year): ?float
    {
        $code = $geography ?? \App\Dto\Geography::nationalCode();

        $observation = app(IpcSeriesRepository::class)->decemberOf($code, $year);

        return $observation?->calendarYearVariation();
    }

    /*
     * Vuelve a proponer el «Porcentaje pactado» con el % de la geografía y el
     * año elegidos en ese momento. Se llama cada vez que cambia la base, la
     * ciudad o el año: cualquiera de los tres mueve la serie de IPC de la que
     * sale el porcentaje, y dejar el valor viejo sería mostrar una cifra que ya
     * no corresponde a lo seleccionado.
     *
     * El año llega como argumento para que el que llama decida cuál usar. Eso
     * importa cuando el estado aún no se actualizó en pantalla: por ejemplo, al
     * cambiar de base nacional a ciudad, el año vigente puede no existir para
     * la ciudad y en ese caso hay que recalcular con el año ya resuelto, no con
     * el que está en el campo.
     */
    private static function refreshPactado(Set $set, Get $get, ?int $ipcYear = null): void
    {
        $year = $ipcYear ?? $get('ipc_year');

        $year = ($year === null || $year === '') ? null : (int) $year;

        if ($year === null || $year <= 0) {
            return;
        }

        $pct = self::percentageForYear(self::geographyCodeFor($get), $year);

        if ($pct !== null) {
            $set('pactado_pct', round((float) $pct, 2));
        }
    }

    /**
     * La ayuda de la fecha cambia según el caso, porque "la fecha propuesta es el
     * aniversario" no sirve de nada si el alquiler no tiene reajustes previos.
     */
    private static function effectiveFromHelp(Get $get): string
    {
        $rentalId = $get('rental_id');

        if ($rentalId === null) {
            return __('rent_adjustment.form.effective_from_pick_rental');
        }

        $rental = Rental::find($rentalId);

        if ($rental === null) {
            return __('rent_adjustment.form.effective_from_pick_rental');
        }

        $legal = $rental->nextAdjustmentDueDate();
        $proposer = app(RentalFacade::class);
        $suggested = $proposer->suggestedAdjustmentDate($legal);

        $base = $legal->lessThanOrEqualTo(today())
            ? __('rent_adjustment.form.effective_from_overdue', ['date' => $legal->format('d/m/Y')])
            : __('rent_adjustment.form.effective_from_help');

        $businessDay = $proposer->adjustmentDateExplanation($legal, $suggested);

        return $businessDay === '' ? $base : $base.' '.$businessDay;
    }

    /*
    |--------------------------------------------------------------------
    | La preview del cálculo
    |--------------------------------------------------------------------
    |
    | POR QUÉ UNA TABLA Y NO UNA LÍNEA DE AYUDA
    |
    | El campo «Canon reajustado» pide un resultado sin mostrar de dónde sale. Eso
    | lo hace parecer un número arbitrario que el usuario debe aceptar o
    | reemplazar a ciegas. La pregunta que realmente se hace al verlo es "¿de
    | dónde sale este número?", y la respuesta necesita más de una frase.
    |
    | LA CADENA, EN ORDEN
    |
    |   canon vigente  → el monto sobre el que se aplica el IPC
    |   IPC aplicado   → el porcentaje, con su año y su geografía
    |   incremento     → la diferencia entre ambos
    |   tope           → canon vigente + incremento, que es lo que respalda el art. 20
    |   canon elegido  → lo que el usuario escribió
    |   diferencia     → lo que se aparta del tope
    |
    | «Canon vigente» NO es el canon original del contrato. Es el resultado del
    | último reajuste aplicado, y por eso la tabla lo llama así y no «canon
    | anterior». Confundir las dos cosas es el error que hizo necesario todo el
    | trabajo del ancla y del reproceso, y el formulario debe dejar claro cuál de
    | las dos está mostrando.
    |
    | CUANDO NO SE PUEDE CALCULAR, NO SE MUESTRA NADA
    |
    | Devolver vacío es preferible a un mensaje de error. Todavía no se ha
    | elegido alquiler, o no hay dato de IPC para esa ciudad: son estados
    | intermedios de un formulario que se está llenando, no fallos. El panel de
    | comprobaciones es el que informa los problemas de verdad.
    */
    private static function rentPreview(Get $get): string
    {
        $calculation = self::calculationFor($get);

        return $calculation === null
            ? ''
            : AdjustmentCalculationTable::make($calculation)->render();
    }

    /**
     * Las cifras del calculo para la combinacion actual, o null si todavia no se pueden resolver.
     *
     * Devolver null en vez de un error es lo correcto: son estados intermedios de un
     * formulario que se esta llenando, no fallos. El aviso de comprobaciones es el
     * que informa de los problemas de verdad.
     */
    private static function calculationFor(Get $get): ?AdjustmentCalculation
    {
        $rentalId = $get('rental_id');
        $effectiveFrom = $get('effective_from');

        if ($rentalId === null || $rentalId === '' || $effectiveFrom === null || $effectiveFrom === '') {
            return null;
        }

        $rental = Rental::find($rentalId);

        if ($rental === null) {
            return null;
        }

        try {
            $snapshot = self::resolveSnapshot($get, \Carbon\Carbon::parse($effectiveFrom));
        } catch (\RuntimeException) {
            return null;
        }

        if ($snapshot === null) {
            return null;
        }

        $requested = $get('new_rent');
        $pactado = $get('pactado_pct');

        return AdjustmentCalculation::make(
            currentRent: app(RentalFacade::class)->currentRentFor($rental),
            percentage: $snapshot->percentage,
            year: $snapshot->year,
            geographyName: $snapshot->geographyName,
            requestedRent: $requested === null || $requested === '' ? null : (float) $requested,
            pactadoPct: $pactado === null || $pactado === '' ? null : (float) $pactado,
        );
    }

    private static function money(float $amount): string
    {
        return app(AppSettings::class)->currency().' '.number_format($amount, 0, ',', '.');
    }

    /**
     * La ayuda de las observaciones cambia: si hay algo que confirmar, la nota deja de
     * ser opcional y hay que decirlo.
     */
    private static function notesHelp(Get $get): string
    {
        return self::deviates($get)
            ? __('rent_adjustment.form.notes_help_required')
            : __('rent_adjustment.form.notes_help');
    }

    /**
     * Propone un canon reajustado a partir del IPC.
     *
     * Sugiere el tope, no un incremento cualquiera: lo que el DANE respalda es el
     * canon que multiplica por el IPC. El arrendador puede pasarse y el sistema
     * lo avisa.
     */
    private static function suggestRent(Set $set, Get $get, Rental $rental): void
    {
        $effectiveFrom = $get('effective_from');

        if ($effectiveFrom === null || $effectiveFrom === '') {
            return;
        }

        $snapshot = self::resolveSnapshot($get, \Carbon\Carbon::parse($effectiveFrom));

        if ($snapshot === null) {
            return;
        }

        $previous = app(RentalFacade::class)->currentRentFor($rental);

        $set('new_rent', $snapshot->applyTo($previous));
    }

    /**
     * Vuelve a calcular la propuesta y el panel de avisos cuando algo cambia.
     */
    private static function refreshSuggestions(Set $set, Get $get): void
    {
        $rentalId = $get('rental_id');

        if ($rentalId === null) {
            return;
        }

        self::suggestRent($set, $get, Rental::find($rentalId) ?? new Rental);
    }

    /**
     * El IPC que corresponde a la combinacion elegida, o null si falta el dato.
     */
    private static function resolveSnapshot(Get $get, \DateTimeInterface $effectiveFrom): ?\App\Dto\IpcSnapshot
    {
        $basis = IpcBasis::from((string) ($get('ipc_basis') ?: IpcBasis::National->value));
        $code = $basis->isStatutory() ? null : ($get('geography_code') ?: null);

        $pactado = $get('pactado_pct');

        $ipcYear = $get('ipc_year');

        try {
            return app(RentalFacade::class)->previewAdjustmentIpc(
                rental: new Rental(['start_date' => today(), 'monthly_amount' => 1]),
                effectiveFrom: $effectiveFrom,
                basis: $basis,
                geographyCode: $code,
                // El año QUE ELIJO EL USUARIO, no el que la ley fijaría.
                //
                // Esto antes pasaba en null, con lo que previewIpc caía siempre en
                // el año estatutorio. Consecuencia: si la vigencia y el año
                // elegido no coincidían (que es justo el caso que hay que poder
                // registrar acknowledging), la preview se quedaba vacía justo
                // cuando más hacía falta: en la combinación que requiere
                // explicación. Con el año explícito, lo que se muestra es lo que
                // se va a registrar.
                ipcYear: $ipcYear === null || $ipcYear === '' ? null : (int) $ipcYear,
                pactadoPercentage: $pactado === null || $pactado === '' ? null : (float) $pactado,
            );
        } catch (RuntimeException) {
            // Todavia no hay dato para esa combinacion. El panel de avisos lo
            // explica con precision; aqui basta con no proponer nada.
            return null;
        }
    }

    /**
     * Las comprobaciones legales para la combinacion actual.
     *
     * Se ejecuta la MISMA cadena de reglas que corre al guardar. Que dos lugares
     * compartan la regla es correcto: el formulario la anticipa y el servicio la
     * impone, porque una pantalla nunca es la garantia de nada.
     *
     * Devuelve cadena vacia cuando no hay nada que comprobar, y el bloque se oculta.
     */
    private static function legalPanel(Get $get): string
    {
        $rentalId = $get('rental_id');
        $effectiveFrom = $get('effective_from');

        if ($rentalId === null || $rentalId === '' || $effectiveFrom === null || $effectiveFrom === '') {
            return '';
        }

        $rental = Rental::find($rentalId);

        if ($rental === null) {
            return '';
        }

        $basis = IpcBasis::from((string) ($get('ipc_basis') ?: IpcBasis::National->value));
        $pactado = $get('pactado_pct');
        $newRent = $get('new_rent');

        $result = app(RentalFacade::class)->validateAdjustment(
            rental: $rental,
            effectiveFrom: \Carbon\Carbon::parse($effectiveFrom),
            requestedRent: $newRent === null || $newRent === '' ? 0.0 : (float) $newRent,
            basis: $basis,
            geographyCode: $basis->isStatutory() ? null : ($get('geography_code') ?: null),
            pactadoPercentage: $pactado === null || $pactado === '' ? null : (float) $pactado,
            acknowledged: (bool) ($get('ipc_outside_statutory_year') ?? false),
            notes: $get('notes'),
        );

        return LegalChecksPanel::make($result['messages'])->render();
    }

    private static function legalPreview(Get $get): string
    {
        return self::legalPanel($get);
    }

    /**
     * Si la combinacion elegida se aparta del articulo 20.
     */
    /*
    | Si la combinación elegida se aparta del artículo 20, y POR QUÉ.
    |
    | Antes esto devolvía un booleano. Con eso la interfaz solo podía hacer dos
    | cosas: mostrar la sección o esconderla. No podía explicar el motivo, y el
    | usuario veía aparecer una casilla de confirmación sin saber qué la había
    | aparecido.
    |
    | Ahora devuelve la LISTA de motivos, y cada uno lleva su propia explicación.
    | Eso es lo que hace que la sección sea dinámica de verdad: aparece cuando hay
    | algo que confirmar, dice qué, y desaparece cuando ya no hay nada.
    |
    | Los motivos son cuatro y ninguno depende de los otros:
    |
    |   1. el año del IPC no es el que fija la ley para esa vigencia;
    |   2. la base es una ciudad, y el artículo 20 no distingue ciudades;
    |   3. las partes pactaron un porcentaje distinto al IPC;
    |   4. el canon escrito excede el tope del artículo 20.
    |
    | El cuarto se calcula con las cifras y no leyendo campos, porque «excede» es
    | una comparación, no un estado del formulario.
    *
    * @return list<string>
    */
    private static function deviationReasons(Get $get): array
    {
        $reasons = [];

        $effectiveFrom = $get('effective_from');
        $ipcYear = $get('ipc_year');
        $basis = IpcBasis::from((string) ($get('ipc_basis') ?: IpcBasis::National->value));

        if ($effectiveFrom !== null && $effectiveFrom !== '' && $ipcYear !== null && $ipcYear !== '') {
            $statutoryYear = (int) \Carbon\Carbon::parse($effectiveFrom)->format('Y') - 1;

            if ((int) $ipcYear !== $statutoryYear) {
                $reasons[] = __('rent_adjustment.deviation.year', [
                    'chosen' => (int) $ipcYear,
                    'statutory' => $statutoryYear,
                ]);
            }
        }

        /*
         * La ciudad solo es separación cuando su porcentaje difiere del total
         * nacional del mismo año. Si coinciden, usar la ciudad no cambia el
         * resultado frente al artículo 20, y eso lo dice el panel de
         * comprobaciones como referencia en lugar de pedir confirmación.
         */
        $cityIsReason = false;

        if ($basis === IpcBasis::City && $ipcYear !== null && $ipcYear !== '') {
            $cityPct = self::percentageForYear(self::geographyCodeFor($get), (int) $ipcYear);
            $nationalPct = self::percentageForYear(null, (int) $ipcYear);

            $sameAsNational = $cityPct !== null
                && $nationalPct !== null
                && round((float) $cityPct, 2) === round((float) $nationalPct, 2);

            if (! $sameAsNational) {
                $reasons[] = __('rent_adjustment.deviation.city', [
                    'geography' => self::cityNameFor($get),
                ]);
                $cityIsReason = true;
            }
        }

        /*
         * El pactado distinto del IPC es motivo, salvo que la ciudad ya lo
         * opaque: si el apartamiento es por la geografía, el pactado queda
         * dentro de esa misma separación y listarlo aparte sería ruido.
         */
        $pactado = $get('pactado_pct');

        if (! $cityIsReason && $pactado !== null && $pactado !== '' && $ipcYear !== null && $ipcYear !== '') {
            $effectivePct = self::percentageForYear(self::geographyCodeFor($get), (int) $ipcYear);

            $matchesIpc = $effectivePct !== null
                && round((float) $pactado, 2) === round((float) $effectivePct, 2);

            if (! $matchesIpc) {
                $reasons[] = __('rent_adjustment.deviation.pactado', [
                    'pct' => number_format((float) $pactado, 2, ',', '.'),
                ]);
            }
        }

        // El exceso se mide sobre las cifras, no sobre el estado del formulario:
        // que el canon escrito sea mayor que el tope es una comparación.
        $calculation = self::calculationFor($get);

        if ($calculation !== null && $calculation->exceedsCap()) {
            $reasons[] = __('rent_adjustment.deviation.excess', [
                'amount' => self::money($calculation->differenceFromCap()),
            ]);
        }

        return $reasons;
    }

    /**
     * @return list<string>
     */
    private static function deviations(Get $get): array
    {
        return self::deviationReasons($get);
    }

    private static function deviates(Get $get): bool
    {
        return self::deviationReasons($get) !== [];
    }

    /**
     * Los motivos que exigen que las partes confirmen el apartamiento.
     *
     * Ahora es la misma lista que `deviationReasons()`. Antes el porcentaje
     * pactado se excluía de aquí porque se leía como el «hasta» del artículo 20,
     * que la ley prevé. Con el campo autocompletado con el % del año del IPC, la
     * lectura cambió: si el usuario lo deja como está, coincide con el cálculo
     * legal y no hay motivo (ni siquiera entra en `deviationReasons()`); pero si
     * escribe otra cifra, es una decisión de las partes que se aparta del
     * artículo 20 y debe confirmarse y documentarse, igual que el año o la
     * ciudad. Por eso ya no se filtra.
     *
     * @return list<string>
     */
    private static function acknowledgementReasons(Get $get): array
    {
        return self::deviationReasons($get);
    }

    private static function needsPartyDecision(Get $get): bool
    {
        return self::acknowledgementReasons($get) !== [];
    }

    /**
     * La introducción de la sección de partes.
     *
     * Dice qué hay que decidir, no qué hay seleccionado. La lista de motivos va
     * dentro, y esa separación evita que la descripción salte de texto cada vez
     * que se toca un campo.
     */
    private static function partyDecisionsDescription(Get $get): string
    {
        if (! self::needsPartyDecision($get)) {
            return '';
        }

        return __('rent_adjustment.sections.party_decisions_why');
    }

    /**
     * Los motivos, como lista dentro de la sección.
     *
     * Vacía cuando no hay ninguno, que es el caso en que la sección está oculta.
     */
    private static function deviationReasonsHtml(Get $get): string
    {
        $reasons = self::deviationReasons($get);

        if ($reasons === []) {
            return '';
        }

        $items = '';

        foreach ($reasons as $reason) {
            $items .= '<li>'.e($reason).'</li>';
        }

        return '<div class="ajuste-reasons">'
            .'<p class="ajuste-reasons__title">'.e(__('rent_adjustment.sections.party_decisions_reasons')).'</p>'
            .'<ul class="ajuste-reasons__list">'.$items.'</ul>'
            .'</div>';
    }

    /**
     * La ayuda del canon escrito.
     *
     * Es un texto corto que solo aparece cuando ya hay cifras que comparar. La
     * explicación larga vive en la tabla de al lado, que es donde se lee.
     */
    private static function newRentHelp(Get $get): string
    {
        $calculation = self::calculationFor($get);

        if ($calculation === null) {
            return '';
        }

        return match (true) {
            $calculation->exceedsCap() => __('rent_adjustment.form.new_rent_help_above'),
            $calculation->belowCap() => __('rent_adjustment.form.new_rent_help_below'),
            default => __('rent_adjustment.form.new_rent_help_at_cap', [
                'cap' => self::money($calculation->cap),
            ]),
        };
    }

    /**
     * El nombre de la ciudad elegida, o el total nacional si la base es nacional.
     */
    private static function cityNameFor(Get $get): string
    {
        $code = self::geographyCodeFor($get);

        if ($code === null) {
            return (string) config('dane_ipc_sql.national_name', 'NACIONAL');
        }

        return (string) (\App\Models\Geography::query()->where('code', $code)->value('name') ?? $code);
    }

    /**
     * Las ciudades con IPC que hay sincronizadas.
     *
     * El total nacional NO aparece aquí: cuando la base es "ciudad" el usuario ya
     * eligió apartarse del artículo 20, y ofrecerle el nacional en la lista lo
     * convertiría en un valor contradictorio.
     *
     * @return array<string, string>
     */
    private static function cityOptions(): array
    {
        return \App\Models\Geography::ipcCities();
    }

    /*
    | Las ayudas de la base y del año se escriben según lo que haya elegido el
    | usuario, no con un texto fijo.
    |
    | El caso que obliga a esto es la base ciudad sin datos cargados: si el año
    | no tiene serie para esa ciudad, el mensaje tiene que decirlo en el selector
    | de año, que es donde el usuario está mirando. Un texto genérico sobre el
    | artículo 20 ahí no dice nada útil.
    */
    private static function cityHelper(Get $get): string
    {
        if (self::cityOptions() === []) {
            return __('ipc.messages.no_cities');
        }

        $code = self::geographyCodeFor($get);

        if ($code === null) {
            return '';
        }

        $year = $get('ipc_year');

        if ($year === null || $year === '') {
            return '';
        }

        $hasData = app(IpcSeriesRepository::class)
            ->yearOptions($code)[(int) $year] ?? null;

        return $hasData === null
            ? __('rent_adjustment.form.geography_code_no_year', [
                'geography' => (string) (\App\Models\Geography::query()
                    ->where('code', $code)
                    ->value('name') ?? $code),
                'year' => (int) $year,
            ])
            : '';
    }

    private static function ipcHelper(Get $get): string
    {
        $options = app(IpcSeriesRepository::class)->yearOptions(self::geographyCodeFor($get));

        if ($options === []) {
            return __('ipc.messages.no_data');
        }

        // Cuando hay base ciudad, el porcentaje ya va al lado de cada año en la
        // etiqueta del selector. Recordar aquí el artículo 20 sería repetir lo
        // que la propia lista muestra.
        return $get('ipc_basis') === IpcBasis::City->value
            ? ''
            : __('rent_adjustment.descriptions.ipc');
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

                Tables\Columns\TextColumn::make('sequence')
                    ->label(__('rent_adjustment.table.sequence'))
                    ->sortable(),

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
                    // Cuando hubo pacto, el IPC legal se muestra aparte. Si solo
                    // aparece el aplicado, parece que el IPC fue ese.
                    ->suffix(fn (RentAdjustment $record): string => $record->legal_pct !== null
                        && abs((float) $record->legal_pct - (float) $record->ipc_percentage) > 0.001
                            ? ' (legal '.number_format((float) $record->legal_pct, 2, ',', '.').' %)'
                            : '')
                    ->sortable(),

                Tables\Columns\TextColumn::make('geography_name')
                    ->label(__('rent_adjustment.table.geography'))
                    ->placeholder('Nacional')
                    ->toggleable(isToggledHiddenByDefault: true),

                Tables\Columns\TextColumn::make('legal_cap_rent')
                    ->label(__('rent_adjustment.table.legal_cap'))
                    ->money(fn (): string => app(AppSettings::class)->currency()),

                Tables\Columns\TextColumn::make('status')
                    ->label(__('rent_adjustment.table.status'))
                    ->badge()
                    ->state(fn (RentAdjustment $record): string => $record->exceedsCap() ? 'exceeds_cap' : 'within_cap')
                    ->formatStateUsing(fn (string $state): string => __('rent_adjustment.statuses.'.$state))
                    ->color(fn (string $state): string => $state === 'exceeds_cap' ? 'danger' : 'success'),

                Tables\Columns\TextColumn::make('state')
                    ->label(__('rent_adjustment.table.status'))
                    ->badge()
                    ->state(fn (RentAdjustment $record): string => $record->state()->value)
                    ->formatStateUsing(fn (string $state): string => __('rent_adjustment.states.'.$state))
                    ->color(fn (string $state): string => \App\Enums\AdjustmentState::from($state)->color()),

                Tables\Columns\TextColumn::make('effective_from')
                    ->label(__('rent_adjustment.table.effective_from'))
                    ->date()
                    ->sortable(),

                Tables\Columns\TextColumn::make('notified_on')
                    ->label(__('rent_adjustment.table.notified_on'))
                    ->date()
                    ->placeholder('—')
                    ->description(fn (RentAdjustment $record): ?string => $record->notification_method?->label())
                    ->sortable(),
            ])
            ->defaultSort('effective_from', 'desc')
            ->actions([
                Tables\Actions\ActionGroup::make([
                    ...self::letterActions(),
                    self::applyAction(),
                    self::markNotifiedAction(),
                    self::recalculateAction(),
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
                builder: fn (RentAdjustment $record, string $format): Document => app(RentalFacade::class)
                    ->adjustmentLetterFor($record, $format),
            ),

            DocumentAction::text(
                name: 'letter_text',
                label: __('invoice.buttons.copy_document_text'),
                builder: fn (RentAdjustment $record, string $format): Document => app(RentalFacade::class)
                    ->adjustmentLetterFor($record, $format),
            ),

            DocumentAction::file(
                name: 'letter_json',
                label: __('rent_adjustment.buttons.letter').' · JSON',
                builder: fn (RentAdjustment $record, string $format): Document => app(RentalFacade::class)
                    ->adjustmentLetterFor($record, $format),
            ),
        ];
    }

    /**
     * Aplica el canon reajustado al alquiler.
     *
     * Ya no bloquea por exceder el IPC. La revision del modulo decidio permitirlo
     * y avisar, porque la jurisprudencia que define si el IPC es tope o parametro
     * no pudo verificarse y el texto admite ambas lecturas. Lo que si exige el
     * articulo es la comunicacion, y esa se comprueba al marcar el reajuste como
     * notificado.
     */
    private static function applyAction(): Tables\Actions\Action
    {
        return Tables\Actions\Action::make('apply')
            ->label(__('rent_adjustment.buttons.apply'))
            ->icon('heroicon-o-check-circle')
            ->color('primary')
            ->requiresConfirmation()
            ->visible(fn (RentAdjustment $record): bool => $record->state()->canApply())
            ->action(function (RentAdjustment $record): void {
                try {
                    app(RentalFacade::class)->applyAdjustment($record);
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
            ->visible(fn (RentAdjustment $record): bool => $record->state()->canNotify())
            ->requiresConfirmation()
            ->form([
                Forms\Components\DatePicker::make('notified_on')
                    ->label(__('rent_adjustment.form.notification_date'))
                    ->default(today())
                    ->required(),

                // El medio no es un detalle de forma. El parrafo 2 del articulo 20
                // condiciona la oponibilidad a que la comunicacion llegue por el
                // canal que la ley o el contrato reconocen.
                Forms\Components\Select::make('notification_method')
                    ->label(__('rent_adjustment.notification_methods.label'))
                    ->helperText(__('rent_adjustment.notification_methods.help'))
                    ->options(NotificationMethod::options())
                    ->required(),
            ])
            ->action(function (RentAdjustment $record, array $data): void {
                try {
                    app(RentalFacade::class)->markAdjustmentNotified(
                        $record,
                        \Carbon\Carbon::parse($data['notified_on']),
                        NotificationMethod::from($data['notification_method']),
                    );
                } catch (RuntimeException $exception) {
                    Notification::make()
                        ->warning()
                        ->title($exception->getMessage())
                        ->persistent()
                        ->send();

                    return;
                }

                Notification::make()
                    ->success()
                    ->title(__('rent_adjustment.notifications.notified'))
                    ->send();
            });
    }

    /**
     * Reprocesa la cadena del alquiler desde su canon base.
     */
    private static function recalculateAction(): Tables\Actions\Action
    {
        return Tables\Actions\Action::make('recalculate')
            ->label(__('rent_adjustment.buttons.recalculate'))
            ->icon('heroicon-o-arrow-path')
            ->color('gray')
            ->requiresConfirmation()
            ->action(function (RentAdjustment $record): void {
                $result = app(RentalFacade::class)->recalculateAdjustmentChain($record->rental);

                Notification::make()
                    ->success()
                    ->title(__('rent_adjustment.notifications.recalculated'))
                    ->body(sprintf(
                        '%d reajustes actualizados. Canon vigente: $%s.',
                        $result['touched'],
                        number_format($result['current_rent'], 0, ',', '.'),
                    ))
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

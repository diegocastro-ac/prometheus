<?php

namespace App\Filament\Resources;

use App\Contracts\CurrentUserContextInterface;
use App\Enums\ActItemState;
use App\Filament\Resources\DeliveryActResource\Pages;
use App\Models\DeliveryAct;
use App\Models\Rental;
use App\Models\Space;
use App\Settings\AppSettings;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class DeliveryActResource extends Resource
{
    protected static ?string $model = DeliveryAct::class;

    protected static ?string $slug = 'administration/delivery-acts';

    protected static ?int $navigationSort = 2;

    protected static ?string $navigationIcon = 'heroicon-o-clipboard-document-check';

    public static function getNavigationGroup(): ?string
    {
        return __('act.navigation.group');
    }

    public static function getNavigationLabel(): string
    {
        return __('act.navigation_label');
    }

    public static function getModelLabel(): string
    {
        return __('act.navigation.labels.singular');
    }

    public static function getPluralModelLabel(): string
    {
        return __('act.navigation.labels.plural');
    }

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make(__('act.sections.parties'))
                    ->schema([
                        Forms\Components\Select::make('rental_id')
                            ->label(__('act.form.rental'))
                            ->relationship('rental', 'name')
                            ->options(fn () => self::rentalOptions())
                            ->required()
                            ->live()
                            ->afterStateUpdated(function ($state, Forms\Set $set): void {
                                self::fillPartiesFromRental($state, $set);
                            }),

                        Forms\Components\Select::make('type')
                            ->label(__('act.form.type'))
                            ->options(self::actTypes())
                            ->default('entrega')
                            ->required()
                            ->searchable(),

                        Forms\Components\TextInput::make('landlord_name')
                            ->label(__('act.form.landlord_name'))
                            ->required()
                            ->maxLength(255),

                        Forms\Components\TextInput::make('landlord_document')
                            ->label(__('act.form.landlord_document'))
                            ->maxLength(255),

                        Forms\Components\TextInput::make('tenant_name')
                            ->label(__('act.form.tenant_name'))
                            ->required()
                            ->maxLength(255),

                        Forms\Components\TextInput::make('tenant_document')
                            ->label(__('act.form.tenant_document'))
                            ->maxLength(255),

                        Forms\Components\DatePicker::make('occurred_at')
                            ->label(__('act.form.occurred_at'))
                            ->default(today())
                            ->required(),

                        Forms\Components\TimePicker::make('scheduled_at')
                            ->label(__('act.form.scheduled_at'))
                            ->seconds(false),
                    ])
                    ->columns(2),

                Forms\Components\Section::make(__('act.sections.readings'))
                    ->description(__('act.descriptions.readings'))
                    ->collapsible()
                    ->collapsed()
                    ->schema([
                        Forms\Components\TextInput::make('water_reading')
                            ->label(__('act.form.water_reading'))
                            ->maxLength(50),

                        Forms\Components\TextInput::make('energy_reading')
                            ->label(__('act.form.energy_reading'))
                            ->maxLength(50),

                        Forms\Components\TextInput::make('gas_reading')
                            ->label(__('act.form.gas_reading'))
                            ->maxLength(50),
                    ])
                    ->columns(3),

                Forms\Components\Section::make(__('act.sections.inventory'))
                    ->description(__('act.descriptions.inventory'))
                    ->collapsible()
                    ->collapsed()
                    ->schema([
                        Forms\Components\Repeater::make('items')
                            ->label(__('act.sections.inventory'))
                            ->schema([
                                Forms\Components\Section::make()
                                    ->schema([
                                        Forms\Components\Grid::make(2)
                                            ->schema([
                                                Forms\Components\TextInput::make('space')
                                                    ->label(__('act.form.space'))
                                                    ->datalist(fn () => Space::query()
                                                        ->where('user_id', app(CurrentUserContextInterface::class)->id())
                                                        ->orderBy('name')
                                                        ->pluck('name')
                                                        ->all())
                                                    ->required()
                                                    ->maxLength(255),

                                                Forms\Components\TextInput::make('name')
                                                    ->label(__('act.form.item_name'))
                                                    ->required()
                                                    ->maxLength(255),
                                            ]),

                                        Forms\Components\Grid::make(2)
                                            ->schema([
                                                Forms\Components\Select::make('state')
                                                    ->label(__('act.form.item_state'))
                                                    ->options(fn () => collect(ActItemState::cases())->mapWithKeys(
                                                        fn (ActItemState $state): array => [$state->value => $state->label()],
                                                    ))
                                                    ->default(ActItemState::BUENO->value)
                                                    ->required(),

                                                Forms\Components\TextInput::make('note')
                                                    ->label(__('act.form.item_note'))
                                                    ->placeholder(__('act.placeholders.item_note'))
                                                    ->maxLength(255),
                                            ]),

                                        Forms\Components\FileUpload::make('photo_path')
                                            ->label(__('act.form.item_photo'))
                                            ->image()
                                            ->directory('act-items')
                                            ->disk('public')
                                            ->visibility('public')
                                            ->columnSpanFull(),
                                    ]),
                            ])
                            ->defaultItems(0)
                            ->addActionLabel('+')
                            ->itemLabel(fn (array $state): ?string => $state['name'] ?? null),
                    ]),

                Forms\Components\Section::make(__('act.sections.commitments'))
                    ->description(__('act.descriptions.commitments'))
                    ->collapsible()
                    ->collapsed()
                    ->schema([
                        Forms\Components\Textarea::make('commitments')
                            ->label(__('act.form.commitments'))
                            ->placeholder(__('act.placeholders.commitments'))
                            ->rows(3),

                        Forms\Components\Textarea::make('observations')
                            ->label(__('act.form.observations'))
                            ->rows(3),
                    ])
                    ->columns(1),

                Forms\Components\Section::make(__('act.sections.signatures'))
                    ->collapsible()
                    ->collapsed()
                    ->schema([
                        Forms\Components\FileUpload::make('landlord_signature_path')
                            ->label(__('act.form.landlord_signature'))
                            ->image()
                            ->directory('signatures')
                            ->disk('public')
                            ->visibility('public'),

                        Forms\Components\FileUpload::make('tenant_signature_path')
                            ->label(__('act.form.tenant_signature'))
                            ->image()
                            ->directory('signatures')
                            ->disk('public')
                            ->visibility('public'),

                        Forms\Components\DateTimePicker::make('signed_at')
                            ->label(__('act.form.signed_at')),
                    ])
                    ->columns(2),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('type')
                    ->label(__('act.table.type'))
                    ->formatStateUsing(fn (string $state): string => self::actTypes()[$state] ?? $state)
                    ->badge()
                    ->sortable(),

                Tables\Columns\TextColumn::make('tenant_name')
                    ->label(__('act.table.tenant'))
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('occurred_at')
                    ->label(__('act.table.occurred_at'))
                    ->date()
                    ->sortable(),

                Tables\Columns\IconColumn::make('is_signed')
                    ->label(__('act.table.signed'))
                    ->boolean()
                    ->getStateUsing(fn (DeliveryAct $record): bool => $record->isSigned())
                    ->sortable(),

                Tables\Columns\TextColumn::make('items_count')
                    ->label(__('act.table.items'))
                    ->counts('items')
                    ->badge(),
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),

                // Entrada del Prototype: no guarda nada, solo abre el
                // formulario de creacion con ?from= para que la copia se haga
                // en el cliente y el guardado siga siendo del Builder.
                Tables\Actions\Action::make('derive')
                    ->label(__('act.actions.derive'))
                    ->icon('heroicon-o-document-duplicate')
                    ->url(fn (DeliveryAct $record): string => self::getUrl('create', ['from' => $record->id])),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ])
            ->defaultSort('occurred_at', 'desc');
    }

    /**
     * Cada arrendador solo ve sus propias actas.
     */
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->where('user_id', app(CurrentUserContextInterface::class)->id());
    }

    /**
     * El acta no tiene pagina de edicion a proposito. Un acta firmada es un
     * documento con valor para las partes: corregirla se hace con una nueva
     * acta, no reescribiendo la anterior.
     */
    public static function getPages(): array
    {
        return [
            'index' => Pages\ListDeliveryActs::route('/'),
            'create' => Pages\CreateDeliveryAct::route('/create'),
        ];
    }

    /**
     * @return array<string, string>
     */
    private static function actTypes(): array
    {
        return [
            'entrega' => __('act.types.entrega'),
            'recepcion' => __('act.types.recepcion'),
            'cambio_arrendatario' => __('act.types.cambio_arrendatario'),
        ];
    }

    /**
     * @return list<string>
     */
    private static function spaceCatalog(): array
    {
        return app(AppSettings::class)->spaceCatalog();
    }

    /**
     * @return array<int|string, string>
     */
    private static function rentalOptions(): array
    {
        return Rental::query()
            ->where('user_id', app(CurrentUserContextInterface::class)->id())
            ->orderBy('name')
            ->pluck('name', 'id')
            ->all();
    }

    /**
     * Al elegir el alquiler se propose el nombre del arrendatario. El Builder
     * es quien lo exige, asi que la pantalla no debe obligar a escribirlo a mano.
     */
    private static function fillPartiesFromRental($rentalId, Forms\Set $set): void
    {
        if ($rentalId === null) {
            return;
        }

        $rental = Rental::with('tenant')->find($rentalId);

        if ($rental?->tenant !== null) {
            $set('tenant_name', $rental->tenant->name);
        }
    }

    /**
     * Agrega un nuevo espacio al catálogo de espacios.
     */
    private static function addToSpaceCatalog(string $newSpace): void
    {
        $currentCatalog = self::spaceCatalog();

        if (! in_array($newSpace, $currentCatalog, true)) {
            $currentCatalog[] = $newSpace;
            \DB::table('app_settings')
                ->where('id', 1)
                ->update(['space_catalog' => json_encode($currentCatalog)]);
        }
    }
}

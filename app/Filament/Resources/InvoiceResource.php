<?php

namespace App\Filament\Resources;

use App\Contracts\CurrentUserContextInterface;
use App\Documents\Contracts\Document;
use App\Documents\DocumentService;
use App\Enums\InvoiceStatus;
use App\Filament\Resources\InvoiceResource\Pages;
use App\Filament\Support\DocumentAction;
use App\Models\Invoice;
use App\Models\Rental;
use App\Services\PaymentRecordingService;
use App\Settings\AppSettings;
use DomainException;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * La factura es la fuente de verdad de lo que se debe. El pago ya no se
 * administra por alquiler: se registra contra la factura que esta cubriendo.
 */
class InvoiceResource extends Resource
{
    protected static ?string $model = Invoice::class;

    protected static ?string $slug = 'administration/invoices';

    protected static ?int $navigationSort = 3;

    protected static ?string $navigationIcon = 'heroicon-o-document-text';

    public static function getNavigationGroup(): ?string
    {
        return __('invoice.navigation.group');
    }

    public static function getNavigationLabel(): string
    {
        return __('invoice.navigation_label');
    }

    public static function getModelLabel(): string
    {
        return __('invoice.navigation.labels.singular');
    }

    public static function getPluralModelLabel(): string
    {
        return __('invoice.navigation.labels.plural');
    }

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make(__('invoice.sections.general'))
                    ->description(__('invoice.descriptions.legal'))
                    ->schema([
                        Forms\Components\Select::make('rental_id')
                            ->label(__('invoice.form.rental_id'))
                            // El alcance se acota al usuario actual: el desplegable
                            // no debe ofrecer alquileres de otro arrendador.
                            ->relationship(
                                'rental',
                                'name',
                                modifyQueryUsing: fn ($query) => $query
                                    ->where('user_id', app(CurrentUserContextInterface::class)->id())
                                    ->orderBy('name'),
                            )
                            ->required()
                            ->live()
                            ->afterStateUpdated(fn ($state, Forms\Set $set) => self::suggestAmount($state, $set)),

                        Forms\Components\Select::make('concept')
                            ->label(__('invoice.form.concept'))
                            ->options(self::conceptOptions())
                            ->default('rent')
                            ->required()
                            ->live(),

                        Forms\Components\TextInput::make('custom_concept')
                            ->label(__('invoice.form.custom_concept'))
                            ->placeholder(__('invoice.placeholders.concept'))
                            ->maxLength(255)
                            ->visible(fn (Forms\Get $get): bool => $get('concept') === 'other')
                            ->required(fn (Forms\Get $get): bool => $get('concept') === 'other'),

                        Forms\Components\TextInput::make('amount')
                            ->label(__('invoice.form.amount'))
                            ->numeric()
                            ->prefix(fn (): string => app(AppSettings::class)->currency())
                            ->minValue(1)
                            ->required(),

                        Forms\Components\DatePicker::make('issued_at')
                            ->label(__('invoice.form.issued_at'))
                            ->default(today())
                            ->required(),

                        // El periodo es el mes que se cobra, que no siempre es
                        // el mes en que se emite: la factura de marzo puede
                        // emitirse en febrero. Por eso es un campo aparte y no
                        // un derivado de issued_at.
                        Forms\Components\TextInput::make('period')
                            ->label(__('invoice.form.period'))
                            ->placeholder(__('invoice.placeholders.period'))
                            ->default(fn (): string => now()->format('Y-m'))
                            ->maxLength(7)
                            ->minLength(7)
                            ->regex('/^\d{4}-(0[1-9]|1[0-2])$/')
                            ->rules([
                                'regex:/^\d{4}-(0[1-9]|1[0-2])$/',
                            ])
                            ->validationMessages([
                                'regex' => __('invoice.validation.period'),
                            ])
                            ->required(),

                        Forms\Components\DatePicker::make('due_at')
                            ->label(__('invoice.form.due_at'))
                            // El vencimiento sale de los dias configurados en
                            // Ajustes, no de un numero escrito a mano.
                            ->default(fn (): string => today()
                                ->addDays(app(AppSettings::class)->invoiceDueDays())
                                ->toDateString())
                            ->afterOrEqual('issued_at')
                            ->required(),

                        Forms\Components\Textarea::make('notes')
                            ->label(__('invoice.form.notes'))
                            ->placeholder(__('invoice.placeholders.notes'))
                            ->rows(2)
                            ->columnSpanFull(),
                    ])
                    ->columns(2),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
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
                    ->sortable()
                    ->searchable(),

                Tables\Columns\TextColumn::make('concept')
                    ->label(__('invoice.table.concept'))
                    ->formatStateUsing(fn (string $state): string => self::conceptLabel($state))
                    ->limit(40)
                    ->searchable(),

                Tables\Columns\TextColumn::make('rental.name')
                    ->label(__('invoice.table.rental'))
                    ->badge()
                    ->color('gray'),

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
            ->defaultSort('issued_at', 'desc')
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->label(__('invoice.form.status'))
                    ->options(fn () => collect(InvoiceStatus::cases())->mapWithKeys(
                        fn (InvoiceStatus $status): array => [$status->value => $status->label()],
                    )),
            ])
            ->actions([
                ...self::receiptDocumentActions(),
                ...self::invoiceDocumentActions(),
                self::recordPaymentAction(),
                self::annulAction(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ]);
    }

    /**
     * Comprobante de pago, en las dos familias.
     *
     * Las acciones solo existen en facturas pagadas. Ocultarlas no es la garantia:
     * el bloqueo real esta en PaymentReceipt::forInvoice(), que lanza si la factura
     * no esta pagada. Aqui se evita ofrecer un boton que siempre va a fallar.
     *
     * @return array<int, Tables\Actions\Action>
     */
    private static function receiptDocumentActions(): array
    {
        return [
            DocumentAction::pdf(
                name: 'receipt_pdf',
                label: __('invoice.buttons.download_receipt'),
                builder: fn (Invoice $record, string $format): Document => app(DocumentService::class)
                    ->receiptFor($record, $format),
            )->visible(fn (Invoice $record): bool => $record->isPaid()),

            DocumentAction::text(
                name: 'receipt_text',
                label: __('invoice.buttons.copy_document_text'),
                builder: fn (Invoice $record, string $format): Document => app(DocumentService::class)
                    ->receiptFor($record, $format),
            )->visible(fn (Invoice $record): bool => $record->isPaid()),
        ];
    }

    /**
     * Factura de arrendamiento, en las dos familias.
     *
     * A diferencia del comprobante, existe siempre: la factura es el documento que
     * se entrega para cobrar, tambien para las facturas ya pagadas.
     *
     * @return array<int, Tables\Actions\Action>
     */
    private static function invoiceDocumentActions(): array
    {
        return [
            DocumentAction::pdf(
                name: 'invoice_pdf',
                label: __('invoice.buttons.download_invoice'),
                builder: fn (Invoice $record, string $format): Document => app(DocumentService::class)
                    ->invoiceFor($record, $format),
            ),

            DocumentAction::text(
                name: 'invoice_text',
                label: __('invoice.buttons.copy_document_text'),
                builder: fn (Invoice $record, string $format): Document => app(DocumentService::class)
                    ->invoiceFor($record, $format),
            ),
        ];
    }

    /**
     * Registrar un abono. El boton se apaga en las facturas que ya no admiten
     * pagos, y el servicio vuelve a validar la regla aunque se llegue por URL.
     */
    private static function recordPaymentAction(): Tables\Actions\Action
    {
        return Tables\Actions\Action::make('record_payment')
            ->label(__('invoice.buttons.record_payment'))
            ->icon('heroicon-o-banknotes')
            ->visible(fn (Invoice $record): bool => $record->canReceivePayments())
            ->form([
                Forms\Components\TextInput::make('amount')
                    ->label(__('invoice.form.payment_amount'))
                    ->numeric()
                    ->minValue(1)
                    ->default(fn (Invoice $record): float => max($record->balance(), 0))
                    ->required(),

                Forms\Components\DatePicker::make('date')
                    ->label(__('invoice.form.payment_date'))
                    ->default(today())
                    ->maxDate(today())
                    ->required(),

                Forms\Components\Select::make('method')
                    ->label(__('invoice.form.payment_method'))
                    ->options(fn (): array => self::methodOptions())
                    ->default('efectivo')
                    ->required(),

                Forms\Components\TextInput::make('reference')
                    ->label(__('invoice.form.payment_reference'))
                    ->maxLength(100),
            ])
            ->action(function (Invoice $record, array $data): void {
                try {
                    $result = app(PaymentRecordingService::class)->record(
                        invoice: $record,
                        amount: (float) $data['amount'],
                        method: $data['method'] ?? null,
                        reference: $data['reference'] ?? null,
                        paidAt: \Illuminate\Support\Carbon::parse($data['date']),
                    );
                } catch (DomainException $exception) {
                    Notification::make()
                        ->danger()
                        ->title($exception->getMessage())
                        ->send();

                    return;
                }

                Notification::make()
                    ->success()
                    ->title(__('invoice.payment_recorded'))
                    ->body($result['invoice']->number)
                    ->send();
            });
    }

    private static function annulAction(): Tables\Actions\Action
    {
        return Tables\Actions\Action::make('annul')
            ->label(__('invoice.buttons.annul'))
            ->icon('heroicon-o-x-circle')
            ->color('danger')
            ->requiresConfirmation()
            ->visible(fn (Invoice $record): bool => $record->status !== InvoiceStatus::ANULADA)
            ->action(fn (Invoice $record) => $record->update(['status' => InvoiceStatus::ANULADA]));
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->where('user_id', app(CurrentUserContextInterface::class)->id());
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListInvoices::route('/'),
            'create' => Pages\CreateInvoice::route('/create'),
        ];
    }

    /**
     * @return array<string, string>
     */
    private static function conceptOptions(): array
    {
        return Invoice::conceptOptions();
    }

    /**
     * Traduce la clave de concepto. La tabla delega al modelo para que la clave y
     * su etiqueta tengan una sola definicion, y no una en la pantalla y otra en el
     * documento.
     */
    private static function conceptLabel(string $concept): string
    {
        return Invoice::conceptLabelFor($concept);
    }

    /**
     * @return array<string, string>
     */
    private static function methodOptions(): array
    {
        return [
            'efectivo' => __('invoice.methods.efectivo'),
            'transferencia' => __('invoice.methods.transferencia'),
            'consignacion' => __('invoice.methods.consignacion'),
        ];
    }

    /**
     * Al elegir alquiler y concepto, el canon propuesto se llena solo. Es una
     * ayuda, no una imposicion: el valor se puede editar.
     */
    private static function suggestAmount($rentalId, Forms\Set $set): void
    {
        if ($rentalId === null) {
            return;
        }

        $rental = Rental::find($rentalId);

        if ($rental !== null) {
            $set('amount', $rental->monthly_amount);
        }
    }
}

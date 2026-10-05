<?php

namespace App\Filament\Pages;

use App\Documents\Contracts\Document;
use App\Documents\DocumentService;
use App\Documents\Money;
use App\Enums\InvoiceStatus;
use App\Models\Invoice;
use App\Models\RentAdjustment;
use App\Models\Rental;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\Page;

/**
 * Pantalla de documentos.
 *
 * Existe porque los cuatro documentos no se dejan Reductionir a una accion de
 * tabla: el estado de cuenta pertenece a un alquiler y un periodo, la carta
 * pertenece a un reajuste, y los dos de la factura pertenecen a una fila. Aqui
 * conviven los cuatro con los datos que cada uno necesita.
 *
 * El formato se elige una vez, arriba, y a partir de ahi todos los botones
 * entregan la misma familia. Esa es la forma en que el Abstract Factory obliga a
 * trabajar: no se pide "el PDF de esto" boton por boton, se elige la familia y
 * se imprimen todos los documentos en ella.
 */
class DocumentsPage extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-document-duplicate';

    protected static ?int $navigationSort = 5;

    protected static ?string $slug = 'administration/documents';

    protected static string $view = 'filament.pages.documents';

    protected static ?string $title = null;

    public ?array $data = [];

    public function getTitle(): string
    {
        return __('invoice.documents.title');
    }

    public static function getNavigationLabel(): string
    {
        return __('invoice.documents.title');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('invoice.navigation.group');
    }

    /**
     * Documento de texto abierto en pantalla, si hay alguno.
     *
     * El PDF se descarga y el texto se muestra. Por eso el estado vive en la
     * pagina y no en un modal de Filament: despues de generar hay que poder
     * cambiar de documento sin recargar.
     */
    public ?string $textPreview = null;

    public function mount(): void
    {
        $this->form->fill([
            'rental_id' => null,
            'period' => null,
            'format' => 'text',
            'invoice_id' => null,
            'adjustment_id' => null,
        ]);
    }

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make(__('invoice.documents.format.label'))
                    ->description(__('invoice.documents.format.hint'))
                    ->schema([
                        Forms\Components\Select::make('format')
                            ->label(__('invoice.documents.format.label'))
                            ->options(fn (): array => app(DocumentService::class)->formatOptions())
                            ->default('text')
                            ->required()
                            ->live()
                            ->afterStateUpdated(fn () => $this->textPreview = null),
                    ]),

                Forms\Components\Section::make(__('invoice.buttons.statement'))
                    ->description(__('invoice.documents.hint.statement'))
                    ->schema([
                        Forms\Components\Select::make('rental_id')
                            ->label(__('invoice.form.rental_id'))
                            ->options(fn (): array => $this->rentalOptions())
                            ->searchable()
                            ->live()
                            ->afterStateUpdated(function (Forms\Set $set, ?string $state) {
                                $set('period', $this->getDefaultPeriod($state));
                                $this->textPreview = null;
                            }),

                        Forms\Components\Select::make('period')
                            ->label(__('invoice.form.period'))
                            ->options(fn (Forms\Get $get): array => $this->periodOptions($get('rental_id')))
                            ->searchable()
                            ->live()
                            ->disabled(fn (Forms\Get $get): bool => empty($get('rental_id')))
                            ->afterStateUpdated(fn () => $this->textPreview = null)
                            ->default(fn (Forms\Get $get): ?string => $this->getDefaultPeriod($get('rental_id')))
                            ->required(),
                    ])
                    ->columns(2),

                Forms\Components\Section::make(__('invoice.buttons.download_invoice'))
                    ->description(__('invoice.documents.hint.invoice'))
                    ->schema([
                        Forms\Components\Select::make('invoice_id')
                            ->label(__('invoice.form.status'))
                            ->options(fn (): array => $this->invoiceOptions())
                            ->searchable()
                            ->live()
                            ->afterStateUpdated(fn () => $this->textPreview = null),
                    ]),

                Forms\Components\Section::make(__('rent_adjustment.buttons.letter'))
                    ->description(__('invoice.documents.hint.adjustment'))
                    ->schema([
                        Forms\Components\Select::make('adjustment_id')
                            ->label(__('rent_adjustment.form.select'))
                            ->options(fn (): array => $this->adjustmentOptions())
                            ->searchable()
                            ->live()
                            ->afterStateUpdated(fn () => $this->textPreview = null),
                    ]),
            ])
            ->statePath('data');
    }

    /**
     * Estado de cuenta del alquiler y el periodo elegidos.
     */
    public function generateStatement(): mixed
    {
        $data = $this->form->getState();
        $rental = Rental::find($data['rental_id'] ?? null);

        if ($rental === null) {
            Notification::make()
                ->danger()
                ->title(__('invoice.form.rental_id').': elija un alquiler.')
                ->send();

            return null;
        }

        $statement = app(DocumentService::class)->statementFor(
            rental: $rental,
            period: (string) $data['period'],
            format: $data['format'],
        );

        if ($statement->invoices() === []) {
            Notification::make()
                ->warning()
                ->title(__('invoice.documents.empty_statement'))
                ->body($rental->name.' · '.$data['period'])
                ->send();
        }

        return $this->deliver($statement);
    }

    /**
     * Factura de arrendamiento de la factura elegida.
     */
    public function generateInvoice(): mixed
    {
        $data = $this->form->getState();
        $invoice = Invoice::find($data['invoice_id'] ?? null);

        if ($invoice === null) {
            Notification::make()
                ->danger()
                ->title(__('invoice.buttons.download_invoice').': elija una factura.')
                ->send();

            return null;
        }

        return $this->deliver(app(DocumentService::class)->invoiceFor($invoice, $data['format']));
    }

    /**
     * Carta de reajuste del ajuste elegido.
     */
    public function generateAdjustmentLetter(): mixed
    {
        $data = $this->form->getState();
        $adjustment = RentAdjustment::find($data['adjustment_id'] ?? null);

        if ($adjustment === null) {
            Notification::make()
                ->danger()
                ->title(__('rent_adjustment.buttons.letter').': elija un reajuste.')
                ->send();

            return null;
        }

        return $this->deliver(app(DocumentService::class)->adjustmentLetterFor($adjustment, $data['format']));
    }

    /**
     * Comprobante de la factura elegida.
     *
     * El bloqueo real esta en PaymentReceipt::forInvoice(), que lanza si la
     * factura no esta pagada. Aqui solo se evita el error innecesario.
     */
    public function generateReceipt(): mixed
    {
        $data = $this->form->getState();
        $invoice = Invoice::find($data['invoice_id'] ?? null);

        if ($invoice === null) {
            Notification::make()
                ->danger()
                ->title(__('invoice.buttons.download_receipt').': elija una factura.')
                ->send();

            return null;
        }

        if (! $invoice->isPaid()) {
            Notification::make()
                ->warning()
                ->title(__('invoice.receipt_locked'))
                ->body($invoice->number.' · '.__('invoice.statuses.'.$invoice->status->value))
                ->send();

            return null;
        }

        return $this->deliver(app(DocumentService::class)->receiptFor($invoice, $data['format']));
    }

    /**
     * El comprobante solo existe para facturas pagadas. El boton se apaga en las
     * demas.
     */
    public function receiptAvailable(?string $invoiceId): bool
    {
        if ($invoiceId === null) {
            return false;
        }

        return Invoice::find($invoiceId)?->status === InvoiceStatus::PAGADA;
    }

    /**
     * Entrega el documento con el mecanismo que el propio documento declara.
     *
     * La familia ya se resolvio al construir el documento; aqui no se decide
     * nada. Solo se obedece.
     */
    private function deliver(Document $document): mixed
    {
        if ($document->isDownload()) {
            // Usar el mismo enfoque que DocumentAction para PDFs
            $view = self::selectView($document);
            $settings = app(\App\Settings\AppSettings::class);

            $pdfBytes = \FlexPDF\Facades\Pdf::view($view, [
                'body' => $document->body(),
                'document' => $document,
                'settings' => $settings,
            ])->page('a4')->output();

            return response()->streamDownload(
                function () use ($pdfBytes) {
                    echo $pdfBytes;
                },
                $document->filename(),
                ['Content-Type' => 'application/pdf']
            );
        }

        $this->textPreview = $document->content();

        return null;
    }

    private static function selectView(\App\Documents\AbstractDocument $document): string
    {
        return match($document::class) {
            \App\Documents\RentInvoice::class => 'documents.invoice',
            \App\Documents\PaymentReceipt::class => 'documents.receipt',
            \App\Documents\AdjustmentLetter::class => 'documents.adjustment-letter',
            \App\Documents\MonthlyStatement::class => 'documents.monthly-statement',
            default => 'documents.document',
        };
    }

    /**
     * @return array<int, string>
     */
    protected function getFormActions(): array
    {
        return [];
    }

    private function rentalOptions(): array
    {
        return Rental::query()
            ->where('user_id', auth()->id())
            ->orderBy('name')
            ->pluck('name', 'id')
            ->all();
    }

    /**
     * Solo las facturas del usuario actual: el select no debe ofrecer lo que la
     * tabla ya esconderia.
     *
     * @return array<int, string>
     */
    private function invoiceOptions(): array
    {
        return Invoice::query()
            ->where('user_id', auth()->id())
            ->orderByDesc('issued_at')
            ->limit(200)
            ->get()
            ->mapWithKeys(fn (Invoice $invoice): array => [
                $invoice->id => sprintf(
                    '%s · %s · %s',
                    $invoice->number,
                    $invoice->period ?? '—',
                    __('invoice.statuses.'.$invoice->status->value),
                ),
            ])
            ->all();
    }

    /**
     * @return array<int, string>
     */
    private function adjustmentOptions(): array
    {
        return RentAdjustment::query()
            ->where('user_id', auth()->id())
            ->orderByDesc('effective_from')
            ->limit(200)
            ->get()
            ->mapWithKeys(fn (RentAdjustment $adjustment): array => [
                $adjustment->id => sprintf(
                    '%s · %s → %s',
                    $adjustment->rental?->name ?? '—',
                    Money::pesos($adjustment->previous_rent),
                    Money::pesos($adjustment->new_rent),
                ),
            ])
            ->all();
    }

    /**
     * @return array<int, string>
     */
    private function periodOptions(?string $rentalId): array
    {
        if ($rentalId === null) {
            return [];
        }

        return Invoice::query()
            ->where('rental_id', $rentalId)
            ->where('user_id', auth()->id())
            ->whereNotNull('period')
            ->orderByDesc('period')
            ->limit(60)
            ->pluck('period', 'period')
            ->unique()
            ->all();
    }

    private function getDefaultPeriod(?string $rentalId): ?string
    {
        if ($rentalId === null) {
            return null;
        }

        $lastPeriod = Invoice::query()
            ->where('rental_id', $rentalId)
            ->where('user_id', auth()->id())
            ->whereNotNull('period')
            ->orderByDesc('period')
            ->value('period');

        return $lastPeriod;
    }
}

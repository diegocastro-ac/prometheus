<?php

namespace App\Documents;

use App\Documents\Rendering\DocumentBody;
use App\Documents\Rendering\DocumentRenderer;
use App\Models\RentAdjustment;

/**
 * Carta de notificacion de reajuste del canon.
 *
 * Este es el documento con una exigencia legal, no solo de forma. El articulo 20
 * de la Ley 820 de 2003 dice dos cosas que la carta tiene que cumplir:
 *
 * 1. El incremento no puede superar el IPC del ano calendario inmediatamente
 *    anterior, y
 * 2. Si el arrendador incrementa, debe informar el monto y la fecha de
 *    vigencia al arrendatario, "so pena de ser inoponible". El pago del
 *    reajuste no da derecho a pedir devolucion si no se comunico.
 *
 * De ahi salen dos decisiones de implementacion: el ajuste se guarda con el
 * IPC congelado (para que la carta emitida no cambie si despues se corrige el
 * dato del DANE), y el exceso sobre el tope se avisa de forma destacada en lugar
 * de aplicar el incremento en silencio.
 */
class AdjustmentLetter extends AbstractDocument
{
    private function __construct(
        DocumentBody $body,
        DocumentRenderer $renderer,
        string $slug,
        private readonly RentAdjustment $adjustment,
    ) {
        parent::__construct($body, $renderer, $slug);
    }

    public static function forAdjustment(RentAdjustment $adjustment, DocumentRenderer $renderer): self
    {
        $document = new self(
            body: self::buildBody($adjustment)->withDocument($adjustment),
            renderer: $renderer,
            slug: 'reajuste-'.$adjustment->id,
            adjustment: $adjustment,
        );

        return $document;
    }

    public function adjustment(): RentAdjustment
    {
        return $this->adjustment;
    }

    public function preview(): string
    {
        return 'Carta de reajuste del alquiler '.$this->adjustment->rental?->name
            .': canon de '.Money::pesos($this->adjustment->previous_rent)
            .' a '.Money::pesos($this->adjustment->new_rent);
    }

    public function tenantName(): string
    {
        return $this->adjustment->rental?->tenant?->name ?? 'No indicado';
    }

    public function propertyName(): string
    {
        return $this->adjustment->rental?->property?->name ?? 'No indicado';
    }

    public function propertyAddress(): string
    {
        return $this->adjustment->rental?->property?->address ?? 'No indicado';
    }

    public function previousAmount(): float
    {
        return (float) $this->adjustment->previous_rent;
    }

    public function newAmount(): float
    {
        return (float) $this->adjustment->new_rent;
    }

    public function ipcYear(): ?int
    {
        return $this->adjustment->ipc_year;
    }

    public function ipcRate(): ?float
    {
        return $this->adjustment->ipc_percentage;
    }

    public function inflationAmount(): float
    {
        return $this->adjustment->increase();
    }

    public function exceedsLegalCap(): bool
    {
        return $this->adjustment->exceedsCap();
    }

    public function legalCapRate(): float
    {
        return $this->adjustment->ipc_percentage + 1.0;
    }

    public function legalAdjustedAmount(): float
    {
        return (float) ($this->adjustment->legal_cap_rent ?? $this->adjustment->new_rent);
    }

    public function date(): \Illuminate\Support\Carbon
    {
        return $this->adjustment->effective_from ?? now();
    }

    public function effectiveDate(): \Illuminate\Support\Carbon
    {
        return $this->adjustment->effective_from ?? now();
    }

    public function period(): string
    {
        return $this->adjustment->effective_from?->format('Y-m') ?? 'No indicado';
    }

    public function adjustmentDate(): \Illuminate\Support\Carbon
    {
        return $this->adjustment->effective_from ?? now();
    }

    private static function buildBody(RentAdjustment $adjustment): DocumentBody
    {
        $rental = $adjustment->rental;

        $body = DocumentBody::make(__('document.adjustment_letter'))
            ->withFields([
                __('document.rental') => $rental?->name ?? 'no indicado',
                __('document.previous_rent') => Money::pesos($adjustment->previous_rent),
                __('document.new_rent_amount') => Money::pesos($adjustment->new_rent),
                __('document.adjustment') => Money::pesos($adjustment->increase())
                    .' ('.Money::percent($adjustment->increasePercentage()).')',
                __('document.effective_from') => $adjustment->effective_from?->format('d/m/Y')
                    ?? 'sin fecha definida',
                __('document.ipc_applied') => $adjustment->ipc_percentage === null
                    ? 'no disponible'
                    : Money::percentPlain($adjustment->ipc_percentage).' del '.$adjustment->ipc_year,
                __('document.legal_cap') => $adjustment->legal_cap_rent === null
                    ? 'no disponible'
                    : Money::pesos($adjustment->legal_cap_rent),
            ]);

        if ($adjustment->exceedsCap()) {
            $body = $body->withWarning(sprintf(
                __('document.warning_exceeds_cap'),
                Money::pesos($adjustment->excess_over_cap),
                Money::pesos((float) $adjustment->legal_cap_rent),
            ));
        }

        $notes = [
            __('document.note_communication'),
            sprintf(
                __('document.note_ipc_source'),
                $adjustment->ipcRate?->source_name ?? 'DANE',
                $adjustment->ipc_year ?? 'no disponible',
                $adjustment->ipcRate?->source_url ?? 'sin enlace registrado',
            ),
        ];

        if ($adjustment->notes) {
            $notes[] = $adjustment->notes;
        }

        if ($adjustment->notified_on !== null) {
            $notes[] = __('document.notified_on').' '.$adjustment->notified_on->format('d/m/Y').'.';
        }

        return $body
            ->withDocument($adjustment)
            ->withNotes(...$notes)
            ->withFooter(
                __('document.generated_by').' '.now()->format('d/m/Y \a \l\a\s H:i'),
            );
    }
}
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
            body: self::buildBody($adjustment),
            renderer: $renderer,
            slug: 'reajuste-'.$adjustment->id,
            adjustment: $adjustment,
        );

        $document->body->setDocument($document);

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

        $body = DocumentBody::make('Comunicacion de reajuste del canon')
            ->withFields([
                'Alquiler' => $rental?->name ?? 'no indicado',
                'Canon anterior' => Money::pesos($adjustment->previous_rent),
                'Canon reajustado' => Money::pesos($adjustment->new_rent),
                'Incremento' => Money::pesos($adjustment->increase())
                    .' ('.Money::percent($adjustment->increasePercentage()).')',
                'Vigencia desde' => $adjustment->effective_from?->format('d/m/Y')
                    ?? 'sin fecha definida',
                'IPC aplicado' => $adjustment->ipc_percentage === null
                    ? 'no disponible'
                    : Money::percentPlain($adjustment->ipc_percentage).' del '.$adjustment->ipc_year,
                'Tope legal' => $adjustment->legal_cap_rent === null
                    ? 'no disponible'
                    : Money::pesos($adjustment->legal_cap_rent),
            ]);

        if ($adjustment->exceedsCap()) {
            $body = $body->withWarning(sprintf(
                'AVISO: el incremento pedido supera en %s el tope legal del IPC. '
                .'Segun el articulo 20 de la Ley 820 de 2003, un incremento por encima del IPC '
                .'solo opera si existe acuerdo escrito entre las partes. Sin ese acuerdo, el canon '
                .'que queda vigente es el de %s.',
                Money::pesos($adjustment->excess_over_cap),
                Money::pesos((float) $adjustment->legal_cap_rent),
            ));
        }

        $notes = [
            'Esta carta cumple el deber de comunicacion del articulo 20 de la Ley 820 de 2003. '
            .'Si no se comunica el monto ni la fecha de vigencia, el reajuste es inoponible al '
            .'arrendatario.',
            sprintf(
                'Fuente del indicador: %s, IPC del %s (%s).',
                $adjustment->ipcRate?->source_name ?? 'DANE',
                $adjustment->ipc_year ?? 'no disponible',
                $adjustment->ipcRate?->source_url ?? 'sin enlace registrado',
            ),
        ];

        if ($adjustment->notes) {
            $notes[] = $adjustment->notes;
        }

        if ($adjustment->notified_on !== null) {
            $notes[] = 'Comunicada el '.$adjustment->notified_on->format('d/m/Y').'.';
        }

        return $body
            ->withNotes(...$notes)
            ->withFooter(
                'Documento generado por Prometheus el '.now()->format('d/m/Y \a \l\a\s H:i'),
            );
    }
}
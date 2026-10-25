<?php

namespace App\Documents;

use App\Documents\Rendering\DocumentBody;
use App\Documents\Rendering\DocumentRenderer;
use App\Models\RentAdjustment;

/**
 * Carta de notificacion de reajuste del canon.
 *
 * Este es el documento con una exigencia legal, no solo de forma. El articulo 20
 * de la Ley 820 de 2003 dice tres cosas que la carta tiene que cumplir:
 *
 * 1. El incremento no puede superar el 100 % del IPC del ano calendario
 *    inmediatamente anterior, con el matiz de que la jurisprudencia que define si
 *    esa cifra es tope o parametro no pudo verificarse, asi que se avisa pero no
 *    se bloquea.
 *
 * 2. "Cada doce (12) meses de ejecucion del contrato bajo un mismo precio": la
 *    carta dice desde que canon se parte, porque es el que rigio y no el
 *    canon original. Esa distincion es la que un inquilino suele no ver y es la
 *    que explica por que el incremento no coincide con el IPC.
 *
 * 3. El aviso tiene que hacerse "a traves del servicio postal autorizado o
 *    mediante el mecanismo de notificacion personal expresamente establecido en
 *    el contrato, so pena de ser inoponible". Por eso la carta imprime el medio
 *    usado y no solo la fecha. Y por el parrafo 3, pagar no sanea la falta de
 *    comunicacion, asi que el incremento no se da por comunicado sin constancia.
 *
 * De ahi sale la ultima decision: el IPC queda congelado en el registro, para que
 * una carta ya emitida no cambie si despues el DANE corrige el dato.
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

        $document->attachToBody();

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

    /**
     * El canon original del alquiler, contra el que se ve el efecto acumulado.
     */
    public function anchorAmount(): ?float
    {
        return $this->adjustment->anchor_rent !== null
            ? (float) $this->adjustment->anchor_rent
            : null;
    }

    /**
     * El IPC que fija la ley, separado del que se aplico.
     */
    public function legalIpcRate(): ?float
    {
        return $this->adjustment->legal_pct !== null
            ? (float) $this->adjustment->legal_pct
            : null;
    }

    public function usesCityIpc(): bool
    {
        return ! $this->adjustment->isNationalBasis();
    }

    public function legalCapRate(): float
    {
        return ((float) ($this->adjustment->legal_pct ?? $this->adjustment->ipc_percentage)) + 1.0;
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

        // El canon base solo aparece cuando hay reajustes previos. Explicar de
        // donde sale el canon anterior evita la pregunta que siempre surge: si el
        // IPC fue del 5 %, por que el incremento no es del 5 % sobre el canon
        // original. La respuesta es que el articulo 20 cuenta desde el precio que
        // regia, no desde el canon inicial.
        if ($adjustment->cumulativeIncreasePercentage() !== $adjustment->increasePercentage()) {
            $body = $body->withFields([
                __('document.cumulative_from_anchor') => Money::percent(
                    $adjustment->cumulativeIncreasePercentage(),
                ).' desde '.Money::pesos($adjustment->anchor_rent),
            ]);
        }

        // Pactar por debajo del IPC es legitimo ("hasta" en el articulo 20), pero
        // conviene que quede a la vista que es una decision de las partes y no el
        // resultado del calculo.
        if ($adjustment->legal_pct !== null && $adjustment->applied_pct !== null
            && (float) $adjustment->legal_pct > (float) $adjustment->applied_pct) {
            $body = $body->withFields([
                __('document.pactado_below') => Money::percentPlain($adjustment->applied_pct)
                    .' (el IPC fue '.Money::percentPlain($adjustment->legal_pct).')',
            ]);
        }

        if (! $adjustment->isNationalBasis()) {
            $body = $body->withWarning(sprintf(
                __('document.warning_city_basis'),
                $adjustment->geography_name ?? $adjustment->geography_code ?? 'otra ciudad',
            ));
        }

        if ($adjustment->exceedsCap()) {
            $body = $body->withWarning(sprintf(
                __('document.warning_exceeds_cap'),
                Money::pesos($adjustment->excess_over_cap),
                Money::pesos((float) $adjustment->legal_cap_rent),
            ));
        }

        if ($rental !== null && ! $rental->isSubjectToLey820()) {
            $body = $body->withWarning(__('document.warning_commercial'));
        }

        $notes = [
            __('document.note_communication'),
            sprintf(
                __('document.note_ipc_source'),
                $adjustment->geography_name ?? $adjustment->ipcRate?->source_name ?? 'DANE',
                $adjustment->ipc_year ?? 'no disponible',
                $adjustment->ipc_source_url ?? $adjustment->ipcRate?->source_url ?? 'sin enlace registrado',
            ),
        ];

        if ($adjustment->notes) {
            $notes[] = $adjustment->notes;
        }

        // El medio importa tanto como la fecha. El articulo 20 hace oponible el
        // incremento solo si la comunicacion llego por el canal que la ley o el
        // contrato reconocieron, e imprimir el medio es la constancia de eso.
        if ($adjustment->notified_on !== null) {
            $notes[] = __('document.notified_on').' '.$adjustment->notified_on->format('d/m/Y').'.'
                .($adjustment->notification_method !== null
                    ? ' — '.($adjustment->notification_method->label() ?? (string) $adjustment->notification_method)
                    : '');
        } elseif ($adjustment->state()->value === 'recorded') {
            $notes[] = __('document.not_yet_notified');
        }

        return $body
            ->withNotes(...$notes)
            ->withFooter(
                __('document.generated_by').' '.now()->format('d/m/Y \a \l\a\s H:i'),
            );
    }
}

<?php

namespace App\Services;

use App\Dto\IpcSnapshot;
use App\Enums\IpcBasis;
use App\Enums\NotificationMethod;
use App\Models\RentAdjustment;
use App\Models\Rental;
use App\Services\Adjustment\AdjustmentChainCalculator;
use App\Services\Adjustment\AdjustmentChainRecalculator;
use App\Services\Adjustment\Decorators\PactadoCapDecorator;
use App\Services\Adjustment\Strategies\AnnualVariationStrategy;
use App\Services\Adjustment\Strategies\FrozenSnapshotStrategy;
use App\Services\Adjustment\Strategies\IpcBasisStrategyResolver;
use App\Services\Adjustment\Validation\AdjustmentContext;
use App\Services\Adjustment\Validation\ReajusteValidationChain;
use App\Services\Adjustment\Validation\RuleResult;
use App\Services\Ipc\IpcSeriesRepository;
use RuntimeException;

/**
 * Calcula, registra y comunica reajustes del canon.
 *
 * El calculo del tope no vive en el documento: vive aqui. La carta de
 * reajuste solo imprime lo que este servicio decidio y guardo, de modo que lo
 * que se leyo en la carta y lo que quedo en la base de datos no pueden
 * discrepar.
 *
 * Lo que cambio con el IPC dinamico es la relacion con la cadena. Antes el
 * servicio tomaba el canon vigente del alquiler y lo guardaba como
 * previous_rent del nuevo registro. Como el canon vigente es un valor que este
 * mismo servicio modifica al aplicar un ajuste, esa copia se quedaba obsoleta en
 * cuanto se editaba un ajuste anterior y no habia forma de recalcular.
 *
 * Ahora el canon de entrada no es una entrada: lo deriva
 * AdjustmentChainCalculator desde el ancla del alquiler, y este servicio solo
 * decide que se registra. El repliegue al ancla es lo que permite que corregir
 * un reajuste del primer año reprograme los siguientes.
 */
class RentAdjustmentService
{
    public function __construct(
        private readonly IpcSeriesRepository $series,
        private readonly AdjustmentChainCalculator $calculator,
        private readonly AdjustmentChainRecalculator $recalculator,
        private readonly ReajusteValidationChain $validation,
    ) {}

    /**
     * Registra un reajuste para el canon que ya rigio en el alquiler.
     *
     * @param  float  $newRent  Canon propuesto por el arrendador.
     * @param  int|null  $ipcYear  Ano del IPC a aplicar. Por defecto, el
     *                             inmediatamente anterior al de vigencia, que es
     *                             lo que ordena el articulo 20 de la Ley 820.
     * @param  string|null  $notes  Observaciones.
     * @param  bool  $ipcOutsideStatutoryYear  Confirma que el arrendador
     *                                         decide apartarse del ano que fija
     *                                         la ley.
     * @param  IpcBasis  $basis  De que serie se toma el IPC.
     * @param  string|null  $geographyCode  La ciudad, si $basis es ciudad.
     * @param  float|null  $pactadoPercentage  Porcentaje pactado por debajo del
     *                                         IPC. El "hasta" del articulo 20 lo
     *                                         admite.
     */
    public function record(
        Rental $rental,
        float $newRent,
        \DateTimeInterface $effectiveFrom,
        ?int $ipcYear = null,
        ?string $notes = null,
        bool $ipcOutsideStatutoryYear = false,
        IpcBasis $basis = IpcBasis::National,
        ?string $geographyCode = null,
        ?float $pactadoPercentage = null,
        string $strategy = 'annual_variation',
    ): RentAdjustment {
        if ($newRent <= 0) {
            throw new RuntimeException('El canon reajustado debe ser mayor que cero.');
        }

        // El ancla tiene que existir antes de registrar nada: sin ella no habria
        // forma de recalcular despues.
        if (! $rental->hasAnchor() && $rental->anchorRent() === null) {
            throw new RuntimeException(sprintf(
                'El alquiler "%s" no tiene canon base con el que trabajar. '
                .'Sin ese dato la cadena de reajustes no se puede recalcular.',
                $rental->name,
            ));
        }

        $strategyInstance = $this->resolveStrategy(
            rental: $rental,
            effectiveFrom: $effectiveFrom,
            ipcYear: $ipcYear,
            basis: $basis,
            geographyCode: $geographyCode,
            strategy: $strategy,
            pactadoPercentage: $pactadoPercentage,
        );

        // LAS DOS CIFRAS VIAJAN SEPARADAS Y POR ESO SE PIDEN POR SEPARADO.
        //
        // El tope legal lo fija el IPC. El porcentaje aplicado puede ser menor
        // porque las partes pactaron asi, ya que el articulo 20 dice "hasta".
        // Confundir los dos errores dos veces: el tope quedaria donde la cifra
        // acordada, y la carta presentaria el pacto como si fuera el resultado
        // del calculo legal.
        $legalSnapshot = $strategyInstance->legalSnapshot($effectiveFrom);
        $appliedSnapshot = $strategyInstance->resolve($effectiveFrom);

        $legalPct = $legalSnapshot->percentage;
        $appliedPct = $appliedSnapshot->percentage;

        $previousRent = $this->calculator->currentRentFor($rental);

        $context = new AdjustmentContext(
            rental: $rental,
            effectiveFrom: $effectiveFrom,
            ipc: $legalSnapshot,
            requestedRent: $newRent,
            previousRent: $previousRent,
            pactadoPercentage: $pactadoPercentage,
            acknowledgedDeviation: $ipcOutsideStatutoryYear,
            notes: $notes,
        );

        $verdict = $this->validation->check($context);

        // Un bloqueo es un dato que falta, no una discusion juridica. No se puede
        // emitir una carta que cita un IPC inexistente.
        if ($verdict->isBlocking()) {
            throw new RuntimeException($verdict->message);
        }

        /*
         * APARTARSE DEL ARTÍCULO 20 EXIGE CONSENTIMIENTO, NO SOLO EXPLICACIÓN.
         *
         * Si la combinación se aparta de la ley (otro año del IPC, una ciudad,
         * o el reajuste antes de los doce meses) hay que marcarlo y confirmarlo.
         * No basta con escribir el motivo: la confirmación es el consentimiento
         * de las partes, y sin ella el registro sería un reajuste impuesto por
         * el sistema en vez de uno acordado. Es una validación dura al guardar.
         */
        if ($this->deviationReason($context) !== null && ! $ipcOutsideStatutoryYear) {
            throw new RuntimeException(sprintf(
                'Esta combinación se aparta del artículo 20: %s '
                .'Marque la confirmación «me aparto del artículo 20» para dejar constancia del '
                .'consentimiento, y escriba el motivo en las observaciones.',
                $this->deviationReason($context),
            ));
        }

        // Apartarse del articulo 20 requiere que quede escrito por que. No es una
        // prohibicion: es exigir que el motivo exista antes de que alguien lo mire
        // tres meses despues.
        if ($this->requiresWrittenJustification($context) && trim((string) $notes) === '') {
            throw new RuntimeException(sprintf(
                '%s%s Para apartarse del artículo 20 hay que dejar escrito el motivo en las '
                .'observaciones: es lo que sostiene el reajuste si el arrendatario lo impugna.',
                $this->deviationReason($context) ?? '',
                $this->deviationReason($context) === null ? '' : ' ',
            ));
        }

        $legalCap = round($previousRent * (1 + ($legalPct / 100)));

        $adjustment = $rental->rentAdjustments()->create([
            'user_id' => $rental->user_id,
            'sequence' => $this->calculator->nextSequence($rental),
            'previous_rent' => $previousRent,
            'anchor_rent' => $rental->anchorRent(),
            'new_rent' => $newRent,
            'ipc_year' => $legalSnapshot->year,
            'ipc_percentage' => $appliedPct,
            'legal_pct' => $legalPct,
            'applied_pct' => $appliedPct,
            'ipc_basis' => $basis,
            'geography_code' => $basis->isStatutory() ? null : $geographyCode,
            'geography_name' => $legalSnapshot->geographyName,
            'ipc_month' => $legalSnapshot->month,
            'ipc_synced_at' => $legalSnapshot->syncedAt,
            'ipc_source_url' => $legalSnapshot->sourceUrl,
            'legal_cap_rent' => $legalCap,
            'excess_over_cap' => max(0.0, round($newRent - $legalCap, 0)),
            // Se mantiene el nombre historico de la columna por compatibilidad,
            // pero ya no bloquea: ver WithinCapOrAcknowledged.
            'requires_written_agreement' => $newRent > $legalCap,
            'effective_from' => $effectiveFrom,
            'notes' => $notes,
        ]);

        // Tras insertar, toda la cadena se reprocesa para que previous_rent,
        // legal_cap_rent y sequence queden coherentes con el ancla. Es idempotente:
        // si no habia cambios previos, el recien creado queda igual.
        $this->recalculator->recalculate($rental, syncRentalRent: false);

        return $adjustment->refresh();
    }

    /**
     * Marca el reajuste como comunicado.
     *
     * El articulo 20, parrafo 2, no basta con la fecha: el incremento es
     * inoponible sin que se le diga al arrendatario el monto y la fecha "a traves
     * del servicio postal autorizado o mediante el mecanismo de notificacion
     * personal expresamente establecido en el contrato". Por eso el medio se
     * guarda junto con la fecha.
     */
    public function markNotified(
        RentAdjustment $adjustment,
        \DateTimeInterface $notifiedOn,
        ?NotificationMethod $method = null,
    ): RentAdjustment {
        if ($method === null) {
            throw new RuntimeException(
                'Debe indicar el medio por el que se comunico el reajuste. El articulo 20 '
                .'exige que la comunicacion se haga por el servicio postal autorizado o por el '
                .'mecanismo pactado en el contrato; sin el medio, la fecha sola no acredita nada.'
            );
        }

        $adjustment->update([
            'notified_on' => $notifiedOn,
            'notification_method' => $method,
        ]);

        return $adjustment->refresh();
    }

    /**
     * Aplica el canon reajustado al alquiler.
     *
     * Aqui es donde se cierra la cadena: el canon vigente pasa a ser el nuevo, y
     * con ello el articulo 20 empieza a contar los doce meses "bajo un mismo
     * precio" desde esta fecha.
     *
     * No bloquea por exceder el IPC. La revision del modulo decidio permitirlo y
     * avisar, porque la jurisprudencia que fija si el IPC es tope o parametro no
     * pudo verificarse y el texto admite ambas lecturas. Lo que si exige el
     * articulo es que la comunicacion quede registrada, y eso se comprueba al
     * marcar el reajuste como notificado, no aqui.
     */
    public function applyToRental(RentAdjustment $adjustment): Rental
    {
        $rental = $adjustment->rental;

        if ($adjustment->effective_from === null) {
            throw new RuntimeException(
                'El reajuste no tiene fecha de vigencia. Sin ella no se puede aplicar al canon.'
            );
        }

        $adjustment->update(['applied_on' => now()->toDateString()]);

        $this->recalculator->recalculate($rental);

        return $rental->refresh();
    }

    /**
     * Recalcula la cadena completa del alquiler.
     *
     * Es lo que se llama despues de editar o de borrar un reajuste.
     */
    public function recalculateChain(Rental $rental): array
    {
        return $this->recalculator->recalculate($rental);
    }

    /**
     * Que IPC corresponde para reajustar en una fecha dada.
     *
     * Se expone separado de record() porque el formulario lo necesita para
     * previsualizar el tope mientras el usuario elige, sin crear nada.
     */
    public function previewIpc(
        Rental $rental,
        \DateTimeInterface $effectiveFrom,
        IpcBasis $basis = IpcBasis::National,
        ?string $geographyCode = null,
        ?float $pactadoPercentage = null,
        string $strategy = 'annual_variation',
        ?int $ipcYear = null,
    ): IpcSnapshot {
        // El preview devuelve la cifra APLICADA, que es la que va a aparecer en el
        // canon sugerido. La legal la pide quien la necesite, con legalIpcFor().
        //
        // ipcYear llega explicito desde el formulario. Antes se pasaba siempre
        // null y aqui se caia al año estatutorio, con lo que el preview mostraba
        // una cifra distinta de la que el usuario había seleccionado. En la
        // combinación legal coincidían por casualidad; en las demás —que son
        // justamente las que exigen explicación— no.
        return $this->resolveStrategy(
            rental: $rental,
            effectiveFrom: $effectiveFrom,
            ipcYear: $ipcYear,
            basis: $basis,
            geographyCode: $geographyCode,
            strategy: $strategy,
            pactadoPercentage: $pactadoPercentage,
        )->resolve($effectiveFrom);
    }

    /**
     * El IPC del articulo 20 sin ningun pacto aplicado.
     *
     * Para mostrarlo en la carta junto al aplicado, que pueden diferir.
     */
    public function legalIpcFor(
        Rental $rental,
        \DateTimeInterface $effectiveFrom,
        ?string $geographyCode = null,
    ): IpcSnapshot {
        return app(AnnualVariationStrategy::class)->resolve($effectiveFrom, $geographyCode);
    }

    /**
     * Los avisos de la cadena de reglas para una combinacion concreta.
     *
     * El formulario la llama en vivo para que el usuario vea por que el sistema
     * le sugiere una fecha o le pide una confirmacion, antes de guardar nada.
     *
     * @return array{summary: RuleResult, messages: list<array<string, mixed>>}
     */
    public function validate(
        Rental $rental,
        \DateTimeInterface $effectiveFrom,
        float $requestedRent,
        IpcBasis $basis = IpcBasis::National,
        ?string $geographyCode = null,
        ?float $pactadoPercentage = null,
        bool $acknowledged = false,
        ?string $notes = null,
        ?NotificationMethod $notificationMethod = null,
    ): array {
        try {
            $ipc = $this->resolveStrategy(
                rental: $rental,
                effectiveFrom: $effectiveFrom,
                ipcYear: null,
                basis: $basis,
                geographyCode: $geographyCode,
                strategy: 'annual_variation',
                pactadoPercentage: $pactadoPercentage,
            )->legalSnapshot($effectiveFrom);
        } catch (RuntimeException) {
            // Sin IPC no hay snapshot, y las reglas que lo necesitan no pueden
            // opinar. HasIpcData se encarga de explicar por que.
            $ipc = null;
        }

        $context = new AdjustmentContext(
            rental: $rental,
            effectiveFrom: $effectiveFrom,
            ipc: $ipc,
            requestedRent: $requestedRent,
            previousRent: $this->calculator->currentRentFor($rental),
            pactadoPercentage: $pactadoPercentage,
            acknowledgedDeviation: $acknowledged,
            notes: $notes,
            notificationMethod: $notificationMethod,
        );

        return [
            'summary' => $this->validation->check($context),
            'messages' => $this->validation->messages($context),
        ];
    }

    /**
     * El motivo concreto por el que hay que dejar escrito el por que.
     *
     * Se construye aqui y no concatenando el resumen de la cadena a proposito. El
     * resumen junta todos los avisos, y si uno solo de ellos exige
     * justificacion, el mensaje que llega al usuario seria una lista de cuatro
     * frases en la que la importante se pierde entre las que no. El motivo que
     * bloquea va solo, y los demas avisos se muestran igual en el formulario.
     *
     * @return string|null null cuando no hay nada que justificar.
     */
    private function deviationReason(AdjustmentContext $context): ?string
    {
        $reasons = [];

        if (! $context->hasIpc()) {
            return null;
        }

        $statutoryYear = (int) $context->effectiveFrom->format('Y') - 1;

        if ($context->ipc->isStatutory && $context->ipc->year !== $statutoryYear) {
            $reasons[] = sprintf(
                'El artículo 20 fija el IPC de %d para un reajuste que rige desde el %s, y se '
                .'eligió el de %d.',
                $statutoryYear,
                $context->effectiveFrom->format('d/m/Y'),
                $context->ipc->year,
            );
        }

        if (! $context->ipc->isStatutory) {
            // La ciudad solo aparta del artículo 20 cuando su porcentaje difiere
            // del total nacional del mismo año. Si coinciden, el resultado es el
            // mismo y no hay separación que confirmar: lo dice el panel de
            // comprobaciones como referencia.
            $nationalPct = $this->series->decemberOf(
                \App\Dto\Geography::nationalCode(),
                $context->ipc->year,
            )?->calendarYearVariation();

            $sameAsNational = $nationalPct !== null
                && round((float) $nationalPct, 2) === round((float) $context->ipc->percentage, 2);

            if (! $sameAsNational) {
                $reasons[] = sprintf(
                    'Se está usando el IPC de %s, y el artículo 20 habla de "el índice de precios al '
                    .'consumidor" sin distinguir ciudades.',
                    $context->ipc->geographyName,
                );
            }
        }

        if ($context->timingIsEarly()) {
            $reasons[] = sprintf(
                'El alquiler aún no cumple los doce meses bajo un mismo precio: el próximo reajuste '
                .'podría regir el %s.',
                $context->rental->nextAdjustmentDueDate()->format('d/m/Y'),
            );
        }

        /*
         * Un pactado distinto del IPC legal es apartamiento cuando la base es el
         * total nacional. Si coincide con el IPC (el valor con el que se
         * autocompleta el campo), no hay nada que confirmar. Cuando la base es
         * ciudad, el apartamiento por geografía ya queda recogido arriba y el
         * pactado no se lista aparte.
         */
        if ($context->ipc->isStatutory
            && $context->pactadoPercentage !== null
            && round((float) $context->pactadoPercentage, 2) !== round((float) $context->ipc->percentage, 2)) {
            $reasons[] = sprintf(
                'Las partes pactan aplicar el %s %% en lugar del %s %% del IPC del año.',
                number_format($context->pactadoPercentage, 2, ',', '.'),
                number_format($context->ipc->percentage, 2, ',', '.'),
            );
        }

        if ($reasons === []) {
            // No hay ninguna desviacion que informar. Que el usuario haya marcado
            // la confirmacion de todas formas sin motivo real no invalida el
            // registro: el boton no obliga a tener algo que confesar.
            return null;
        }

        return implode(' ', $reasons);
    }

    /**
     * Si hay que dejar escrito el motivo de apartarse del articulo 20.
     *
     * Exige justificacion en tres casos:
     *
     *   1. Se eligio otro ano del IPC, o el de una ciudad, que es apartarse del
     *      texto del articulo.
     *   2. El reajuste se registra antes de los doce meses bajo un mismo precio.
     *   3. El usuario marco la confirmacion. Esto ultimo es lo mas importante:
     *      marcar "me aparto" sin explicar por que deja el registro igual de
     *      vacio que no marcarlo. La confirmacion obliga, no exime.
     *
     * Lo que YA NO exige justificacion es exceder lo que da el IPC. Esa parte
     * cambio en la revision del modulo: el articulo admite las dos lecturas y
     * bloquear convertia la decision del usuario en un rechazo.
     */
    private function requiresWrittenJustification(AdjustmentContext $context): bool
    {
        return $this->deviationReason($context) !== null
            || $context->acknowledgedDeviation;
    }

    /**
     * Arma la estrategia que resuelve el IPC, ya con la geografia y el pacto.
     *
     * Devuelve el DECORADOR y no el resultado, a proposito. El llamador necesita
     * las dos cifras: la legal, que fija el tope, y la aplicada, que puede ser
     * menor por pacto. Si este metodo devolviera un unico snapshot con las dos
     * mezcladas, el servicio tendria que adivinar de donde salio cada numero, y
     * esa adivinanza es justamente donde el tope terminaba mal calculado.
     */
    private function resolveStrategy(
        Rental $rental,
        \DateTimeInterface $effectiveFrom,
        ?int $ipcYear,
        IpcBasis $basis,
        ?string $geographyCode,
        string $strategy,
        ?float $pactadoPercentage,
    ): PactadoCapDecorator {
        $resolver = app(IpcBasisStrategyResolver::class);

        if (! $resolver->has($strategy)) {
            $strategy = 'annual_variation';
        }

        $resolved = $resolver->resolve($strategy);
        $code = $this->calculator->geographyFor($rental, $basis, $geographyCode);

        // Con ano explicito se consulta ese, aunque no sea el que la ley manda.
        // Es lo que permite registrar un reajuste con un IPC distinto, y la
        // cadena de reglas se encarga de avisar de que se aparta del articulo 20.
        if ($ipcYear !== null) {
            $observation = $this->series->decemberOf(
                $code ?? \App\Dto\Geography::nationalCode(),
                $ipcYear,
            );

            if ($observation === null) {
                // El mensaje no dice COMO sincronizar. Esta excepcion la lee
                // quien administra el alquiler, y "ejecute este comando de
                // consola" no es una instruccion para un usuario de la
                // aplicacion. Que el dato falte ya es el hecho; el como se
                // resuelve en ipc:doctor.
                // $code llega null cuando la base es nacional, y el nombre del
                // total nacional no es un string vacio: es como lo nombra el DANE.
                // Sin esto el mensaje decia "el IPC de  para diciembre".
                throw new RuntimeException(__('adjustment_rules.has_ipc_data.missing_short', [
                    'year' => $ipcYear,
                    'next_year' => $ipcYear + 1,
                    'geography' => $code === null
                        ? __('rent_adjustment.preview.national_geography')
                        : (string) (\App\Models\Geography::query()
                            ->where('code', $code)
                            ->value('name') ?? $code),
                ]));
            }

            $variation = $observation->calendarYearVariation();

            if ($variation === null) {
                throw new RuntimeException(sprintf(
                    'El dato del DANE para diciembre de %d viene sin variacion anual.',
                    $ipcYear,
                ));
            }

            $resolved = FrozenSnapshotStrategy::of(IpcSnapshot::fromObservation(
                observation: $observation->toObservation(),
                percentage: (float) $variation,
                isStatutory: $basis->isStatutory(),
                sourceName: $observation->source_name ?? 'DANE',
                sourceUrl: $observation->source_url,
                syncedAt: $observation->synced_at,
            ));
        }

        return new PactadoCapDecorator($resolved, $pactadoPercentage, $code);
    }
}

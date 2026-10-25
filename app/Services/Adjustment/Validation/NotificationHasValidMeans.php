<?php

namespace App\Services\Adjustment\Validation;

/**
 * Articulo 20, parrafo 2: el aviso tiene que tener medio.
 *
 * "Debera informarle al arrendatario el monto del incremento y la fecha en que
 * se hara efectivo, a traves del servicio postal autorizado o mediante el
 * mecanismo de notificacion personal expresamente establecido en el contrato, so
 * pena de ser inoponible al arrendatario."
 *
 * La ley no pide un "aviso escrito" generico. Nombra el servicio postal autorizado
 * y el mecanismo que el contrato haya pactado, y de eso depende que el incremento
 * sea oponible. Guardar solo la fecha de notificacion deja sin verificar justo el
 * requisito que la ley si condiciona, asi que el medio viaja junto con la fecha.
 *
 * El parrafo 3 agrega algo que conviene tener presente al messaging: el pago del
 * reajuste no sana la falta de comunicacion. El inquilino que pago puede pedir
 * reintegrro aunque nunca se le haya avisado, y por eso la carta deja constancia
 * del medio usado en lugar de dar el incremento por comunicado.
 */
class NotificationHasValidMeans implements ReajusteRule
{
    public function name(): string
    {
        return 'notification_has_valid_means';
    }

    public function article(): string
    {
        return 'Art. 20, Ley 820 de 2003';
    }

    /**
     * Esta regla no se ejecuta al crear el reajuste sino al marcarlo como
     * comunicado, que es cuando la fecha y el medio existen.
     */
    public function check(AdjustmentContext $context): RuleResult
    {
        $method = $context->notificationMethod;

        if ($method === null) {
            return RuleResult::passes($this->article());
        }

        if ($method->isExpresslyRecognisedByArticle20()) {
            return RuleResult::info(
                message: __('adjustment_rules.notification_has_valid_means.valid', [
                    'method' => strtolower((string) $method->label()),
                ]),
                article: $this->article(),
                context: ['method' => $method->value],
            );
        }

        return RuleResult::warning(
            message: __('adjustment_rules.notification_has_valid_means.invalid', [
                'method' => (string) $method->label(),
            ]),
            article: $this->article(),
            context: ['method' => $method->value],
        );
    }
}

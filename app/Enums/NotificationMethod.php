<?php

namespace App\Enums;

/**
 * Medio por el que se comunico el reajuste al arrendatario.
 *
 * El articulo 20, parrafo 2, no exige simplemente "aviso escrito": nombra los
 * medios que hacen la comunicacion oponible.
 *
 *   "debera informarle al arrendatario el monto del incremento y la fecha en
 *    que se hara efectivo, a traves del servicio postal autorizado o mediante
 *    el mecanismo de notificacion personal expresamente establecido en el
 *    contrato, so pena de ser inoponible al arrendatario."
 *
 * Es decir: correo postal autorizado, o el mecanismo que el contrato haya
 * previsto. Un mensaje por chat no cumple por si solo. Guardar solo la fecha de
 * notificacion deja sin verificar el requisito que la ley si condiciona, asi que
 * el medio viaja junto con la fecha.
 *
 * El parrafo 3 agrega que el pago del reajuste no sana la falta de comunicacion:
 * el inquilino que pago puede pedir reintegrro aunque nunca se le aviso. Por eso
 * la carta deja constancia del medio usado.
 */
enum NotificationMethod: string
{
    /** Servicio postal autorizado, que es la via que la ley menciona de forma expresa. */
    case Postal = 'postal';

    /** El mecanismo de notificacion personal que el contrato establecio. */
    case Contractual = 'contractual';

    /** Entrega en mano con constancia. */
    case InPerson = 'in_person';

    /** Correo electronico. La ley no lo nombra, pero un contrato puede pactarlo. */
    case Email = 'email';

    public function label(): string
    {
        return match ($this) {
            self::Postal => __('rent_adjustment.notification_methods.postal'),
            self::Contractual => __('rent_adjustment.notification_methods.contractual'),
            self::InPerson => __('rent_adjustment.notification_methods.in_person'),
            self::Email => __('rent_adjustment.notification_methods.email'),
        };
    }

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        return collect(self::cases())
            ->mapWithKeys(fn (self $method): array => [$method->value => $method->label()])
            ->all();
    }

    /**
     * El articulo 20 solo reconoce el correo postal autorizado y el mecanismo
     * pactado. Los otros dos son validos unicamente si el contrato los establecio,
     * y eso lo decide el arrendador, no el sistema.
     */
    public function isExpresslyRecognisedByArticle20(): bool
    {
        return $this === self::Postal || $this === self::Contractual;
    }
}

<?php

namespace App\Dto;

/**
 * Resumen compacto del portafolio de un arrendador.
 *
 * Es el contexto que viaja en el prompt de la IA en cada consulta: sin el,
 * el modelo solo tiene la pregunta y tenderia a inventar. Con el, puede
 * responder preguntas compuestas ("como va mi negocio") usando solo datos
 * verificados. Se construye por usuario y nunca incluye datos ajenos.
 */
final class PortfolioDigest
{
    /**
     * @param  list<array<string, mixed>>  $rentals
     * @param  array<string, float>  $totals
     * @param  list<array<string, mixed>>  $alerts
     * @param  list<array<string, mixed>>  $pendingInvoices
     */
    public function __construct(
        public readonly int $userId,
        public readonly array $rentals,
        public readonly array $totals,
        public readonly array $alerts,
        public readonly array $pendingInvoices = [],
        public readonly array $catalog = [],
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'rentals' => $this->rentals,
            'totals' => $this->totals,
            'alerts' => $this->alerts,
            'pending_invoices' => $this->pendingInvoices,
            'catalog' => $this->catalog,
        ];
    }
}

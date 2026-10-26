<?php

namespace Tests\Feature;

use App\Enums\InvoiceStatus;
use App\Models\Invoice;
use App\Models\Rental;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * Base de las pruebas de documentos.
 *
 * Los documentos necesitan un arrendador autenticado, porque el alcance de las
 * facturas y los alquileres se define por el usuario actual. Centralizarlo aqui
 * evita que cada prueba olvide el actingAs y termine probando datos ajenos.
 */
abstract class DocumentTestCase extends \Tests\TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->actingAs($this->user);
    }

    /**
     * Factura pagada por completo. Es el caso normal del comprobante.
     */
    protected function paidInvoice(array $attributes = []): Invoice
    {
        $invoice = Invoice::factory()->create(array_merge([
            'user_id' => $this->user->id,
            'status' => InvoiceStatus::PAGADA,
            'paid_at' => now(),
        ], $attributes));

        $invoice->payments()->create([
            'user_id' => $this->user->id,
            'rental_id' => $invoice->rental_id,
            'date' => now()->toDateString(),
            'amount' => $invoice->amount,
            'method' => 'efectivo',
        ]);

        return $invoice->refresh();
    }

    /**
     * Factura emitida y sin pagar. Es el caso donde el comprobante no debe
     * existir.
     */
    protected function issuedInvoice(array $attributes = []): Invoice
    {
        return Invoice::factory()->create(array_merge([
            'user_id' => $this->user->id,
            'status' => InvoiceStatus::EMITIDA,
            'paid_at' => null,
        ], $attributes));
    }

    /**
     * Facturas de un alquiler en un periodo, ya ordenadas como las espera el
     * estado de cuenta.
     *
     * @return list<Invoice>
     */
    protected function invoiceForPeriod(string $period): array
    {
        $rental = Rental::factory()->create([
            'user_id' => $this->user->id,
            'name' => 'Apartamento centro',
        ]);

        return Invoice::factory()
            ->count(2)
            ->create([
                'user_id' => $this->user->id,
                'rental_id' => $rental->id,
                'period' => $period,
            ])
            ->sortBy('number')
            ->values()
            ->all();
    }
}

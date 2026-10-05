<?php

namespace Database\Seeders;

use App\Enums\InvoiceStatus;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Rental;
use App\Services\InvoiceNumberService;
use Illuminate\Database\Seeder;

/**
 * Las facturas se siembran antes que los pagos porque cada pago se apoya en su
 * factura. Sin esa relacion un pago queda huerfano y el panel no lo puede
 * clasificar.
 */
class PaymentSeeder extends Seeder
{
    public function __construct(private readonly InvoiceNumberService $numbers) {}

    public function run(): void
    {
        Rental::query()->each(function (Rental $rental): void {
            $this->seedForRental($rental);
        });
    }

    private function seedForRental(Rental $rental): void
    {
        // Cuatro facturas: dos pagadas completas, una vencida y una emitida.
        // Los numeros salen del servicio y no de un contador propio: si cada
        // uno lleva el suyo, el primero que se cree choca contra lo que ya
        // emitio el plan de cobros, porque los dos arrancan en 0001.
        foreach ([-3, -2] as $monthsAgo) {
            $issuedAt = now()->subMonths($monthsAgo);

            $invoice = Invoice::factory()->create([
                'number' => $this->numbers->next($rental->user_id, (int) $issuedAt->format('Y')),
                'rental_id' => $rental->id,
                'user_id' => $rental->user_id,
                'period' => Invoice::periodFor($issuedAt),
                'issued_at' => $issuedAt->toDateString(),
                'due_at' => $issuedAt->copy()->addDays(5)->toDateString(),
            ]);

            Payment::factory()->create([
                'invoice_id' => $invoice->id,
                'rental_id' => $rental->id,
                'user_id' => $rental->user_id,
                'amount' => $invoice->amount,
                'date' => $invoice->issued_at,
            ]);

            $invoice->refreshStatus();
        }

        Invoice::factory()->overdue()->create([
            'number' => $this->numbers->next($rental->user_id, (int) now()->subMonth()->format('Y')),
            'rental_id' => $rental->id,
            'user_id' => $rental->user_id,
        ]);

        // La factura del mes en curso queda emitida y con un abono parcial.
        // Se fijan las fechas porque el valor por defecto es aleatorio y podria
        // vencer la factura antes de tiempo.
        $current = Invoice::factory()->create([
            'number' => $this->numbers->next($rental->user_id, (int) now()->format('Y')),
            'rental_id' => $rental->id,
            'user_id' => $rental->user_id,
            'period' => today()->format('Y-m'),
            'status' => InvoiceStatus::EMITIDA,
            'issued_at' => today()->startOfMonth()->toDateString(),
            'due_at' => today()->addDays(5)->toDateString(),
        ]);

        Payment::factory()->create([
            'invoice_id' => $current->id,
            'rental_id' => $rental->id,
            'user_id' => $rental->user_id,
            'amount' => round($current->amount / 2, 2),
            'date' => today(),
        ]);

        $current->refreshStatus();
    }
}

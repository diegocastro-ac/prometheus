<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * Consecutivo de facturas por arrendador y ano.
 *
 * Vive fuera de AppSettings porque es el unico dato que cambia con el uso, y
 * AppSettings es inmutable por diseno. El incremento es atomico para que dos
 * facturas simultaneas no saquen el mismo numero.
 *
 * El contador se reinicia cada ano: en enero vuelve a 0001, de modo que el
 * numero no depende de cuantosemitio el arrendador en el ano anterior.
 */
class InvoiceNumberService
{
    private const PREFIX = 'FV';

    public function next(int|string $userId, ?int $year = null): string
    {
        $year ??= (int) now()->format('Y');

        // insertOrIgnore y no updateOrInsert: si la fila ya existe,
        // updateOrInsert le escribiria last_number = 0 y todas las facturas
        // saldrian con el mismo numero.
        DB::table('invoice_sequences')->insertOrIgnore([
            'user_id' => $userId,
            'year' => $year,
            'last_number' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('invoice_sequences')
            ->where('user_id', $userId)
            ->where('year', $year)
            ->increment('last_number');

        // increment devuelve las filas afectadas, no el valor nuevo, asi que
        // hay que releer el contador para conocer el numero asignado.
        $next = (int) DB::table('invoice_sequences')
            ->where('user_id', $userId)
            ->where('year', $year)
            ->value('last_number');

        return self::PREFIX.'-'.$year.'-'.str_pad((string) $next, 4, '0', STR_PAD_LEFT);
    }
}

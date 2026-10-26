<?php

namespace App\Infrastructure\Holidays;

use App\Contracts\HolidaySource;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Proxy (GoF) - RealSubject y Adapter.
 *
 * Es el unico punto que conoce la forma del JSON, el codigo del pais y la URL
 * de Nager.Date. Traduce HTTP a una lista de fechas y no decide que hacer si la
 * API falla: lanza la excepcion para que el Proxy aplique su politica.
 */
final class NagerDateHolidaySource implements HolidaySource
{
    public function __construct(
        private readonly string $baseUrl,
        private readonly string $country,
    ) {}

    public function holidaysFor(int $year): array
    {
        $url = rtrim($this->baseUrl, '/')."/PublicHolidays/{$year}/{$this->country}";
        $response = Http::timeout(5)->get($url);

        if (! $response->successful()) {
            throw new RuntimeException("Nager.Date respondio {$response->status()} para {$year}/{$this->country}.");
        }

        $payload = $response->json();

        if (! is_array($payload)) {
            throw new RuntimeException("Nager.Date devolvio una respuesta inesperada para {$year}/{$this->country}.");
        }

        return collect($payload)
            ->pluck('date')
            ->filter(fn (mixed $date): bool => is_string($date) && str_starts_with($date, (string) $year))
            ->values()
            ->all();
    }
}

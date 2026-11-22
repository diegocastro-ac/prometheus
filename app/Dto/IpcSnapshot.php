<?php

namespace App\Dto;

use App\Enums\IpcBasis;

/**
 * El IPC concreto que una estrategia resolvio para un reajuste.
 *
 * Es el dato que termina sosteniendo la carta. Se guarda congelado en el
 * reajuste, no se vuelve a derivar: un documento ya comunicado no puede cambiar
 * de contenido porque alguien corrigio una fila de referencia.
 *
 * La distincion entre `isStatutory` e `informative` es la que impide que un
 * calculo por indices acabe presentandose como el tope del articulo 20. El
 * articulo 20 habla de "el indice de precios al consumidor" y el DANE lo
 * publica como variacion anual de diciembre. Cualquier otra forma de deducir un
 * porcentaje es ilustrativa, aunque sea matematica y exacta.
 */
final readonly class IpcSnapshot
{
    public function __construct(
        public float $percentage,
        public int $year,
        public int $month,
        public string $geographyCode,
        public string $geographyName,
        public string $basis,
        public bool $isStatutory = true,
        public ?float $index = null,
        public ?string $sourceName = null,
        public ?string $sourceUrl = null,
        public ?\DateTimeInterface $syncedAt = null,
        public ?string $explanation = null,
    ) {}

    public static function national(
        float $percentage,
        int $year,
        ?float $index = null,
        ?string $sourceName = null,
        ?string $sourceUrl = null,
        ?\DateTimeInterface $syncedAt = null,
    ): self {
        return new self(
            percentage: $percentage,
            year: $year,
            month: 12,
            geographyCode: Geography::nationalCode(),
            geographyName: (string) config('dane_ipc_sql.national_name', 'NACIONAL'),
            basis: IpcBasis::National->value,
            isStatutory: true,
            index: $index,
            sourceName: $sourceName ?? 'DANE',
            sourceUrl: $sourceUrl,
            syncedAt: $syncedAt,
        );
    }

    public static function forCity(
        float $percentage,
        int $year,
        string $geographyCode,
        string $geographyName,
        ?float $index = null,
        ?string $sourceName = null,
        ?string $sourceUrl = null,
        ?\DateTimeInterface $syncedAt = null,
    ): self {
        return new self(
            percentage: $percentage,
            year: $year,
            month: 12,
            geographyCode: $geographyCode,
            geographyName: $geographyName,
            basis: IpcBasis::City->value,
            // El articulo 20 no distingue ciudades. Elegir el IPC de una ciudad
            // es apartarse del texto legal, y por eso el snapshot lo declara.
            isStatutory: false,
            index: $index,
            sourceName: $sourceName ?? 'DANE',
            sourceUrl: $sourceUrl,
            syncedAt: $syncedAt,
        );
    }

    /**
     * Construye un snapshot a partir de una observacion ya normalizada.
     */
    public static function fromObservation(
        IpcObservation $observation,
        float $percentage,
        bool $isStatutory,
        ?string $sourceName = null,
        ?string $sourceUrl = null,
        ?\DateTimeInterface $syncedAt = null,
    ): self {
        return new self(
            percentage: $percentage,
            year: $observation->year,
            month: $observation->month,
            geographyCode: $observation->geographyCode,
            geographyName: $observation->geographyName,
            basis: $isStatutory ? IpcBasis::National->value : IpcBasis::City->value,
            isStatutory: $isStatutory,
            index: $observation->index,
            sourceName: $sourceName ?? 'DANE',
            sourceUrl: $sourceUrl,
            syncedAt: $syncedAt,
        );
    }

    public function basisEnum(): IpcBasis
    {
        return IpcBasis::from($this->basis);
    }

    /**
     * El factor que multiplica al canon, como 1,0510.
     */
    public function factor(): float
    {
        return 1 + ($this->percentage / 100);
    }

    /**
     * Texto de fuente para la carta: "DANE, IPC dic. 2025".
     */
    public function sourceLabel(): string
    {
        return sprintf(
            '%s, IPC dic. %d',
            $this->sourceName ?? 'DANE',
            $this->year,
        );
    }

    /**
     * El canon que resulta de aplicar este IPC a un monto dado.
     *
     * El redondeo a pesos enteros ocurre aqui y en ningun otro sitio, para que
     * el replay de la cadena y el preview del formulario produzcan el mismo
     * numero. Un canon con centavos no existe en la practice contractual
     * colombiana y mostrarlo crearia una diferencia falsa entre lo que dice la
     * pantalla y lo que dice la carta.
     */
    public function applyTo(float $amount): float
    {
        return round($amount * $this->factor());
    }

    public function withExplanation(string $explanation): self
    {
        return new self(
            percentage: $this->percentage,
            year: $this->year,
            month: $this->month,
            geographyCode: $this->geographyCode,
            geographyName: $this->geographyName,
            basis: $this->basis,
            isStatutory: $this->isStatutory,
            index: $this->index,
            sourceName: $this->sourceName,
            sourceUrl: $this->sourceUrl,
            syncedAt: $this->syncedAt,
            explanation: $explanation,
        );
    }
}

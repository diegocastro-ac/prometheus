<?php

namespace App\Settings;

use Illuminate\Support\Facades\DB;

/**
 * Configuracion de la aplicacion, compartida por toda la peticion.
 *
 * Es el unico lugar del sistema donde vive la razon social, el NIT, la moneda
 * y los textos legales. Los documentos y el acta de entrega la reciben en lugar
 * de recibir cada dato por parametro, que es lo que hacia que la informacion
 * se duplicara en varias pantallas.
 *
 * La instancia es inmutable: se construye una vez desde la base de datos y no
 * cambia. Cuando se guardan ajustes nuevos se descarta la instancia del
 * contenedor para que la siguiente peticion la vuelva a leer.
 */
final class AppSettings
{
    /**
     * @param  list<string>  $spaceCatalog
     */
    private function __construct(
        private readonly string $businessName,
        private readonly string $taxId,
        private readonly string $address,
        private readonly string $currency,
        private readonly ?string $logoPath,
        private readonly ?string $email,
        private readonly ?string $phone,
        private readonly int $invoiceDueDays,
        private readonly ?string $legalFooter,
        private readonly array $spaceCatalog,
    ) {}

    /**
     * Lee la fila unica de configuracion. Si todavia no existe, devuelve los
     * valores por defecto para que el sistema arranque sin configuracion.
     */
    public static function fromDatabase(): self
    {
        $row = DB::table('app_settings')->where('id', 1)->first();

        if ($row === null) {
            return self::defaults();
        }

        return new self(
            businessName: $row->business_name,
            taxId: $row->tax_id,
            address: $row->address ?? '',
            currency: $row->currency ?? 'COP',
            logoPath: $row->logo_path,
            email: $row->email,
            phone: $row->phone,
            invoiceDueDays: (int) ($row->invoice_due_days ?? 5),
            legalFooter: $row->legal_footer,
            spaceCatalog: self::decodeSpaceCatalog($row->space_catalog),
        );
    }

    /**
     * Valores minimos para poder operar antes de que el arrendador configure nada.
     */
    public static function defaults(): self
    {
        return new self(
            businessName: config('app.name'),
            taxId: '',
            address: '',
            currency: 'COP',
            logoPath: null,
            email: null,
            phone: null,
            invoiceDueDays: 5,
            legalFooter: null,
            spaceCatalog: [],
        );
    }

    public function businessName(): string
    {
        return $this->businessName;
    }

    public function taxId(): string
    {
        return $this->taxId;
    }

    public function address(): string
    {
        return $this->address;
    }

    public function currency(): string
    {
        return $this->currency;
    }

    public function logoPath(): ?string
    {
        return $this->logoPath;
    }

    public function email(): ?string
    {
        return $this->email;
    }

    public function phone(): ?string
    {
        return $this->phone;
    }

    public function invoiceDueDays(): int
    {
        return $this->invoiceDueDays;
    }

    public function legalFooter(): ?string
    {
        return $this->legalFooter;
    }

    /**
     * @return list<string>
     */
    public function spaceCatalog(): array
    {
        return $this->spaceCatalog;
    }

    /**
     * Formatea un monto con la moneda configurada. Todos los documentos usan
     * este metodo, para que el separador de miles no cambie entre documentos.
     */
    public function formatMoney(float $amount): string
    {
        return $this->currency.' '.number_format($amount, 0, ',', '.');
    }

    /**
     * @return list<string>
     */
    private static function decodeSpaceCatalog(mixed $raw): array
    {
        if (is_string($raw)) {
            $raw = json_decode($raw, true);
        }

        if (! is_array($raw)) {
            return [];
        }

        return array_values(array_filter(array_map(
            static fn (mixed $space): string => is_string($space) ? trim($space) : '',
            $raw,
        )));
    }
}
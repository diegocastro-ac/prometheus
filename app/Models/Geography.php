<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Una geografia sobre la que el DANE publica IPC.
 *
 * El catalogo se arma con dos fuentes. La principal es qryCiudad, que devuelve
 * las 23 ciudades con IPC. La secundaria es el catalogo DIVIPOLA de datos
 * abiertos, que aporta el nombre del departamento.
 *
 * La division importa porque no son lo mismo. El DIVIPOLA tiene 1.122
 * municipios y solo unos pocos tienen IPC. Importar el catalogo completo
 * sirve para dar contexto, pero el filtro de ciudad solo puede ofrecer las que
 * tienen has_ipc en true, que es exactamente lo que devuelve el DANE.
 */
class Geography extends Model
{
    /** @use HasFactory<\Database\Factories\GeographyFactory> */
    use HasFactory;

    protected $fillable = [
        'code',
        'name',
        'department_code',
        'department',
        'has_ipc',
        'source',
    ];

    protected $casts = [
        'has_ipc' => 'boolean',
    ];

    /**
     * @param  Builder<$this>  $query
     * @return Builder<$this>
     */
    public function scopeWithIpc(Builder $query): Builder
    {
        return $query->where('has_ipc', true);
    }

    public function isNational(): bool
    {
        return $this->code === \App\Dto\Geography::nationalCode();
    }

    public function label(): string
    {
        return $this->department
            ? $this->name.' — '.$this->department
            : $this->name;
    }

    /**
     * Las ciudades donde puede estar un inmueble, con su departamento.
     *
     * A diferencia de ipcCities(), aqui entra TODO el catalogo, no solo lo que
     * tiene IPC. La razon es una distincion que el usuario hace mejor que el
     * sistema: el indice de precios al consumidor se publica para 23 ciudades,
     * pero un arrendador puede tener un inmueble en cualquiera de las 1100 del
     * DIVIPOLA. Filtrar el selector a las 23 seria decirle que no puede registrar
     * una propiedad que existe.
     *
     * Lo que si se hace es MARCAR cuales tienen serie disponible. Ocultar el
     * problema no lo previene: solo lo esconde hasta el momento de calcular un
     * reajuste, cuando el usuario ya no puede hacer nada al respecto. Marcado,
     * se decide con la informacion a la vista.
     *
     * El total nacional NO entra: una propiedad esta en una ciudad o no esta en
     * ninguna, y "nacional" no es una ubicacion.
     *
     * @return array<string, string>
     */
    public static function locationOptions(bool $withIpcMarker = true): array
    {
        return static::query()
            ->where('code', '!=', self::nationalCode())
            ->orderBy('name')
            ->get()
            ->mapWithKeys(fn (Geography $geography): array => [
                $geography->code => $withIpcMarker
                    ? $geography->label().($geography->has_ipc
                        ? ' ('.__('geography.with_ipc').')'
                        : ' ('.__('geography.without_ipc').')')
                    : $geography->label(),
            ])
            ->all();
    }

    /**
     * Las opciones del filtro: total nacional primero, luego las ciudades.
     *
     * El total nacional va primero porque es la base que fija el articulo 20: es
     * la respuesta correcta en la mayoria de los casos, y esconderla en medio de
     * una lista alfabetica obliga a buscarla. Las ciudades van despues y en orden
     * alfabetico, porque ahi si se busca por nombre.
     *
     * @return array<string, string>
     */
    public static function ipcOptions(): array
    {
        $national = static::query()->withIpc()->where('code', self::nationalCode())->first();

        $options = [];

        if ($national !== null) {
            $options[$national->code] = $national->name.' (Nacional)';
        }

        return $options + self::ipcCities();
    }

    /**
     * Las ciudades con IPC, sin el total nacional, en orden alfabetico.
     *
     * Es lo que ofrece el selector cuando el usuario elige usar el IPC de una
     * ciudad en vez del nacional. Ahi el nacional no tiene sentido: seria un valor
     * contradictorio dentro de una lista que ya dice "ciudad".
     *
     * @return array<string, string>
     */
    public static function ipcCities(): array
    {
        return static::query()
            ->withIpc()
            ->where('code', '!=', self::nationalCode())
            ->orderBy('name')
            ->pluck('name', 'code')
            ->all();
    }

    /**
     * El listado del filtro de geografias.
     *
     * NO lleva una entrada "Todos". Filament ya dibuja la suya: un SelectFilter
     * construye su Select con `selectablePlaceholder()` en true, lo que produce
     * una primera opción de valor vacío que es exactamente "sin filtro".
     *
     * Agregar aquí un `'' => 'Todos'` dejaba DOS opciones de valor vacío con la
     * misma etiqueta en la misma lista, y las dos hacían lo mismo. El nombre de
     * esa opción —"Todos"— se le da con `->placeholder()` en el filtro, que es
     * donde Filament lee la etiqueta de la que ya dibuja.
     *
     * @return array<string, string>
     */
    public static function filterOptions(): array
    {
        return self::ipcOptions();
    }

    private static function nationalCode(): string
    {
        return \App\Dto\Geography::nationalCode();
    }
}

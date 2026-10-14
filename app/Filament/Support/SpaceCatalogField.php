<?php

namespace App\Filament\Support;

use App\Contracts\CurrentUserContextInterface;
use App\Models\Space;
use Filament\Forms;

/**
 * El selector de espacios del inventario del acta.
 *
 * POR QUE ES UN SELECT Y NO UN CAMPO DE TEXTO
 * -------------------------------------------
 * Antes era un `TextInput` con `datalist`. La sugerencia era correcta —el
 * inventario se llena con lo que ya se usó— pero el campo aceptaba cualquier
 * texto, y el `DeliveryActBuilder` lo rechazaba después con un error al guardar.
 * Es decir: el usuario escribía «bodega», el sistema le mostraba «Bodega» en la
 * lista, elegía «Bodega», y al guardar le decía que «Bodega» no estaba en el
 * catálogo. Un mensaje de error por escribir con tilde.
 *
 * Con el `datalist` el error aparecía DESPUÉS de escribir veinte líneas de
 * inventario. Con el selector, el espacio no se puede elegir mal: sale de la
 * lista, o se crea.
 *
 * POR QUE EL CATÁLOGO ESTÁ AQUÍ Y NO EN AJUSTES
 * ---------------------------------------------
 * Ver el comentario de AjustesPage. El resumen: había dos catálogos —la tabla
 * `spaces` y `app_settings.space_catalog`— y ya divergían. Este usa el de la
 * tabla, que es el que tenía el `user_id` y el índice único.
 *
 * POR QUÉ PUEDE CREARSE DESDE AQUÍ
 * --------------------------------
 * El catálogo era una tarea previa: antes de poder levantar una sola acta había
 * que ir a Ajustes y escribir el vocabulario completo. Eso es pedirle al usuario
 * que anticipe lo que necesitará.
 *
 * Crear desde el inventario resuelve el caso real —«este inmueble tiene una
 * zona de lavado que no estaba en mi lista»— sin obligar a salir de la pantalla
 * en la que está trabajando ni perder lo que ya llenó.
 *
 * ADEMÁS SE PUEDE RENOMBRAR Y BORRAR
 * ---------------------------------
 * Un catálogo se vuelve obsoleto. Sin poder corregirlo, el único camino era
 * seguir escribiendo variantes: «Sala», «Sala principal», «Sala 1». Eso fragmenta
 * el historial y una comparación entre dos actas deja de ser posible. Con
 * `manageOptionForms` el usuario corregye el nombre y el inventario siguiente
 * ya usa el correcto.
 *
 * LA COMPARACIÓN ES INSENSIBLE A MAYÚSCULAS
 * -----------------------------------------
 * El Builder compara en minúsculas. Es lo que evita que «sala» y «Sala» se
 * cuenten como dos espacios distintos, que es lo que pasaba con la validación
 * anterior, que comparaba en minúsculas mientras el formulario no lo pedía.
 */
final class SpaceCatalogField
{
    /**
     * @param  Forms\Components\Select  $field  el campo dentro del Repeater.
     */
    public static function make(Forms\Components\Select $field): Forms\Components\Select
    {
        return $field
            ->label(__('act.form.space'))
            ->options(fn (): array => self::options())
            // Buscable porque el catálogo crece con el uso, y a partir de quince
            // o veinte espacios nadie los recorre en una lista desplegable.
            ->searchable()
            // El buscador propio de Filament, no el del navegador: sin esto la
            // lista se comporta como un <select> nativo y no busca.
            ->native(false)
            ->required()
            ->placeholder(__('act.form.space_placeholder'))
            ->createOptionForm([
                Forms\Components\TextInput::make('name')
                    ->label(__('act.form.space_new'))
                    ->helperText(__('act.form.space_new_help'))
                    ->required()
                    ->maxLength(60),
            ])
            ->createOptionUsing(function (array $data): string {
                $name = trim((string) ($data['name'] ?? ''));

                // firstOrCreate en lugar de create: el botón puede pulsarse dos
                // veces sobre el mismo nombre, y un índice único que revienta
                // con una excepción es una forma de enseñarle al usuario a no
                // usar la función.
                $space = Space::query()->firstOrCreate([
                    'user_id' => app(CurrentUserContextInterface::class)->id(),
                    'name' => $name,
                ]);

                return $space->name;
            })
            /*
            | RENOMBRAR
            |
            | Filament v3 ofrece createOptionForm y editOptionForm, pero no una
            | acción de borrar. Se usa la de editar, que sí existe.
            |
            | No hay borrado a propósito, y no por falta de API: quitar un espacio
            | del catálogo no borra las actas que lo usaron, y deja al usuario
            | con la duda de si puede o no puede usarlo. Renombrar resuelve el
            | problema real —el catálogo se fragmenta con variantes— sin crear ese
            | hueco. Un espacio que de verdad sobra, se deja: ocupa una línea.
            |
            | El callback updateOptionUsing devuelve el nombre NUEVO como
            | resultado, que es lo que Filament pone en el campo.
            */
            ->editOptionForm([
                Forms\Components\TextInput::make('name')
                    ->label(__('act.form.space_new'))
                    ->required()
                    ->maxLength(60),
            ])
            ->fillEditOptionActionFormUsing(fn (string $state): array => [
                'name' => $state,
            ])
            ->updateOptionUsing(function (array $data, string $state): string {
                $name = trim((string) ($data['name'] ?? ''));

                $space = Space::query()
                    ->where('user_id', app(CurrentUserContextInterface::class)->id())
                    ->where('name', $state)
                    ->first();

                if ($space === null) {
                    return $state;
                }

                /*
                | Solo se renombra la fila del catálogo. Los items del acta NO se
                | tocan.
                |
                | Un acta ya suscrita es un documento: cambiarle el nombre al
                | espacio invalidaría lo que se entregó. El histórico conserva el
                | nombre con el que se usó, y el catálogo empieza a usar el nuevo.
                | Un nombre que colisiona con otro existente se resuelve con
                | firstOrCreate, no con una violación de la clave única.
                */
                $target = Space::query()->firstOrCreate([
                    'user_id' => app(CurrentUserContextInterface::class)->id(),
                    'name' => $name,
                ]);

                if ($target->id !== $space->id) {
                    // El destino ya existía: se quita el duplicado y el
                    // catalogo queda con una sola entrada, que es lo que quería
                    // el usuario al renombrar a un nombre que ya estaba.
                    $space->delete();
                } else {
                    $space->update(['name' => $name]);
                }

                return $target->name;
            })
            ->editOptionModalHeading(__('act.form.space_edit'));
    }

    /**
     * Los espacios del arrendador, en orden alfabético.
     *
     * @return array<string, string>
     */
    public static function options(): array
    {
        return Space::query()
            ->where('user_id', app(CurrentUserContextInterface::class)->id())
            ->orderBy('name')
            ->pluck('name', 'name')
            ->all();
    }
}

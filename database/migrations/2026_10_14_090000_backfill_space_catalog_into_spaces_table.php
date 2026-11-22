<?php

use App\Models\Space;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * El catalogo de espacios deja de estar en Ajustes y vive en la tabla `spaces`.
 *
 * POR QUE HABIA DOS CATALOGOS
 * ---------------------------
 * Eran dos, y ya divergian: la tabla `spaces` tenia doce filas y el
 * `space_catalog` de `app_settings` seis. El que VALIDA era el de Ajustes
 * (DeliveryActBuilder::assertSpaceIsCatalogued leia ahi), y el que se mostraba
 * como sugerencia era el de `spaces`. Es decir: el usuario podía elegir un
 * espacio de la lista que le sugerian y el sistema se lo rechazaba.
 *
 * Eso no es un bug de sincronizacion. Es la consecuencia de tener la fuente de
 * verdad en el lugar equivocado: un ajuste global de la aplicacion, para algo
 * que es vocabulario del ARRENDADOR.
 *
 * POR QUE `spaces` Y NO OTRA COSA
 * ------------------------------
 * La tabla ya existe, ya tiene la columna `user_id`, ya tiene indice unico por
 * (name, user_id) y el Builder ya la escribia con firstOrCreate(). Es decir: la
 * infraestructura para ser la fuente de verdad ya estaba paid. Solo habia que
 * decidir que manda.
 *
 * Ademas resuelve un problema que el catalogo global no podia: `app_settings` es
 * una fila unica compartida, asi que dos arrendadores en la misma instalacion
 * comparten espacios. Con `spaces` cada uno tiene los suyos, que es lo correcto:
 * uno llama "Sala" y el otro "Estar".
 *
 * EL BACKFILL
 * -----------
 * Se copia el catalogo de Ajustes a `spaces` ANTES de quitarlo, para no perder
 * los espacios que alguien ya habia configurado. Sin ese paso, un arrendador que
 * hubiera configurado "Bodega" en Ajustes y "Garaje" en el inventario perderia
 * "Bodega" al cambiar de sitio el catalogo.
 *
 * Los espacios que ya estan en `spaces` no se tocan: pueden diferir en capitalizacion
 * ("Sala" contra "sala"), y unificar eso aqui seria cambiar el nombre con el que
 * el usuario ya registro sus actas. La comparacion del Builder es insensible a
 * mayusculas justamente por eso.
 */
return new class extends Migration
{
    public function up(): void
    {
        $raw = DB::table('app_settings')->where('id', 1)->value('space_catalog');

        if (! is_string($raw) || $raw === '') {
            return;
        }

        $catalog = json_decode($raw, true);

        if (! is_array($catalog)) {
            return;
        }

        // El catalogo de Ajustes era global, asi que no tiene duenador. Se
        // reparte entre los usuarios que ya existen, y no entre uno elegido al
        // azar: un espacio que cada uno tenga es mejor que un espacio que solo
        // tenga el primero.
        $userIds = DB::table('users')->pluck('id');

        if ($userIds->isEmpty()) {
            return;
        }

        foreach ($catalog as $name) {
            if (! is_string($name)) {
                continue;
            }

            $name = trim($name);

            if ($name === '') {
                continue;
            }

            foreach ($userIds as $userId) {
                Space::query()->firstOrCreate(
                    ['user_id' => $userId, 'name' => $name],
                );
            }
        }
    }

    public function down(): void
    {
        // No se hace nada. Las filas de `spaces` que creo el backfill son
        // legitimas por si mismas: son espacios de un arrendador, que es lo que
        // la tabla guarda. Borrarlas al revertir dejaria al usuario sin los
        // espacios que ya tenia.
    }
};
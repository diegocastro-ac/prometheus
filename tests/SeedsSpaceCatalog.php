<?php

namespace Tests;

use App\Models\Space;
use App\Models\User;

/**
 * Siembra el catálogo de espacios del usuario actual.
 *
 * Antes el catálogo vivía en `app_settings.space_catalog` y cada test insertaba
 * ahí una fila con `json_encode([...])`. Con el catálogo en `spaces` eso ya no
 * compila, y pegar `Space::factory()->create()` en cada test repetiría el mismo
 * código diez veces.
 *
 * Este trait es el equivalente actualizado: los espacios se crean filas de
 * `spaces` del usuario que los va a usar, que es como funcionan de verdad.
 */
trait SeedsSpaceCatalog
{
    /**
     * Crea los espacios indicados para el usuario dado.
     *
     * @param  list<string>  $spaces
     */
    protected function seedSpaces(User $user, array $spaces = ['Sala', 'Cocina', 'Baño']): void
    {
        foreach ($spaces as $space) {
            Space::query()->firstOrCreate([
                'user_id' => $user->id,
                'name' => $space,
            ]);
        }
    }

    /**
     * Igual, pero para el primer usuario de la base.
     *
     * Existe porque la mayoría de los tests que necesitan un catálogo ya tienen
     * un usuario nombrado, y no siempre lo tienen a mano en ese punto.
     *
     * @param  list<string>  $spaces
     */
    protected function seedSpacesForFirstUser(array $spaces = ['Sala', 'Cocina', 'Baño']): void
    {
        $this->seedSpaces(User::query()->firstOrFail(), $spaces);
    }
}

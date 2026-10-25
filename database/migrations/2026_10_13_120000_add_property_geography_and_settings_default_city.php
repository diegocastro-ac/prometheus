<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * La ciudad por defecto del inventario, y la ciudad de cada propiedad.
 *
 * DOS CAMBIOS EN UNA MIGRACION, Y POR QUE
 * ---------------------------------------
 * La columna de color se elimina en la misma migracion en que se agrega la de
 * ciudad, aunque no tengan relacion. No es descuido: son el mismo cambio de
 * proposito.
 *
 * Antes, AjustesPage no tenia campos para primary_color, secondary_color ni
 * font_family, pero SettingsService::save() si los escribia con un valor por
 * defecto. Cada vez que alguien guardaba la configuracion, esos tres campos
 * perdian lo que tuvieran. El comportamiento era incorrecto y nadie lo
 * notaba porque el valor por defecto y el valor guardado se parecian.
 *
 * La alternativa habria sido agregar los tres campos al formulario. Se
 * descartó porque no hay ninguna vista que los lea: cssVariables() no lo invoca
 * nadie, y el panel define su propio color en el PanelProvider. Habian sido
 * una funcionalidad empezada y nunca terminada.
 *
 * QUE QUEDA EN NULL ES UNA DECISION
 * ---------------------------------
 * default_city_code y geography_code se pueden quedar en null, y esa ausencia
 * es significativa: significa que no hay ciudad declarada. El formulario de
 * propiedades NO rellena el total nacional cuando falta la ciudad predeterminada,
 * porque el total nacional no es una ciudad y asumirla seria inventar la
 * ubicacion del inmueble.
 *
 * No se hace backfill a partir de la direccion en texto libre: adivinar la
 * ciudad leyendo una direccion es fabricar un dato, y ese dato terminaria
 * alimentando los topes de IPC de todos los reajustes futuros.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('app_settings', function (Blueprint $table): void {
            $table->dropColumn(['primary_color', 'secondary_color', 'font_family']);

            $table->string('default_city_code', 5)->nullable()->after('space_catalog');
        });

        Schema::table('properties', function (Blueprint $table): void {
            // Nullable a proposito: ver la nota de cabecera. Las propiedades
            // existentes quedan sin ciudad y el formulario lo exige al editarlas.
            $table->string('geography_code', 5)->nullable()->after('address');

            $table->index('geography_code');
        });
    }

    public function down(): void
    {
        Schema::table('properties', function (Blueprint $table): void {
            $table->dropIndex(['geography_code']);

            $table->dropColumn('geography_code');
        });

        Schema::table('app_settings', function (Blueprint $table): void {
            $table->dropColumn('default_city_code');

            // Se restauran vacias, no con los valores por defecto. Devolver
            // '#3b82f6' seria resucitar el bug que esta migracion elimina.
            $table->string('primary_color')->nullable()->after('legal_footer');
            $table->string('secondary_color')->nullable()->after('primary_color');
            $table->string('font_family')->nullable()->after('secondary_color');
        });
    }
};

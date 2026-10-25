<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
|--------------------------------------------------------------------------
| Programacion del IPC
|--------------------------------------------------------------------------
|
| El DANE publica el IPC los primeros dias de cada mes. El comando corre todos
| los dias entre el 8 y el 15 porque la fecha exacta varia por mes y por
| decision del DANE: programar un solo dia fallaria la sincronizacion de todo
| un ano la mitad de las veces.
|
| Repetir no cuesta nada. La sincronizacion es un upsert, el comando aborta si la
| serie no cuadra con las cifras oficiales, y el recalculo de cadenas es
| idempotente, de modo que una corrida que no encuentra cambios no escribe nada.
|
| El catalogo de ciudades va aparte y mensual: cambia muy poco, y una consulta
| de menos al CDA es una peticion menos a un servicio que devuelve 502 de forma
| intermitente.
|
*/

Schedule::command('ipc:sync')->cron('0 8 8-15 * *')->withoutOverlapping();
Schedule::command('ipc:sync:geographies')->monthlyOn(20, '08:30')->withoutOverlapping();

// Red de seguridad: si el IPC quedo desalineado porque el DANE corrigio un dato,
// el recalculo lo deja consistente.
Schedule::command('rent-adjustments:recalculate')->weeklyOn(1, '07:00')->withoutOverlapping();
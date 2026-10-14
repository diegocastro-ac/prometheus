<?php

namespace App\Filament\Resources\IpcSeriesResource\Pages;

use App\Dto\Geography;
use App\Filament\Resources\IpcSeriesResource;
use App\Services\Ipc\IpcSeriesRepository;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;

class ListIpcSeries extends ListRecords
{
    protected static string $resource = IpcSeriesResource::class;

    /**
     * Avisa cuando la copia local esta desactualizada.
     *
     * La fecha del DANE no es fija: publica el IPC los primeros dias del mes y a
     * veces se retrasa. Por eso el aviso aparece a partir de cuarenta dias sin
     * sincronizar, no el dia siguiente al ultimo IPC.
     *
     * QUE DICE EL AVISO Y QUE NO
     * -------------------------
     * Antes esta pantalla decia "Ejecute: php artisan ipc:sync". Era la
     * instruccion de consola de quien administra el servidor filtrada a quien
     * esta mirando una tabla, y ademas era incompleta: no decia QUE cities
     * sincronizar ni desde cuando, que es justo lo que uno necesita saber para
     * decidir si el problema es de red, del DANE o del catalogo.
     *
     * Ahora el aviso dice el hecho en el idioma del dominio: la fecha del ultimo
     * dato y cuantos dias lleva sin actualizarse. Quien tenga que ejecutar el
     * comando ya lo sabe, porque administra el sistema; quien no, lo que
     * necesita saber es si la cifra que ve es reciente.
     */
    protected function getHeaderActions(): array
    {
        $repository = app(IpcSeriesRepository::class);
        $lastSynced = $repository->lastSyncedAt(Geography::nationalCode());

        $actions = [
            Action::make('ipc_last_sync')
                ->label(__('ipc.messages.last_sync', [
                    'date' => $lastSynced?->format('d/m/Y') ?? __('ipc.messages.never'),
                ]))
                ->icon('heroicon-o-arrow-down-tray')
                ->color('gray')
                ->disabled()
                ->button(),
        ];

        if ($lastSynced === null) {
            return $actions;
        }

        $days = $lastSynced->diffInDays(now());

        if ($days < 40) {
            return $actions;
        }

        return [
            ...$actions,

            Action::make('ipc_stale')
                ->label(__('ipc.messages.stale', [
                    'date' => $lastSynced->format('d/m/Y'),
                ]))
                ->icon('heroicon-o-exclamation-triangle')
                ->color('warning')
                ->action(function (): void {
                    Notification::make()
                        ->warning()
                        ->title(__('ipc.messages.stale_title'))
                        ->body(__('ipc.messages.stale_body'))
                        ->persistent()
                        ->send();
                }),
        ];
    }
}

<?php

namespace App\Filament\Resources\IpcSeriesResource\Pages;

use App\Dto\Geography;
use App\Events\IpcSeriesSynchronized;
use App\Filament\Resources\IpcSeriesResource;
use App\Services\Ipc\IpcSeriesRepository;
use App\Services\Ipc\IpcSynchronizer;
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
     *
     * LA FECHA DE CABECERA TAMBIÉN SINCRONIZA
     * --------------------------------------
     * La acción de cabecera que dice "Última carga: ..." es la que descarga la
     * serie. No hay dos acciones (una informativa y una que sincroniza) porque
     * describirían la misma fecha en dos lugares: la cabecera es, a la vez, el
     * hecho y la acción. Pide confirmación porque la llamada tarda minutos y
     * dispara la reconstrucción de tasas y el recálculo de cadenas; un clic
     * accidental no debería lanzar ese trabajo.
     */
    protected function getHeaderActions(): array
    {
        $repository = app(IpcSeriesRepository::class);
        $lastSynced = $repository->lastSyncedAt(Geography::nationalCode());

        $actions = [
            Action::make('ipc_sync')
                ->label(__('ipc.messages.sync'))
                ->icon('heroicon-o-arrow-down-tray')
                ->color('primary')
                ->button()
                ->requiresConfirmation()
                ->modalHeading(__('ipc.messages.sync_confirm_title'))
                ->modalDescription(__('ipc.messages.sync_confirm_body'))
                ->modalSubmitActionLabel(__('ipc.messages.sync'))
                ->action(function (IpcSynchronizer $synchronizer): void {
                    // Para no depender de una cola que hoy no corre, se ejecuta
                    // síncrono en la misma petición, pero con un plazo amplio:
                    // la llamada al DANE es lenta e intermitente (502).
                    set_time_limit(300);

                    try {
                        $result = $synchronizer->syncGeography(
                            Geography::nationalCode(),
                        );
                    } catch (\Throwable $exception) {
                        report($exception);

                        Notification::make()
                            ->danger()
                            ->title(__('ipc.messages.sync_fail'))
                            ->body($exception->getMessage())
                            ->persistent()
                            ->send();

                        return;
                    }

                    // La sync manual trae solo el nacional y corre en la misma
                    // petición (no hay cola hoy): primero descarga y guarda, y
                    // si el nacional quedó bien se dispara la misma
                    // reconstrucción que corre el proceso automático. Las
                    // ciudades siguen en el proceso automático: una sola
                    // pantalla de tabla no es el lugar para lanzar esas 23+
                    // descargas.
                    $stored = (int) ($result['stored'] ?? 0);

                    if (! ($result['ok'] ?? false)) {
                        Notification::make()
                            ->danger()
                            ->title(__('ipc.messages.sync_fail'))
                            ->body((string) ($result['message'] ?? ''))
                            ->persistent()
                            ->send();

                        return;
                    }

                    event(new IpcSeriesSynchronized(
                        geographies: [$result['geography']],
                        storedPeriods: $stored,
                        nationalOnly: true,
                    ));

                    Notification::make()
                        ->success()
                        ->title(__('ipc.messages.sync_ok'))
                        ->body(__('ipc.messages.sync_ok_body', ['periods' => $stored]))
                        ->send();

                    $this->redirect(static::getUrl(), navigate: true);
                })
                ->extraAttributes([
                    'title' => __('ipc.messages.last_sync', [
                        'date' => $lastSynced?->format('d/m/Y') ?? __('ipc.messages.never'),
                    ]),
                ]),
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

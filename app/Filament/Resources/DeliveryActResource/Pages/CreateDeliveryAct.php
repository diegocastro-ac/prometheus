<?php

namespace App\Filament\Resources\DeliveryActResource\Pages;

use App\Filament\Resources\DeliveryActResource;
use App\Models\DeliveryAct;
use App\Services\Acts\DeliveryActDraft;
use App\Services\Acts\DeliveryActRecorder;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use RuntimeException;

/**
 * La creacion del acta no la hace Filament directamente: pasa por el Builder.
 *
 * Solo se invocan los metodos with... de las secciones que la pantalla realmente
 * lleno. Si el arrendador dejo vacias las lecturas, el Builder nunca recibe esa
 * seccion y el acta se guarda sin ella.
 */
class CreateDeliveryAct extends CreateRecord
{
    protected static string $resource = DeliveryActResource::class;

    protected static ?string $title = null;

    public function getTitle(): string
    {
        return __('act.buttons.create');
    }

    /**
     * Con ?from=<id> el formulario arranca prellenado con una copia del acta
     * indicado. Sin ese parametro el comportamiento es el de siempre.
     */
    public function mount(): void
    {
        parent::mount();

        $this->fillFromSourceAct();
    }

    private function fillFromSourceAct(): void
    {
        $sourceId = request()->query('from');

        if (! is_numeric($sourceId)) {
            return;
        }

        // El mismo alcance por usuario que usa la lista: una acta ajena no se
        // copia, se finje que no existe.
        $source = DeliveryActResource::getEloquentQuery()
            ->whereKey((int) $sourceId)
            ->first();

        if ($source === null) {
            return;
        }

        $this->form->fill((new DeliveryActDraft($source))->formState());

        Notification::make()
            ->success()
            ->title(__('act.derived'))
            ->send();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordCreation(array $data): DeliveryAct
    {
        try {
            $act = $this->assemble($data);
        } catch (RuntimeException $exception) {
            // El Builder lanza aqui sus reglas de negocio: espacio fuera del
            // catalogo, elemento sin nombre, dato obligatorio faltante. Se
            // avisa en pantalla en vez de mostrar una pantalla de error 500.
            Notification::make()
                ->danger()
                ->title($exception->getMessage())
                ->send();

            $this->halt();
        }

        Notification::make()
            ->success()
            ->title(__('act.created'))
            ->send();

        return $act;
    }

    /**
     * El ensamblado vive en DeliveryActRecorder: la pantalla y el asistente
     * de chat comparten la misma fuente de reglas del Builder.
     *
     * @param  array<string, mixed>  $data
     */
    private function assemble(array $data): DeliveryAct
    {
        return app(DeliveryActRecorder::class)->record(
            $data,
            (int) auth()->id(),
        );
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}

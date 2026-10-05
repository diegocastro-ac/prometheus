{{-- Pantalla de Ajustes. No es un Resource porque no tiene registros: es la
     fila unica de configuracion que respalda al singleton AppSettings. --}}
<x-filament-panels::page>
    <x-filament-panels::form wire:submit="save">
        {{ $this->form }}

        <x-filament-panels::form.actions :actions="$this->getFormActions()" />
    </x-filament-panels::form>
</x-filament-panels::page>
<x-filament-panels::page>
    <x-filament-panels::form wire:submit="save">
        {{ $this->form }}

        <x-filament-panels::form.actions :actions="$this->getFormActions()" />
    </x-filament-panels::form>

    @if (auth()->user()->email_verified_at)
        <x-filament::section>
            <x-slot name="heading">
                Verificación de Email
            </x-slot>

            <div class="flex items-center gap-3">
                <x-filament::icon icon="heroicon-o-check-circle" class="h-6 w-6 text-success-500" />
                <span class="text-sm">Tu correo electrónico está verificado.</span>
            </div>
        </x-filament::section>
    @else
        <x-filament::section>
            <x-slot name="heading">
                Verificación de Email
            </x-slot>

            <div class="space-y-3">
                <div class="flex items-center gap-3">
                    <x-filament::icon icon="heroicon-o-exclamation-circle" class="h-6 w-6 text-warning-500" />
                    <span class="text-sm">Tu correo electrónico no está verificado.</span>
                </div>

                <x-filament::button wire:click="sendVerificationEmail" color="primary" size="sm">
                    Enviar email de verificación
                </x-filament::button>
            </div>
        </x-filament::section>
    @endif

    {{-- Modal de confirmación de cambio de email --}}
    <x-filament::modal id="confirm-email-change" width="md">
        <x-slot name="heading">
            Confirmar cambio de correo electrónico
        </x-slot>

        <x-slot name="description">
            Al cambiar tu correo electrónico, deberás verificarlo nuevamente. ¿Deseas continuar?
        </x-slot>

        <x-slot name="footerActions">
            <x-filament::button color="gray" wire:click="cancelEmailChange">
                Cancelar
            </x-filament::button>

            <x-filament::button wire:click="confirmEmailChange">
                Sí, cambiar email
            </x-filament::button>
        </x-slot>
    </x-filament::modal>
</x-filament-panels::page>

<?php

namespace App\Filament\Pages;

use Filament\Forms;
use Filament\Forms\Form;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Auth;
use Filament\Notifications\Notification;
use Illuminate\Validation\Rules\Password;

class EditProfile extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-user-circle';

    protected static string $view = 'filament.pages.edit-profile';

    protected static bool $shouldRegisterNavigation = false;

    public ?array $data = [];
    public bool $confirmingEmailChange = false;
    public ?string $newEmail = null;

    public function mount(): void
    {
        $this->form->fill([
            'name' => Auth::user()->name,
            'email' => Auth::user()->email,
            'document_type' => Auth::user()->document_type,
            'document' => Auth::user()->document,
            'phone_number' => Auth::user()->phone_number,
        ]);
    }

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('Información Personal')
                    ->schema([
                        Forms\Components\TextInput::make('name')
                            ->label('Nombre completo')
                            ->required()
                            ->maxLength(255),

                        Forms\Components\TextInput::make('email')
                            ->label('Correo electrónico')
                            ->email()
                            ->required()
                            ->unique(ignoreRecord: true)
                            ->maxLength(255),

                        Forms\Components\Select::make('document_type')
                            ->label('Tipo de documento')
                            ->options([
                                'CC' => 'Cédula de Ciudadanía',
                                'TI' => 'Tarjeta de Identidad',
                                'CE' => 'Cédula de Extranjería',
                                'PAS' => 'Pasaporte',
                                'NIT' => 'NIT',
                            ])
                            ->native(false),

                        Forms\Components\TextInput::make('document')
                            ->label('Número de documento')
                            ->unique(ignoreRecord: true)
                            ->maxLength(255),

                        Forms\Components\TextInput::make('phone_number')
                            ->label('Número de teléfono')
                            ->tel()
                            ->unique(ignoreRecord: true)
                            ->maxLength(255),
                    ])
                    ->columns(2),

                Forms\Components\Section::make('Cambiar Contraseña')
                    ->schema([
                        Forms\Components\TextInput::make('current_password')
                            ->label('Contraseña actual')
                            ->password()
                            ->revealable()
                            ->rules(['required_with:new_password']),

                        Forms\Components\TextInput::make('new_password')
                            ->label('Nueva contraseña')
                            ->password()
                            ->revealable()
                            ->rules([
                                'nullable',
                                'confirmed',
                                Password::min(8),
                            ]),

                        Forms\Components\TextInput::make('new_password_confirmation')
                            ->label('Confirmar nueva contraseña')
                            ->password()
                            ->revealable()
                            ->dehydrated(false),
                    ])
                    ->columns(2)
                    ->description('Deja estos campos en blanco si no deseas cambiar tu contraseña.'),
            ])
            ->statePath('data');
    }

    public function save(): void
    {
        $data = $this->form->getState();
        $user = Auth::user();

        // Verificar si está cambiando el email
        $emailChanged = $user->email !== $data['email'];

        // Si cambió el email y está verificado, pedir confirmación
        if ($emailChanged && $user->hasVerifiedEmail() && !$this->confirmingEmailChange) {
            $this->newEmail = $data['email'];
            $this->confirmingEmailChange = true;

            $this->dispatch('open-modal', id: 'confirm-email-change');

            return;
        }

        // Procesar el guardado
        $this->processSave($data, $user, $emailChanged);
    }

    public function cancelEmailChange(): void
    {
        $this->confirmingEmailChange = false;
        $this->newEmail = null;

        $this->dispatch('close-modal', id: 'confirm-email-change');
    }

    public function confirmEmailChange(): void
    {
        $data = $this->form->getState();
        $user = Auth::user();

        $this->dispatch('close-modal', id: 'confirm-email-change');

        $this->processSave($data, $user, true);
    }

    protected function processSave(array $data, $user, bool $emailChanged): void
    {
        // Validar contraseña actual si se está intentando cambiar
        if (!empty($data['current_password'])) {
            if (!Hash::check($data['current_password'], $user->password)) {
                Notification::make()
                    ->danger()
                    ->title('Error')
                    ->body('La contraseña actual es incorrecta.')
                    ->send();
                return;
            }

            if (empty($data['new_password'])) {
                Notification::make()
                    ->danger()
                    ->title('Error')
                    ->body('Debes ingresar una nueva contraseña.')
                    ->send();
                return;
            }

            $user->password = Hash::make($data['new_password']);
        }

        // Actualizar datos del perfil
        $user->name = $data['name'];

        // Si cambió el email, desmarcar como verificado
        if ($emailChanged) {
            $user->email = $data['email'];
            $user->email_verified_at = null;
        }

        $user->document_type = $data['document_type'];
        $user->document = $data['document'];
        $user->phone_number = $data['phone_number'];

        $user->save();

        // Reset de confirmación
        $this->confirmingEmailChange = false;
        $this->newEmail = null;

        $message = 'Tu información ha sido actualizada correctamente.';
        if ($emailChanged) {
            $message .= ' Tu nuevo correo electrónico debe ser verificado.';
        }
        if (!empty($data['current_password'])) {
            $message .= ' Tu contraseña ha sido cambiada exitosamente.';
        }

        Notification::make()
            ->success()
            ->title('Perfil actualizado')
            ->body($message)
            ->duration(5000)
            ->send();

        // Refrescar el formulario
        $this->mount();
    }

    public function sendVerificationEmail(): void
    {
        $user = Auth::user();

        if ($user->hasVerifiedEmail()) {
            Notification::make()
                ->warning()
                ->title('Email ya verificado')
                ->body('Tu correo electrónico ya está verificado.')
                ->send();
            return;
        }

        $user->sendEmailVerificationNotification();

        Notification::make()
            ->success()
            ->title('Email enviado')
            ->body('Se ha enviado un enlace de verificación a tu correo electrónico.')
            ->duration(5000)
            ->send();
    }

    protected function getFormActions(): array
    {
        return [
            Forms\Components\Actions\Action::make('save')
                ->label('Guardar cambios')
                ->submit('save'),
        ];
    }
}

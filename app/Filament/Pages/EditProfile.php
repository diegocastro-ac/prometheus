<?php

namespace App\Filament\Pages;

use App\Services\ProfileService;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rules\Password;

class EditProfile extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-user-circle';

    protected static string $view = 'filament.pages.edit-profile';

    protected static bool $shouldRegisterNavigation = false;

    public ?array $data = [];
    public bool $confirmingEmailChange = false;
    public ?string $newEmail = null;

    private ProfileService $profileService;

    public function __construct()
    {
        $this->profileService = app(ProfileService::class);
    }

    public function getTitle(): string
    {
        return __('profile.title');
    }

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
                Forms\Components\Section::make(__('profile.sections.personal_info'))
                    ->schema([
                        Forms\Components\TextInput::make('name')
                            ->label(__('profile.form.name'))
                            ->required()
                            ->maxLength(255),

                        Forms\Components\TextInput::make('email')
                            ->label(__('profile.form.email'))
                            ->email()
                            ->required()
                            ->unique(ignoreRecord: true)
                            ->maxLength(255),

                        Forms\Components\Select::make('document_type')
                            ->label(__('profile.form.document_type'))
                            ->options([
                                'CC' => __('profile.document_types.CC'),
                                'TI' => __('profile.document_types.TI'),
                                'CE' => __('profile.document_types.CE'),
                                'PAS' => __('profile.document_types.PAS'),
                                'NIT' => __('profile.document_types.NIT'),
                            ])
                            ->native(false),

                        Forms\Components\TextInput::make('document')
                            ->label(__('profile.form.document'))
                            ->unique(ignoreRecord: true)
                            ->maxLength(255),

                        Forms\Components\TextInput::make('phone_number')
                            ->label(__('profile.form.phone_number'))
                            ->tel()
                            ->unique(ignoreRecord: true)
                            ->maxLength(255),
                    ])
                    ->columns(2),

                Forms\Components\Section::make(__('profile.sections.change_password'))
                    ->schema([
                        Forms\Components\TextInput::make('current_password')
                            ->label(__('profile.form.current_password'))
                            ->password()
                            ->revealable()
                            ->rules(['required_with:new_password']),

                        Forms\Components\TextInput::make('new_password')
                            ->label(__('profile.form.new_password'))
                            ->password()
                            ->revealable()
                            ->rules([
                                'nullable',
                                'confirmed',
                                Password::min(8),
                            ]),

                        Forms\Components\TextInput::make('new_password_confirmation')
                            ->label(__('profile.form.new_password_confirmation'))
                            ->password()
                            ->revealable()
                            ->dehydrated(false),
                    ])
                    ->columns(2)
                    ->description(__('profile.descriptions.password_hint')),
            ])
            ->statePath('data');
    }

    public function save(): void
    {
        $data = $this->form->getState();
        $user = Auth::user();

        $emailChanged = $user->email !== $data['email'];

        if ($emailChanged && $user->hasVerifiedEmail() && !$this->confirmingEmailChange) {
            $this->newEmail = $data['email'];
            $this->confirmingEmailChange = true;

            $this->dispatch('open-modal', id: 'confirm-email-change');

            return;
        }

        $this->processSave($data, $user->id, $emailChanged);
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

        $this->processSave($data, $user->id, true);
    }

    protected function processSave(array $data, int $userId, bool $emailChanged): void
    {
        $result = $this->profileService->updateProfile($userId, $data, $emailChanged);

        if (!$result['success']) {
            return;
        }

        $this->confirmingEmailChange = false;
        $this->newEmail = null;

        Notification::make()
            ->success()
            ->title(__('filament-panels::resources/pages/edit-record.notifications.saved.title'))
            ->body($result['message'])
            ->duration(5000)
            ->send();

        $this->mount();
    }

    public function sendVerificationEmail(): void
    {
        $result = $this->profileService->sendVerificationEmail(Auth::id());

        if (($result['already_verified'] ?? false)) {
            Notification::make()
                ->warning()
                ->title(__('filament-panels::pages/auth/email-verification/email-verification-prompt.notifications.notification_already_sent.title'))
                ->body(__('profile.notifications.already_verified'))
                ->send();
            return;
        }

        if (!$result['success']) {
            return;
        }

        Notification::make()
            ->success()
            ->title(__('filament-panels::pages/auth/email-verification/email-verification-prompt.notifications.notification_sent.title'))
            ->body(__('profile.notifications.verification_sent'))
            ->duration(5000)
            ->send();
    }

    protected function getFormActions(): array
    {
        return [
            Forms\Components\Actions\Action::make('save')
                ->label(__('profile.buttons.save'))
                ->submit('save'),
        ];
    }
}

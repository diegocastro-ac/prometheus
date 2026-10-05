<?php

namespace App\Filament\Pages;

use App\Services\SettingsService;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\Page;

class AjustesPage extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-cog-6-tooth';

    protected static bool $isGloballySearchable = false;

    protected static string $view = 'filament.pages.ajustes';

    public ?array $data = [];

    public static function getNavigationLabel(): string
    {
        return __('settings.navigation_label');
    }

    public function mount(): void
    {
        $this->form->fill(app(SettingsService::class)->current());
    }

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make(__('settings.sections.business'))
                    ->description(__('settings.description'))
                    ->schema([
                        Forms\Components\TextInput::make('business_name')
                            ->label(__('settings.form.business_name'))
                            ->placeholder(__('settings.placeholders.business_name'))
                            ->required()
                            ->maxLength(255),

                        Forms\Components\TextInput::make('tax_id')
                            ->label(__('settings.form.tax_id'))
                            ->placeholder(__('settings.placeholders.tax_id'))
                            ->required()
                            ->maxLength(255),

                        Forms\Components\TextInput::make('address')
                            ->label(__('settings.form.address'))
                            ->maxLength(255),

                        Forms\Components\TextInput::make('currency')
                            ->label(__('settings.form.currency'))
                            ->default('COP')
                            ->required()
                            ->maxLength(8),

                        Forms\Components\FileUpload::make('logo_path')
                            ->label(__('settings.form.logo'))
                            ->image()
                            ->directory('logos')
                            ->disk('public')
                            ->visibility('public'),
                    ])
                    ->columns(2),

                Forms\Components\Section::make(__('settings.sections.contact'))
                    ->schema([
                        Forms\Components\TextInput::make('email')
                            ->label(__('settings.form.email'))
                            ->email()
                            ->maxLength(255),

                        Forms\Components\TextInput::make('phone')
                            ->label(__('settings.form.phone'))
                            ->tel()
                            ->maxLength(255),
                    ])
                    ->columns(2),

                Forms\Components\Section::make(__('settings.sections.billing'))
                    ->description(__('settings.descriptions.billing'))
                    ->schema([
                        Forms\Components\TextInput::make('invoice_due_days')
                            ->label(__('settings.form.invoice_due_days'))
                            ->numeric()
                            ->minValue(0)
                            ->maxValue(90)
                            ->default(5)
                            ->required(),

                        Forms\Components\Textarea::make('legal_footer')
                            ->label(__('settings.form.legal_footer'))
                            ->placeholder(__('settings.placeholders.legal_footer'))
                            ->rows(3)
                            ->columnSpanFull(),
                    ])
                    ->columns(2),
            ])
            ->statePath('data');
    }

    public function save(): void
    {
        $data = $this->form->getState();

        // El campo del logo llega como un array de rutas cuando se subio algo
        // en este envio. Si no se subio nada, se conserva la imagen anterior.
        if (is_array($data['logo_path'] ?? null)) {
            $data['logo_path'] = $data['logo_path'][0] ?? null;
        }

        app(SettingsService::class)->save($data);

        Notification::make()
            ->success()
            ->title(__('settings.saved'))
            ->send();

        $this->mount();
    }

    protected function getFormActions(): array
    {
        return [
            Forms\Components\Actions\Action::make('save')
                ->label(__('settings.buttons.save'))
                ->submit('save'),
        ];
    }
}
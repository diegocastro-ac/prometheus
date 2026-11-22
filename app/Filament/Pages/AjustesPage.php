<?php

namespace App\Filament\Pages;

use App\Models\Geography;
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

    public static function shouldRegisterNavigation(): bool
    {
        return false;
    }

    public static function getNavigationLabel(): string
    {
        return __('settings.navigation_label');
    }

    public function getTitle(): string
    {
        return __('settings.title');
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
                        /*
                        | La ciudad predeterminada NO es un valor obligatorio.
                        |
                        | Si no se elige ninguna, el formulario de propiedades deja el
                        | campo de ciudad vacio y lo exige. La alternativa habria sido
                        | asumir el total nacional, y esa es la decision equivocada: el
                        | total nacional no es una ciudad. Rellenarlo automaticamente
                        | inventaria la ubicacion del inmueble y esa suposicion
                        | terminaria alimentando el tope de IPC de todos sus reajustes.
                        */
                        Forms\Components\Select::make('default_city_code')
                            ->label(__('settings.form.default_city_code'))
                            ->helperText(__('settings.form.default_city_code_help'))
                            ->options(fn (): array => Geography::locationOptions())
                            ->searchable()
                            ->native(false)
                            ->placeholder(__('settings.form.default_city_code_placeholder')),

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

                        /*
                        | El catálogo de espacios NO vive aquí.
                        |
                        | Estaba en Ajustes y en la tabla `spaces` al mismo tiempo, y
                        | ya divergían: doce espacios en la tabla contra seis en el
                        | ajuste. Peor aún: el que validaba era este, y el que se le
                        | mostraba al usuario como sugerencia era el otro. Se podía
                        | elegir un espacio de la lista y ver cómo lo rechazaba el
                        | sistema.
                        |
                        | La causa es de fondo: un ajuste GLOBAL de la aplicación para
                        | algo que es vocabulario del ARRENDADOR. Dos arrendadores en
                        | la misma instalación compartían espacios, cuando lo natural
                        | es que uno diga «Sala» y el otro «Estar».
                        |
                        | Ahora el catálogo está donde se usa: en el inventario del
                        | acta, con un selector que permite elegir uno existente o
                        | crear uno nuevo en el momento, sin salir de la pantalla.
                        */
                    ])
                    ->columns(2),
            ])
            ->statePath('data');
    }

    public function save(): void
    {
        $data = $this->form->getState();

        // El campo del logo llega como un array de rutas cuando se subió algo
        // en este envío. Si no se subió nada, se conserva la imagen anterior.
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

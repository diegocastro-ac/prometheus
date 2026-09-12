<?php

namespace App\Filament\Resources;

use App\Contracts\CurrentUserContextInterface;
use App\Filament\Resources\RentalResource\Pages;
use App\Filament\Resources\RentalResource\RelationManagers;
use App\Models\Rental;
use App\Rules\UniqueActiveRentalRule;
use App\ValueObjects\RentalPeriod;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Filament\Forms\Components\Hidden;
use Illuminate\Validation\Rule;
use Filament\Resources\Pages\Page;
use Filament\Pages\SubNavigationPosition;
use Filament\Infolists\Infolist;
use Filament\Infolists\Components;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rules\Numeric;

class RentalResource extends Resource
{
    protected static ?string $model = Rental::class;

    protected static ?string $recordTitleAttribute = 'name';

    protected static ?string $slug = 'administration/rentals';

    protected static ?int $navigationSort = 2;

    protected static ?string $navigationIcon = 'heroicon-o-clipboard-document-list';

    protected static SubNavigationPosition $subNavigationPosition = SubNavigationPosition::Top;

    private static ?CurrentUserContextInterface $userContext = null;

    public static function getUserContext(): CurrentUserContextInterface
    {
        if (self::$userContext === null) {
            self::$userContext = app(CurrentUserContextInterface::class);
        }
        return self::$userContext;
    }

    public static function getNavigationGroup(): ?string
    {
        return __('rental.navigation.group');
    }

    public static function getNavigationLabel(): string
    {
        return __('rental.navigation.labels.plural');
    }

    public static function getModelLabel(): string
    {
        return __('rental.navigation.labels.singular');
    }

    public static function getPluralModelLabel(): string
    {
        return __('rental.navigation.labels.plural');
    }

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make()
                    ->schema([

                        Forms\Components\DatePicker::make('start_date')
                            ->label(__('rental.form.sections.main.start_date'))
                            ->required()
                            ->reactive()
                            ->afterStateUpdated(function (callable $get, callable $set) {
                                $months = (int) $get('total_months');
                                if ($get('start_date') && $months > 0) {
                                    $period = new RentalPeriod(\Carbon\Carbon::parse($get('start_date')), $months);
                                    $set('end_date', $period->endDateFormatted());
                                }
                            }),
                        Forms\Components\DatePicker::make('end_date')
                            ->label(__('rental.form.sections.main.end_date'))
                            ->required()
                            ->disabled(),
                        Forms\Components\TextInput::make('name')
                            ->label(__('rental.form.sections.main.name'))
                            ->required()
                            ->maxLength(50),
                        Forms\Components\Select::make('total_months')
                            ->label(__('rental.form.sections.main.total_months'))
                            ->options(array_combine(range(1, 12), range(1, 12)))
                            ->required()
                            ->reactive()
                            ->afterStateUpdated(function (callable $get, callable $set, $state) {
                                $months = (int) $state;
                                if ($get('start_date') && $months > 0) {
                                    $period = new RentalPeriod(\Carbon\Carbon::parse($get('start_date')), $months);
                                    $set('end_date', $period->endDateFormatted());
                                }
                            }),
                        Forms\Components\Select::make('total_persons')
                            ->label(__('rental.form.sections.main.total_persons'))
                            ->options(array_combine(range(1, 5), range(1, 5))),
                        Forms\Components\TextInput::make('monthly_amount')
                            ->label(__('rental.form.sections.main.monthly_amount'))
                            ->required()
                            ->numeric()
                            ->rules(['numeric', 'min:0']),
                        Forms\Components\Select::make('tenant_id')
                            ->label(__('rental.form.sections.main.tenant'))
                            ->relationship('tenant', 'name')
                            ->required()
                            ->rules(fn(?Rental $record) => [
                                new UniqueActiveRentalRule('tenant_id', $record?->id),
                            ]),
                        Forms\Components\Select::make('property_id')
                            ->label(__('rental.form.sections.main.property'))
                            ->relationship('property', 'name')
                            ->required()
                            ->rules(fn(?Rental $record) => [
                                new UniqueActiveRentalRule('property_id', $record?->id),
                            ]),
                        Forms\Components\Toggle::make('is_active')
                            ->label(__('rental.form.sections.main.is_active'))
                            ->default(true)
                            ->required(),
                        Forms\Components\Textarea::make('description')
                            ->label(__('rental.form.sections.main.description'))
                            ->columnSpan('full')
                            ->autosize()
                            ->maxLength(255),
                        Hidden::make('user_id')
                            ->default(fn() => self::getUserContext()->id()),
                    ])
                    ->columns(2),

                Forms\Components\Section::make(__('rental.form.sections.agreement.title'))
                    ->description(__('rental.form.sections.agreement.description'))
                    ->schema([
                        Forms\Components\FileUpload::make('agreement_path')
                            ->hiddenLabel()
                            ->disk('public')
                            ->directory(
                                fn($get, $record) =>
                                $record
                                    ? "users/{$get('user_id')}/rentals/{$record->id}/agreement"
                                    : "temp/uploads"
                            )
                            ->acceptedFileTypes([
                                'application/pdf',
                                'application/msword',
                                'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                                'image/jpeg',
                                'image/png',
                            ])
                            ->maxSize(5120)
                            ->hint(__('rental.form.sections.agreement.hint')),
                    ])
                    ->collapsed(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->label(__('rental.table.columns.name'))
                    ->searchable()
                    ->sortable()
                    ->limit(15),
                Tables\Columns\TextColumn::make('start_date')
                    ->label(__('rental.table.columns.start_date'))
                    ->date()
                    ->sortable(),
                Tables\Columns\TextColumn::make('end_date')
                    ->label(__('rental.table.columns.end_date'))
                    ->date()
                    ->sortable(),
                Tables\Columns\TextColumn::make('total_months')
                    ->label(__('rental.table.columns.total_months'))
                    ->numeric()
                    ->sortable(),
                Tables\Columns\TextColumn::make('total_persons')
                    ->label(__('rental.table.columns.total_persons'))
                    ->numeric()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('monthly_amount')
                    ->label(__('rental.table.columns.monthly_amount'))
                    ->numeric()
                    ->sortable(),
                Tables\Columns\IconColumn::make('agreement_path')
                    ->label(__('rental.table.columns.agreement'))
                    ->boolean()
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\IconColumn::make('is_active')
                    ->label(__('rental.table.columns.is_active'))
                    ->boolean(),
                Tables\Columns\TextColumn::make('tenant.name')
                    ->label(__('rental.table.columns.tenant'))
                    ->searchable()
                    ->sortable()
                    ->limit(15),
                Tables\Columns\TextColumn::make('property.name')
                    ->label(__('rental.table.columns.property'))
                    ->searchable()
                    ->sortable()
                    ->limit(15),
                Tables\Columns\TextColumn::make('description')
                    ->label(__('rental.table.columns.description'))
                    ->searchable()
                    ->limit(30)
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('created_at')
                    ->label(__('rental.table.columns.created_at'))
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('updated_at')
                    ->label(__('rental.table.columns.updated_at'))
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                //
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListRentals::route('/'),
            'create' => Pages\CreateRental::route('/create'),
            'payments' => Pages\ManageRentalPayments::route('/{record}/payments'),
            'edit' => Pages\EditRental::route('/{record}/edit'),
            'view' => Pages\ViewRental::route('/{record}'),
        ];
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->where('user_id', self::getUserContext()->id());
    }

    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist
            ->schema([
                Components\Section::make()
                    ->schema([
                        Components\Split::make([
                            Components\Grid::make(2)
                                ->schema([
                                    Components\Group::make([

                                        Components\TextEntry::make('start_date')
                                            ->label(__('rental.infolist.sections.main.start_date'))
                                            ->date()
                                            ->badge()
                                            ->color('success'),
                                        Components\TextEntry::make('end_date')
                                            ->label(__('rental.infolist.sections.main.end_date'))
                                            ->date()
                                            ->badge()
                                            ->color('success'),
                                        Components\TextEntry::make('total_months')
                                            ->label(__('rental.infolist.sections.main.total_months')),

                                        Components\TextEntry::make('monthly_amount')
                                            ->label(__('rental.infolist.sections.main.monthly_amount'))
                                            ->numeric(),
                                        Components\TextEntry::make('property.name')
                                            ->label(__('rental.infolist.sections.main.property')),
                                        Components\TextEntry::make('created_at')
                                            ->label(__('rental.infolist.sections.main.created_at'))
                                            ->dateTime(),
                                    ]),
                                    Components\Group::make([
                                        Components\TextEntry::make('name')
                                            ->label(__('rental.infolist.sections.main.name')),

                                        Components\TextEntry::make('total_persons')
                                            ->label(__('rental.infolist.sections.main.total_persons')),
                                        Components\TextEntry::make('tenant.name')
                                            ->label(__('rental.infolist.sections.main.tenant')),
                                        Components\IconEntry::make('is_active')
                                            ->label(__('rental.infolist.sections.main.is_active'))
                                            ->boolean(),
                                        Components\TextEntry::make('description')
                                            ->label(__('rental.infolist.sections.main.description')),
                                        Components\TextEntry::make('updated_at')
                                            ->label(__('rental.infolist.sections.main.updated_at'))
                                            ->dateTime(),
                                    ]),
                                ]),

                        ])->from('lg'),
                    ]),

                Components\Section::make(__('rental.infolist.sections.agreement.title'))
                    ->schema([
                        Components\TextEntry::make('agreement_path')
                            ->hiddenLabel()
                            ->default(__('rental.infolist.sections.agreement.empty'))
                            ->formatStateUsing(function ($record) {
                                $path = $record->agreement_path;

                                if (!$path) {
                                    return __('rental.infolist.sections.agreement.empty');
                                }

                                $url = asset("storage/{$path}");
                                $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));

                                if (in_array($ext, ['jpg', 'png'])) {
                                    $downloadImage = __('rental.infolist.sections.agreement.download_image');

                                    return <<<HTML
                                        <a href="{$url}" target="_blank">
                                            <img src="{$url}" style="max-width:150px; border:1px solid #ccc; border-radius:4px;" />
                                        </a>
                                        <br>
                                        <a href="{$url}" download class="text-sm text-primary-600 underline">
                                            {$downloadImage}
                                            </a>
                                    HTML;
                                }

                                $filename = basename($path);
                                return <<<HTML
                                    <span style="font-size:1.25em; vertical-align:middle;">📄</span>
                                    <a href="{$url}" download class="text-sm text-primary-600 underline">
                                        {$filename}
                                    </a>
                                HTML;
                            })
                            ->html(),
                    ])
                    ->collapsible(),
            ]);
    }

    public static function getRecordSubNavigation(Page $page): array
    {
        return $page->generateNavigationItems([
            Pages\ViewRental::class,
            Pages\EditRental::class,
            Pages\ManageRentalPayments::class,
        ]);
    }
}

<?php

namespace App\Filament\Resources;

use App\Filament\Resources\RentalResource\Pages;
use App\Filament\Resources\RentalResource\RelationManagers;
use App\Models\Rental;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Illuminate\Support\Facades\Auth;
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

    protected static ?string $navigationGroup = 'Administration';

    protected static ?int $navigationSort = 2;

    protected static ?string $navigationIcon = 'heroicon-o-clipboard-document-list';

    protected static SubNavigationPosition $subNavigationPosition = SubNavigationPosition::Top;

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make()
                    ->schema([

                        Forms\Components\DatePicker::make('start_date')
                            ->required()
                            ->reactive()
                            ->afterStateUpdated(function (callable $get, callable $set) {
                                $months = (int) $get('total_months');
                                if ($get('start_date') && $months > 0) {
                                    $end = \Carbon\Carbon::parse($get('start_date'))
                                        ->addMonths($months)
                                        ->format('Y-m-d');
                                    $set('end_date', $end);
                                }
                            }),
                        Forms\Components\DatePicker::make('end_date')
                            ->required()
                            ->disabled(),
                        Forms\Components\TextInput::make('name')
                            ->required()
                            ->maxLength(50),
                        Forms\Components\Select::make('total_months')
                            ->options(array_combine(range(1, 12), range(1, 12)))
                            ->required()
                            ->reactive()
                            ->afterStateUpdated(function (callable $get, callable $set, $state) {
                                $months = (int) $state;
                                if ($get('start_date') && $months > 0) {
                                    $end = \Carbon\Carbon::parse($get('start_date'))
                                        ->addMonths($months)
                                        ->format('Y-m-d');
                                    $set('end_date', $end);
                                }
                            }),
                        Forms\Components\Select::make('total_persons')
                            ->options(array_combine(range(1, 5), range(1, 5))),
                        Forms\Components\TextInput::make('monthly_amount')
                            ->required()
                            ->numeric()
                            ->rules(['numeric', 'min:0']),
                        Forms\Components\Select::make('tenant_id')
                            ->relationship('tenant', 'name')
                            ->required()
                            ->rules(fn(?Rental $record) => [
                                Rule::unique('rentals', 'tenant_id')
                                    ->where('is_active', true)
                                    ->ignore($record?->id),
                            ]),
                        Forms\Components\Select::make('property_id')
                            ->relationship('property', 'name')
                            ->required()
                            ->rules(fn(?Rental $record) => [
                                Rule::unique('rentals', 'property_id')
                                    ->where('is_active', true)
                                    ->ignore($record?->id),
                            ]),
                        Forms\Components\Toggle::make('is_active')
                            ->default(true)
                            ->required(),
                        Forms\Components\Textarea::make('description')
                            ->columnSpan('full')
                            ->autosize()
                            ->maxLength(255),
                        Hidden::make('user_id')
                            ->default(fn() => Auth::id()),
                    ])
                    ->columns(2),

                Forms\Components\Section::make('Agreement')
                    ->description('The agreement document is optional.')
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
                            ->hint('Only .pdf, .docx or images (.jpg, .png), up to 5MB'),
                    ])
                    ->collapsed(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->searchable()
                    ->sortable()
                    ->limit(15),
                Tables\Columns\TextColumn::make('start_date')
                    ->date()
                    ->sortable(),
                Tables\Columns\TextColumn::make('end_date')
                    ->date()
                    ->sortable(),
                Tables\Columns\TextColumn::make('total_months')
                    ->numeric()
                    ->sortable(),
                Tables\Columns\TextColumn::make('total_persons')
                    ->numeric()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('monthly_amount')
                    ->numeric()
                    ->sortable(),
                Tables\Columns\IconColumn::make('agreement_path')
                    ->label('Agreement')
                    ->boolean()
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\IconColumn::make('is_active')
                    ->boolean(),
                Tables\Columns\TextColumn::make('tenant.name')
                    ->searchable()
                    ->sortable()
                    ->limit(15),
                Tables\Columns\TextColumn::make('property.name')
                    ->searchable()
                    ->sortable()
                    ->limit(15),
                Tables\Columns\TextColumn::make('description')
                    ->searchable()
                    ->limit(30)
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('updated_at')
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
            ->where('user_id', Auth::id());
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
                                            ->date()
                                            ->badge()
                                            ->color('success'),
                                        Components\TextEntry::make('end_date')
                                            ->date()
                                            ->badge()
                                            ->color('success'),
                                        Components\TextEntry::make('total_months'),
                                        Components\TextEntry::make('monthly_amount')
                                            ->numeric(),
                                        Components\TextEntry::make('property.name'),
                                        Components\TextEntry::make('created_at')
                                            ->dateTime(),
                                    ]),
                                    Components\Group::make([
                                        Components\TextEntry::make('name'),

                                        Components\TextEntry::make('total_persons'),
                                        Components\TextEntry::make('tenant.name'),
                                        Components\IconEntry::make('is_active')
                                            ->boolean(),
                                        Components\TextEntry::make('description'),
                                        Components\TextEntry::make('updated_at')
                                            ->dateTime(),
                                    ]),
                                ]),

                        ])->from('lg'),
                    ]),

                Components\Section::make('Agreement')
                    ->schema([
                        Components\TextEntry::make('agreement_path')
                            ->hiddenLabel()
                            ->default('No document or image uploaded')
                            ->formatStateUsing(function ($record) {
                                $path = $record->agreement_path;

                                if (!$path) {
                                    return 'No document or image uploaded';
                                }

                                $url = asset("storage/{$path}");
                                $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));

                                if (in_array($ext, ['jpg', 'png'])) {
                                    return <<<HTML
                                        <a href="{$url}" target="_blank">
                                            <img src="{$url}" style="max-width:150px; border:1px solid #ccc; border-radius:4px;" />
                                        </a>
                                        <br>
                                        <a href="{$url}" download class="text-sm text-primary-600 underline">Download image</a>
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

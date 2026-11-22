<?php

namespace App\Filament\Support;

use App\Contracts\CurrentUserContextInterface;
use App\Services\InventoryItemFactory;
use Filament\Forms;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * Selector de elementos del catalogo maestro.
 *
 * Guarda el id, no el nombre: el catalogo puede renombrarse y el acta conserva
 * su propio snapshot. Las opciones y el alta comparten el pool scoped de
 * flyweights, asi que el elemento recien creado queda disponible sin recargar.
 */
final class MasterInventoryItemCatalogField
{
    public static function make(Forms\Components\Select $field): Forms\Components\Select
    {
        return $field
            ->label(__('act.form.item_master'))
            ->options(fn (): array => self::options())
            ->searchable()
            ->native(false)
            ->required()
            ->placeholder(__('act.form.item_master_placeholder'))
            ->createOptionForm([
                Forms\Components\TextInput::make('name')
                    ->label(__('act.form.item_master_new'))
                    ->helperText(__('act.form.item_master_new_help'))
                    ->required()
                    ->maxLength(120),
            ])
            ->createOptionUsing(function (array $data): string {
                $item = app(InventoryItemFactory::class)->firstOrCreate(
                    self::userId(),
                    trim((string) ($data['name'] ?? '')),
                );

                return (string) $item->id();
            })
            ->editOptionForm([
                Forms\Components\TextInput::make('name')
                    ->label(__('act.form.item_master_new'))
                    ->required()
                    ->maxLength(120),
            ])
            ->fillEditOptionActionFormUsing(function (string $state): array {
                $item = app(InventoryItemFactory::class)->find(self::userId(), $state);

                return [
                    'name' => $item?->name() ?? $state,
                ];
            })
            ->updateOptionUsing(function (array $data, string $state): string {
                $factory = app(InventoryItemFactory::class);
                $item = $factory->find(self::userId(), $state);

                if ($item === null) {
                    return $state;
                }

                try {
                    $factory->updateName($item, trim((string) ($data['name'] ?? '')));
                } catch (RuntimeException $exception) {
                    throw ValidationException::withMessages([
                        'name' => $exception->getMessage(),
                    ]);
                }

                return (string) $item->id();
            })
            ->editOptionModalHeading(__('act.form.item_master_edit'));
    }

    /**
     * @return array<int, string>
     */
    public static function options(): array
    {
        return app(InventoryItemFactory::class)->options(self::userId());
    }

    public static function labelFor(mixed $id): ?string
    {
        if ($id === null || $id === '' || $id === []) {
            return null;
        }

        return app(InventoryItemFactory::class)->find(self::userId(), $id)?->name();
    }

    private static function userId(): int
    {
        return (int) app(CurrentUserContextInterface::class)->id();
    }
}

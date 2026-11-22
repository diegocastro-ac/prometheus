<?php

namespace App\Filament\Support;

use App\Contracts\CurrentUserContextInterface;
use App\Models\Space;
use App\Services\Acts\SpaceTreeBuilder;
use Filament\Forms;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * Selector de espacios del inventario.
 *
 * El valor es el id del espacio, no su nombre: el arbol permite dos "Bano" en
 * ramas distintas y el nombre por si solo ya no identifica una fila. La ruta
 * completa se muestra en las opciones para que la eleccion sea inequivoca.
 */
final class SpaceCatalogField
{
    public static function make(Forms\Components\Select $field): Forms\Components\Select
    {
        return $field
            ->label(__('act.form.space'))
            ->options(fn (): array => self::treeOptions())
            ->searchable()
            ->native(false)
            ->required()
            ->placeholder(__('act.form.space_placeholder'))
            ->createOptionModalHeading(__('act.form.space_new'))
            ->createOptionForm([
                Forms\Components\TextInput::make('name')
                    ->label(__('act.form.space_new'))
                    ->helperText(__('act.form.space_new_help'))
                    ->required()
                    ->maxLength(60),

                Forms\Components\Select::make('parent_id')
                    ->label(__('act.form.space_parent'))
                    ->options(fn (): array => self::treeOptions())
                    ->placeholder(__('act.form.space_parent_none'))
                    ->native()
                    ->helperText(__('act.form.space_parent_helper')),
            ])
            ->createOptionUsing(function (array $data): string {
                $parentId = self::parentId($data['parent_id'] ?? null);

                if ($parentId !== null && self::find($parentId) === null) {
                    throw ValidationException::withMessages([
                        'parent_id' => __('act.errors.invalid_space_parent'),
                    ]);
                }

                $space = Space::query()->firstOrCreate([
                    'user_id' => self::userId(),
                    'parent_id' => $parentId,
                    'name' => trim((string) ($data['name'] ?? '')),
                ]);

                return (string) $space->getKey();
            })
            ->editOptionForm([
                Forms\Components\TextInput::make('name')
                    ->label(__('act.form.space_new'))
                    ->required()
                    ->maxLength(60),

                Forms\Components\Select::make('parent_id')
                    ->label(__('act.form.space_parent'))
                    ->options(fn (): array => self::treeOptions())
                    ->placeholder(__('act.form.space_parent_none'))
                    ->native()
                    ->helperText(__('act.form.space_parent_helper')),
            ])
            ->fillEditOptionActionFormUsing(function (string $state): array {
                $space = self::find((int) $state);

                return [
                    'name' => $space?->name ?? $state,
                    'parent_id' => $space?->parent_id,
                ];
            })
            ->updateOptionUsing(function (array $data, string $state): string {
                $space = self::find((int) $state);

                if ($space === null) {
                    return $state;
                }

                $parentId = self::parentId($data['parent_id'] ?? null);
                $name = trim((string) ($data['name'] ?? ''));

                if (! $space->canBeParentedBy($parentId)) {
                    throw ValidationException::withMessages([
                        'parent_id' => __('act.errors.invalid_space_parent'),
                    ]);
                }

                try {
                    $space->update([
                        'name' => $name,
                        'parent_id' => $parentId,
                    ]);
                } catch (RuntimeException $exception) {
                    throw ValidationException::withMessages([
                        'name' => $exception->getMessage(),
                    ]);
                }

                return (string) $space->getKey();
            })
            ->editOptionModalHeading(__('act.form.space_edit'));
    }

    /**
     * @return array<int, string>
     */
    public static function options(): array
    {
        return app(SpaceTreeBuilder::class)->options(self::userId());
    }

    /**
     * @return array<int, string>
     */
    public static function treeOptions(): array
    {
        return app(SpaceTreeBuilder::class)->treeOptions(self::userId());
    }

    private static function find(int $id): ?Space
    {
        return Space::query()
            ->where('user_id', self::userId())
            ->find($id);
    }

    private static function parentPreview(mixed $value): string
    {
        $parentId = self::parentId($value);

        if ($parentId === null) {
            return __('act.form.space_parent_help_root');
        }

        $path = self::options()[$parentId] ?? null;

        return $path === null
            ? __('act.form.space_parent_help_unknown')
            : __('act.form.space_parent_help', ['path' => $path]);
    }

    private static function parentId(mixed $value): ?int
    {
        if ($value === null || $value === '' || $value === []) {
            return null;
        }

        return (int) $value;
    }

    private static function userId(): int|string|null
    {
        return app(CurrentUserContextInterface::class)->id();
    }
}

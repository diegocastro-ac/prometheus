<?php

namespace App\Services\Adjustment\Strategies;

use InvalidArgumentException;

/**
 * Selecciona la estrategia segun lo que pide el usuario.
 *
 * El patron Strategy necesita un punto unico donde se decida cual usar. Sin el,
 * cada pantalla que quiera ofrecer la alternativa por indices tiene que saber
 * construir la implementacion, y acaba apareciendo un switch duplicado en tres
 * sitios.
 *
 * El nombre viene del formulario, asi que se valida contra las opciones
 * declaradas. Un nombre desconocido cae en la estrategia legal en vez de romper:
 * la alternativa por indices es una comodidad, y que no exista no puede impedir
 * reajustar.
 */
class IpcBasisStrategyResolver
{
    /**
     * @param  array<string, class-string<IpcBasisStrategy>>  $strategies
     */
    public function __construct(
        private readonly array $strategies = [
            'annual_variation' => AnnualVariationStrategy::class,
            'index_ratio' => IndexRatioStrategy::class,
        ],
    ) {}

    /**
     * @param  array<string, mixed>  $container
     */
    public function resolve(?string $name = null, array $container = []): IpcBasisStrategy
    {
        $name ??= 'annual_variation';

        if (! array_key_exists($name, $this->strategies)) {
            throw new InvalidArgumentException(sprintf(
                'No hay una estrategia de IPC llamada "%s". Disponibles: %s.',
                $name,
                implode(', ', array_keys($this->strategies)),
            ));
        }

        $class = $this->strategies[$name];

        return $container === [] ? app($class) : app($class, $container);
    }

    /**
     * Todas las estrategias, para mostrarlas en el formulario.
     *
     * @return array<string, IpcBasisStrategy>
     */
    public function all(): array
    {
        return array_map(fn (string $class): IpcBasisStrategy => app($class), $this->strategies);
    }

    /**
     * Las opciones del selector, marcando cuales pueden alimentar el tope legal.
     *
     * @return array<string, string>
     */
    public function options(): array
    {
        return collect($this->all())
            ->mapWithKeys(fn (IpcBasisStrategy $strategy): array => [
                $strategy->name() => $strategy->description().($strategy->isStatutory()
                    ? ''
                    : ' — '.__('ipc.strategies.informative_marker')),
            ])
            ->all();
    }

    public function has(string $name): bool
    {
        return array_key_exists($name, $this->strategies);
    }
}

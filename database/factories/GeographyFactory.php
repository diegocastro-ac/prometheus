<?php

namespace Database\Factories;

use App\Models\Geography;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Geography>
 */
class GeographyFactory extends Factory
{
    protected $model = Geography::class;

    public function definition(): array
    {
        return [
            'code' => $this->faker->unique()->numerify('#####'),
            'name' => $this->faker->city(),
            'department_code' => $this->faker->numerify('##'),
            'department' => $this->faker->state(),
            'has_ipc' => true,
            'source' => 'divipola',
        ];
    }

    /**
     * El total nacional, que el CDA publica como PAI_ID 50.
     */
    public function national(): self
    {
        return $this->state(fn (): array => [
            'code' => \App\Dto\Geography::nationalCode(),
            'name' => 'NACIONAL',
            'department_code' => null,
            'department' => null,
            'has_ipc' => true,
            'source' => 'cda',
        ]);
    }

    /**
     * Una ciudad con IPC.
     */
    public function withIpc(string $code = '20001', string $name = 'VALLEDUPAR', ?string $department = null): self
    {
        return $this->state(fn (): array => [
            'code' => $code,
            'name' => $name,
            'department_code' => substr($code, 0, 2),
            'department' => $department ?? $this->faker->state(),
            'has_ipc' => true,
            'source' => 'cda',
        ]);
    }

    /**
     * Un municipio del DIVIPOLA que NO tiene IPC.
     */
    public function withoutIpc(string $code, string $name): self
    {
        return $this->state(fn (): array => [
            'code' => $code,
            'name' => $name,
            'department_code' => substr($code, 0, 2),
            'department' => $this->faker->state(),
            'has_ipc' => false,
            'source' => 'divipola',
        ]);
    }
}

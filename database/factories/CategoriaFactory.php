<?php

namespace Database\Factories;

use App\Models\Team;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Categoria>
 */
class CategoriaFactory extends Factory
{
    public function definition(): array
    {
        return [
            'team_id' => Team::factory(),
            'nombre' => $this->faker->unique()->words(2, true),
            'tipo' => 'egreso',
            'grupo' => 'gasto_operativo',
            'naturaleza' => 'fijo',
            'activo' => true,
            'orden' => 0,
        ];
    }

    public function ingreso(): static
    {
        return $this->state(fn () => [
            'tipo' => 'ingreso',
            'grupo' => 'ingreso',
            'naturaleza' => null,
        ]);
    }
}

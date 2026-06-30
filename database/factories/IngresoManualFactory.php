<?php

namespace Database\Factories;

use App\Models\Categoria;
use App\Models\Team;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\IngresoManual>
 */
class IngresoManualFactory extends Factory
{
    public function definition(): array
    {
        return [
            'team_id' => Team::factory(),
            'empresa_id' => null,
            'categoria_id' => Categoria::factory()->ingreso(),
            'fecha' => $this->faker->dateTimeBetween('-3 months', 'now')->format('Y-m-d'),
            'monto' => $this->faker->randomFloat(2, 1, 50000),
            'descripcion' => $this->faker->sentence(3),
            'cliente' => $this->faker->optional()->company(),
            'metodo' => $this->faker->randomElement(['efectivo', 'otro']),
            'user_id' => User::factory(),
        ];
    }
}

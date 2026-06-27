<?php

namespace Database\Factories;

use App\Models\Team;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Empresa>
 */
class EmpresaFactory extends Factory
{
    public function definition(): array
    {
        $nombre = $this->faker->unique()->company();

        return [
            'team_id' => Team::factory(),
            'nombre' => $nombre,
            'slug' => Str::slug($nombre).'-'.$this->faker->unique()->numberBetween(1, 99999),
            'color' => $this->faker->hexColor(),
            'activo' => true,
            'orden' => 0,
        ];
    }
}

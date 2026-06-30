<?php

namespace Database\Factories;

use App\Models\Empresa;
use App\Models\Team;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Empleado>
 */
class EmpleadoFactory extends Factory
{
    public function definition(): array
    {
        return [
            'team_id' => Team::factory(),
            'empresa_id' => null,
            'nombre' => $this->faker->name(),
            'puesto' => $this->faker->optional()->jobTitle(),
            'fecha_entrada' => '2026-01-01',
            'fecha_baja' => null,
            'salario_fiscal' => 20000,
            'salario_real' => 24000,
            'clasificacion' => 'administrativa',
            'activo' => true,
            'user_id' => User::factory(),
        ];
    }
}

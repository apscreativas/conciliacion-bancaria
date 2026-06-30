<?php

namespace Database\Factories;

use App\Models\Categoria;
use App\Models\Team;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\EgresoRecurrente>
 */
class EgresoRecurrenteFactory extends Factory
{
    public function definition(): array
    {
        return [
            'team_id' => Team::factory(),
            'empresa_id' => null,
            'categoria_id' => Categoria::factory(),
            'descripcion' => $this->faker->sentence(3),
            'proveedor' => $this->faker->optional()->company(),
            'monto' => $this->faker->randomFloat(2, 100, 20000),
            'frecuencia' => 'mensual',
            'dia_del_mes' => 1,
            'ajuste_dia_habil' => 'habil_anterior',
            'fecha_inicio' => '2026-01-01',
            'vigencia_tipo' => 'indefinida',
            'fecha_fin' => null,
            'num_pagos' => null,
            'pagos_generados' => 0,
            'activo' => true,
            'proxima_generacion' => '2026-01-01',
            'user_id' => User::factory(),
        ];
    }
}

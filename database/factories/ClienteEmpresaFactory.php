<?php

namespace Database\Factories;

use App\Models\Team;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\ClienteEmpresa>
 */
class ClienteEmpresaFactory extends Factory
{
    public function definition(): array
    {
        return [
            'team_id' => Team::factory(),
            'rfc' => $this->faker->regexify('[A-Z]{4}\d{6}[A-Z0-9]{3}'),
            'nombre' => $this->faker->company(),
            'empresa_id' => null,
            'excluido' => false,
            'veces' => 0,
            'ultima_asignacion_at' => null,
            'user_id' => User::factory(),
        ];
    }

    /**
     * Cliente excluido del catálogo (respeta etiquetas individuales).
     */
    public function excluido(): static
    {
        return $this->state(fn () => ['excluido' => true]);
    }
}

<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // User::factory(10)->create();

        $user = User::factory()->create([
            'name' => 'Miguel San Sebastian',
            'email' => 'msansebastianmorales@gmail.com',
            'password' => Hash::make('>>?y2TtA):D@FK9'),
        ]);

        $team = \App\Models\Team::create([
            'user_id' => $user->id,
            'name' => "Miguel's Team",
            'personal_team' => true,
        ]);

        $user->forceFill([
            'current_team_id' => $team->id,
        ])->save();

        // Fase 0 (PRD finanzas): empresas + catálogo de categorías por team (idempotente).
        $this->call(FinanzasCatalogoSeeder::class);
    }
}

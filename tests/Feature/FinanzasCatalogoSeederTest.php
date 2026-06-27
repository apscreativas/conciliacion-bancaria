<?php

use App\Models\Categoria;
use App\Models\Empresa;
use App\Models\User;
use Database\Seeders\FinanzasCatalogoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('seeds 3 empresas and the category catalog per team, idempotently', function () {
    $user = User::factory()->create();
    $teamId = $user->current_team_id;

    $this->seed(FinanzasCatalogoSeeder::class);

    $empresas = Empresa::withoutGlobalScopes()->where('team_id', $teamId)->count();
    $categorias = Categoria::withoutGlobalScopes()->where('team_id', $teamId)->count();

    expect($empresas)->toBe(3);
    expect($categorias)->toBeGreaterThan(0);

    // Re-run: must not duplicate
    $this->seed(FinanzasCatalogoSeeder::class);

    expect(Empresa::withoutGlobalScopes()->where('team_id', $teamId)->count())->toBe(3);
    expect(Categoria::withoutGlobalScopes()->where('team_id', $teamId)->count())->toBe($categorias);
});

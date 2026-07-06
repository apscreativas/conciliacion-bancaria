<?php

namespace App\Policies;

use App\Models\Categoria;
use App\Models\User;
use App\Policies\Concerns\ChecksTeamOwnership;

class CategoriaPolicy
{
    use ChecksTeamOwnership;

    /**
     * Cualquier miembro del team puede listar (la tenencia la aplica TeamOwned).
     */
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Categoria $categoria): bool
    {
        return true;
    }

    public function create(User $user): bool
    {
        return $this->managesCurrentTeam($user);
    }

    public function update(User $user, Categoria $categoria): bool
    {
        return $this->managesCurrentTeam($user) && $categoria->team_id === $user->current_team_id;
    }

    public function delete(User $user, Categoria $categoria): bool
    {
        return $this->managesCurrentTeam($user) && $categoria->team_id === $user->current_team_id;
    }
}

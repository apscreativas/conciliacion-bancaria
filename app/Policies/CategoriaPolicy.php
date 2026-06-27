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
        return $this->ownsCurrentTeam($user);
    }

    public function update(User $user, Categoria $categoria): bool
    {
        return $this->ownsCurrentTeam($user);
    }

    public function delete(User $user, Categoria $categoria): bool
    {
        return $this->ownsCurrentTeam($user);
    }
}

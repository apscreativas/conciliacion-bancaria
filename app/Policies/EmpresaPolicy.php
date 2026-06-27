<?php

namespace App\Policies;

use App\Models\Empresa;
use App\Models\User;
use App\Policies\Concerns\ChecksTeamOwnership;

class EmpresaPolicy
{
    use ChecksTeamOwnership;

    /**
     * Cualquier miembro del team puede listar (la tenencia la aplica TeamOwned).
     */
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Empresa $empresa): bool
    {
        return true;
    }

    public function create(User $user): bool
    {
        return $this->ownsCurrentTeam($user);
    }

    public function update(User $user, Empresa $empresa): bool
    {
        return $this->ownsCurrentTeam($user);
    }

    public function delete(User $user, Empresa $empresa): bool
    {
        return $this->ownsCurrentTeam($user);
    }
}

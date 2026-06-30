<?php

namespace App\Policies;

use App\Models\Empleado;
use App\Models\User;
use App\Policies\Concerns\ChecksTeamOwnership;

class EmpleadoPolicy
{
    use ChecksTeamOwnership;

    public function viewAny(User $user): bool
    {
        return $this->ownsCurrentTeam($user);
    }

    public function view(User $user, Empleado $empleado): bool
    {
        return $this->ownsCurrentTeam($user) && $empleado->team_id === $user->current_team_id;
    }

    public function create(User $user): bool
    {
        return $this->ownsCurrentTeam($user);
    }

    public function update(User $user, Empleado $empleado): bool
    {
        return $this->ownsCurrentTeam($user) && $empleado->team_id === $user->current_team_id;
    }

    public function delete(User $user, Empleado $empleado): bool
    {
        return $this->ownsCurrentTeam($user) && $empleado->team_id === $user->current_team_id;
    }
}

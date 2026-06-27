<?php

namespace App\Policies\Concerns;

use App\Models\User;

trait ChecksTeamOwnership
{
    /**
     * El usuario es dueño de su team actual. Maneja el caso de team nulo
     * (current_team_id null o team borrado) devolviendo false en vez de NPE.
     */
    protected function ownsCurrentTeam(User $user): bool
    {
        $team = $user->currentTeam;

        return $team !== null && $user->ownsTeam($team);
    }
}

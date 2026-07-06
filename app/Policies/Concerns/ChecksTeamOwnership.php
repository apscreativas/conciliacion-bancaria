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

    /**
     * El usuario administra su team actual: dueño O miembro con rol 'admin'
     * (owner-equivalente para los módulos financieros). Usar este check en los
     * gates "solo owner" de la app; `ownsCurrentTeam` queda para lo estructural
     * del team (invitaciones, renombrar). Team nulo → false.
     */
    protected function managesCurrentTeam(User $user): bool
    {
        $team = $user->currentTeam;

        return $team !== null && $user->managesTeam($team);
    }
}

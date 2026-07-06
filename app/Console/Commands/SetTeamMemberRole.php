<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;

/**
 * Cambia el rol de un miembro en el pivot `team_user` ('admin' | 'member').
 *
 * 'admin' es owner-equivalente para los módulos financieros (dashboard
 * ejecutivo, empleados, tolerancia, mutaciones de empresas/categorías) —
 * ver User::managesTeam. El rol 'owner' NO se asigna por aquí: el dueño
 * lo define `teams.user_id`. Uso típico (una vez, en prod):
 *
 *   php artisan team:member-role ceo@empresa.com admin
 */
class SetTeamMemberRole extends Command
{
    protected $signature = 'team:member-role
        {email : Email del usuario miembro}
        {role : Rol a asignar: admin | member}
        {--team= : ID del team (solo necesario si el usuario pertenece a varios)}';

    protected $description = "Asigna rol 'admin' o 'member' a un miembro de un team (team_user.role)";

    public function handle(): int
    {
        $email = $this->argument('email');
        $role = $this->argument('role');

        if (! in_array($role, ['admin', 'member'], true)) {
            $this->error("Rol inválido '{$role}'. Usa 'admin' o 'member' (el owner lo define teams.user_id).");

            return self::FAILURE;
        }

        $user = User::where('email', $email)->first();

        if ($user === null) {
            $this->error("No existe un usuario con email {$email}.");

            return self::FAILURE;
        }

        // Membresías por pivot (no incluye teams que el usuario POSEE).
        $memberships = $user->teams;

        if ($this->option('team') !== null) {
            $memberships = $memberships->where('id', (int) $this->option('team'));
        }

        if ($memberships->isEmpty()) {
            $this->error(
                "{$email} no es miembro (pivot team_user) de ningún team"
                .($this->option('team') ? " con id {$this->option('team')}" : '')
                .'. Si es el dueño del team, ya tiene acceso total.'
            );

            return self::FAILURE;
        }

        if ($memberships->count() > 1) {
            $this->error("{$email} pertenece a varios teams; especifica --team=ID:");
            foreach ($memberships as $team) {
                $this->line("  - {$team->id}: {$team->name} (rol actual: {$team->pivot->role})");
            }

            return self::FAILURE;
        }

        $team = $memberships->first();

        // El dueño no se degrada por pivot: su acceso viene de teams.user_id.
        if ($user->ownsTeam($team)) {
            $this->error("{$email} es el dueño del team '{$team->name}'; su rol no se gestiona por pivot.");

            return self::FAILURE;
        }

        $anterior = $team->pivot->role;
        $user->teams()->updateExistingPivot($team->id, ['role' => $role]);

        $this->info("Listo: {$email} ahora es '{$role}' en el team '{$team->name}' (antes '{$anterior}').");

        return self::SUCCESS;
    }
}

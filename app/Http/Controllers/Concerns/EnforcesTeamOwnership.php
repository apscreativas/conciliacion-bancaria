<?php

namespace App\Http\Controllers\Concerns;

use Illuminate\Database\Eloquent\Model;

/**
 * Defense-in-depth de tenancy (CLAUDE.md §1.3): aun con el global scope de TeamOwned,
 * los controllers re-verifican team_id sobre el modelo bindeado antes de mutarlo.
 */
trait EnforcesTeamOwnership
{
    protected function ensureOwnTeam(Model $model): void
    {
        if ($model->team_id !== auth()->user()->current_team_id) {
            abort(403);
        }
    }
}

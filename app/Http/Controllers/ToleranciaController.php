<?php

namespace App\Http\Controllers;

use App\Models\Tolerancia;
use App\Policies\Concerns\ChecksTeamOwnership;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Redirect;
use Inertia\Inertia;

class ToleranciaController extends Controller
{
    use ChecksTeamOwnership;

    /**
     * Show the form for editing the tolerance.
     */
    public function edit(Request $request)
    {
        $user = $request->user();
        $team = $user->currentTeam;

        // Authorization: dueño del team o miembro con rol 'admin'.
        if (! $this->managesCurrentTeam($user)) {
            abort(403, 'Solo el propietario o un admin del equipo puede configurar la tolerancia.');
        }

        // Get or create tolerance for this team
        // Since we are using TeamOwned model, it will automatically scope to current team
        // but for firstOrCreate we might need to be explicit or rely on the boot method
        $tolerancia = Tolerancia::firstOrCreate(
            ['team_id' => $team->id],
            [
                'monto' => 0.00,
                'user_id' => $user->id, // Assign current owner as creator
            ]
        );

        return Inertia::render('Settings/Tolerance', [
            'tolerancia' => $tolerancia,
        ]);
    }

    /**
     * Update the tolerance in storage.
     */
    public function update(Request $request)
    {
        $user = $request->user();
        $team = $user->currentTeam;

        if (! $this->managesCurrentTeam($user)) {
            abort(403, 'Unauthorized action.');
        }

        $request->validate([
            'monto' => 'required|numeric|min:0',
        ]);

        $tolerancia = Tolerancia::firstOrCreate(['team_id' => $team->id]);

        $tolerancia->update([
            'monto' => $request->monto,
        ]);

        return Redirect::route('settings.tolerance')->with('success', 'Configuración de tolerancia actualizada.');
    }
}

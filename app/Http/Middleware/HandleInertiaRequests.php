<?php

namespace App\Http\Middleware;

use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that is loaded on the first page visit.
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determine the current asset version.
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        // Calculate available years dynamically
        $teamId = $request->user()?->current_team_id;
        $years = [];

        if ($teamId) {
            $invoiceYears = \App\Models\Factura::where('team_id', $teamId)
                ->selectRaw('YEAR(fecha_emision) as year')
                ->distinct()
                ->pluck('year')
                ->toArray();

            $movementYears = \App\Models\Movimiento::where('team_id', $teamId)
                ->selectRaw('YEAR(fecha) as year')
                ->distinct()
                ->pluck('year')
                ->toArray();
            
            $allYears = array_unique(array_merge($invoiceYears, $movementYears));
            sort($allYears);
            
            // Ensure current year is always included
            if (!in_array(now()->year, $allYears)) {
                $allYears[] = now()->year;
                sort($allYears);
            }
            
            $years = array_values($allYears);
        } else {
             $years = [now()->year];
        }

        return [
            ...parent::share($request),
            'auth' => [
                'user' => $request->user() ? array_merge(
                    $request->user()->only(['id', 'name', 'email', 'current_team_id', 'profile_photo_url']),
                    [
                        'current_team' => $request->user()->currentTeam
                            ? $request->user()->currentTeam->only(['id', 'name', 'user_id', 'personal_team'])
                            : null,
                        // Owner o miembro con rol 'admin' del team actual: el sidebar
                        // usa este flag para los módulos owner/admin (ejecutivo,
                        // empleados, tolerancia, settings).
                        'manages_team' => $request->user()->currentTeam !== null
                            && $request->user()->managesTeam($request->user()->currentTeam),
                        'all_teams' => $request->user()->allTeams()->values()->all(),
                    ]
                ) : null,
            ],
            'flash' => [
                'success' => fn () => $request->session()->get('success'),
                'error' => fn () => $request->session()->get('error'),
                'warning' => fn () => $request->session()->get('warning'),
                'toasts' => fn () => $request->session()->get('toasts'),
            ],
            'filters' => [
                'month' => $request->input('month', now()->month),
                'year' => $request->input('year', now()->year),
            ],
            'available_years' => $years,
        ];
    }
}

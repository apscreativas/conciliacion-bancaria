<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ResolvesExpenseOptions;
use App\Models\ClienteEmpresa;
use App\Models\Factura;
use App\Services\Finance\ClienteEmpresaService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Catálogo cliente→empresa (solo Ingresos). Autz = cualquier miembro del team
 * (igual que la conciliación); NO requiere ser owner. El scoping por team se hace
 * de forma explícita con `current_team_id` (defense in depth sobre el global scope).
 */
class ClienteEmpresaController extends Controller
{
    use ResolvesExpenseOptions;

    public function __construct(private ClienteEmpresaService $svc) {}

    public function index(Request $request): Response
    {
        $teamId = auth()->user()->current_team_id;

        // Catálogo aprendido/editable rfc → empresa.
        $catalogo = ClienteEmpresa::where('team_id', $teamId)
            ->with('empresa:id,nombre,color')
            ->orderByDesc('veces')
            ->orderBy('nombre')
            ->get()
            ->map(fn (ClienteEmpresa $c) => [
                'id' => $c->id,
                'rfc' => $c->rfc,
                'nombre' => $c->nombre,
                'empresa' => $c->empresa ? [
                    'id' => $c->empresa->id,
                    'nombre' => $c->empresa->nombre,
                    'color' => $c->empresa->color,
                ] : null,
                'excluido' => $c->excluido,
                'veces' => $c->veces,
                'ultima_asignacion_at' => $c->ultima_asignacion_at?->toDateString(),
            ]);

        return Inertia::render('Clients/Index', [
            'catalogo' => $catalogo,
            'empresas' => $this->empresasActivas($teamId),
            'recurrentes' => $this->reporteRecurrentes($teamId),
        ]);
    }

    /**
     * Override manual del mapeo rfc → empresa. Cualquier miembro del team.
     * PATCH parcial: solo se actualizan las claves presentes en el payload.
     * `empresa_id` scoped al team (nullable para des-asignar el default);
     * `excluido` marca al cliente como "respeta etiquetas individuales"
     * (no aprende, no sugiere, no se aplica — ver ClienteEmpresaService).
     */
    public function update(Request $request, ClienteEmpresa $client): RedirectResponse
    {
        $teamId = auth()->user()->current_team_id;

        // Tenancy: el registro debe pertenecer al team actual (defense in depth).
        if ($client->team_id !== $teamId) {
            abort(404);
        }

        $validated = $request->validate([
            'empresa_id' => [
                'sometimes',
                'nullable',
                Rule::exists('empresas', 'id')->where(fn ($q) => $q->where('team_id', $teamId)),
            ],
            'excluido' => ['sometimes', 'boolean'],
        ]);

        // PATCH parcial pero nunca un no-op silencioso: sin ninguna clave
        // reconocida (payload vacío o typo) → 422, no un falso "éxito".
        if ($validated === []) {
            throw ValidationException::withMessages([
                'empresa_id' => 'Nada que actualizar: envía empresa_id y/o excluido.',
            ]);
        }

        $client->update($validated);

        return back()->with('success', 'Cliente actualizado.');
    }

    /**
     * Aplica el catálogo a las conciliaciones (ingresos) del team que aún no tienen
     * empresa asignada. Arrastra el histórico con las sugerencias unívocas del catálogo.
     */
    public function aplicarSugerencias(Request $request): RedirectResponse
    {
        $teamId = auth()->user()->current_team_id;

        $n = $this->svc->aplicarASinEmpresa($teamId);

        return back()->with('success', "{$n} conciliaciones asignadas");
    }

    /**
     * Reporte de facturación recurrente / "dejó de facturar" a partir de las facturas
     * emitidas (ingresos). Agrupa por rfc: nombre (último visto), meses distintos con
     * factura, última fecha y conteo. Marca `recurrente` cuando el cliente facturó en
     * ≥3 de los últimos 4 meses (incluyendo el mes en curso) y `sin_factura_mes_actual`
     * cuando es recurrente pero no facturó en el mes actual. Cruza con el catálogo para
     * exponer la empresa mapeada. Devuelve solo los recurrentes, con los "sin factura
     * este mes" primero.
     *
     * @return array<int, array<string, mixed>>
     */
    private function reporteRecurrentes(int $teamId): array
    {
        $now = Carbon::now();
        $mesActual = $now->format('Y-m');

        // Ventana de 4 meses: mes actual + 3 previos.
        $ventana = collect(range(0, 3))
            ->map(fn (int $i) => $now->copy()->subMonthsNoOverflow($i)->format('Y-m'))
            ->all();

        // Mapa rfc → empresa desde el catálogo (para mostrar la empresa por defecto).
        // Solo aplicables: los excluidos se muestran "Sin asignar" (su empresa no se aplica).
        $mapaEmpresa = ClienteEmpresa::where('team_id', $teamId)
            ->aplicable()
            ->with('empresa:id,nombre,color')
            ->get()
            ->keyBy('rfc');

        $facturas = Factura::where('team_id', $teamId)
            ->whereNotNull('rfc')
            ->whereNotNull('fecha_emision')
            ->get(['rfc', 'nombre', 'fecha_emision']);

        return $facturas
            ->groupBy('rfc')
            ->map(function ($grupo, $rfc) use ($ventana, $mesActual, $mapaEmpresa) {
                $ordenadas = $grupo->sortBy('fecha_emision');
                $ultima = $ordenadas->last();

                $meses = $grupo
                    ->map(fn ($f) => Carbon::parse($f->fecha_emision)->format('Y-m'))
                    ->unique()
                    ->values();

                $mesesEnVentana = $meses->intersect($ventana)->count();
                $recurrente = $mesesEnVentana >= 3;
                $sinFacturaMesActual = $recurrente && ! $meses->contains($mesActual);

                $cat = $mapaEmpresa->get($rfc);

                return [
                    'rfc' => $rfc,
                    'nombre' => $ultima->nombre,
                    'ultima_fecha' => Carbon::parse($ultima->fecha_emision)->toDateString(),
                    'conteo' => $grupo->count(),
                    'meses_facturados' => $meses->count(),
                    'recurrente' => $recurrente,
                    'sin_factura_mes_actual' => $sinFacturaMesActual,
                    'empresa' => $cat && $cat->empresa ? [
                        'id' => $cat->empresa->id,
                        'nombre' => $cat->empresa->nombre,
                        'color' => $cat->empresa->color,
                    ] : null,
                ];
            })
            ->filter(fn ($row) => $row['recurrente'])
            // Los "sin factura este mes" primero; luego por fecha más reciente.
            ->sortBy([
                ['sin_factura_mes_actual', 'desc'],
                ['ultima_fecha', 'desc'],
            ])
            ->values()
            ->all();
    }
}

<?php

namespace App\Http\Controllers;

use App\Models\Conciliacion;
use App\Models\Factura;
use App\Models\Movimiento;
use App\Services\Reconciliation\MatcherService;
use Illuminate\Http\Request;
use Inertia\Inertia;

class ReconciliationController extends Controller
{
    public function index(Request $request)
    {
        $teamId = auth()->user()->current_team_id;

        // Middleware sets 'month' and 'year' in request from session/input
        $month = $request->input('month');
        $year = $request->input('year');

        // New Filters
        $dateFrom = $request->input('date_from');
        $dateTo = $request->input('date_to');
        $amountMin = $request->input('amount_min');
        $amountMax = $request->input('amount_max');

        // Queries
        $invoicesQuery = Factura::where('team_id', $teamId)->doesntHave('conciliaciones');
        $movementsQuery = Movimiento::where('team_id', $teamId)
            ->where(function ($query) {
                $query->where('tipo', 'abono')->orWhere('tipo', 'Abono');
            })
            ->with(['archivo.bankFormat'])
            ->doesntHave('conciliaciones');

        // Date Filter Strategy
        if ($dateFrom || $dateTo) {
            if ($dateFrom) {
                $invoicesQuery->whereDate('fecha_emision', '>=', $dateFrom);
                $movementsQuery->whereDate('fecha', '>=', $dateFrom);
            }
            if ($dateTo) {
                $invoicesQuery->whereDate('fecha_emision', '<=', $dateTo);
                $movementsQuery->whereDate('fecha', '<=', $dateTo);
            }
        } else {
            // Fallback to Month/Year w/ strict filtering
            if ($month) {
                $invoicesQuery->whereMonth('fecha_emision', $month);
                $movementsQuery->whereMonth('fecha', $month);
            }
            if ($year) {
                $invoicesQuery->whereYear('fecha_emision', $year);
                $movementsQuery->whereYear('fecha', $year);
            }
        }

        // Amount Filter
        if ($amountMin) {
            $invoicesQuery->where('monto', '>=', $amountMin);
            $movementsQuery->where('monto', '>=', $amountMin);
        }
        if ($amountMax) {
            $invoicesQuery->where('monto', '<=', $amountMax);
            $movementsQuery->where('monto', '<=', $amountMax);
        }

        $invoices = $invoicesQuery->orderBy('fecha_emision', 'desc')->limit(200)->get();
        $movements = $movementsQuery->orderBy('fecha', 'desc')->limit(200)->get();

        // Fetch Tolerance Settings
        $tolerancia = \App\Models\Tolerancia::firstOrCreate(
            ['team_id' => $teamId],
            ['monto' => 0.00, 'user_id' => auth()->id()]
        );
        $toleranceAmount = (float) ($tolerancia->monto ?? 0.00);

        return Inertia::render('Reconciliation/Workbench', [
            'invoices' => $invoices,
            'movements' => $movements,
            'tolerance' => $toleranceAmount,
            'filters' => [
                'month' => $month,
                'year' => $year,
                'date_from' => $dateFrom,
                'date_to' => $dateTo,
                'amount_min' => $amountMin,
                'amount_max' => $amountMax,
            ],
        ]);
    }

    public function auto(Request $request, MatcherService $matcher)
    {
        $teamId = auth()->user()->current_team_id;

        // Fetch Tolerance Settings
        $tolerancia = \App\Models\Tolerancia::firstOrCreate(
            ['team_id' => $teamId],
            ['monto' => 0.00, 'user_id' => auth()->id()]
        );
        $toleranceAmount = (float) ($tolerancia->monto ?? 0.00);

        $month = $request->input('month');
        $year = $request->input('year');

        // Find matches using configured tolerance
        // Note: toleranceDays is reused as 'strict month' toggle in a way, or we just ignore it.
        // We pass month/year to filter potential candidates?
        // Identifying candidates happens inside findMatches usually.
        // We should update findMatches signature to accept Month/Year context.
        $matches = $matcher->findMatches($teamId, $toleranceAmount, $month, $year);

        // Instead of applying them, return suggestions for user to confirm
        return Inertia::render('Reconciliation/Matches', [
            'matches' => $matches,
            'tolerance' => [
                'amount' => $toleranceAmount,
            ],
        ]);
    }

    public function batch(Request $request, MatcherService $matcher)
    {
        $request->validate([
            'matches' => 'required|array',
            'matches.*.invoice_id' => 'required|exists:facturas,id',
            'matches.*.movement_id' => 'required|exists:movimientos,id',
        ]);

        foreach ($request->matches as $match) {
            // Verify ownership for each pair
            $invoice = Factura::where('id', $match['invoice_id'])->where('team_id', auth()->user()->current_team_id)->exists();
            $movement = Movimiento::where('id', $match['movement_id'])->where('team_id', auth()->user()->current_team_id)->exists();

            if (! $invoice || ! $movement) {
                abort(403, 'Unauthorized access to resources.');
            }
        }

        $count = 0;
        $teamId = auth()->user()->current_team_id;
        foreach ($request->matches as $match) {
            $movement = Movimiento::where('team_id', $teamId)->find($match['movement_id']);

            $matcher->reconcile(
                [$match['invoice_id']],
                [$match['movement_id']],
                'automatico',
                $movement ? $movement->fecha : null
            );
            $count++;
        }

        return redirect()->route('reconciliation.index')->with('success', "Se han conciliado {$count} registros exitosamente.");
    }

    public function store(Request $request, MatcherService $matcher)
    {
        \Illuminate\Support\Facades\Log::info('Reconciliation Store Request', $request->all());
        \Illuminate\Support\Facades\Log::info('Conciliacion At', ['val' => $request->conciliacion_at]);
        $request->validate([
            'invoice_ids' => 'required|array',
            'movement_ids' => 'required|array',
            'conciliacion_at' => 'nullable|date',
        ]);

        // Validate RFC consistency locally & Ownership
        $invoices = Factura::where('team_id', auth()->user()->current_team_id)
            ->whereIn('id', $request->invoice_ids)
            ->get();

        if ($invoices->count() !== count($request->invoice_ids)) {
            abort(403, 'Unauthorized access to some resources.');
        }
        if ($invoices->count() > 1) {
            $firstRfc = $invoices->first()->rfc;
            $mismatch = $invoices->some(function ($invoice) use ($firstRfc) {
                return $invoice->rfc !== $firstRfc;
            });

            if ($mismatch) {
                return back()->withErrors(['error' => 'Discrepancia de RFC: Todas las facturas seleccionadas deben pertenecer al mismo RFC receptor.']);
            }
        }

        $matcher->reconcile($request->invoice_ids, $request->movement_ids, 'manual', $request->conciliacion_at);

        return back()->with('success', 'Conciliación manual registrada exitosamente.');
    }

    public function history(Request $request)
    {
        $teamId = auth()->user()->current_team_id;
        $search = $request->input('search');

        // Date selection
        $month = $request->input('month');
        $year = $request->input('year');
        $dateFrom = $request->input('date_from');
        $dateTo = $request->input('date_to');

        // Amount selection
        $amountMin = $request->input('amount_min');
        $amountMax = $request->input('amount_max');

        // 1. Paginate distinct group_ids (filtered)
        $query = Conciliacion::query()
            ->join('facturas', 'conciliacions.factura_id', '=', 'facturas.id')
            ->join('movimientos', 'conciliacions.movimiento_id', '=', 'movimientos.id')
            ->where('facturas.team_id', $teamId);

        // Date Filter Strategy: Range takes precedence over Month/Year picker
        if ($dateFrom || $dateTo) {
            if ($dateFrom) {
                // Use coalesce to fallback to created_at if fecha_conciliacion is null (though migration defaults might apply)
                $query->whereDate('conciliacions.fecha_conciliacion', '>=', $dateFrom);
            }
            if ($dateTo) {
                $query->whereDate('conciliacions.fecha_conciliacion', '<=', $dateTo);
            }
        } else {
            // Fallback to Month/Year if no specific range provided
            if ($month) {
                $query->whereMonth('conciliacions.fecha_conciliacion', $month);
            }
            if ($year) {
                $query->whereYear('conciliacions.fecha_conciliacion', $year);
            }
        }

        // Search Logic
        if ($search) {
            $query->where(function ($q) use ($search) {
                $q->where('facturas.nombre', 'like', "%{$search}%")
                    ->orWhere('facturas.rfc', 'like', "%{$search}%")
                    ->orWhere('movimientos.descripcion', 'like', "%{$search}%")
                    ->orWhere('movimientos.referencia', 'like', "%{$search}%");
                if (is_numeric($search)) {
                    $q->orWhere('movimientos.monto', (float) $search);
                }
            });
        }

        // Grouping & Filtering by Aggregate Amount
        $query->groupBy('conciliacions.group_id')
            ->select('conciliacions.group_id')
            ->selectRaw('MAX(conciliacions.created_at) as created_at')
            ->selectRaw('MAX(conciliacions.fecha_conciliacion) as fecha_conciliacion');

        // Amount Filter (Total Applied in Group)
        if ($amountMin || $amountMax) {
            $sumExpr = 'SUM(conciliacions.monto_aplicado)';
            if ($amountMin) {
                $query->havingRaw("$sumExpr >= ?", [$amountMin]);
            }
            if ($amountMax) {
                $query->havingRaw("$sumExpr <= ?", [$amountMax]);
            }
        }

        $perPage = $request->input('per_page', 10);
        if ($perPage === 'all') {
            $perPage = 200;
        } elseif (! in_array((int) $perPage, [10, 25, 50])) {
            $perPage = 10;
        } else {
            $perPage = (int) $perPage;
        }

        $groupsPager = $query->orderBy('created_at', 'desc')
            ->paginate($perPage)
            ->withQueryString();

        // 2. Fetch details for these groups
        $groupIds = collect($groupsPager->items())->pluck('group_id');

        $details = Conciliacion::whereIn('group_id', $groupIds)
            ->with(['factura', 'movimiento.archivo.bankFormat', 'user'])
            ->get()
            ->groupBy('group_id');

        // 3. Transform to clean structure
        $transformedGroups = collect($groupsPager->items())->map(function ($groupItem) use ($details) {
            $groupId = $groupItem->group_id;
            $items = $details->get($groupId);

            if (! $items) {
                return null;
            }

            $first = $items->first();

            // Unique Invoices and Movements
            $invoices = $items->pluck('factura')->unique('id')->values();
            $movements = $items->pluck('movimiento')->unique('id')->values();

            $totalInvoices = $invoices->sum('monto');
            $totalMovements = $movements->sum('monto');

            // Sum of monto_aplicado of all items in this group
            $totalApplied = $items->sum('monto_aplicado');

            return [
                'id' => $groupId,
                'created_at' => $first->fecha_conciliacion ?? $first->created_at,
                'user' => $first->user,
                'invoices' => $invoices,
                'movements' => $movements,
                'total_invoices' => $totalInvoices,
                'total_movements' => $totalMovements,
                'total_applied' => $totalApplied,
            ];
        })->filter();

        $groupsPager->setCollection($transformedGroups);

        return Inertia::render('Reconciliation/History', [
            'reconciledGroups' => $groupsPager,
            'filters' => [
                'search' => $search,
                'month' => $month,
                'year' => $year,
                'date_from' => $dateFrom,
                'date_to' => $dateTo,
                'amount_min' => $amountMin,
                'amount_max' => $amountMax,
                'per_page' => (int) $perPage,
            ],
        ]);
    }

    public function status(Request $request)
    {
        $teamId = auth()->user()->current_team_id;
        $search = $request->input('search');
        $month = $request->input('month');
        $year = $request->input('year');

        // Independent Sort Parameters
        $invoiceSort = $request->input('invoice_sort', 'date');
        $invoiceDirection = $request->input('invoice_direction', 'desc');
        $movementSort = $request->input('movement_sort', 'date');
        $movementDirection = $request->input('movement_direction', 'desc');

        // Advanced Filters
        $dateFrom = $request->input('date_from');
        $dateTo = $request->input('date_to');
        $amountMin = $request->input('amount_min');
        $amountMax = $request->input('amount_max');

        // Invoice Sort Mapping
        $invoiceSortColumn = match ($invoiceSort) {
            'amount' => 'monto',
            default => 'fecha_emision',
        };

        // Movement Sort Mapping
        $movementSortColumn = match ($movementSort) {
            'amount' => 'monto',
            default => 'fecha',
        };

        // Validate month/year to prevent silent failures from invalid values
        $validMonth = ($month && is_numeric($month) && (int) $month >= 1 && (int) $month <= 12) ? (int) $month : null;
        $validYear = ($year && is_numeric($year) && (int) $year >= 2000 && (int) $year <= 2100) ? (int) $year : null;

        // Helper closures for search
        $invoiceSearch = function ($query) use ($search, $dateFrom, $dateTo, $validMonth, $validYear, $amountMin, $amountMax) {
            if ($search) {
                $query->where(function ($q) use ($search) {
                    $q->where('nombre', 'like', "%{$search}%")
                        ->orWhere('rfc', 'like', "%{$search}%")
                        ->orWhere('uuid', 'like', "%{$search}%");
                    if (is_numeric($search)) {
                        $q->orWhere('monto', (float) $search);
                    }
                });
            }

            // Date Filters (Date range takes precedence over Month/Year)
            if ($dateFrom || $dateTo) {
                if ($dateFrom) {
                    $query->whereDate('fecha_emision', '>=', $dateFrom);
                }
                if ($dateTo) {
                    $query->whereDate('fecha_emision', '<=', $dateTo);
                }
            } else {
                if ($validMonth) {
                    $query->whereMonth('fecha_emision', $validMonth);
                }
                if ($validYear) {
                    $query->whereYear('fecha_emision', $validYear);
                }
            }

            // Amount Filters
            if ($amountMin) {
                $query->where('monto', '>=', $amountMin);
            }
            if ($amountMax) {
                $query->where('monto', '<=', $amountMax);
            }
        };

        $movementSearch = function ($query) use ($search, $dateFrom, $dateTo, $validMonth, $validYear, $amountMin, $amountMax) {
            if ($search) {
                $query->where(function ($q) use ($search) {
                    $q->where('descripcion', 'like', "%{$search}%")
                        ->orWhere('referencia', 'like', "%{$search}%");
                    if (is_numeric($search)) {
                        $q->orWhere('monto', (float) $search);
                    }
                });
            }

            // Date Filters
            if ($dateFrom || $dateTo) {
                if ($dateFrom) {
                    $query->whereDate('fecha', '>=', $dateFrom);
                }
                if ($dateTo) {
                    $query->whereDate('fecha', '<=', $dateTo);
                }
            } else {
                if ($validMonth) {
                    $query->whereMonth('fecha', $validMonth);
                }
                if ($validYear) {
                    $query->whereYear('fecha', $validYear);
                }
            }

            // Amount Filters
            if ($amountMin) {
                $query->where('monto', '>=', $amountMin);
            }
            if ($amountMax) {
                $query->where('monto', '<=', $amountMax);
            }
        };

        // Conciliated Items
        $conciliatedInvoices = Factura::where('team_id', $teamId)
            ->has('conciliaciones')
            ->where($invoiceSearch)
            ->with(['conciliaciones.user'])
            ->orderBy($invoiceSortColumn, $invoiceDirection)
            ->limit(50)
            ->get();

        $conciliatedMovements = Movimiento::where('team_id', $teamId)
            ->has('conciliaciones')
            ->where($movementSearch)
            ->with(['conciliaciones.user', 'archivo.bankFormat'])
            ->orderBy($movementSortColumn, $movementDirection)
            ->limit(50)
            ->get();

        // Pending Items (limited to prevent memory exhaustion)
        $pendingInvoices = Factura::where('team_id', $teamId)
            ->doesntHave('conciliaciones')
            ->where($invoiceSearch)
            ->orderBy($invoiceSortColumn, $invoiceDirection)
            ->limit(200)
            ->get();

        $pendingMovements = Movimiento::where('team_id', $teamId)
            ->where(function ($query) {
                $query->where('tipo', 'abono')
                    ->orWhere('tipo', 'Abono');
            })
            ->doesntHave('conciliaciones')
            ->where($movementSearch)
            ->with(['archivo.bankFormat'])
            ->orderBy($movementSortColumn, $movementDirection)
            ->limit(200)
            ->get();

        return Inertia::render('Reconciliation/Status', [
            'conciliatedInvoices' => $conciliatedInvoices,
            'conciliatedMovements' => $conciliatedMovements,
            'pendingInvoices' => $pendingInvoices,
            'pendingMovements' => $pendingMovements,
            'totalPendingInvoices' => $pendingInvoices->sum('monto'),
            'totalPendingMovements' => $pendingMovements->sum('monto'),
            'totalConciliatedInvoices' => $conciliatedInvoices->sum('monto'),
            'totalConciliatedMovements' => $conciliatedMovements->sum('monto'),
            'filters' => [
                'search' => $search,
                'date_from' => $dateFrom,
                'date_to' => $dateTo,
                'amount_min' => $amountMin,
                'amount_max' => $amountMax,
                'month' => $month,
                'year' => $year,
                'invoice_sort' => $invoiceSort,
                'invoice_direction' => $invoiceDirection,
                'movement_sort' => $movementSort,
                'movement_direction' => $movementDirection,
            ],
        ]);
    }

    public function export(Request $request)
    {
        $request->validate([
            'format' => 'nullable|in:xlsx,pdf',
            'month' => 'nullable|integer|min:1|max:12',
            'year' => 'nullable|integer|min:2000|max:2100',
            'date_from' => 'nullable|date',
            'date_to' => 'nullable|date',
            'amount_min' => 'nullable|numeric|min:0',
            'amount_max' => 'nullable|numeric|min:0',
        ]);

        $teamId = auth()->user()->current_team_id;
        $userId = auth()->id();

        $month = $request->input('month');
        $year = $request->input('year');
        $dateFrom = $request->input('date_from');
        $dateTo = $request->input('date_to');
        $search = $request->input('search');
        $amountMin = $request->input('amount_min');
        $amountMax = $request->input('amount_max');

        // Always async for now, unless row count check implemented later.
        $format = $request->input('format', 'xlsx');

        // Create Request Record
        $exportRequest = \App\Models\ExportRequest::create([
            'team_id' => $teamId,
            'user_id' => $userId,
            'type' => $format,
            'status' => 'queued',
            'filters' => [
                'month' => $month,
                'year' => $year,
                'date_from' => $dateFrom,
                'date_to' => $dateTo,
                'search' => $search,
                'amount_min' => $amountMin,
                'amount_max' => $amountMax,
            ],
        ]);

        // Dispatch Job
        if ($format === 'pdf') {
            \App\Jobs\GenerateReconciliationPdfExportJob::dispatch($exportRequest);
        } else {
            \App\Jobs\GenerateReconciliationExcelExportJob::dispatch($exportRequest);
        }

        if ($request->wantsJson()) {
            return response()->json([
                'id' => $exportRequest->id,
                'status' => 'queued',
                'message' => 'Export starting.',
            ]);
        }

        // Fallback for non-JSON request (if any legacy link exists)
        return back()->with('success', 'Exportación iniciada. Verifique el historial en unos momentos.');
    }

    public function checkExportStatus($id)
    {
        $exportRequest = \App\Models\ExportRequest::where('team_id', auth()->user()->current_team_id)
            ->findOrFail($id);

        if ($exportRequest->user_id !== auth()->id()) {
            abort(403);
        }

        // Offline Safeguard: If queued for > 2 minutes, warn user.
        $isOffline = false;
        if ($exportRequest->status === 'queued' && $exportRequest->created_at->diffInMinutes(now()) > 2) {
            $isOffline = true;
        }

        return response()->json([
            'status' => $exportRequest->status,
            'error_message' => $exportRequest->error_message,
            'is_offline' => $isOffline, // Frontend can check this flag
        ]);
    }

    public function downloadExport($id)
    {
        $exportRequest = \App\Models\ExportRequest::where('team_id', auth()->user()->current_team_id)
            ->findOrFail($id);

        if ($exportRequest->user_id !== auth()->id()) {
            abort(403);
        }

        if ($exportRequest->status !== 'completed' || ! $exportRequest->file_path) {
            abort(404, 'File not ready or failed.');
        }

        if (! \Illuminate\Support\Facades\Storage::exists($exportRequest->file_path)) {
            abort(404, 'File not found on disk.');
        }

        return \Illuminate\Support\Facades\Storage::download(
            $exportRequest->file_path,
            $exportRequest->file_name
        );
    }

    public function destroy($id)
    {
        $conciliacion = Conciliacion::findOrFail($id);

        // Check ownership via Factura
        if ($conciliacion->factura->team_id !== auth()->user()->current_team_id) {
            abort(403);
        }

        $conciliacion->delete();

        return back()->with('success', 'Conciliación eliminada exitosamente.');
    }

    public function destroyGroup($groupId)
    {
        // Find one record to verify team ownership
        $first = Conciliacion::where('group_id', $groupId)->firstOrFail();

        // This check is a bit tricky if we join but simpler:
        // ensure item belongs to user's team.
        // We can just rely on the join logic or check one relation.
        if ($first->factura->team_id !== auth()->user()->current_team_id) {
            abort(403);
        }

        // Delete all with this group_id
        Conciliacion::where('group_id', $groupId)->delete();

        return back()->with('success', 'Grupo de conciliación desvinculado exitosamente.');
    }
}

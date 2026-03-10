<?php

namespace App\Exports\Sheets;

use App\Exports\Traits\ExcelStylingHelper;
use App\Models\Conciliacion;
use Illuminate\Support\Facades\DB;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithChunkReading;
use Maatwebsite\Excel\Concerns\WithColumnFormatting;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithTitle;

class ConciliatedInvoicesSheet implements FromQuery, ShouldAutoSize, WithChunkReading, WithColumnFormatting, WithEvents, WithHeadings, WithMapping, WithTitle
{
    use ExcelStylingHelper;

    protected $teamId;

    protected $month;

    protected $year;

    protected $dateFrom;

    protected $dateTo;

    protected $search;

    protected $amountMin;

    protected $amountMax;

    protected $groupIds;

    public function __construct($teamId, $month, $year, $dateFrom, $dateTo, $search = null, $amountMin = null, $amountMax = null, $groupIds = [])
    {
        $this->teamId = $teamId;
        $this->month = $month;
        $this->year = $year;
        $this->dateFrom = $dateFrom;
        $this->dateTo = $dateTo;
        $this->search = $search;
        $this->amountMin = $amountMin;
        $this->amountMax = $amountMax;
        $this->groupIds = $groupIds;
    }

    public function query()
    {
        $query = Conciliacion::query()
            ->select(
                'conciliacions.group_id',
                'conciliacions.factura_id',
                'conciliacions.user_id',
                DB::raw('MAX(conciliacions.fecha_conciliacion) as fecha_conciliacion'),
                DB::raw('SUM(conciliacions.monto_aplicado) as monto_aplicado')
            )
            ->with(['factura', 'user'])
            ->where('conciliacions.team_id', $this->teamId)
            ->join('facturas', 'conciliacions.factura_id', '=', 'facturas.id')
            ->groupBy('conciliacions.group_id', 'conciliacions.factura_id', 'conciliacions.user_id')
            ->orderBy('conciliacions.group_id');

        // If we have specific groupIds from the orchestrator, we use them.
        // These IDs were already filtered by search/date/amount in the orchestrator.
        if (! empty($this->groupIds)) {
            $query->whereIn('conciliacions.group_id', $this->groupIds);

            return $query;
        }

        if ($this->dateFrom || $this->dateTo) {
            if ($this->dateFrom) {
                $query->whereDate('conciliacions.fecha_conciliacion', '>=', $this->dateFrom);
            }
            if ($this->dateTo) {
                $query->whereDate('conciliacions.fecha_conciliacion', '<=', $this->dateTo);
            }
        } elseif ($this->month && $this->year) {
            $query->whereMonth('conciliacions.fecha_conciliacion', $this->month)
                ->whereYear('conciliacions.fecha_conciliacion', $this->year);
        }

        if ($this->search) {
            $query->where(function ($q) {
                $q->where('facturas.nombre', 'like', "%{$this->search}%")
                    ->orWhere('facturas.rfc', 'like', "%{$this->search}%")
                    ->orWhere('facturas.folio', 'like', "%{$this->search}%")
                    ->orWhere('facturas.referencia', 'like', "%{$this->search}%");
            });
        }

        if ($this->amountMin) {
            $query->where('facturas.monto', '>=', $this->amountMin);
        }

        if ($this->amountMax) {
            $query->where('facturas.monto', '<=', $this->amountMax);
        }

        return $query;
    }

    public function headings(): array
    {
        return [
            'Fecha Conciliación',
            'Group ID',
            'RFC Receptor',
            'Razón Social',
            'UUID Factura',
            'Referencia',
            'Monto Factura',
            'Monto Aplicado',
            'Suma Facturas del Grupo',
            'Suma Pagos del Grupo',
            'Diferencia del Grupo',
            'Conciliado Por',
        ];
    }

    public function map($conciliacion): array
    {
        $groupId = $conciliacion->group_id;

        $totalPagos = DB::table('conciliacions')
            ->join('movimientos', 'conciliacions.movimiento_id', '=', 'movimientos.id')
            ->where('conciliacions.team_id', $this->teamId)
            ->where('group_id', $groupId)
            ->distinct()
            ->sum('movimientos.monto');

        $totalFacturas = DB::table('conciliacions')
            ->join('facturas', 'conciliacions.factura_id', '=', 'facturas.id')
            ->where('conciliacions.team_id', $this->teamId)
            ->where('group_id', $groupId)
            ->distinct()
            ->sum('facturas.monto');

        $diferencia = $totalPagos - $totalFacturas;

        return [
            $conciliacion->fecha_conciliacion ? \Carbon\Carbon::parse($conciliacion->fecha_conciliacion)->format('d/m/Y') : 'N/A',
            $conciliacion->group_id,
            $conciliacion->factura->rfc ?? 'N/A',
            $conciliacion->factura->nombre ?? 'N/A',
            $conciliacion->factura->uuid ?? 'N/A',
            $conciliacion->factura->referencia ?? 'N/A',
            $conciliacion->factura->monto ?? 0,
            $conciliacion->monto_aplicado ?? 0,
            $totalFacturas,
            $totalPagos,
            $diferencia,
            $conciliacion->user->name ?? 'N/A',
        ];
    }

    public function chunkSize(): int
    {
        return 1000;
    }

    public function title(): string
    {
        return 'Facturas Conciliadas';
    }
}

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Estado de Resultados</title>
    <style>
        @page { margin: 12mm 12mm; size: A4 portrait; }

        body {
            font-family: 'Helvetica', 'Arial', sans-serif;
            font-size: 9pt;
            color: #1F2937;
            line-height: 1.3;
            background-color: #F8FAFC;
            margin: 0;
            padding: 0;
        }

        .w-full { width: 100%; }
        .text-right { text-align: right; }
        .text-center { text-align: center; }
        .font-bold { font-weight: bold; }
        .uppercase { text-transform: uppercase; }
        .text-xs { font-size: 7pt; }
        .text-sm { font-size: 8pt; }
        .text-lg { font-size: 11pt; }
        .text-xl { font-size: 14pt; }
        .text-2xl { font-size: 18pt; }

        .text-blue { color: #2563EB; }
        .text-green { color: #16A34A; }
        .text-red { color: #DC2626; }
        .text-gray { color: #6B7280; }
        .text-white { color: #ffffff; }

        .header-block {
            padding-bottom: 18px;
            margin-bottom: 18px;
            border-bottom: 2px solid #3B82F6;
        }

        .card {
            background-color: #ffffff;
            border: 1px solid #E2E8F0;
            border-radius: 8px;
            padding: 15px;
            margin-bottom: 15px;
            page-break-inside: avoid;
        }

        .section-title {
            font-size: 12pt;
            font-weight: bold;
            color: #0F172A;
            margin-bottom: 10px;
            border-left: 4px solid #3B82F6;
            padding-left: 10px;
        }

        /* Estado de Resultados */
        .pl-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 9pt;
        }
        .pl-table td {
            padding: 7px 8px;
            border-bottom: 1px solid #F1F5F9;
        }
        .pl-table .label { color: #334155; }
        .pl-table .amount { text-align: right; font-weight: bold; color: #0F172A; }
        .pl-table .margin { text-align: right; color: #64748B; width: 60px; }
        .pl-table .indent { padding-left: 24px; color: #64748B; font-size: 8pt; }

        .row-subtotal td { background-color: #F8FAFC; border-top: 1px solid #CBD5E1; }
        .row-total td { background-color: #0F172A; color: #ffffff; }
        .row-total .label, .row-total .amount, .row-total .margin { color: #ffffff; }

        /* KPI strip */
        .kpi-strip {
            background-color: #0F172A;
            color: white;
            border-radius: 8px;
            padding: 12px 0;
            margin-bottom: 18px;
        }
        .kpi-cell {
            text-align: center;
            border-right: 1px solid #334155;
            width: 25%;
        }
        .kpi-cell:last-child { border-right: none; }
        .kpi-label { font-size: 7pt; text-transform: uppercase; color: #94A3B8; letter-spacing: 0.5px; }
        .kpi-value { font-size: 11pt; font-weight: bold; margin-top: 2px; }
        .kpi-sub { font-size: 7pt; color: #94A3B8; margin-top: 1px; }

        /* Margen por empresa */
        .emp-table { width: 100%; border-collapse: collapse; font-size: 8pt; }
        .emp-table th {
            text-align: left; color: #64748B; font-weight: bold; text-transform: uppercase;
            border-bottom: 1px solid #E2E8F0; padding: 6px 4px; font-size: 7pt;
        }
        .emp-table td { padding: 6px 4px; border-bottom: 1px solid #F1F5F9; color: #334155; }
        .emp-table tr:last-child td { border-bottom: none; }
        .dot { display: inline-block; width: 9px; height: 9px; border-radius: 50%; margin-right: 5px; }
        .bar-wrap { background-color: #F1F5F9; border-radius: 999px; height: 8px; width: 100%; }
        .bar { height: 8px; border-radius: 999px; }
    </style>
</head>
<body>
    @php
        $granularidadLabel = [
            'mensual' => 'Mensual',
            'trimestral' => 'Trimestral',
            'semestral' => 'Semestral',
            'anual' => 'Anual',
        ][$granularidad] ?? 'Mensual';
        $fmt = fn ($v) => '$'.number_format((float) $v, 2);
        $pct = fn ($v) => number_format((float) $v * 100, 1).'%';
    @endphp

    <!-- Header -->
    <div class="header-block">
        <table class="w-full">
            <tr>
                <td width="60%">
                    <div class="text-xl font-bold" style="color: #0F172A;">ESTADO DE RESULTADOS</div>
                    <div class="text-xs font-bold text-blue uppercase" style="letter-spacing: 1px;">
                        {{ $empresaNombre }} &bull; {{ $granularidadLabel }}
                    </div>
                </td>
                <td width="40%" class="text-right">
                    <div class="text-xs text-gray uppercase">Periodo</div>
                    <div class="text-lg font-bold" style="color: #0F172A;">
                        {{ \Carbon\Carbon::parse($desde)->locale('es')->isoFormat('D MMM Y') }}
                        &mdash;
                        {{ \Carbon\Carbon::parse($hasta)->locale('es')->isoFormat('D MMM Y') }}
                    </div>
                    <div class="text-xs text-gray">
                        Generado: {{ $generadoAt->format('d/m/Y H:i') }}
                    </div>
                </td>
            </tr>
        </table>
    </div>

    <!-- KPI strip -->
    <table class="w-full kpi-strip">
        <tr>
            <td class="kpi-cell">
                <div class="kpi-label">Ingresos</div>
                <div class="kpi-value text-white">{{ $fmt($pnl['ingresos']['total']) }}</div>
            </td>
            <td class="kpi-cell">
                <div class="kpi-label">Utilidad Bruta</div>
                <div class="kpi-value text-white">{{ $fmt($pnl['utilidad_bruta']) }}</div>
                <div class="kpi-sub">{{ $pct($pnl['margen_bruto']) }}</div>
            </td>
            <td class="kpi-cell">
                <div class="kpi-label">EBITDA</div>
                <div class="kpi-value text-white">{{ $fmt($pnl['ebitda']) }}</div>
                <div class="kpi-sub">{{ $pct($pnl['margen_ebitda']) }}</div>
            </td>
            <td class="kpi-cell">
                <div class="kpi-label">Utilidad Neta</div>
                <div class="kpi-value" style="color: {{ $pnl['utilidad_neta'] >= 0 ? '#34D399' : '#F87171' }};">{{ $fmt($pnl['utilidad_neta']) }}</div>
                <div class="kpi-sub">{{ $pct($pnl['margen_neto']) }}</div>
            </td>
        </tr>
    </table>

    <!-- Estado de Resultados -->
    <div class="section-title">Estado de Resultados</div>
    <div class="card">
        <table class="pl-table">
            <tr>
                <td class="label font-bold">Ingresos totales</td>
                <td class="amount">{{ $fmt($pnl['ingresos']['total']) }}</td>
                <td class="margin">100%</td>
            </tr>
            <tr>
                <td class="indent">Bancario conciliado</td>
                <td class="amount" style="font-weight: normal; color: #64748B;">{{ $fmt($pnl['ingresos']['bancario_conciliado']) }}</td>
                <td class="margin"></td>
            </tr>
            <tr>
                <td class="indent">Ingreso manual (efectivo)</td>
                <td class="amount" style="font-weight: normal; color: #64748B;">{{ $fmt($pnl['ingresos']['manual']) }}</td>
                <td class="margin"></td>
            </tr>
            <tr>
                <td class="label">(&minus;) Costo de venta</td>
                <td class="amount text-red">{{ $fmt($pnl['costo_venta']) }}</td>
                <td class="margin"></td>
            </tr>
            <tr class="row-subtotal">
                <td class="label font-bold">Utilidad bruta</td>
                <td class="amount">{{ $fmt($pnl['utilidad_bruta']) }}</td>
                <td class="margin">{{ $pct($pnl['margen_bruto']) }}</td>
            </tr>
            <tr>
                <td class="label">(&minus;) Gasto operativo</td>
                <td class="amount text-red">{{ $fmt($pnl['gasto_operativo']) }}</td>
                <td class="margin"></td>
            </tr>
            <tr class="row-subtotal">
                <td class="label font-bold">EBITDA</td>
                <td class="amount">{{ $fmt($pnl['ebitda']) }}</td>
                <td class="margin">{{ $pct($pnl['margen_ebitda']) }}</td>
            </tr>
            <tr>
                <td class="label">(&minus;) Debajo de EBITDA</td>
                <td class="amount text-red">{{ $fmt($pnl['abajo_ebitda']) }}</td>
                <td class="margin"></td>
            </tr>
            @if((float) $pnl['sin_clasificar'] != 0.0)
            <tr>
                <td class="label">(&minus;) Sin clasificar</td>
                <td class="amount text-red">{{ $fmt($pnl['sin_clasificar']) }}</td>
                <td class="margin"></td>
            </tr>
            @endif
            <tr class="row-total">
                <td class="label font-bold">Utilidad neta</td>
                <td class="amount">{{ $fmt($pnl['utilidad_neta']) }}</td>
                <td class="margin">{{ $pct($pnl['margen_neto']) }}</td>
            </tr>
        </table>
    </div>

    <!-- Comparativos -->
    <div class="card">
        <table class="w-full" style="font-size: 8pt;">
            <tr>
                <td width="33%" class="text-gray uppercase text-xs">Periodo actual</td>
                <td width="33%" class="text-gray uppercase text-xs">Periodo anterior</td>
                <td width="33%" class="text-gray uppercase text-xs">Año anterior</td>
            </tr>
            <tr>
                <td class="font-bold text-lg">{{ $fmt($pnl['ingresos']['total']) }}</td>
                <td class="font-bold">{{ $fmt($pnlPrev['ingresos']['total']) }}</td>
                <td class="font-bold">{{ $fmt($pnlYoY['ingresos']['total']) }}</td>
            </tr>
            <tr>
                <td class="text-xs text-gray">Utilidad neta: {{ $fmt($pnl['utilidad_neta']) }}</td>
                <td class="text-xs text-gray">Utilidad neta: {{ $fmt($pnlPrev['utilidad_neta']) }}</td>
                <td class="text-xs text-gray">Utilidad neta: {{ $fmt($pnlYoY['utilidad_neta']) }}</td>
            </tr>
        </table>
    </div>

    <!-- Margen por empresa -->
    @if($porEmpresa->count() > 0)
    <div class="section-title">Margen por empresa</div>
    <div class="card">
        <table class="emp-table">
            <thead>
                <tr>
                    <th width="30%">Empresa</th>
                    <th width="20%" class="text-right">Ingresos</th>
                    <th width="20%" class="text-right">Utilidad neta</th>
                    <th width="30%">Margen neto</th>
                </tr>
            </thead>
            <tbody>
                @foreach($porEmpresa as $empresa)
                    @php $margen = (float) $empresa['pnl']['margen_neto']; $w = max(0, min(100, $margen * 100)); @endphp
                    <tr>
                        <td>
                            <span class="dot" style="background: {{ $empresa['color'] ?? '#94A3B8' }};"></span>
                            {{ $empresa['nombre'] }}
                        </td>
                        <td class="text-right">{{ $fmt($empresa['pnl']['ingresos']['total']) }}</td>
                        <td class="text-right">{{ $fmt($empresa['pnl']['utilidad_neta']) }}</td>
                        <td>
                            <table class="w-full" style="border-collapse: collapse;">
                                <tr>
                                    <td style="padding: 0;">
                                        <div class="bar-wrap">
                                            <div class="bar" style="width: {{ $w }}%; background: {{ $empresa['color'] ?? '#3B82F6' }};"></div>
                                        </div>
                                    </td>
                                    <td width="40" class="text-right" style="padding: 0 0 0 6px;">{{ $pct($margen) }}</td>
                                </tr>
                            </table>
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
    @endif

    <!-- Footer -->
    <script type="text/php">
        if (isset($pdf)) {
            $text = "Página {PAGE_NUM} de {PAGE_COUNT}  |  Estado de Resultados";
            $size = 7;
            $font = $fontMetrics->getFont("Helvetica, Arial, sans-serif");
            $width = $fontMetrics->get_text_width($text, $font, $size);
            $pdf->page_text($pdf->get_width() - $width - 40, $pdf->get_height() - 20, $text, $font, $size, array(0.5, 0.5, 0.5));
        }
    </script>
</body>
</html>

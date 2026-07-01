// Tipos compartidos por el dashboard ejecutivo v2 (page + Partials).

export interface Pnl {
    ingresos: { total: number; bancario_conciliado: number; manual: number };
    costo_venta: number;
    utilidad_bruta: number;
    margen_bruto: number;
    gasto_operativo: number;
    ebitda: number;
    margen_ebitda: number;
    abajo_ebitda: number;
    sin_clasificar: number;
    utilidad_neta: number;
    margen_neto: number;
    egresos_total: number;
}

export interface EmpresaPnl {
    id: number;
    nombre: string;
    color: string | null;
    pnl: Pnl;
}

// Un punto mensual de `monthlySeries` (orden cronológico asc).
export interface MonthPoint {
    year: number;
    month: number;
    label: string;
    ingresos_total: number;
    ingresos_bancario: number;
    ingresos_manual: number;
    egresos_total: number;
    costo_venta: number;
    gasto_operativo: number;
    abajo_ebitda: number;
    sin_clasificar: number;
    utilidad_bruta: number;
    ebitda: number;
    utilidad_neta: number;
    margen_bruto: number;
    margen_ebitda: number;
    margen_neto: number;
}

// Un punto mensual de `ingresoPorEmpresaMensual`.
export interface IngresoEmpresaMonth {
    year: number;
    month: number;
    label: string;
    empresas: Array<{
        empresa_id: number;
        nombre: string | null;
        color: string | null;
        total: number;
    }>;
    sin_asignar: number;
}

export interface CategoriaEgreso {
    nombre: string;
    grupo: string | null;
    total: number;
}

export interface NaturalezaEgreso {
    fijo: number;
    variable: number;
    sin_clasificar: number;
}

export interface Proveedor {
    proveedor: string;
    total: number;
}

export interface Nomina {
    fiscal: number;
    complemento: number;
    total: number;
}

// Paleta de charts coherente con el theme (Tailwind).
export const CHART_COLORS = {
    ingresos: "#22C55E", // green-500
    egresos: "#EF4444", // red-500
    utilidad: "#6366F1", // indigo-500
    costoVenta: "#F97316", // orange-500
    gastoOperativo: "#EAB308", // yellow-500
    abajoEbitda: "#A855F7", // purple-500
    sinClasificar: "#94A3B8", // slate-400
    margenBruto: "#22C55E",
    margenEbitda: "#6366F1",
    margenNeto: "#0EA5E9", // sky-500
    fijo: "#6366F1",
    variable: "#F97316",
} as const;

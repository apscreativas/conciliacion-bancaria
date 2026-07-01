<script setup lang="ts">
import AuthenticatedLayout from "@/Layouts/AuthenticatedLayout.vue";
import { Head, router, usePage } from "@inertiajs/vue3";
import { ref, computed, onUnmounted } from "vue";
import { formatCurrency } from "@/utils/format";
import { trans } from "laravel-vue-i18n";
import axios from "axios";
import {
    CHART_COLORS,
    type Pnl,
    type EmpresaPnl,
    type MonthPoint,
    type IngresoEmpresaMonth,
    type CategoriaEgreso,
    type NaturalezaEgreso,
    type Proveedor,
    type Nomina,
} from "./types";
import KpiCard from "./Partials/KpiCard.vue";
import TrendChart from "./Partials/TrendChart.vue";
import MarginTrendChart from "./Partials/MarginTrendChart.vue";
import EgresosComposition from "./Partials/EgresosComposition.vue";
import IngresoEmpresaChart from "./Partials/IngresoEmpresaChart.vue";
import FijoVariableChart from "./Partials/FijoVariableChart.vue";
import TopProveedores from "./Partials/TopProveedores.vue";
import NominaRollup from "./Partials/NominaRollup.vue";
import PnlWaterfall from "./Partials/PnlWaterfall.vue";

const props = defineProps<{
    pnl: Pnl;
    pnlPrev: Pnl;
    pnlYoY: Pnl;
    porEmpresa: EmpresaPnl[];
    tuChecador: EmpresaPnl | null;
    empresas: Array<{ id: number; nombre: string; color: string | null }>;
    series: MonthPoint[];
    ingresoEmpresaSeries: IngresoEmpresaMonth[];
    egresosPorCategoria: CategoriaEgreso[];
    egresosPorNaturaleza: NaturalezaEgreso;
    topProveedores: Proveedor[];
    nominaRollup: Nomina;
    filters: {
        granularidad: string;
        empresa_id: number | null;
        month: number;
        year: number;
        months: number;
    };
}>();

const page = usePage();

const showToast = (message: string, type: "error" | "success" = "error") => {
    if (type === "success") page.props.flash.success = message;
    else page.props.flash.error = message;
};

const granularidad = ref(props.filters.granularidad || "mensual");
const empresaId = ref<string>(
    props.filters.empresa_id != null ? String(props.filters.empresa_id) : "",
);
const months = ref<string>(String(props.filters.months || 12));

const granularidades = [
    { value: "mensual", label: "Mensual" },
    { value: "trimestral", label: "Trimestral" },
    { value: "semestral", label: "Semestral" },
    { value: "anual", label: "Anual" },
];

const reload = () => {
    router.get(
        route("executive"),
        {
            granularidad: granularidad.value,
            empresa_id: empresaId.value || undefined,
            months: months.value,
        },
        { preserveState: true, preserveScroll: true, replace: true },
    );
};

// Delta % entre actual y comparativo (null si el comparativo es 0 → "—").
const delta = (current: number, base: number): number | null => {
    const b = Number(base);
    if (b === 0) return null;
    return (Number(current) - b) / Math.abs(b);
};

interface KpiVm {
    label: string;
    value: number;
    margin: number | null;
    deltaPrev: number | null;
    deltaYoY: number | null;
    sparkline: number[];
    color: string;
    borderClass: string;
}

const kpis = computed<KpiVm[]>(() => [
    {
        label: "Ingresos",
        value: props.pnl.ingresos.total,
        margin: null,
        deltaPrev: delta(props.pnl.ingresos.total, props.pnlPrev.ingresos.total),
        deltaYoY: delta(props.pnl.ingresos.total, props.pnlYoY.ingresos.total),
        sparkline: props.series.map((m) => m.ingresos_total),
        color: CHART_COLORS.ingresos,
        borderClass: "border-green-500",
    },
    {
        label: "Utilidad bruta",
        value: props.pnl.utilidad_bruta,
        margin: props.pnl.margen_bruto,
        deltaPrev: delta(props.pnl.utilidad_bruta, props.pnlPrev.utilidad_bruta),
        deltaYoY: delta(props.pnl.utilidad_bruta, props.pnlYoY.utilidad_bruta),
        sparkline: props.series.map((m) => m.utilidad_bruta),
        color: "#0EA5E9",
        borderClass: "border-sky-500",
    },
    {
        label: "EBITDA",
        value: props.pnl.ebitda,
        margin: props.pnl.margen_ebitda,
        deltaPrev: delta(props.pnl.ebitda, props.pnlPrev.ebitda),
        deltaYoY: delta(props.pnl.ebitda, props.pnlYoY.ebitda),
        sparkline: props.series.map((m) => m.ebitda),
        color: CHART_COLORS.utilidad,
        borderClass: "border-indigo-500",
    },
    {
        label: "Utilidad neta",
        value: props.pnl.utilidad_neta,
        margin: props.pnl.margen_neto,
        deltaPrev: delta(props.pnl.utilidad_neta, props.pnlPrev.utilidad_neta),
        deltaYoY: delta(props.pnl.utilidad_neta, props.pnlYoY.utilidad_neta),
        sparkline: props.series.map((m) => m.utilidad_neta),
        color: "#10B981",
        borderClass: "border-emerald-600",
    },
]);

// Margen por empresa: el ancho de la barra ES el margen neto (%). Negativo → 0.
const pct = (ratio: number): string => `${(Number(ratio) * 100).toFixed(1)}%`;
const empresaBarWidth = (e: EmpresaPnl): string =>
    `${Math.min(100, Math.max(0, Number(e.pnl.margen_neto) * 100))}%`;

// ─── Export PDF (polling, espeja Reconciliation/Status.vue) ───
const exportProcessing = ref(false);
const activeInterval = ref<ReturnType<typeof setInterval> | null>(null);

const clearActiveInterval = () => {
    if (activeInterval.value !== null) {
        clearInterval(activeInterval.value);
        activeInterval.value = null;
    }
};

onUnmounted(() => clearActiveInterval());

const startExport = async () => {
    if (exportProcessing.value) return;
    exportProcessing.value = true;

    try {
        const response = await axios.get(route("executive.export"), {
            params: {
                granularidad: granularidad.value,
                empresa_id: empresaId.value || undefined,
                month: props.filters.month,
                year: props.filters.year,
                months: months.value,
            },
        });

        if (response.data.id) {
            pollExport(response.data.id);
        } else {
            exportProcessing.value = false;
        }
    } catch (e) {
        exportProcessing.value = false;
        showToast(trans("No se pudo iniciar la exportación. Intenta de nuevo."));
    }
};

const pollExport = (id: number) => {
    clearActiveInterval();
    activeInterval.value = setInterval(async () => {
        try {
            const res = await axios.get(route("executive.export.status", id));
            if (res.data.status === "completed") {
                clearActiveInterval();
                exportProcessing.value = false;
                window.location.href = route("executive.export.download", id);
            } else if (res.data.status === "failed") {
                clearActiveInterval();
                exportProcessing.value = false;
                showToast(
                    trans("La exportación falló") +
                        ": " +
                        (res.data.error_message || trans("Error desconocido")),
                );
            } else if (res.data.is_offline) {
                clearActiveInterval();
                exportProcessing.value = false;
                showToast(trans("La exportación está tardando. Verifica que el worker de la cola esté activo."));
            }
        } catch (e) {
            clearActiveInterval();
            exportProcessing.value = false;
            showToast(trans("No se pudo consultar el estado de la exportación."));
        }
    }, 2000);
};
</script>

<template>
    <Head :title="$t('Dashboard ejecutivo')" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex justify-between items-center">
                <h2 class="font-semibold text-xl text-gray-800 dark:text-gray-200 leading-tight">
                    {{ $t("Dashboard ejecutivo") }}
                </h2>
            </div>
        </template>

        <div class="py-6">
            <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
                <!-- Selectores + Export -->
                <div
                    class="mb-6 flex flex-col sm:flex-row sm:items-end gap-4 bg-white dark:bg-gray-800 shadow-sm sm:rounded-lg p-4"
                >
                    <div>
                        <label class="block text-xs font-medium text-gray-500 dark:text-gray-400 uppercase mb-1">
                            {{ $t("Granularidad") }}
                        </label>
                        <select
                            v-model="granularidad"
                            @change="reload"
                            class="block w-full text-sm rounded-md border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-200 focus:ring-indigo-500 focus:border-indigo-500"
                        >
                            <option v-for="g in granularidades" :key="g.value" :value="g.value">
                                {{ $t(g.label) }}
                            </option>
                        </select>
                    </div>

                    <div>
                        <label class="block text-xs font-medium text-gray-500 dark:text-gray-400 uppercase mb-1">
                            {{ $t("Empresa") }}
                        </label>
                        <select
                            v-model="empresaId"
                            @change="reload"
                            class="block w-full text-sm rounded-md border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-200 focus:ring-indigo-500 focus:border-indigo-500"
                        >
                            <option value="">{{ $t("Consolidado") }}</option>
                            <option v-for="e in empresas" :key="e.id" :value="String(e.id)">
                                {{ e.nombre }}
                            </option>
                        </select>
                    </div>

                    <div>
                        <label class="block text-xs font-medium text-gray-500 dark:text-gray-400 uppercase mb-1">
                            {{ $t("Ventana") }}
                        </label>
                        <select
                            v-model="months"
                            @change="reload"
                            class="block w-full text-sm rounded-md border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-200 focus:ring-indigo-500 focus:border-indigo-500"
                        >
                            <option value="6">{{ $t("Últimos 6 meses") }}</option>
                            <option value="12">{{ $t("Últimos 12 meses") }}</option>
                        </select>
                    </div>

                    <div class="sm:ml-auto">
                        <button
                            @click="startExport"
                            :disabled="exportProcessing"
                            class="inline-flex items-center gap-2 px-4 py-2 bg-indigo-600 hover:bg-indigo-700 disabled:opacity-50 text-white text-sm font-medium rounded-md shadow-sm focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500"
                        >
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 21h10a2 2 0 002-2V9.414a1 1 0 00-.293-.707l-5.414-5.414A1 1 0 0012.586 3H7a2 2 0 00-2 2v14a2 2 0 002 2z" />
                            </svg>
                            {{ exportProcessing ? $t("Generando PDF…") : $t("Exportar PDF") }}
                        </button>
                    </div>
                </div>

                <!-- Tarjetas KPI con sparkline -->
                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6 mb-8">
                    <KpiCard
                        v-for="kpi in kpis"
                        :key="kpi.label"
                        :label="kpi.label"
                        :valor="kpi.value"
                        :margen="kpi.margin"
                        :delta-prev="kpi.deltaPrev"
                        :delta-yoy="kpi.deltaYoY"
                        :sparkline="kpi.sparkline"
                        :color="kpi.color"
                        :border-class="kpi.borderClass"
                    />
                </div>

                <!-- Tendencias -->
                <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-8">
                    <TrendChart :series="series" />
                    <MarginTrendChart :series="series" />
                </div>

                <!-- Composición de egresos -->
                <div class="mb-8">
                    <EgresosComposition :series="series" :categorias="egresosPorCategoria" />
                </div>

                <!-- Ingreso por empresa + Tu Checador -->
                <div class="mb-8">
                    <IngresoEmpresaChart
                        :ingreso-empresa-series="ingresoEmpresaSeries"
                        :tu-checador="tuChecador"
                    />
                </div>

                <!-- Fijos vs variables + Nómina -->
                <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-8">
                    <FijoVariableChart :naturaleza="egresosPorNaturaleza" />
                    <NominaRollup :nomina="nominaRollup" />
                </div>

                <!-- Top proveedores + categorías -->
                <div class="mb-8">
                    <TopProveedores :proveedores="topProveedores" :categorias="egresosPorCategoria" />
                </div>

                <!-- Waterfall + Margen por empresa -->
                <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
                    <PnlWaterfall :pnl="pnl" />

                    <div
                        v-if="porEmpresa.length > 0"
                        class="bg-white dark:bg-gray-800 shadow-sm sm:rounded-lg p-6"
                    >
                        <h3 class="text-lg font-medium text-gray-900 dark:text-white mb-4">
                            {{ $t("Margen por empresa") }}
                        </h3>
                        <div class="space-y-4">
                            <div v-for="e in porEmpresa" :key="e.id" class="flex items-center gap-4">
                                <div class="w-40 text-sm shrink-0 flex items-center gap-2 text-gray-700 dark:text-gray-300">
                                    <span
                                        class="inline-block w-3 h-3 rounded-full shrink-0"
                                        :style="{ background: e.color || '#94A3B8' }"
                                    ></span>
                                    <span class="truncate">{{ e.nombre }}</span>
                                </div>
                                <div class="flex-1 bg-gray-100 dark:bg-gray-700 rounded h-6 overflow-hidden">
                                    <div
                                        class="h-6 rounded flex items-center justify-end pr-2 text-xs text-white font-medium"
                                        :style="{ background: e.color || '#3B82F6', width: empresaBarWidth(e) }"
                                    >
                                        {{ pct(e.pnl.margen_neto) }}
                                    </div>
                                </div>
                                <div class="w-32 text-right text-sm font-semibold text-gray-900 dark:text-white shrink-0">
                                    {{ formatCurrency(e.pnl.ingresos.total) }}
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>

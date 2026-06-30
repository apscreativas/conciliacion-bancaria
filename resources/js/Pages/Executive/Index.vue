<script setup lang="ts">
import AuthenticatedLayout from "@/Layouts/AuthenticatedLayout.vue";
import { Head, router, usePage } from "@inertiajs/vue3";
import { ref, computed, onUnmounted } from "vue";
import { formatCurrency } from "@/utils/format";
import axios from "axios";

interface Pnl {
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

interface EmpresaPnl {
    id: number;
    nombre: string;
    color: string | null;
    pnl: Pnl;
}

const props = defineProps<{
    pnl: Pnl;
    pnlPrev: Pnl;
    pnlYoY: Pnl;
    porEmpresa: EmpresaPnl[];
    tuChecador: EmpresaPnl | null;
    empresas: Array<{ id: number; nombre: string; color: string | null }>;
    filters: {
        granularidad: string;
        empresa_id: number | null;
        month: number;
        year: number;
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
        },
        { preserveState: true, preserveScroll: true, replace: true },
    );
};

// Margen (ratio 0..1) → porcentaje string.
const pct = (ratio: number): string => `${(Number(ratio) * 100).toFixed(1)}%`;

// Delta % entre actual y comparativo (null si el comparativo es 0 → "—").
const delta = (current: number, base: number): number | null => {
    const b = Number(base);
    if (b === 0) return null;
    return (Number(current) - b) / Math.abs(b);
};

interface Kpi {
    label: string;
    value: number;
    margin: number | null;
    deltaPrev: number | null;
    deltaYoY: number | null;
}

const kpis = computed<Kpi[]>(() => [
    {
        label: "Ingresos",
        value: props.pnl.ingresos.total,
        margin: null,
        deltaPrev: delta(props.pnl.ingresos.total, props.pnlPrev.ingresos.total),
        deltaYoY: delta(props.pnl.ingresos.total, props.pnlYoY.ingresos.total),
    },
    {
        label: "Utilidad bruta",
        value: props.pnl.utilidad_bruta,
        margin: props.pnl.margen_bruto,
        deltaPrev: delta(props.pnl.utilidad_bruta, props.pnlPrev.utilidad_bruta),
        deltaYoY: delta(props.pnl.utilidad_bruta, props.pnlYoY.utilidad_bruta),
    },
    {
        label: "EBITDA",
        value: props.pnl.ebitda,
        margin: props.pnl.margen_ebitda,
        deltaPrev: delta(props.pnl.ebitda, props.pnlPrev.ebitda),
        deltaYoY: delta(props.pnl.ebitda, props.pnlYoY.ebitda),
    },
    {
        label: "Utilidad neta",
        value: props.pnl.utilidad_neta,
        margin: props.pnl.margen_neto,
        deltaPrev: delta(props.pnl.utilidad_neta, props.pnlPrev.utilidad_neta),
        deltaYoY: delta(props.pnl.utilidad_neta, props.pnlYoY.utilidad_neta),
    },
]);

const borderColors = [
    "border-indigo-500",
    "border-green-500",
    "border-blue-500",
    "border-emerald-600",
];

// Waterfall del P&L: pasos con tipo (base / resta / subtotal).
interface Step {
    label: string;
    amount: number;
    kind: "base" | "deduct" | "subtotal";
}

const steps = computed<Step[]>(() => {
    const p = props.pnl;
    const arr: Step[] = [
        { label: "Ingresos", amount: p.ingresos.total, kind: "base" },
        { label: "Costo de venta", amount: -p.costo_venta, kind: "deduct" },
        { label: "Utilidad bruta", amount: p.utilidad_bruta, kind: "subtotal" },
        { label: "Gasto operativo", amount: -p.gasto_operativo, kind: "deduct" },
        { label: "EBITDA", amount: p.ebitda, kind: "subtotal" },
        { label: "Debajo de EBITDA", amount: -p.abajo_ebitda, kind: "deduct" },
    ];
    if (Number(p.sin_clasificar) !== 0) {
        arr.push({ label: "Sin clasificar", amount: -p.sin_clasificar, kind: "deduct" });
    }
    arr.push({ label: "Utilidad neta", amount: p.utilidad_neta, kind: "subtotal" });
    return arr;
});

const maxAbs = computed(() => {
    const vals = steps.value.map((s) => Math.abs(s.amount));
    const m = Math.max(...vals, 1);
    return m;
});

const barWidth = (amount: number): string =>
    `${Math.min(100, (Math.abs(amount) / maxAbs.value) * 100)}%`;

// Margen por empresa: ancho de barra normalizado al ingreso máximo entre empresas.
const maxEmpresaIngreso = computed(() => {
    const vals = props.porEmpresa.map((e) => Number(e.pnl.ingresos.total));
    return Math.max(...vals, 1);
});

const empresaBarWidth = (e: EmpresaPnl): string =>
    `${Math.min(100, (Number(e.pnl.ingresos.total) / maxEmpresaIngreso.value) * 100)}%`;

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
            },
        });

        if (response.data.id) {
            pollExport(response.data.id);
        } else {
            exportProcessing.value = false;
        }
    } catch (e) {
        exportProcessing.value = false;
        showToast("Error iniciando exportación. Intente de nuevo.");
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
                    "La exportación falló: " +
                        (res.data.error_message || "Error desconocido"),
                );
            }
        } catch (e) {
            clearActiveInterval();
            exportProcessing.value = false;
            showToast("Error consultando estado de exportación.");
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
                            <option
                                v-for="g in granularidades"
                                :key="g.value"
                                :value="g.value"
                            >
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
                            <option
                                v-for="e in empresas"
                                :key="e.id"
                                :value="String(e.id)"
                            >
                                {{ e.nombre }}
                            </option>
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

                <!-- Tarjetas KPI -->
                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6 mb-8">
                    <div
                        v-for="(kpi, idx) in kpis"
                        :key="kpi.label"
                        class="bg-white dark:bg-gray-800 overflow-hidden shadow-sm sm:rounded-lg p-6 border-l-4"
                        :class="borderColors[idx]"
                    >
                        <div class="text-gray-500 dark:text-gray-400 text-xs font-medium uppercase">
                            {{ $t(kpi.label) }}
                        </div>
                        <div class="mt-2 text-2xl font-bold text-gray-900 dark:text-white">
                            {{ formatCurrency(kpi.value) }}
                        </div>
                        <div v-if="kpi.margin !== null" class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                            {{ $t("Margen") }}: {{ pct(kpi.margin) }}
                        </div>
                        <div class="mt-3 flex flex-col gap-1 text-xs">
                            <span :class="kpi.deltaPrev === null ? 'text-gray-400' : (kpi.deltaPrev >= 0 ? 'text-green-600' : 'text-red-600')">
                                <template v-if="kpi.deltaPrev === null">—</template>
                                <template v-else>{{ kpi.deltaPrev >= 0 ? "↑" : "↓" }} {{ pct(Math.abs(kpi.deltaPrev)) }}</template>
                                <span class="text-gray-400 dark:text-gray-500"> {{ $t("vs periodo anterior") }}</span>
                            </span>
                            <span :class="kpi.deltaYoY === null ? 'text-gray-400' : (kpi.deltaYoY >= 0 ? 'text-green-600' : 'text-red-600')">
                                <template v-if="kpi.deltaYoY === null">—</template>
                                <template v-else>{{ kpi.deltaYoY >= 0 ? "↑" : "↓" }} {{ pct(Math.abs(kpi.deltaYoY)) }}</template>
                                <span class="text-gray-400 dark:text-gray-500"> {{ $t("vs año anterior") }}</span>
                            </span>
                        </div>
                    </div>
                </div>

                <!-- Tarjeta Tu Checador -->
                <div
                    v-if="tuChecador"
                    class="bg-gradient-to-r from-indigo-600 to-purple-600 text-white shadow-sm sm:rounded-lg p-6 mb-8"
                >
                    <div class="flex items-center justify-between">
                        <div>
                            <div class="text-xs font-medium uppercase opacity-80">
                                {{ $t("Ingreso recurrente") }} · {{ tuChecador.nombre }}
                            </div>
                            <div class="mt-2 text-3xl font-bold">
                                {{ formatCurrency(tuChecador.pnl.ingresos.total) }}
                            </div>
                        </div>
                        <div class="text-right">
                            <div class="text-xs uppercase opacity-80">{{ $t("Utilidad neta") }}</div>
                            <div class="text-xl font-bold">{{ formatCurrency(tuChecador.pnl.utilidad_neta) }}</div>
                            <div class="text-xs opacity-80">{{ $t("Margen") }}: {{ pct(tuChecador.pnl.margen_neto) }}</div>
                        </div>
                    </div>
                </div>

                <!-- Waterfall P&L -->
                <div class="bg-white dark:bg-gray-800 shadow-sm sm:rounded-lg p-6 mb-8">
                    <h3 class="text-lg font-medium text-gray-900 dark:text-white mb-4">
                        {{ $t("Estado de Resultados") }}
                    </h3>
                    <div class="space-y-3">
                        <div
                            v-for="step in steps"
                            :key="step.label"
                            class="flex items-center gap-4"
                        >
                            <div class="w-40 text-sm shrink-0"
                                :class="step.kind === 'subtotal' ? 'font-bold text-gray-900 dark:text-white' : 'text-gray-600 dark:text-gray-400'"
                            >
                                {{ $t(step.label) }}
                            </div>
                            <div class="flex-1 bg-gray-100 dark:bg-gray-700 rounded h-6 relative overflow-hidden">
                                <div
                                    class="h-6 rounded"
                                    :class="step.kind === 'deduct' ? 'bg-red-400' : (step.kind === 'subtotal' ? 'bg-indigo-600' : 'bg-green-500')"
                                    :style="{ width: barWidth(step.amount) }"
                                ></div>
                            </div>
                            <div class="w-32 text-right text-sm font-semibold shrink-0"
                                :class="step.amount < 0 ? 'text-red-600' : 'text-gray-900 dark:text-white'"
                            >
                                {{ formatCurrency(step.amount) }}
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Margen por empresa -->
                <div
                    v-if="porEmpresa.length > 0"
                    class="bg-white dark:bg-gray-800 shadow-sm sm:rounded-lg p-6"
                >
                    <h3 class="text-lg font-medium text-gray-900 dark:text-white mb-4">
                        {{ $t("Margen por empresa") }}
                    </h3>
                    <div class="space-y-4">
                        <div
                            v-for="e in porEmpresa"
                            :key="e.id"
                            class="flex items-center gap-4"
                        >
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
    </AuthenticatedLayout>
</template>

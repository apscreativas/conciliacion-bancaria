<script setup lang="ts">
import { computed } from "vue";
import VueApexCharts from "vue3-apexcharts";
import { trans } from "laravel-vue-i18n";
import { formatCurrency } from "@/utils/format";
import type { IngresoEmpresaMonth, EmpresaPnl } from "../types";

const props = defineProps<{
    ingresoEmpresaSeries: IngresoEmpresaMonth[];
    tuChecador: EmpresaPnl | null;
}>();

const SIN_ASIGNAR_COLOR = "#94A3B8"; // slate-400
const FALLBACK = ["#6366F1", "#22C55E", "#F97316", "#0EA5E9", "#EC4899", "#EAB308", "#8B5CF6", "#14B8A6"];

const labels = computed(() => props.ingresoEmpresaSeries.map((m) => m.label));

// Union de empresas (id → nombre/color) preservando primer color visto.
const empresasIndex = computed(() => {
    const map = new Map<number, { nombre: string; color: string | null }>();
    props.ingresoEmpresaSeries.forEach((mes) => {
        mes.empresas.forEach((e) => {
            if (!map.has(e.empresa_id)) {
                map.set(e.empresa_id, { nombre: e.nombre ?? `#${e.empresa_id}`, color: e.color });
            }
        });
    });
    return map;
});

const chartSeries = computed(() => {
    const ids = Array.from(empresasIndex.value.keys());
    const perEmpresa = ids.map((id) => ({
        name: empresasIndex.value.get(id)!.nombre,
        data: props.ingresoEmpresaSeries.map((mes) => {
            const found = mes.empresas.find((e) => e.empresa_id === id);
            return found ? Number(found.total) : 0;
        }),
    }));

    const sinAsignar = {
        name: trans("Sin asignar"),
        data: props.ingresoEmpresaSeries.map((mes) => Number(mes.sin_asignar)),
    };

    return [...perEmpresa, sinAsignar];
});

const colors = computed(() => {
    const ids = Array.from(empresasIndex.value.keys());
    const arr = ids.map((id, i) => empresasIndex.value.get(id)!.color ?? FALLBACK[i % FALLBACK.length]);
    arr.push(SIN_ASIGNAR_COLOR);
    return arr;
});

const options = computed(() => ({
    chart: { type: "bar" as const, height: 320, stacked: true, toolbar: { show: false }, animations: { enabled: false } },
    colors: colors.value,
    plotOptions: { bar: { columnWidth: "60%" } },
    dataLabels: { enabled: false },
    xaxis: { categories: labels.value },
    yaxis: { labels: { formatter: (v: number) => formatCurrency(v) } },
    tooltip: { y: { formatter: (v: number) => formatCurrency(v) } },
    legend: { position: "top" as const },
    grid: { borderColor: "#E5E7EB", strokeDashArray: 4 },
}));
</script>

<template>
    <div class="bg-white dark:bg-gray-800 shadow-sm sm:rounded-lg p-6">
        <div class="flex flex-col sm:flex-row sm:items-start sm:justify-between gap-4 mb-4">
            <h3 class="text-lg font-medium text-gray-900 dark:text-white">
                {{ $t("Ingreso por empresa") }}
            </h3>
            <div
                v-if="tuChecador"
                class="rounded-lg bg-gradient-to-r from-indigo-600 to-purple-600 text-white px-4 py-2 shrink-0"
            >
                <div class="text-[10px] font-medium uppercase opacity-80">
                    {{ $t("Ingreso recurrente") }} · {{ tuChecador.nombre }}
                </div>
                <div class="text-lg font-bold">{{ formatCurrency(tuChecador.pnl.ingresos.total) }}</div>
            </div>
        </div>
        <VueApexCharts type="bar" height="320" :options="options" :series="chartSeries" />
    </div>
</template>

<script setup lang="ts">
import { computed } from "vue";
import VueApexCharts from "vue3-apexcharts";
import { trans } from "laravel-vue-i18n";
import { formatCurrency } from "@/utils/format";
import { CHART_COLORS, type MonthPoint, type CategoriaEgreso } from "../types";

const props = defineProps<{
    series: MonthPoint[];
    categorias: CategoriaEgreso[];
}>();

// ── Barras apiladas por mes: COGS / OPEX / abajo EBITDA / sin clasificar ──
const stackedSeries = computed(() => [
    { name: trans("Costo de venta"), data: props.series.map((m) => m.costo_venta) },
    { name: trans("Gasto operativo"), data: props.series.map((m) => m.gasto_operativo) },
    { name: trans("Debajo de EBITDA"), data: props.series.map((m) => m.abajo_ebitda) },
    { name: trans("Sin clasificar"), data: props.series.map((m) => m.sin_clasificar) },
]);

const stackedOptions = computed(() => ({
    chart: { type: "bar" as const, height: 320, stacked: true, toolbar: { show: false }, animations: { enabled: false } },
    colors: [CHART_COLORS.costoVenta, CHART_COLORS.gastoOperativo, CHART_COLORS.abajoEbitda, CHART_COLORS.sinClasificar],
    plotOptions: { bar: { columnWidth: "60%" } },
    dataLabels: { enabled: false },
    xaxis: { categories: props.series.map((m) => m.label) },
    yaxis: { labels: { formatter: (v: number) => formatCurrency(v) } },
    tooltip: { y: { formatter: (v: number) => formatCurrency(v) } },
    legend: { position: "top" as const },
    grid: { borderColor: "#E5E7EB", strokeDashArray: 4 },
}));

// ── Dona del periodo por categoría: top 8 + "Otros" ──
const TOP = 8;

const donut = computed(() => {
    const cats = props.categorias;
    const top = cats.slice(0, TOP);
    const resto = cats.slice(TOP).reduce((acc, c) => acc + Number(c.total), 0);
    const labels = top.map((c) => c.nombre);
    const values = top.map((c) => Number(c.total));
    if (resto > 0) {
        labels.push(trans("Otros"));
        values.push(resto);
    }
    return { labels, values };
});

const hasDonut = computed(() => donut.value.values.some((v) => v > 0));

const donutOptions = computed(() => ({
    chart: { type: "donut" as const, height: 320, animations: { enabled: false } },
    labels: donut.value.labels,
    legend: { position: "bottom" as const },
    dataLabels: { enabled: true, formatter: (val: number) => `${Number(val).toFixed(1)}%` },
    tooltip: { y: { formatter: (v: number) => formatCurrency(v) } },
}));
</script>

<template>
    <div class="bg-white dark:bg-gray-800 shadow-sm sm:rounded-lg p-6">
        <h3 class="text-lg font-medium text-gray-900 dark:text-white mb-4">
            {{ $t("Composición de egresos") }}
        </h3>
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
            <div>
                <p class="text-xs font-medium text-gray-500 dark:text-gray-400 uppercase mb-2">
                    {{ $t("Por mes") }}
                </p>
                <VueApexCharts type="bar" height="320" :options="stackedOptions" :series="stackedSeries" />
            </div>
            <div>
                <p class="text-xs font-medium text-gray-500 dark:text-gray-400 uppercase mb-2">
                    {{ $t("Por categoría (periodo)") }}
                </p>
                <VueApexCharts
                    v-if="hasDonut"
                    type="donut"
                    height="320"
                    :options="donutOptions"
                    :series="donut.values"
                />
                <p v-else class="text-sm text-gray-400 py-12 text-center">
                    {{ $t("Sin datos en el periodo") }}
                </p>
            </div>
        </div>
    </div>
</template>

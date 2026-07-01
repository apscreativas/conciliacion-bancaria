<script setup lang="ts">
import { computed } from "vue";
import VueApexCharts from "vue3-apexcharts";
import { trans } from "laravel-vue-i18n";
import { formatCurrency } from "@/utils/format";
import { CHART_COLORS, type MonthPoint } from "../types";

const props = defineProps<{ series: MonthPoint[] }>();

const chartSeries = computed(() => [
    { name: trans("Ingresos"), type: "area", data: props.series.map((m) => m.ingresos_total) },
    { name: trans("Egresos"), type: "line", data: props.series.map((m) => m.egresos_total) },
    { name: trans("Utilidad neta"), type: "line", data: props.series.map((m) => m.utilidad_neta) },
]);

const options = computed(() => ({
    chart: { type: "line" as const, height: 320, toolbar: { show: false }, animations: { enabled: false } },
    colors: [CHART_COLORS.ingresos, CHART_COLORS.egresos, CHART_COLORS.utilidad],
    stroke: { curve: "smooth" as const, width: [0, 3, 3] },
    fill: { type: ["gradient", "solid", "solid"], gradient: { opacityFrom: 0.3, opacityTo: 0 } },
    dataLabels: { enabled: false },
    xaxis: { categories: props.series.map((m) => m.label) },
    yaxis: { labels: { formatter: (v: number) => formatCurrency(v) } },
    tooltip: { y: { formatter: (v: number) => formatCurrency(v) } },
    legend: { position: "top" as const },
    grid: { borderColor: "#E5E7EB", strokeDashArray: 4 },
}));
</script>

<template>
    <div class="bg-white dark:bg-gray-800 shadow-sm sm:rounded-lg p-6">
        <h3 class="text-lg font-medium text-gray-900 dark:text-white mb-4">
            {{ $t("Tendencia de ingresos y egresos") }}
        </h3>
        <VueApexCharts type="line" height="320" :options="options" :series="chartSeries" />
    </div>
</template>

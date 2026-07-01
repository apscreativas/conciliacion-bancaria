<script setup lang="ts">
import { computed } from "vue";
import VueApexCharts from "vue3-apexcharts";
import { wTrans } from "laravel-vue-i18n";
import { CHART_COLORS, type MonthPoint } from "../types";

const props = defineProps<{ series: MonthPoint[] }>();

// Márgenes son ratio 0..1 → ×100 para %.
const toPct = (v: number): number => Math.round(Number(v) * 1000) / 10;

const chartSeries = computed(() => [
    { name: wTrans("Margen bruto").value, data: props.series.map((m) => toPct(m.margen_bruto)) },
    { name: wTrans("Margen EBITDA").value, data: props.series.map((m) => toPct(m.margen_ebitda)) },
    { name: wTrans("Margen neto").value, data: props.series.map((m) => toPct(m.margen_neto)) },
]);

const options = computed(() => ({
    chart: { type: "line" as const, height: 320, toolbar: { show: false }, animations: { enabled: false } },
    colors: [CHART_COLORS.margenBruto, CHART_COLORS.margenEbitda, CHART_COLORS.margenNeto],
    stroke: { curve: "smooth" as const, width: 3 },
    dataLabels: { enabled: false },
    xaxis: { categories: props.series.map((m) => m.label) },
    yaxis: { labels: { formatter: (v: number) => `${v.toFixed(1)}%` } },
    tooltip: { y: { formatter: (v: number) => `${v.toFixed(1)}%` } },
    legend: { position: "top" as const },
    grid: { borderColor: "#E5E7EB", strokeDashArray: 4 },
}));
</script>

<template>
    <div class="bg-white dark:bg-gray-800 shadow-sm sm:rounded-lg p-6">
        <h3 class="text-lg font-medium text-gray-900 dark:text-white mb-4">
            {{ $t("Márgenes en el tiempo") }}
        </h3>
        <VueApexCharts type="line" height="320" :options="options" :series="chartSeries" />
    </div>
</template>

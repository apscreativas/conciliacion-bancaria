<script setup lang="ts">
import { computed } from "vue";
import VueApexCharts from "vue3-apexcharts";
import { trans } from "laravel-vue-i18n";
import { formatCurrency } from "@/utils/format";
import { CHART_COLORS, type NaturalezaEgreso } from "../types";

const props = defineProps<{ naturaleza: NaturalezaEgreso }>();

const values = computed(() => [
    Number(props.naturaleza.fijo),
    Number(props.naturaleza.variable),
    Number(props.naturaleza.sin_clasificar),
]);

const hasData = computed(() => values.value.some((v) => v > 0));

const options = computed(() => ({
    chart: { type: "donut" as const, height: 300, animations: { enabled: false } },
    labels: [trans("Fijos"), trans("Variables"), trans("Sin clasificar")],
    colors: [CHART_COLORS.fijo, CHART_COLORS.variable, CHART_COLORS.sinClasificar],
    legend: { position: "bottom" as const },
    dataLabels: { enabled: true, formatter: (val: number) => `${Number(val).toFixed(1)}%` },
    tooltip: { y: { formatter: (v: number) => formatCurrency(v) } },
}));
</script>

<template>
    <div class="bg-white dark:bg-gray-800 shadow-sm sm:rounded-lg p-6">
        <h3 class="text-lg font-medium text-gray-900 dark:text-white mb-4">
            {{ $t("Fijos vs variables") }}
        </h3>
        <VueApexCharts
            v-if="hasData"
            type="donut"
            height="300"
            :options="options"
            :series="values"
        />
        <p v-else class="text-sm text-gray-400 py-12 text-center">
            {{ $t("Sin datos en el periodo") }}
        </p>
    </div>
</template>

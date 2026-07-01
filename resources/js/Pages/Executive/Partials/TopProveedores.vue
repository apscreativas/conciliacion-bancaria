<script setup lang="ts">
import { computed } from "vue";
import VueApexCharts from "vue3-apexcharts";
import { trans } from "laravel-vue-i18n";
import { formatCurrency } from "@/utils/format";
import { CHART_COLORS, type Proveedor, type CategoriaEgreso } from "../types";

const props = defineProps<{
    proveedores: Proveedor[];
    categorias: CategoriaEgreso[];
}>();

const hasProveedores = computed(() => props.proveedores.length > 0);

const chartSeries = computed(() => [
    { name: trans("Gasto"), data: props.proveedores.map((p) => Number(p.total)) },
]);

const options = computed(() => ({
    chart: { type: "bar" as const, height: 320, toolbar: { show: false }, animations: { enabled: false } },
    colors: [CHART_COLORS.egresos],
    plotOptions: { bar: { horizontal: true, barHeight: "60%" } },
    dataLabels: { enabled: false },
    xaxis: {
        categories: props.proveedores.map((p) => p.proveedor),
        labels: { formatter: (v: number) => formatCurrency(v) },
    },
    tooltip: { y: { formatter: (v: number) => formatCurrency(v) } },
    grid: { borderColor: "#E5E7EB", strokeDashArray: 4 },
}));

// Top categorías como lista con barras proporcionales.
const maxCat = computed(() => Math.max(...props.categorias.map((c) => Number(c.total)), 1));
const catWidth = (total: number): string => `${Math.min(100, (Number(total) / maxCat.value) * 100)}%`;
</script>

<template>
    <div class="bg-white dark:bg-gray-800 shadow-sm sm:rounded-lg p-6">
        <h3 class="text-lg font-medium text-gray-900 dark:text-white mb-4">
            {{ $t("Top proveedores") }}
        </h3>
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
            <div>
                <VueApexCharts
                    v-if="hasProveedores"
                    type="bar"
                    height="320"
                    :options="options"
                    :series="chartSeries"
                />
                <p v-else class="text-sm text-gray-400 py-12 text-center">
                    {{ $t("Sin proveedores en el periodo") }}
                </p>
            </div>
            <div>
                <p class="text-xs font-medium text-gray-500 dark:text-gray-400 uppercase mb-3">
                    {{ $t("Top categorías") }}
                </p>
                <div v-if="categorias.length" class="space-y-3">
                    <div
                        v-for="cat in categorias.slice(0, 8)"
                        :key="cat.nombre"
                        class="flex items-center gap-3"
                    >
                        <div class="w-32 text-sm text-gray-600 dark:text-gray-300 truncate shrink-0">
                            {{ cat.nombre }}
                        </div>
                        <div class="flex-1 bg-gray-100 dark:bg-gray-700 rounded h-4 overflow-hidden">
                            <div class="h-4 rounded bg-indigo-500" :style="{ width: catWidth(cat.total) }"></div>
                        </div>
                        <div class="w-28 text-right text-sm font-semibold text-gray-900 dark:text-white shrink-0">
                            {{ formatCurrency(cat.total) }}
                        </div>
                    </div>
                </div>
                <p v-else class="text-sm text-gray-400 py-12 text-center">
                    {{ $t("Sin datos en el periodo") }}
                </p>
            </div>
        </div>
    </div>
</template>

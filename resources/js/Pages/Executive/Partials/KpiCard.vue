<script setup lang="ts">
import { computed } from "vue";
import VueApexCharts from "vue3-apexcharts";
import { formatCurrency } from "@/utils/format";

const props = defineProps<{
    label: string;
    valor: number;
    margen: number | null; // ratio 0..1
    deltaPrev: number | null; // ratio
    deltaYoy: number | null; // ratio
    sparkline: number[];
    color?: string; // color de línea del sparkline + borde
    borderClass?: string;
}>();

// Margen (ratio 0..1) → porcentaje string.
const pct = (ratio: number): string => `${(Number(ratio) * 100).toFixed(1)}%`;

const sparkSeries = computed(() => [{ name: props.label, data: props.sparkline }]);

const sparkOptions = computed(() => ({
    chart: {
        type: "area" as const,
        sparkline: { enabled: true },
        animations: { enabled: false },
    },
    stroke: { curve: "smooth" as const, width: 2 },
    fill: { type: "gradient", gradient: { opacityFrom: 0.35, opacityTo: 0 } },
    colors: [props.color ?? "#6366F1"],
    tooltip: { enabled: false },
}));
</script>

<template>
    <div
        class="bg-white dark:bg-gray-800 overflow-hidden shadow-sm sm:rounded-lg p-6 border-l-4"
        :class="borderClass ?? 'border-indigo-500'"
    >
        <div class="text-gray-500 dark:text-gray-400 text-xs font-medium uppercase">
            {{ $t(label) }}
        </div>
        <div class="mt-2 text-2xl font-bold text-gray-900 dark:text-white">
            {{ formatCurrency(valor) }}
        </div>
        <div v-if="margen !== null" class="mt-1 text-sm text-gray-500 dark:text-gray-400">
            {{ $t("Margen") }}: {{ pct(margen) }}
        </div>

        <div v-if="sparkline.length" class="mt-2 -mx-2">
            <VueApexCharts
                type="area"
                height="48"
                :options="sparkOptions"
                :series="sparkSeries"
            />
        </div>

        <div class="mt-3 flex flex-col gap-1 text-xs">
            <span :class="deltaPrev === null ? 'text-gray-400' : (deltaPrev >= 0 ? 'text-green-600' : 'text-red-600')">
                <template v-if="deltaPrev === null">—</template>
                <template v-else>{{ deltaPrev >= 0 ? "↑" : "↓" }} {{ pct(Math.abs(deltaPrev)) }}</template>
                <span class="text-gray-400 dark:text-gray-500"> {{ $t("vs periodo anterior") }}</span>
            </span>
            <span :class="deltaYoy === null ? 'text-gray-400' : (deltaYoy >= 0 ? 'text-green-600' : 'text-red-600')">
                <template v-if="deltaYoy === null">—</template>
                <template v-else>{{ deltaYoy >= 0 ? "↑" : "↓" }} {{ pct(Math.abs(deltaYoy)) }}</template>
                <span class="text-gray-400 dark:text-gray-500"> {{ $t("vs año anterior") }}</span>
            </span>
        </div>
    </div>
</template>

<script setup lang="ts">
import { formatCurrency } from "@/utils/format";
import type { Nomina } from "../types";

const props = defineProps<{ nomina: Nomina }>();

const pctOf = (part: number): string => {
    const total = Number(props.nomina.total);
    if (total === 0) return "0.0%";
    return `${((Number(part) / total) * 100).toFixed(1)}%`;
};
</script>

<template>
    <div class="bg-white dark:bg-gray-800 shadow-sm sm:rounded-lg p-6">
        <h3 class="text-lg font-medium text-gray-900 dark:text-white mb-4">
            {{ $t("Costo de nómina") }}
        </h3>
        <div class="text-3xl font-bold text-gray-900 dark:text-white">
            {{ formatCurrency(nomina.total) }}
        </div>
        <div class="mt-6 space-y-4">
            <div>
                <div class="flex justify-between text-sm mb-1">
                    <span class="text-gray-600 dark:text-gray-300">{{ $t("Fiscal") }}</span>
                    <span class="font-semibold text-gray-900 dark:text-white">
                        {{ formatCurrency(nomina.fiscal) }} · {{ pctOf(nomina.fiscal) }}
                    </span>
                </div>
                <div class="bg-gray-100 dark:bg-gray-700 rounded h-3 overflow-hidden">
                    <div class="h-3 rounded bg-indigo-500" :style="{ width: pctOf(nomina.fiscal) }"></div>
                </div>
            </div>
            <div>
                <div class="flex justify-between text-sm mb-1">
                    <span class="text-gray-600 dark:text-gray-300">{{ $t("Complemento") }}</span>
                    <span class="font-semibold text-gray-900 dark:text-white">
                        {{ formatCurrency(nomina.complemento) }} · {{ pctOf(nomina.complemento) }}
                    </span>
                </div>
                <div class="bg-gray-100 dark:bg-gray-700 rounded h-3 overflow-hidden">
                    <div class="h-3 rounded bg-orange-500" :style="{ width: pctOf(nomina.complemento) }"></div>
                </div>
            </div>
        </div>
    </div>
</template>

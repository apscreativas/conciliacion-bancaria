<script setup lang="ts">
import { computed } from "vue";
import { formatCurrency } from "@/utils/format";
import type { Pnl } from "../types";

const props = defineProps<{ pnl: Pnl }>();

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

const maxAbs = computed(() => Math.max(...steps.value.map((s) => Math.abs(s.amount)), 1));

const barWidth = (amount: number): string =>
    `${Math.min(100, (Math.abs(amount) / maxAbs.value) * 100)}%`;
</script>

<template>
    <div class="bg-white dark:bg-gray-800 shadow-sm sm:rounded-lg p-6">
        <h3 class="text-lg font-medium text-gray-900 dark:text-white mb-4">
            {{ $t("Estado de Resultados") }}
        </h3>
        <div class="space-y-3">
            <div v-for="step in steps" :key="step.label" class="flex items-center gap-4">
                <div
                    class="w-40 text-sm shrink-0"
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
                <div
                    class="w-32 text-right text-sm font-semibold shrink-0"
                    :class="step.amount < 0 ? 'text-red-600' : 'text-gray-900 dark:text-white'"
                >
                    {{ formatCurrency(step.amount) }}
                </div>
            </div>
        </div>
    </div>
</template>

<script setup lang="ts">
import { router } from "@inertiajs/vue3";

interface Empresa {
    id: number;
    nombre: string;
    color: string | null;
}

const props = defineProps<{
    group: {
        id: string; // Group UUID
        created_at: string;
        user: { name: string };
        empresa?: Empresa | null;
        invoices: Array<any>;
        movements: Array<any>;
        total_invoices: number;
        total_movements: number;
        total_applied: number;
    };
    empresas: Empresa[];
}>();

const emit = defineEmits(["unreconcile"]);

const assignEmpresa = (event: Event) => {
    const value = (event.target as HTMLSelectElement).value;
    router.patch(
        route("reconciliation.group.empresa.update", props.group.id),
        { empresa_id: value === "" ? null : Number(value) },
        { preserveScroll: true },
    );
};

const formatDate = (dateString: string) => {
    if (!dateString) return "";
    const d = new Date(dateString);
    const userTimezoneOffset = d.getTimezoneOffset() * 60000;
    const adjustedDate = new Date(d.getTime() + userTimezoneOffset);
    return adjustedDate.toLocaleDateString("es-ES", {
        year: "numeric",
        month: "long",
        day: "numeric",
    });
};

const formatCurrency = (amount: number) => {
    return new Intl.NumberFormat("es-MX", {
        style: "currency",
        currency: "MXN",
    }).format(amount);
};

const getDifference = (group: any) => {
    return Number(group.total_movements) - Number(group.total_invoices);
};
</script>

<template>
    <div
        class="bg-white dark:bg-gray-800 shadow-sm sm:rounded-lg overflow-hidden border border-gray-200 dark:border-gray-700"
    >
        <!-- Group Header -->
        <div
            class="bg-gray-50 dark:bg-gray-900/50 px-6 py-4 border-b border-gray-200 dark:border-gray-700 flex flex-col md:flex-row justify-between md:items-center gap-4"
        >
            <div>
                <h3
                    class="text-sm font-bold uppercase tracking-wider text-gray-500"
                >
                    {{ $t("CONCILIACIÓN") }}
                </h3>
                <div class="text-sm text-gray-400 mt-1">
                    {{ formatDate(group.created_at) }} • {{ group.user.name }}
                </div>
                <!-- Empresa: badge + selector (Finanzas Fase 1) -->
                <div class="flex items-center gap-2 mt-2">
                    <span
                        v-if="group.empresa"
                        class="text-[10px] font-bold px-2 py-0.5 rounded border inline-block w-fit"
                        :style="{
                            backgroundColor: (group.empresa.color || '#9ca3af') + '15',
                            color: group.empresa.color || '#9ca3af',
                            borderColor: (group.empresa.color || '#9ca3af') + '30',
                        }"
                    >
                        {{ group.empresa.nombre }}
                    </span>
                    <select
                        :value="group.empresa?.id ?? ''"
                        @change="assignEmpresa"
                        :title="$t('Asignar empresa')"
                        class="text-xs border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300 rounded-md shadow-sm focus:border-indigo-500 focus:ring-indigo-500 py-1"
                    >
                        <option value="">{{ $t("Sin asignar") }}</option>
                        <option v-for="e in empresas" :key="e.id" :value="e.id">
                            {{ e.nombre }}
                        </option>
                    </select>
                </div>
            </div>

            <div class="flex gap-8 text-right">
                <div>
                    <div class="text-xs text-gray-500">
                        {{ $t("Total Facturas") }}
                    </div>
                    <div
                        class="text-lg font-bold text-gray-800 dark:text-gray-200"
                    >
                        {{ formatCurrency(group.total_invoices) }}
                    </div>
                </div>
                <div>
                    <div class="text-xs text-gray-500">
                        {{ $t("Total Pagos") }}
                    </div>
                    <div
                        class="text-lg font-bold text-gray-800 dark:text-gray-200"
                    >
                        {{ formatCurrency(group.total_movements) }}
                    </div>
                </div>
                <div>
                    <div class="text-xs text-gray-500">
                        {{ $t("Diferencia") }}
                    </div>
                    <div
                        class="text-xl font-bold"
                        :class="{
                            'text-gray-400':
                                Math.abs(getDifference(group)) < 0.01,
                            'text-green-600 dark:text-green-400':
                                getDifference(group) >= 0.01,
                            'text-red-500 dark:text-red-400':
                                getDifference(group) <= -0.01,
                        }"
                    >
                        {{ formatCurrency(getDifference(group)) }}
                    </div>
                </div>
                <div class="flex items-center">
                    <button
                        @click="emit('unreconcile', group.id)"
                        class="text-xs text-red-500 dark:text-red-400 hover:text-red-700 dark:hover:text-red-300 font-medium underline"
                    >
                        {{ $t("Desvincular Grupo") }}
                    </button>
                </div>
            </div>
        </div>

        <!-- Content Grid: Invoices vs Payments -->
        <div
            class="grid grid-cols-1 md:grid-cols-2 divide-y md:divide-y-0 md:divide-x divide-gray-200 dark:divide-gray-700"
        >
            <!-- Left: Invoices -->
            <div class="p-6 bg-indigo-50/10">
                <h4
                    class="text-xs font-semibold text-indigo-600 dark:text-indigo-400 uppercase tracking-widest mb-4"
                >
                    {{ $t("FACTURAS") }} ({{ group.invoices.length }})
                </h4>
                <div class="space-y-3">
                    <div
                        v-for="invoice in group.invoices"
                        :key="invoice.id"
                        class="flex justify-between items-start text-sm p-3 bg-white dark:bg-gray-800 rounded border border-gray-100 dark:border-gray-700"
                    >
                        <div>
                            <div
                                class="font-medium text-gray-900 dark:text-white"
                            >
                                {{ invoice.nombre || "Factura Eliminada" }}
                            </div>
                            <!-- NEW: RFC Display -->
                            <div class="text-xs text-gray-500 font-mono mt-0.5">
                                {{ invoice.rfc }}
                            </div>
                            <div class="text-xs text-gray-400 mt-1">
                                {{ formatDate(invoice.fecha_emision) }}
                            </div>
                        </div>
                        <div class="font-bold text-gray-700 dark:text-gray-300">
                            {{ formatCurrency(Number(invoice.monto)) }}
                        </div>
                    </div>
                </div>
            </div>

            <!-- Right: Payments -->
            <div class="p-6 bg-green-50/10">
                <h4
                    class="text-xs font-semibold text-green-600 dark:text-green-400 uppercase tracking-widest mb-4"
                >
                    {{ $t("PAGOS") }} ({{ group.movements.length }})
                </h4>
                <div class="space-y-3">
                    <div
                        v-for="movement in group.movements"
                        :key="movement.id"
                        class="flex justify-between items-start text-sm p-3 bg-white dark:bg-gray-800 rounded border border-gray-100 dark:border-gray-700"
                    >
                        <div>
                            <div
                                class="font-medium text-gray-900 dark:text-white"
                            >
                                {{ movement.descripcion || "Sin Descripción" }}
                            </div>
                            <!-- NEW: Bank Format Display -->
                            <div
                                v-if="movement.archivo?.bank_format"
                                class="text-[10px] font-bold px-1.5 py-0.5 rounded border inline-block w-fit mb-1"
                                :style="{
                                    backgroundColor:
                                        movement.archivo.bank_format.color +
                                        '15',
                                    color: movement.archivo.bank_format.color,
                                    borderColor:
                                        movement.archivo.bank_format.color +
                                        '30',
                                }"
                            >
                                {{ movement.archivo.bank_format.name }}
                            </div>
                            <div
                                class="text-xs font-semibold text-indigo-500 mt-0.5"
                                v-else-if="movement.banco"
                            >
                                {{ movement.banco.nombre }}
                            </div>
                            <div class="text-xs text-gray-500 mt-0.5">
                                {{ movement.referencia || "Ref: N/A" }}
                            </div>
                            <div class="text-xs text-gray-400">
                                {{ formatDate(movement.fecha) }}
                            </div>
                        </div>
                        <div class="font-bold text-gray-700 dark:text-gray-300">
                            {{ formatCurrency(Number(movement.monto)) }}
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</template>

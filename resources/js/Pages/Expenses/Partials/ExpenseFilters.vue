<script setup lang="ts">
import PrimaryButton from "@/Components/PrimaryButton.vue";
import SecondaryButton from "@/Components/SecondaryButton.vue";
import { reactive } from "vue";

interface Option {
    id: number;
    nombre: string;
}

const props = defineProps<{
    filters?: {
        date_from?: string;
        date_to?: string;
        amount_min?: string;
        amount_max?: string;
        empresa_id?: string | number;
        categoria_id?: string | number;
    };
    empresas: Option[];
    categorias: Option[];
}>();

const emit = defineEmits(["apply", "clear"]);

const filterForm = reactive({
    date_from: props.filters?.date_from || "",
    date_to: props.filters?.date_to || "",
    amount_min: props.filters?.amount_min || "",
    amount_max: props.filters?.amount_max || "",
    empresa_id: props.filters?.empresa_id || "",
    categoria_id: props.filters?.categoria_id || "",
});

const selectClass =
    "w-full text-sm border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white rounded-md focus:ring-indigo-500 focus:border-indigo-500";

const applyFilters = () => emit("apply", { ...filterForm });

const clearFilters = () => {
    filterForm.date_from = "";
    filterForm.date_to = "";
    filterForm.amount_min = "";
    filterForm.amount_max = "";
    filterForm.empresa_id = "";
    filterForm.categoria_id = "";
    emit("clear");
};
</script>

<template>
    <div class="mb-6 bg-gray-50 dark:bg-gray-700/50 p-4 rounded-lg border border-gray-200 dark:border-gray-700">
        <h3 class="text-xs font-semibold text-gray-400 uppercase tracking-widest mb-3">
            {{ $t("FILTROS DE BÚSQUEDA") }}
        </h3>
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
            <div>
                <label class="block text-xs font-medium text-gray-500 dark:text-gray-400 mb-1">{{ $t("Empresa") }}</label>
                <select v-model="filterForm.empresa_id" :class="selectClass">
                    <option value="">{{ $t("Todas") }}</option>
                    <option v-for="e in empresas" :key="e.id" :value="e.id">{{ e.nombre }}</option>
                </select>
            </div>
            <div>
                <label class="block text-xs font-medium text-gray-500 dark:text-gray-400 mb-1">{{ $t("Categoría") }}</label>
                <select v-model="filterForm.categoria_id" :class="selectClass">
                    <option value="">{{ $t("Todas") }}</option>
                    <option v-for="c in categorias" :key="c.id" :value="c.id">{{ c.nombre }}</option>
                </select>
            </div>
            <div class="grid grid-cols-2 gap-2">
                <div>
                    <label class="block text-xs font-medium text-gray-500 dark:text-gray-400 mb-1">{{ $t("Desde") }}</label>
                    <input type="date" v-model="filterForm.date_from" :class="selectClass" />
                </div>
                <div>
                    <label class="block text-xs font-medium text-gray-500 dark:text-gray-400 mb-1">{{ $t("Hasta") }}</label>
                    <input type="date" v-model="filterForm.date_to" :class="selectClass" />
                </div>
            </div>
            <div>
                <label class="block text-xs font-medium text-gray-500 dark:text-gray-400 mb-1">{{ $t("Monto Mín ($)") }}</label>
                <input type="number" step="0.01" v-model="filterForm.amount_min" placeholder="0.00" :class="selectClass" />
            </div>
            <div>
                <label class="block text-xs font-medium text-gray-500 dark:text-gray-400 mb-1">{{ $t("Monto Máx ($)") }}</label>
                <input type="number" step="0.01" v-model="filterForm.amount_max" placeholder="0.00" :class="selectClass" />
            </div>
        </div>
        <div class="mt-4 flex justify-end space-x-3">
            <SecondaryButton @click="clearFilters">{{ $t("LIMPIAR") }}</SecondaryButton>
            <PrimaryButton @click="applyFilters">{{ $t("APLICAR FILTROS") }}</PrimaryButton>
        </div>
    </div>
</template>

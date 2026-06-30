<script setup lang="ts">
import AuthenticatedLayout from "@/Layouts/AuthenticatedLayout.vue";
import { Head, Link, router } from "@inertiajs/vue3";
import Modal from "@/Components/Modal.vue";
import PrimaryButton from "@/Components/PrimaryButton.vue";
import SecondaryButton from "@/Components/SecondaryButton.vue";
import DangerButton from "@/Components/DangerButton.vue";
import EmptyState from "@/Components/EmptyState.vue";
import { formatCurrency, formatDate } from "@/utils/format";
import { trans } from "laravel-vue-i18n";
import { ref } from "vue";

interface Option {
    id: number;
    nombre: string;
    color?: string | null;
}

interface Plantilla {
    id: number;
    descripcion: string;
    proveedor: string | null;
    monto: number;
    frecuencia: string;
    proxima_generacion: string;
    vigencia_tipo: string;
    fecha_fin: string | null;
    num_pagos: number | null;
    pagos_generados: number;
    activo: boolean;
    empresa: Option | null;
    categoria: Option | null;
}

defineProps<{
    plantillas: { data: Plantilla[]; links: Array<any> };
    empresas: Option[];
    categorias: Option[];
}>();

const frecuenciaLabels: Record<string, string> = {
    quincenal: "Quincenal",
    mensual: "Mensual",
    bimestral: "Bimestral",
    trimestral: "Trimestral",
    anual: "Anual",
};

const vigencia = (p: Plantilla) => {
    if (p.vigencia_tipo === "hasta_fecha" && p.fecha_fin) return trans("Hasta :fecha", { fecha: formatDate(p.fecha_fin) });
    if (p.vigencia_tipo === "num_pagos") return `${p.pagos_generados}/${p.num_pagos} ${trans("pagos")}`;
    return trans("Indefinida");
};

function paginationLabel(html: string): string {
    return html.replace(/&laquo;/g, "«").replace(/&raquo;/g, "»").replace(/<[^>]*>/g, "");
}

const confirmingDeletion = ref(false);
const toDelete = ref<Plantilla | null>(null);
const confirmDeletion = (p: Plantilla) => {
    toDelete.value = p;
    confirmingDeletion.value = true;
};
const closeModal = () => {
    confirmingDeletion.value = false;
    toDelete.value = null;
};
const destroy = () => {
    if (!toDelete.value) return;
    router.delete(route("recurring-expenses.destroy", toDelete.value.id), {
        preserveScroll: true,
        onSuccess: () => closeModal(),
    });
};
</script>

<template>
    <Head :title="$t('Gastos recurrentes')" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex justify-between items-center">
                <h2 class="font-semibold text-xl text-gray-800 dark:text-gray-200 leading-tight">{{ $t("Gastos recurrentes") }}</h2>
                <div class="flex items-center gap-3">
                    <Link :href="route('expenses.index')" class="text-sm text-gray-500 hover:text-gray-700 dark:text-gray-400">← {{ $t("Egresos") }}</Link>
                    <Link :href="route('recurring-expenses.create')">
                        <PrimaryButton>{{ $t("Nueva plantilla") }}</PrimaryButton>
                    </Link>
                </div>
            </div>
        </template>

        <div class="py-12">
            <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
                <div class="mb-6">
                    <p class="text-gray-500 dark:text-gray-400 text-sm">
                        {{ $t("Plantillas que generan egresos automáticamente cada periodo (servidores, suscripciones, gastos fijos).") }}
                    </p>
                </div>

                <div class="bg-white dark:bg-gray-800 overflow-hidden shadow-sm sm:rounded-lg">
                    <EmptyState
                        v-if="plantillas.data.length === 0"
                        :title="$t('No hay plantillas recurrentes.')"
                        :description="$t('Crea una plantilla para que un gasto fijo se registre solo cada periodo.')"
                    />
                    <table v-else class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                        <thead class="bg-gray-50 dark:bg-gray-900/50">
                            <tr>
                                <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase">{{ $t("Descripción") }}</th>
                                <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase">{{ $t("Categoría") }}</th>
                                <th class="px-4 py-3 text-right text-xs font-semibold text-gray-500 uppercase">{{ $t("Monto") }}</th>
                                <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase">{{ $t("Frecuencia") }}</th>
                                <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase">{{ $t("Próxima") }}</th>
                                <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase">{{ $t("Vigencia") }}</th>
                                <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase">{{ $t("Estado") }}</th>
                                <th class="px-4 py-3 text-right text-xs font-semibold text-gray-500 uppercase">{{ $t("Acciones") }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-200 dark:divide-gray-700">
                            <tr v-for="p in plantillas.data" :key="p.id" :class="{ 'opacity-50': !p.activo }">
                                <td class="px-4 py-3 text-sm text-gray-900 dark:text-gray-100">
                                    <div class="font-medium">{{ p.descripcion }}</div>
                                    <div v-if="p.empresa" class="text-xs" :style="{ color: p.empresa.color || '#9ca3af' }">{{ p.empresa.nombre }}</div>
                                </td>
                                <td class="px-4 py-3 whitespace-nowrap text-sm text-gray-600 dark:text-gray-400">{{ p.categoria?.nombre ?? "—" }}</td>
                                <td class="px-4 py-3 whitespace-nowrap text-right text-sm font-bold text-gray-800 dark:text-gray-200">{{ formatCurrency(p.monto) }}</td>
                                <td class="px-4 py-3 whitespace-nowrap text-sm text-gray-600 dark:text-gray-400">{{ $t(frecuenciaLabels[p.frecuencia] ?? p.frecuencia) }}</td>
                                <td class="px-4 py-3 whitespace-nowrap text-sm text-gray-600 dark:text-gray-400">{{ formatDate(p.proxima_generacion) }}</td>
                                <td class="px-4 py-3 whitespace-nowrap text-sm text-gray-600 dark:text-gray-400">{{ vigencia(p) }}</td>
                                <td class="px-4 py-3 whitespace-nowrap">
                                    <span
                                        class="inline-flex px-2 text-xs font-semibold rounded-full"
                                        :class="p.activo ? 'bg-green-100 text-green-800 dark:bg-green-900/40 dark:text-green-300' : 'bg-gray-100 text-gray-600 dark:bg-gray-700 dark:text-gray-400'"
                                    >{{ p.activo ? $t("Activo") : $t("Inactivo") }}</span>
                                </td>
                                <td class="px-4 py-3 whitespace-nowrap text-right text-sm">
                                    <Link :href="route('recurring-expenses.edit', p.id)" class="text-indigo-600 hover:text-indigo-900 dark:text-indigo-400">{{ $t("Editar") }}</Link>
                                    <button @click="confirmDeletion(p)" class="ml-4 text-red-600 hover:text-red-900 dark:text-red-400">{{ $t("Eliminar") }}</button>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <!-- Paginación -->
                <div v-if="plantillas.links.length > 3" class="mt-6 flex flex-wrap justify-center -space-x-px">
                    <template v-for="(link, key) in plantillas.links" :key="key">
                        <div v-if="link.url === null" class="px-4 py-2 text-sm text-gray-400 border dark:border-gray-700" v-text="paginationLabel(link.label)" />
                        <Link
                            v-else
                            :href="link.url"
                            preserve-scroll
                            class="px-4 py-2 text-sm border dark:border-gray-700"
                            :class="link.active ? 'bg-indigo-600 text-white border-indigo-600' : 'bg-white dark:bg-gray-800 text-gray-600 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-gray-700'"
                            v-text="paginationLabel(link.label)"
                        />
                    </template>
                </div>
            </div>
        </div>

        <Modal :show="confirmingDeletion" @close="closeModal">
            <div class="p-6">
                <h2 class="text-lg font-medium text-gray-900 dark:text-gray-100">{{ $t("¿Eliminar esta plantilla?") }}</h2>
                <p class="mt-1 text-sm text-gray-600 dark:text-gray-400">{{ $t("Los egresos ya generados se conservan. Esta acción es irreversible.") }}</p>
                <div class="mt-6 flex justify-end">
                    <SecondaryButton @click="closeModal">{{ $t("Cancelar") }}</SecondaryButton>
                    <DangerButton class="ml-3" @click="destroy">{{ $t("Eliminar") }}</DangerButton>
                </div>
            </div>
        </Modal>
    </AuthenticatedLayout>
</template>

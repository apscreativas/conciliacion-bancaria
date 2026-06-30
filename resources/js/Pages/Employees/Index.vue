<script setup lang="ts">
import AuthenticatedLayout from "@/Layouts/AuthenticatedLayout.vue";
import { Head, Link, router } from "@inertiajs/vue3";
import Modal from "@/Components/Modal.vue";
import PrimaryButton from "@/Components/PrimaryButton.vue";
import SecondaryButton from "@/Components/SecondaryButton.vue";
import DangerButton from "@/Components/DangerButton.vue";
import EmptyState from "@/Components/EmptyState.vue";
import { formatCurrency, formatDate } from "@/utils/format";
import { ref } from "vue";

interface Option {
    id: number;
    nombre: string;
    color?: string | null;
}

interface Empleado {
    id: number;
    nombre: string;
    puesto: string | null;
    fecha_entrada: string;
    fecha_baja: string | null;
    salario_fiscal: number;
    salario_real: number;
    clasificacion: string | null;
    activo: boolean;
    empresa: Option | null;
}

defineProps<{
    empleados: { data: Empleado[]; links: Array<any> };
    empresas: Option[];
}>();

function paginationLabel(html: string): string {
    return html.replace(/&laquo;/g, "«").replace(/&raquo;/g, "»").replace(/<[^>]*>/g, "");
}

const confirmingDeletion = ref(false);
const toDelete = ref<Empleado | null>(null);
const confirmDeletion = (e: Empleado) => {
    toDelete.value = e;
    confirmingDeletion.value = true;
};
const closeModal = () => {
    confirmingDeletion.value = false;
    toDelete.value = null;
};
const destroy = () => {
    if (!toDelete.value) return;
    router.delete(route("employees.destroy", toDelete.value.id), {
        preserveScroll: true,
        onSuccess: () => closeModal(),
    });
};
</script>

<template>
    <Head :title="$t('Empleados')" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex justify-between items-center">
                <h2 class="font-semibold text-xl text-gray-800 dark:text-gray-200 leading-tight">{{ $t("Empleados") }}</h2>
                <Link :href="route('employees.create')">
                    <PrimaryButton>{{ $t("Nuevo empleado") }}</PrimaryButton>
                </Link>
            </div>
        </template>

        <div class="py-12">
            <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
                <div class="mb-6">
                    <p class="text-gray-500 dark:text-gray-400 text-sm">
                        {{ $t("La nómina (fiscal + complemento) se genera sola cada quincena a partir de los empleados activos.") }}
                    </p>
                </div>

                <div class="bg-white dark:bg-gray-800 overflow-hidden shadow-sm sm:rounded-lg">
                    <EmptyState
                        v-if="empleados.data.length === 0"
                        :title="$t('No hay empleados registrados.')"
                        :description="$t('Registra empleados para que su nómina se genere cada quincena.')"
                    />
                    <table v-else class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                        <thead class="bg-gray-50 dark:bg-gray-900/50">
                            <tr>
                                <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase">{{ $t("Nombre") }}</th>
                                <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase">{{ $t("Empresa") }}</th>
                                <th class="px-4 py-3 text-right text-xs font-semibold text-gray-500 uppercase">{{ $t("Salario fiscal") }}</th>
                                <th class="px-4 py-3 text-right text-xs font-semibold text-gray-500 uppercase">{{ $t("Salario real") }}</th>
                                <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase">{{ $t("Estado") }}</th>
                                <th class="px-4 py-3 text-right text-xs font-semibold text-gray-500 uppercase">{{ $t("Acciones") }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-200 dark:divide-gray-700">
                            <tr v-for="e in empleados.data" :key="e.id" :class="{ 'opacity-50': !e.activo }">
                                <td class="px-4 py-3 text-sm text-gray-900 dark:text-gray-100">
                                    <div class="font-medium">{{ e.nombre }}</div>
                                    <div v-if="e.puesto" class="text-xs text-gray-400">{{ e.puesto }}</div>
                                </td>
                                <td class="px-4 py-3 whitespace-nowrap text-sm text-gray-600 dark:text-gray-400">
                                    <span v-if="e.empresa" class="text-xs" :style="{ color: e.empresa.color || '#9ca3af' }">{{ e.empresa.nombre }}</span>
                                    <span v-else class="text-xs text-gray-400">{{ $t("Sin asignar") }}</span>
                                </td>
                                <td class="px-4 py-3 whitespace-nowrap text-right text-sm text-gray-600 dark:text-gray-400">{{ formatCurrency(e.salario_fiscal) }}</td>
                                <td class="px-4 py-3 whitespace-nowrap text-right text-sm font-bold text-gray-800 dark:text-gray-200">{{ formatCurrency(e.salario_real) }}</td>
                                <td class="px-4 py-3 whitespace-nowrap">
                                    <span
                                        class="inline-flex px-2 text-xs font-semibold rounded-full"
                                        :class="e.activo ? 'bg-green-100 text-green-800 dark:bg-green-900/40 dark:text-green-300' : 'bg-gray-100 text-gray-600 dark:bg-gray-700 dark:text-gray-400'"
                                    >{{ e.activo ? $t("Activo") : $t("Inactivo") }}</span>
                                </td>
                                <td class="px-4 py-3 whitespace-nowrap text-right text-sm">
                                    <Link :href="route('employees.edit', e.id)" class="text-indigo-600 hover:text-indigo-900 dark:text-indigo-400">{{ $t("Editar") }}</Link>
                                    <button @click="confirmDeletion(e)" class="ml-4 text-red-600 hover:text-red-900 dark:text-red-400">{{ $t("Eliminar") }}</button>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <div v-if="empleados.links.length > 3" class="mt-6 flex flex-wrap justify-center -space-x-px">
                    <template v-for="(link, key) in empleados.links" :key="key">
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
                <h2 class="text-lg font-medium text-gray-900 dark:text-gray-100">{{ $t("¿Eliminar este empleado?") }}</h2>
                <p class="mt-1 text-sm text-gray-600 dark:text-gray-400">{{ $t("La nómina ya generada se conserva. Esta acción es irreversible.") }}</p>
                <div class="mt-6 flex justify-end">
                    <SecondaryButton @click="closeModal">{{ $t("Cancelar") }}</SecondaryButton>
                    <DangerButton class="ml-3" @click="destroy">{{ $t("Eliminar") }}</DangerButton>
                </div>
            </div>
        </Modal>
    </AuthenticatedLayout>
</template>

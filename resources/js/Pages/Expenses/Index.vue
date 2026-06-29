<script setup lang="ts">
import AuthenticatedLayout from "@/Layouts/AuthenticatedLayout.vue";
import { Head, Link, router } from "@inertiajs/vue3";
import Modal from "@/Components/Modal.vue";
import PrimaryButton from "@/Components/PrimaryButton.vue";
import SecondaryButton from "@/Components/SecondaryButton.vue";
import DangerButton from "@/Components/DangerButton.vue";
import EmptyState from "@/Components/EmptyState.vue";
import ExpenseFilters from "./Partials/ExpenseFilters.vue";
import { ref } from "vue";

interface Option {
    id: number;
    nombre: string;
    color?: string | null;
}

interface Egreso {
    id: number;
    fecha: string;
    monto: number;
    descripcion: string;
    proveedor: string | null;
    metodo_pago: string | null;
    empresa: Option | null;
    categoria: Option | null;
}

const props = defineProps<{
    egresos: { data: Egreso[]; links: Array<any> };
    empresas: Option[];
    categorias: Option[];
    total: number;
    totalsByCategoria: Array<{ nombre: string; total: number }>;
    filters?: Record<string, any>;
}>();

const formatCurrency = (amount: number) =>
    new Intl.NumberFormat("es-MX", { style: "currency", currency: "MXN" }).format(Number(amount));

// `fecha` llega como ISO (cast date → 'YYYY-MM-DDThh:mm:ss...Z'); tomar solo la parte de fecha
// y parsear como medianoche local evita el desfase de zona horaria y "Invalid Date".
const formatDate = (d: string) =>
    new Intl.DateTimeFormat("es-MX", { dateStyle: "medium" }).format(new Date(d.slice(0, 10) + "T00:00:00"));

function paginationLabel(html: string): string {
    return html.replace(/&laquo;/g, "«").replace(/&raquo;/g, "»").replace(/<[^>]*>/g, "");
}

const applyFilters = (f: any) => {
    router.get(
        route("expenses.index"),
        {
            ...f,
            month: props.filters?.month,
            year: props.filters?.year,
            per_page: props.filters?.per_page,
        },
        { preserveState: true, replace: true, preserveScroll: true },
    );
};

const clearFilters = () => router.get(route("expenses.index"), { month: props.filters?.month, year: props.filters?.year });

const confirmingDeletion = ref(false);
const toDelete = ref<Egreso | null>(null);
const confirmDeletion = (e: Egreso) => {
    toDelete.value = e;
    confirmingDeletion.value = true;
};
const closeModal = () => {
    confirmingDeletion.value = false;
    toDelete.value = null;
};
const destroy = () => {
    if (!toDelete.value) return;
    router.delete(route("expenses.destroy", toDelete.value.id), {
        preserveScroll: true,
        onSuccess: () => closeModal(),
    });
};
</script>

<template>
    <Head :title="$t('Egresos')" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex justify-between items-center">
                <h2 class="font-semibold text-xl text-gray-800 dark:text-gray-200 leading-tight">{{ $t("Egresos") }}</h2>
                <div class="flex items-center gap-3">
                    <Link :href="route('recurring-expenses.index')">
                        <SecondaryButton>{{ $t("Gastos recurrentes") }}</SecondaryButton>
                    </Link>
                    <Link :href="route('expenses.create')">
                        <PrimaryButton>{{ $t("Nuevo Egreso") }}</PrimaryButton>
                    </Link>
                </div>
            </div>
        </template>

        <div class="py-12">
            <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
                <ExpenseFilters :filters="filters" :empresas="empresas" :categorias="categorias" @apply="applyFilters" @clear="clearFilters" />

                <!-- Totales -->
                <div class="mb-6 bg-white dark:bg-gray-800 shadow-sm sm:rounded-lg p-6 border-l-4 border-red-500">
                    <div class="flex flex-wrap items-baseline justify-between gap-4">
                        <div>
                            <div class="text-gray-500 dark:text-gray-400 text-sm font-medium uppercase">{{ $t("Total del periodo") }}</div>
                            <div class="text-3xl font-bold text-gray-900 dark:text-white">{{ formatCurrency(total) }}</div>
                        </div>
                        <div v-if="totalsByCategoria.length" class="flex flex-wrap gap-x-6 gap-y-1 text-sm">
                            <div v-for="row in totalsByCategoria" :key="row.nombre" class="text-gray-600 dark:text-gray-300">
                                <span class="text-gray-400">{{ row.nombre }}:</span> {{ formatCurrency(row.total) }}
                            </div>
                        </div>
                    </div>
                </div>

                <div class="bg-white dark:bg-gray-800 overflow-hidden shadow-sm sm:rounded-lg">
                    <EmptyState
                        v-if="egresos.data.length === 0"
                        :title="$t('No hay egresos en este periodo.')"
                        :description="$t('Registra tu primer gasto o ajusta los filtros.')"
                    />
                    <table v-else class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                        <thead class="bg-gray-50 dark:bg-gray-900/50">
                            <tr>
                                <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase">{{ $t("Fecha") }}</th>
                                <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase">{{ $t("Descripción") }}</th>
                                <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase">{{ $t("Empresa") }}</th>
                                <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase">{{ $t("Categoría") }}</th>
                                <th class="px-4 py-3 text-right text-xs font-semibold text-gray-500 uppercase">{{ $t("Monto") }}</th>
                                <th class="px-4 py-3 text-right text-xs font-semibold text-gray-500 uppercase">{{ $t("Acciones") }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-200 dark:divide-gray-700">
                            <tr v-for="e in egresos.data" :key="e.id">
                                <td class="px-4 py-3 whitespace-nowrap text-sm text-gray-700 dark:text-gray-300">{{ formatDate(e.fecha) }}</td>
                                <td class="px-4 py-3 text-sm text-gray-900 dark:text-gray-100">
                                    <div class="font-medium">{{ e.descripcion }}</div>
                                    <div v-if="e.proveedor" class="text-xs text-gray-400">{{ e.proveedor }}</div>
                                </td>
                                <td class="px-4 py-3 whitespace-nowrap">
                                    <span
                                        v-if="e.empresa"
                                        class="text-[10px] font-bold px-2 py-0.5 rounded border inline-block"
                                        :style="{ backgroundColor: (e.empresa.color || '#9ca3af') + '15', color: e.empresa.color || '#9ca3af', borderColor: (e.empresa.color || '#9ca3af') + '30' }"
                                    >{{ e.empresa.nombre }}</span>
                                    <span v-else class="text-xs text-gray-400">{{ $t("Sin asignar") }}</span>
                                </td>
                                <td class="px-4 py-3 whitespace-nowrap text-sm text-gray-600 dark:text-gray-400">{{ e.categoria?.nombre ?? "—" }}</td>
                                <td class="px-4 py-3 whitespace-nowrap text-right text-sm font-bold text-gray-800 dark:text-gray-200">{{ formatCurrency(e.monto) }}</td>
                                <td class="px-4 py-3 whitespace-nowrap text-right text-sm">
                                    <Link :href="route('expenses.edit', e.id)" class="text-indigo-600 hover:text-indigo-900 dark:text-indigo-400">{{ $t("Editar") }}</Link>
                                    <button @click="confirmDeletion(e)" class="ml-4 text-red-600 hover:text-red-900 dark:text-red-400">{{ $t("Eliminar") }}</button>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <!-- Paginación -->
                <div v-if="egresos.links.length > 3" class="mt-6 flex flex-wrap justify-center -space-x-px">
                    <template v-for="(link, key) in egresos.links" :key="key">
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
                <h2 class="text-lg font-medium text-gray-900 dark:text-gray-100">{{ $t("¿Eliminar este egreso?") }}</h2>
                <p class="mt-1 text-sm text-gray-600 dark:text-gray-400">{{ $t("Esta acción es irreversible.") }}</p>
                <div class="mt-6 flex justify-end">
                    <SecondaryButton @click="closeModal">{{ $t("Cancelar") }}</SecondaryButton>
                    <DangerButton class="ml-3" @click="destroy">{{ $t("Eliminar") }}</DangerButton>
                </div>
            </div>
        </Modal>
    </AuthenticatedLayout>
</template>

<script setup lang="ts">
import AuthenticatedLayout from "@/Layouts/AuthenticatedLayout.vue";
import { Head, Link, router, useForm } from "@inertiajs/vue3";
import { ref, watch, reactive, computed } from "vue";
import debounce from "lodash/debounce";
import ConfirmationModal from "@/Components/ConfirmationModal.vue";
import PrimaryButton from "@/Components/PrimaryButton.vue";
import SecondaryButton from "@/Components/SecondaryButton.vue";
import HistoryFilters from "./Partials/HistoryFilters.vue";
import ReconciliationGroupCard from "./Partials/ReconciliationGroupCard.vue";

const props = defineProps<{
    reconciledGroups: {
        data: Array<{
            id: string; // Group UUID
            created_at: string;
            user: { name: string };
            invoices: Array<{
                id: number;
                uuid: string;
                nombre: string;
                rfc: string;
                monto: number;
                fecha_emision: string;
            }>;
            movements: Array<{
                id: number;
                descripcion: string;
                referencia: string;
                fecha: string;
                monto: number;
                banco?: { nombre: string } | null;
            }>;
            total_invoices: number;
            total_movements: number;
            total_applied: number;
        }>;
        links: Array<any>;
    };
    filters?: {
        search?: string;
        month?: string;
        year?: string;
        date_from?: string;
        date_to?: string;
        amount_min?: string;
        amount_max?: string;
        per_page?: string | number;
    };
}>();

const search = ref(props.filters?.search || "");
const perPage = ref(props.filters?.per_page || 10);

const applyFilters = (filters: any) => {
    router.get(
        route("reconciliation.history"),
        {
            search: search.value,
            date_from: filters.date_from,
            date_to: filters.date_to,
            amount_min: filters.amount_min,
            amount_max: filters.amount_max,
            month: props.filters?.month,
            year: props.filters?.year,
            per_page: perPage.value,
        },
        {
            preserveState: true,
            replace: true,
        },
    );
};

watch(
    search,
    debounce((value: string) => {
        applyFilters({
            date_from: props.filters?.date_from,
            date_to: props.filters?.date_to,
            amount_min: props.filters?.amount_min,
            amount_max: props.filters?.amount_max,
        });
    }, 500),
);

const confirmingUnreconcile = ref(false);
const groupIdToUnlink = ref<string | null>(null);
const form = useForm({});

// Computed to safely retrieve the group being unlinked, avoiding repeated .find() in the template
const groupToUnlink = computed(() => {
    if (!groupIdToUnlink.value) return null;
    return props.reconciledGroups.data.find((g) => g.id === groupIdToUnlink.value) ?? null;
});

const confirmUnreconcile = (groupId: string) => {
    groupIdToUnlink.value = groupId;
    confirmingUnreconcile.value = true;
};

const unreconcile = () => {
    if (!groupIdToUnlink.value) return;

    form.delete(route("reconciliation.group.destroy", groupIdToUnlink.value), {
        preserveScroll: true,
        onSuccess: () => closeModal(),
        onError: () => (groupIdToUnlink.value = null),
        onFinish: () => form.reset(),
    });
};

const closeModal = () => {
    confirmingUnreconcile.value = false;
    groupIdToUnlink.value = null;
    form.reset();
};
</script>

<template>
    <Head title="Historial de Conciliaciones" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex justify-between items-center">
                <h2
                    class="font-semibold text-xl text-gray-800 dark:text-gray-200 leading-tight"
                >
                    {{ $t("Historial de Conciliaciones") }}
                </h2>
            </div>
        </template>

        <div class="py-12">
            <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
                <!-- Filters Section -->
                <HistoryFilters :filters="filters" @apply="applyFilters" />

                <!-- Search Bar -->
                <div class="flex justify-between items-center mb-6">
                    <div class="relative w-full max-w-md">
                        <div
                            class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none"
                        >
                            <svg
                                class="h-5 w-5 text-gray-400"
                                xmlns="http://www.w3.org/2000/svg"
                                viewBox="0 0 20 20"
                                fill="currentColor"
                            >
                                <path
                                    fill-rule="evenodd"
                                    d="M8 4a4 4 0 100 8 4 4 0 000-8zM2 8a6 6 0 1110.89 3.476l4.817 4.817a1 1 0 01-1.414 1.414l-4.816-4.816A6 6 0 012 8z"
                                    clip-rule="evenodd"
                                />
                            </svg>
                        </div>
                        <input
                            v-model="search"
                            type="text"
                            class="block w-full pl-10 pr-3 py-2 border border-gray-300 dark:border-gray-600 rounded-md leading-5 bg-white dark:bg-gray-700 text-gray-900 dark:text-white placeholder-gray-500 dark:placeholder-gray-400 focus:outline-none focus:placeholder-gray-400 dark:focus:placeholder-gray-300 focus:ring-1 focus:ring-indigo-500 focus:border-indigo-500 sm:text-sm"
                            :placeholder="
                                $t('Buscar por factura, RFC o monto...')
                            "
                        />
                    </div>
                </div>

                <!-- Empty State -->
                <div
                    v-if="reconciledGroups.data.length === 0"
                    class="bg-white dark:bg-gray-800 overflow-hidden shadow-sm sm:rounded-lg p-6 text-center text-gray-500 dark:text-gray-400"
                >
                    No hay conciliaciones registradas (ajusta los filtros si es
                    necesario).
                </div>

                <!-- Top Pagination REMOVED -->

                <!-- Groups List -->
                <div v-if="reconciledGroups.data.length > 0" class="space-y-8">
                    <ReconciliationGroupCard
                        v-for="group in reconciledGroups.data"
                        :key="group.id"
                        :group="group"
                        @unreconcile="confirmUnreconcile"
                    />
                </div>

                <!-- Bottom Pagination & Controls -->
                <div
                    class="mt-6 flex flex-col sm:flex-row justify-between items-center"
                    v-if="reconciledGroups.data.length > 0"
                >
                    <!-- Per Page Selector -->
                    <div class="mb-4 sm:mb-0">
                        <select
                            v-model="perPage"
                            @change="
                                applyFilters({
                                    date_from: filters?.date_from,
                                    date_to: filters?.date_to,
                                    amount_min: filters?.amount_min,
                                    amount_max: filters?.amount_max,
                                })
                            "
                            class="border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300 focus:border-indigo-500 dark:focus:border-indigo-600 focus:ring-indigo-500 dark:focus:ring-indigo-600 rounded-md shadow-sm text-sm"
                        >
                            <option :value="10">10 por pág</option>
                            <option :value="25">25 por pág</option>
                            <option :value="50">50 por pág</option>
                            <option value="all">Todos</option>
                        </select>
                    </div>

                    <!-- Pagination Links -->
                    <div
                        class="flex justify-center -space-x-px rounded-md shadow-sm flex-wrap"
                        v-if="reconciledGroups.links.length > 3"
                    >
                        <template
                            v-for="(link, key) in reconciledGroups.links"
                            :key="key"
                        >
                            <div
                                v-if="link.url === null"
                                class="relative inline-flex items-center px-4 py-2 text-sm font-medium text-gray-500 bg-white border border-gray-300 cursor-default leading-5 dark:bg-gray-800 dark:border-gray-600 dark:text-gray-400"
                                :class="{
                                    'rounded-l-md': key === 0,
                                    'rounded-r-md':
                                        key ===
                                        reconciledGroups.links.length - 1,
                                }"
                                v-html="link.label"
                            />
                            <Link
                                v-else
                                :href="link.url"
                                class="relative inline-flex items-center px-4 py-2 text-sm font-medium border leading-5 transition focus:z-10 focus:outline-none focus:ring ring-gray-300 dark:ring-gray-700 active:bg-gray-100 dark:active:bg-gray-700"
                                :class="{
                                    'z-10 bg-indigo-50 border-indigo-500 text-indigo-600 dark:bg-indigo-900 dark:border-indigo-500 dark:text-indigo-300':
                                        link.active,
                                    'bg-white border-gray-300 text-gray-500 hover:text-gray-700 hover:bg-gray-50 dark:bg-gray-800 dark:border-gray-600 dark:text-gray-300 dark:hover:text-white dark:hover:bg-gray-700':
                                        !link.active,
                                    'rounded-l-md': key === 0,
                                    'rounded-r-md':
                                        key ===
                                        reconciledGroups.links.length - 1,
                                }"
                                v-html="link.label"
                            />
                        </template>
                    </div>
                </div>
            </div>
        </div>

        <ConfirmationModal :show="confirmingUnreconcile" @close="closeModal">
            <template #title> Desvincular Grupo de Conciliación </template>

            <template #content>
                ¿Estás seguro de que deseas desvincular este grupo completo? Se
                eliminarán las relaciones entre
                {{ groupToUnlink?.invoices.length ?? 0 }}
                facturas y
                {{ groupToUnlink?.movements.length ?? 0 }}
                pagos. El saldo volverá a estar pendiente.
            </template>

            <template #footer>
                <SecondaryButton @click="closeModal">
                    Cancelar
                </SecondaryButton>

                <PrimaryButton
                    class="ml-3 bg-red-600 hover:bg-red-500 focus:bg-red-700 active:bg-red-900 border-red-600 focus:ring-red-500"
                    :class="{ 'opacity-25': form.processing }"
                    :disabled="form.processing"
                    @click="unreconcile"
                >
                    Desvincular Grupo
                </PrimaryButton>
            </template>
        </ConfirmationModal>
    </AuthenticatedLayout>
</template>

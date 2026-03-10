<script setup lang="ts">
import AuthenticatedLayout from "@/Layouts/AuthenticatedLayout.vue";
import { Head, router } from "@inertiajs/vue3";
import { ref, watch, onUnmounted } from "vue";
import { debounce } from "lodash";
import StatusTabs from "./Partials/StatusTabs.vue";
import StatusSummary from "./Partials/StatusSummary.vue";
import TransactionList from "./Partials/TransactionList.vue";
import AdvancedFilters from "@/Components/AdvancedFilters.vue";
import SecondaryButton from "@/Components/SecondaryButton.vue";
import axios from "axios";

const props = defineProps<{
    conciliatedInvoices: Array<any>;
    conciliatedMovements: Array<any>;
    pendingInvoices: Array<any>;
    pendingMovements: Array<any>;
    totalPendingInvoices: number;
    totalPendingMovements: number;
    totalConciliatedInvoices: number;
    totalConciliatedMovements: number;
    filters?: {
        search?: string;
        date_from?: string;
        date_to?: string;
        amount_min?: string;
        amount_max?: string;
        month?: string | number;
        year?: string | number;
        invoice_sort?: string;
        invoice_direction?: string;
        movement_sort?: string;
        movement_direction?: string;
    };
}>();

const activeTab = ref("pending"); // pending | conciliated
const search = ref(props.filters?.search || "");

// Independent Sort States
const invoiceSort = ref(props.filters?.invoice_sort || "date");
const invoiceDirection = ref(props.filters?.invoice_direction || "desc");
const movementSort = ref(props.filters?.movement_sort || "date");
const movementDirection = ref(props.filters?.movement_direction || "desc");

const updateParams = (newFilters: any = {}) => {
    // If newFilters has keys (from AdvancedFilters emit), use it.
    // Otherwise (from sort watchers), fallback to current props.filters.
    const currentFilters =
        Object.keys(newFilters).length > 0 ? newFilters : props.filters || {};

    const params = {
        search: currentFilters.search,
        date_from: currentFilters.date_from,
        date_to: currentFilters.date_to,
        amount_min: currentFilters.amount_min,
        amount_max: currentFilters.amount_max,
        invoice_sort: invoiceSort.value,
        invoice_direction: invoiceDirection.value,
        movement_sort: movementSort.value,
        movement_direction: movementDirection.value,
    };

    router.get(route("reconciliation.status"), params, {
        preserveState: true,
        replace: true,
    });
};

// Remove search watcher since component handles it
// watch(search, debounce(updateParams, 300));
watch([invoiceSort, invoiceDirection, movementSort, movementDirection], () =>
    updateParams(),
);

const exportProcessing = ref(false);
// Track the active poll interval so we can clear it on unmount or on new export
const activeExportInterval = ref<ReturnType<typeof setInterval> | null>(null);

const clearActiveInterval = () => {
    if (activeExportInterval.value !== null) {
        clearInterval(activeExportInterval.value);
        activeExportInterval.value = null;
    }
};

// Clear interval when component is destroyed to prevent memory leaks
onUnmounted(() => {
    clearActiveInterval();
});

const startExport = async (format: string) => {
    if (exportProcessing.value) return;
    exportProcessing.value = true;

    try {
        const currentFilters = props.filters || {};
        const params = {
            format: format,
            month: currentFilters.month,
            year: currentFilters.year,
            search: currentFilters.search,
            date_from: currentFilters.date_from,
            date_to: currentFilters.date_to,
            amount_min: currentFilters.amount_min,
            amount_max: currentFilters.amount_max,
        };

        const response = await axios.get(route("reconciliation.export"), {
            params,
        });

        if (response.data.id) {
            pollExport(response.data.id);
        } else {
            // Fallback if generic response
            exportProcessing.value = false;
        }
    } catch (error) {
        console.error(error);
        exportProcessing.value = false;
        alert("Error iniciando exportación. Intente de nuevo.");
    }
};

const pollExport = (id: number) => {
    // Cancel any previous poll before starting a new one
    clearActiveInterval();

    activeExportInterval.value = setInterval(async () => {
        try {
            const res = await axios.get(
                route("reconciliation.export.status", id),
            );

            if (res.data.status === "completed") {
                clearActiveInterval();
                exportProcessing.value = false;
                window.location.href = route(
                    "reconciliation.export.download",
                    id,
                );
            } else if (res.data.status === "failed") {
                clearActiveInterval();
                exportProcessing.value = false;
                alert(
                    "La exportación falló: " +
                        (res.data.error_message || "Error desconocido"),
                );
            }
        } catch (e) {
            clearActiveInterval();
            exportProcessing.value = false;
            alert("Error consultando estado de exportación.");
        }
    }, 2000);
};
</script>

<template>
    <Head title="Reporte de Estatus" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex justify-between items-center bg-transparent">
                <h2 class="font-semibold text-xl text-gray-800 leading-tight">
                    Reporte de Estatus
                </h2>
            </div>
        </template>

        <div class="py-6">
            <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
                <!-- Top Bar: Filters (Tabs inside) -->
                <div class="mb-6">
                    <AdvancedFilters
                        :filters="filters"
                        :placeholder="$t('Buscar ID, RFC, Nombre o Monto...')"
                        @update="updateParams"
                    >
                        <template #footer>
                            <StatusTabs
                                v-model="activeTab"
                                :pending-count="
                                    pendingInvoices.length +
                                    pendingMovements.length
                                "
                                :conciliated-count="
                                    conciliatedInvoices.length +
                                    conciliatedMovements.length
                                "
                            />
                        </template>

                        <template #actions>
                            <div class="flex gap-2">
                                <SecondaryButton
                                    @click="startExport('xlsx')"
                                    :disabled="exportProcessing"
                                    size="sm"
                                    class="flex items-center gap-2"
                                >
                                    <svg
                                        class="w-4 h-4"
                                        fill="none"
                                        stroke="currentColor"
                                        viewBox="0 0 24 24"
                                    >
                                        <path
                                            stroke-linecap="round"
                                            stroke-linejoin="round"
                                            stroke-width="2"
                                            d="M12 10v6m0 0l-3-3m3 3l3-3m2 8H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"
                                        ></path>
                                    </svg>
                                    {{
                                        exportProcessing
                                            ? "..."
                                            : "Excel"
                                    }}
                                </SecondaryButton>
                                <SecondaryButton
                                    @click="startExport('pdf')"
                                    :disabled="exportProcessing"
                                    size="sm"
                                    class="flex items-center gap-2"
                                >
                                    <svg
                                        class="w-4 h-4"
                                        fill="none"
                                        stroke="currentColor"
                                        viewBox="0 0 24 24"
                                    >
                                        <path
                                            stroke-linecap="round"
                                            stroke-linejoin="round"
                                            stroke-width="2"
                                            d="M7 21h10a2 2 0 002-2V9.414a1 1 0 00-.293-.707l-5.414-5.414A1 1 0 0012.586 3H7a2 2 0 00-2 2v14a2 2 0 002 2z"
                                        ></path>
                                        <path
                                            stroke-linecap="round"
                                            stroke-linejoin="round"
                                            stroke-width="2"
                                            d="M9 9h1m4 0h1m-5 4h5m-5 4h5"
                                        ></path>
                                    </svg>
                                    {{
                                        exportProcessing
                                            ? "..."
                                            : "PDF"
                                    }}
                                </SecondaryButton>
                            </div>
                        </template>
                    </AdvancedFilters>
                </div>

                <StatusSummary
                    :active-tab="activeTab"
                    :total-pending-invoices="totalPendingInvoices"
                    :total-conciliated-invoices="totalConciliatedInvoices"
                    :total-pending-movements="totalPendingMovements"
                    :total-conciliated-movements="totalConciliatedMovements"
                />

                <div class="grid grid-cols-1 md:grid-cols-2 gap-6 mt-6">
                    <!-- Invoices Column -->
                    <TransactionList
                        title="Facturas"
                        type="invoice"
                        :items="
                            activeTab === 'pending'
                                ? pendingInvoices
                                : conciliatedInvoices
                        "
                        :is-conciliated="activeTab === 'conciliated'"
                        :current-sort="invoiceSort"
                        :current-direction="invoiceDirection"
                        @toggle-sort="
                            (s) => {
                                if (invoiceSort === s) {
                                    invoiceDirection =
                                        invoiceDirection === 'asc'
                                            ? 'desc'
                                            : 'asc';
                                } else {
                                    invoiceSort = s;
                                    invoiceDirection = 'desc';
                                }
                            }
                        "
                    />

                    <!-- Movements Column -->
                    <TransactionList
                        title="Movimientos"
                        type="movement"
                        :items="
                            activeTab === 'pending'
                                ? pendingMovements
                                : conciliatedMovements
                        "
                        :is-conciliated="activeTab === 'conciliated'"
                        :current-sort="movementSort"
                        :current-direction="movementDirection"
                        @toggle-sort="
                            (s) => {
                                if (movementSort === s) {
                                    movementDirection =
                                        movementDirection === 'asc'
                                            ? 'desc'
                                            : 'asc';
                                } else {
                                    movementSort = s;
                                    movementDirection = 'desc';
                                }
                            }
                        "
                    />
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>

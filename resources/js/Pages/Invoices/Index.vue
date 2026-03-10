<script setup lang="ts">
import AuthenticatedLayout from "@/Layouts/AuthenticatedLayout.vue";
import { Head, router, Link, useForm } from "@inertiajs/vue3";
import { ref, computed } from "vue";
import ConfirmationModal from "@/Components/ConfirmationModal.vue";
import Modal from "@/Components/Modal.vue";
import PrimaryButton from "@/Components/PrimaryButton.vue";
import SecondaryButton from "@/Components/SecondaryButton.vue";
import InvoiceFilters from "@/Pages/Reconciliation/Partials/InvoiceFilters.vue";
import InvoiceTable from "@/Pages/Reconciliation/Partials/InvoiceTable.vue";

const props = defineProps<{
    files: {
        data: Array<{
            id: number;
            path: string;
            created_at: string;
            factura?: {
                uuid: string;
                monto: number;
                rfc: string;
                nombre: string;
                fecha_emision: string;
                tipo_comprobante?: string;
                metodo_pago?: string;
                conciliaciones_count?: number;
                conciliaciones?: Array<{
                    id: number;
                    user?: {
                        name: string;
                    };
                }>;
            };
        }>;
        links: Array<{
            url?: string;
            label: string;
            active: boolean;
        }>;
        current_page: number;
        last_page: number;
        from: number;
        to: number;
        total: number;
    };
    filters?: {
        search?: string;
        date?: string;
        date_from?: string;
        date_to?: string;
        amount_min?: string;
        amount_max?: string;
        sort?: string;
        direction?: string;
        per_page?: string | number;
    };
}>();

const sortColumn = ref(props.filters?.sort || "created_at");
const sortDirection = ref(props.filters?.direction || "desc");
const perPage = ref(props.filters?.per_page || 10);

const updateParams = (filters: any) => {
    router.get(
        route("invoices.index"),
        {
            search: filters.search,
            date: filters.date,
            date_from: filters.date_from,
            date_to: filters.date_to,
            amount_min: filters.amount_min,
            amount_max: filters.amount_max,
            sort: sortColumn.value,
            direction: sortDirection.value,
            per_page: perPage.value,
        },
        {
            preserveState: true,
            replace: true,
        },
    );
};

const handleSort = (column: string) => {
    if (sortColumn.value === column) {
        sortDirection.value = sortDirection.value === "asc" ? "desc" : "asc";
    } else {
        sortColumn.value = column;
        sortDirection.value = "desc";
    }
    // Trigger update with current search/date values would be ideal,
    // but here we might need to access the child component state or keep state lifted.
    // For simplicity, we just reload with current url params + new sort,
    // but the filters component handles the search/date state.
    // To fix this properly, we should probably keep search/date state in parent like before
    // OR emit event from filters component on every change.
    // Let's rely on inertia existing params or props.
    // Actually, `updateParams` function above expects filters object.

    // Quick fix: we need current search/date.
    // In strict component design, parent should hold state.
    // Let's move state back to parent for filters to ensure sort works with current filters.
    // BUT I already extracted filters.
    // I will modify `updateParams` to use current props or local state if I sync it.

    // Let's re-implement `updateParams` to merge.
    router.visit(route("invoices.index"), {
        data: {
            ...route().params, // Keep existing params
            sort: sortColumn.value,
            direction: sortDirection.value,
        },
        preserveState: true,
        replace: true,
    });
};

const handlePerPage = (newPerPage: string | number) => {
    perPage.value = newPerPage;
    router.visit(route("invoices.index"), {
        data: {
            ...route().params,
            per_page: newPerPage,
        },
        preserveState: true,
        replace: true,
    });
};

const confirmingFileDeletion = ref(false);
const fileIdToDelete = ref<number | null>(null);
const confirmingBatchDeletion = ref(false);
const selectedIds = ref<number[]>([]);
const form = useForm({});
const batchForm = useForm({
    ids: [] as number[],
});

const toggleSelect = (id: number) => {
    if (selectedIds.value.includes(id)) {
        selectedIds.value = selectedIds.value.filter((i) => i !== id);
    } else {
        selectedIds.value.push(id);
    }
};

const toggleAll = (val: boolean) => {
    if (val) {
        selectedIds.value = props.files.data.map((f) => f.id);
    } else {
        selectedIds.value = [];
    }
};

const confirmFileDeletion = (id: number) => {
    fileIdToDelete.value = id;
    confirmingFileDeletion.value = true;
};

const confirmBatchDeletion = () => {
    confirmingBatchDeletion.value = true;
};

const deleteFile = () => {
    if (!fileIdToDelete.value) return;

    form.delete(route("invoices.destroy", fileIdToDelete.value), {
        preserveScroll: true,
        onSuccess: () => closeModal(),
        onError: () => (fileIdToDelete.value = null),
        onFinish: () => form.reset(),
    });
};

const deleteBatch = () => {
    batchForm.ids = selectedIds.value;
    batchForm.post(route("invoices.batch-destroy"), {
        preserveScroll: true,
        onSuccess: () => {
            closeModal();
            selectedIds.value = [];
        },
        onFinish: () => batchForm.reset(),
    });
};

const closeModal = () => {
    confirmingFileDeletion.value = false;
    confirmingBatchDeletion.value = false;
    fileIdToDelete.value = null;
    form.reset();
};

const formatCurrency = (amount: number) => {
    return new Intl.NumberFormat("es-MX", {
        style: "currency",
        currency: "MXN",
    }).format(amount);
};

// View detail modal
const showViewModal = ref(false);
const viewingFile = ref<typeof props.files.data[0] | null>(null);

const viewInvoice = (file: typeof props.files.data[0]) => {
    viewingFile.value = file;
    showViewModal.value = true;
};

const closeViewModal = () => {
    showViewModal.value = false;
    viewingFile.value = null;
};

const formatDate = (date?: string) => {
    if (!date) return "N/A";
    const d = new Date(date);
    const offset = d.getTimezoneOffset() * 60000;
    return new Date(d.getTime() + offset).toLocaleDateString("es-MX", {
        year: "numeric",
        month: "long",
        day: "numeric",
    });
};

const tipoLabel = (tipo?: string) => {
    const map: Record<string, string> = {
        I: "Ingreso",
        E: "Egreso",
        P: "Complemento de Pago",
        T: "Traslado",
        N: "Nómina",
    };
    return tipo ? map[tipo] || tipo : "N/A";
};
</script>

<template>
    <Head title="Facturas" />

    <AuthenticatedLayout>
        <template #header>
            <h2
                class="font-semibold text-xl text-gray-800 dark:text-gray-200 leading-tight"
            >
                {{ $t("Facturas") }}
            </h2>
        </template>

        <div class="py-12">
            <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
                <div
                    class="bg-white dark:bg-gray-800 overflow-hidden shadow-sm sm:rounded-lg"
                >
                    <div class="p-6 text-gray-900 dark:text-gray-100">
                        <div
                            class="flex flex-col md:flex-row justify-between items-center mb-6 gap-4"
                        >
                            <div>
                                <h3
                                    class="text-lg font-medium text-gray-900 dark:text-gray-100"
                                >
                                    {{ $t("Facturas Cargadas") }}
                                </h3>
                                <p
                                    class="text-sm text-gray-500 dark:text-gray-400 mt-1"
                                >
                                    <span
                                        class="font-semibold text-gray-700 dark:text-gray-200"
                                    >
                                        {{
                                            $t(
                                                "Total: :count facturas | Monto Total (Página): :amount",
                                                {
                                                    count: String(files.total),
                                                    amount: formatCurrency(
                                                        files.data.reduce(
                                                            (sum, file) =>
                                                                sum +
                                                                Number(
                                                                    file.factura
                                                                        ?.monto ||
                                                                        0,
                                                                ),
                                                            0,
                                                        ),
                                                    ),
                                                },
                                            )
                                        }}
                                    </span>
                                </p>
                            </div>

                            <!-- Batch Actions (Delete) -->
                            <Transition
                                enter-active-class="transition ease-out duration-200"
                                enter-from-class="opacity-0 scale-95"
                                enter-to-class="opacity-100 scale-100"
                                leave-active-class="transition ease-in duration-75"
                                leave-from-class="opacity-100 scale-100"
                                leave-to-class="opacity-0 scale-95"
                            >
                                <button
                                    v-if="selectedIds.length > 0"
                                    @click="confirmBatchDeletion"
                                    class="inline-flex items-center px-4 py-2 bg-red-600 border border-transparent rounded-md font-semibold text-xs text-white uppercase tracking-widest hover:bg-red-500 active:bg-red-700 focus:outline-none focus:ring-2 focus:ring-red-500 focus:ring-offset-2 dark:focus:ring-offset-gray-800 transition ease-in-out duration-150"
                                >
                                    {{ $t("Eliminar") }} ({{
                                        selectedIds.length
                                    }})
                                </button>
                            </Transition>
                        </div>

                        <!-- Filters -->
                        <div class="mb-6">
                            <InvoiceFilters
                                :filters="filters"
                                @update="updateParams"
                            />
                        </div>

                        <InvoiceTable
                            :files="files"
                            :selected-ids="selectedIds"
                            :sort-column="sortColumn"
                            :sort-direction="sortDirection"
                            :per-page="perPage"
                            @sort="handleSort"
                            @toggle-select="toggleSelect"
                            @toggle-all="toggleAll"
                            @view="viewInvoice"
                            @update-per-page="handlePerPage"
                        />
                    </div>
                </div>
            </div>
        </div>

        <!-- Invoice Detail Modal -->
        <Modal :show="showViewModal" @close="closeViewModal" max-width="lg">
            <div class="p-6" v-if="viewingFile?.factura">
                <div class="flex justify-between items-start mb-6">
                    <h2 class="text-lg font-semibold text-gray-900 dark:text-gray-100">
                        {{ $t("Detalle de Factura") }}
                    </h2>
                    <button @click="closeViewModal" class="text-gray-400 hover:text-gray-600 dark:hover:text-gray-300">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                        </svg>
                    </button>
                </div>

                <dl class="grid grid-cols-1 sm:grid-cols-2 gap-x-6 gap-y-4 text-sm">
                    <div>
                        <dt class="font-medium text-gray-500 dark:text-gray-400">{{ $t("UUID") }}</dt>
                        <dd class="mt-1 text-gray-900 dark:text-gray-100 font-mono text-xs break-all">
                            {{ viewingFile.factura.uuid || "N/A" }}
                        </dd>
                    </div>
                    <div>
                        <dt class="font-medium text-gray-500 dark:text-gray-400">{{ $t("RFC") }}</dt>
                        <dd class="mt-1 text-gray-900 dark:text-gray-100 font-mono">
                            {{ viewingFile.factura.rfc || "N/A" }}
                        </dd>
                    </div>
                    <div class="sm:col-span-2">
                        <dt class="font-medium text-gray-500 dark:text-gray-400">{{ $t("Nombre / Razón Social") }}</dt>
                        <dd class="mt-1 text-gray-900 dark:text-gray-100">
                            {{ viewingFile.factura.nombre || "N/A" }}
                        </dd>
                    </div>
                    <div>
                        <dt class="font-medium text-gray-500 dark:text-gray-400">{{ $t("Monto") }}</dt>
                        <dd class="mt-1 text-xl font-bold text-gray-900 dark:text-gray-100">
                            {{ formatCurrency(Number(viewingFile.factura.monto)) }}
                        </dd>
                    </div>
                    <div>
                        <dt class="font-medium text-gray-500 dark:text-gray-400">{{ $t("Fecha de Emisión") }}</dt>
                        <dd class="mt-1 text-gray-900 dark:text-gray-100">
                            {{ formatDate(viewingFile.factura.fecha_emision) }}
                        </dd>
                    </div>
                    <div>
                        <dt class="font-medium text-gray-500 dark:text-gray-400">{{ $t("Tipo de Comprobante") }}</dt>
                        <dd class="mt-1 text-gray-900 dark:text-gray-100">
                            {{ tipoLabel(viewingFile.factura.tipo_comprobante) }}
                        </dd>
                    </div>
                    <div>
                        <dt class="font-medium text-gray-500 dark:text-gray-400">{{ $t("Método de Pago") }}</dt>
                        <dd class="mt-1 text-gray-900 dark:text-gray-100">
                            {{ viewingFile.factura.metodo_pago || "N/A" }}
                        </dd>
                    </div>
                    <div class="sm:col-span-2">
                        <dt class="font-medium text-gray-500 dark:text-gray-400">{{ $t("Estado") }}</dt>
                        <dd class="mt-1">
                            <span
                                v-if="viewingFile.factura.conciliaciones_count && viewingFile.factura.conciliaciones_count > 0"
                                class="inline-flex items-center bg-green-100 text-green-800 text-xs font-semibold px-2.5 py-0.5 rounded border border-green-400"
                            >
                                {{ $t("CONCILIADO") }}
                            </span>
                            <span
                                v-else
                                class="inline-flex items-center bg-yellow-100 text-yellow-800 text-xs font-semibold px-2.5 py-0.5 rounded border border-yellow-400"
                            >
                                {{ $t("PENDIENTE") }}
                            </span>
                        </dd>
                    </div>
                </dl>
            </div>
        </Modal>

        <ConfirmationModal :show="confirmingFileDeletion" @close="closeModal">
            <template #title> {{ $t("Eliminar Factura") }} </template>

            <template #content>
                {{
                    $t(
                        "¿Estás seguro de que deseas eliminar esta factura? Esta acción eliminará el archivo y todos los registros asociados permanentemente.",
                    )
                }}
            </template>

            <template #footer>
                <SecondaryButton @click="closeModal">
                    {{ $t("Cancelar") }}
                </SecondaryButton>

                <PrimaryButton
                    class="ml-3 bg-red-600 hover:bg-red-500 focus:bg-red-700 active:bg-red-900 border-red-600 focus:ring-red-500"
                    :class="{ 'opacity-25': form.processing }"
                    :disabled="form.processing"
                    @click="deleteFile"
                >
                    {{ $t("Eliminar") }}
                </PrimaryButton>
            </template>
        </ConfirmationModal>

        <ConfirmationModal :show="confirmingBatchDeletion" @close="closeModal">
            <template #title>
                {{ $t("Eliminar Facturas Seleccionadas") }}
            </template>

            <template #content>
                {{
                    $t(
                        "¿Estás seguro de que deseas eliminar las :count facturas seleccionadas? Esta acción no se puede deshacer.",
                        { count: String(selectedIds.length) },
                    )
                }}
            </template>

            <template #footer>
                <SecondaryButton @click="closeModal">
                    {{ $t("Cancelar") }}
                </SecondaryButton>

                <PrimaryButton
                    class="ml-3 bg-red-600 hover:bg-red-500 focus:bg-red-700 active:bg-red-900 border-red-600 focus:ring-red-500"
                    :class="{ 'opacity-25': batchForm.processing }"
                    :disabled="batchForm.processing"
                    @click="deleteBatch"
                >
                    {{ $t("Eliminar Todo") }}
                </PrimaryButton>
            </template>
        </ConfirmationModal>
    </AuthenticatedLayout>
</template>

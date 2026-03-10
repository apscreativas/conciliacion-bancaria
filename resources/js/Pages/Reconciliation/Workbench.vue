<script setup lang="ts">
import AuthenticatedLayout from "@/Layouts/AuthenticatedLayout.vue";
import { Head, router } from "@inertiajs/vue3";
import { ref, computed, reactive, watch } from "vue";
import PrimaryButton from "@/Components/PrimaryButton.vue";
import SecondaryButton from "@/Components/SecondaryButton.vue";
import WorkbenchSelectionSummary from "./Partials/WorkbenchSelectionSummary.vue";
import WorkbenchColumns from "./Partials/WorkbenchColumns.vue";
import ReconciliationModal from "./Partials/ReconciliationModal.vue";
import DatePicker from "@/Components/DatePicker.vue";
import { wTrans } from "laravel-vue-i18n";

const props = defineProps<{
    invoices: Array<any>;
    movements: Array<any>;
    tolerance: number;
    filters?: {
        month?: string;
        year?: string;
        date_from?: string;
        date_to?: string;
        amount_min?: string;
        amount_max?: string;
    };
}>();

const selectedInvoices = ref<number[]>([]);
const selectedMovements = ref<number[]>([]);
const showConfirmationModal = ref(false);
const confirmationMessage = ref("");
const confirmationTitle = ref("");

const showErrorModal = ref(false);
const errorMessage = ref("");
const errorTitle = ref("");
const reconciliationDate = ref(new Date().toISOString().split("T")[0]);
// Track if the user manually edited the date — if so, don't overwrite it
const userModifiedDate = ref(false);

// Helper to calculate best date (only used when user has NOT manually set a date)
const calculateBestDate = () => {
    if (userModifiedDate.value) return;

    // 1. If no movements, default to today
    if (selectedMovements.value.length === 0) return;

    // 2. If no invoices, fallback to max amount logic or just latest movement
    if (selectedInvoices.value.length === 0) {
        let maxAmount = -1;
        let bestDate: string | null = null;
        selectedMovements.value.forEach((id) => {
            const mov = props.movements.find((m) => m.id == id);
            if (mov) {
                const amount = parseFloat(mov.monto);
                if (!isNaN(amount) && amount > maxAmount) {
                    maxAmount = amount;
                    bestDate = mov.fecha;
                }
            }
        });
        if (bestDate)
            reconciliationDate.value = (bestDate as string).substring(0, 10);
        return;
    }

    // 3. Matcher Logic: Closest movement date to the *first* selected invoice date
    const firstInvoiceId = selectedInvoices.value[0];
    const invoice = props.invoices.find((i) => i.id === firstInvoiceId);

    if (!invoice || !invoice.fecha_emision) return;

    const targetDate = new Date(invoice.fecha_emision);
    let bestDiff = Infinity;
    let closestDate: string | null = null;

    selectedMovements.value.forEach((id) => {
        const mov = props.movements.find((m) => m.id == id);
        if (mov && mov.fecha) {
            const movDate = new Date(mov.fecha);
            const diff = Math.abs(movDate.getTime() - targetDate.getTime());

            if (diff < bestDiff) {
                bestDiff = diff;
                closestDate = mov.fecha;
            }
        }
    });

    if (closestDate) {
        reconciliationDate.value = (closestDate as string).substring(0, 10);
    }
};

// When selection changes, recalculate the date only if user hasn't manually set it.
// Also reset the userModifiedDate flag when the selection is fully cleared.
watch([selectedMovements, selectedInvoices], () => {
    if (selectedMovements.value.length === 0 && selectedInvoices.value.length === 0) {
        userModifiedDate.value = false;
        reconciliationDate.value = new Date().toISOString().split("T")[0];
    }
    calculateBestDate();
});

// Filters Logic
const filterForm = reactive({
    date_from: props.filters?.date_from || "",
    date_to: props.filters?.date_to || "",
    amount_min: props.filters?.amount_min || "",
    amount_max: props.filters?.amount_max || "",
});

const applyFilters = () => {
    router.get(
        route("reconciliation.index"),
        {
            date_from: filterForm.date_from,
            date_to: filterForm.date_to,
            amount_min: filterForm.amount_min,
            amount_max: filterForm.amount_max,
            month: props.filters?.month,
            year: props.filters?.year,
        },
        {
            preserveState: true,
            replace: true,
            preserveScroll: true,
        },
    );
};

const clearFilters = () => {
    filterForm.date_from = "";
    filterForm.date_to = "";
    filterForm.amount_min = "";
    filterForm.amount_max = "";
    applyFilters();
};

const toggleInvoice = (id: number) => {
    if (selectedInvoices.value.includes(id)) {
        selectedInvoices.value = selectedInvoices.value.filter((i) => i !== id);
    } else {
        selectedInvoices.value.push(id);
    }
};

const toggleMovement = (id: number) => {
    if (selectedMovements.value.includes(id)) {
        selectedMovements.value = selectedMovements.value.filter(
            (i) => i !== id,
        );
    } else {
        selectedMovements.value.push(id);
    }
};

const processing = ref(false);
const autoReconciling = ref(false);

const totalInvoices = computed(() => {
    return props.invoices
        .filter((i) => selectedInvoices.value.includes(i.id))
        .reduce((sum, i) => sum + Number(i.monto), 0);
});

const totalMovements = computed(() => {
    return props.movements
        .filter((m) => selectedMovements.value.includes(m.id))
        .reduce((sum, m) => sum + Number(m.monto), 0);
});

const diff = computed(() => totalMovements.value - totalInvoices.value);

const validateAndReconcile = () => {
    let warnings: string[] = [];
    let title = wTrans("Confirmar Conciliación").value;

    // Recalculate date one last time to be sure
    calculateBestDate();

    // 1. RFC Consistency Check
    const selectedInvoiceObjects = props.invoices.filter((i) =>
        selectedInvoices.value.includes(i.id),
    );
    if (selectedInvoiceObjects.length > 1) {
        const firstRFC = selectedInvoiceObjects[0].rfc;
        const hasMismatch = selectedInvoiceObjects.some(
            (i) => i.rfc !== firstRFC,
        );

        if (hasMismatch) {
            errorTitle.value = wTrans("Error de RFC").value;
            errorMessage.value = wTrans(
                "Las facturas seleccionadas deben pertenecer al mismo RFC receptor.",
            ).value;
            showErrorModal.value = true;
            return;
        }
    }

    // 2. Tolerance Check
    const absDiff = Math.abs(diff.value);
    const tolerance = props.tolerance || 0.0;

    if (absDiff > tolerance + 0.001) {
        title =
            warnings.length > 0
                ? wTrans("Advertencias de Conciliación").value
                : wTrans("Diferencia Excede Tolerancia").value;
        warnings.push(
            `⚠ ${wTrans("Diferencia de Monto").value}:\n${wTrans("La diferencia ($:diff) es mayor que la tolerancia permitida ($:tolerance).", { diff: absDiff.toFixed(2), tolerance: tolerance.toFixed(2) }).value}`,
        );
    }

    if (warnings.length > 0) {
        confirmationTitle.value = title;
        confirmationMessage.value =
            warnings.join("\n\n") +
            "\n\n" +
            wTrans("¿Estás seguro de que deseas continuar?").value;
        showConfirmationModal.value = true;
        return;
    }

    // If validations pass, proceed directly
    submitReconciliation();
};

const submitReconciliation = () => {
    if (!reconciliationDate.value) {
        errorTitle.value = wTrans("Error de Validación").value;
        errorMessage.value = wTrans("Debe seleccionar una fecha de conciliación.").value;
        showErrorModal.value = true;
        return;
    }
    showConfirmationModal.value = false;
    processing.value = true;
    router.post(
        route("reconciliation.store"),
        {
            invoice_ids: selectedInvoices.value,

            movement_ids: selectedMovements.value,
            conciliacion_at: reconciliationDate.value,
        },
        {
            onSuccess: () => {
                selectedInvoices.value = [];
                selectedMovements.value = [];
            },
            onFinish: () => {
                processing.value = false;
            },
        },
    );
};

const autoReconcile = () => {
    autoReconciling.value = true;
    router.get(
        route("reconciliation.auto"),
        {},
        {
            onFinish: () => {
                autoReconciling.value = false;
            },
        },
    );
};
</script>

<template>
    <Head title="Mesa de Trabajo" />

    <AuthenticatedLayout>
        <template #header>
            <h2
                class="font-semibold text-xl text-gray-800 dark:text-gray-200 leading-tight"
            >
                {{ $t("Mesa de Trabajo") }}
            </h2>
        </template>

        <div class="py-12">
            <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
                <!-- Filters Section -->
                <div
                    class="mb-6 bg-white dark:bg-gray-800 p-4 rounded-lg shadow border border-gray-200 dark:border-gray-700"
                >
                    <h3
                        class="text-xs font-semibold text-gray-400 uppercase tracking-widest mb-3"
                    >
                        {{ $t("FILTROS DE BÚSQUEDA") }}
                    </h3>
                    <div
                        class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4"
                    >
                        <!-- Date Range -->
                        <div>
                            <label
                                class="block text-xs font-medium text-gray-500 dark:text-gray-400 mb-1"
                                >{{ $t("Desde") }}</label
                            >
                            <DatePicker
                                v-model="filterForm.date_from"
                                :placeholder="$t('dd/mm/aaaa')"
                            />
                        </div>
                        <div>
                            <label
                                class="block text-xs font-medium text-gray-500 dark:text-gray-400 mb-1"
                                >{{ $t("Hasta") }}</label
                            >
                            <DatePicker
                                v-model="filterForm.date_to"
                                :placeholder="$t('dd/mm/aaaa')"
                            />
                        </div>

                        <!-- Amount Range -->
                        <div>
                            <label
                                class="block text-xs font-medium text-gray-500 dark:text-gray-400 mb-1"
                                >{{ $t("Monto Mín ($)") }}</label
                            >
                            <input
                                type="number"
                                step="0.01"
                                v-model="filterForm.amount_min"
                                placeholder="0.00"
                                class="w-full text-sm border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white rounded-md focus:ring-indigo-500 focus:border-indigo-500"
                            />
                        </div>
                        <div>
                            <label
                                class="block text-xs font-medium text-gray-500 dark:text-gray-400 mb-1"
                                >{{ $t("Monto Máx ($)") }}</label
                            >
                            <input
                                type="number"
                                step="0.01"
                                v-model="filterForm.amount_max"
                                placeholder="0.00"
                                class="w-full text-sm border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white rounded-md focus:ring-indigo-500 focus:border-indigo-500"
                            />
                        </div>
                    </div>
                    <div class="mt-4 flex justify-end space-x-3">
                        <SecondaryButton @click="clearFilters" size="sm">{{
                            $t("LIMPIAR")
                        }}</SecondaryButton>
                        <PrimaryButton @click="applyFilters" size="sm">{{
                            $t("APLICAR FILTROS")
                        }}</PrimaryButton>
                    </div>
                </div>

                <!-- Selection Summary Bar -->
                <WorkbenchSelectionSummary
                    :total-invoices="totalInvoices"
                    :total-movements="totalMovements"
                    :diff="diff"
                    :has-selection="
                        selectedInvoices.length > 0 &&
                        selectedMovements.length > 0
                    "
                    :processing="processing"
                    :auto-reconciling="autoReconciling"
                    @validate="validateAndReconcile"
                    @auto-reconcile="autoReconcile"
                />

                <!-- Split View Columns -->
                <WorkbenchColumns
                    :invoices="invoices"
                    :movements="movements"
                    :selected-invoices="selectedInvoices"
                    :selected-movements="selectedMovements"
                    @toggle-invoice="toggleInvoice"
                    @toggle-movement="toggleMovement"
                />
            </div>
        </div>

        <!-- Confirmation Modal -->
        <ReconciliationModal
            :show="showConfirmationModal"
            :title="confirmationTitle"
            :message="confirmationMessage"
            :processing="processing"
            @close="showConfirmationModal = false"
            @confirm="submitReconciliation"
        >
            <template #content>
                <div class="mt-4">
                    <label
                        class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1"
                        >{{ $t("Fecha de Conciliación") }}</label
                    >
                    <DatePicker
                        v-model="reconciliationDate"
                        :placeholder="$t('dd/mm/aaaa')"
                        @update:modelValue="userModifiedDate = true"
                    />
                    <p class="text-xs text-gray-500 mt-1">
                        {{
                            $t(
                                "Esta fecha se asignará al registro de conciliación.",
                            )
                        }}
                    </p>
                </div>
            </template>
        </ReconciliationModal>

        <!-- Error Modal -->
        <ReconciliationModal
            :show="showErrorModal"
            :title="errorTitle"
            :message="errorMessage"
            :processing="false"
            :is-error="true"
            @close="showErrorModal = false"
        />
    </AuthenticatedLayout>
</template>

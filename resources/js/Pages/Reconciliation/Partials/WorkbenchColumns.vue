<script setup lang="ts">
import { computed, ref } from "vue";

const props = defineProps<{
    invoices: Array<any>;
    movements: Array<any>;
    selectedInvoices: number[];
    selectedMovements: number[];
}>();

const emit = defineEmits(["toggleInvoice", "toggleMovement"]);

type SortOrder = "default" | "asc" | "desc";
const invoiceSort = ref<SortOrder>("default");
const movementSort = ref<SortOrder>("default");

const toggleInvoiceSort = () => {
    if (invoiceSort.value === "default") invoiceSort.value = "asc";
    else if (invoiceSort.value === "asc") invoiceSort.value = "desc";
    else invoiceSort.value = "default";
};

const toggleMovementSort = () => {
    if (movementSort.value === "default") movementSort.value = "asc";
    else if (movementSort.value === "asc") movementSort.value = "desc";
    else movementSort.value = "default";
};

const formatCurrency = (amount: number) => {
    return new Intl.NumberFormat("es-MX", {
        style: "currency",
        currency: "MXN",
    }).format(amount);
};

const formatDate = (dateString: string) => {
    if (!dateString) return "";
    const d = new Date(dateString);
    const userTimezoneOffset = d.getTimezoneOffset() * 60000;
    const adjustedDate = new Date(d.getTime() + userTimezoneOffset);
    return adjustedDate.toLocaleDateString("es-MX", {
        year: "numeric",
        month: "2-digit",
        day: "2-digit",
    });
};

const sortedInvoices = computed(() => {
    return [...props.invoices].sort((a, b) => {
        // 1. Selected items always on top
        const aSelected = props.selectedInvoices.includes(a.id);
        const bSelected = props.selectedInvoices.includes(b.id);
        if (aSelected && !bSelected) return -1;
        if (!aSelected && bSelected) return 1;

        // 2. Sort by Amount if active
        if (invoiceSort.value === "asc") {
            return Number(a.monto) - Number(b.monto);
        } else if (invoiceSort.value === "desc") {
            return Number(b.monto) - Number(a.monto);
        }

        // 3. Default (Original order, likely Date Desc)
        return 0;
    });
});

const sortedMovements = computed(() => {
    return [...props.movements].sort((a, b) => {
        // 1. Selected items always on top
        const aSelected = props.selectedMovements.includes(a.id);
        const bSelected = props.selectedMovements.includes(b.id);
        if (aSelected && !bSelected) return -1;
        if (!aSelected && bSelected) return 1;

        // 2. Sort by Amount if active
        if (movementSort.value === "asc") {
            return Number(a.monto) - Number(b.monto);
        } else if (movementSort.value === "desc") {
            return Number(b.monto) - Number(a.monto);
        }

        // 3. Default (Original order, likely Date Desc)
        return 0;
    });
});
</script>

<template>
    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
        <!-- Left Column: Invoices -->
        <div
            class="bg-white dark:bg-gray-800 overflow-hidden shadow-sm sm:rounded-lg flex flex-col h-[700px]"
        >
            <div
                class="p-4 border-b border-gray-200 dark:border-gray-700 bg-gray-50 dark:bg-gray-900 flex justify-between items-center"
            >
                <span class="font-semibold text-gray-700 dark:text-gray-300">{{
                    $t("FACTURAS PENDIENTES")
                }}</span>

                <button
                    @click="toggleInvoiceSort"
                    class="p-1 rounded hover:bg-gray-200 dark:hover:bg-gray-700 transition-colors text-gray-500 mx-1 flex items-center space-x-1"
                    :title="$t('Ordenar por monto')"
                >
                    <span
                        class="text-xs uppercase font-medium hidden sm:inline-block"
                    >
                        {{
                            invoiceSort === "asc"
                                ? $t("Menor a Mayor")
                                : invoiceSort === "desc"
                                  ? $t("Mayor a Menor")
                                  : $t("Ordenar Monto")
                        }}
                    </span>
                    <!-- Icons -->
                    <svg
                        v-if="invoiceSort === 'default'"
                        class="w-4 h-4 ml-1"
                        fill="none"
                        stroke="currentColor"
                        viewBox="0 0 24 24"
                    >
                        <path
                            stroke-linecap="round"
                            stroke-linejoin="round"
                            stroke-width="2"
                            d="M7 16V4m0 0L3 8m4-4l4 4m6 0v12m0 0l4-4m-4 4l-4-4"
                        ></path>
                    </svg>
                    <svg
                        v-if="invoiceSort === 'asc'"
                        class="w-4 h-4 ml-1"
                        fill="none"
                        stroke="currentColor"
                        viewBox="0 0 24 24"
                    >
                        <path
                            stroke-linecap="round"
                            stroke-linejoin="round"
                            stroke-width="2"
                            d="M3 4h13M3 8h9m-9 4h6m4 0l4-4m0 0l4 4m-4-4v12"
                        ></path>
                    </svg>
                    <svg
                        v-if="invoiceSort === 'desc'"
                        class="w-4 h-4 ml-1"
                        fill="none"
                        stroke="currentColor"
                        viewBox="0 0 24 24"
                    >
                        <path
                            stroke-linecap="round"
                            stroke-linejoin="round"
                            stroke-width="2"
                            d="M3 4h13M3 8h9m-9 4h9m5-4v12m0 0l-4-4m4 4l4-4"
                        ></path>
                    </svg>
                </button>
            </div>

            <div class="flex-1 overflow-y-auto p-4 space-y-3 custom-scrollbar">
                <div
                    v-if="invoices.length === 0"
                    class="text-center text-gray-500 py-10"
                >
                    {{ $t("No hay facturas pendientes.") }}
                </div>

                <div
                    v-for="invoice in sortedInvoices"
                    :key="invoice.id"
                    @click="emit('toggleInvoice', invoice.id)"
                    class="p-3 border rounded-lg cursor-pointer transition-colors relative hover:shadow-md"
                    :class="{
                        'border-indigo-500 bg-indigo-50 dark:bg-indigo-900/20 ring-1 ring-indigo-500':
                            selectedInvoices.includes(invoice.id),
                        'border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800':
                            !selectedInvoices.includes(invoice.id),
                    }"
                >
                    <div class="flex justify-between items-start">
                        <div>
                            <div
                                class="font-bold text-gray-800 dark:text-gray-200 text-sm"
                            >
                                {{ invoice.nombre }}
                            </div>
                            <div class="text-xs text-gray-500 mt-1">
                                {{ formatDate(invoice.fecha_emision) }}
                            </div>
                            <div
                                class="text-xs text-gray-400 font-mono mt-0.5 truncate w-48"
                                :title="invoice.uuid"
                            >
                                {{ invoice.uuid.substring(0, 18) }}..
                            </div>
                            <span
                                v-if="invoice.tipo_comprobante === 'P'"
                                class="inline-block mt-1 px-1.5 py-0.5 text-[10px] font-bold rounded bg-purple-100 text-purple-700 dark:bg-purple-900/40 dark:text-purple-300"
                            >
                                Complemento de Pago
                            </span>
                            <span
                                v-else-if="invoice.metodo_pago === 'PUE'"
                                class="inline-block mt-1 px-1.5 py-0.5 text-[10px] font-bold rounded bg-blue-100 text-blue-700 dark:bg-blue-900/40 dark:text-blue-300"
                            >
                                PUE
                            </span>
                        </div>
                        <div class="text-right">
                            <div
                                class="font-bold text-indigo-600 dark:text-indigo-400"
                            >
                                {{ formatCurrency(invoice.monto) }}
                            </div>
                        </div>
                    </div>

                    <!-- Checkmark indicator -->
                    <div
                        v-if="selectedInvoices.includes(invoice.id)"
                        class="absolute top-2 right-2 h-4 w-4 bg-indigo-600 rounded-full flex items-center justify-center"
                    >
                        <svg
                            class="w-3 h-3 text-white"
                            fill="none"
                            stroke="currentColor"
                            viewBox="0 0 24 24"
                        >
                            <path
                                stroke-linecap="round"
                                stroke-linejoin="round"
                                stroke-width="2"
                                d="M5 13l4 4L19 7"
                            ></path>
                        </svg>
                    </div>
                </div>
            </div>
        </div>

        <!-- Right Column: Movements -->
        <div
            class="bg-white dark:bg-gray-800 overflow-hidden shadow-sm sm:rounded-lg flex flex-col h-[700px]"
        >
            <div
                class="p-4 border-b border-gray-200 dark:border-gray-700 bg-gray-50 dark:bg-gray-900 flex justify-between items-center"
            >
                <span class="font-semibold text-gray-700 dark:text-gray-300">{{
                    $t("Movimientos Bancarios")
                }}</span>

                <button
                    @click="toggleMovementSort"
                    class="p-1 rounded hover:bg-gray-200 dark:hover:bg-gray-700 transition-colors text-gray-500 mx-1 flex items-center space-x-1"
                    :title="$t('Ordenar por monto')"
                >
                    <span
                        class="text-xs uppercase font-medium hidden sm:inline-block"
                    >
                        {{
                            movementSort === "asc"
                                ? $t("Menor a Mayor")
                                : movementSort === "desc"
                                  ? $t("Mayor a Menor")
                                  : $t("Ordenar Monto")
                        }}
                    </span>
                    <!-- Icons -->
                    <svg
                        v-if="movementSort === 'default'"
                        class="w-4 h-4 ml-1"
                        fill="none"
                        stroke="currentColor"
                        viewBox="0 0 24 24"
                    >
                        <path
                            stroke-linecap="round"
                            stroke-linejoin="round"
                            stroke-width="2"
                            d="M7 16V4m0 0L3 8m4-4l4 4m6 0v12m0 0l4-4m-4 4l-4-4"
                        ></path>
                    </svg>
                    <svg
                        v-if="movementSort === 'asc'"
                        class="w-4 h-4 ml-1"
                        fill="none"
                        stroke="currentColor"
                        viewBox="0 0 24 24"
                    >
                        <path
                            stroke-linecap="round"
                            stroke-linejoin="round"
                            stroke-width="2"
                            d="M3 4h13M3 8h9m-9 4h6m4 0l4-4m0 0l4 4m-4-4v12"
                        ></path>
                    </svg>
                    <svg
                        v-if="movementSort === 'desc'"
                        class="w-4 h-4 ml-1"
                        fill="none"
                        stroke="currentColor"
                        viewBox="0 0 24 24"
                    >
                        <path
                            stroke-linecap="round"
                            stroke-linejoin="round"
                            stroke-width="2"
                            d="M3 4h13M3 8h9m-9 4h9m5-4v12m0 0l-4-4m4 4l4-4"
                        ></path>
                    </svg>
                </button>
            </div>

            <div class="flex-1 overflow-y-auto p-4 space-y-3 custom-scrollbar">
                <div
                    v-if="movements.length === 0"
                    class="text-center text-gray-500 py-10"
                >
                    {{ $t("No hay movimientos pendientes.") }}
                </div>

                <div
                    v-for="movement in sortedMovements"
                    :key="movement.id"
                    @click="emit('toggleMovement', movement.id)"
                    class="p-3 border rounded-lg cursor-pointer transition-colors relative hover:shadow-md"
                    :class="{
                        'border-green-500 bg-green-50 dark:bg-green-900/20 ring-1 ring-green-500':
                            selectedMovements.includes(movement.id),
                        'border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800':
                            !selectedMovements.includes(movement.id),
                    }"
                >
                    <div class="flex justify-between items-start">
                        <div class="flex-1 mr-2">
                            <div
                                class="font-bold text-gray-800 dark:text-gray-200 text-sm break-words leading-tight"
                            >
                                {{ movement.descripcion }}
                            </div>
                            <div class="text-xs text-gray-500 mt-1">
                                {{ formatDate(movement.fecha) }}
                            </div>
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
                                v-else-if="movement.archivo?.banco"
                                class="text-[10px] font-bold text-indigo-600 uppercase mt-0.5 tracking-wide bg-indigo-50 dark:bg-indigo-900/30 inline-block px-1 rounded"
                            >
                                {{ movement.archivo.banco.nombre }}
                            </div>
                            <div
                                class="text-[10px] text-gray-400 uppercase mt-0.5 tracking-wide bg-gray-100 dark:bg-gray-700 inline-block px-1 rounded ml-1"
                            >
                                {{ movement.tipo }}
                            </div>
                        </div>
                        <div class="text-right whitespace-nowrap">
                            <div
                                class="font-bold text-green-600 dark:text-green-400"
                            >
                                {{ formatCurrency(movement.monto) }}
                            </div>
                        </div>
                    </div>

                    <!-- Checkmark indicator -->
                    <div
                        v-if="selectedMovements.includes(movement.id)"
                        class="absolute top-2 right-2 h-4 w-4 bg-green-600 rounded-full flex items-center justify-center"
                    >
                        <svg
                            class="w-3 h-3 text-white"
                            fill="none"
                            stroke="currentColor"
                            viewBox="0 0 24 24"
                        >
                            <path
                                stroke-linecap="round"
                                stroke-linejoin="round"
                                stroke-width="2"
                                d="M5 13l4 4L19 7"
                            ></path>
                        </svg>
                    </div>
                </div>
            </div>
        </div>
    </div>
</template>

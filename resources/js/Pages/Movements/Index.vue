<script setup lang="ts">
import AuthenticatedLayout from "@/Layouts/AuthenticatedLayout.vue";
import Modal from "@/Components/Modal.vue";
import SecondaryButton from "@/Components/SecondaryButton.vue";
import ConfirmationModal from "@/Components/ConfirmationModal.vue";
import PrimaryButton from "@/Components/PrimaryButton.vue";
import { Head, router, useForm, Link } from "@inertiajs/vue3";
import { ref, computed, reactive, onUnmounted } from "vue";
import axios from "axios";
import MovementFilters from "@/Pages/Reconciliation/Partials/MovementFilters.vue";
import MovementTable from "@/Pages/Reconciliation/Partials/MovementTable.vue";

const props = defineProps<{
    files: Array<{
        id: number;
        path: string;
        original_name?: string;
        created_at: string;
        banco?: { nombre: string };
        bank_format?: { name: string; color: string };
        movimientos_count: number;
    }>;
    movements: {
        data: Array<{
            id: number;
            fecha: string;
            descripcion: string;
            tipo: string;
            monto: number;
            conciliaciones_count: number;
            archivo?: {
                original_name?: string;
                banco?: { nombre: string };
                bank_format?: { name: string; color: string };
            };
        }>;
        links: Array<any>;
    };
    filters?: {
        month?: string;
        year?: string;
        date?: string;
        date_from?: string;
        date_to?: string;
        amount_min?: string;
        amount_max?: string;
        per_page?: string | number;
        sort_by?: string;
        sort_order?: string;
    };
}>();

const perPage = ref(props.filters?.per_page || 50);

const viewMode = ref(
    new URLSearchParams(window.location.search).has("page") ||
        props.filters?.date_from ||
        props.filters?.amount_min
        ? "movements"
        : "files",
); // 'files' | 'movements'
const showModal = ref(false);
const selectedFile = ref<any>(null);
const fileMovements = ref<any[]>([]);
const isLoading = ref(false);
const loadError = ref<string | null>(null);
// AbortController to cancel in-flight requests when a new one is started
let activeAbortController: AbortController | null = null;

onUnmounted(() => {
    activeAbortController?.abort();
});

const form = useForm({
    file: null as File | null,
    bank_format_id: null as number | null,
});

const activeTab = ref("all"); // 'all', 'abono', 'cargo'

const applyFilters = (filters: any) => {
    router.get(
        route("movements.index"),
        {
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
            preserveScroll: true,
        },
    );
};

const clearFilters = () => {
    applyFilters({
        date_from: "",
        date_to: "",
        amount_min: "",
        amount_max: "",
    });
};

const handlePerPage = (newPerPage: string | number) => {
    perPage.value = newPerPage;
    applyFilters({
        date_from: props.filters?.date_from,
        date_to: props.filters?.date_to,
        amount_min: props.filters?.amount_min,
        amount_max: props.filters?.amount_max,
    });
};

const handleSortChange = (field: string) => {
    let newOrder = "desc";
    if (
        props.filters?.sort_by === field &&
        props.filters?.sort_order === "desc"
    ) {
        newOrder = "asc";
    }

    router.get(
        route("movements.index"),
        {
            date_from: props.filters?.date_from,
            date_to: props.filters?.date_to,
            amount_min: props.filters?.amount_min,
            amount_max: props.filters?.amount_max,
            month: props.filters?.month,
            year: props.filters?.year,
            per_page: perPage.value,
            sort_by: field,
            sort_order: newOrder,
        },
        {
            preserveState: true,
            replace: true,
            preserveScroll: true,
        },
    );
};

const formatDate = (date: string) => {
    return new Date(date).toLocaleDateString("es-MX", {
        year: "numeric",
        month: "long",
        day: "numeric",
        hour: "2-digit",
        minute: "2-digit",
    });
};

const formatCurrency = (amount: number) => {
    return new Intl.NumberFormat("es-MX", {
        style: "currency",
        currency: "MXN",
    }).format(amount);
};

const viewDetails = async (file: any) => {
    // Cancel any in-flight request for a previous file
    activeAbortController?.abort();
    activeAbortController = new AbortController();

    selectedFile.value = file;
    showModal.value = true;
    isLoading.value = true;
    loadError.value = null;
    fileMovements.value = [];
    activeTab.value = "all";

    try {
        const response = await axios.get(route("movements.show", file.id), {
            signal: activeAbortController.signal,
        });
        fileMovements.value = response.data;
    } catch (error: any) {
        // Ignore aborted requests (user switched file quickly)
        if (axios.isCancel(error) || error?.code === "ERR_CANCELED") return;
        console.error("Error fetching movements", error);
        loadError.value = "No se pudieron cargar los movimientos. Intente de nuevo.";
    } finally {
        isLoading.value = false;
    }
};

const filteredMovements = computed(() => {
    if (activeTab.value === "all") return fileMovements.value;
    return fileMovements.value.filter((m) => m.tipo === activeTab.value);
});

const totalAbonos = computed(() => {
    return fileMovements.value
        .filter((m) => m.tipo === "abono")
        .reduce((sum, m) => sum + Number(m.monto), 0);
});

const totalCargos = computed(() => {
    return fileMovements.value
        .filter((m) => m.tipo === "cargo")
        .reduce((sum, m) => sum + Number(m.monto), 0);
});

const confirmingFileDeletion = ref(false);
const fileIdToDelete = ref<number | null>(null);
const confirmingBatchDeletion = ref(false);
const selectedIds = ref<number[]>([]);
const batchForm = useForm({ ids: [] as number[] });

const selectAll = computed({
    get: () =>
        props.files.length > 0 &&
        selectedIds.value.length === props.files.length,
    set: (val) => {
        if (val) {
            selectedIds.value = props.files.map((f) => f.id);
        } else {
            selectedIds.value = [];
        }
    },
});

const confirmDeleteFile = (file: { id: number }) => {
    fileIdToDelete.value = file.id;
    confirmingFileDeletion.value = true;
};

const confirmBatchDeletion = () => {
    confirmingBatchDeletion.value = true;
};

const deleteBatch = () => {
    batchForm.ids = selectedIds.value;
    batchForm.post(route("movements.batch-destroy"), {
        preserveScroll: true,
        onSuccess: () => {
            closeModal();
            selectedIds.value = [];
        },
        onFinish: () => batchForm.reset(),
    });
};

const deleteFileConfirmed = () => {
    if (!fileIdToDelete.value) return;

    form.delete(route("movements.destroy", fileIdToDelete.value), {
        preserveScroll: true,
        onSuccess: () => closeModal(),
        onError: () => (fileIdToDelete.value = null),
        onFinish: () => form.reset(),
    });
};

const closeModal = () => {
    showModal.value = false;
    selectedFile.value = null;
    fileMovements.value = [];
    confirmingFileDeletion.value = false;
    confirmingBatchDeletion.value = false;
    fileIdToDelete.value = null;
    form.reset();
};

const formatDateNoTime = (date?: string) => {
    if (!date) return "N/A";
    const d = new Date(date);
    const userTimezoneOffset = d.getTimezoneOffset() * 60000;
    const adjustedDate = new Date(d.getTime() + userTimezoneOffset);
    return adjustedDate.toLocaleDateString("es-MX", {
        year: "numeric",
        month: "long",
        day: "numeric",
    });
};
</script>

<template>
    <Head title="Movimientos Bancarios" />

    <AuthenticatedLayout>
        <template #header>
            <h2
                class="font-semibold text-xl text-gray-800 dark:text-gray-200 leading-tight"
            >
                {{ $t("Movimientos Bancarios") }}
            </h2>
        </template>

        <div class="py-12">
            <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
                <!-- Page Tabs -->
                <div class="mb-6 border-b border-gray-200 dark:border-gray-700">
                    <ul
                        class="flex flex-wrap -mb-px text-sm font-medium text-center text-gray-500 dark:text-gray-400"
                    >
                        <li class="mr-2">
                            <a
                                href="#"
                                @click.prevent="viewMode = 'files'"
                                :class="
                                    viewMode === 'files'
                                        ? 'inline-block p-4 text-blue-600 border-b-2 border-blue-600 rounded-t-lg active dark:text-blue-500 dark:border-blue-500'
                                        : 'inline-block p-4 border-b-2 border-transparent rounded-t-lg hover:text-gray-600 hover:border-gray-300 dark:hover:text-gray-300'
                                "
                            >
                                {{ $t("Archivos Cargados") }}
                            </a>
                        </li>
                        <li class="mr-2">
                            <a
                                href="#"
                                @click.prevent="viewMode = 'movements'"
                                :class="
                                    viewMode === 'movements'
                                        ? 'inline-block p-4 text-blue-600 border-b-2 border-blue-600 rounded-t-lg active dark:text-blue-500 dark:border-blue-500'
                                        : 'inline-block p-4 border-b-2 border-transparent rounded-t-lg hover:text-gray-600 hover:border-gray-300 dark:hover:text-gray-300'
                                "
                            >
                                {{ $t("Todos los Movimientos") }}
                            </a>
                        </li>
                    </ul>
                </div>

                <div
                    class="bg-white dark:bg-gray-800 overflow-hidden shadow-sm sm:rounded-lg"
                >
                    <div class="p-6 text-gray-900 dark:text-gray-100">
                        <!-- Header & Actions -->
                        <div
                            class="flex flex-col md:flex-row justify-between items-center mb-6 gap-4"
                        >
                            <h3 class="text-lg font-medium">
                                {{
                                    viewMode === "files"
                                        ? $t("Archivos de Movimientos Cargados")
                                        : $t("Listado General de Movimientos")
                                }}
                            </h3>

                            <div class="flex items-center gap-4">
                                <!-- Batch Delete Button (Only for Files view) -->
                                <Transition
                                    enter-active-class="transition ease-out duration-200"
                                    enter-from-class="opacity-0 scale-95"
                                    enter-to-class="opacity-100 scale-100"
                                    leave-active-class="transition ease-in duration-75"
                                    leave-from-class="opacity-100 scale-100"
                                    leave-to-class="opacity-0 scale-95"
                                >
                                    <button
                                        v-if="
                                            viewMode === 'files' &&
                                            selectedIds.length > 0
                                        "
                                        @click="confirmBatchDeletion"
                                        class="inline-flex items-center px-4 py-2 bg-red-600 border border-transparent rounded-md font-semibold text-xs text-white uppercase tracking-widest hover:bg-red-500 active:bg-red-700 focus:outline-none focus:ring-2 focus:ring-red-500 focus:ring-offset-2 transition ease-in-out duration-150"
                                    >
                                        Eliminar ({{ selectedIds.length }})
                                    </button>
                                </Transition>
                            </div>
                        </div>

                        <!-- Filters (Only for Movements Tab) -->
                        <MovementFilters
                            v-if="viewMode === 'movements'"
                            :filters="filters"
                            @apply="applyFilters"
                            @clear="clearFilters"
                        />

                        <!-- Files View -->
                        <div v-if="viewMode === 'files'">
                            <div
                                v-if="files.length === 0"
                                class="text-center py-8 text-gray-500"
                            >
                                No se han cargado archivos de movimientos aún.
                            </div>

                            <div v-else class="overflow-x-auto relative">
                                <table
                                    class="w-full text-sm text-left text-gray-500 dark:text-gray-400"
                                >
                                    <thead
                                        class="text-xs text-gray-700 uppercase bg-gray-50 dark:bg-gray-700 dark:text-gray-400"
                                    >
                                        <tr>
                                            <th scope="col" class="p-4 w-4">
                                                <div class="flex items-center">
                                                    <input
                                                        type="checkbox"
                                                        v-model="selectAll"
                                                        class="rounded border-gray-300 text-indigo-600 shadow-sm focus:ring-indigo-500 dark:border-gray-700 dark:bg-gray-900 dark:focus:ring-indigo-600 dark:focus:ring-offset-gray-800"
                                                    />
                                                </div>
                                            </th>
                                            <th scope="col" class="py-3 px-6">
                                                {{ $t("ID") }}
                                            </th>
                                            <th scope="col" class="py-3 px-6">
                                                {{ $t("BANCO") }}
                                            </th>
                                            <th scope="col" class="py-3 px-6">
                                                {{ $t("ARCHIVO") }}
                                            </th>
                                            <th scope="col" class="py-3 px-6">
                                                {{ $t("MOVIMIENTOS") }}
                                            </th>
                                            <th scope="col" class="py-3 px-6">
                                                {{ $t("FECHA DE CARGA") }}
                                            </th>
                                            <th scope="col" class="py-3 px-6">
                                                {{ $t("ACCIONES") }}
                                            </th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <tr
                                            v-for="file in files"
                                            :key="file.id"
                                            class="bg-white border-b dark:bg-gray-800 dark:border-gray-700 hover:bg-gray-50 dark:hover:bg-gray-700 cursor-pointer"
                                            @click="viewDetails(file)"
                                        >
                                            <td class="p-4 w-4" @click.stop>
                                                <div class="flex items-center">
                                                    <input
                                                        type="checkbox"
                                                        :value="file.id"
                                                        v-model="selectedIds"
                                                        class="rounded border-gray-300 text-indigo-600 shadow-sm focus:ring-indigo-500 dark:border-gray-700 dark:bg-gray-900 dark:focus:ring-indigo-600 dark:focus:ring-offset-gray-800"
                                                    />
                                                </div>
                                            </td>
                                            <td class="py-4 px-6">
                                                {{ file.id }}
                                            </td>
                                            <td class="py-4 px-6">
                                                <span
                                                    class="text-xs font-semibold px-2.5 py-0.5 rounded border"
                                                    :style="
                                                        file.bank_format?.color
                                                            ? {
                                                                  backgroundColor:
                                                                      file
                                                                          .bank_format
                                                                          .color +
                                                                      '20',
                                                                  color: file
                                                                      .bank_format
                                                                      .color,
                                                                  borderColor:
                                                                      file
                                                                          .bank_format
                                                                          .color +
                                                                      '40',
                                                              }
                                                            : {}
                                                    "
                                                    :class="{
                                                        'bg-blue-100 text-blue-800 border-blue-400':
                                                            !file.bank_format
                                                                ?.color,
                                                    }"
                                                >
                                                    {{
                                                        file.bank_format
                                                            ?.name ||
                                                        file.banco?.nombre ||
                                                        "Desconocido"
                                                    }}
                                                </span>
                                            </td>
                                            <td
                                                class="py-4 px-6 truncate max-w-xs"
                                                :title="
                                                    file.original_name ||
                                                    file.path
                                                "
                                            >
                                                {{
                                                    file.original_name ||
                                                    file.path.split("/").pop()
                                                }}
                                            </td>
                                            <td class="py-4 px-6">
                                                <span
                                                    class="bg-gray-100 text-gray-800 text-xs font-semibold mr-2 px-2.5 py-0.5 rounded dark:bg-gray-700 dark:text-gray-300"
                                                >
                                                    {{ file.movimientos_count }}
                                                </span>
                                            </td>
                                            <td class="py-4 px-6">
                                                {{
                                                    formatDate(file.created_at)
                                                }}
                                            </td>
                                            <td class="py-4 px-6">
                                                <div
                                                    class="flex items-center gap-4"
                                                >
                                                    <button
                                                        @click.stop="
                                                            viewDetails(file)
                                                        "
                                                        class="font-medium text-blue-600 dark:text-blue-500 hover:underline"
                                                    >
                                                        {{ $t("Ver Detalle") }}
                                                    </button>
                                                    <button
                                                        @click.stop="
                                                            confirmDeleteFile(
                                                                file,
                                                            )
                                                        "
                                                        class="font-medium text-red-600 dark:text-red-500 hover:underline"
                                                    >
                                                        {{ $t("Eliminar") }}
                                                    </button>
                                                </div>
                                            </td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                        </div>

                        <!-- MOVEMENTS VIEW -->
                        <MovementTable
                            v-else-if="viewMode === 'movements'"
                            :movements="movements"
                            :per-page="perPage"
                            :sort-by="filters?.sort_by"
                            :sort-order="filters?.sort_order"
                            @update-per-page="handlePerPage"
                            @sort-change="handleSortChange"
                        />
                    </div>
                </div>
            </div>
        </div>

        <Modal :show="showModal" @close="closeModal">
            <div class="p-6">
                <div class="flex justify-between items-start mb-4">
                    <div>
                        <h2
                            class="text-lg font-medium text-gray-900 dark:text-gray-100"
                        >
                            Detalle de Movimientos
                        </h2>
                        <p
                            class="mt-1 text-sm text-gray-600 dark:text-gray-400"
                            v-if="selectedFile"
                        >
                            Archivo:
                            {{
                                selectedFile.original_name ||
                                selectedFile.path.split("/").pop()
                            }}
                        </p>
                    </div>
                    <div
                        class="text-right text-sm"
                        v-if="!isLoading && fileMovements.length > 0"
                    >
                        <div class="text-green-600">
                            Abonos: {{ formatCurrency(totalAbonos) }}
                        </div>
                        <div class="text-red-600">
                            Cargos: {{ formatCurrency(totalCargos) }}
                        </div>
                    </div>
                </div>

                <div class="mb-4 border-b border-gray-200 dark:border-gray-700">
                    <ul
                        class="flex flex-wrap -mb-px text-sm font-medium text-center text-gray-500 dark:text-gray-400"
                    >
                        <li class="mr-2">
                            <a
                                href="#"
                                @click.prevent="activeTab = 'all'"
                                :class="
                                    activeTab === 'all'
                                        ? 'text-blue-600 border-b-2 border-blue-600 dark:text-blue-500 dark:border-blue-500'
                                        : 'hover:text-gray-600 hover:border-gray-300 dark:hover:text-gray-300'
                                "
                                class="inline-block p-4 rounded-t-lg"
                                >Todos</a
                            >
                        </li>
                        <li class="mr-2">
                            <a
                                href="#"
                                @click.prevent="activeTab = 'abono'"
                                :class="
                                    activeTab === 'abono'
                                        ? 'text-green-600 border-b-2 border-green-600'
                                        : 'hover:text-gray-600 hover:border-gray-300 dark:hover:text-gray-300'
                                "
                                class="inline-block p-4 rounded-t-lg"
                                >Abonos</a
                            >
                        </li>
                        <li class="mr-2">
                            <a
                                href="#"
                                @click.prevent="activeTab = 'cargo'"
                                :class="
                                    activeTab === 'cargo'
                                        ? 'text-red-600 border-b-2 border-red-600'
                                        : 'hover:text-gray-600 hover:border-gray-300 dark:hover:text-gray-300'
                                "
                                class="inline-block p-4 rounded-t-lg"
                                >Cargos</a
                            >
                        </li>
                    </ul>
                </div>

                <div class="mt-6">
                    <div v-if="isLoading" class="text-center py-4">
                        <!-- Spinner -->
                        <svg
                            class="animate-spin h-5 w-5 mx-auto text-gray-500"
                            xmlns="http://www.w3.org/2000/svg"
                            fill="none"
                            viewBox="0 0 24 24"
                        >
                            <circle
                                class="opacity-25"
                                cx="12"
                                cy="12"
                                r="10"
                                stroke="currentColor"
                                stroke-width="4"
                            ></circle>
                            <path
                                class="opacity-75"
                                fill="currentColor"
                                d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"
                            ></path>
                        </svg>
                        <span class="mt-2 block text-sm text-gray-500"
                            >Cargando movimientos...</span
                        >
                    </div>
                    <div
                        v-else-if="loadError"
                        class="text-center py-4 text-red-600 dark:text-red-400 text-sm"
                    >
                        {{ loadError }}
                    </div>
                    <div
                        v-else-if="filteredMovements.length === 0"
                        class="text-center py-4 text-gray-500"
                    >
                        No hay movimientos de este tipo para mostrar.
                    </div>
                    <div
                        v-else
                        class="overflow-x-auto max-h-[60vh] overflow-y-auto"
                    >
                        <table
                            class="w-full text-sm text-left text-gray-500 dark:text-gray-400"
                        >
                            <thead
                                class="text-xs text-gray-700 uppercase bg-gray-50 dark:bg-gray-700 dark:text-gray-400 sticky top-0"
                            >
                                <tr>
                                    <th class="py-2 px-4">Fecha</th>
                                    <th class="py-2 px-4">Descripción</th>
                                    <th class="py-2 px-4">Tipo</th>
                                    <th class="py-2 px-4">Estado</th>
                                    <th class="py-2 px-4 text-right">Monto</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr
                                    v-for="mov in filteredMovements"
                                    :key="mov.id"
                                    class="bg-white border-b dark:bg-gray-800 dark:border-gray-700"
                                >
                                    <td class="py-2 px-4 whitespace-nowrap">
                                        {{ formatDateNoTime(mov.fecha) }}
                                    </td>
                                    <td class="py-3 px-4 min-w-[300px] whitespace-normal break-words text-gray-700 dark:text-gray-300">
                                        {{ mov.descripcion }}
                                    </td>
                                    <td class="py-2 px-4">
                                        <span
                                            :class="
                                                mov.tipo === 'abono'
                                                    ? 'bg-green-100 text-green-800'
                                                    : 'bg-red-100 text-red-800'
                                            "
                                            class="text-xs font-medium px-2.5 py-0.5 rounded"
                                            >{{
                                                mov.tipo === "abono"
                                                    ? "Abono"
                                                    : "Cargo"
                                            }}</span
                                        >
                                    </td>
                                    <td class="py-2 px-4">
                                        <span
                                            v-if="mov.conciliaciones_count > 0"
                                            class="bg-green-100 text-green-800 text-xs font-semibold px-2.5 py-0.5 rounded border border-green-400"
                                            >Conciliado</span
                                        >
                                        <span
                                            v-else
                                            class="bg-gray-100 text-gray-800 text-xs font-semibold px-2.5 py-0.5 rounded border border-gray-400"
                                            >Pendiente</span
                                        >
                                    </td>
                                    <td
                                        class="py-2 px-4 text-right font-mono"
                                        :class="
                                            mov.tipo === 'cargo'
                                                ? 'text-red-600'
                                                : 'text-green-600'
                                        "
                                    >
                                        {{ formatCurrency(Number(mov.monto)) }}
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
                <div class="mt-6 flex justify-end">
                    <SecondaryButton @click="closeModal"
                        >Cerrar</SecondaryButton
                    >
                </div>
            </div>
        </Modal>

        <ConfirmationModal :show="confirmingFileDeletion" @close="closeModal">
            <template #title> Eliminar Archivo de Movimientos </template>
            <template #content>
                ¿Estás seguro de que deseas eliminar este archivo? Se eliminarán
                todos los movimientos bancarios asociados permanentemente. Esta
                acción no se puede deshacer.
            </template>
            <template #footer>
                <SecondaryButton @click="closeModal">Cancelar</SecondaryButton>
                <PrimaryButton
                    class="ml-3 bg-red-600 hover:bg-red-500 focus:bg-red-700 active:bg-red-900 border-red-600 focus:ring-red-500"
                    :class="{ 'opacity-25': form.processing }"
                    :disabled="form.processing"
                    @click="deleteFileConfirmed"
                    >Eliminar</PrimaryButton
                >
            </template>
        </ConfirmationModal>

        <ConfirmationModal :show="confirmingBatchDeletion" @close="closeModal">
            <template #title> Eliminar Archivos Seleccionados </template>
            <template #content>
                ¿Estás seguro de que deseas eliminar los
                {{ selectedIds.length }} archivos seleccionados? Se eliminarán
                todos los movimientos asociados permanentemente.
            </template>
            <template #footer>
                <SecondaryButton @click="closeModal">Cancelar</SecondaryButton>
                <PrimaryButton
                    class="ml-3 bg-red-600 hover:bg-red-500 focus:bg-red-700 active:bg-red-900 border-red-600 focus:ring-red-500"
                    :class="{ 'opacity-25': batchForm.processing }"
                    :disabled="batchForm.processing"
                    @click="deleteBatch"
                    >Eliminar Todo</PrimaryButton
                >
            </template>
        </ConfirmationModal>
    </AuthenticatedLayout>
</template>

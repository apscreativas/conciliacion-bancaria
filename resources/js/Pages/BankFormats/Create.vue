<script setup>
import AuthenticatedLayout from "@/Layouts/AuthenticatedLayout.vue";
import { Head, useForm, router } from "@inertiajs/vue3";
import { ref, computed } from "vue";
import PrimaryButton from "@/Components/PrimaryButton.vue";
import SecondaryButton from "@/Components/SecondaryButton.vue";
import axios from "axios";
import { trans } from "laravel-vue-i18n";
import Swal from "sweetalert2";

const props = defineProps({
    format: Object,
});

const fileInput = ref(null);
const file = ref(null);
const rows = ref([]);
const isPreviewing = ref(false);

const isEditing = computed(() => !!props.format);

const form = useForm({
    name: props.format?.name || "",
    start_row: props.format?.start_row || 1,
    date_column: props.format?.date_column || "",
    description_column: props.format?.description_column || "",
    amount_column: props.format?.amount_column || "",
    debit_column: props.format?.debit_column || "",
    credit_column: props.format?.credit_column || "",
    reference_column: props.format?.reference_column || "",
    type_column: props.format?.type_column || "",
    color: props.format?.color || "#3b82f6",
});

const columnMappings = ref({
    fecha: props.format?.date_column || null,
    descripcion: props.format?.description_column || null,
    monto: props.format?.amount_column || null,
    cargo: props.format?.debit_column || null,
    abono: props.format?.credit_column || null,
    referencia: props.format?.reference_column || null,
    tipo: props.format?.type_column || null,
});

// Colors for the picker
const availableColors = [
    "#3b82f6", // Blue
    "#ef4444", // Red
    "#10b981", // Emerald
    "#f59e0b", // Amber
    "#8b5cf6", // Violet
    "#ec4899", // Pink
    "#6366f1", // Indigo
    "#14b8a6", // Teal
];

const handleFileChange = (e) => {
    file.value = e.target.files[0];
    if (file.value) {
        uploadPreview();
    }
};

const uploadPreview = async () => {
    if (!file.value) return;
    isPreviewing.value = true;

    const formData = new FormData();
    formData.append("file", file.value);

    try {
        const response = await axios.post(
            route("bank-formats.preview"),
            formData,
            {
                headers: { "Content-Type": "multipart/form-data" },
            },
        );
        rows.value = response.data.rows;
    } catch (error) {
        console.error("Preview failed", error);
        Swal.fire({
            icon: "error",
            title: trans("Error"),
            text: trans("Error reading file"),
        });
    } finally {
        isPreviewing.value = false;
    }
};

const getColumnLetter = (index) => {
    let letter = "";
    while (index >= 0) {
        letter = String.fromCharCode((index % 26) + 65) + letter;
        index = Math.floor(index / 26) - 1;
    }
    return letter;
};

const selectColumn = (colIndex, type) => {
    const letter = getColumnLetter(colIndex);

    // Unset if already selected
    if (columnMappings.value[type] === letter) {
        columnMappings.value[type] = null;
        return;
    }

    // Assign
    columnMappings.value[type] = letter;
};

const isColumnSelected = (colIndex) => {
    const letter = getColumnLetter(colIndex);
    return Object.values(columnMappings.value).includes(letter);
};

const getColumnType = (colIndex) => {
    const letter = getColumnLetter(colIndex);
    for (const [key, val] of Object.entries(columnMappings.value)) {
        if (val === letter) return key;
    }
    return null;
};

const setStartRow = (rowIndex) => {
    // rowIndex is 0-based from array, so user sees rowIndex + 1
    form.start_row = rowIndex + 1;
};

const saveFormat = () => {
    if (!form.name) {
        return Swal.fire({
            icon: "warning",
            title: trans("Campo requerido"),
            text: trans("Por favor ingresa un nombre"),
        });
    }

    // Only require mapping if we are NOT in simple edit mode OR if rows are present (meaning re-mapping)
    // If Editing and NO rows loaded, we preserve existing mapping unless user uploaded a file.
    // However, the form fields are bound so they are sent.
    // Logic: If rows.length > 0 (user is re-mapping), validate mappings.
    const isRemapping = rows.value.length > 0;

    if (isRemapping) {
        if (!columnMappings.value.fecha || !columnMappings.value.descripcion) {
            return Swal.fire({
                icon: "warning",
                title: trans("Columnas incompletas"),
                text: trans(
                    "Por favor asigna las columnas de Fecha y Descripción",
                ),
            });
        }

        if (
            !columnMappings.value.monto &&
            (!columnMappings.value.cargo || !columnMappings.value.abono)
        ) {
            return Swal.fire({
                icon: "warning",
                title: trans("Columnas incompletas"),
                text: trans(
                    'Debes asignar "Monto" (columna única) O "Cargo" y "Abono" (columnas separadas)',
                ),
            });
        }

        form.date_column = columnMappings.value.fecha;
        form.description_column = columnMappings.value.descripcion;
        form.amount_column = columnMappings.value.monto;
        form.debit_column = columnMappings.value.cargo;
        form.credit_column = columnMappings.value.abono;
        form.reference_column = columnMappings.value.referencia;
        form.type_column = columnMappings.value.tipo;
    }

    if (isEditing.value) {
        form.put(route("bank-formats.update", props.format.id), {
            onSuccess: () => router.visit(route("bank-formats.index")),
        });
    } else {
        if (!isRemapping) {
            return Swal.fire({
                icon: "info",
                title: trans("Archivo requerido"),
                text: trans(
                    "Por favor sube un archivo para asignar las columnas.",
                ),
            });
        } // Creation requires mapping
        form.post(route("bank-formats.store"), {
            onSuccess: () => router.visit(route("bank-formats.index")),
        });
    }
};

const getTypeColor = (type) => {
    switch (type) {
        case "fecha":
            return "bg-green-100 text-green-800 border-green-300";
        case "descripcion":
            return "bg-blue-100 text-blue-800 border-blue-300";
        case "monto":
            return "bg-purple-100 text-purple-800 border-purple-300";
        case "cargo":
            return "bg-red-100 text-red-800 border-red-300";
        case "abono":
            return "bg-green-100 text-green-800 border-green-300";
        case "referencia":
            return "bg-gray-100 text-gray-800 border-gray-300";
        case "tipo":
            return "bg-yellow-100 text-yellow-800 border-yellow-300";
        default:
            return "bg-white";
    }
};

const getTypeLabel = (type) => {
    switch (type) {
        case "fecha":
            return trans("Fecha");
        case "descripcion":
            return trans("Descripción");
        case "monto":
            return trans("Monto");
        case "cargo":
            return trans("Cargo");
        case "abono":
            return trans("Abono");
        case "referencia":
            return trans("Referencia");
        case "tipo":
            return trans("Tipo");
        default:
            return "";
    }
};
</script>

<template>
    <Head title="Crear Formato Bancario" />

    <AuthenticatedLayout>
        <template #header>
            <h2
                class="font-semibold text-xl text-gray-800 dark:text-gray-200 leading-tight"
            >
                {{
                    isEditing
                        ? $t("Editar Formato Bancario")
                        : $t("Crear Formato Bancario")
                }}
            </h2>
        </template>

        <div class="py-12">
            <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
                <div
                    class="bg-white dark:bg-gray-800 overflow-hidden shadow-sm sm:rounded-lg"
                >
                    <div class="p-6 text-gray-900 dark:text-gray-100">
                        <!-- Step 1: Upload (Only show if creating OR explicit re-upload needed) -->
                        <div
                            v-if="rows.length === 0 && !isEditing"
                            class="flex flex-col items-center justify-center p-12 border-2 border-dashed border-gray-300 rounded-lg bg-gray-50 dark:bg-gray-700/50 dark:border-gray-600"
                        >
                            <h3 class="text-lg font-medium mb-4">
                                {{
                                    $t(
                                        "Sube un Estado de Cuenta de ejemplo (Excel/CSV)",
                                    )
                                }}
                            </h3>
                            <p
                                class="text-sm text-gray-500 mb-6 text-center max-w-md"
                            >
                                {{
                                    $t(
                                        "Usaremos este archivo para previsualizar los datos y ayudarte a identificar qué columna corresponde a la fecha, monto y descripción.",
                                    )
                                }}
                            </p>

                            <label class="cursor-pointer">
                                <span
                                    class="bg-blue-600 text-white px-4 py-2 rounded-md hover:bg-blue-700 transition"
                                    >{{ $t("Seleccionar Archivo") }}</span
                                >
                                <input
                                    type="file"
                                    ref="fileInput"
                                    class="hidden"
                                    accept=".xlsx,.xls,.csv"
                                    @change="handleFileChange"
                                />
                            </label>

                            <p v-if="isPreviewing" class="mt-4 text-blue-600">
                                {{ $t("Leyendo archivo...") }}
                            </p>
                        </div>

                        <!-- Editor (Show if rows loaded OR if Editing) -->
                        <div v-else>
                            <div class="mb-6">
                                <h3
                                    class="text-lg font-bold mb-4 border-b pb-2 dark:border-gray-700"
                                >
                                    {{
                                        isEditing
                                            ? $t("Editar Formato")
                                            : $t("Configurar Formato")
                                    }}
                                </h3>

                                <div
                                    class="grid grid-cols-1 md:grid-cols-2 gap-6 mb-4"
                                >
                                    <div>
                                        <label
                                            class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1"
                                            >{{ $t("Nombre del Banco") }}</label
                                        >
                                        <input
                                            v-model="form.name"
                                            type="text"
                                            placeholder="Ej. Santander Empresarial"
                                            class="block w-full rounded-md border-gray-300 dark:bg-gray-900 dark:border-gray-700 dark:text-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm"
                                        />
                                    </div>

                                    <div>
                                        <label
                                            class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1"
                                            >{{
                                                $t("Color Identificador")
                                            }}</label
                                        >
                                        <div class="flex space-x-2">
                                            <button
                                                v-for="color in availableColors"
                                                :key="color"
                                                type="button"
                                                class="w-8 h-8 rounded-full border-2 transition-transform hover:scale-110 focus:outline-none"
                                                :class="{
                                                    'border-gray-900 dark:border-white ring-2 ring-indigo-500':
                                                        form.color === color,
                                                    'border-transparent':
                                                        form.color !== color,
                                                }"
                                                :style="{
                                                    backgroundColor: color,
                                                }"
                                                @click="form.color = color"
                                            ></button>
                                        </div>
                                    </div>
                                </div>

                                <!-- Edit Mode: Info when no file uploaded -->
                                <div
                                    v-if="isEditing && rows.length === 0"
                                    class="p-4 bg-gray-50 dark:bg-gray-700/50 rounded-lg mb-6 border dark:border-gray-600"
                                >
                                    <div
                                        class="flex justify-between items-center"
                                    >
                                        <div>
                                            <p
                                                class="text-sm font-medium text-gray-900 dark:text-gray-200"
                                            >
                                                Configuración Actual:
                                            </p>
                                            <ul
                                                class="text-xs text-gray-500 dark:text-gray-400 mt-1 grid grid-cols-2 gap-x-4 gap-y-1"
                                            >
                                                <li>
                                                    {{ $t("Fecha") }}:
                                                    <span
                                                        class="font-mono text-gray-700 dark:text-gray-300"
                                                        >{{
                                                            form.date_column
                                                        }}</span
                                                    >
                                                </li>
                                                <li v-if="form.amount_column">
                                                    {{ $t("Monto") }}:
                                                    <span
                                                        class="font-mono text-gray-700 dark:text-gray-300"
                                                        >{{
                                                            form.amount_column
                                                        }}</span
                                                    >
                                                </li>
                                                <li v-else>
                                                    {{ $t("Cargos") }}:
                                                    <span
                                                        class="font-mono text-gray-700 dark:text-gray-300"
                                                        >{{
                                                            form.debit_column
                                                        }}</span
                                                    >, {{ $t("Abonos") }}:
                                                    <span
                                                        class="font-mono text-gray-700 dark:text-gray-300"
                                                        >{{
                                                            form.credit_column
                                                        }}</span
                                                    >
                                                </li>
                                                <li>
                                                    {{ $t("Descripción") }}:
                                                    <span
                                                        class="font-mono text-gray-700 dark:text-gray-300"
                                                        >{{
                                                            form.description_column
                                                        }}</span
                                                    >
                                                </li>
                                                <li>
                                                    {{ $t("Fila Inicio") }}:
                                                    <span
                                                        class="font-mono text-gray-700 dark:text-gray-300"
                                                        >{{
                                                            form.start_row
                                                        }}</span
                                                    >
                                                </li>
                                            </ul>
                                        </div>
                                        <div>
                                            <label class="cursor-pointer">
                                                <span
                                                    class="text-sm text-blue-600 hover:text-blue-500 underline font-medium"
                                                    >{{
                                                        $t(
                                                            "Subir archivo para re-mapear",
                                                        )
                                                    }}</span
                                                >
                                                <input
                                                    type="file"
                                                    ref="fileInput"
                                                    class="hidden"
                                                    accept=".xlsx,.xls,.csv"
                                                    @change="handleFileChange"
                                                />
                                            </label>
                                        </div>
                                    </div>
                                </div>

                                <div
                                    class="flex justify-end space-x-2 mt-4 pt-4 border-t dark:border-gray-700"
                                >
                                    <SecondaryButton
                                        v-if="!isEditing"
                                        @click="rows = []"
                                        >{{
                                            $t("Subir otro archivo")
                                        }}</SecondaryButton
                                    >
                                    <SecondaryButton
                                        v-if="isEditing"
                                        @click="
                                            router.visit(
                                                route('bank-formats.index'),
                                            )
                                        "
                                        >{{ $t("Cancelar") }}</SecondaryButton
                                    >
                                    <PrimaryButton
                                        @click="saveFormat"
                                        :disabled="form.processing"
                                    >
                                        {{
                                            isEditing
                                                ? $t("Actualizar Formato")
                                                : $t("Guardar Formato")
                                        }}
                                    </PrimaryButton>
                                </div>
                            </div>

                            <!-- Visual Table (Only if rows exist) -->
                            <div v-if="rows.length > 0">
                                <div
                                    class="mb-4 p-4 bg-blue-50 dark:bg-blue-900/20 rounded-lg text-sm text-blue-800 dark:text-blue-300"
                                >
                                    <p class="font-bold">
                                        {{ $t("Instrucciones:") }}
                                    </p>
                                    <ul class="list-disc ml-5 mt-1 space-y-1">
                                        <li>
                                            {{
                                                $t(
                                                    "Haz clic en los encabezados de letra (A, B, C...) para asignar columnas a Fecha, Descripción y Monto.",
                                                )
                                            }}
                                        </li>
                                        <li>
                                            {{
                                                $t(
                                                    "Haz clic en un número de fila para indicar dónde comienzan los datos reales (Fila Inicial).",
                                                )
                                            }}
                                        </li>
                                    </ul>
                                </div>

                                <div class="flex space-x-4 mb-4 text-sm">
                                    <div class="flex items-center">
                                        <span
                                            class="w-3 h-3 rounded-full bg-green-500 mr-1"
                                        ></span>
                                        {{ $t("Fecha") }}
                                    </div>
                                    <div class="flex items-center">
                                        <span
                                            class="w-3 h-3 rounded-full bg-blue-500 mr-1"
                                        ></span>
                                        {{ $t("Descripción") }}
                                    </div>
                                    <div class="flex items-center">
                                        <span
                                            class="w-3 h-3 rounded-full bg-purple-500 mr-1"
                                        ></span>
                                        {{ $t("Monto") }}
                                    </div>
                                    <div class="flex items-center">
                                        <span
                                            class="w-3 h-3 rounded-full bg-red-500 mr-1"
                                        ></span>
                                        {{ $t("Cargo") }}
                                    </div>
                                    <div class="flex items-center">
                                        <span
                                            class="w-3 h-3 rounded-full bg-green-500 mr-1"
                                        ></span>
                                        {{ $t("Abono") }}
                                    </div>
                                    <div class="flex items-center">
                                        <span
                                            class="w-3 h-3 rounded-full bg-yellow-500 mr-1"
                                        ></span>
                                        {{ $t("Tipo (Opc)") }}
                                    </div>
                                    <div class="flex items-center">
                                        <span
                                            class="w-3 h-3 rounded-full bg-gray-500 mr-1"
                                        ></span>
                                        {{ $t("Ref (Opc)") }}
                                    </div>
                                </div>

                                <div
                                    class="overflow-x-auto border rounded-lg dark:border-gray-700"
                                >
                                    <table
                                        class="min-w-full divide-y divide-gray-200 dark:divide-gray-700"
                                    >
                                        <thead
                                            class="bg-gray-50 dark:bg-gray-800"
                                        >
                                            <tr>
                                                <th
                                                    class="px-3 py-2 text-center text-xs font-medium text-gray-500 uppercase tracking-wider sticky left-0 bg-gray-50 dark:bg-gray-800 z-10 w-12"
                                                >
                                                    #
                                                </th>
                                                <th
                                                    v-for="(
                                                        cell, colIndex
                                                    ) in rows[0]"
                                                    :key="colIndex"
                                                    class="px-3 py-2 text-left text-xs font-medium uppercase tracking-wider border-l dark:border-gray-700 relative group cursor-pointer hover:bg-gray-100 dark:hover:bg-gray-700 transition"
                                                    :class="
                                                        isColumnSelected(
                                                            colIndex,
                                                        )
                                                            ? getTypeColor(
                                                                  getColumnType(
                                                                      colIndex,
                                                                  ),
                                                              )
                                                            : 'text-gray-500'
                                                    "
                                                >
                                                    <div
                                                        class="flex justify-between items-center mb-1"
                                                    >
                                                        <span
                                                            class="text-lg font-bold"
                                                            >{{
                                                                getColumnLetter(
                                                                    colIndex,
                                                                )
                                                            }}</span
                                                        >
                                                        <span
                                                            v-if="
                                                                isColumnSelected(
                                                                    colIndex,
                                                                )
                                                            "
                                                            class="px-1.5 py-0.5 rounded text-[10px] bg-white/50 border border-black/10"
                                                        >
                                                            {{
                                                                getTypeLabel(
                                                                    getColumnType(
                                                                        colIndex,
                                                                    ),
                                                                )
                                                            }}
                                                        </span>
                                                    </div>

                                                    <!-- Dropdown for selecting type -->
                                                    <div
                                                        class="opacity-0 group-hover:opacity-100 absolute top-full left-0 z-20 w-32 bg-white dark:bg-gray-700 shadow-lg rounded-b-md border dark:border-gray-600 flex flex-col p-1"
                                                    >
                                                        <button
                                                            @click="
                                                                selectColumn(
                                                                    colIndex,
                                                                    'fecha',
                                                                )
                                                            "
                                                            class="text-left px-2 py-1 text-xs hover:bg-green-100 dark:hover:bg-green-900 rounded text-green-700 dark:text-green-300 font-medium"
                                                        >
                                                            {{ $t("Fecha") }}
                                                        </button>
                                                        <button
                                                            @click="
                                                                selectColumn(
                                                                    colIndex,
                                                                    'descripcion',
                                                                )
                                                            "
                                                            class="text-left px-2 py-1 text-xs hover:bg-blue-100 dark:hover:bg-blue-900 rounded text-blue-700 dark:text-blue-300 font-medium"
                                                        >
                                                            {{
                                                                $t(
                                                                    "Descripción",
                                                                )
                                                            }}
                                                        </button>
                                                        <div
                                                            class="border-t my-1 dark:border-gray-600"
                                                        ></div>
                                                        <button
                                                            @click="
                                                                selectColumn(
                                                                    colIndex,
                                                                    'monto',
                                                                )
                                                            "
                                                            class="text-left px-2 py-1 text-xs hover:bg-purple-100 dark:hover:bg-purple-900 rounded text-purple-700 dark:text-purple-300 font-medium"
                                                        >
                                                            {{ $t("Monto") }}
                                                        </button>
                                                        <button
                                                            @click="
                                                                selectColumn(
                                                                    colIndex,
                                                                    'cargo',
                                                                )
                                                            "
                                                            class="text-left px-2 py-1 text-xs hover:bg-red-100 dark:hover:bg-red-900 rounded text-red-700 dark:text-red-300 font-medium"
                                                        >
                                                            {{ $t("Cargo") }}
                                                        </button>
                                                        <button
                                                            @click="
                                                                selectColumn(
                                                                    colIndex,
                                                                    'abono',
                                                                )
                                                            "
                                                            class="text-left px-2 py-1 text-xs hover:bg-green-100 dark:hover:bg-green-900 rounded text-green-700 dark:text-green-300 font-medium"
                                                        >
                                                            {{ $t("Abono") }}
                                                        </button>
                                                        <div
                                                            class="border-t my-1 dark:border-gray-600"
                                                        ></div>
                                                        <button
                                                            @click="
                                                                selectColumn(
                                                                    colIndex,
                                                                    'referencia',
                                                                )
                                                            "
                                                            class="text-left px-2 py-1 text-xs hover:bg-gray-100 dark:hover:bg-gray-600 rounded"
                                                        >
                                                            {{
                                                                $t("Referencia")
                                                            }}
                                                        </button>
                                                        <button
                                                            @click="
                                                                selectColumn(
                                                                    colIndex,
                                                                    'tipo',
                                                                )
                                                            "
                                                            class="text-left px-2 py-1 text-xs hover:bg-yellow-100 dark:hover:bg-yellow-900 rounded"
                                                        >
                                                            {{ $t("Tipo") }}
                                                        </button>
                                                    </div>
                                                </th>
                                            </tr>
                                        </thead>
                                        <tbody
                                            class="bg-white dark:bg-gray-800 divide-y divide-gray-200 dark:divide-gray-700"
                                        >
                                            <tr
                                                v-for="(row, rowIndex) in rows"
                                                :key="rowIndex"
                                                class="hover:bg-gray-50 dark:hover:bg-gray-700 transition cursor-pointer"
                                                :class="{
                                                    'opacity-40 bg-gray-100 dark:bg-gray-900':
                                                        rowIndex + 1 <
                                                        form.start_row,
                                                    'bg-green-50/50 dark:bg-green-900/10':
                                                        rowIndex + 1 ===
                                                        form.start_row,
                                                }"
                                                @click="setStartRow(rowIndex)"
                                                title="Click para establecer como Fila Inicial"
                                            >
                                                <td
                                                    class="px-3 py-2 whitespace-nowrap text-xs text-center font-medium text-gray-500 border-r dark:border-gray-700 sticky left-0 bg-white dark:bg-gray-800"
                                                >
                                                    {{ rowIndex + 1 }}
                                                    <span
                                                        v-if="
                                                            rowIndex + 1 ===
                                                            form.start_row
                                                        "
                                                        class="block text-[9px] text-green-600 font-bold"
                                                        >{{
                                                            $t("INICIO")
                                                        }}</span
                                                    >
                                                </td>
                                                <td
                                                    v-for="(
                                                        cell, colIndex
                                                    ) in row"
                                                    :key="colIndex"
                                                    class="px-3 py-2 whitespace-nowrap text-xs text-gray-700 dark:text-gray-300 border-l dark:border-gray-700"
                                                    :class="{
                                                        'bg-green-50 dark:bg-green-900/20':
                                                            isColumnSelected(
                                                                colIndex,
                                                            ) &&
                                                            getColumnType(
                                                                colIndex,
                                                            ) === 'fecha',
                                                        'bg-blue-50 dark:bg-blue-900/20':
                                                            isColumnSelected(
                                                                colIndex,
                                                            ) &&
                                                            getColumnType(
                                                                colIndex,
                                                            ) === 'descripcion',
                                                        'bg-purple-50 dark:bg-purple-900/20':
                                                            isColumnSelected(
                                                                colIndex,
                                                            ) &&
                                                            getColumnType(
                                                                colIndex,
                                                            ) === 'monto',
                                                        'bg-red-50 dark:bg-red-900/20':
                                                            isColumnSelected(
                                                                colIndex,
                                                            ) &&
                                                            getColumnType(
                                                                colIndex,
                                                            ) === 'cargo',
                                                        'bg-emerald-50 dark:bg-emerald-900/20':
                                                            isColumnSelected(
                                                                colIndex,
                                                            ) &&
                                                            getColumnType(
                                                                colIndex,
                                                            ) === 'abono',
                                                    }"
                                                >
                                                    {{ cell }}
                                                </td>
                                            </tr>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>

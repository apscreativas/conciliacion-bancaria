<script setup>
import Modal from "@/Components/Modal.vue";
import SecondaryButton from "@/Components/SecondaryButton.vue";
import PrimaryButton from "@/Components/PrimaryButton.vue";
import { useForm, router } from "@inertiajs/vue3";
import { ref, reactive } from "vue";
import axios from "axios";

const props = defineProps({
    show: {
        type: Boolean,
        default: false,
    },
});

const emit = defineEmits(["close"]);

const form = useForm({
    files: [],
    statement: null,
    files: [],
    statement: null,
    bank_code: "", // No default hardcoded
});

// Format Form - Removed inline logic
// const formatForm = useForm({...});
// const showFormatForm = ref(false);

const bankFormats = ref([]);

const fetchFormats = async () => {
    try {
        const response = await axios.get(route("bank-formats.list"));
        bankFormats.value = response.data;
    } catch (e) {
        console.error(e);
    }
};

// Fetch on mount/open
import { watch } from "vue";
watch(
    () => props.show,
    (newVal) => {
        if (newVal) {
            fetchFormats().then(() => {
                // Auto-select first format if available and none selected
                if (bankFormats.value.length > 0 && !form.bank_code) {
                    form.bank_code = bankFormats.value[0].id;
                }
            });
        }
    },
);

const goToCreateFormat = () => {
    // Close modal first? Or just navigate.
    // Navigation will unmount this anyway.
    router.visit(route("bank-formats.create"));
};

const uploadState = reactive({
    isProcessing: false,
    currentFileIndex: 0,
    totalFiles: 0,
    successCount: 0,
    errorCount: 0,
    duplicateCount: 0,
    logs: [],
    progressPercentage: 0,
});

const xmlInput = ref(null);
const xlsxInput = ref(null);

const close = () => {
    if (uploadState.isProcessing) return; // Prevent closing while uploading
    form.reset();
    form.clearErrors();
    resetUploadState();
    emit("close");
};

const resetUploadState = () => {
    uploadState.isProcessing = false;
    uploadState.currentFileIndex = 0;
    uploadState.totalFiles = 0;
    uploadState.successCount = 0;
    uploadState.errorCount = 0;
    uploadState.duplicateCount = 0;
    uploadState.logs = [];
    uploadState.progressPercentage = 0;
};

const isDraggingXml = ref(false);
const isDraggingXlsx = ref(false);

const handleXmlChange = (e) => {
    form.files = Array.from(e.target.files);
};

const handleXmlDrop = (e) => {
    isDraggingXml.value = false;
    form.files = Array.from(e.dataTransfer.files);
};

const handleXlsxChange = (e) => {
    form.statement = e.target.files[0] || null;
};

const handleXlsxDrop = (e) => {
    isDraggingXlsx.value = false;
    const files = e.dataTransfer.files;
    if (files.length > 0) {
        form.statement = files[0];
    }
};

const processQueue = async () => {
    uploadState.isProcessing = true;
    uploadState.totalFiles = form.files.length + (form.statement ? 1 : 0);
    uploadState.currentFileIndex = 0;
    uploadState.successCount = 0;
    uploadState.errorCount = 0;
    uploadState.duplicateCount = 0; // Track duplicates separately
    uploadState.logs = [];

    // 1. Process XML Files Sequentially
    for (let i = 0; i < form.files.length; i++) {
        const file = form.files[i];
        uploadState.currentFileIndex = i + 1;
        updateProgress();

        try {
            const formData = new FormData();
            formData.append("files[]", file); // Backend expects array 'files'

            const response = await axios.post(route("upload.store"), formData, {
                headers: {
                    "Content-Type": "multipart/form-data",
                    "X-Requested-With": "XMLHttpRequest",
                },
            });

            if (response.data.success) {
                // Backend now returns 'xml_processed' for Queued items, and 'xml_xml_duplicates' for Duplicates found Sync.
                // We trust these counters.

                if (response.data.results.xml_processed > 0) {
                    uploadState.successCount++;
                } else if (response.data.results.xml_xml_duplicates > 0) {
                    const dupErrors = response.data.results.file_errors;
                    if (dupErrors && dupErrors.length > 0) {
                        dupErrors.forEach((err) => uploadState.logs.push(err));
                    } else {
                        uploadState.logs.push(`Duplicado: ${file.name}`);
                    }
                    uploadState.duplicateCount++;
                } else if (
                    response.data.results.xml_other_errors > 0 ||
                    (response.data.results.file_errors &&
                        response.data.results.file_errors.length > 0)
                ) {
                    // Logic for errors
                    if (
                        response.data.results.file_errors &&
                        response.data.results.file_errors.length > 0
                    ) {
                        response.data.results.file_errors.forEach((err) =>
                            uploadState.logs.push(err),
                        );
                    } else {
                        uploadState.logs.push(`Error: ${file.name}`);
                    }
                    uploadState.errorCount++;
                } else {
                    // Fallback if success=true but no counters (shouldn't happen with new controller logic)
                    // Treat as success/queued?
                    uploadState.successCount++;
                }
            } else {
                uploadState.errorCount++;
                const errors = response.data.results?.file_errors;
                if (errors && errors.length > 0) {
                    errors.forEach((err) => uploadState.logs.push(err));
                } else {
                    uploadState.logs.push(`Falló: ${file.name}`);
                }
            }
        } catch (error) {
            console.error(error);
            uploadState.errorCount++;
            uploadState.logs.push(`Error de servidor: ${file.name}`);
        }

        // Small delay to prevent network congestion
        await new Promise((resolve) => setTimeout(resolve, 50));
    }

    // 2. Process Statement if exists
    if (form.statement) {
        uploadState.currentFileIndex++;
        updateProgress();
        try {
            const formData = new FormData();
            formData.append("statement", form.statement);
            formData.append("bank_code", form.bank_code);

            const response = await axios.post(route("upload.store"), formData, {
                headers: {
                    "Content-Type": "multipart/form-data",
                    "X-Requested-With": "XMLHttpRequest",
                },
            });

            if (response.data.success) {
                const successToast = response.data.toasts.find(
                    (t) => t.type === "success",
                );
                const warningToast = response.data.toasts.find(
                    (t) => t.type === "warning",
                );
                const errorToast = response.data.toasts.find(
                    (t) => t.type === "error",
                );

                if (successToast) {
                    uploadState.successCount++;
                } else if (warningToast) {
                    uploadState.duplicateCount++;
                    uploadState.logs.push(warningToast.message);
                } else if (errorToast) {
                    uploadState.errorCount++;
                    uploadState.logs.push(errorToast.message);
                } else {
                    // Fallback
                    uploadState.errorCount++;
                    uploadState.logs.push(
                        `Estado de Cuenta: Error desconocido`,
                    );
                }
            } else {
                uploadState.errorCount++;
                uploadState.logs.push(`Estado de Cuenta: Falló la solicitud`);
            }
        } catch (error) {
            console.error(error);
            uploadState.errorCount++;

            let errorMsg = error.message;
            if (error.response && error.response.data) {
                // If we sent back specific toasts/results in 422
                if (error.response.data.toasts) {
                    const errorToast = error.response.data.toasts.find(
                        (t) => t.type === "error",
                    );
                    if (errorToast) errorMsg = errorToast.message;
                } else if (error.response.data.message) {
                    errorMsg = error.response.data.message;
                }
            }

            uploadState.logs.push(
                `Error al subir Estado de Cuenta: ${errorMsg}`,
            );
        }
    }

    uploadState.isProcessing = false;

    // Final Summary to User before optional reload
    // We will NOT auto-reload immediately if there are errors/duplicates, so the user can see the report
    // Just refresh data in background
    router.reload({
        only: ["files", "toasts", "flash", "stats", "recentActivity", "errors"],
    });
};

const updateProgress = () => {
    if (uploadState.totalFiles > 0) {
        uploadState.progressPercentage = Math.round(
            (uploadState.currentFileIndex / uploadState.totalFiles) * 100,
        );
    } else {
        uploadState.progressPercentage = 0;
    }
};

const submit = () => {
    processQueue();
};
</script>

<template>
    <Modal :show="show" @close="close">
        <div class="p-6">
            <h2 class="text-lg font-medium text-gray-900 dark:text-gray-100">
                Subir Archivos
            </h2>

            <p class="mt-1 text-sm text-gray-600 dark:text-gray-400">
                Carga tus facturas (XML) y/o tu estado de cuenta (Excel) para
                procesar la conciliación.
            </p>

            <form @submit.prevent="submit" class="mt-6 space-y-6">
                <!-- Facturas Section -->
                <div
                    v-show="
                        !uploadState.isProcessing &&
                        uploadState.totalFiles === 0
                    "
                    class="p-4 bg-gray-50 dark:bg-gray-700/50 rounded-lg border border-gray-200 dark:border-gray-700"
                >
                    <h3
                        class="text-md font-semibold text-gray-800 dark:text-gray-200 mb-2"
                    >
                        Facturas Emitidas / Recibidas
                    </h3>
                    <div class="flex items-center justify-center w-full">
                        <label
                            for="xml-dropzone"
                            class="flex flex-col items-center justify-center w-full h-32 border-2 border-dashed rounded-lg cursor-pointer transition duration-150"
                            :class="[
                                isDraggingXml
                                    ? 'border-blue-500 bg-blue-50 dark:bg-gray-600 dark:border-blue-400'
                                    : 'border-gray-300 bg-gray-50 hover:bg-gray-100 dark:bg-gray-700 dark:hover:bg-gray-600 dark:border-gray-600 dark:hover:border-gray-500',
                            ]"
                            @dragover.prevent="isDraggingXml = true"
                            @dragleave.prevent="isDraggingXml = false"
                            @drop.prevent="handleXmlDrop"
                        >
                            <div
                                class="flex flex-col items-center justify-center pt-5 pb-6 text-center px-4"
                            >
                                <svg
                                    v-if="!form.files.length"
                                    class="w-8 h-8 mb-4 text-gray-500 dark:text-gray-400"
                                    aria-hidden="true"
                                    xmlns="http://www.w3.org/2000/svg"
                                    fill="none"
                                    viewBox="0 0 20 16"
                                >
                                    <path
                                        stroke="currentColor"
                                        stroke-linecap="round"
                                        stroke-linejoin="round"
                                        stroke-width="2"
                                        d="M13 13h3a3 3 0 0 0 0-6h-.025A5.56 5.56 0 0 0 16 6.5 5.5 5.5 0 0 0 5.207 5.021C5.137 5.017 5.071 5 5 5a4 4 0 0 0 0 8h2.167M10 15V6m0 0L8 8m2-2 2 2"
                                    />
                                </svg>
                                <p
                                    v-if="!form.files.length"
                                    class="mb-2 text-sm text-gray-500 dark:text-gray-400"
                                >
                                    <span class="font-semibold"
                                        >Click para subir</span
                                    >
                                    o arrastra tus XML
                                </p>
                                <p
                                    v-else
                                    class="text-sm text-green-600 dark:text-green-400 font-medium"
                                >
                                    {{ form.files.length }} archivo(s)
                                    seleccionado(s)
                                </p>
                                <p
                                    class="text-xs text-gray-500 dark:text-gray-400"
                                >
                                    XML (CFDI 3.3 / 4.0)
                                </p>
                            </div>
                            <input
                                id="xml-dropzone"
                                ref="xmlInput"
                                type="file"
                                class="hidden"
                                multiple
                                accept=".xml"
                                @change="handleXmlChange"
                            />
                        </label>
                    </div>
                    <p
                        v-if="form.errors['files.0']"
                        class="mt-2 text-sm text-red-600"
                    >
                        {{ form.errors["files.0"] }}
                    </p>
                </div>

                <!-- Estado de Cuenta Section -->
                <div
                    v-show="
                        !uploadState.isProcessing &&
                        uploadState.totalFiles === 0
                    "
                    class="p-4 bg-gray-50 dark:bg-gray-700/50 rounded-lg border border-gray-200 dark:border-gray-700"
                >
                    <h3
                        class="text-md font-semibold text-gray-800 dark:text-gray-200 mb-2"
                    >
                        Estado de Cuenta Bancario
                    </h3>

                    <!-- Bank Selection -->
                    <div class="mb-4">
                        <label
                            for="bank"
                            class="block mb-2 text-sm font-medium text-gray-900 dark:text-gray-300"
                            >Selecciona el Banco / Formato</label
                        >
                        <div class="flex gap-2">
                            <select
                                id="bank"
                                v-model="form.bank_code"
                                class="bg-gray-50 border border-gray-300 text-gray-900 text-sm rounded-lg focus:ring-blue-500 focus:border-blue-500 block w-full p-2.5 dark:bg-gray-700 dark:border-gray-600 dark:placeholder-gray-400 dark:text-white dark:focus:ring-blue-500 dark:focus:border-blue-500"
                            >
                                <option value="" disabled>
                                    Selecciona un formato
                                </option>
                                <option
                                    v-for="format in bankFormats"
                                    :key="format.id"
                                    :value="format.id"
                                >
                                    {{ format.name }}
                                </option>
                            </select>
                            <button
                                type="button"
                                @click="goToCreateFormat"
                                class="bg-gray-200 dark:bg-gray-600 hover:bg-gray-300 dark:hover:bg-gray-500 text-gray-700 dark:text-gray-200 font-bold py-2 px-4 rounded"
                                title="Crear Nuevo Formato (Avanzado)"
                            >
                                +
                            </button>
                        </div>
                        <p
                            class="mt-1 text-xs text-gray-500 dark:text-gray-400"
                        >
                            ¿Tu banco no está en la lista? Haz clic en "+" para
                            crear un formato personalizado.
                        </p>

                        <p
                            v-if="form.errors.bank_code"
                            class="mt-2 text-sm text-red-600"
                        >
                            {{ form.errors.bank_code }}
                        </p>
                    </div>
                    <div class="flex items-center justify-center w-full">
                        <label
                            for="xlsx-dropzone"
                            class="flex flex-col items-center justify-center w-full h-32 border-2 border-dashed rounded-lg cursor-pointer transition duration-150"
                            :class="[
                                isDraggingXlsx
                                    ? 'border-blue-500 bg-blue-50 dark:bg-gray-600 dark:border-blue-400'
                                    : 'border-gray-300 bg-gray-50 hover:bg-gray-100 dark:bg-gray-700 dark:hover:bg-gray-600 dark:border-gray-600 dark:hover:border-gray-500',
                            ]"
                            @dragover.prevent="isDraggingXlsx = true"
                            @dragleave.prevent="isDraggingXlsx = false"
                            @drop.prevent="handleXlsxDrop"
                        >
                            <div
                                class="flex flex-col items-center justify-center pt-5 pb-6 text-center px-4"
                            >
                                <svg
                                    v-if="!form.statement"
                                    class="w-8 h-8 mb-4 text-gray-500 dark:text-gray-400"
                                    aria-hidden="true"
                                    xmlns="http://www.w3.org/2000/svg"
                                    fill="none"
                                    viewBox="0 0 20 16"
                                >
                                    <path
                                        stroke="currentColor"
                                        stroke-linecap="round"
                                        stroke-linejoin="round"
                                        stroke-width="2"
                                        d="M13 13h3a3 3 0 0 0 0-6h-.025A5.56 5.56 0 0 0 16 6.5 5.5 5.5 0 0 0 5.207 5.021C5.137 5.017 5.071 5 5 5a4 4 0 0 0 0 8h2.167M10 15V6m0 0L8 8m2-2 2 2"
                                    />
                                </svg>
                                <p
                                    v-if="!form.statement"
                                    class="mb-2 text-sm text-gray-500 dark:text-gray-400"
                                >
                                    <span class="font-semibold"
                                        >Click para subir</span
                                    >
                                    o arrastra tu Excel
                                </p>
                                <p
                                    v-else
                                    class="text-sm text-green-600 dark:text-green-400 font-medium"
                                >
                                    {{ form.statement.name }}
                                </p>
                                <p
                                    class="text-xs text-gray-500 dark:text-gray-400"
                                >
                                    XLSX, XLS, CSV (Formato BBVA)
                                </p>
                            </div>
                            <input
                                id="xlsx-dropzone"
                                ref="xlsxInput"
                                type="file"
                                class="hidden"
                                accept=".xlsx,.xls,.csv"
                                @change="handleXlsxChange"
                            />
                        </label>
                    </div>
                    <p
                        v-if="form.errors.statement"
                        class="mt-2 text-sm text-red-600"
                    >
                        {{ form.errors.statement }}
                    </p>
                </div>

                <!-- Progress & Results -->
                <div v-if="uploadState.totalFiles > 0" class="mt-4">
                    <div class="flex justify-between mb-1">
                        <span
                            class="text-base font-medium text-blue-700 dark:text-white"
                            >Procesando...</span
                        >
                        <span
                            class="text-sm font-medium text-blue-700 dark:text-white"
                            >{{ uploadState.progressPercentage }}%</span
                        >
                    </div>
                    <div
                        class="w-full bg-gray-200 rounded-full h-2.5 dark:bg-gray-700"
                    >
                        <div
                            class="bg-blue-600 h-2.5 rounded-full"
                            :style="{
                                width: uploadState.progressPercentage + '%',
                            }"
                        ></div>
                    </div>
                    <p class="mt-2 text-sm text-gray-600 dark:text-gray-300">
                        Archivo {{ uploadState.currentFileIndex }} de
                        {{ uploadState.totalFiles }}
                    </p>
                    <div class="mt-2 flex space-x-4 text-sm">
                        <span class="text-green-600"
                            >Completados: {{ uploadState.successCount }}</span
                        >
                        <span class="text-yellow-600 dark:text-yellow-500"
                            >Duplicados: {{ uploadState.duplicateCount }}</span
                        >
                        <span class="text-red-500"
                            >Errores: {{ uploadState.errorCount }}</span
                        >
                    </div>

                    <!-- Error Logs -->
                    <div
                        v-if="uploadState.logs.length > 0"
                        class="mt-3 p-2 bg-red-50 dark:bg-red-900/20 rounded border border-red-100 dark:border-red-900 overflow-y-auto max-h-32 text-xs text-red-600 dark:text-red-400"
                    >
                        <p
                            v-for="(log, index) in uploadState.logs"
                            :key="index"
                        >
                            {{ log }}
                        </p>
                    </div>
                </div>

                <div
                    v-if="
                        !uploadState.isProcessing &&
                        uploadState.totalFiles > 0 &&
                        uploadState.errorCount === 0
                    "
                    class="p-3 bg-green-100 text-green-800 rounded-md text-sm mt-4"
                >
                    Carga completada.
                </div>

                <div class="mt-6 flex justify-end">
                    <SecondaryButton
                        v-if="
                            uploadState.isProcessing ||
                            uploadState.totalFiles === 0
                        "
                        @click="close"
                        class="mr-3"
                        :disabled="uploadState.isProcessing"
                    >
                        {{
                            uploadState.isProcessing
                                ? "Procesando..."
                                : "Cancelar"
                        }}
                    </SecondaryButton>

                    <PrimaryButton
                        v-if="
                            !uploadState.isProcessing &&
                            uploadState.totalFiles === 0
                        "
                        :class="{
                            'opacity-25':
                                (!form.files.length && !form.statement) ||
                                (form.statement && !form.bank_code),
                        }"
                        :disabled="
                            (!form.files.length && !form.statement) ||
                            (form.statement && !form.bank_code)
                        "
                    >
                        Iniciar Carga
                    </PrimaryButton>
                    <PrimaryButton
                        v-else-if="
                            !uploadState.isProcessing &&
                            uploadState.totalFiles > 0
                        "
                        @click="close"
                    >
                        Cerrar
                    </PrimaryButton>
                </div>
            </form>
        </div>
    </Modal>
</template>

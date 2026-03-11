<script setup lang="ts">
import { Link } from "@inertiajs/vue3";
import { computed } from "vue";

const props = defineProps<{
    files: {
        data: Array<{
            id: number;
            path: string;
            original_name?: string;
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
            };
        }>;
        links: Array<any>;
    };
    sortColumn?: string;
    sortDirection?: string;
    selectedIds: number[];
    perPage: string | number;
}>();

const emit = defineEmits([
    "sort",
    "toggle-select",
    "toggle-all",
    "delete",
    "view",
    "update-per-page",
]);

const selectAll = computed({
    get: () =>
        props.files.data.length > 0 &&
        props.selectedIds.length === props.files.data.length,
    set: (val) => {
        emit("toggle-all", val);
    },
});

const formatSemDate = (date?: string) => {
    if (!date) return "N/A";
    const d = new Date(date);
    const userTimezoneOffset = d.getTimezoneOffset() * 60000;
    const adjustedDate = new Date(d.getTime() + userTimezoneOffset);
    return adjustedDate.toLocaleDateString("es-MX");
};

const formatCurrency = (amount: number) => {
    return new Intl.NumberFormat("es-MX", {
        style: "currency",
        currency: "MXN",
    }).format(amount);
};

const tipoLabel = (factura?: { tipo_comprobante?: string; metodo_pago?: string }) => {
    if (!factura) return 'N/A';
    if (factura.tipo_comprobante === 'P') return 'Complemento';
    if (factura.metodo_pago === 'PUE') return 'PUE';
    return factura.metodo_pago || 'N/A';
};

const tipoClass = (factura?: { tipo_comprobante?: string; metodo_pago?: string }) => {
    if (!factura) return 'bg-gray-100 text-gray-600 border-gray-300';
    if (factura.tipo_comprobante === 'P') return 'bg-purple-100 text-purple-700 border-purple-400';
    if (factura.metodo_pago === 'PUE') return 'bg-blue-100 text-blue-700 border-blue-400';
    return 'bg-gray-100 text-gray-600 border-gray-300';
};

const fileExtension = (name?: string) => {
    if (!name) return '';
    const parts = name.split('.');
    return parts.length > 1 ? '.' + parts.pop() : '';
};
</script>

<template>
    <div>
        <div
            v-if="files.data.length === 0"
            class="text-center py-8 text-gray-500"
        >
            {{ $t("No se han cargado facturas aún.") }}
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
                            {{ $t("RECEPTOR (RFC)") }}
                        </th>
                        <th scope="col" class="py-3 px-6">
                            {{ $t("NOMBRE") }}
                        </th>
                        <th
                            scope="col"
                            class="px-6 py-3 cursor-pointer hover:bg-gray-100 dark:hover:bg-gray-700"
                            @click="emit('sort', 'total')"
                        >
                            <div class="flex items-center gap-1">
                                {{ $t("TOTAL") }}
                                <span v-if="sortColumn === 'total'">
                                    {{ sortDirection === "asc" ? "↑" : "↓" }}
                                </span>
                            </div>
                        </th>
                        <th
                            scope="col"
                            class="px-6 py-3 cursor-pointer hover:bg-gray-100 dark:hover:bg-gray-700"
                            @click="emit('sort', 'fecha_emision')"
                        >
                            <div class="flex items-center gap-1">
                                {{ $t("FECHA EMISIÓN") }}
                                <span v-if="sortColumn === 'fecha_emision'">
                                    {{ sortDirection === "asc" ? "↑" : "↓" }}
                                </span>
                            </div>
                        </th>
                        <th
                            scope="col"
                            class="px-6 py-3 cursor-pointer hover:bg-gray-100 dark:hover:bg-gray-700"
                            @click="emit('sort', 'tipo')"
                        >
                            <div class="flex items-center gap-1">
                                {{ $t("TIPO") }}
                                <span v-if="sortColumn === 'tipo'">
                                    {{ sortDirection === "asc" ? "↑" : "↓" }}
                                </span>
                            </div>
                        </th>
                        <th scope="col" class="py-3 px-6">
                            {{ $t("ARCHIVO") }}
                        </th>
                        <th scope="col" class="py-3 px-6">
                            {{ $t("ESTADO") }}
                        </th>
                        <th scope="col" class="py-3 px-6 text-right">
                            {{ $t("ACCIONES") }}
                        </th>
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="file in files.data"
                        :key="file.id"
                        class="bg-white border-b dark:bg-gray-800 dark:border-gray-700 hover:bg-gray-50 dark:hover:bg-gray-600"
                    >
                        <td class="p-4 w-4">
                            <div class="flex items-center">
                                <input
                                    type="checkbox"
                                    :checked="selectedIds.includes(file.id)"
                                    @change="emit('toggle-select', file.id)"
                                    class="rounded border-gray-300 text-indigo-600 shadow-sm focus:ring-indigo-500 dark:border-gray-700 dark:bg-gray-900 dark:focus:ring-indigo-600 dark:focus:ring-offset-gray-800"
                                />
                            </div>
                        </td>
                        <td class="py-4 px-6">
                            {{ file.factura?.rfc || "N/A" }}
                        </td>
                        <td class="py-4 px-6">
                            {{ file.factura?.nombre || "N/A" }}
                        </td>
                        <td class="py-4 px-6 font-mono font-medium">
                            {{
                                file.factura?.monto
                                    ? formatCurrency(Number(file.factura.monto))
                                    : "N/A"
                            }}
                        </td>
                        <td class="py-4 px-6">
                            {{ formatSemDate(file.factura?.fecha_emision) }}
                        </td>
                        <td class="py-4 px-6">
                            <span
                                class="text-xs font-semibold px-2.5 py-0.5 rounded border"
                                :class="tipoClass(file.factura)"
                            >
                                {{ tipoLabel(file.factura) }}
                            </span>
                        </td>
                        <td class="py-4 px-6">
                            <span
                                v-if="file.original_name"
                                class="text-xs text-gray-500 dark:text-gray-400 truncate max-w-[150px] inline-block"
                                :title="file.original_name"
                            >
                                {{ file.original_name }}
                            </span>
                            <span v-else class="text-xs text-gray-400">—</span>
                        </td>
                        <td class="py-4 px-6">
                            <span
                                v-if="
                                    file.factura?.conciliaciones_count &&
                                    file.factura.conciliaciones_count > 0
                                "
                                class="bg-green-100 text-green-800 text-xs font-semibold px-2.5 py-0.5 rounded dark:bg-green-200 dark:text-green-900 border border-green-400"
                            >
                                {{ $t("CONCILIADO") }}
                            </span>
                            <span
                                v-else
                                class="bg-yellow-100 text-yellow-800 text-xs font-semibold px-2.5 py-0.5 rounded dark:bg-yellow-200 dark:text-yellow-900 border border-yellow-400"
                            >
                                {{ $t("PENDIENTE") }}
                            </span>
                        </td>
                        <td class="py-4 px-6 text-right">
                            <button
                                @click="emit('view', file)"
                                class="font-medium text-blue-600 dark:text-blue-400 hover:underline"
                            >
                                {{ $t("Ver") }}
                            </button>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <!-- Pagination -->
        <!-- Pagination -->
        <div
            class="mt-4 flex flex-col md:flex-row justify-between items-center gap-4"
        >
            <div v-if="files.links.length > 3" class="flex flex-wrap -mb-1">
                <template v-for="(link, key) in files.links" :key="key">
                    <div
                        v-if="link.url === null"
                        class="mr-1 mb-1 px-4 py-3 text-sm leading-4 text-gray-400 border rounded"
                        v-html="link.label"
                    />
                    <Link
                        v-else
                        class="mr-1 mb-1 px-4 py-3 text-sm leading-4 border rounded hover:bg-white focus:border-indigo-500 focus:text-indigo-500 dark:text-gray-300 dark:border-gray-600 dark:hover:bg-gray-700"
                        :class="{
                            'bg-blue-700 text-white dark:bg-blue-600':
                                link.active,
                        }"
                        :href="link.url"
                        v-html="link.label"
                    />
                </template>
            </div>

            <div class="flex items-center gap-2">
                <label class="text-sm text-gray-500 dark:text-gray-400">{{
                    $t("Mostrar:")
                }}</label>
                <select
                    :value="perPage"
                    @change="
                        emit(
                            'update-per-page',
                            ($event.target as HTMLSelectElement).value,
                        )
                    "
                    class="bg-gray-50 border border-gray-300 text-gray-900 text-sm rounded-lg focus:ring-blue-500 focus:border-blue-500 block p-2.5 dark:bg-gray-700 dark:border-gray-600 dark:placeholder-gray-400 dark:text-white dark:focus:ring-blue-500 dark:focus:border-blue-500"
                >
                    <option value="10">10</option>
                    <option value="25">25</option>
                    <option value="50">50</option>
                    <option value="all">{{ $t("Todos") }}</option>
                </select>
            </div>
        </div>
    </div>
</template>

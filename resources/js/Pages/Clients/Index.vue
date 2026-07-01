<script setup lang="ts">
import AuthenticatedLayout from "@/Layouts/AuthenticatedLayout.vue";
import EmptyState from "@/Components/EmptyState.vue";
import PrimaryButton from "@/Components/PrimaryButton.vue";
import { Head, router } from "@inertiajs/vue3";
import { formatDate } from "@/utils/format";

interface Empresa {
    id: number;
    nombre: string;
    color: string | null;
}

interface CatalogoRow {
    id: number;
    rfc: string;
    nombre: string;
    empresa: Empresa | null;
    excluido: boolean;
    veces: number;
    ultima_asignacion_at: string | null;
}

interface RecurrenteRow {
    rfc: string;
    nombre: string;
    ultima_fecha: string;
    conteo: number;
    meses_facturados: number;
    recurrente: boolean;
    sin_factura_mes_actual: boolean;
    empresa: Empresa | null;
}

defineProps<{
    catalogo: CatalogoRow[];
    empresas: Empresa[];
    recurrentes: RecurrenteRow[];
}>();

// Chip de color de empresa (fondo 15, borde 30), mismo patrón que la conciliación.
const badgeStyle = (color: string | null) => {
    const c = color || "#9ca3af";
    return { backgroundColor: c + "15", color: c, borderColor: c + "30" };
};

const assignEmpresa = (row: CatalogoRow, event: Event) => {
    const value = (event.target as HTMLSelectElement).value;
    router.patch(
        route("clients.update", row.id),
        { empresa_id: value === "" ? null : Number(value) },
        { preserveScroll: true },
    );
};

// "Respetar etiquetas individuales": excluye al cliente del catálogo (no aprende,
// no sugiere, no se aplica). El mapeo de empresa queda inerte hasta des-excluir.
const toggleExcluido = (row: CatalogoRow) => {
    router.patch(
        route("clients.update", row.id),
        { excluido: !row.excluido },
        { preserveScroll: true },
    );
};

const aplicarCatalogo = () => {
    router.post(route("clients.apply"), {}, { preserveScroll: true });
};
</script>

<template>
    <Head :title="$t('Clientes')" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex justify-between items-center">
                <h2 class="font-semibold text-xl text-gray-800 dark:text-gray-200 leading-tight">
                    {{ $t("Clientes") }}
                </h2>
                <PrimaryButton @click="aplicarCatalogo">
                    {{ $t("Aplicar catálogo a conciliaciones sin empresa") }}
                </PrimaryButton>
            </div>
        </template>

        <div class="py-12">
            <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-8">
                <!-- Facturación recurrente / dejó de facturar -->
                <div class="bg-white dark:bg-gray-800 overflow-hidden shadow-sm sm:rounded-lg">
                    <div class="px-6 py-4 border-b border-gray-200 dark:border-gray-700">
                        <h3 class="font-semibold text-gray-800 dark:text-gray-200">
                            {{ $t("Facturación recurrente") }}
                        </h3>
                        <p class="text-sm text-gray-500 dark:text-gray-400 mt-1">
                            {{ $t("Clientes que facturan cada mes; se resaltan los que no facturaron este mes.") }}
                        </p>
                    </div>

                    <EmptyState
                        v-if="recurrentes.length === 0"
                        :title="$t('Sin clientes recurrentes')"
                        :description="$t('Aún no hay clientes con facturación mensual constante.')"
                    />
                    <table v-else class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                        <thead class="bg-gray-50 dark:bg-gray-900/50">
                            <tr>
                                <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase">{{ $t("Cliente") }}</th>
                                <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase">{{ $t("Empresa") }}</th>
                                <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase">{{ $t("Última factura") }}</th>
                                <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase">{{ $t("Estado") }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-200 dark:divide-gray-700">
                            <tr
                                v-for="row in recurrentes"
                                :key="row.rfc"
                                :class="row.sin_factura_mes_actual ? 'bg-red-50 dark:bg-red-900/20' : ''"
                            >
                                <td class="px-4 py-3 text-sm text-gray-900 dark:text-gray-100">
                                    <div class="font-medium">{{ row.nombre }}</div>
                                    <div class="text-xs text-gray-400 font-mono">{{ row.rfc }}</div>
                                </td>
                                <td class="px-4 py-3 whitespace-nowrap">
                                    <span
                                        v-if="row.empresa"
                                        class="text-[10px] font-bold px-2 py-0.5 rounded border inline-block"
                                        :style="badgeStyle(row.empresa.color)"
                                    >{{ row.empresa.nombre }}</span>
                                    <span v-else class="text-xs text-gray-400">{{ $t("Sin asignar") }}</span>
                                </td>
                                <td class="px-4 py-3 whitespace-nowrap text-sm text-gray-600 dark:text-gray-400">
                                    {{ formatDate(row.ultima_fecha) }}
                                </td>
                                <td class="px-4 py-3 whitespace-nowrap text-sm">
                                    <span
                                        v-if="row.sin_factura_mes_actual"
                                        class="text-[11px] font-bold px-2 py-0.5 rounded bg-red-100 text-red-700 dark:bg-red-900/40 dark:text-red-300"
                                    >{{ $t("No facturó este mes") }}</span>
                                    <span
                                        v-else
                                        class="text-[11px] font-bold px-2 py-0.5 rounded bg-green-100 text-green-700 dark:bg-green-900/40 dark:text-green-300"
                                    >{{ $t("Al corriente") }}</span>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <!-- Catálogo de clientes -->
                <div class="bg-white dark:bg-gray-800 overflow-hidden shadow-sm sm:rounded-lg">
                    <div class="px-6 py-4 border-b border-gray-200 dark:border-gray-700">
                        <h3 class="font-semibold text-gray-800 dark:text-gray-200">
                            {{ $t("Catálogo de clientes") }}
                        </h3>
                        <p class="text-sm text-gray-500 dark:text-gray-400 mt-1">
                            {{ $t("Empresa por defecto que se asigna al conciliar ingresos de cada cliente.") }}
                        </p>
                    </div>

                    <EmptyState
                        v-if="catalogo.length === 0"
                        :title="$t('Catálogo vacío')"
                        :description="$t('El catálogo se aprende solo al asignar empresas en la conciliación.')"
                    />
                    <table v-else class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                        <thead class="bg-gray-50 dark:bg-gray-900/50">
                            <tr>
                                <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase">{{ $t("RFC") }}</th>
                                <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase">{{ $t("Cliente") }}</th>
                                <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase">{{ $t("Empresa por defecto") }}</th>
                                <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase">{{ $t("Respetar etiquetas") }}</th>
                                <th class="px-4 py-3 text-right text-xs font-semibold text-gray-500 uppercase">{{ $t("Veces") }}</th>
                                <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase">{{ $t("Última asignación") }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-200 dark:divide-gray-700">
                            <tr v-for="row in catalogo" :key="row.id">
                                <td class="px-4 py-3 whitespace-nowrap text-sm font-mono text-gray-600 dark:text-gray-400">{{ row.rfc }}</td>
                                <td class="px-4 py-3 text-sm text-gray-900 dark:text-gray-100 font-medium">{{ row.nombre }}</td>
                                <td class="px-4 py-3 whitespace-nowrap">
                                    <div class="flex items-center gap-2" :class="row.excluido ? 'opacity-50' : ''">
                                        <span
                                            v-if="row.empresa"
                                            class="text-[10px] font-bold px-2 py-0.5 rounded border inline-block"
                                            :style="badgeStyle(row.empresa.color)"
                                        >{{ row.empresa.nombre }}</span>
                                        <select
                                            :value="row.empresa?.id ?? ''"
                                            @change="assignEmpresa(row, $event)"
                                            :title="row.excluido ? $t('La empresa por defecto no se usa mientras el cliente esté excluido') : $t('Empresa por defecto')"
                                            class="text-xs border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300 rounded-md shadow-sm focus:border-indigo-500 focus:ring-indigo-500 py-1"
                                        >
                                            <option value="">{{ $t("Sin asignar") }}</option>
                                            <option v-for="e in empresas" :key="e.id" :value="e.id">{{ e.nombre }}</option>
                                        </select>
                                    </div>
                                </td>
                                <td class="px-4 py-3 whitespace-nowrap text-sm">
                                    <label
                                        class="inline-flex items-center gap-2 cursor-pointer"
                                        :title="$t('No aprender ni sugerir empresa para este cliente; cada conciliación se etiqueta a mano.')"
                                    >
                                        <input
                                            type="checkbox"
                                            :checked="row.excluido"
                                            @change="toggleExcluido(row)"
                                            class="rounded border-gray-300 dark:border-gray-700 text-indigo-600 focus:ring-indigo-500"
                                        />
                                        <span class="text-xs text-gray-500">{{ $t("Respetar etiquetas") }}</span>
                                    </label>
                                </td>
                                <td class="px-4 py-3 whitespace-nowrap text-right text-sm text-gray-700 dark:text-gray-300">{{ row.veces }}</td>
                                <td class="px-4 py-3 whitespace-nowrap text-sm text-gray-500 dark:text-gray-400">
                                    {{ row.ultima_asignacion_at ? formatDate(row.ultima_asignacion_at) : "—" }}
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>

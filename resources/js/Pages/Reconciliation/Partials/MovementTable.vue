<script setup lang="ts">
import { Link } from "@inertiajs/vue3";

defineProps<{
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
    perPage: string | number;
    sortBy?: string;
    sortOrder?: string;
}>();

const emit = defineEmits(["update-per-page", "sort-change"]);

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

const formatCurrency = (amount: number) => {
    return new Intl.NumberFormat("es-MX", {
        style: "currency",
        currency: "MXN",
    }).format(amount);
};
</script>

<template>
    <div>
        <div
            v-if="movements.data.length === 0"
            class="text-center py-8 text-gray-500"
        >
            {{ $t("No se encontraron movimientos en este periodo.") }}
        </div>
        <div v-else>
            <div class="overflow-x-auto relative">
                <table
                    class="w-full text-sm text-left text-gray-500 dark:text-gray-400"
                >
                    <thead
                        class="text-xs text-gray-700 uppercase bg-gray-50 dark:bg-gray-700 dark:text-gray-400"
                    >
                        <tr>
                            <th
                                class="py-3 px-6 cursor-pointer hover:text-gray-900 dark:hover:text-gray-200"
                                @click="emit('sort-change', 'bank')"
                            >
                                <div class="flex items-center">
                                    {{ $t("BANCO") }}
                                    <span v-if="sortBy === 'bank'" class="ml-1">
                                        <template v-if="sortOrder === 'asc'"
                                            >↑</template
                                        >
                                        <template v-else>↓</template>
                                    </span>
                                </div>
                            </th>
                            <th
                                class="py-3 px-6 cursor-pointer hover:text-gray-900 dark:hover:text-gray-200"
                                @click="emit('sort-change', 'fecha')"
                            >
                                <div class="flex items-center">
                                    {{ $t("FECHA") }}
                                    <span
                                        v-if="sortBy === 'fecha'"
                                        class="ml-1"
                                    >
                                        <template v-if="sortOrder === 'asc'"
                                            >↑</template
                                        >
                                        <template v-else>↓</template>
                                    </span>
                                </div>
                            </th>
                            <th class="py-3 px-6">{{ $t("DESCRIPCIÓN") }}</th>
                            <th class="py-3 px-6">{{ $t("TIPO") }}</th>
                            <th class="py-3 px-6">{{ $t("ESTADO") }}</th>
                            <th
                                class="py-3 px-6 text-right cursor-pointer hover:text-gray-900 dark:hover:text-gray-200"
                                @click="emit('sort-change', 'monto')"
                            >
                                <div class="flex items-center justify-end">
                                    {{ $t("MONTO") }}
                                    <span
                                        v-if="sortBy === 'monto'"
                                        class="ml-1"
                                    >
                                        <template v-if="sortOrder === 'asc'"
                                            >↑</template
                                        >
                                        <template v-else>↓</template>
                                    </span>
                                </div>
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="mov in movements.data"
                            :key="mov.id"
                            class="bg-white border-b dark:bg-gray-800 dark:border-gray-700 hover:bg-gray-50 dark:hover:bg-gray-700"
                        >
                            <td class="py-4 px-6">
                                <div class="flex flex-col">
                                    <span
                                        class="text-[10px] font-bold px-1.5 py-0.5 rounded border inline-block w-fit mb-1"
                                        :style="
                                            mov.archivo?.bank_format?.color
                                                ? {
                                                      backgroundColor:
                                                          mov.archivo
                                                              .bank_format
                                                              .color + '15',
                                                      color: mov.archivo
                                                          .bank_format.color,
                                                      borderColor:
                                                          mov.archivo
                                                              .bank_format
                                                              .color + '30',
                                                  }
                                                : {}
                                        "
                                        :class="{
                                            'text-indigo-600':
                                                !mov.archivo?.bank_format
                                                    ?.color,
                                        }"
                                    >
                                        {{
                                            mov.archivo?.bank_format?.name ||
                                            mov.archivo?.banco?.nombre ||
                                            "N/A"
                                        }}
                                    </span>
                                    <span class="text-[10px] text-gray-400">{{
                                        mov.archivo?.original_name || "Archivo"
                                    }}</span>
                                </div>
                            </td>
                            <td class="py-4 px-6 whitespace-nowrap">
                                {{ formatDateNoTime(mov.fecha) }}
                            </td>
                            <td
                                class="py-4 px-6 min-w-[300px] whitespace-normal break-words"
                            >
                                {{ mov.descripcion }}
                            </td>
                            <td class="py-4 px-6">
                                <span
                                    :class="
                                        mov.tipo === 'abono'
                                            ? 'bg-green-100 text-green-800'
                                            : 'bg-red-100 text-red-800'
                                    "
                                    class="text-xs font-medium px-2.5 py-0.5 rounded"
                                >
                                    {{
                                        mov.tipo === "abono"
                                            ? $t("Abono")
                                            : $t("Cargo")
                                    }}
                                </span>
                            </td>
                            <td class="py-4 px-6">
                                <span
                                    v-if="
                                        mov.conciliaciones_count &&
                                        mov.conciliaciones_count > 0
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
                            <td
                                class="py-4 px-6 text-right font-mono"
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

            <!-- Pagination -->
            <div
                class="mt-4 flex flex-col md:flex-row justify-between items-center gap-4"
            >
                <div
                    v-if="movements.links.length > 3"
                    class="flex flex-wrap -mb-1"
                >
                    <template v-for="(link, key) in movements.links" :key="key">
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
                            preserve-state
                            preserve-scroll
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
    </div>
</template>

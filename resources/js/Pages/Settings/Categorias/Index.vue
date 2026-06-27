<script setup lang="ts">
import AuthenticatedLayout from "@/Layouts/AuthenticatedLayout.vue";
import { Head, Link, router, usePage } from "@inertiajs/vue3";
import Modal from "@/Components/Modal.vue";
import SecondaryButton from "@/Components/SecondaryButton.vue";
import DangerButton from "@/Components/DangerButton.vue";
import PrimaryButton from "@/Components/PrimaryButton.vue";
import EmptyState from "@/Components/EmptyState.vue";
import { computed, ref } from "vue";

interface Categoria {
    id: number;
    nombre: string;
    tipo: "ingreso" | "egreso";
    grupo: string;
    naturaleza: "fijo" | "variable" | null;
    activo: boolean;
    orden: number;
}

defineProps<{
    categorias: Categoria[];
}>();

const page = usePage();
const isOwner = computed(() => {
    const user: any = page.props.auth.user;
    return user.current_team && user.current_team.user_id === user.id;
});

const grupoLabels: Record<string, string> = {
    ingreso: "Ingreso",
    costo_venta: "Costo de venta",
    gasto_operativo: "Gasto operativo",
    abajo_ebitda: "Abajo de EBITDA",
};

const confirmingDeletion = ref(false);
const toDelete = ref<Categoria | null>(null);

const confirmDeletion = (categoria: Categoria) => {
    toDelete.value = categoria;
    confirmingDeletion.value = true;
};

const closeModal = () => {
    confirmingDeletion.value = false;
    toDelete.value = null;
};

const destroy = () => {
    if (!toDelete.value) return;
    router.delete(route("settings.categorias.destroy", toDelete.value.id), {
        preserveScroll: true,
        onSuccess: () => closeModal(),
    });
};
</script>

<template>
    <Head :title="$t('Categorías')" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex justify-between items-center">
                <h2 class="font-semibold text-xl text-gray-800 dark:text-gray-200 leading-tight">
                    {{ $t('Categorías') }}
                </h2>
                <Link v-if="isOwner" :href="route('settings.categorias.create')">
                    <PrimaryButton>{{ $t('Crear Categoría') }}</PrimaryButton>
                </Link>
            </div>
        </template>

        <div class="py-12">
            <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
                <div class="mb-6">
                    <p class="text-gray-500 dark:text-gray-400 text-sm">
                        {{ $t('Catálogo de cuentas que arma el Estado de Resultados (ingresos y egresos).') }}
                    </p>
                </div>

                <div class="bg-white dark:bg-gray-800 overflow-hidden shadow-sm sm:rounded-lg">
                    <EmptyState
                        v-if="categorias.length === 0"
                        :title="$t('No hay categorías registradas.')"
                        :description="$t('Crea categorías para clasificar ingresos y egresos.')"
                    />

                    <table v-else class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                        <thead class="bg-gray-50 dark:bg-gray-900/50">
                            <tr>
                                <th class="px-6 py-3 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider">{{ $t('Categoría') }}</th>
                                <th class="px-6 py-3 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider">{{ $t('Tipo') }}</th>
                                <th class="px-6 py-3 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider">{{ $t('Grupo') }}</th>
                                <th class="px-6 py-3 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider">{{ $t('Naturaleza') }}</th>
                                <th class="px-6 py-3 text-right text-xs font-semibold text-gray-500 uppercase tracking-wider">{{ $t('Acciones') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-200 dark:divide-gray-700">
                            <tr v-for="categoria in categorias" :key="categoria.id">
                                <td class="px-6 py-4 whitespace-nowrap font-medium text-gray-900 dark:text-gray-100">{{ categoria.nombre }}</td>
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <span
                                        class="inline-flex px-2 text-xs font-semibold rounded-full"
                                        :class="categoria.tipo === 'ingreso' ? 'bg-green-100 text-green-800 dark:bg-green-900/40 dark:text-green-300' : 'bg-red-100 text-red-800 dark:bg-red-900/40 dark:text-red-300'"
                                    >
                                        {{ $t(categoria.tipo === 'ingreso' ? 'Ingreso' : 'Egreso') }}
                                    </span>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-600 dark:text-gray-400">{{ $t(grupoLabels[categoria.grupo] ?? categoria.grupo) }}</td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-600 dark:text-gray-400">{{ categoria.naturaleza ? $t(categoria.naturaleza === 'fijo' ? 'Fijo' : 'Variable') : '—' }}</td>
                                <td class="px-6 py-4 whitespace-nowrap text-right text-sm">
                                    <template v-if="isOwner">
                                        <Link :href="route('settings.categorias.edit', categoria.id)" class="text-indigo-600 hover:text-indigo-900 dark:text-indigo-400">{{ $t('Editar') }}</Link>
                                        <button @click="confirmDeletion(categoria)" class="ml-4 text-red-600 hover:text-red-900 dark:text-red-400">{{ $t('Eliminar') }}</button>
                                    </template>
                                    <span v-else class="text-gray-400">—</span>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <Modal :show="confirmingDeletion" @close="closeModal">
            <div class="p-6">
                <h2 class="text-lg font-medium text-gray-900 dark:text-gray-100">
                    {{ $t('¿Eliminar esta categoría?') }}
                </h2>
                <p class="mt-1 text-sm text-gray-600 dark:text-gray-400">
                    {{ $t('Esta acción es irreversible.') }}
                </p>
                <div class="mt-6 flex justify-end">
                    <SecondaryButton @click="closeModal">{{ $t('Cancelar') }}</SecondaryButton>
                    <DangerButton class="ml-3" @click="destroy">{{ $t('Eliminar') }}</DangerButton>
                </div>
            </div>
        </Modal>
    </AuthenticatedLayout>
</template>

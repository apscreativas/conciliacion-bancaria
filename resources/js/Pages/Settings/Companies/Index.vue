<script setup lang="ts">
import AuthenticatedLayout from "@/Layouts/AuthenticatedLayout.vue";
import { Head, Link, router, usePage } from "@inertiajs/vue3";
import Modal from "@/Components/Modal.vue";
import SecondaryButton from "@/Components/SecondaryButton.vue";
import DangerButton from "@/Components/DangerButton.vue";
import PrimaryButton from "@/Components/PrimaryButton.vue";
import EmptyState from "@/Components/EmptyState.vue";
import { computed, ref } from "vue";

interface Empresa {
    id: number;
    nombre: string;
    slug: string;
    color: string | null;
    activo: boolean;
    orden: number;
}

defineProps<{
    empresas: Empresa[];
}>();

const page = usePage();
const isOwner = computed(() => {
    const user: any = page.props.auth.user;
    return user.current_team && user.current_team.user_id === user.id;
});

const confirmingDeletion = ref(false);
const toDelete = ref<Empresa | null>(null);

const confirmDeletion = (empresa: Empresa) => {
    toDelete.value = empresa;
    confirmingDeletion.value = true;
};

const closeModal = () => {
    confirmingDeletion.value = false;
    toDelete.value = null;
};

const destroy = () => {
    if (!toDelete.value) return;
    router.delete(route("settings.companies.destroy", toDelete.value.id), {
        preserveScroll: true,
        onSuccess: () => closeModal(),
    });
};
</script>

<template>
    <Head :title="$t('Empresas')" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex justify-between items-center">
                <h2 class="font-semibold text-xl text-gray-800 dark:text-gray-200 leading-tight">
                    {{ $t('Empresas') }}
                </h2>
                <Link v-if="isOwner" :href="route('settings.companies.create')">
                    <PrimaryButton>{{ $t('Crear Empresa') }}</PrimaryButton>
                </Link>
            </div>
        </template>

        <div class="py-12">
            <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
                <div class="mb-6">
                    <p class="text-gray-500 dark:text-gray-400 text-sm">
                        {{ $t('Unidades de negocio del grupo. Se usan para clasificar ingresos y egresos por empresa.') }}
                    </p>
                </div>

                <div class="bg-white dark:bg-gray-800 overflow-hidden shadow-sm sm:rounded-lg">
                    <EmptyState
                        v-if="empresas.length === 0"
                        :title="$t('No hay empresas registradas.')"
                        :description="$t('Crea la primera unidad de negocio para empezar a clasificar movimientos.')"
                    />

                    <table v-else class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                        <thead class="bg-gray-50 dark:bg-gray-900/50">
                            <tr>
                                <th class="px-6 py-3 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider">{{ $t('Empresa') }}</th>
                                <th class="px-6 py-3 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider">{{ $t('Estado') }}</th>
                                <th class="px-6 py-3 text-right text-xs font-semibold text-gray-500 uppercase tracking-wider">{{ $t('Acciones') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-200 dark:divide-gray-700">
                            <tr v-for="empresa in empresas" :key="empresa.id">
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <div class="flex items-center gap-3">
                                        <span class="w-3 h-3 rounded-full" :style="{ backgroundColor: empresa.color || '#9ca3af' }"></span>
                                        <span class="font-medium text-gray-900 dark:text-gray-100">{{ empresa.nombre }}</span>
                                    </div>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <span
                                        class="inline-flex px-2 text-xs font-semibold rounded-full"
                                        :class="empresa.activo ? 'bg-green-100 text-green-800 dark:bg-green-900/40 dark:text-green-300' : 'bg-gray-100 text-gray-600 dark:bg-gray-700 dark:text-gray-400'"
                                    >
                                        {{ empresa.activo ? $t('Activo') : $t('Inactivo') }}
                                    </span>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-right text-sm">
                                    <template v-if="isOwner">
                                        <Link :href="route('settings.companies.edit', empresa.id)" class="text-indigo-600 hover:text-indigo-900 dark:text-indigo-400">{{ $t('Editar') }}</Link>
                                        <button @click="confirmDeletion(empresa)" class="ml-4 text-red-600 hover:text-red-900 dark:text-red-400">{{ $t('Eliminar') }}</button>
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
                    {{ $t('¿Eliminar esta empresa?') }}
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

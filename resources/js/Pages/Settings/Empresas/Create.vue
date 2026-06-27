<script setup lang="ts">
import AuthenticatedLayout from "@/Layouts/AuthenticatedLayout.vue";
import { Head, Link, useForm } from "@inertiajs/vue3";
import PrimaryButton from "@/Components/PrimaryButton.vue";
import SecondaryButton from "@/Components/SecondaryButton.vue";
import InputLabel from "@/Components/InputLabel.vue";
import TextInput from "@/Components/TextInput.vue";
import InputError from "@/Components/InputError.vue";
import { computed } from "vue";

interface Empresa {
    id: number;
    nombre: string;
    color: string | null;
    activo: boolean;
    orden: number;
}

const props = defineProps<{
    empresa?: Empresa;
}>();

const isEdit = computed(() => !!props.empresa);

const form = useForm({
    nombre: props.empresa?.nombre ?? "",
    color: props.empresa?.color ?? "#6366f1",
    activo: props.empresa?.activo ?? true,
    orden: props.empresa?.orden ?? 0,
});

const submit = () => {
    if (isEdit.value) {
        form.put(route("settings.empresas.update", props.empresa!.id));
    } else {
        form.post(route("settings.empresas.store"));
    }
};
</script>

<template>
    <Head :title="isEdit ? $t('Editar Empresa') : $t('Crear Empresa')" />

    <AuthenticatedLayout>
        <template #header>
            <h2 class="font-semibold text-xl text-gray-800 dark:text-gray-200 leading-tight">
                {{ isEdit ? $t('Editar Empresa') : $t('Crear Empresa') }}
            </h2>
        </template>

        <div class="py-12">
            <div class="max-w-xl mx-auto sm:px-6 lg:px-8">
                <div class="bg-white dark:bg-gray-800 overflow-hidden shadow-sm sm:rounded-lg">
                    <div class="p-6">
                        <form @submit.prevent="submit" class="space-y-6">
                            <div>
                                <InputLabel for="nombre" :value="$t('Nombre')" />
                                <TextInput id="nombre" type="text" class="mt-1 block w-full" v-model="form.nombre" required autofocus />
                                <InputError class="mt-2" :message="form.errors.nombre" />
                            </div>

                            <div>
                                <InputLabel for="color" :value="$t('Color')" />
                                <input id="color" type="color" v-model="form.color" class="mt-1 h-10 w-20 rounded border-gray-300 dark:border-gray-700 bg-white dark:bg-gray-900" />
                                <InputError class="mt-2" :message="form.errors.color" />
                            </div>

                            <div>
                                <InputLabel for="orden" :value="$t('Orden')" />
                                <input id="orden" type="number" min="0" v-model.number="form.orden" class="mt-1 block w-full rounded-md border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500" />
                                <InputError class="mt-2" :message="form.errors.orden" />
                            </div>

                            <label class="flex items-center gap-2">
                                <input type="checkbox" v-model="form.activo" class="rounded border-gray-300 text-indigo-600 shadow-sm focus:ring-indigo-500" />
                                <span class="text-sm text-gray-700 dark:text-gray-300">{{ $t('Activo') }}</span>
                            </label>
                            <InputError class="mt-2" :message="form.errors.activo" />

                            <div class="flex items-center gap-4">
                                <PrimaryButton :disabled="form.processing">{{ $t('Guardar') }}</PrimaryButton>
                                <Link :href="route('settings.empresas.index')">
                                    <SecondaryButton type="button">{{ $t('Cancelar') }}</SecondaryButton>
                                </Link>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>

<script setup lang="ts">
import AuthenticatedLayout from "@/Layouts/AuthenticatedLayout.vue";
import { Head, Link, useForm } from "@inertiajs/vue3";
import PrimaryButton from "@/Components/PrimaryButton.vue";
import SecondaryButton from "@/Components/SecondaryButton.vue";
import InputLabel from "@/Components/InputLabel.vue";
import TextInput from "@/Components/TextInput.vue";
import InputError from "@/Components/InputError.vue";
import { computed } from "vue";

interface Categoria {
    id: number;
    nombre: string;
    tipo: "ingreso" | "egreso";
    grupo: string;
    naturaleza: "fijo" | "variable" | null;
    activo: boolean;
    orden: number;
}

const props = defineProps<{
    categoria?: Categoria;
}>();

const isEdit = computed(() => !!props.categoria);

const form = useForm({
    nombre: props.categoria?.nombre ?? "",
    tipo: props.categoria?.tipo ?? "egreso",
    grupo: props.categoria?.grupo ?? "gasto_operativo",
    naturaleza: props.categoria?.naturaleza ?? null,
    activo: props.categoria?.activo ?? true,
    orden: props.categoria?.orden ?? 0,
});

const submit = () => {
    if (isEdit.value) {
        form.put(route("settings.categorias.update", props.categoria!.id));
    } else {
        form.post(route("settings.categorias.store"));
    }
};
</script>

<template>
    <Head :title="isEdit ? $t('Editar Categoría') : $t('Crear Categoría')" />

    <AuthenticatedLayout>
        <template #header>
            <h2 class="font-semibold text-xl text-gray-800 dark:text-gray-200 leading-tight">
                {{ isEdit ? $t('Editar Categoría') : $t('Crear Categoría') }}
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
                                <InputLabel for="tipo" :value="$t('Tipo')" />
                                <select id="tipo" v-model="form.tipo" class="mt-1 block w-full rounded-md border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                                    <option value="ingreso">{{ $t('Ingreso') }}</option>
                                    <option value="egreso">{{ $t('Egreso') }}</option>
                                </select>
                                <InputError class="mt-2" :message="form.errors.tipo" />
                            </div>

                            <div>
                                <InputLabel for="grupo" :value="$t('Grupo')" />
                                <select id="grupo" v-model="form.grupo" class="mt-1 block w-full rounded-md border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                                    <option value="ingreso">{{ $t('Ingreso') }}</option>
                                    <option value="costo_venta">{{ $t('Costo de venta') }}</option>
                                    <option value="gasto_operativo">{{ $t('Gasto operativo') }}</option>
                                    <option value="abajo_ebitda">{{ $t('Abajo de EBITDA') }}</option>
                                </select>
                                <InputError class="mt-2" :message="form.errors.grupo" />
                            </div>

                            <div>
                                <InputLabel for="naturaleza" :value="$t('Naturaleza')" />
                                <select id="naturaleza" v-model="form.naturaleza" class="mt-1 block w-full rounded-md border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                                    <option :value="null">{{ $t('No aplica') }}</option>
                                    <option value="fijo">{{ $t('Fijo') }}</option>
                                    <option value="variable">{{ $t('Variable') }}</option>
                                </select>
                                <InputError class="mt-2" :message="form.errors.naturaleza" />
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
                                <Link :href="route('settings.categorias.index')">
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

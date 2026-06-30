<script setup lang="ts">
import AuthenticatedLayout from "@/Layouts/AuthenticatedLayout.vue";
import { Head, Link, useForm } from "@inertiajs/vue3";
import PrimaryButton from "@/Components/PrimaryButton.vue";
import SecondaryButton from "@/Components/SecondaryButton.vue";
import InputLabel from "@/Components/InputLabel.vue";
import TextInput from "@/Components/TextInput.vue";
import InputError from "@/Components/InputError.vue";
import { computed } from "vue";

interface Option {
    id: number;
    nombre: string;
}

interface Empleado {
    id: number;
    empresa_id: number | null;
    empresa?: Option | null;
    nombre: string;
    puesto: string | null;
    fecha_entrada: string;
    fecha_baja: string | null;
    salario_fiscal: number;
    salario_real: number;
    clasificacion: string | null;
    activo: boolean;
}

const props = defineProps<{
    empleado?: Empleado;
    empresas: Option[];
}>();

const isEdit = computed(() => !!props.empleado);

const empresaOptions = computed<Option[]>(() => {
    const cur = props.empleado?.empresa;
    return cur && !props.empresas.some((e) => e.id === cur.id) ? [cur, ...props.empresas] : props.empresas;
});

const selectClass =
    "mt-1 block w-full rounded-md border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500";

const form = useForm({
    nombre: props.empleado?.nombre ?? "",
    puesto: props.empleado?.puesto ?? "",
    empresa_id: props.empleado?.empresa_id ?? "",
    fecha_entrada: props.empleado?.fecha_entrada?.slice(0, 10) ?? new Date().toISOString().slice(0, 10),
    fecha_baja: props.empleado?.fecha_baja?.slice(0, 10) ?? "",
    salario_fiscal: props.empleado?.salario_fiscal ?? "",
    salario_real: props.empleado?.salario_real ?? "",
    clasificacion: props.empleado?.clasificacion ?? "",
    activo: props.empleado?.activo ?? true,
});

const submit = () => {
    if (isEdit.value) {
        form.put(route("employees.update", props.empleado!.id));
    } else {
        form.post(route("employees.store"));
    }
};
</script>

<template>
    <Head :title="isEdit ? $t('Editar empleado') : $t('Nuevo empleado')" />

    <AuthenticatedLayout>
        <template #header>
            <h2 class="font-semibold text-xl text-gray-800 dark:text-gray-200 leading-tight">
                {{ isEdit ? $t("Editar empleado") : $t("Nuevo empleado") }}
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

                            <div class="grid grid-cols-2 gap-4">
                                <div>
                                    <InputLabel for="puesto" :value="$t('Puesto')" />
                                    <TextInput id="puesto" type="text" class="mt-1 block w-full" v-model="form.puesto" />
                                    <InputError class="mt-2" :message="form.errors.puesto" />
                                </div>
                                <div>
                                    <InputLabel for="empresa_id" :value="$t('Empresa')" />
                                    <select id="empresa_id" v-model="form.empresa_id" required :class="selectClass">
                                        <option value="" disabled>{{ $t("Selecciona…") }}</option>
                                        <option v-for="e in empresaOptions" :key="e.id" :value="e.id">{{ e.nombre }}</option>
                                    </select>
                                    <InputError class="mt-2" :message="form.errors.empresa_id" />
                                </div>
                            </div>

                            <div class="grid grid-cols-2 gap-4">
                                <div>
                                    <InputLabel for="salario_fiscal" :value="$t('Salario fiscal')" />
                                    <input id="salario_fiscal" type="number" step="0.01" min="0.01" v-model="form.salario_fiscal" required :class="selectClass" />
                                    <InputError class="mt-2" :message="form.errors.salario_fiscal" />
                                </div>
                                <div>
                                    <InputLabel for="salario_real" :value="$t('Salario real')" />
                                    <input id="salario_real" type="number" step="0.01" min="0.01" v-model="form.salario_real" required :class="selectClass" />
                                    <InputError class="mt-2" :message="form.errors.salario_real" />
                                </div>
                            </div>

                            <div class="grid grid-cols-2 gap-4">
                                <div>
                                    <InputLabel for="fecha_entrada" :value="$t('Fecha de entrada')" />
                                    <input id="fecha_entrada" type="date" v-model="form.fecha_entrada" required :class="selectClass" />
                                    <InputError class="mt-2" :message="form.errors.fecha_entrada" />
                                </div>
                                <div>
                                    <InputLabel for="fecha_baja" :value="$t('Fecha de baja')" />
                                    <input id="fecha_baja" type="date" v-model="form.fecha_baja" :class="selectClass" />
                                    <InputError class="mt-2" :message="form.errors.fecha_baja" />
                                </div>
                            </div>

                            <div>
                                <InputLabel for="clasificacion" :value="$t('Clasificación')" />
                                <select id="clasificacion" v-model="form.clasificacion" :class="selectClass">
                                    <option value="">{{ $t("Sin clasificar") }}</option>
                                    <option value="tecnica">{{ $t("Técnica (facturable)") }}</option>
                                    <option value="administrativa">{{ $t("Administrativa") }}</option>
                                </select>
                                <InputError class="mt-2" :message="form.errors.clasificacion" />
                            </div>

                            <label class="flex items-center gap-2">
                                <input type="checkbox" v-model="form.activo" class="rounded border-gray-300 text-indigo-600 shadow-sm focus:ring-indigo-500" />
                                <span class="text-sm text-gray-700 dark:text-gray-300">{{ $t("Activo") }}</span>
                            </label>

                            <div class="flex items-center gap-4">
                                <PrimaryButton :disabled="form.processing">{{ $t("Guardar") }}</PrimaryButton>
                                <Link :href="route('employees.index')">
                                    <SecondaryButton type="button">{{ $t("Cancelar") }}</SecondaryButton>
                                </Link>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>

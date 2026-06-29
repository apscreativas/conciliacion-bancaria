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

interface Egreso {
    id: number;
    empresa_id: number | null;
    categoria_id: number | null;
    fecha: string;
    monto: number;
    descripcion: string;
    proveedor: string | null;
    metodo_pago: string | null;
}

const props = defineProps<{
    egreso?: Egreso;
    empresas: Option[];
    categorias: Option[];
}>();

const isEdit = computed(() => !!props.egreso);

const selectClass =
    "mt-1 block w-full rounded-md border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500";

const form = useForm({
    fecha: props.egreso?.fecha ?? new Date().toISOString().slice(0, 10),
    monto: props.egreso?.monto ?? "",
    descripcion: props.egreso?.descripcion ?? "",
    proveedor: props.egreso?.proveedor ?? "",
    empresa_id: props.egreso?.empresa_id ?? "",
    categoria_id: props.egreso?.categoria_id ?? "",
    metodo_pago: props.egreso?.metodo_pago ?? "",
});

const submit = () => {
    if (isEdit.value) {
        form.put(route("expenses.update", props.egreso!.id));
    } else {
        form.post(route("expenses.store"));
    }
};
</script>

<template>
    <Head :title="isEdit ? $t('Editar Egreso') : $t('Nuevo Egreso')" />

    <AuthenticatedLayout>
        <template #header>
            <h2 class="font-semibold text-xl text-gray-800 dark:text-gray-200 leading-tight">
                {{ isEdit ? $t("Editar Egreso") : $t("Nuevo Egreso") }}
            </h2>
        </template>

        <div class="py-12">
            <div class="max-w-xl mx-auto sm:px-6 lg:px-8">
                <div class="bg-white dark:bg-gray-800 overflow-hidden shadow-sm sm:rounded-lg">
                    <div class="p-6">
                        <form @submit.prevent="submit" class="space-y-6">
                            <div class="grid grid-cols-2 gap-4">
                                <div>
                                    <InputLabel for="fecha" :value="$t('Fecha')" />
                                    <input id="fecha" type="date" v-model="form.fecha" required :class="selectClass" />
                                    <InputError class="mt-2" :message="form.errors.fecha" />
                                </div>
                                <div>
                                    <InputLabel for="monto" :value="$t('Monto')" />
                                    <input id="monto" type="number" step="0.01" min="0.01" v-model="form.monto" required :class="selectClass" />
                                    <InputError class="mt-2" :message="form.errors.monto" />
                                </div>
                            </div>

                            <div>
                                <InputLabel for="descripcion" :value="$t('Descripción')" />
                                <TextInput id="descripcion" type="text" class="mt-1 block w-full" v-model="form.descripcion" required autofocus />
                                <InputError class="mt-2" :message="form.errors.descripcion" />
                            </div>

                            <div>
                                <InputLabel for="categoria_id" :value="$t('Categoría')" />
                                <select id="categoria_id" v-model="form.categoria_id" required :class="selectClass">
                                    <option value="" disabled>{{ $t("Selecciona…") }}</option>
                                    <option v-for="c in categorias" :key="c.id" :value="c.id">{{ c.nombre }}</option>
                                </select>
                                <InputError class="mt-2" :message="form.errors.categoria_id" />
                            </div>

                            <div>
                                <InputLabel for="empresa_id" :value="$t('Empresa')" />
                                <select id="empresa_id" v-model="form.empresa_id" :class="selectClass">
                                    <option value="">{{ $t("Sin asignar") }}</option>
                                    <option v-for="e in empresas" :key="e.id" :value="e.id">{{ e.nombre }}</option>
                                </select>
                                <InputError class="mt-2" :message="form.errors.empresa_id" />
                            </div>

                            <div class="grid grid-cols-2 gap-4">
                                <div>
                                    <InputLabel for="proveedor" :value="$t('Proveedor')" />
                                    <TextInput id="proveedor" type="text" class="mt-1 block w-full" v-model="form.proveedor" />
                                    <InputError class="mt-2" :message="form.errors.proveedor" />
                                </div>
                                <div>
                                    <InputLabel for="metodo_pago" :value="$t('Método de pago')" />
                                    <select id="metodo_pago" v-model="form.metodo_pago" :class="selectClass">
                                        <option value="">{{ $t("Sin especificar") }}</option>
                                        <option value="transferencia">{{ $t("Transferencia") }}</option>
                                        <option value="efectivo">{{ $t("Efectivo") }}</option>
                                        <option value="tarjeta">{{ $t("Tarjeta") }}</option>
                                        <option value="otro">{{ $t("Otro") }}</option>
                                    </select>
                                    <InputError class="mt-2" :message="form.errors.metodo_pago" />
                                </div>
                            </div>

                            <div class="flex items-center gap-4">
                                <PrimaryButton :disabled="form.processing">{{ $t("Guardar") }}</PrimaryButton>
                                <Link :href="route('expenses.index')">
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

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

interface Ingreso {
    id: number;
    empresa_id: number | null;
    categoria_id: number | null;
    empresa?: Option | null;
    categoria?: Option | null;
    fecha: string;
    monto: number;
    descripcion: string;
    cliente: string | null;
    metodo: string | null;
}

const props = defineProps<{
    ingreso?: Ingreso;
    empresas: Option[];
    categorias: Option[];
}>();

const isEdit = computed(() => !!props.ingreso);

// Si la cat/empresa actual del ingreso fue desactivada, no estará en la lista activa;
// la fusionamos para que el <select> siempre muestre el valor real.
const categoriaOptions = computed<Option[]>(() => {
    const cur = props.ingreso?.categoria;
    return cur && !props.categorias.some((c) => c.id === cur.id) ? [cur, ...props.categorias] : props.categorias;
});
const empresaOptions = computed<Option[]>(() => {
    const cur = props.ingreso?.empresa;
    return cur && !props.empresas.some((e) => e.id === cur.id) ? [cur, ...props.empresas] : props.empresas;
});

const selectClass =
    "mt-1 block w-full rounded-md border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500";

const form = useForm({
    // `fecha` puede venir como ISO completo; el <input type=date> requiere 'YYYY-MM-DD'.
    fecha: props.ingreso?.fecha?.slice(0, 10) ?? new Date().toISOString().slice(0, 10),
    monto: props.ingreso?.monto ?? "",
    descripcion: props.ingreso?.descripcion ?? "",
    cliente: props.ingreso?.cliente ?? "",
    empresa_id: props.ingreso?.empresa_id ?? "",
    categoria_id: props.ingreso?.categoria_id ?? "",
    metodo: props.ingreso?.metodo ?? "efectivo",
});

const submit = () => {
    if (isEdit.value) {
        form.put(route("cash-income.update", props.ingreso!.id));
    } else {
        form.post(route("cash-income.store"));
    }
};
</script>

<template>
    <Head :title="isEdit ? $t('Editar ingreso') : $t('Nuevo ingreso')" />

    <AuthenticatedLayout>
        <template #header>
            <h2 class="font-semibold text-xl text-gray-800 dark:text-gray-200 leading-tight">
                {{ isEdit ? $t("Editar ingreso") : $t("Nuevo ingreso") }}
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
                                    <option v-for="c in categoriaOptions" :key="c.id" :value="c.id">{{ c.nombre }}</option>
                                </select>
                                <InputError class="mt-2" :message="form.errors.categoria_id" />
                            </div>

                            <div>
                                <InputLabel for="empresa_id" :value="$t('Empresa')" />
                                <select id="empresa_id" v-model="form.empresa_id" :class="selectClass">
                                    <option value="">{{ $t("Sin asignar") }}</option>
                                    <option v-for="e in empresaOptions" :key="e.id" :value="e.id">{{ e.nombre }}</option>
                                </select>
                                <InputError class="mt-2" :message="form.errors.empresa_id" />
                            </div>

                            <div class="grid grid-cols-2 gap-4">
                                <div>
                                    <InputLabel for="cliente" :value="$t('Cliente')" />
                                    <TextInput id="cliente" type="text" class="mt-1 block w-full" v-model="form.cliente" />
                                    <InputError class="mt-2" :message="form.errors.cliente" />
                                </div>
                                <div>
                                    <InputLabel for="metodo" :value="$t('Método')" />
                                    <select id="metodo" v-model="form.metodo" :class="selectClass">
                                        <option value="efectivo">{{ $t("Efectivo") }}</option>
                                        <option value="otro">{{ $t("Otro") }}</option>
                                    </select>
                                    <InputError class="mt-2" :message="form.errors.metodo" />
                                </div>
                            </div>

                            <div class="flex items-center gap-4">
                                <PrimaryButton :disabled="form.processing">{{ $t("Guardar") }}</PrimaryButton>
                                <Link :href="route('cash-income.index')">
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

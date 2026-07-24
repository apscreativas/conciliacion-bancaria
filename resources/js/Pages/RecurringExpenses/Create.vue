<script setup lang="ts">
import AuthenticatedLayout from "@/Layouts/AuthenticatedLayout.vue";
import { Head, Link, useForm } from "@inertiajs/vue3";
import PrimaryButton from "@/Components/PrimaryButton.vue";
import SecondaryButton from "@/Components/SecondaryButton.vue";
import InputLabel from "@/Components/InputLabel.vue";
import TextInput from "@/Components/TextInput.vue";
import InputError from "@/Components/InputError.vue";
import { formatDate } from "@/utils/format";
import { computed } from "vue";

interface Option {
    id: number;
    nombre: string;
}

interface Plantilla {
    id: number;
    empresa_id: number | null;
    categoria_id: number | null;
    empresa?: Option | null;
    categoria?: Option | null;
    descripcion: string;
    proveedor: string | null;
    monto: number;
    frecuencia: string;
    dia_del_mes: number;
    ajuste_dia_habil: string;
    fecha_inicio: string;
    vigencia_tipo: string;
    fecha_fin: string | null;
    num_pagos: number | null;
    activo: boolean;
}

const props = defineProps<{
    plantilla?: Plantilla;
    empresas: Option[];
    categorias: Option[];
}>();

const isEdit = computed(() => !!props.plantilla);

const categoriaOptions = computed<Option[]>(() => {
    const cur = props.plantilla?.categoria;
    return cur && !props.categorias.some((c) => c.id === cur.id) ? [cur, ...props.categorias] : props.categorias;
});
const empresaOptions = computed<Option[]>(() => {
    const cur = props.plantilla?.empresa;
    return cur && !props.empresas.some((e) => e.id === cur.id) ? [cur, ...props.empresas] : props.empresas;
});

const selectClass =
    "mt-1 block w-full rounded-md border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500";

const form = useForm({
    descripcion: props.plantilla?.descripcion ?? "",
    proveedor: props.plantilla?.proveedor ?? "",
    monto: props.plantilla?.monto ?? "",
    empresa_id: props.plantilla?.empresa_id ?? "",
    categoria_id: props.plantilla?.categoria_id ?? "",
    frecuencia: props.plantilla?.frecuencia ?? "mensual",
    dia_del_mes: props.plantilla?.dia_del_mes ?? 1,
    ajuste_dia_habil: props.plantilla?.ajuste_dia_habil ?? "habil_anterior",
    fecha_inicio: props.plantilla?.fecha_inicio?.slice(0, 10) ?? new Date().toISOString().slice(0, 10),
    vigencia_tipo: props.plantilla?.vigencia_tipo ?? "indefinida",
    fecha_fin: props.plantilla?.fecha_fin?.slice(0, 10) ?? "",
    num_pagos: props.plantilla?.num_pagos ?? "",
    activo: props.plantilla?.activo ?? true,
});

// Espejo de RecurrenceCalculator::firstOccurrence — día del mes de fecha_inicio,
// recortado al último día si el mes es más corto. Solo informativo (el backend decide).
const primerEgreso = computed<string | null>(() => {
    if (isEdit.value || !form.fecha_inicio) return null;
    const [y, m] = form.fecha_inicio.split("-").map(Number);
    const dia = Number(form.dia_del_mes);
    if (!y || !m || !dia || dia < 1) return null;
    const d = Math.min(dia, new Date(y, m, 0).getDate());
    return `${y}-${String(m).padStart(2, "0")}-${String(d).padStart(2, "0")}`;
});

const submit = () => {
    if (isEdit.value) {
        form.put(route("recurring-expenses.update", props.plantilla!.id));
    } else {
        form.post(route("recurring-expenses.store"));
    }
};
</script>

<template>
    <Head :title="isEdit ? $t('Editar plantilla') : $t('Nueva plantilla')" />

    <AuthenticatedLayout>
        <template #header>
            <h2 class="font-semibold text-xl text-gray-800 dark:text-gray-200 leading-tight">
                {{ isEdit ? $t("Editar plantilla") : $t("Nueva plantilla") }}
            </h2>
        </template>

        <div class="py-12">
            <div class="max-w-xl mx-auto sm:px-6 lg:px-8">
                <div class="bg-white dark:bg-gray-800 overflow-hidden shadow-sm sm:rounded-lg">
                    <div class="p-6">
                        <form @submit.prevent="submit" class="space-y-6">
                            <div>
                                <InputLabel for="descripcion" :value="$t('Descripción')" />
                                <TextInput id="descripcion" type="text" class="mt-1 block w-full" v-model="form.descripcion" required autofocus />
                                <InputError class="mt-2" :message="form.errors.descripcion" />
                            </div>

                            <div class="grid grid-cols-2 gap-4">
                                <div>
                                    <InputLabel for="monto" :value="$t('Monto')" />
                                    <input id="monto" type="number" step="0.01" min="0.01" v-model="form.monto" required :class="selectClass" />
                                    <InputError class="mt-2" :message="form.errors.monto" />
                                </div>
                                <div>
                                    <InputLabel for="proveedor" :value="$t('Proveedor')" />
                                    <TextInput id="proveedor" type="text" class="mt-1 block w-full" v-model="form.proveedor" />
                                    <InputError class="mt-2" :message="form.errors.proveedor" />
                                </div>
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
                                    <InputLabel for="frecuencia" :value="$t('Frecuencia')" />
                                    <select id="frecuencia" v-model="form.frecuencia" :class="selectClass">
                                        <option value="mensual">{{ $t("Mensual") }}</option>
                                        <option value="bimestral">{{ $t("Bimestral") }}</option>
                                        <option value="trimestral">{{ $t("Trimestral") }}</option>
                                        <option value="anual">{{ $t("Anual") }}</option>
                                    </select>
                                    <InputError class="mt-2" :message="form.errors.frecuencia" />
                                </div>
                                <div>
                                    <InputLabel for="dia_del_mes" :value="$t('Día del mes')" />
                                    <input id="dia_del_mes" type="number" min="1" max="31" v-model.number="form.dia_del_mes" required :class="selectClass" />
                                    <InputError class="mt-2" :message="form.errors.dia_del_mes" />
                                </div>
                            </div>

                            <div class="grid grid-cols-2 gap-4">
                                <div>
                                    <InputLabel for="ajuste_dia_habil" :value="$t('Ajuste fin de semana')" />
                                    <select id="ajuste_dia_habil" v-model="form.ajuste_dia_habil" :class="selectClass">
                                        <option value="ninguno">{{ $t("Sin ajuste") }}</option>
                                        <option value="habil_anterior">{{ $t("Día hábil anterior") }}</option>
                                        <option value="habil_siguiente">{{ $t("Día hábil siguiente") }}</option>
                                    </select>
                                    <InputError class="mt-2" :message="form.errors.ajuste_dia_habil" />
                                </div>
                                <div>
                                    <InputLabel for="fecha_inicio" :value="$t('Fecha de inicio')" />
                                    <input id="fecha_inicio" type="date" v-model="form.fecha_inicio" required :class="selectClass" />
                                    <InputError class="mt-2" :message="form.errors.fecha_inicio" />
                                </div>
                            </div>

                            <p v-if="primerEgreso" class="text-sm text-gray-500 dark:text-gray-400">
                                {{ $t("Primer egreso") }}: {{ formatDate(primerEgreso) }}
                                <span v-if="form.ajuste_dia_habil !== 'ninguno'">({{ $t("puede ajustarse a día hábil") }})</span>
                            </p>

                            <div>
                                <InputLabel for="vigencia_tipo" :value="$t('Vigencia')" />
                                <select id="vigencia_tipo" v-model="form.vigencia_tipo" :class="selectClass">
                                    <option value="indefinida">{{ $t("Indefinida") }}</option>
                                    <option value="hasta_fecha">{{ $t("Hasta una fecha") }}</option>
                                    <option value="num_pagos">{{ $t("Número de pagos") }}</option>
                                </select>
                                <InputError class="mt-2" :message="form.errors.vigencia_tipo" />
                            </div>

                            <div v-if="form.vigencia_tipo === 'hasta_fecha'">
                                <InputLabel for="fecha_fin" :value="$t('Fecha de fin')" />
                                <input id="fecha_fin" type="date" v-model="form.fecha_fin" :class="selectClass" />
                                <InputError class="mt-2" :message="form.errors.fecha_fin" />
                            </div>
                            <div v-if="form.vigencia_tipo === 'num_pagos'">
                                <InputLabel for="num_pagos" :value="$t('Número de pagos')" />
                                <input id="num_pagos" type="number" min="1" v-model.number="form.num_pagos" :class="selectClass" />
                                <InputError class="mt-2" :message="form.errors.num_pagos" />
                            </div>

                            <label class="flex items-center gap-2">
                                <input type="checkbox" v-model="form.activo" class="rounded border-gray-300 text-indigo-600 shadow-sm focus:ring-indigo-500" />
                                <span class="text-sm text-gray-700 dark:text-gray-300">{{ $t("Activo") }}</span>
                            </label>

                            <div class="flex items-center gap-4">
                                <PrimaryButton :disabled="form.processing">{{ $t("Guardar") }}</PrimaryButton>
                                <Link :href="route('recurring-expenses.index')">
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

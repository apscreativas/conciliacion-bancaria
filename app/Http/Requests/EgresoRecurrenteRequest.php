<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class EgresoRecurrenteRequest extends FormRequest
{
    /**
     * Captura operativa: cualquier miembro del team (tenancy por TeamOwned).
     */
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'empresa_id' => $this->input('empresa_id') ?: null,
            // Limpiar el campo de vigencia que no aplica para evitar valores colgados.
            'fecha_fin' => $this->input('vigencia_tipo') === 'hasta_fecha' ? $this->input('fecha_fin') : null,
            'num_pagos' => $this->input('vigencia_tipo') === 'num_pagos' ? $this->input('num_pagos') : null,
        ]);
    }

    public function rules(): array
    {
        $teamId = $this->user()->current_team_id;

        return [
            'descripcion' => ['required', 'string', 'max:255'],
            'proveedor' => ['nullable', 'string', 'max:255'],
            'monto' => ['required', 'numeric', 'gt:0'],
            'empresa_id' => [
                'nullable',
                Rule::exists('empresas', 'id')->where(fn ($q) => $q->where('team_id', $teamId)),
            ],
            'categoria_id' => [
                'required',
                Rule::exists('categorias', 'id')->where(fn ($q) => $q->where('team_id', $teamId)->where('tipo', 'egreso')),
            ],
            // Fase 3 no ofrece 'quincenal' (es de nómina, Fase 3B).
            'frecuencia' => ['required', Rule::in(['mensual', 'bimestral', 'trimestral', 'anual'])],
            'dia_del_mes' => ['required', 'integer', 'between:1,31'],
            'ajuste_dia_habil' => ['required', Rule::in(['ninguno', 'habil_anterior', 'habil_siguiente'])],
            'fecha_inicio' => ['required', 'date'],
            'vigencia_tipo' => ['required', Rule::in(['indefinida', 'hasta_fecha', 'num_pagos'])],
            'fecha_fin' => ['nullable', 'required_if:vigencia_tipo,hasta_fecha', 'date', 'after_or_equal:fecha_inicio'],
            'num_pagos' => ['nullable', 'required_if:vigencia_tipo,num_pagos', 'integer', 'min:1'],
            'activo' => ['boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'categoria_id.required' => 'Selecciona una categoría de egreso.',
            'categoria_id.exists' => 'La categoría no es válida (debe ser de tipo egreso del equipo).',
            'monto.gt' => 'El monto debe ser mayor que cero.',
            'fecha_fin.required_if' => 'Indica la fecha de fin de la vigencia.',
            'num_pagos.required_if' => 'Indica el número de pagos.',
        ];
    }
}

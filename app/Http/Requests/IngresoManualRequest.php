<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class IngresoManualRequest extends FormRequest
{
    /**
     * Captura operativa: cualquier miembro del team autenticado (tenancy por TeamOwned).
     */
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'empresa_id' => $this->input('empresa_id') ?: null,
            'metodo' => $this->input('metodo') ?: 'efectivo',
        ]);
    }

    public function rules(): array
    {
        $teamId = $this->user()->current_team_id;

        return [
            'empresa_id' => [
                'nullable',
                Rule::exists('empresas', 'id')->where(fn ($q) => $q->where('team_id', $teamId)),
            ],
            'categoria_id' => [
                'required',
                Rule::exists('categorias', 'id')->where(fn ($q) => $q->where('team_id', $teamId)->where('tipo', 'ingreso')),
            ],
            'fecha' => ['required', 'date'],
            'monto' => ['required', 'numeric', 'gt:0'],
            'descripcion' => ['required', 'string', 'max:255'],
            'cliente' => ['nullable', 'string', 'max:255'],
            'metodo' => ['required', Rule::in(['efectivo', 'otro'])],
        ];
    }

    public function messages(): array
    {
        return [
            'categoria_id.required' => 'Selecciona una categoría de ingreso.',
            'categoria_id.exists' => 'La categoría no es válida (debe ser de tipo ingreso del equipo).',
            'monto.gt' => 'El monto debe ser mayor que cero.',
        ];
    }
}

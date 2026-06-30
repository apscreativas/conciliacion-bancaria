<?php

namespace App\Http\Requests;

use App\Models\Empleado;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class EmpleadoRequest extends FormRequest
{
    /**
     * Autz vía EmpleadoPolicy ANTES de validar (un no-owner recibe 403, no 422).
     */
    public function authorize(): bool
    {
        $empleado = $this->route('employee');

        return $empleado
            ? $this->user()->can('update', $empleado)
            : $this->user()->can('create', Empleado::class);
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'empresa_id' => $this->input('empresa_id') ?: null,
            'fecha_baja' => $this->input('fecha_baja') ?: null,
            'clasificacion' => $this->input('clasificacion') ?: null,
            'activo' => $this->boolean('activo'),
        ]);
    }

    public function rules(): array
    {
        $teamId = $this->user()->current_team_id;

        return [
            'nombre' => ['required', 'string', 'max:255'],
            'puesto' => ['nullable', 'string', 'max:255'],
            'empresa_id' => [
                'required',
                Rule::exists('empresas', 'id')->where(fn ($q) => $q->where('team_id', $teamId)),
            ],
            'fecha_entrada' => ['required', 'date'],
            'fecha_baja' => ['nullable', 'date', 'after_or_equal:fecha_entrada'],
            'salario_fiscal' => ['required', 'numeric', 'gt:0'],
            'salario_real' => ['required', 'numeric', 'gt:0', 'gte:salario_fiscal'],
            'clasificacion' => ['nullable', Rule::in(['tecnica', 'administrativa'])],
            'activo' => ['boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'empresa_id.required' => 'Selecciona la empresa / centro de costo.',
            'salario_real.gte' => 'El salario real no puede ser menor que el fiscal.',
            'fecha_baja.after_or_equal' => 'La fecha de baja no puede ser anterior a la de entrada.',
        ];
    }
}

<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class EmpresaRequest extends FormRequest
{
    /**
     * Autorización resuelta por EmpresaPolicy vía $this->authorize() en el controller.
     */
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'slug' => Str::slug((string) $this->input('nombre')),
            'activo' => $this->boolean('activo'),
        ]);
    }

    public function rules(): array
    {
        $teamId = $this->user()->current_team_id;
        $id = optional($this->route('company'))->id;

        return [
            'nombre' => [
                'required', 'string', 'max:255',
                Rule::unique('empresas', 'nombre')->ignore($id)->where(fn ($q) => $q->where('team_id', $teamId)),
            ],
            // El slug se deriva del nombre; validar su unicidad evita un 500 por el índice unique(team_id, slug).
            'slug' => [
                'required', 'string', 'max:255',
                Rule::unique('empresas', 'slug')->ignore($id)->where(fn ($q) => $q->where('team_id', $teamId)),
            ],
            'color' => ['nullable', 'string', 'max:20'],
            'activo' => ['boolean'],
            'orden' => ['nullable', 'integer', 'min:0'],
        ];
    }

    public function messages(): array
    {
        return [
            'slug.required' => 'El nombre debe contener al menos un carácter alfanumérico.',
            'slug.unique' => 'Ya existe una empresa con un nombre equivalente (mismo identificador).',
        ];
    }
}

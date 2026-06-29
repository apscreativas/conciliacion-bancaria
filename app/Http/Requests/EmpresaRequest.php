<?php

namespace App\Http\Requests;

use App\Models\Empresa;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class EmpresaRequest extends FormRequest
{
    /**
     * Autz vía EmpresaPolicy ANTES de validar (un no-owner recibe 403, no 422).
     */
    public function authorize(): bool
    {
        $empresa = $this->route('company');

        return $empresa
            ? $this->user()->can('update', $empresa)
            : $this->user()->can('create', Empresa::class);
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'slug' => Str::slug((string) $this->input('nombre')),
            'activo' => $this->boolean('activo'),
            // La columna orden es NOT NULL default 0; un input vacío llega como null
            // (ConvertEmptyStringsToNull) → coercionar para no romper el INSERT.
            'orden' => $this->filled('orden') ? $this->input('orden') : 0,
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
            'orden' => ['integer', 'min:0'],
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

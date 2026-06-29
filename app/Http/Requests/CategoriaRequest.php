<?php

namespace App\Http\Requests;

use App\Models\Categoria;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CategoriaRequest extends FormRequest
{
    /**
     * Autz vía CategoriaPolicy ANTES de validar (un no-owner recibe 403, no 422).
     */
    public function authorize(): bool
    {
        $categoria = $this->route('category');

        return $categoria
            ? $this->user()->can('update', $categoria)
            : $this->user()->can('create', Categoria::class);
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'activo' => $this->boolean('activo'),
            // El select envía '' cuando "No aplica"; normalizar a null.
            'naturaleza' => $this->input('naturaleza') ?: null,
            // orden es NOT NULL default 0; un input vacío llega como null → coercionar.
            'orden' => $this->filled('orden') ? $this->input('orden') : 0,
        ]);
    }

    public function rules(): array
    {
        $teamId = $this->user()->current_team_id;
        $id = optional($this->route('category'))->id;

        return [
            'nombre' => [
                'required', 'string', 'max:255',
                Rule::unique('categorias', 'nombre')->ignore($id)->where(fn ($q) => $q->where('team_id', $teamId)),
            ],
            'tipo' => ['required', Rule::in(['ingreso', 'egreso'])],
            'grupo' => ['required', Rule::in(['ingreso', 'costo_venta', 'gasto_operativo', 'abajo_ebitda'])],
            'naturaleza' => ['nullable', Rule::in(['fijo', 'variable'])],
            'activo' => ['boolean'],
            'orden' => ['integer', 'min:0'],
        ];
    }

    /**
     * Invariante de negocio (PRD §4.2): ingreso ⇒ grupo 'ingreso' y sin naturaleza;
     * egreso ⇒ grupo de egreso y naturaleza fijo/variable.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v) {
            $tipo = $this->input('tipo');
            $grupo = $this->input('grupo');
            $naturaleza = $this->input('naturaleza');

            if ($tipo === 'ingreso') {
                if ($grupo !== 'ingreso') {
                    $v->errors()->add('grupo', "Un ingreso debe usar el grupo 'ingreso'.");
                }
                if (! is_null($naturaleza)) {
                    $v->errors()->add('naturaleza', 'Un ingreso no lleva naturaleza.');
                }
            } elseif ($tipo === 'egreso') {
                if (! in_array($grupo, ['costo_venta', 'gasto_operativo', 'abajo_ebitda'], true)) {
                    $v->errors()->add('grupo', 'Un egreso debe usar un grupo de egreso (costo de venta, gasto operativo o abajo de EBITDA).');
                }
                if (! in_array($naturaleza, ['fijo', 'variable'], true)) {
                    $v->errors()->add('naturaleza', 'Un egreso debe indicar naturaleza fijo o variable.');
                }
            }
        });
    }
}

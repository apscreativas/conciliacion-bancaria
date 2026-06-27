<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CategoriaRequest extends FormRequest
{
    /**
     * Autorización resuelta por CategoriaPolicy vía $this->authorize() en el controller.
     */
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'activo' => $this->boolean('activo'),
            // El select envía '' cuando "No aplica"; normalizar a null.
            'naturaleza' => $this->input('naturaleza') ?: null,
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
            'orden' => ['nullable', 'integer', 'min:0'],
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

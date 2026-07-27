<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class RegistrarEntradaInventarioRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'cantidad' => ['required', 'numeric', 'gt:0'],
            'unidad' => ['nullable', 'string', 'max:20'],
            'referencia_tipo' => ['nullable', 'string', 'max:50'],
            'referencia_id' => ['nullable', 'integer'],
            'observaciones' => ['nullable', 'string'],
        ];
    }
}

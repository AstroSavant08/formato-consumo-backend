<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class IndexMovimientoInventarioRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'producto_id' => ['nullable', 'integer', 'exists:productos,id'],
            'tipo' => ['nullable', 'string', Rule::in([
                'entrada',
                'salida',
                'entrega',
                'ajuste',
                'devolucion',
                'correccion',
            ])],
            'fecha_desde' => ['nullable', 'date'],
            'fecha_hasta' => ['nullable', 'date', 'after_or_equal:fecha_desde'],
        ];
    }
}

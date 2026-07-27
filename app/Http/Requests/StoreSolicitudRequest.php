<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreSolicitudRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'usuario_id' => ['prohibited'],
            'area_id' => ['required', 'integer', 'exists:areas,id'],
            'fecha' => ['required', 'date'],
            'justificacion' => ['nullable', 'string'],
            'observaciones' => ['nullable', 'string'],
            'detalles' => ['required', 'array', 'min:1'],
            'detalles.*.producto_id' => ['required', 'integer', 'exists:productos,id'],
            'detalles.*.cantidad' => ['required', 'numeric', 'gt:0'],
            'detalles.*.unidad' => ['required', 'string', 'max:20'],
            'detalles.*.precio_unitario' => ['nullable', 'numeric', 'gte:0'],
        ];
    }
}

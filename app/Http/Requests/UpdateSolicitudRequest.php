<?php

namespace App\Http\Requests;

use App\Models\Solicitud;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateSolicitudRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'area_id' => ['sometimes', 'integer', 'exists:areas,id'],
            'fecha' => ['sometimes', 'date'],
            'justificacion' => ['nullable', 'string'],
            'observaciones' => ['nullable', 'string'],
            'estado' => ['sometimes', 'string', Rule::in([Solicitud::ESTADO_EN_REVISION])],
            'detalles' => ['sometimes', 'array', 'min:1'],
            'detalles.*.producto_id' => ['required_with:detalles', 'integer', 'exists:productos,id'],
            'detalles.*.cantidad' => ['required_with:detalles', 'numeric', 'gt:0'],
            'detalles.*.unidad' => ['required_with:detalles', 'string', 'max:20'],
            'detalles.*.precio_unitario' => ['nullable', 'numeric', 'gte:0'],
        ];
    }
}

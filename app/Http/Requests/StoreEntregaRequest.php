<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreEntregaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'fecha' => ['required', 'date'],
            'producto_id' => ['required', 'integer', 'exists:productos,id'],
            'area_id' => ['required', 'integer', 'exists:areas,id'],
            'cantidad' => ['required', 'numeric', 'gt:0'],
            'unidad' => ['required', 'string', 'max:20'],
            'quien_recibe' => ['required', 'string', 'max:255'],
            'entregado_por' => ['required', 'string', 'max:255'],
            'fuente' => ['prohibited'],
        ];
    }
}

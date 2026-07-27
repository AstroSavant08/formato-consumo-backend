<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ShowSemaforoConsumoRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'producto_id' => ['required', 'integer', 'exists:productos,id'],
            'mes' => ['required', 'integer', 'min:1', 'max:12'],
            'anio' => ['required', 'integer', 'min:2000', 'max:2100'],
            'area_id' => ['nullable', 'integer', 'exists:areas,id'],
        ];
    }
}

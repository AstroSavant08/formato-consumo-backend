<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreStagingHomologacionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'producto_id_destino' => ['required', 'integer', 'exists:productos,id'],
            'notas' => ['nullable', 'string', 'max:2000'],
            'confirmar_reemplazo' => ['sometimes', 'boolean'],
        ];
    }
}

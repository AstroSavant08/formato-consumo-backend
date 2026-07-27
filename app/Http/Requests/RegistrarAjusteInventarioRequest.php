<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class RegistrarAjusteInventarioRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'nuevo_stock' => ['required', 'numeric', 'min:0'],
            'observaciones' => ['required', 'string'],
        ];
    }
}

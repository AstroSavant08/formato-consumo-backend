<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ResolverPreciosVigentesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'producto_ids' => ['required', 'array', 'min:1'],
            'producto_ids.*' => ['integer', 'exists:productos,id'],
            'fecha' => ['nullable', 'date'],
        ];
    }
}

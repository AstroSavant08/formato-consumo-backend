<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class BulkStagingHomologacionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'staging_ids' => ['required', 'array', 'min:1', 'max:500'],
            'staging_ids.*' => ['required', 'integer', 'distinct', 'exists:excel_import_staging,id'],
            'producto_id_destino' => ['required', 'integer', 'exists:productos,id'],
            'notas' => ['nullable', 'string', 'max:2000'],
            'confirmar_reemplazo' => ['sometimes', 'boolean'],
        ];
    }
}

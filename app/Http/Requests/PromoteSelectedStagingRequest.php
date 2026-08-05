<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class PromoteSelectedStagingRequest extends FormRequest
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
            'confirmar_promocion' => ['required', 'accepted'],
        ];
    }
}

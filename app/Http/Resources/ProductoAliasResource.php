<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProductoAliasResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'alias' => $this->alias,
            'producto_id' => $this->producto_id,
            'fuente' => $this->fuente,
            'confianza' => $this->confianza,
            'revisado' => $this->revisado,
            'requiere_revision' => $this->requiere_revision,
            'notas' => $this->notas,
        ];
    }
}

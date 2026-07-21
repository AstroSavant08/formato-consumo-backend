<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProductoResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'nombre' => $this->nombre,
            'categoria_id' => $this->categoria_id,
            'unidad_default' => $this->unidad_default,
            'stock_minimo_referencia' => $this->stock_minimo_referencia,
            'activo' => $this->activo,
            'es_historico_excel' => $this->es_historico_excel,
            'aliases' => ProductoAliasResource::collection($this->whenLoaded('aliases')),
        ];
    }
}

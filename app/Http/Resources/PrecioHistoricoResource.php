<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PrecioHistoricoResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'producto_id' => $this->producto_id,
            'producto' => $this->whenLoaded('producto', fn () => $this->producto ? [
                'id' => $this->producto->id,
                'nombre' => $this->producto->nombre,
                'unidad_default' => $this->producto->unidad_default,
            ] : null),
            'precio' => (float) $this->precio,
            'vigente_desde' => $this->vigente_desde?->toDateString(),
            'vigente_hasta' => $this->vigente_hasta?->toDateString(),
            'usuario_id' => $this->usuario_id,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}

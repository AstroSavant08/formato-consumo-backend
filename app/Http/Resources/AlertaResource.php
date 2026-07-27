<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AlertaResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'tipo' => $this->tipo,
            'severidad' => $this->severidad,
            'producto_id' => $this->producto_id,
            'producto' => $this->whenLoaded('producto', fn () => $this->producto ? [
                'id' => $this->producto->id,
                'nombre' => $this->producto->nombre,
                'unidad_default' => $this->producto->unidad_default,
            ] : null),
            'area_id' => $this->area_id,
            'mensaje' => $this->mensaje,
            'metadata' => $this->metadata,
            'leida' => (bool) $this->leida,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}

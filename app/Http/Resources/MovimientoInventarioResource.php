<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MovimientoInventarioResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'producto_id' => $this->producto_id,
            'producto' => $this->whenLoaded('producto', fn () => [
                'id' => $this->producto->id,
                'nombre' => $this->producto->nombre,
                'unidad_default' => $this->producto->unidad_default,
            ]),
            'tipo' => $this->tipo,
            'cantidad' => (float) $this->cantidad,
            'stock_anterior' => (float) $this->stock_anterior,
            'stock_posterior' => (float) $this->stock_posterior,
            'referencia_tipo' => $this->referencia_tipo,
            'referencia_id' => $this->referencia_id,
            'usuario' => $this->whenLoaded('usuario', fn () => $this->usuario ? [
                'id' => $this->usuario->id,
                'name' => $this->usuario->name,
            ] : null),
            'observaciones' => $this->observaciones,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}

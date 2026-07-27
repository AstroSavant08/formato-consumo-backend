<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class InventarioResource extends JsonResource
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
            'stock_fisico' => (float) $this->stock_fisico,
            'stock_reserva' => (float) $this->stock_reserva,
            'stock_comprometido' => (float) $this->stock_comprometido,
            'stock_disponible' => $this->stock_disponible,
            'stock_minimo' => (float) $this->stock_minimo,
        ];
    }
}

<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SolicitudDetalleResource extends JsonResource
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
            'cantidad_solicitada' => (float) $this->cantidad_solicitada,
            'unidad' => $this->unidad,
            'cantidad_aprobada' => $this->cantidad_aprobada !== null ? (float) $this->cantidad_aprobada : null,
            'precio_unitario' => (float) $this->precio_unitario,
            'subtotal' => (float) $this->subtotal,
        ];
    }
}
